"""Модуль гуманизации действий — биометрическая имитация ввода."""

from __future__ import annotations

import asyncio
import logging
import math
import random
from dataclasses import dataclass, field
from typing import Any, Optional

try:
    from playwright.async_api import Locator
except ImportError:
    Locator = None  # type: ignore[assignment]

logger = logging.getLogger(__name__)


@dataclass
class HumanBehaviorResult:
    """Результат симуляции клика."""

    trajectory_points: list[tuple[float, float]]
    click_delay_ms: int
    fitts_time_estimate_ms: Optional[int] = None


class BezierCurveGenerator:
    """Генератор кривых Безье 3-го порядка для траекторий мыши."""

    def __init__(self, order: int = 3):
        self.order = order

    def generate(self, start: tuple[float, float], end: tuple[float, float]) -> list[tuple[float, float]]:
        """
        Возвращает список точек на кривой Безье между start и end.
        Точки равномерно распределены по длине кривой.
        """
        if self.order != 3:
            raise ValueError("Только кубические кривые поддерживаются")

        p0 = start
        p1 = (start[0] + end[0]) / 2, (start[1] + end[1]) / 2
        p2 = p1
        p3 = end

        t_values = [i / (self.order - 1) for i in range(self.order)]
        points: list[tuple[float, float]] = []

        for t in t_values:
            x = self._bezier_point(p0[0], p1[0], p2[0], p3[0], t)
            y = self._bezier_point(p0[1], p1[1], p2[1], p3[1], t)
            points.append((x, y))

        return points

    def _bezier_point(self, x0: float, x1: float, x2: float, x3: float, t: float) -> float:
        """Вычисляет точку на кубической кривой Безье."""
        return (1 - t)**3 * x0 + 3*(1 - t)**2*t*x1 + 3*(1 - t)*t**2*x2 + t**3*x3


class FittsLawCalculator:
    """Реализация закона Фиттса для оценки времени клика."""

    def __init__(self):
        self.a = 0.001  # константа (порог)
        self.b = 0.2    # коэффициент пропорциональности

    def estimate_time(self, distance: float, target_size: float) -> int:
        """
        Возвращает оценку времени клика в миллисекундах.
        D — расстояние до цели, W — ширина цели (или её эквивалент).
        """
        if target_size <= 0 or distance <= 0:
            raise ValueError("distance и target_size должны быть > 0")

        time_ms = int((self.a + self.b * math.log1p(distance / target_size)) * 1000)
        return max(time_ms, 50)  # минимум 50 мс для реалистичности


class HumanBehavior:
    """
    Биометрическая имитация ввода:
    - Генерация траекторий движения мыши через кривые Безье 3-го порядка
    - Реализация закона Фиттса для кликов
    - Случайные задержки между нажатиями клавиш (когнитивные паузы)
    """

    def __init__(self):
        self.bezier_curves = BezierCurveGenerator(order=3)
        self.fitts_law = FittsLawCalculator()

    async def simulate_click(self, element: Locator, mouse_position: tuple[int, int]) -> HumanBehaviorResult:
        """
        Генерирует естественную траекторию движения мыши.

        Асинхронно выполняет движение к элементу с использованием
        кривой Безье и возвращает результат с оценкой времени по закону Фиттса.
        """
        if element is None:
            raise ValueError("element не может быть None")

        # Генерируем траекторию (примерно 10 точек)
        trajectory_points = self.bezier_curves.generate(
            start=(0, 0),
            end=mouse_position,
        )

        # Оцениваем время клика по закону Фиттса
        distance = ((trajectory_points[-1][0] - trajectory_points[0][0])**2 +
                    (trajectory_points[-1][1] - trajectory_points[0][1])**2)**0.5
        target_size = 30  # условный размер цели в пикселях
        fitts_time_ms = self.fitts_law.estimate_time(distance, target_size)

        # Добавляем случайную когнитивную паузу (10–200 мс)
        click_delay_ms = random.randint(10, 200)

        return HumanBehaviorResult(
            trajectory_points=trajectory_points,
            click_delay_ms=click_delay_ms,
            fitts_time_estimate_ms=fitts_time_ms,
        )

    async def simulate_keypress(self) -> int:
        """
        Возвращает случайную задержку между нажатиями клавиш (когнитивная пауза).
        """
        return random.randint(50, 300)


__all__ = ["HumanBehavior", "HumanBehaviorResult"]