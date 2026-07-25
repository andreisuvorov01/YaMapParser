"""
Импорт XRay конфигов из share-ссылок и подписок.

Поддерживаемые форматы:
  - vless://  — VLESS share link (с параметрами ?type=, ?security=, ?flow=, ?headerType=, ?fp=, ?pbk=, ?sid=, ?sni=, ?spx=)
  - vmess://  — VMess share link (base64-encoded JSON)
  - trojan:// — Trojan share link
  - ss://     — Shadowsocks share link

Подписка: HTTP-ответ, где каждая строка — share-ссылка (обычно base64-encoded bulk).
"""

from __future__ import annotations

import base64
import json
import logging
import re
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Optional
from urllib.parse import parse_qs, unquote, urlparse

import httpx

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Модель распарсенного конфига
# ---------------------------------------------------------------------------

@dataclass
class ParsedConfig:
    """Распарсенный конфиг из share-ссылки."""
    protocol: str          # vless / vmess / trojan / ss
    name: str              # понятное имя (из ссылки или сгенерированное)
    address: str           # IP или домен сервера
    port: int              # порт
    uuid_or_password: str  # UUID (vless/vmess) или пароль (trojan/ss)
    encryption: str = "none"
    flow: str = ""
    network: str = "tcp"
    security: str = "none"
    reality_settings: dict[str, str] = field(default_factory=dict)
    tls_settings: dict[str, str] = field(default_factory=dict)
    ws_settings: dict[str, str] = field(default_factory=dict)
    grpc_settings: dict[str, str] = field(default_factory=dict)
    header_type: str = "none"
    path: str = ""
    host: str = ""
    sni: str = ""
    fingerprint: str = "chrome"
    alpn: list[str] = field(default_factory=lambda: ["h2", "http/1.1"])

    def to_xray_json(self, socks_port: int = 10808) -> dict[str, Any]:
        """
        Конвертирует распарсенный конфиг в JSON-конфиг XRay.
        """
        # Базовый outbound
        outbound: dict[str, Any] = {
            "tag": "proxy",
            "protocol": self.protocol,
            "settings": {},
        }

        if self.protocol == "vless":
            outbound["settings"] = {
                "vnext": [
                    {
                        "address": self.address,
                        "port": self.port,
                        "users": [
                            {
                                "id": self.uuid_or_password,
                                "encryption": self.encryption,
                            }
                        ],
                    }
                ]
            }
            if self.flow:
                outbound["settings"]["vnext"][0]["users"][0]["flow"] = self.flow

        elif self.protocol == "vmess":
            outbound["settings"] = {
                "vnext": [
                    {
                        "address": self.address,
                        "port": self.port,
                        "users": [
                            {
                                "id": self.uuid_or_password,
                                "security": self.encryption or "auto",
                            }
                        ],
                    }
                ]
            }

        elif self.protocol == "trojan":
            outbound["settings"] = {
                "servers": [
                    {
                        "address": self.address,
                        "port": self.port,
                        "password": self.uuid_or_password,
                    }
                ]
            }

        elif self.protocol == "shadowsocks" or self.protocol == "ss":
            outbound["protocol"] = "shadowsocks"
            outbound["settings"] = {
                "servers": [
                    {
                        "address": self.address,
                        "port": self.port,
                        "method": self.encryption or "aes-256-gcm",
                        "password": self.uuid_or_password,
                    }
                ]
            }

        # Stream settings
        stream_settings: dict[str, Any] = {
            "network": self.network,
            "security": self.security,
        }

        if self.security == "reality" and self.reality_settings:
            stream_settings["realitySettings"] = self.reality_settings
            stream_settings["realitySettings"]["fingerprint"] = self.fingerprint
        elif self.security == "tls" and self.tls_settings:
            stream_settings["tlsSettings"] = self.tls_settings

        if self.network == "ws" and self.ws_settings:
            stream_settings["wsSettings"] = self.ws_settings
        elif self.network == "grpc" and self.grpc_settings:
            stream_settings["grpcSettings"] = self.grpc_settings
        elif self.network == "tcp" and self.header_type and self.header_type != "none":
            stream_settings["tcpSettings"] = {
                "header": {
                    "type": self.header_type,
                    "request": {
                        "path": [self.path or "/"],
                        "headers": {
                            "Host": [self.host or self.address],
                        },
                    },
                }
            }

        outbound["streamSettings"] = stream_settings

        # Полный конфиг XRay
        config: dict[str, Any] = {
            "log": {"loglevel": "warning"},
            "inbounds": [
                {
                    "tag": "socks-in",
                    "port": socks_port,
                    "listen": "127.0.0.1",
                    "protocol": "socks",
                    "settings": {"auth": "noauth", "udp": True},
                }
            ],
            "outbounds": [
                outbound,
                {"tag": "direct", "protocol": "freedom"},
            ],
            "routing": {
                "rules": [
                    {
                        "type": "field",
                        "ip": ["geoip:private"],
                        "outboundTag": "direct",
                    }
                ]
            },
        }

        return config


