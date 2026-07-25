import type { QueueLevel, Report } from "./types";

// Оценка очереди/пробки на АЗС по краудсорс-отчётам водителей.
//
// Методика (всё считается по полю `queue` в отчётах, без выдуманных данных):
//  1. Берём только отчёты за последние QUEUE_WINDOW_MS — очередь меняется
//     быстрее, чем наличие топлива, поэтому окно короче окна свежести статуса.
//  2. Каждому отчёту присваиваем вес: экспоненциальное затухание по возрасту
//     (период полураспада QUEUE_HALF_LIFE_MS) → свежие сообщения весомее
//     старых; подтверждения немного усиливают вес.
//  3. Вероятность очереди — это взвешенная доля отчётов, сообщивших о наличии
//     очереди (queue ≠ "none"), относительно суммарного веса. Это честная
//     «оценка мнения» недавних отчётов; надёжность отражает отдельный
//     показатель уверенности (см. ниже), а не искажение самой вероятности.
//  4. Оценка числа машин — взвешенное среднее диапазонов CARS_RANGE по всем
//     недавним отчётам (отчёты «без очереди» тянут оценку к нулю). Это грубая
//     оценка по сообщениям, а не измерение.
//  5. Уверенность — комбинация свежести самого свежего отчёта и объёма данных
//     (мало отчётов → ниже уверенность). Если свежих отчётов нет — честно
//     отдаём hasData = false («нет данных об очереди»).

// Окно учёта отчётов об очереди (короче окна свежести статуса в 3 ч).
const QUEUE_WINDOW_MS = 2 * 60 * 60 * 1000; // 2 часа

// Период полураспада веса отчёта об очереди.
const QUEUE_HALF_LIFE_MS = 25 * 60 * 1000; // 25 минут

// Эффективное число свежих отчётов, при котором уверенность считается полной.
const FULL_VOLUME = 2.5;

// Примерный диапазон числа машин для каждого уровня очереди (оценка по отзывам).
const CARS_RANGE: Record<QueueLevel, [number, number]> = {
  none: [0, 0],
  small: [1, 3],
  big: [4, 8],
  hours: [9, 18],
};

export type QueueChance = "none" | "low" | "medium" | "high";

// Подписи уровней вероятности для UI.
export const QUEUE_CHANCE_LABEL: Record<QueueChance, string> = {
  none: "Сейчас без очереди",
  low: "Очередь маловероятна",
  medium: "Возможна очередь",
  high: "Высокая вероятность очереди",
};

// Цвета уровней (амбер-акцент проекта; «без очереди» — зелёный, «высокая» — красный).
export const QUEUE_CHANCE_HEX: Record<QueueChance, string> = {
  none: "#00C853",
  low: "#FFB020",
  medium: "#FF9100",
  high: "#FF3D00",
};

export interface QueueEstimate {
  hasData: boolean; // есть ли свежие отчёты об очереди
  probability: number; // 0–100, вероятность реальной очереди прямо сейчас
  chance: QueueChance; // уровень для UI
  carsMin: number; // нижняя граница оценки числа машин
  carsMax: number; // верхняя граница оценки числа машин
  carsLabel: string; // готовая подпись «≈ 4–8 машин» / «без очереди»
  confidence: number; // 0–100, достаточно ли данных
  reportsUsed: number; // сколько отчётов учтено в окне
  lastQueueReportAt: string | null; // время последнего отчёта об очереди
}

// Вес отчёта: затухание по возрасту + лёгкий бонус за подтверждения.
function reportWeight(report: Report, now: number): number {
  const ageMs = now - new Date(report.created_at).getTime();
  const decay = ageMs <= 0 ? 1 : Math.pow(0.5, ageMs / QUEUE_HALF_LIFE_MS);
  return decay * (1 + 0.4 * Math.max(0, report.confirms ?? 0));
}

// Порог вероятности → качественный уровень.
function chanceFrom(probability: number): QueueChance {
  if (probability >= 60) return "high";
  if (probability >= 35) return "medium";
  if (probability >= 15) return "low";
  return "none";
}

function carsLabelFrom(min: number, max: number): string {
  if (max <= 0) return "Сейчас без очереди";
  if (min <= 0) return `≈ до ${max} ${plural(max)}`;
  if (min === max) return `≈ ${min} ${plural(max)}`;
  return `≈ ${min}–${max} ${plural(max)}`;
}

// Склонение слова «машина» для числа (по верхней границе диапазона).
function plural(n: number): string {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return "машина";
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return "машины";
  return "машин";
}

const EMPTY: QueueEstimate = {
  hasData: false,
  probability: 0,
  chance: "none",
  carsMin: 0,
  carsMax: 0,
  carsLabel: "Нет данных об очереди",
  confidence: 0,
  reportsUsed: 0,
  lastQueueReportAt: null,
};

// Главная функция: по списку отчётов станции считает оценку очереди.
export function estimateQueue(reports: Report[], now = Date.now()): QueueEstimate {
  const recent = reports.filter(
    (r) => now - new Date(r.created_at).getTime() <= QUEUE_WINDOW_MS
  );
  if (recent.length === 0) return { ...EMPTY };

  let totalWeight = 0;
  let queueWeight = 0;
  let carsLowWeighted = 0;
  let carsHighWeighted = 0;
  let effectiveCount = 0; // «эффективное» число свежих отчётов (по затуханию)
  let maxDecay = 0; // вклад самого свежего отчёта (0..1) — для свежести
  let lastQueueReportAt: string | null = null;

  for (const r of recent) {
    const w = reportWeight(r, now);
    totalWeight += w;
    const ageMs = now - new Date(r.created_at).getTime();
    const decay = ageMs <= 0 ? 1 : Math.pow(0.5, ageMs / QUEUE_HALF_LIFE_MS);
    effectiveCount += decay;
    if (decay > maxDecay) maxDecay = decay;
    const [lo, hi] = CARS_RANGE[r.queue];
    carsLowWeighted += lo * w;
    carsHighWeighted += hi * w;
    if (r.queue !== "none") {
      queueWeight += w;
      // Запоминаем самый свежий отчёт, сообщивший об очереди.
      if (
        !lastQueueReportAt ||
        new Date(r.created_at) > new Date(lastQueueReportAt)
      ) {
        lastQueueReportAt = r.created_at;
      }
    }
  }

  if (totalWeight <= 0) return { ...EMPTY };

  // Вероятность = взвешенная доля отчётов, сообщивших об очереди.
  const probability = Math.round((queueWeight / totalWeight) * 100);

  const carsMin = Math.round(carsLowWeighted / totalWeight);
  const carsMax = Math.max(carsMin, Math.round(carsHighWeighted / totalWeight));

  // Уверенность: свежесть самого свежего отчёта + объём данных.
  const volume = Math.min(1, effectiveCount / FULL_VOLUME);
  const confidence = Math.round((0.5 * maxDecay + 0.5 * volume) * 100);

  return {
    hasData: true,
    probability,
    chance: chanceFrom(probability),
    carsMin,
    carsMax,
    carsLabel: carsLabelFrom(carsMin, carsMax),
    confidence,
    reportsUsed: recent.length,
    lastQueueReportAt,
  };
}
