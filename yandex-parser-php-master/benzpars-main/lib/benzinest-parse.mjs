// Разбор данных benzinest.ru. В отличие от gdebenz (см. gdebenz-parse.mjs),
// у benzinest статус по каждому виду топлива уже структурирован
// (fuels[].status), а не зашит в текст — маппинг проще и без regex.
//
// Использует тот же словарь топлив/очереди, что и gdebenz-parse.mjs
// ("АИ-92".."АИ-100", "ДТ", queue: none/small/big/hours), чтобы строки в
// таблице `reports` были совместимы независимо от client_id.

const FUEL_TYPE_MAP = {
  "AI-92": "АИ-92",
  "AI-95": "АИ-95",
  // benzinest отдельно отличает АИ-95 премиум ("95+") от обычного — в нашей
  // схеме отдельного бакета для этого нет, сводим к АИ-95.
  "AI-95+": "АИ-95",
  "AI-98": "АИ-98",
  "AI-100": "АИ-100",
  Diesel: "ДТ",
};

const QUEUE_MAP = {
  NONE: "none",
  MEDIUM: "small",
  HIGH: "big",
};

export function mapBenzinestQueue(raw) {
  return QUEUE_MAP[raw] ?? "none";
}

/** Числовой OSM id станции benzinest, либо null (если когда-нибудь появятся
 * пользовательские станции без OSM id — сейчас в выдаче таких нет). */
export function osmIdForBenzinest(station) {
  const s = String(station?.id ?? "").trim();
  return /^\d+$/.test(s) ? Number(s) : null;
}

/** Сводит fuels[] к { available, anyAvailable, sawKnown }. */
export function mapBenzinestFuels(fuels) {
  const available = new Set();
  let anyAvailable = false;
  let sawKnown = false;
  if (Array.isArray(fuels)) {
    for (const f of fuels) {
      const label = FUEL_TYPE_MAP[f?.type];
      if (!label) continue;
      if (f.status === "AVAILABLE") {
        available.add(label);
        anyAvailable = true;
        sawKnown = true;
      } else if (f.status === "OUT_OF_STOCK") {
        sawKnown = true;
      }
      // "UNKNOWN" — не считаем ни известным, ни доступным
    }
  }
  return { available: [...available], anyAvailable, sawKnown };
}

/**
 * Станция считается закрытой ("no"), если benzinest явно пометил её
 * NOT_WORKING, ИЛИ если она открыта/статус неизвестен, но ни одно топливо не
 * в наличии при этом хотя бы одно топливо имеет известный статус. null —
 * реально нечего сообщить (ни статуса станции, ни статуса топлива).
 */
function deriveStatus(topStatus, fuelsResult) {
  if (topStatus === "NOT_WORKING") return "no";
  if (fuelsResult.anyAvailable) return "yes";
  if (fuelsResult.sawKnown) return "no";
  return null;
}

/** Одна станция из /api/stations (текущий срез) -> строка `reports` (или null). */
export function toBenzinestReportRow(stationId, station) {
  const fuelsResult = mapBenzinestFuels(station?.fuels);
  const status = deriveStatus(station?.status, fuelsResult);
  if (!status) return null;

  const createdAtRaw = station?.lastReportAt || station?.lastUpdated;
  const createdAt = createdAtRaw ? new Date(createdAtRaw) : null;
  const createdIso = createdAt && Number.isFinite(createdAt.getTime()) ? createdAt.toISOString() : new Date().toISOString();

  return {
    station_id: stationId,
    status,
    fuel_types: fuelsResult.available,
    queue: mapBenzinestQueue(station?.queue),
    confirms: Number(station?.reports24h) || 0,
    client_id: "benzinest",
    created_at: createdIso,
  };
}

/**
 * Полная лента отметок станции из `/api/stations/<id>/reports?since=..&limit=..`
 * — аналог gdebenz-ленты `/api/comments/<id>/recent`, но с явными голосами
 * (votes.up/down) вместо текстовых аннотаций. Каждый элемент `items[]` — это
 * отдельный исторический отчёт (не текущий срез), поэтому, в отличие от
 * toBenzinestReportRow, тут может получиться МНОГО строк на одну станцию.
 */
export function toBenzinestReportRows(stationId, items) {
  if (!Array.isArray(items)) return [];
  const rows = [];
  for (const item of items) {
    const fuelsResult = mapBenzinestFuels(item?.fuels);
    const status = deriveStatus(item?.status, fuelsResult);
    if (!status) continue;

    const createdMs = Number(item?.created_at) * 1000;
    const createdIso = Number.isFinite(createdMs) && createdMs > 0 ? new Date(createdMs).toISOString() : new Date().toISOString();

    rows.push({
      station_id: stationId,
      status,
      fuel_types: fuelsResult.available,
      queue: mapBenzinestQueue(item?.queue),
      confirms: Math.max(0, Number(item?.votes?.up) || 0),
      client_id: "benzinest",
      created_at: createdIso,
    });
  }
  return rows;
}
