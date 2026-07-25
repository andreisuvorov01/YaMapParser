"""
Локальный HTTP-прокси для Node.js скриптов.

Эндпоинты:
  POST /fetch         — TLSFingerprinter (curl_cffi / httpx HTTP/2)
  POST /fetch-browser — Playwright stealth
  GET  /health        — статус XRay + конфигов
  POST /xray/rotate   — принудительная смена конфига

Переменные окружения:
  PROXY_HOST              default: 127.0.0.1
  PROXY_PORT              default: 8765
  FETCH_THROTTLE_MS       default: 300
  BROWSER_THROTTLE_MS     default: 2000
  BROWSER_HEADLESS        default: true
  BROWSER_TIMEOUT_MS      default: 45000
  BROWSER_WAIT_UNTIL      default: domcontentloaded
  PROXY_MAX_FAILS         ошибок подряд до смены конфига  default: 3
  XRAY_SOCKS_HOST         default: 127.0.0.1
  XRAY_SOCKS_PORT         default: 10808
  XRAY_CONFIGS_DIR        папка с JSON-конфигами XRay     default: xray_configs
  XRAY_EXECUTABLE         путь к xray.exe                 default: xray
"""

from __future__ import annotations

import asyncio
import base64
import logging
import os
import json
import random
import re
import socket
import subprocess
import tempfile
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Optional

import httpx
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from tls_fingerprinter import TLSFingerprinter, TLSFingerprintResult, close_all_sessions
from stealth_browser import new_stealth_context
from payload_padding import PayloadPadding
from config_importer import (
    import_from_link,
    import_from_subscription,
    import_from_happ_json,
    save_xray_config_direct,
)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)
logging.getLogger("uvicorn.access").setLevel(logging.WARNING)

# ---------------------------------------------------------------------------
# Загрузка .env
# ---------------------------------------------------------------------------
_env_path = os.path.join(os.path.dirname(__file__), ".env")
if os.path.exists(_env_path):
    with open(_env_path, encoding="utf-8") as _f:
        for _line in _f:
            _line = _line.strip()
            if not _line or _line.startswith("#"):
                continue
            _m = re.match(r"^([\w.-]+)\s*=\s*(.*)$", _line)
            if _m and _m.group(1) not in os.environ:
                os.environ[_m.group(1)] = _m.group(2).strip('"\'')

# ---------------------------------------------------------------------------
# Конфигурация
# ---------------------------------------------------------------------------
HOST = os.getenv("PROXY_HOST", "127.0.0.1")
PORT = int(os.getenv("PROXY_PORT", "8765"))
FETCH_THROTTLE_MS = int(os.getenv("FETCH_THROTTLE_MS", "300"))
BROWSER_THROTTLE_MS = int(os.getenv("BROWSER_THROTTLE_MS", "2000"))
BROWSER_HEADLESS = os.getenv("BROWSER_HEADLESS", "true").lower() != "false"
BROWSER_TIMEOUT_MS = int(os.getenv("BROWSER_TIMEOUT_MS", "45000"))
BROWSER_WAIT_UNTIL = os.getenv("BROWSER_WAIT_UNTIL", "domcontentloaded")
PROXY_MAX_FAILS = int(os.getenv("PROXY_MAX_FAILS", "3"))
XRAY_SOCKS_HOST = os.getenv("XRAY_SOCKS_HOST", "127.0.0.1")
XRAY_SOCKS_PORT = int(os.getenv("XRAY_SOCKS_PORT", "10808"))
XRAY_CONFIGS_DIR = os.getenv("XRAY_CONFIGS_DIR", os.path.join(os.path.dirname(__file__), "xray_configs"))
# Определяем полный путь к Xray: xray.exe на Windows, xray на Linux/macOS; относительный путь резолвим от _script_dir
_script_dir = os.path.dirname(os.path.abspath(__file__))
_xray_exe_env = os.getenv("XRAY_EXECUTABLE", "xray.exe" if os.name == "nt" else "xray")
if os.path.isabs(_xray_exe_env):
    XRAY_EXECUTABLE = _xray_exe_env
else:
    XRAY_EXECUTABLE = os.path.join(_script_dir, _xray_exe_env)

FINGERPRINT_PROFILES = ["chrome_130", "chrome_130", "chrome_136", "chrome_136", "firefox_128", "firefox_128"]
ROTATE_EVERY_N = int(os.getenv("ROTATE_EVERY_N", "25"))  # смена конфига каждые N успешных запросов
XRAY_BLOCK_COOLDOWN_SEC = int(os.getenv("XRAY_BLOCK_COOLDOWN_SEC", "600"))  # карантин конфига после 403/429
XRAY_WAIT_READY_SEC = float(os.getenv("XRAY_WAIT_READY_SEC", "10"))
# URL для проверки работоспособности прокси — целевые сайты, не google.
# Тестируем на ОБОИХ источниках, которые реально парсим (gdebenz + benzinest,
# см. sync-gdebenz.mjs/sync-benzinest.mjs) — прокси может быть заблокирован
# или ограничен по гео на одном из них и при этом нормально работать на
# другом (разные хостинги/CDN/анти-бот у сайтов), так что единственная общая
# проверка недооценивала часть рабочих конфигов.
BENCH_TEST_URL = os.getenv("BENCH_TEST_URL", "https://gdebenz.org/api/stations?lat1=55.7&lon1=37.5&lat2=55.8&lon2=37.6")
BENCH_TEST_URL_BENZINEST = os.getenv("BENCH_TEST_URL_BENZINEST", "https://benzinest.ru/api/stations?bbox=55.7,37.5,55.8,37.6")

# ---------------------------------------------------------------------------
# Пул одновременно работающих XRay-процессов для /fetch.
#
# Раньше /fetch всегда шёл через ОДИН активный XRay-процесс (_xray.current),
# причём все запросы к нему вообще сериализовались одной блокировкой
# (_xray_request_lock) — сколько бы конфигов ни лежало в xray_configs/,
# реально нагрузка на источник шла всегда с одного IP за раз.
# XRayFleet поднимает сразу POOL_SIZE процессов на разных портах (из
# конфигов, прошедших bench) и раздаёт запросы по свободным слотам —
# конкурентность и реальная скорость теперь равны размеру пула, а не 1.
XRAY_POOL_SIZE = int(os.getenv("XRAY_POOL_SIZE", "16"))
# Минимальный интервал между запросами через ОДИН И ТОТ ЖЕ слот пула (мс).
# Держит вежливый темп на каждый отдельный IP, а не на сервер в целом —
# суммарный потолок скорости растёт вместе с XRAY_POOL_SIZE.
XRAY_POOL_MIN_INTERVAL_MS = int(os.getenv("XRAY_POOL_MIN_INTERVAL_MS", "200"))
# Ошибок подряд на одном слоте, после которых конфиг считается нерабочим и
# слот пересоздаётся на другом конфиге (не дожидаясь общего счётчика).
XRAY_POOL_MAX_FAILS = int(os.getenv("XRAY_POOL_MAX_FAILS", "3"))
XRAY_POOL_BASE_PORT = int(os.getenv("XRAY_POOL_BASE_PORT", "13000"))
# Конкурентность bench-теста конфигов при старте: сколько xray-процессов
# может ОДНОВРЕМЕННО быть запущено/протестировано. Сам запуск процесса и
# ожидание порта — дешёвая операция, можно держать высоким.
BENCH_CONCURRENCY = int(os.getenv("BENCH_CONCURRENCY", "24"))
# А вот РЕАЛЬНЫЕ HTTP-запросы через curl_cffi (TLSFingerprinter) — отдельный,
# гораздо более узкий лимит: каждый новый (fingerprint, proxy) в
# tls_fingerprinter.py создаёт свою AsyncSession/curl-хендл (см. _get_session),
# а bench бьёт по 400+ РАЗНЫМ локальным портам — то есть создаёт СТОЛЬКО ЖЕ
# новых сессий подряд. При BENCH_CONCURRENCY=24 это означает до 24 новых
# curl_cffi-сессий одновременно, что на практике даёт синхронизированные
# зависания (все стартуют вместе, все падают по таймауту почти одновременно) —
# похоже на конфликт на уровне libcurl/OpenSSL при параллельном создании
# хендлов, а не на реальную недоступность прокси. Сам curl_cffi по умолчанию
# использует max_clients=10 у AsyncSession — ограничиваем HTTP-часть похожим
# порядком величины, отдельно от BENCH_CONCURRENCY.
BENCH_HTTP_CONCURRENCY = int(os.getenv("BENCH_HTTP_CONCURRENCY", "10"))

