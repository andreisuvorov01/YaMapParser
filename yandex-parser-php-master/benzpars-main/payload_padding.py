"""Модуль раздувания запроса мусорными данными (Payload Padding) для обхода WAF с Fail Open."""

from __future__ import annotations

import asyncio
import logging
import os
import random
from dataclasses import dataclass, field
from typing import Any, Optional

logger = logging.getLogger(__name__)


@dataclass
class PayloadPaddingResult:
    """Результат раздувания запроса."""

    padded_payload: bytes
    padding_size: int
    original_size: int
    integrity_hash: Optional[str] = None  # SHA-256 хэш оригинальной нагрузки


class PayloadPadding:
    """
    Раздувание запроса мусорными данными в начале тела для обхода WAF с режимом Fail Open.
    
    Добавление сотен килобайт случайных данных перед полезной нагрузкой,
    сохранение целостности ответа (парсинг только нужного фрагмента).
    """

    def __init__(self, padding_size: int = 256 * 1024):
        self.padding_size = padding_size  # по умолчанию 256 КБ
        self._random_data_cache: bytes = b""

    async def pad_request(self, payload: bytes) -> PayloadPaddingResult:
        if not payload:
            raise ValueError("payload cannot be empty")
        padding_data = await asyncio.get_event_loop().run_in_executor(
            None, self._generate_random_padding, self.padding_size
        )
        import hashlib
        integrity_hash = hashlib.sha256(payload).hexdigest()[:16]
        return PayloadPaddingResult(
            padded_payload=padding_data + payload,
            padding_size=self.padding_size,
            original_size=len(payload),
            integrity_hash=integrity_hash,
        )

    def _generate_random_padding(self, size: int) -> bytes:
        if self._random_data_cache and len(self._random_data_cache) >= size:
            return self._random_data_cache[:size]
        padding = os.urandom(size)
        self._random_data_cache = padding
        return padding

    def extract_original(self, padded_payload: bytes, integrity_hash: Optional[str] = None) -> bytes:
        """
        Извлекает оригинальную нагрузку из раздутанного запроса.
        
        Если integrity_hash передана, проверяет целостность ответа.
        Возвращает только оригинальный payload без мусора.
        """
        if not padded_payload:
            raise ValueError("padded_payload не может быть пустым")

        # Ищем границу между padding и оригинальной нагрузкой (обычно это начало JSON или HTML)
        import json
        try:
            first_json_char = padded_payload.index(b"{") or padded_payload.index(b"[")
        except ValueError:
            first_json_char = 0

        original_payload = padded_payload[first_json_char:]

        if integrity_hash and not self._verify_integrity(original_payload, integrity_hash):
            logger.warning("Целостность оригинальной нагрузки нарушена!")

        return original_payload

    def _verify_integrity(self, payload: bytes, expected_hash: str) -> bool:
        """
        Проверяет целостность оригинальной нагрузки по хэшу.
        """
        import hashlib
        computed_hash = hashlib.sha256(payload).hexdigest()[:16]
        return computed_hash == expected_hash


__all__ = ["PayloadPadding", "PayloadPaddingResult"]