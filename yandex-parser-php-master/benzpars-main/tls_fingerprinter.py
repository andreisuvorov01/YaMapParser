"""Модуль эмуляции легитимного клиента с подменой TLS/HTTP/2 отпечатков."""

from __future__ import annotations

import asyncio
import logging
import random
from dataclasses import dataclass
from typing import Any, Optional

try:
    from curl_cffi.requests import AsyncSession
    CURL_CFFI_AVAILABLE = True
except ImportError:
    CURL_CFFI_AVAILABLE = False

import httpx

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Профили браузеров для curl_cffi impersonate
# ---------------------------------------------------------------------------
_CURL_PROFILES: dict[str, str] = {
    "chrome_130": "chrome131",
    "chrome_136": "chrome136",
    "firefox_128": "firefox133",
}

# ---------------------------------------------------------------------------
# Пулы реалистичных User-Agent строк
# ---------------------------------------------------------------------------
_UA_CHROME = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 11.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36",
]
_UA_FIREFOX = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:129.0) Gecko/20100101 Firefox/129.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:128.0) Gecko/20100101 Firefox/128.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0",
]

_REFERERS = [
    "https://www.google.com/",
    "https://yandex.ru/",
    "https://www.google.ru/",
    "",
    "",
]

_UA_BY_PROFILE: dict[str, list[str]] = {
    "chrome_130": _UA_CHROME,
    "chrome_136": _UA_CHROME,
    "firefox_128": _UA_FIREFOX,
}


def _chrome_headers(ua: str) -> dict[str, str]:
    referer = random.choice(_REFERERS)
    h = {
        "User-Agent": ua,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
        "Accept-Language": random.choice(["ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7", "ru,en-US;q=0.9,en;q=0.8", "ru-RU,ru;q=0.9,en;q=0.8"]),
        "Accept-Encoding": "gzip, deflate, br, zstd",
        "Cache-Control": random.choice(["max-age=0", "no-cache"]),
        "Sec-Ch-Ua": random.choice([
            '"Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
            '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
            '"Google Chrome";v="136", "Chromium";v="136", "Not.A/Brand";v="99"',
        ]),
        "Sec-Ch-Ua-Mobile": "?0",
        "Sec-Ch-Ua-Platform": random.choice(['"Windows"', '"macOS"']),
        "Sec-Fetch-Dest": "document",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Site": "none" if not referer else "cross-site",
        "Sec-Fetch-User": "?1",
        "Upgrade-Insecure-Requests": "1",
        "Connection": "keep-alive",
    }
    if referer:
        h["Referer"] = referer
    if random.random() < 0.3:
        h["DNT"] = "1"
    return h


def _firefox_headers(ua: str) -> dict[str, str]:
    referer = random.choice(_REFERERS)
    h = {
        "User-Agent": ua,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language": random.choice(["ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3", "ru,en;q=0.7", "ru-RU,ru;q=0.9,en;q=0.5"]),
        "Accept-Encoding": "gzip, deflate, br, zstd",
        "Connection": "keep-alive",
        "Upgrade-Insecure-Requests": "1",
        "Sec-Fetch-Dest": "document",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Site": "none" if not referer else "cross-site",
        "Sec-Fetch-User": "?1",
        "Cache-Control": "max-age=0",
        "TE": "trailers",
    }
    if referer:
        h["Referer"] = referer
    if random.random() < 0.5:
        h["DNT"] = "1"
    return h


def _build_headers(fingerprint: str) -> dict[str, str]:
    ua_pool = _UA_BY_PROFILE.get(fingerprint, _UA_CHROME)
    ua = random.choice(ua_pool)
    if fingerprint.startswith("firefox"):
        return _firefox_headers(ua)
    return _chrome_headers(ua)


# ---------------------------------------------------------------------------
# Глобальный пул сессий curl_cffi — одна сессия на (fingerprint, proxy)
# Избегаем пересоздания AsyncSession при каждом запросе —
# это устраняет конфликт OpenSSL при высокой конкурентности
# ---------------------------------------------------------------------------
_session_pool: dict[tuple, Any] = {}
_session_lock = asyncio.Lock()


async def _get_session(profile: str, proxy: Optional[str]) -> Any:
    """Returns a cached AsyncSession for the given profile+proxy combination."""
    key = (profile, proxy)
    async with _session_lock:
        if key not in _session_pool:
            proxies = {"https": proxy, "http": proxy} if proxy else None
            _session_pool[key] = AsyncSession(impersonate=profile, proxies=proxies)
        return _session_pool[key]


async def close_all_sessions() -> None:
    """Closes all cached sessions. Call on server shutdown."""
    async with _session_lock:
        for session in _session_pool.values():
            try:
                await session.close()
            except Exception:
                pass
        _session_pool.clear()


# ---------------------------------------------------------------------------
# Dataclass результата
# ---------------------------------------------------------------------------
@dataclass
class TLSFingerprintResult:
    url: str
    status_code: int
    headers: dict[str, Any]
    body: bytes
    fingerprint_used: Optional[str] = None


# ---------------------------------------------------------------------------
# Основной класс
# ---------------------------------------------------------------------------
class TLSFingerprinter:
    def __init__(self, fingerprint: str = "chrome_130", proxy: Optional[str] = None):
        self.fingerprint = fingerprint
        self.proxy = proxy

    async def emulate_client(self, target_url: str) -> TLSFingerprintResult:
        if CURL_CFFI_AVAILABLE:
            return await self._request_curl_cffi(target_url)
        return await self._request_httpx(target_url)

    async def _request_curl_cffi(self, target_url: str) -> TLSFingerprintResult:
        profile = _CURL_PROFILES.get(self.fingerprint, "chrome131")
        headers = _build_headers(self.fingerprint)
        # Используем переиспользуемую сессию — не создаём новую при каждом запросе
        session = await _get_session(profile, self.proxy)
        resp = await session.get(
            target_url,
            headers=headers,
            timeout=30,
            allow_redirects=True,
        )
        return TLSFingerprintResult(
            url=target_url,
            status_code=resp.status_code,
            headers=dict(resp.headers),
            body=resp.content,
            fingerprint_used=self.fingerprint,
        )

    async def _request_httpx(self, target_url: str) -> TLSFingerprintResult:
        headers = _build_headers(self.fingerprint)
        transport = (
            httpx.AsyncHTTPTransport(proxy=self.proxy, http2=True)
            if self.proxy
            else httpx.AsyncHTTPTransport(http2=True)
        )
        async with httpx.AsyncClient(
            transport=transport,
            follow_redirects=True,
            timeout=30,
        ) as client:
            resp = await client.get(target_url, headers=headers)
            return TLSFingerprintResult(
                url=target_url,
                status_code=resp.status_code,
                headers=dict(resp.headers),
                body=resp.content,
                fingerprint_used=f"{self.fingerprint}_httpx_fallback",
            )


__all__ = ["TLSFingerprinter", "TLSFingerprintResult", "close_all_sessions"]