# Глобальные счётчики для статистики в логе
_total_requests = 0
_total_ok = 0
_total_fail = 0

# Общий для _run_bench_on_startup и /xray/bench семафор на HTTP-часть bench —
# см. BENCH_HTTP_CONCURRENCY выше.
_bench_http_sem = asyncio.Semaphore(BENCH_HTTP_CONCURRENCY)


async def _wait_port_ready(host: str, port: int, timeout: float = 3.0) -> bool:
    """Ждёт, пока локальный порт начнёт принимать TCP-соединения, вместо
    фиксированной паузы после старта xray. Фиксированный sleep (было 0.3-
    0.35с) рассчитан на низкую конкурентность — при одновременном запуске
    много xray-процессов (bench с высоким BENCH_CONCURRENCY, пул из
    XRAY_POOL_SIZE слотов) часть из них под нагрузкой не успевает забиндить
    SOCKS-порт в это окно, и первый же запрос через прокси падает с
    "Could not resolve proxy"/connection refused, хотя конфиг рабочий —
    просто проверили на долю секунды раньше времени. Опрос конкретного
    порта устраняет эту гонку независимо от конкурентности.
    """
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            _, writer = await asyncio.wait_for(asyncio.open_connection(host, port), timeout=0.3)
            writer.close()
            try:
                await writer.wait_closed()
            except Exception:
                pass
            return True
        except Exception:
            await asyncio.sleep(0.05)
    return False


# ---------------------------------------------------------------------------
# XRay менеджер
# ---------------------------------------------------------------------------
@dataclass
class XRayConfig:
    path: str
    name: str
    fails: int = 0
    # healthy — по gdebenz (основная цель, используется по умолчанию везде,
    # где раньше был единственный healthy); healthy_benzinest — отдельно по
    # benzinest.ru. None=не тестирован, True=рабочий, False=нерабочий.
    healthy: Optional[bool] = None
    healthy_benzinest: Optional[bool] = None
    latency_ms: int = 0             # последняя измеренная задержка (по gdebenz)
    blocked_until: float = 0.0       # monotonic timestamp: временный карантин после 403/429


def _config_is_valid(path: Path) -> bool:
    """Отбраковывает заведомо нерабочие конфиги ДО попытки запуска XRay —
    часть подписок содержит decoy-ноды (напр. с "BL" в имени и паролем вида
    "------------BanV2ray------------"), которые всегда падают с exit=23
    ("REALITY: Empty realitySettings"). Без этой проверки такие конфиги
    честно перебираются наравне с рабочими на каждом старте/ротации, теряя
    по несколько секунд на каждый (запуск процесса + таймаут)."""
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
    except Exception:
        return False
    for outbound in data.get("outbounds", []):
        if outbound.get("tag") != "proxy":
            continue
        stream = outbound.get("streamSettings") or {}
        if stream.get("security") == "reality":
            reality = stream.get("realitySettings") or {}
            if not reality.get("publicKey"):
                return False
    return True


class XRayManager:
    def __init__(self) -> None:
        self.configs: list[XRayConfig] = []
        self._index: int = 0
        self._process: Optional[subprocess.Popen] = None
        self._lock = asyncio.Lock()
        self.current: Optional[XRayConfig] = None
        self.proxy_url: str = f"socks5://{XRAY_SOCKS_HOST}:{XRAY_SOCKS_PORT}"
        self._temp_config_path: Optional[str] = None
        self._temp_log_path: Optional[str] = None
        self._rotating: bool = False  # флаг — идёт ротация, не блокируем запросы
        self.bench_completed: bool = False  # после bench используем только healthy=True

    def load_configs(self) -> None:
        configs_dir = Path(XRAY_CONFIGS_DIR)
        if not configs_dir.exists():
            configs_dir.mkdir(parents=True)
            logger.warning("Папка xray_configs создана — добавьте JSON-конфиги XRay")
            return
        files = sorted(configs_dir.glob("*.json"))
        self.configs = []
        skipped = 0
        for f in files:
            if _config_is_valid(f):
                self.configs.append(XRayConfig(path=str(f), name=f.stem))
            else:
                skipped += 1
        logger.info("XRay конфигов найдено: %d", len(self.configs))
        if skipped:
            logger.info(
                "Пропущено заведомо нерабочих конфигов (decoy/битые, без realitySettings и т.п.): %d",
                skipped,
            )


    def _port_available(self, port: int) -> bool:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            try:
                sock.bind((XRAY_SOCKS_HOST, port))
                return True
            except OSError:
                return False

    def _pick_socks_port(self) -> int:
        if self._port_available(XRAY_SOCKS_PORT):
            return XRAY_SOCKS_PORT
        for port in range(XRAY_SOCKS_PORT + 1, XRAY_SOCKS_PORT + 200):
            if self._port_available(port):
                logger.warning("Порт XRay %d занят, временно использую %d", XRAY_SOCKS_PORT, port)
                return port
        raise RuntimeError(f"Нет свободного SOCKS-порта около {XRAY_SOCKS_PORT}")

    def _prepare_config_for_start(self, config: XRayConfig) -> tuple[str, int]:
        socks_port = self._pick_socks_port()
        with open(config.path, encoding="utf-8") as f:
            data = json.load(f)
        for inbound in data.get("inbounds", []):
            if inbound.get("protocol") == "socks":
                inbound["listen"] = XRAY_SOCKS_HOST
                inbound["port"] = socks_port
        tmp = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False, encoding="utf-8")
        with tmp:
            json.dump(data, tmp, ensure_ascii=False)
        return tmp.name, socks_port

    def get_healthy_configs(self, target: str = "any") -> list[XRayConfig]:
        """Возвращает конфиги, разрешённые для парса.

        `target`: "gdebenz" | "benzinest" | "any" (по умолчанию) — конфиг
        считается рабочим для "any", если прошёл bench хотя бы по ОДНОЙ из
        двух целей (прокси может быть заблокирован/ограничен по гео на
        одном сайте и нормально работать на другом). /fetch обслуживает оба
        источника через общий пул слотов, поэтому фильтр по умолчанию — "any".

        До завершения bench допускаем все загруженные конфиги, чтобы прокси мог
        стартовать. После bench не используем непрошедшие проверку конфиги даже
        если рабочих не осталось: это лучше, чем снова гонять заведомо битые
        конфиги в парсе и получать серии TLS/502 ошибок.
        """
        now = time.monotonic()

        def is_healthy(c: XRayConfig) -> bool:
            if target == "gdebenz":
                return c.healthy is True
            if target == "benzinest":
                return c.healthy_benzinest is True
            return c.healthy is True or c.healthy_benzinest is True

        healthy = [c for c in self.configs if is_healthy(c) and c.blocked_until <= now]
        if healthy:
            return healthy
        if self.bench_completed:
            return []
        return self.configs

    async def start(self, config: XRayConfig) -> bool:
        async with self._lock:
            if self._process and self._process.poll() is None:
                self._process.kill()
                try:
                    self._process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    self._process.terminate()
                self._process = None
            if self._temp_config_path:
                try:
                    os.unlink(self._temp_config_path)
                except OSError:
                    pass
                self._temp_config_path = None
            if self._temp_log_path:
                try:
                    os.unlink(self._temp_log_path)
                except OSError:
                    pass
                self._temp_log_path = None
            try:
                run_config_path, socks_port = self._prepare_config_for_start(config)
                self._temp_config_path = run_config_path
                self.proxy_url = f"socks5://{XRAY_SOCKS_HOST}:{socks_port}"
                # Файл, а не PIPE/DEVNULL: PIPE у долгоживущего процесса, чей
                # вывод никто не вычитывает, рано или поздно заполняется и
                # блокирует xray на записи; DEVNULL (как было раньше) просто
                # выбрасывал вывод — а Xray при ошибке конфига чаще всего пишет
                # именно в stdout, не в stderr, поэтому в логе было "stderr
                # пуст" даже когда реальная причина падения была известна
                # самому xray. Файл лишён обеих проблем: не блокирует запись и
                # его можно дочитать после завершения процесса.
                log_tmp = tempfile.NamedTemporaryFile(mode="w", suffix=".log", delete=False, encoding="utf-8")
                self._temp_log_path = log_tmp.name
                self._process = subprocess.Popen(
                    [XRAY_EXECUTABLE, "run", "-c", run_config_path],
                    stdout=log_tmp,
                    stderr=subprocess.STDOUT,
                )
                log_tmp.close()  # родителю дескриптор не нужен — у потомка своя копия (dup через fork/exec)
                self.current = config
                if self._process.poll() is None:
                    await _wait_port_ready(XRAY_SOCKS_HOST, socks_port)
                if self._process.poll() is not None:
                    try:
                        with open(self._temp_log_path, encoding="utf-8", errors="replace") as f:
                            output = f.read().strip()[-500:]
                    except OSError:
                        output = ""
                    logger.warning(
                        "❌ XRay быстро завершился [%d/%d] %s (exit=%s): %s",
                        self._index + 1, len(self.configs), config.name, self._process.returncode, output or "вывод пуст",
                    )
                    config.healthy = False
                    self._process = None
                    return False
                logger.info("✅ XRay [%d/%d] %s (pid=%d, socks=%d)", self._index + 1, len(self.configs), config.name, self._process.pid, socks_port)
                return True
            except FileNotFoundError:
                logger.error("❌ XRay не найден: '%s'", XRAY_EXECUTABLE)
                return False
            except Exception as e:
                logger.error("❌ Ошибка запуска XRay: %s", e)
                return False

    async def rotate(self) -> bool:
        """Переключается на следующий рабочий конфиг. Не блокирует входящие запросы."""
        if not self.configs or self._rotating:
            return False
        self._rotating = True
        try:
            prev = self.current.name if self.current else "none"
            healthy = self.get_healthy_configs()
            if not healthy:
                logger.warning("Нет рабочих конфигов для ротации")
                return False

            # Ищем следующий рабочий конфиг после текущего индекса
            current_cfg = self.current
            if current_cfg and current_cfg in healthy:
                current_idx = healthy.index(current_cfg)
                next_idx = (current_idx + 1) % len(healthy)
            else:
                next_idx = 0

            cfg = healthy[next_idx]
            # Обновляем _index чтобы указывал на этот конфиг в общем списке
            if cfg in self.configs:
                self._index = self.configs.index(cfg)

            logger.info(
                "➡️  Ротация [%d/%d рабочих]: %s → %s",
                next_idx + 1, len(healthy),
                prev, cfg.name,
            )
            async with _xray_request_lock:
                return await self.start(cfg)
        finally:
            self._rotating = False

    async def stop(self) -> None:
        async with self._lock:
            if self._process and self._process.poll() is None:
                self._process.kill()
            self._process = None
            if self._temp_config_path:
                try:
                    os.unlink(self._temp_config_path)
                except OSError:
                    pass
                self._temp_config_path = None
            if self._temp_log_path:
                try:
                    os.unlink(self._temp_log_path)
                except OSError:
                    pass
                self._temp_log_path = None

    @property
    def is_running(self) -> bool:
        return self._process is not None and self._process.poll() is None


