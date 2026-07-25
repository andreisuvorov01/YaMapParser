// Разбор данных map.benzin-status.tech. Как и benzinest, статус уже
// структурирован (без текстового разбора), но у ЭТОГО сайта нет прямого OSM
// id — свои внутренние плотные (1, 2, 3, 5, 7, 11, ...) числовые id, никак не
// связанные с OSM node id, так что для map.benzin-status.tech ВСЕГДА
// используется синтетический osm_id (см. osmIdFor из sync-gdebenz.mjs).
//
// Использует тот же словарь топлив/статусов, что и остальные источники
// ("АИ-92".."АИ-100", "ДТ", "Газ", статус yes/low/no), чтобы строки в
// `reports` были совместимы независимо от client_id.

const FUEL_TYPE_MAP = {
  ai92: "АИ-92",
  ai95: "АИ-95",
  ai98: "АИ-98",
  ai100: "АИ-100",
  dt: "ДТ",
  gas: "Газ",
};

// available/limited/none/unknown — полный набор значений, встреченных в
// реальных ответах API (см. разведку в истории чата). "unknown" — станция
// без достаточных данных для вывода статуса, пропускаем её (как gdebenz
// пропускает нераспознанные статусы через mapGdebenzStatus).
const STATUS_MAP = {
  available: "yes",
  limited: "low",
  none: "no",
};

export function mapBenzinStatusStatus(raw) {
  return STATUS_MAP[raw] ?? null;
}

export function mapBenzinStatusFuelTypes(fuelTypes) {
  if (!Array.isArray(fuelTypes)) return [];
  const set = new Set();
  for (const f of fuelTypes) {
    const label = FUEL_TYPE_MAP[f];
    if (label) set.add(label);
  }
  return [...set];
}

/** `station.prices[]` (детальный эндпоинт) или единственная цена из
 * тайлового списка (`price`/`priceFuel`/...) → jsonb-ОБЪЕКТ (не массив!) для
 * `reports.prices` — колонка ограничена CHECK'ом `reports_prices_is_object`
 * (`jsonb_typeof(prices) = 'object'`). Ключ — вид топлива (тот же словарь,
 * что и у fuel_types), значение — ПРОСТО ЧИСЛО (цена), в том же плоском виде
 * `{ "АИ-95": 68.68 }`, что уже пишут пользовательские отчёты с фронтенда
 * (client_id вида "cid:...") — не вложенный объект с метаданными: тот же
 * jsonb-столбец читает существующий фронтенд, ожидающий число, а не объект.
 * source/confidence/priceAt с benzinstatus сознательно отбрасываются здесь.
 * Пустой объект, если цен нет вообще — в этом случае в БД пишем null (см.
 * вызывающий код), а не `{}`, чтобы отличать "не спрашивали" от "спросили,
 * цен нет" было бы избыточно: сам факт report-строки уже значит "спросили в
 * этот момент".
 */
export function mapBenzinStatusPrices(prices) {
  const out = {};
  if (!Array.isArray(prices)) return out;
  for (const p of prices) {
    const fuel = FUEL_TYPE_MAP[p?.fuel];
    const price = Number(p?.price);
    if (!fuel || !Number.isFinite(price)) continue;
    out[fuel] = price;
  }
  return out;
}

/** Одна станция из тайлового `/api/stations?bbox=...` → строка `reports`
 * (или null, если статус "unknown" — сказать нечего). Цена (если есть) —
 * только одна, для одного вида топлива, `priceFuel`. */
export function toBenzinStatusTileReportRow(stationId, station) {
  const status = mapBenzinStatusStatus(station?.status);
  if (!status) return null;

  const createdAtRaw = station?.lastReportAt;
  const createdAt = createdAtRaw ? new Date(Number(createdAtRaw)) : null;
  const createdIso = createdAt && Number.isFinite(createdAt.getTime()) ? createdAt.toISOString() : new Date().toISOString();

  const priceFuel = FUEL_TYPE_MAP[station?.priceFuel];
  const price = Number(station?.price);
  // Плоский { fuel: number }, как и mapBenzinStatusPrices — см. коммент там.
  const prices = priceFuel && Number.isFinite(price) ? { [priceFuel]: price } : {};

  return {
    station_id: stationId,
    status,
    fuel_types: mapBenzinStatusFuelTypes(station?.fuelTypes),
    queue: "none",
    confirms: 0,
    client_id: "benzinstatus",
    created_at: createdIso,
    prices: Object.keys(prices).length > 0 ? prices : null,
  };
}

/** Детальная карточка станции (`/api/stations/<id>`) → строка `reports` (или
 * null) — используется в sync-gdebenz-comments.mjs (третий пункт, независимо
 * от gdebenz/benzinest), полный набор цен по всем видам топлива сразу (в
 * отличие от тайлового списка). */
export function toBenzinStatusDetailReportRow(stationId, detail) {
  const station = detail?.station;
  const status = mapBenzinStatusStatus(station?.status);
  if (!status) return null;

  const createdAtRaw = station?.statusAt;
  const createdAt = createdAtRaw ? new Date(Number(createdAtRaw)) : null;
  const createdIso = createdAt && Number.isFinite(createdAt.getTime()) ? createdAt.toISOString() : new Date().toISOString();

  const prices = mapBenzinStatusPrices(station?.prices);

  return {
    station_id: stationId,
    status,
    fuel_types: [],
    queue: "none",
    confirms: Math.max(0, Number(station?.confirms) || 0),
    client_id: "benzinstatus",
    created_at: createdIso,
    prices: Object.keys(prices).length > 0 ? prices : null,
  };
}