# ---------------------------------------------------------------------------
# Парсеры share-ссылок
# ---------------------------------------------------------------------------

def _b64_decode(data: str) -> str:
    """Декодирует base64 (с padding и без)."""
    data = data.strip()
    # Добавляем padding если нужно
    padding = 4 - len(data) % 4
    if padding != 4:
        data += "=" * padding
    try:
        return base64.urlsafe_b64decode(data).decode("utf-8")
    except Exception:
        try:
            return base64.b64decode(data).decode("utf-8")
        except Exception as e:
            raise ValueError(f"Не удалось декодировать base64: {e}")


def _b64_decode_std(data: str) -> str:
    """Стандартный base64 decode (не urlsafe)."""
    data = data.strip()
    padding = 4 - len(data) % 4
    if padding != 4:
        data += "=" * padding
    return base64.b64decode(data).decode("utf-8")


def parse_vless(link: str) -> ParsedConfig:
    """
    Парсит vless://uuid@host:port?параметры#name
    
    Параметры:
      ?type=tcp|ws|grpc
      ?security=none|tls|reality
      ?flow=xtls-rprx-vision
      ?encryption=none
      ?headerType=none|http
      ?path=/some/path
      ?host=example.com
      ?sni=example.com
      ?fp=chrome|firefox
      ?pbk=publicKey (reality)
      ?sid=shortId (reality)
      ?spx=spiderX (reality)
      ?alpn=h2,http/1.1
    """
    parsed = urlparse(link)
    # userinfo = uuid@host:port
    userinfo = parsed.netloc  # uuid@host:port
    # Разделяем uuid и host:port
    at_idx = userinfo.find("@")
    if at_idx == -1:
        raise ValueError(f"Неверный формат vless://: нет '@' в {userinfo}")
    uuid_str = userinfo[:at_idx]
    host_port = userinfo[at_idx + 1:]
    # host:port
    if ":" in host_port:
        host, port_str = host_port.rsplit(":", 1)
        port = int(port_str)
    else:
        host = host_port
        port = 443

    # Имя из фрагмента
    name = unquote(parsed.fragment) if parsed.fragment else f"vless-{host}"

    # Параметры
    params = parse_qs(parsed.query, keep_blank_values=True)
    # Извлекаем первые значения
    def get_first(key: str, default: str = "") -> str:
        vals = params.get(key, [])
        return vals[0] if vals else default

    network = get_first("type", "tcp")
    security = get_first("security", "none")
    flow = get_first("flow", "")
    encryption = get_first("encryption", "none")
    header_type = get_first("headerType", "none")
    path = get_first("path", "")
    host_h = get_first("host", "")
    sni = get_first("sni", "")
    fingerprint = get_first("fp", "chrome")
    alpn_str = get_first("alpn", "h2,http/1.1")
    alpn = [a.strip() for a in alpn_str.split(",") if a.strip()]

    cfg = ParsedConfig(
        protocol="vless",
        name=name,
        address=host,
        port=port,
        uuid_or_password=uuid_str,
        encryption=encryption,
        flow=flow,
        network=network,
        security=security,
        header_type=header_type,
        path=path,
        host=host_h,
        sni=sni or host_h,
        fingerprint=fingerprint,
        alpn=alpn,
    )

    # Reality settings
    if security == "reality":
        pbk = get_first("pbk", "")
        sid = get_first("sid", "")
        spx = get_first("spx", "")
        cfg.reality_settings = {
            "serverName": sni or host_h or host,
            "publicKey": pbk,
            "shortId": sid or "0",
        }
        if spx:
            cfg.reality_settings["spiderX"] = spx

    # TLS settings
    if security == "tls":
        cfg.tls_settings = {
            "serverName": sni or host_h or host,
            "alpn": alpn,
            "fingerprint": fingerprint,
        }

    # WebSocket settings
    if network == "ws":
        ws_headers = {}
        if host_h:
            ws_headers["Host"] = host_h
        cfg.ws_settings = {
            "path": path or "/",
        }
        if ws_headers:
            cfg.ws_settings["headers"] = ws_headers

    # gRPC settings
    if network == "grpc":
        cfg.grpc_settings = {
            "serviceName": path or "",
        }

    return cfg