_xray = XRayManager()


# ---------------------------------------------------------------------------
# XRayFleet — пул одновременно живых XRay-процессов для /fetch (см. константы
# XRAY_POOL_* выше). Каждый слот — отдельный процесс на отдельном порту со
# своим здоровьем/карантином/темпом запросов, независимым от остальных.
# ---------------------------------------------------------------------------
@dataclass
class FleetSlot:
    config: XRayConfig
    port: int
    proc: subprocess.Popen
    temp_path: str
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)
    last_request_at: float = 0.0
    consecutive_fails: int = 0


class XRayFleet:
    def __init__(self) -> None:
        self.slots: list[FleetSlot] = []
        self._manage_lock = asyncio.Lock()
        self._used_config_names: set[str] = set()
        self.ready = False

    def _port_available(self, port: int) -> bool:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            try:
                sock.bind((XRAY_SOCKS_HOST, port))
                return True
            except OSError:
                return False

    def _pick_port(self) -> int:
        used_ports = {s.port for s in self.slots}
        port = XRAY_POOL_BASE_PORT
        while port in used_ports or not self._port_available(port):
            port += 1
        return port

    async def _start_slot(self, config: XRayConfig) -> bool:
        port = self._pick_port()
        try:
            with open(config.path, encoding="utf-8") as f:
                data = json.load(f)
            for inbound in data.get("inbounds", []):
                if inbound.get("protocol") == "socks":
                    inbound["listen"] = XRAY_SOCKS_HOST
                    inbound["port"] = port
            tmp = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False, encoding="utf-8")
            with tmp:
                json.dump(data, tmp, ensure_ascii=False)
            proc = subprocess.Popen(
                [XRAY_EXECUTABLE, "run", "-c", tmp.name],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            if proc.poll() is None:
                await _wait_port_ready(XRAY_SOCKS_HOST, port)
            if proc.poll() is not None:
                try:
                    os.unlink(tmp.name)
                except OSError:
                    pass
                config.healthy = False
                return False
            self.slots.append(FleetSlot(config=config, port=port, proc=proc, temp_path=tmp.name))
            self._used_config_names.add(config.name)
            return True
        except Exception as e:
            logger.warning("XRayFleet: не удалось запустить слот %s: %s", config.name, e)
            return False

    def _candidates(self) -> list[XRayConfig]:
        now = time.monotonic()
        return [
            c for c in _xray.get_healthy_configs()
            if c.name not in self._used_config_names and c.blocked_until <= now
        ]

    async def _fill_slots(self) -> None:
        wanted = min(XRAY_POOL_SIZE, len(_xray.configs)) if _xray.configs else 0
        candidates = self._candidates()
        random.shuffle(candidates)
        started = 0
        while len(self.slots) < wanted and candidates:
            cfg = candidates.pop()
            if await self._start_slot(cfg):
                started += 1
        if started:
            logger.info("🚀 XRayFleet: поднято слотов %d/%d (активно всего %d)", started, wanted, len(self.slots))
        elif wanted and not self.slots:
            logger.warning("XRayFleet: не удалось поднять ни одного слота пула")

    async def ensure_started(self) -> None:
        if self.slots or not _xray.configs:
            self.ready = True
            return
        async with self._manage_lock:
            if self.slots:
                return
            await self._fill_slots()
            self.ready = True

    async def _replace_slot(self, slot: FleetSlot) -> None:
        async with self._manage_lock:
            if slot not in self.slots:
                return
            self.slots.remove(slot)
            self._used_config_names.discard(slot.config.name)
            try:
                if slot.proc.poll() is None:
                    slot.proc.kill()
            except Exception:
                pass
            try:
                os.unlink(slot.temp_path)
            except OSError:
                pass

            candidates = self._candidates()
            if not candidates:
                logger.warning(
                    "XRayFleet: нет свободных здоровых конфигов взамен %s (осталось слотов: %d)",
                    slot.config.name, len(self.slots),
                )
                return
            cfg = random.choice(candidates)
            if await self._start_slot(cfg):
                logger.info("🔁 XRayFleet: слот заменён %s → %s (активно %d)", slot.config.name, cfg.name, len(self.slots))
            else:
                logger.warning("XRayFleet: замена %s → %s не запустилась", slot.config.name, cfg.name)

    async def acquire(self, deadline: float) -> Optional[FleetSlot]:
        await self.ensure_started()
        while True:
            now = time.monotonic()
            available = [s for s in self.slots if not s.lock.locked() and s.config.blocked_until <= now]
            if available:
                slot = min(available, key=lambda s: s.last_request_at)
                await slot.lock.acquire()
                wait = slot.last_request_at + (XRAY_POOL_MIN_INTERVAL_MS / 1000.0) - time.monotonic()
                if wait > 0:
                    await asyncio.sleep(wait)
                slot.last_request_at = time.monotonic()
                return slot
            if not self.slots and _xray.configs:
                await self._fill_slots()
            if time.monotonic() >= deadline:
                return None
            await asyncio.sleep(0.05)

    def release(self, slot: FleetSlot) -> None:
        if slot.lock.locked():
            slot.lock.release()

    async def on_fail(self, slot: FleetSlot, *, blocked: bool) -> None:
        slot.consecutive_fails += 1
        if blocked:
            slot.config.blocked_until = time.monotonic() + XRAY_BLOCK_COOLDOWN_SEC
            logger.warning("🧊 [pool] %s в карантине на %dс после 403/429", slot.config.name, XRAY_BLOCK_COOLDOWN_SEC)
            asyncio.ensure_future(self._replace_slot(slot))
        elif slot.consecutive_fails >= XRAY_POOL_MAX_FAILS:
            logger.warning("⛔ [pool] %s: %d ошибок подряд, меняю слот", slot.config.name, slot.consecutive_fails)
            slot.config.healthy = False
            asyncio.ensure_future(self._replace_slot(slot))

    def on_ok(self, slot: FleetSlot) -> None:
        slot.consecutive_fails = 0
        slot.config.fails = 0

    async def stop(self) -> None:
        async with self._manage_lock:
            for s in self.slots:
                try:
                    if s.proc.poll() is None:
                        s.proc.kill()
                except Exception:
                    pass
                try:
                    os.unlink(s.temp_path)
                except OSError:
                    pass
            self.slots.clear()
            self._used_config_names.clear()
            self.ready = False


_fleet = XRayFleet()


# ---------------------------------------------------------------------------
# Пул постоянных XRay процессов для http2-bomb
# ---------------------------------------------------------------------------
class XRayPool:
    """Пул постоянно живых XRay процессов на фиксированных портах.
    Запускается один раз, переиспользуется во всех волнах bomb.
    """
    BASE_PORT = 12000

    def __init__(self) -> None:
        self._procs: list[tuple[subprocess.Popen, str, int]] = []  # (proc, cfg_name, port)
        self._lock = asyncio.Lock()
        self._ready = False

    async def ensure(self, configs: list) -> list[str]:
        """Запускает пул если ещё не запущен. Возвращает список socks5 URL."""
        async with self._lock:
            if self._ready and all(p.poll() is None for p, _, _ in self._procs):
                return [f"socks5://127.0.0.1:{port}" for _, _, port in self._procs]
            await self._stop_all()
            import json as _j, tempfile as _t
            started = []
            for i, cfg in enumerate(configs):
                port = self.BASE_PORT + i
                try:
                    with open(cfg.path, encoding="utf-8") as f:
                        data = _j.load(f)
                    for inb in data.get("inbounds", []):
                        inb["port"] = port
                    with _t.NamedTemporaryFile(mode="w", suffix=".json", delete=False, encoding="utf-8") as tmp:
                        _j.dump(data, tmp)
                        tmp_path = tmp.name
                    proc = subprocess.Popen(
                        [XRAY_EXECUTABLE, "run", "-c", tmp_path],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                    )
                    self._procs.append((proc, cfg.name, port))
                    started.append(f"socks5://127.0.0.1:{port}")
                except Exception as e:
                    logger.warning("XRayPool: не удалось запустить %s: %s", cfg.name, e)
            await asyncio.sleep(0.5)  # ждём старта всех процессов
            self._ready = True
            logger.info("XRayPool: запущено %d процессов", len(self._procs))
            return started

    async def _stop_all(self) -> None:
        for proc, _, _ in self._procs:
            try:
                if proc.poll() is None:
                    proc.kill()
            except Exception:
                pass
        self._procs.clear()
        self._ready = False

    async def stop(self) -> None:
        async with self._lock:
            await self._stop_all()

    def proxy_urls(self) -> list[str]:
        return [f"socks5://127.0.0.1:{port}" for _, _, port in self._procs if self._procs]


_xray_pool = XRayPool()


# ---------------------------------------------------------------------------
# Счётчики для ротации XRay
# ---------------------------------------------------------------------------
_consecutive_fails = 0
_request_count = 0
_rotate_lock = asyncio.Lock()
_xray_request_lock = asyncio.Lock()


async def _on_request_fail(*, blocked: bool = False) -> None:
    global _consecutive_fails, _request_count
    _consecutive_fails += 1
    _request_count = 0
    if blocked and _xray.current:
        _xray.current.blocked_until = time.monotonic() + XRAY_BLOCK_COOLDOWN_SEC
        logger.warning(
            "🧊 Конфиг %s в карантине на %d сек после 403/429",
            _xray.current.name, XRAY_BLOCK_COOLDOWN_SEC,
        )
    if (blocked or _consecutive_fails >= PROXY_MAX_FAILS) and _xray.configs:
        async with _rotate_lock:
            if blocked or _consecutive_fails >= PROXY_MAX_FAILS:
                _consecutive_fails = 0
                # Если текущий конфиг подвел — помечаем его как проблемный
                if _xray.current:
                    _xray.current.fails += 1
                    # После 3*PROXY_MAX_FAILS ошибок подряд помечаем как нерабочий
                    if _xray.current.fails >= PROXY_MAX_FAILS * 3:
                        logger.warning("⛔ Конфиг %s помечен как нерабочий (%d ошибок)", _xray.current.name, _xray.current.fails)
                        _xray.current.healthy = False
                # fire-and-forget — не блокируем текущий запрос
                asyncio.ensure_future(_xray.rotate())


async def _on_request_ok() -> None:
    global _consecutive_fails, _request_count
    _consecutive_fails = 0
    _request_count += 1
    # Сбрасываем счётчик ошибок текущего конфига при успешном запросе
    if _xray.current:
        _xray.current.fails = 0
    if _request_count >= ROTATE_EVERY_N and _xray.configs:
        async with _rotate_lock:
            if _request_count >= ROTATE_EVERY_N:
                _request_count = 0
                asyncio.ensure_future(_xray.rotate())


def _get_proxy() -> Optional[str]:
    """Возвращает текущий SOCKS5 URL XRay или None, если XRay нельзя использовать."""
    if not _xray.configs or not _xray.is_running or _xray.current is None:
        return None
    if _xray.bench_completed and _xray.current.healthy is not True:
        return None
    if _xray.current.blocked_until > time.monotonic():
        return None
    return _xray.proxy_url


def _next_fingerprint() -> str:
    return random.choice(FINGERPRINT_PROFILES)


# ---------------------------------------------------------------------------
# Throttle — rate limiter без глобальной блокировки запросов
# ---------------------------------------------------------------------------
_fetch_last_at = 0.0
_browser_last_at = 0.0
_fetch_throttle_lock = asyncio.Lock()
_browser_throttle_lock = asyncio.Lock()


async def _throttle_fetch() -> None:
    global _fetch_last_at
    if FETCH_THROTTLE_MS <= 0:
        return
    async with _fetch_throttle_lock:
        now = time.monotonic()
        gap = FETCH_THROTTLE_MS / 1000.0
        wait = _fetch_last_at + gap - now
        if wait > 0:
            await asyncio.sleep(wait)
        _fetch_last_at = time.monotonic()


async def _throttle_browser() -> None:
    global _browser_last_at
    if BROWSER_THROTTLE_MS <= 0:
        return
    async with _browser_throttle_lock:
        now = time.monotonic()
        gap = BROWSER_THROTTLE_MS / 1000.0
        wait = _browser_last_at + gap - now
        if wait > 0:
            await asyncio.sleep(wait)
        _browser_last_at = time.monotonic()


# ---------------------------------------------------------------------------
# Браузер
# ---------------------------------------------------------------------------
_playwright = None
_browser = None
_browser_lock = asyncio.Lock()


async def _get_browser():
    global _playwright, _browser
    async with _browser_lock:
        if _browser is not None:
            return _browser
        try:
            from playwright.async_api import async_playwright
        except ImportError:
            raise RuntimeError("pip install playwright && playwright install chromium")
        _playwright = await async_playwright().start()
        _browser = await _playwright.chromium.launch(
            headless=BROWSER_HEADLESS,
            args=[
                "--disable-blink-features=AutomationControlled",
                "--disable-infobars",
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-background-timer-throttling",
                "--disable-backgrounding-occluded-windows",
                "--disable-renderer-backgrounding",
                "--window-size=1920,1080",
            ],
        )
        logger.info("Playwright запущен (headless=%s)", BROWSER_HEADLESS)
        return _browser


# ---------------------------------------------------------------------------
# Lifespan
# ---------------------------------------------------------------------------
@asynccontextmanager
async def lifespan(app: FastAPI):
    _xray.load_configs()
    if _xray.configs:
        started = False
        for i, cfg in enumerate(_xray.configs):
            _xray._index = i
            ok = await _xray.start(cfg)
            if ok:
                started = True
                break
        if not started:
            logger.warning("XRay не запустился — работаем без прокси")
        else:
            # Bench обязателен до начала парса: после него в ротацию попадут
            # только конфиги с healthy=True.
            logger.info("Запускаю обязательный тест конфигов (bench, конкурентность %d)...", BENCH_CONCURRENCY)
            await _run_bench_on_startup()
            # Поднимаем пул сразу после bench, а не лениво на первый /fetch —
            # так первый запрос парсинга не платит задержку старта процессов.
            await _fleet.ensure_started()
    else:
        logger.warning("Нет XRay конфигов в '%s' — работаем без прокси", XRAY_CONFIGS_DIR)

    logger.info(
        "Сервер http://%s:%d | XRay-пул: %d/%d слотов | throttle=%dмс",
        HOST, PORT, len(_fleet.slots), XRAY_POOL_SIZE, FETCH_THROTTLE_MS,
    )
    yield
    await _fleet.stop()
    await _xray.stop()
    await _xray_pool.stop()
    await close_all_sessions()
    global _browser, _playwright
    if _browser:
        await _browser.close()
    if _playwright:
        await _playwright.stop()
    logger.info("Сервер остановлен")


async def _run_bench_on_startup() -> None:
    """Запускает bench тест при старте сервера для маркировки рабочих конфигов."""
    try:
        # Ждём немного чтобы сервер успел запуститься
        await asyncio.sleep(1.0)
        logger.info(
            "🔍 Bench при старте: тестирую %d конфигов (процессы: %d, HTTP: %d) -> gdebenz + benzinest",
            len(_xray.configs), BENCH_CONCURRENCY, BENCH_HTTP_CONCURRENCY,
        )
        BENCH_TIMEOUT = 6.0
        sem = asyncio.Semaphore(BENCH_CONCURRENCY)
        base_port = 11000

        async def probe(proxy: str, url: str) -> bool:
            # Отдельный, узкий семафор именно на HTTP-часть (curl_cffi) — см.
            # BENCH_HTTP_CONCURRENCY выше: создание новой AsyncSession на
            # каждый уникальный (fingerprint, proxy) не масштабируется на
            # десятки одновременных вызовов так же хорошо, как запуск процессов.
            async with _bench_http_sem:
                fingerprinter = TLSFingerprinter(fingerprint="chrome_130", proxy=proxy)
                result = await asyncio.wait_for(fingerprinter.emulate_client(url), timeout=BENCH_TIMEOUT)
                return 200 <= result.status_code < 400

        async def test_one(cfg: XRayConfig, port: int) -> dict:
            async with sem:
                proc = None
                tmp_path = None
                t_start = time.monotonic()
                try:
                    import json as _json
                    with open(cfg.path, encoding="utf-8") as f:
                        cfg_data = _json.load(f)
                    for inb in cfg_data.get("inbounds", []):
                        inb["port"] = port
                    import tempfile, os as _os
                    with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False, encoding="utf-8") as tmp:
                        _json.dump(cfg_data, tmp)
                        tmp_path = tmp.name
                    proc = subprocess.Popen(
                        [XRAY_EXECUTABLE, "run", "-c", tmp_path],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                    )
                    if proc.poll() is not None:
                        raise RuntimeError(f"xray сразу завершился (exit={proc.returncode})")
                    # Fail-fast: если порт не открылся, реальный HTTP-запрос
                    # заведомо провалится — не тратим на него полный
                    # BENCH_TIMEOUT впустую, это только раздувает время bench.
                    if not await _wait_port_ready("127.0.0.1", port):
                        raise RuntimeError("порт не открылся вовремя")

                    proxy = f"socks5://127.0.0.1:{port}"
                    try:
                        ok_gdebenz = await probe(proxy, BENCH_TEST_URL)
                    except Exception:
                        ok_gdebenz = False
                    try:
                        ok_benzinest = await probe(proxy, BENCH_TEST_URL_BENZINEST)
                    except Exception:
                        ok_benzinest = False

                    latency_ms = int((time.monotonic() - t_start) * 1000)
                    icon = "✅" if (ok_gdebenz or ok_benzinest) else "❌"
                    logger.info(
                        "bench %s %s gdebenz=%s benzinest=%s %dms",
                        icon, cfg.name, "ok" if ok_gdebenz else "-", "ok" if ok_benzinest else "-", latency_ms,
                    )
                    return {"name": cfg.name, "ok": ok_gdebenz, "ok_benzinest": ok_benzinest, "latency_ms": latency_ms}
                except Exception as e:
                    latency_ms = int((time.monotonic() - t_start) * 1000)
                    logger.info("bench ❌ %s ERR %dms: %s", cfg.name, latency_ms, str(e)[:120])
                    return {"name": cfg.name, "ok": False, "ok_benzinest": False, "latency_ms": latency_ms}
                finally:
                    if proc and proc.poll() is None:
                        proc.kill()
                    if tmp_path:
                        try:
                            _os.unlink(tmp_path)
                        except Exception:
                            pass

        tasks = [asyncio.create_task(test_one(cfg, base_port + i)) for i, cfg in enumerate(_xray.configs)]
        results = await asyncio.gather(*tasks)

        for r in results:
            cfg = next((c for c in _xray.configs if c.name == r["name"]), None)
            if cfg:
                cfg.healthy = r["ok"]
                cfg.healthy_benzinest = r.get("ok_benzinest", False)
                cfg.latency_ms = r.get("latency_ms", 0)
                cfg.fails = 0

        _xray.bench_completed = True
        ok_count = len([r for r in results if r["ok"]])
        ok_benzinest_count = len([r for r in results if r.get("ok_benzinest")])
        logger.info(
            "🔍 Bench при старте: %d/%d рабочих по gdebenz, %d/%d по benzinest",
            ok_count, len(results), ok_benzinest_count, len(results),
        )

        ok_list = sorted([r for r in results if r["ok"]], key=lambda r: r["latency_ms"])
        if ok_list:
            best_name = ok_list[0]["name"]
            best_cfg = next((c for c in _xray.configs if c.name == best_name), None)
            if best_cfg and _xray.current is not best_cfg:
                _xray._index = _xray.configs.index(best_cfg)
                await _xray.start(best_cfg)
        else:
            logger.warning("Bench при старте не нашёл рабочих XRay конфигов — отключаю XRay для парса")
            await _xray.stop()
    except Exception as e:
        logger.error("Ошибка bench при старте: %s", e)


