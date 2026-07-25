"""Cloudflare Enterprise Bypass Parser — модуль обхода защищённых веб-ресурсов."""

from __future__ import annotations

import asyncio
import logging
from dataclasses import dataclass, field
from typing import Any, Optional
from urllib.parse import urlparse

from .config_loader import Config, ConfigLoader
from .human_behavior import HumanBehavior
from .origin_discovery import OriginDiscovery
from .payload_padding import PayloadPadding
from .stealth_browser import StealthBrowser
from .tls_fingerprinter import TLSFingerprinter

logger = logging.getLogger(__name__)


@dataclass
class ParserConfig:
    """Конфигурация парсера с параметрами для всех уровней обхода."""

    user_agent: str = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
    stealth_plugins: list[str] = field(default_factory=lambda: ["stealth", "undetectable"])
    tls_fingerprint: str = "chrome_130"
    padding_size: int = 256 * 1024
    max_retries: int = 3
    use_browser: bool = False  # True — Playwright, False — TLS fingerprint (быстрее)
    headless: bool = True


@dataclass
class ParseResult:
    """Результат парсинга с метаданными о применённых методах обхода."""

    url: str
    status_code: int
    body: bytes
    headers: dict[str, Any]
    method_used: str  # "tls_fingerprint" | "stealth_browser"
    origin_ip: Optional[str] = None
    retries: int = 0
    success: bool = False


class CloudflareBypassParser:
    """Основной класс для асинхронного парсинга с поддержкой всех методов обхода."""

    def __init__(self, config: Optional[ParserConfig] = None):
        self.config = config or ParserConfig()
        self.origin_discovery = OriginDiscovery()
        self.tls_fingerprinter = TLSFingerprinter(fingerprint=self.config.tls_fingerprint)
        self.stealth_browser = StealthBrowser(self.config.user_agent)
        self.human_behavior = HumanBehavior()
        self.payload_padding = PayloadPadding(self.config.padding_size)

    async def parse(self, url: str, max_retries: Optional[int] = None) -> ParseResult:
        """
        Парсит URL с обходом Cloudflare.

        Стратегия:
        1. Пробуем TLS fingerprint (быстро, без браузера)
        2. Если 403/429/503 — переключаемся на Stealth Browser (Playwright)
        3. При каждой попытке применяем случайные задержки (human behavior)
        """
        retries = max_retries if max_retries is not None else self.config.max_retries
        domain = urlparse(url).netloc

        result = ParseResult(
            url=url,
            status_code=0,
            body=b"",
            headers={},
            method_used="tls_fingerprint",
        )

        # Пробуем обнаружить Origin IP (не блокирует основной запрос)
        try:
            origin_result = await self.origin_discovery.find_origin_ip(domain)
            result.origin_ip = origin_result.origin_ip
        except Exception as e:
            logger.warning(f"Origin discovery failed: {e}")

        # Попытки через TLS fingerprint
        for attempt in range(retries):
            result.retries = attempt
            try:
                delay = await self.human_behavior.simulate_keypress()
                await asyncio.sleep(delay / 1000)

                tls_result = await self.tls_fingerprinter.emulate_client(url)
                result.status_code = tls_result.status_code
                result.headers = tls_result.headers
                result.body = tls_result.body
                result.method_used = "tls_fingerprint"

                if tls_result.status_code not in (403, 429, 503):
                    result.success = True
                    return result

                logger.info(f"TLS fingerprint вернул {tls_result.status_code}, попытка {attempt + 1}/{retries}")

            except Exception as e:
                logger.warning(f"TLS fingerprint attempt {attempt + 1} failed: {e}")

        # Fallback на Stealth Browser если включён
        if self.config.use_browser:
            result.method_used = "stealth_browser"
            try:
                browser_result = await self.stealth_browser.launch_stealthed(headless=self.config.headless)
                if browser_result.page is not None:
                    page = browser_result.page
                    response = await page.goto(url, wait_until="networkidle", timeout=30000)
                    if response:
                        result.status_code = response.status
                        result.body = await response.body()
                        result.headers = dict(response.headers)
                        result.success = result.status_code not in (403, 429, 503)
                    await self.stealth_browser.close()
            except Exception as e:
                logger.error(f"Stealth browser failed: {e}")

        return result

    async def close(self) -> None:
        """Освобождает все ресурсы."""
        await self.origin_discovery.close()
        await self.stealth_browser.close()


__all__ = [
    "ParserConfig",
    "ParseResult",
    "CloudflareBypassParser",
]