def parse_vmess(link: str) -> ParsedConfig:
    """
    Парсит vmess://base64-encoded-json
    
    JSON содержит:
      add — адрес
      port — порт
      id — UUID
      aid — alterId (обычно 0)
      net — network (tcp/ws/grpc)
      type — header type (none/http)
      path — path
      host — host
      tls — tls/none
      scy — security (aes-128-gcm/chacha20-poly1305/auto/none)
      ps — ps (name)
      alpn — alpn
      fp — fingerprint
      sni — serverName
    """
    # vmess:// потом base64
    b64_part = link[len("vmess://"):]
    decoded = _b64_decode(b64_part)
    data = json.loads(decoded)

    name = data.get("ps", "") or f"vmess-{data.get('add', 'unknown')}"
    host = data.get("add", "")
    port = int(data.get("port", 443))
    uuid_str = data.get("id", "")
    aid = int(data.get("aid", 0))
    network = data.get("net", "tcp")
    header_type = data.get("type", "none")
    path = data.get("path", "")
    host_h = data.get("host", "")
    security = "tls" if data.get("tls") == "tls" else "none"
    encryption = data.get("scy", "auto")
    alpn_str = data.get("alpn", "")
    alpn = [a.strip() for a in alpn_str.split(",") if a.strip()] if alpn_str else ["h2", "http/1.1"]
    fingerprint = data.get("fp", "chrome")
    sni = data.get("sni", "")

    cfg = ParsedConfig(
        protocol="vmess",
        name=name,
        address=host,
        port=port,
        uuid_or_password=uuid_str,
        encryption=encryption,
        network=network,
        security=security,
        header_type=header_type,
        path=path,
        host=host_h,
        sni=sni or host_h or host,
        fingerprint=fingerprint,
        alpn=alpn,
    )

    if security == "tls":
        tls_settings: dict[str, Any] = {
            "serverName": sni or host_h or host,
            "fingerprint": fingerprint,
        }
        if alpn:
            tls_settings["alpn"] = alpn
        cfg.tls_settings = tls_settings

    if network == "ws":
        ws_headers = {}
        if host_h:
            ws_headers["Host"] = host_h
        cfg.ws_settings = {
            "path": path or "/",
        }
        if ws_headers:
            cfg.ws_settings["headers"] = ws_headers

    if network == "grpc":
        cfg.grpc_settings = {
            "serviceName": path or "",
        }

    if network == "tcp" and header_type and header_type != "none":
        cfg.header_type = header_type

    return cfg