app = FastAPI(title="Bypass Proxy", lifespan=lifespan)


# ---------------------------------------------------------------------------
# Модели
# ---------------------------------------------------------------------------
class FetchRequest(BaseModel):
    url: str
    headers: dict[str, str] = {}
    timeout: int = 30
    wait_for_selector: Optional[str] = None


class ImportLinkRequest(BaseModel):
    link: str
    socks_port: int = XRAY_SOCKS_PORT


class ImportSubscriptionRequest(BaseModel):
    url: str
    socks_port: int = XRAY_SOCKS_PORT
    timeout: int = 30
    headers: dict[str, str] = {}
    """Кастомные заголовки для запроса подписки (например User-Agent приложения happ)"""


class ImportHappJsonRequest(BaseModel):
    path: str
    """Путь к JSON-файлу с конфигами формата happ"""


class FetchResponse(BaseModel):
    status: int
    headers: dict[str, str]
    body: str
    method: str = "tls"
    proxy_used: Optional[str] = None


class Http2BombRequest(BaseModel):
    url: str
    padding_kb: int = 256          # размер мусорного тела в КБ
    concurrency: int = 1           # параллельных соединений
    timeout: int = 30
    method: str = "POST"           # HTTP метод (POST/PUT)


class Http2BombResult(BaseModel):
    url: str
    sent: int                      # кол-во отправленных запросов
    ok: int
    errors: int
    results: list[dict]
    proxy_used: Optional[str] = None


