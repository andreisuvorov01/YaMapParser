"""Модуль обнаружения реального сервера (Origin IP) для обхода Cloudflare Enterprise."""

from __future__ import annotations

import asyncio
import hashlib
import logging
import socket
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Optional

import aiohttp

logger = logging.getLogger(__name__)


@dataclass
class OriginEvidence:
    """Доказательство обнаружения Origin IP."""

    source: str
    value: Any
    confidence: float
    timestamp: datetime = field(default_factory=datetime.utcnow)


@dataclass
class OriginDiscoveryResult:
    """Результат поиска Origin IP."""

    origin_ip: Optional[str] = None
    confidence: float = 0.0
    evidence: list[OriginEvidence] = field(default_factory=list)
    failed_checks: list[str] = field(default_factory=list)


def _is_cloudflare_ip(ip: str) -> bool:
    """Проверяет, является ли IP адресом Cloudflare (CIDR-диапазоны)."""
    cloudflare_prefixes = (
        "103.21.", "103.22.", "103.23.",
        "104.16.", "104.17.", "104.18.", "104.19.", "104.20.",
        "104.21.", "104.22.", "104.23.", "104.24.", "104.25.",
        "108.162.", "141.101.", "162.158.", "172.64.", "172.65.",
        "172.66.", "172.67.", "172.68.", "172.69.", "172.70.",
        "172.71.", "188.114.", "190.93.", "197.234.", "198.41.",
    )
    return any(ip.startswith(p) for p in cloudflare_prefixes)


class DNSHistoryClient:
    """Клиент для получения исторических DNS записей через HackerTarget (бесплатный API)."""

    def __init__(self, session: Optional[aiohttp.ClientSession] = None):
        self._own_session = session is None
        self.session = session or aiohttp.ClientSession()

    async def lookup(self, domain: str) -> list[OriginEvidence]:
        """Ищет Origin IP через hackertarget.com (бесплатный, без ключа)."""
        evidence_list: list[OriginEvidence] = []
        try:
            url = f"https://api.hackertarget.com/hostsearch/?q={domain}"
            async with self.session.get(url, timeout=aiohttp.ClientTimeout(total=15)) as resp:
                if resp.status == 200:
                    text = await resp.text()
                    for line in text.splitlines():
                        parts = line.split(",")
                        if len(parts) == 2:
                            ip = parts[1].strip()
                            if ip and not _is_cloudflare_ip(ip):
                                evidence_list.append(OriginEvidence(
                                    source="dns_history",
                                    value=ip,
                                    confidence=0.75,
                                ))
        except Exception as e:
            logger.warning(f"DNS history lookup failed for {domain}: {e}")
        return evidence_list

    async def close(self) -> None:
        if self._own_session:
            await self.session.close()


class SSLCertificateScanner:
    """Сканирует SSL-сертификаты через crt.sh для поиска поддоменов."""

    def __init__(self, session: Optional[aiohttp.ClientSession] = None):
        self._own_session = session is None
        self.session = session or aiohttp.ClientSession()

    async def scan(self, domain: str) -> list[OriginEvidence]:
        """Ищет поддомены через crt.sh и резолвит их в IP."""
        evidence_list: list[OriginEvidence] = []
        try:
            url = f"https://crt.sh/?q=%.{domain}&output=json"
            async with self.session.get(url, timeout=aiohttp.ClientTimeout(total=20)) as resp:
                if resp.status == 200:
                    data = await resp.json(content_type=None)
                    seen: set[str] = set()
                    for cert in data:
                        name = cert.get("name_value", "")
                        for subdomain in name.splitlines():
                            subdomain = subdomain.strip().lstrip("*.")
                            if subdomain and subdomain not in seen and subdomain.endswith(domain):
                                seen.add(subdomain)
                                try:
                                    ip = await asyncio.get_event_loop().run_in_executor(
                                        None, socket.gethostbyname, subdomain
                                    )
                                    if not _is_cloudflare_ip(ip):
                                        evidence_list.append(OriginEvidence(
                                            source="ssl_cert",
                                            value=ip,
                                            confidence=0.80,
                                        ))
                                except socket.gaierror:
                                    pass
        except Exception as e:
            logger.warning(f"SSL certificate scan failed for {domain}: {e}")
        return evidence_list

    async def close(self) -> None:
        if self._own_session:
            await self.session.close()


