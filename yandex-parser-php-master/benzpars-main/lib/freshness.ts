import type {
  FuelStatus,
  QueueLevel,
  ReportForStatus,
  Station,
  StationStatus,
} from "./types";

// Окно свежести: отчёты старше этого времени считаются устаревшими.
export const FRESH_WINDOW_MS = 3 * 60 * 60 * 1000; // 3 часа

// Период полураспада веса отчёта (чем свежее — тем больше вес).
const HALF_LIFE_MS = 60 * 60 * 1000; // 1 час

// Вес отчёта по давности + числу подтверждений.
function reportWeight(report: ReportForStatus, now: number): number {
  const ageMs = now - new Date(report.created_at).getTime();
  if (ageMs < 0) return 1 + report.confirms;
  const decay = Math.pow(0.5, ageMs / HALF_LIFE_MS);
  // Подтверждения немного усиливают вес отчёта.
  return decay * (1 + 0.5 * Math.max(0, report.confirms));
}

// Уровень доверия к данным заправки по свежести/количеству отчётов.
export type FreshnessLevel = "fresh" | "recent" | "old" | "none";

export const FRESHNESS_LABEL: Record<FreshnessLevel, string> = {
  fresh: "свежо",
  recent: "недавно",
  old: "давно",
  none: "нет данных",
};

// Цвета совпадают с палитрой статусов (зелёный→серый), безопасны на тёмном фоне.
export const FRESHNESS_HEX: Record<FreshnessLevel, string> = {
  fresh: "#00C853",
  recent: "#FFB020",
  old: "#FF9100",
  none: "#90A4AE",
};

export interface Confidence {
  level: FreshnessLevel;
  score: number; // 0–100
}

// Считает доверие: свежесть последнего отчёта (в окне 3ч) + объём (отчёты+подтверждения).
// Честно: старше окна свежести → «нет данных».
export function confidence(input: {
  lastReportAt: string | null;
  reportsCount: number;
  confirms?: number;
  now?: number;
}): Confidence {
  const now = input.now ?? Date.now();
  if (!input.lastReportAt || input.reportsCount <= 0) {
    return { level: "none", score: 0 };
  }
  const age = now - new Date(input.lastReportAt).getTime();
  if (age > FRESH_WINDOW_MS) return { level: "none", score: 0 };

  // Свежесть 0..1 (чем меньше возраст в окне — тем выше).
  const recency = Math.max(0, 1 - age / FRESH_WINDOW_MS);
  // Объём 0..1: 3+ свежих отчёта (с учётом подтверждений) дают максимум.
  const volume = Math.min(
    1,
    (input.reportsCount + (input.confirms ?? 0) * 0.5) / 3
  );
  const score = Math.round((0.65 * recency + 0.35 * volume) * 100);

  let level: FreshnessLevel;
  if (age <= 45 * 60 * 1000 && score >= 55) level = "fresh";
  else if (age <= 90 * 60 * 1000) level = "recent";
  else level = "old";
  return { level, score };
}

// Человекочитаемое "обновлено N назад".
export function timeAgo(iso: string | null): string {
  if (!iso) return "нет данных";
  const diffMs = Date.now() - new Date(iso).getTime();
  if (diffMs < 0) return "только что";
  const min = Math.floor(diffMs / 60000);
  if (min < 1) return "только что";
  if (min < 60) return `${min} мин назад`;
  const hours = Math.floor(min / 60);
  if (hours < 24) return `${hours} ч назад`;
  const days = Math.floor(hours / 24);
  return `${days} дн назад`;
}

// Агрегирует список отчётов одной заправки в её текущий статус.
// Использует взвешенное по свежести голосование.
export function aggregateStation(
  station: Station,
  reports: ReportForStatus[],
  now = Date.now()
): StationStatus {
  const fresh = reports.filter(
    (r) => now - new Date(r.created_at).getTime() <= FRESH_WINDOW_MS
  );

  if (fresh.length === 0) {
    // Нет свежих отчётов — статус неизвестен; помечаем устаревшим, если
    // когда-либо были отчёты вообще.
    const last = reports[0]?.created_at ?? null;
    return {
      ...station,
      status: "unknown",
      queue: null,
      limit_liters: null,
      fuel_types: [],
      last_report_at: last,
      reports_count: 0,
      stale: Boolean(last),
      conflicting: false,
    };
  }

  // Взвешенное голосование по статусу.
  const statusScore: Record<FuelStatus, number> = {
    yes: 0,
    low: 0,
    no: 0,
    unknown: 0,
  };
  const queueScore: Record<QueueLevel, number> = {
    none: 0,
    small: 0,
    big: 0,
    hours: 0,
  };
  const fuelScore = new Map<string, number>();
  let limitWeighted = 0;
  let limitWeight = 0;

  for (const r of fresh) {
    const w = reportWeight(r, now);
    statusScore[r.status] += w;
    queueScore[r.queue] += w;
    for (const f of r.fuel_types) {
      fuelScore.set(f, (fuelScore.get(f) ?? 0) + w);
    }
    if (typeof r.limit_liters === "number") {
      limitWeighted += r.limit_liters * w;
      limitWeight += w;
    }
  }

  const status = pickMax(statusScore) as FuelStatus;
  const queue = pickMax(queueScore) as QueueLevel;

  // Конфликт: второй по весу статус набрал почти столько же.
  const sortedStatus = Object.entries(statusScore).sort((a, b) => b[1] - a[1]);
  const conflicting =
    sortedStatus.length > 1 &&
    sortedStatus[1][1] > 0 &&
    sortedStatus[1][1] >= sortedStatus[0][1] * 0.75;

  // Виды топлива, которые упоминались чаще всего.
  const fuel_types = Array.from(fuelScore.entries())
    .sort((a, b) => b[1] - a[1])
    .map(([f]) => f) as StationStatus["fuel_types"];

  const limit_liters =
    limitWeight > 0 ? Math.round(limitWeighted / limitWeight) : null;

  const last_report_at = fresh.reduce<string | null>((acc, r) => {
    if (!acc || new Date(r.created_at) > new Date(acc)) return r.created_at;
    return acc;
  }, null);

  return {
    ...station,
    status,
    queue,
    limit_liters,
    fuel_types,
    last_report_at,
    reports_count: fresh.length,
    stale: false,
    conflicting,
  };
}

function pickMax<T extends string>(scores: Record<T, number>): T {
  let bestKey = Object.keys(scores)[0] as T;
  let best = -Infinity;
  for (const [k, v] of Object.entries(scores) as [T, number][]) {
    if (v > best) {
      best = v;
      bestKey = k;
    }
  }
  return bestKey;
}