# ---------------------------------------------------------------------------
# POST /fetch
# ---------------------------------------------------------------------------
@app.post("/fetch", response_model=FetchResponse)
async def fetch(req: FetchRequest) -> FetchResponse:
    if _xray.configs:
        return await _fetch_via_fleet(req)
    return await _fetch_direct(req)


async def _fetch_direct(req: FetchRequest) -> FetchResponse:
    """Нет ни одного XRay-конфига — прямой запрос без прокси."""
    global _total_requests, _total_ok, _total_fail
    await _throttle_fetch()

    fingerprint = _next_fingerprint()
    try:
        fingerprinter = TLSFingerprinter(fingerprint=fingerprint, proxy=None)
        result: TLSFingerprintResult = await fingerprinter.emulate_client(req.url)
        _total_requests += 1

        if result.status_code in (403, 429):
            _total_fail += 1
            logger.warning(
                "[%d fail/%d] HTTP %d BLOCK | %s | cfg=direct | fp=%s",
                _total_fail, _total_requests, result.status_code, req.url, fingerprint,
            )
            raise HTTPException(status_code=result.status_code, detail=f"HTTP {result.status_code}")

        _total_ok += 1
        logger.info(
            "[%d ok/%d] HTTP %d | %s | cfg=direct | fp=%s",
            _total_ok, _total_requests, result.status_code, req.url, fingerprint,
        )
        return FetchResponse(
            status=result.status_code,
            headers={k: v for k, v in result.headers.items()},
            body=base64.b64encode(result.body).decode(),
            method="tls",
            proxy_used=None,
        )
    except HTTPException:
        raise
    except Exception as e:
        _total_requests += 1
        _total_fail += 1
        logger.warning("[%d fail/%d] ERR | %s | cfg=direct | %s", _total_fail, _total_requests, req.url, e)
        raise HTTPException(status_code=502, detail=str(e))