def parse_trojan(link: str) -> ParsedConfig:
    """
    Парсит trojan://password@host:port?параметры#name
    
    Параметры:
      ?type=tcp|ws|grpc
      ?security=tls|reality
      ?path=/some/path
      ?host=example.com
      ?sni=example.com
      ?fp=chrome
      ?alpn=h2,http/1.1
      ?allowInsecure=0|1
    """
    parsed = urlparse(link)
    userinfo = parsed.netloc  # password@host:port
    at_idx = userinfo.find("@")
    if at_idx == -1:
        raise ValueError(f"Неверный формат trojan://: нет '@' в {userinfo}")
    password = userinfo[:at_idx]
    host_port = userinfo[at_idx + 1:]
    if ":" in host_port:
        host, port_str = host_port.rsplit(":", 1)
        port = int(port_str)
    else:
        host = host_port
        port = 443

    name = unquote(parsed.fragment) if parsed.fragment else f"trojan-{host}"
    params = parse_qs(parsed.query, keep_blank_values=True)

    def get_first(key: str, default: str = "") -> str:
        vals = params.get(key, [])
        return vals[0] if vals else default

    network = get_first("type", "tcp")
    security = get_first("security", "tls")
    path = get_first("path", "")
    host_h = get_first("host", "")
    sni = get_first("sni", "")
    fingerprint = get_first("fp", "chrome")
    alpn_str = get_first("alpn", "h2,http/1.1")
    alpn = [a.strip() for a in alpn_str.split(",") if a.strip()]
    allow_insecure = get_first("allowInsecure", "0") == "1"

    cfg = ParsedConfig(
        protocol="trojan",
        name=name,
        address=host,
        port=port,
        uuid_or_password=password,
        encryption="none",
        network=network,
        security=security,
        path=path,
        host=host_h,
        sni=sni or host_h or host,
        fingerprint=fingerprint,
        alpn=alpn,
    )

    if security == "tls":
        tls_settings: dict[str, Any] = {
            "serverName": sni or host_h or host,
            "fingerprint": fingerprint,
        }
        if alpn:
            tls_settings["alpn"] = alpn
        if allow_insecure:
            tls_settings["allowInsecure"] = True
        cfg.tls_settings = tls_settings

    if network == "ws":
        ws_headers = {}
        if host_h:
            ws_headers["Host"] = host_h
        cfg.ws_settings = {
            "path": path or "/",
        }
        if ws_headers:
            cfg.ws_settings["headers"] = ws_headers

    if network == "grpc":
        cfg.grpc_settings = {
            "serviceName": path or "",
        }

    return cfg


def parse_ss(link: str) -> ParsedConfig:
    """
    Парсит ss://method:password@host:port#name
    или ss://base64(method:password)@host:port#name
    """
    parsed = urlparse(link)
    userinfo = parsed.netloc  # может быть method:pass@host:port или base64@host:port

    # Имя
    name = unquote(parsed.fragment) if parsed.fragment else ""

    # Пробуем распарсить userinfo
    at_idx = userinfo.find("@")
    if at_idx == -1:
        # Возможно вся инфа в base64
        b64_part = userinfo
        decoded = _b64_decode(b64_part)
        # decoded = method:password@host:port
        at_idx2 = decoded.find("@")
        if at_idx2 == -1:
            raise ValueError(f"Неверный формат ss://: {link}")
        method_pass = decoded[:at_idx2]
        host_port = decoded[at_idx2 + 1:]
    else:
        method_pass_b64 = userinfo[:at_idx]
        host_port = userinfo[at_idx + 1:]
        # method:password может быть в base64 или plaintext
        try:
            decoded = _b64_decode(method_pass_b64)
            method_pass = decoded
        except Exception:
            method_pass = method_pass_b64

    if ":" in method_pass:
        method, password = method_pass.split(":", 1)
    else:
        method = "aes-256-gcm"
        password = method_pass

    if ":" in host_port:
        host, port_str = host_port.rsplit(":", 1)
        port = int(port_str)
    else:
        host = host_port
        port = 443

    if not name:
        name = f"ss-{host}"

    return ParsedConfig(
        protocol="shadowsocks",
        name=name,
        address=host,
        port=port,
        uuid_or_password=password,
        encryption=method,
        network="tcp",
        security="none",
    )


# ---------------------------------------------------------------------------
# Диспетчер парсинга
# ---------------------------------------------------------------------------