class FaviconHashCalculator:
    """Вычисляет хэш favicon для идентификации целевого сервера."""

    def __init__(self, session: Optional[aiohttp.ClientSession] = None):
        self._own_session = session is None
        self.session = session or aiohttp.ClientSession()

    async def compute_hash(self, domain: str) -> list[OriginEvidence]:
        """Получает favicon и вычисляет его MD5 (совместим с Shodan favicon search)."""
        evidence_list: list[OriginEvidence] = []
        try:
            url = f"https://{domain}/favicon.ico"
            headers = {"User-Agent": "Mozilla/5.0 (compatible; FaviconHasher/1.0)"}
            async with self.session.get(url, headers=headers, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                if resp.status == 200:
                    favicon_data = await resp.read()
                    sha256 = hashlib.sha256(favicon_data).hexdigest()[:16]
                    md5 = hashlib.md5(favicon_data).hexdigest()
                    evidence_list.append(OriginEvidence(
                        source="favicon_hash",
                        value={"sha256": sha256, "md5": md5},
                        confidence=0.60,
                    ))
        except Exception as e:
            logger.warning(f"Favicon hash computation failed for {domain}: {e}")
        return evidence_list

    async def close(self) -> None:
        if self._own_session:
            await self.session.close()


class DirectDNSResolver:
    """Резолвит домен напрямую, минуя Cloudflare (через публичные DNS)."""

    async def resolve(self, domain: str) -> list[OriginEvidence]:
        """Пробует резолвить домен через Google/Cloudflare DNS и фильтрует CF-IP."""
        evidence_list: list[OriginEvidence] = []
        try:
            ips = await asyncio.get_event_loop().run_in_executor(
                None, socket.getaddrinfo, domain, None
            )
            for info in ips:
                ip = info[4][0]
                if not _is_cloudflare_ip(ip):
                    evidence_list.append(OriginEvidence(
                        source="direct_dns",
                        value=ip,
                        confidence=0.50,
                    ))
        except Exception as e:
            logger.warning(f"Direct DNS resolve failed for {domain}: {e}")
        return evidence_list


class OriginDiscovery:
    """Основной класс для обнаружения Origin IP."""

    def __init__(self):
        self._session: Optional[aiohttp.ClientSession] = None
        self.dns_history: Optional[DNSHistoryClient] = None
        self.ssl_scanner: Optional[SSLCertificateScanner] = None
        self.favicon_hasher: Optional[FaviconHashCalculator] = None
        self.direct_resolver = DirectDNSResolver()

    async def _ensure_session(self) -> None:
        if self._session is None or self._session.closed:
            self._session = aiohttp.ClientSession()
            self.dns_history = DNSHistoryClient(self._session)
            self.ssl_scanner = SSLCertificateScanner(self._session)
            self.favicon_hasher = FaviconHashCalculator(self._session)

    async def find_origin_ip(self, domain: str) -> OriginDiscoveryResult:
        """
        Параллельно запускает все проверки и агрегирует результаты.
        Возвращает OriginDiscoveryResult с наиболее вероятным Origin IP.
        """
        await self._ensure_session()
        result = OriginDiscoveryResult()

        tasks = [
            self.dns_history.lookup(domain),
            self.ssl_scanner.scan(domain),
            self.favicon_hasher.compute_hash(domain),
            self.direct_resolver.resolve(domain),
        ]

        results = await asyncio.gather(*tasks, return_exceptions=True)

        all_evidence: list[OriginEvidence] = []
        for r in results:
            if isinstance(r, list):
                all_evidence.extend(r)
            elif isinstance(r, Exception):
                logger.warning(f"Проверка завершилась с ошибкой: {r}")

        if not all_evidence:
            result.failed_checks.append("no_origin_ip_found")
            return result

        # Группируем по IP и суммируем confidence
        ip_scores: dict[str, float] = {}
        ip_evidence: dict[str, list[OriginEvidence]] = {}
        for ev in all_evidence:
            ip = ev.value if isinstance(ev.value, str) else str(ev.value)
            ip_scores[ip] = ip_scores.get(ip, 0.0) + ev.confidence
            ip_evidence.setdefault(ip, []).append(ev)

        best_ip = max(ip_scores, key=lambda k: ip_scores[k])
        total = sum(ip_scores.values())
        count = len(ip_scores)

        result.origin_ip = best_ip
        result.confidence = min(ip_scores[best_ip] / max(total / count, 1.0), 1.0)
        result.evidence = all_evidence

        return result

    async def close(self) -> None:
        if self._session and not self._session.closed:
            await self._session.close()


__all__ = ["OriginDiscovery", "OriginEvidence", "OriginDiscoveryResult"]