async def _fetch_via_fleet(req: FetchRequest) -> FetchResponse:
    """Запрос через пул одновременно живых XRay-процессов (см. XRayFleet).

    Слот на всё время запроса занят только текущим вызовом — конкурентность
    равна числу активных слотов пула, а не 1, как раньше при полной
    сериализации через один процесс. Здоровье/карантин/темп запросов
    отслеживаются отдельно на каждый слот, а не глобально.
    """
    global _total_requests, _total_ok, _total_fail

    deadline = time.monotonic() + XRAY_WAIT_READY_SEC
    slot = await _fleet.acquire(deadline)
    if slot is None:
        raise HTTPException(
            status_code=503,
            detail="Нет готового слота XRay-пула: конфиги ещё не прошли bench или все в карантине",
        )

    fingerprint = _next_fingerprint()
    proxy_url = f"socks5://{XRAY_SOCKS_HOST}:{slot.port}"
    try:
        fingerprinter = TLSFingerprinter(fingerprint=fingerprint, proxy=proxy_url)
        result: TLSFingerprintResult = await fingerprinter.emulate_client(req.url)
        _total_requests += 1

        if result.status_code in (403, 429):
            _total_fail += 1
            await _fleet.on_fail(slot, blocked=True)
            logger.warning(
                "[%d fail/%d] HTTP %d BLOCK | %s | slot=%s | fp=%s",
                _total_fail, _total_requests, result.status_code, req.url, slot.config.name, fingerprint,
            )
            raise HTTPException(status_code=result.status_code, detail=f"HTTP {result.status_code}")

        if result.status_code >= 500:
            # 5xx через конкретный слот часто означает проблему именно этого
            # exit-узла (протухший туннель/обрыв до цели), а не самого
            # источника — считаем ошибкой слота, а не тихим "успехом", как
            # было раньше (см. анализ: старый код не банил конфиг за 5xx).
            _total_fail += 1
            await _fleet.on_fail(slot, blocked=False)
            logger.warning(
                "[%d fail/%d] HTTP %d | %s | slot=%s | fp=%s",
                _total_fail, _total_requests, result.status_code, req.url, slot.config.name, fingerprint,
            )
            raise HTTPException(status_code=502, detail=f"HTTP {result.status_code} от источника через {slot.config.name}")

        _total_ok += 1
        _fleet.on_ok(slot)
        logger.info(
            "[%d ok/%d] HTTP %d | %s | slot=%s | fp=%s",
            _total_ok, _total_requests, result.status_code, req.url, slot.config.name, fingerprint,
        )
        return FetchResponse(
            status=result.status_code,
            headers={k: v for k, v in result.headers.items()},
            body=base64.b64encode(result.body).decode(),
            method="tls",
            proxy_used=proxy_url,
        )
    except HTTPException:
        raise
    except Exception as e:
        _total_requests += 1
        _total_fail += 1
        await _fleet.on_fail(slot, blocked=False)
        logger.warning(
            "[%d fail/%d] ERR | %s | slot=%s | %s",
            _total_fail, _total_requests, req.url, slot.config.name, e,
        )
        raise HTTPException(status_code=502, detail=str(e))
    finally:
        _fleet.release(slot)


# ---------------------------------------------------------------------------
# POST /fetch-browser
# ---------------------------------------------------------------------------
@app.post("/fetch-browser", response_model=FetchResponse)
async def fetch_browser(req: FetchRequest) -> FetchResponse:
    await _throttle_browser()

    browser = await _get_browser()
    proxy_url = _get_proxy()
    context = await new_stealth_context(browser, proxy=proxy_url)
    page = await context.new_page()

    try:
        timeout_ms = req.timeout * 1000 if req.timeout else BROWSER_TIMEOUT_MS
        response = await page.goto(req.url, wait_until=BROWSER_WAIT_UNTIL, timeout=timeout_ms)
        if response is None:
            raise RuntimeError("Браузер не вернул ответ")

        status = response.status
        if status in (403, 429):
            await _on_request_fail(blocked=True)
            raise HTTPException(status_code=status, detail=f"HTTP {status}")

        await _on_request_ok()

        if req.wait_for_selector:
            try:
                await page.wait_for_selector(req.wait_for_selector, timeout=10000)
            except Exception:
                logger.warning("Селектор '%s' не появился", req.wait_for_selector)
        else:
            await asyncio.sleep(random.uniform(0.5, 1.5))

        body = await response.body()
        return FetchResponse(
            status=status,
            headers=dict(response.headers),
            body=base64.b64encode(body).decode(),
            method="browser",
            proxy_used=proxy_url,
        )

    except HTTPException:
        raise
    except Exception as e:
        await _on_request_fail()
        logger.warning("/fetch-browser %s: %s", req.url, e)
        raise HTTPException(status_code=502, detail=str(e))
    finally:
        await page.close()
        await context.close()