def parse_share_link(link: str) -> ParsedConfig:
    """
    Определяет тип ссылки и парсит её.
    
    Поддерживает: vless://, vmess://, trojan://, ss://
    """
    link = link.strip()

    if link.startswith("vless://"):
        return parse_vless(link)
    elif link.startswith("vmess://"):
        return parse_vmess(link)
    elif link.startswith("trojan://"):
        return parse_trojan(link)
    elif link.startswith("ss://"):
        return parse_ss(link)
    else:
        raise ValueError(f"Неподдерживаемый протокол: {link[:20]}...")


# ---------------------------------------------------------------------------
# Работа с подписками
# ---------------------------------------------------------------------------

async def fetch_subscription(
    url: str,
    timeout: int = 30,
    headers: Optional[dict[str, str]] = None,
) -> list[str]:
    """
    Скачивает подписку по URL.
    
    Подписка — это HTTP-ответ, где тело — это:
    1. Base64-закодированный список share-ссылок (по одной на строку)
    2. Или просто список share-ссылок в plain text (по одной на строку)
    3. Или JSON-массив готовых XRay-конфигов (формат happ/sing-box)
    
    Параметр headers позволяет передать кастомные заголовки,
    например User-Agent приложения happ для получения правильного UUID.
    
    Возвращает список share-ссылок.
    """
    default_headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "Accept": "*/*",
    }
    if headers:
        default_headers.update(headers)

    async with httpx.AsyncClient(timeout=timeout, follow_redirects=True) as client:
        resp = await client.get(url, headers=default_headers)
        resp.raise_for_status()
        body = resp.text.strip()

    # Пробуем распарсить как JSON (формат happ/sing-box)
    try:
        json_data = json.loads(body)
        if isinstance(json_data, list):
            logger.info("Подписка %s в формате JSON (%d конфигов)", url, len(json_data))
            # Это массив готовых XRay-конфигов — сохраняем их напрямую
            return json_data  # вернём как есть, обработаем выше
    except (json.JSONDecodeError, ValueError):
        pass

    # Пробуем декодировать как base64
    lines = _try_decode_subscription(body)

    # Фильтруем только строки, похожие на share-ссылки
    share_links = []
    for line in lines:
        line = line.strip()
        if line and any(line.startswith(prefix) for prefix in ["vless://", "vmess://", "trojan://", "ss://"]):
            share_links.append(line)

    if not share_links:
        logger.warning("Подписка %s не содержит share-ссылок", url)

    return share_links


def _try_decode_subscription(body: str) -> list[str]:
    """
    Пробует декодировать тело подписки.
    
    Сначала пробует как base64, потом как plain text.
    """
    # Убираем пробелы
    clean = body.strip()

    # Пробуем как base64
    try:
        decoded = _b64_decode(clean)
        lines = decoded.splitlines()
        # Проверяем, похоже ли на share-ссылки
        if any(line.strip().startswith(prefix) for line in lines for prefix in ["vless://", "vmess://", "trojan://", "ss://"]):
            return lines
    except Exception:
        pass

    # Пробуем как plain text
    lines = clean.splitlines()
    if any(line.strip().startswith(prefix) for line in lines for prefix in ["vless://", "vmess://", "trojan://", "ss://"]):
        return lines

    # Если ничего не нашли, возвращаем как есть
    return lines




