"""Модуль загрузки конфигурации для системы обхода защищённых веб-ресурсов."""

from __future__ import annotations

import json
import logging
import os
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Optional

logger = logging.getLogger(__name__)


@dataclass
class Config:
    """Конфигурация системы обхода защищённых веб-ресурсов."""

    user_agent: str = field(default="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
    stealth_plugins: list[str] = field(default_factory=lambda: ["stealth", "undetectable"])
    tls_fingerprint: Optional[str] = None
    padding_size: int = 256 * 1024  # 256 КБ
    max_retries: int = 3
    timeout_seconds: float = 30.0
    proxy_url: Optional[str] = None
    locale_consistency: bool = True
    audio_context_emulated: bool = True
    headers: dict[str, str] = field(default_factory=dict)
    cookies: list[dict[str, Any]] = field(default_factory=list)


class ConfigLoader:
    """
    Загрузчик конфигурации с поддержкой YAML/JSON и дефолтных значений.
    
    Функционал:
    - Парсинг YAML (через ruamel.yaml) или JSON
    - Объединение пользовательских настроек с дефолтными
    - Валидация обязательных полей
    - Логирование ошибок при загрузке
    """

    def __init__(self, config_path: Optional[str] = None):
        self.config_path = Path(config_path) if config_path else None
        self._config_cache: Optional[Config] = None

    def load(self) -> Config:
        """
        Загружает конфигурацию из файла или возвращает дефолтную.
        
        Приоритет:
        1. Файл, указанный в config_path (если существует)
        2. Файл config.yaml в текущей директории
        3. Дефолтная конфигурация
        """
        if self._config_cache is not None:
            return self._config_cache

        # Попытка загрузить из указанного пути
        if self.config_path and self.config_path.exists():
            config = self._parse_file(self.config_path)
            logger.info(f"Конфигурация загружена из {self.config_path}")
            return config

        # Поиск в текущей директории
        for candidate in ["config.yaml", "config.json"]:
            path = Path.cwd() / candidate
            if path.exists():
                config = self._parse_file(path)
                logger.info(f"Конфигурация загружена из {path}")
                return config

        # Возвращаем дефолтную конфигурацию
        default_config = Config()
        logger.warning("Использование дефолтной конфигурации")
        self._config_cache = default_config
        return default_config

    def _parse_file(self, path: Path) -> Config:
        """
        Парсит файл конфигурации (YAML или JSON).
        
        Возвращает объект Config с объединёнными значениями.
        """
        try:
            if path.suffix == ".yaml":
                return self._parse_yaml(path)
            elif path.suffix == ".json":
                return self._parse_json(path)
            else:
                raise ValueError(f"Неподдерживаемый формат файла: {path.suffix}")
        except Exception as e:
            logger.error(f"Ошибка при загрузке конфигурации из {path}: {e}")
            # Возвращаем дефолтную конфигурацию
            return Config()

    def _parse_yaml(self, path: Path) -> Config:
        """
        Парсит YAML-конфигурацию.
        
        Использует ruamel.yaml для сохранения типов данных.
        """
        try:
            import yaml
        except ImportError:
            raise ImportError("ruamel.yaml не установлен. Установите: pip install ruamel.yaml")

        with open(path, "r", encoding="utf-8") as f:
            data = yaml.safe_load(f)

        if not isinstance(data, dict):
            logger.error("Конфигурация должна быть словарём")
            return Config()

        # Объединение с дефолтными значениями
        config_dict: dict[str, Any] = {
            "user_agent": data.get("user_agent", self._default_user_agent()),
            "stealth_plugins": data.get("stealth_plugins", self._default_stealth_plugins()),
            "tls_fingerprint": data.get("tls_fingerprint"),
            "padding_size": data.get("padding_size", 256 * 1024),
            "max_retries": data.get("max_retries", 3),
            "timeout_seconds": data.get("timeout_seconds", 30.0),
            "proxy_url": data.get("proxy_url"),
            "locale_consistency": data.get("locale_consistency", True),
            "audio_context_emulated": data.get("audio_context_emulated", True),
            "headers": data.get("headers", {}),
            "cookies": data.get("cookies", []),
        }

        return Config(**config_dict)

    def _parse_json(self, path: Path) -> Config:
        """
        Парсит JSON-конфигурацию.
        
        Простой парсинг без сохранения типов (для совместимости).
        """
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)

        if not isinstance(data, dict):
            logger.error("Конфигурация должна быть словарём")
            return Config()

        # Объединение с дефолтными значениями
        config_dict: dict[str, Any] = {
            "user_agent": data.get("user_agent", self._default_user_agent()),
            "stealth_plugins": data.get("stealth_plugins", self._default_stealth_plugins()),
            "tls_fingerprint": data.get("tls_fingerprint"),
            "padding_size": data.get("padding_size", 256 * 1024),
            "max_retries": data.get("max_retries", 3),
            "timeout_seconds": data.get("timeout_seconds", 30.0),
            "proxy_url": data.get("proxy_url"),
            "locale_consistency": data.get("locale_consistency", True),
            "audio_context_emulated": data.get("audio_context_emulated", True),
            "headers": data.get("headers", {}),
            "cookies": data.get("cookies", []),
        }

        return Config(**config_dict)

    def _default_user_agent(self) -> str:
        """Возвращает дефолтный User-Agent."""
        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"

    def _default_stealth_plugins(self) -> list[str]:
        """Возвращает дефолтный список stealth-плагинов."""
        return ["stealth", "undetectable"]


__all__ = ["Config", "ConfigLoader"]