# ---------------------------------------------------------------------------
# GET /health
# ---------------------------------------------------------------------------
@app.get("/health")
async def health() -> dict[str, Any]:
    healthy_count = len([c for c in _xray.configs if c.healthy is True])
    unhealthy_count = len([c for c in _xray.configs if c.healthy is False])
    untested_count = len([c for c in _xray.configs if c.healthy is None])
    healthy_benzinest_count = len([c for c in _xray.configs if c.healthy_benzinest is True])
    healthy_any_count = len([c for c in _xray.configs if c.healthy is True or c.healthy_benzinest is True])
    return {
        "ok": True,
        "xray_running": _xray.is_running,
        "xray_config": _xray.current.name if _xray.current else None,
        "xray_configs_total": len(_xray.configs),
        "configs_healthy": healthy_count,
        "configs_healthy_benzinest": healthy_benzinest_count,
        "configs_healthy_any": healthy_any_count,
        "configs_unhealthy": unhealthy_count,
        "configs_untested": untested_count,
        "consecutive_fails": _consecutive_fails,
        "requests_until_rotate": max(0, ROTATE_EVERY_N - _request_count),
        "fetch_throttle_ms": FETCH_THROTTLE_MS,
        "browser_ready": _browser is not None,
        # Пул для /fetch (см. XRayFleet) — Node-скрипты читают pool_active,
        # чтобы сами выставлять себе конкурентность вместо жёстко зашитой.
        "pool_target": XRAY_POOL_SIZE,
        "pool_active": len(_fleet.slots),
        "pool_slots": [
            {
                "name": s.config.name,
                "port": s.port,
                "consecutive_fails": s.consecutive_fails,
                "blocked_for_sec": max(0, int(s.config.blocked_until - time.monotonic())),
            }
            for s in _fleet.slots
        ],
        "configs": [
            {
                "name": c.name,
                "healthy": c.healthy,
                "healthy_benzinest": c.healthy_benzinest,
                "latency_ms": c.latency_ms,
                "blocked_for_sec": max(0, int(c.blocked_until - time.monotonic())),
            }
            for c in _xray.configs
        ],
    }


# ---------------------------------------------------------------------------
# POST /xray/bench
# ---------------------------------------------------------------------------
@app.post("/xray/bench")
async def xray_bench() -> dict[str, Any]:
    """Быстрый параллельный тест всех конфигов XRay по двум целям —
    gdebenz.org и benzinest.ru (см. BENCH_TEST_URL/BENCH_TEST_URL_BENZINEST).
    Для каждого конфига: запускает xray, делает запрос к обоим сайтам,
    записывает латентность и статус по каждому отдельно. Запускается
    параллельно с ограничением по портам (BENCH_CONCURRENCY) и отдельным,
    более узким ограничением на сами HTTP-запросы (BENCH_HTTP_CONCURRENCY).
    После теста обновляет healthy/healthy_benzinest/latency_ms для каждого
    конфига и переключается на лучший по gdebenz (используется как единственный
    активный конфиг легаси-менеджера для /fetch-browser).
    """
    if not _xray.configs:
        raise HTTPException(status_code=400, detail="Нет конфигов XRay")

    logger.info(
        "🔍 /xray/bench: тестирую %d конфигов (процессы: %d, HTTP: %d) -> gdebenz + benzinest",
        len(_xray.configs), BENCH_CONCURRENCY, BENCH_HTTP_CONCURRENCY,
    )

    BENCH_TIMEOUT = 6.0

    results: list[dict] = []
    sem = asyncio.Semaphore(BENCH_CONCURRENCY)

    async def probe(proxy: str, url: str) -> tuple[bool, int]:
        async with _bench_http_sem:
            fingerprinter = TLSFingerprinter(fingerprint="chrome_130", proxy=proxy)
            result = await asyncio.wait_for(fingerprinter.emulate_client(url), timeout=BENCH_TIMEOUT)
            return 200 <= result.status_code < 400, result.status_code

    async def test_one(cfg: XRayConfig, port: int) -> dict:
        async with sem:
            proc = None
            tmp_path = None
            t_start = time.monotonic()
            try:
                import json as _json
                with open(cfg.path, encoding="utf-8") as f:
                    cfg_data = _json.load(f)
                for inb in cfg_data.get("inbounds", []):
                    inb["port"] = port
                import tempfile, os as _os
                with tempfile.NamedTemporaryFile(
                    mode="w", suffix=".json", delete=False, encoding="utf-8"
                ) as tmp:
                    _json.dump(cfg_data, tmp)
                    tmp_path = tmp.name

                proc = subprocess.Popen(
                    [XRAY_EXECUTABLE, "run", "-c", tmp_path],
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL,
                )
                if proc.poll() is not None:
                    raise RuntimeError(f"xray сразу завершился (exit={proc.returncode})")
                if not await _wait_port_ready("127.0.0.1", port):
                    raise RuntimeError("порт не открылся вовремя")

                proxy = f"socks5://127.0.0.1:{port}"
                status_gdebenz = 0
                try:
                    ok_gdebenz, status_gdebenz = await probe(proxy, BENCH_TEST_URL)
                except Exception:
                    ok_gdebenz = False
                try:
                    ok_benzinest, _ = await probe(proxy, BENCH_TEST_URL_BENZINEST)
                except Exception:
                    ok_benzinest = False

                latency_ms = int((time.monotonic() - t_start) * 1000)
                icon = "✅" if (ok_gdebenz or ok_benzinest) else "❌"
                logger.info(
                    "bench %s %s gdebenz=%s benzinest=%s %dms",
                    icon, cfg.name, "ok" if ok_gdebenz else "-", "ok" if ok_benzinest else "-", latency_ms,
                )
                return {
                    "name": cfg.name, "ok": ok_gdebenz, "ok_benzinest": ok_benzinest,
                    "status": status_gdebenz, "latency_ms": latency_ms,
                }
            except Exception as e:
                latency_ms = int((time.monotonic() - t_start) * 1000)
                logger.info("bench ❌ %s ERR %dms: %s", cfg.name, latency_ms, str(e)[:120])
                return {"name": cfg.name, "ok": False, "ok_benzinest": False, "error": str(e)[:80], "latency_ms": latency_ms}
            finally:
                if proc and proc.poll() is None:
                    proc.kill()
                if tmp_path:
                    try:
                        _os.unlink(tmp_path)
                    except Exception:
                        pass

    # Раздаём порты начиная с 11000 чтобы не пересекаться с основным XRAY_SOCKS_PORT
    base_port = 11000
    tasks = [
        asyncio.create_task(test_one(cfg, base_port + i))
        for i, cfg in enumerate(_xray.configs)
    ]
    results = await asyncio.gather(*tasks)

    # Обновляем healthy/healthy_benzinest/latency_ms для каждого конфига по результатам теста
    for r in results:
        cfg = next((c for c in _xray.configs if c.name == r["name"]), None)
        if cfg:
            cfg.healthy = r["ok"]
            cfg.healthy_benzinest = r.get("ok_benzinest", False)
            cfg.latency_ms = r.get("latency_ms", 0)
            cfg.fails = 0  # сбрасываем счётчик ошибок после теста

    _xray.bench_completed = True
    ok_list = sorted([r for r in results if r["ok"]], key=lambda r: r["latency_ms"])
    ok_benzinest_list = [r for r in results if r.get("ok_benzinest")]
    fail_list = [r for r in results if not r["ok"] and not r.get("ok_benzinest")]

    healthy_count = len([c for c in _xray.configs if c.healthy is True])
    healthy_benzinest_count = len([c for c in _xray.configs if c.healthy_benzinest is True])
    logger.info(
        "Тест конфигов: %d/%d рабочих по gdebenz, %d/%d по benzinest, лучший: %s (%dмс)",
        len(ok_list), len(results), len(ok_benzinest_list), len(results),
        ok_list[0]["name"] if ok_list else "нет",
        ok_list[0]["latency_ms"] if ok_list else 0,
    )

    # Переключаемся на лучший конфиг по gdebenz если есть рабочие, иначе
    # отключаем XRay: после bench не возвращаемся к непрошедшим конфигам.
    if ok_list:
        best_name = ok_list[0]["name"]
        best_cfg = next((c for c in _xray.configs if c.name == best_name), None)
        if best_cfg:
            _xray._index = _xray.configs.index(best_cfg)
            await _xray.start(best_cfg)
    else:
        logger.warning("/xray/bench не нашёл рабочих XRay конфигов — отключаю XRay")
        await _xray.stop()

    return {
        "total": len(results),
        "ok": len(ok_list),
        "ok_benzinest": len(ok_benzinest_list),
        "failed": len(fail_list),
        "healthy": healthy_count,
        "healthy_benzinest": healthy_benzinest_count,
        "best": ok_list[0] if ok_list else None,
        "results": sorted(results, key=lambda r: (not r["ok"] and not r.get("ok_benzinest"), r.get("latency_ms", 9999))),
    }