def _canonical_config(config: dict[str, Any]) -> str:
    return json.dumps(config, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def _find_duplicate_config(config_dir: Path, config: dict[str, Any]) -> Optional[Path]:
    wanted = _canonical_config(config)
    for existing in sorted(config_dir.glob("*.json")):
        try:
            with open(existing, "r", encoding="utf-8") as f:
                current = json.load(f)
        except Exception:
            continue
        if isinstance(current, dict) and _canonical_config(current) == wanted:
            return existing
    return None


def _write_unique_config(config_dir: Path, safe_name: str, config: dict[str, Any], display_name: str) -> dict[str, Any]:
    duplicate = _find_duplicate_config(config_dir, config)
    if duplicate:
        logger.info("Конфиг уже добавлен: %s (%s)", duplicate, display_name)
        return {"file": str(duplicate), "duplicate": True}

    filename = f"{safe_name}.json"
    filepath = config_dir / filename
    counter = 1
    while filepath.exists():
        filename = f"{safe_name}_{counter}.json"
        filepath = config_dir / filename
        counter += 1

    with open(filepath, "w", encoding="utf-8") as f:
        json.dump(config, f, indent=2, ensure_ascii=False)

    logger.info("Сохранён конфиг: %s (%s)", filepath, display_name)
    return {"file": str(filepath), "duplicate": False}

# ---------------------------------------------------------------------------
# Сохранение конфига в файл
# ---------------------------------------------------------------------------

def save_config(parsed: ParsedConfig, configs_dir: str, socks_port: int = 10808) -> dict[str, Any]:
    """
    Сохраняет распарсенный конфиг в JSON-файл в configs_dir.

    Возвращает {file, duplicate}; duplicate=True означает, что такой же
    нормализованный конфиг уже есть и новый файл не создавался.
    """
    config_dir = Path(configs_dir)
    config_dir.mkdir(parents=True, exist_ok=True)

    safe_name = re.sub(r'[^a-zA-Z0-9_-]', '_', parsed.name)[:50]
    if not safe_name:
        safe_name = f"config_{uuid.uuid4().hex[:8]}"

    config_json = parsed.to_xray_json(socks_port=socks_port)
    return _write_unique_config(config_dir, safe_name, config_json, parsed.name)


def save_xray_config_direct(
    xray_config: dict[str, Any],
    configs_dir: str,
    name: str = "",
) -> str:
    """
    Сохраняет готовый XRay-конфиг (словарь) в JSON-файл.
    
    Используется для импорта из формата happ/sing-box, где конфиги
    уже приходят в готовом виде.
    
    Нормализует конфиг для совместимости с XRayManager:
    - Оставляет только один socks inbound (удаляет http и дубликаты)
    - Удаляет секции observatory, routing.balancers, meta, dns
    - Добавляет direct outbound если его нет
    
    Возвращает путь к сохранённому файлу.
    """
    config_dir = Path(configs_dir)
    config_dir.mkdir(parents=True, exist_ok=True)

    # Извлекаем имя из remarks или генерируем
    if not name:
        name = xray_config.get("remarks", "") or xray_config.get("tag", "") or f"config_{uuid.uuid4().hex[:8]}"

    safe_name = re.sub(r'[^a-zA-Z0-9_-]', '_', name)[:50]
    if not safe_name:
        safe_name = f"config_{uuid.uuid4().hex[:8]}"

    # Создаём чистую копию конфига, чтобы не мутировать оригинал
    config = {}
    config["log"] = xray_config.get("log", {"loglevel": "warning"})

    # --- Inbounds: оставляем только один socks ---
    inbounds = xray_config.get("inbounds", [])
    socks_inbounds = [i for i in inbounds if i.get("protocol") == "socks"]
    if socks_inbounds:
        # Берём первый socks inbound и нормализуем его
        inbound = dict(socks_inbounds[0])
        inbound["tag"] = "socks-in"
        inbound["port"] = 10808
        inbound["listen"] = "127.0.0.1"
        if "sniffing" in inbound:
            del inbound["sniffing"]
        config["inbounds"] = [inbound]
    else:
        # Создаём стандартный socks inbound
        config["inbounds"] = [
            {
                "tag": "socks-in",
                "port": 10808,
                "listen": "127.0.0.1",
                "protocol": "socks",
                "settings": {"auth": "noauth", "udp": True},
            }
        ]

    # --- Outbounds: берём прокси outbound + добавляем direct ---
    outbounds = xray_config.get("outbounds", [])
    # Ищем первый не-direct/non-freedom/blackhole outbound — это прокси
    proxy_outbounds = [
        o for o in outbounds
        if o.get("protocol") not in ("freedom", "blackhole")
    ]
    if proxy_outbounds:
        config["outbounds"] = [dict(proxy_outbounds[0])]
        config["outbounds"][0]["tag"] = "proxy"
    else:
        config["outbounds"] = [{"tag": "proxy", "protocol": "freedom"}]

    # Добавляем direct
    config["outbounds"].append({"tag": "direct", "protocol": "freedom"})

    # --- Routing: без geoip (не требует geoip.dat) ---
    # Используем упрощённое правило: все запросы идут через proxy,
    # direct зарезервирован для будущего использования
    config["routing"] = {
        "domainStrategy": "AsIs",
        "rules": [
            {
                "type": "field",
                "outboundTag": "direct",
                "port": "0-53",
            }
        ]
    }

    return _write_unique_config(config_dir, safe_name, config, name)


# ---------------------------------------------------------------------------
# High-level API
# ---------------------------------------------------------------------------

async def import_from_link(
    link: str,
    configs_dir: str,
    socks_port: int = 10808,
) -> dict[str, Any]:
    """
    Импортирует конфиг из share-ссылки.
    
    Возвращает информацию о импортированном конфиге.
    """
    parsed = parse_share_link(link)
    saved = save_config(parsed, configs_dir, socks_port=socks_port)

    return {
        "name": parsed.name,
        "protocol": parsed.protocol,
        "address": parsed.address,
        "port": parsed.port,
        **saved,
    }


async def import_from_subscription(
    url: str,
    configs_dir: str,
    socks_port: int = 10808,
    timeout: int = 30,
    headers: Optional[dict[str, str]] = None,
) -> list[dict[str, Any]]:
    """
    Импортирует конфиги из подписки.
    
    Поддерживаются форматы:
    - Base64-закодированный список share-ссылок
    - Plain text список share-ссылок
    - JSON-массив готовых XRay-конфигов (формат happ/sing-box)
    
    Параметр headers позволяет передать кастомные заголовки HTTP
    для получения правильного ответа от сервера подписки.
    
    Возвращает список информации об импортированных конфигах.
    """
    data = await fetch_subscription(url, timeout=timeout, headers=headers)
    results = []

    # Если пришёл список словарей — это JSON-формат happ (готовые XRay-конфиги)
    if data and isinstance(data[0], dict):
        logger.info("Импорт %d конфигов из JSON-формата", len(data))
        for item in data:
            try:
                name = item.get("remarks", "") or item.get("tag", "")
                saved = save_xray_config_direct(item, configs_dir, name=name)
                results.append({
                    "name": name,
                    "protocol": "xray-json",
                    **saved,
                })
            except Exception as e:
                logger.warning("Ошибка импорта JSON-конфига: %s", e)
                results.append({"error": str(e)})
        return results

    # Обычные share-ссылки
    for link in data:
        try:
            result = await import_from_link(link, configs_dir, socks_port=socks_port)
            results.append(result)
        except Exception as e:
            logger.warning("Ошибка импорта ссылки %s...: %s", link[:50], e)
            results.append({
                "error": str(e),
                "link_preview": link[:80],
            })

    return results


async def import_from_happ_json(
    json_path: str,
    configs_dir: str,
) -> list[dict[str, Any]]:
    """
    Импортирует конфиги из JSON-файла формата happ.
    
    Файл должен содержать массив XRay-конфигов (как в happ_response.json).
    
    Возвращает список информации об импортированных конфигах.
    """
    with open(json_path, "r", encoding="utf-8") as f:
        data = json.load(f)

    if not isinstance(data, list):
        raise ValueError("Файл должен содержать JSON-массив конфигов")

    results = []
    for item in data:
        try:
            name = item.get("remarks", "") or item.get("tag", "")
            saved = save_xray_config_direct(item, configs_dir, name=name)
            results.append({
                "name": name,
                "protocol": "xray-json",
                **saved,
            })
        except Exception as e:
            logger.warning("Ошибка импорта JSON-конфига: %s", e)
            results.append({"error": str(e)})

    return results


__all__ = [
    "ParsedConfig",
    "parse_share_link",
    "parse_vless",
    "parse_vmess",
    "parse_trojan",
    "parse_ss",
    "fetch_subscription",
    "save_config",
    "save_xray_config_direct",
    "import_from_link",
    "import_from_subscription",
    "import_from_happ_json",
]