# ---------------------------------------------------------------------------
# POST /xray/rotate
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# POST /http2-bomb
# ---------------------------------------------------------------------------
@app.post("/http2-bomb", response_model=Http2BombResult)
async def http2_bomb(req: Http2BombRequest) -> Http2BombResult:
    """HTTP/2 нагрузочное тестирование сервера.
    Отправляет concurrency параллельных HTTP/2 запросов с раздутым телом (padding_kb КБ).
    Использует XRay SOCKS5 прокси если доступен.
    """
    import json as _json, tempfile as _tempfile
    padder = PayloadPadding(padding_size=req.padding_kb * 1024)
    pad_result = await padder.pad_request(b'{"test": 1}')
    body = pad_result.padded_payload
    method = req.method.upper()
    t_global = time.monotonic()

    configs = _xray.get_healthy_configs()
    if not configs:
        raise HTTPException(status_code=503, detail="Нет рабочих XRay конфигов")

    # Поднимаем пул постоянных XRay процессов (один раз, переиспользуется)
    proxy_urls = await _xray_pool.ensure(configs)
    if not proxy_urls:
        raise HTTPException(status_code=503, detail="XRayPool не запустился")

    n_proxies = len(proxy_urls)
    # Создаём пул httpx клиентов — по одному на каждый прокси (HTTP/2 соединение)
    clients: list[httpx.AsyncClient] = []
    try:
        for proxy_url in proxy_urls:
            transport = httpx.AsyncHTTPTransport(proxy=proxy_url, http2=True)
            clients.append(httpx.AsyncClient(transport=transport, follow_redirects=True, timeout=req.timeout))

        all_results: list[dict] = []
        wave_size = n_proxies  # одна волна = по одному запросу через каждый прокси
        waves = max(1, (req.concurrency + wave_size - 1) // wave_size)

        async def _one_shot(idx: int, client: httpx.AsyncClient, proxy_url: str) -> dict:
            t0 = time.monotonic()
            unique_body = os.urandom(8) + body
            try:
                send = client.put if method == "PUT" else client.post
                resp = await send(req.url, content=unique_body,
                                  headers={"Content-Type": "application/octet-stream"})
                latency_ms = int((time.monotonic() - t0) * 1000)
                return {"idx": idx, "ok": True, "status": resp.status_code,
                        "latency_ms": latency_ms, "http_version": resp.http_version,
                        "proxy": proxy_url.split(":")[-1]}
            except Exception as e:
                latency_ms = int((time.monotonic() - t0) * 1000)
                return {"idx": idx, "ok": False, "error": str(e)[:80],
                        "latency_ms": latency_ms, "proxy": proxy_url.split(":")[-1]}

        for wave in range(waves):
            wave_tasks = []
            for j in range(wave_size):
                global_idx = wave * wave_size + j
                if global_idx >= req.concurrency:
                    break
                client = clients[j % n_proxies]
                proxy_url = proxy_urls[j % n_proxies]
                wave_tasks.append(asyncio.create_task(_one_shot(global_idx, client, proxy_url)))
            wave_results = await asyncio.gather(*wave_tasks)
            all_results.extend(wave_results)
            ok_wave = sum(1 for r in wave_results if r["ok"])
            logger.info("bomb wave %d/%d: %d/%d ok", wave + 1, waves, ok_wave, len(wave_tasks))

    finally:
        for c in clients:
            await c.aclose()

    ok_count = sum(1 for r in all_results if r["ok"])
    total_ms = int((time.monotonic() - t_global) * 1000)
    rps = round(len(all_results) / max(total_ms / 1000, 0.001), 1)
    logger.info("bomb done: %d/%d ok, %dms total, %.1f req/s, body=%dKB x %d proxies",
                ok_count, len(all_results), total_ms, rps, req.padding_kb, n_proxies)
    return Http2BombResult(
        url=req.url,
        sent=len(all_results),
        ok=ok_count,
        errors=len(all_results) - ok_count,
        results=all_results,
        proxy_used=f"pool:{n_proxies} proxies, {waves} waves, {rps} req/s",
    )

@app.post("/xray/rotate")
async def xray_rotate() -> dict[str, Any]:
    """Принудительно переключить на следующий XRay конфиг."""
    ok = await _xray.rotate()
    return {"ok": ok, "config": _xray.current.name if _xray.current else None}


# ---------------------------------------------------------------------------
# POST /import/link
# ---------------------------------------------------------------------------
@app.post("/import/link")
async def import_link(req: ImportLinkRequest) -> dict[str, Any]:
    """Импортирует конфиг из share-ссылки (vless://, vmess://, trojan://, ss://)."""
    try:
        result = await import_from_link(req.link, XRAY_CONFIGS_DIR, socks_port=req.socks_port)
        _xray.load_configs()
        return {"ok": True, **result}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


# ---------------------------------------------------------------------------
# POST /import/subscription
# ---------------------------------------------------------------------------
@app.post("/import/subscription")
async def import_subscription(req: ImportSubscriptionRequest) -> dict[str, Any]:
    """Импортирует конфиги из URL подписки.
    
    Поддерживаются форматы:
    - Base64-закодированный список share-ссылок
    - Plain text список share-ссылок
    - JSON-массив готовых XRay-конфигов (формат happ/sing-box)
    
    Параметр headers позволяет передать кастомные заголовки HTTP,
    например User-Agent приложения happ для получения правильного UUID.
    """
    try:
        headers = req.headers if req.headers else None
        results = await import_from_subscription(
            req.url, XRAY_CONFIGS_DIR,
            socks_port=req.socks_port,
            timeout=req.timeout,
            headers=headers,
        )
        _xray.load_configs()
        imported = len([r for r in results if "error" not in r and not r.get("duplicate")])
        skipped_duplicates = len([r for r in results if r.get("duplicate")])
        return {"ok": True, "imported": imported, "skipped_duplicates": skipped_duplicates, "results": results}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


# ---------------------------------------------------------------------------
# POST /import/happ-json
# ---------------------------------------------------------------------------
@app.post("/import/happ-json")
async def import_happ_json(req: ImportHappJsonRequest) -> dict[str, Any]:
    """Импортирует конфиги из JSON-файла формата happ/sing-box.
    
    Файл должен содержать массив XRay-конфигов (как в happ_response.json).
    """
    try:
        results = await import_from_happ_json(req.path, XRAY_CONFIGS_DIR)
        _xray.load_configs()
        imported = len([r for r in results if "error" not in r and not r.get("duplicate")])
        skipped_duplicates = len([r for r in results if r.get("duplicate")])
        return {"ok": True, "imported": imported, "skipped_duplicates": skipped_duplicates, "results": results}
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        app, host=HOST, port=PORT,
        log_level="info",
        access_log=False,
    )
