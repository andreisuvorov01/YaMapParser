import { loadEnv } from "./load-env.mjs";
import { bulkInsert, bulkUpsert, closeDb, dbQuery, findNearestStations, selectStationIdsByOsmId } from "./lib/db.mjs";
import { createRateGate } from "./lib/gdebenz-http.mjs";
import { fetchJson } from "./lib/benzinstatus-http.mjs";
import { toBenzinStatusTileReportRow } from "./lib/benzinstatus-parse.mjs";
import { withSupabaseRetry } from "./lib/supabase-retry.mjs";
import { MACRO_REGIONS, SPARSE_TILE_LAT, SPARSE_TILE_LON, loadRussiaPolygon, osmIdFor, tileTouchesRussia, tilesFor } from "./sync-gdebenz.mjs";

loadEnv();

// ---------------------------------------------------------------------------
// map.benzin-status.tech — тайловый обход через `/api/stations?bbox=south,west,north,east`.
//
// В отличие от gdebenz.org/benzinest.ru — сайт НЕ защищён (ни DDoS-Guard, ни
// Cloudflare challenge, ни TLS-фингерпринтингом не пахнет: обычный
// browser-like User-Agent через прямой fetch() отрабатывает без прокси).
// Поэтому этот скрипт НЕ ходит через proxy_server.py/XRay-пул — работает и
// без него, независимо от остальных источников.
//
// Ограничение сервера: ответ молча обрезается на 2000 станций БЕЗ ошибки
// (не как gdebenz с явным bbox_too_large) — если в тайле пришло ровно 2000+,
// считаем это возможным обрезанием и дробим тайл дальше (см. fetchTile).
// Слишком широкий bbox (проверено ~161° по долготе) отдаёт {"error":"bad_bbox"}
// — тайлы гарантированно мельче этого порога.
//
// ID станций свои внутренние (плотные, не OSM) — НИКОГДА не совпадают с
// настоящим OSM id, который используют gdebenz/benzinest (те напрямую
// пробрасывают OSM node id). Поэтому попытка сопоставить станции по
// синтетическому osm_id (как раньше делал этот скрипт) НЕ работает — она
// просто заводит для каждой физически той же заправки отдельную дублирующую
// строку рядом с уже существующей от gdebenz/benzinest. Вместо этого станции
// сопоставляются по географической близости (см. findNearestStations в
// lib/db.mjs, PostGIS gist-индекс на stations.geo) — если рядом (в пределах
// MATCH_RADIUS_M) уже есть станция, к НЕЙ просто привязывается
// benzinstatus_id, новая строка не создаётся; синтетический osm_id
// используется только для станций, для которых рядом никого не нашлось
// (действительно новые для нас станции).
//
// Этот скрипт отвечает только за ОБНАРУЖЕНИЕ станций (привязка
// benzinstatus_id к существующим/новым в stations) и грубый статус/одну цену
// из тайлового списка — как sync-gdebenz.mjs/sync-benzinest.mjs для своих
// источников. Полный набор цен по всем видам топлива (детальная карточка
// `/api/stations/<id>`) забирается НЕ отдельным скриптом, а прямо внутри
// sync-gdebenz-comments.mjs (третий пункт per-station прохода, вместе с
// gdebenz/benzinest) — см. блок BENZINSTATUS_* там.
// ---------------------------------------------------------------------------

const API_URL = "https://map.benzin-status.tech/api/stations";
// См. тот же комментарий в sync-gdebenz-comments.mjs — сервер начал отдавать
// HTTP 429 при более быстром темпе (150мс там), здесь тоже поднят дефолт.
const MIN_INTERVAL_MS = Math.max(0, Number(process.env.BENZINSTATUS_MIN_INTERVAL_MS ?? 500));
const CONCURRENCY = Math.max(1, Number(process.env.BENZINSTATUS_CONCURRENCY) || 6);
// Сайт мультистрановый (встречены станции вне РФ) — тайлы те же, что у
// gdebenz/benzinest (макро-регионы РФ), но помельче: Москва уже упирается в
// 2000-кап на тайле ~2°×2°, так что дефолт заметно меньше, чем TILE_LAT/LON
// у gdebenz (1.2°) — держим маржу до кап-лимита без частого рекурсивного дробления.
const TILE_LAT = Number(process.env.BENZINSTATUS_TILE_LAT) || 0.6;
const TILE_LON = Number(process.env.BENZINSTATUS_TILE_LON) || 0.6;
const BATCH = Math.max(1, Number(process.env.BENZINSTATUS_BATCH) || 500);
const SELECT_CHUNK = Math.max(1, Number(process.env.BENZINSTATUS_SELECT_CHUNK) || 1000);
// Признак возможного молчаливого обрезания ответа сервером (см. коммент выше).
const TRUNCATION_THRESHOLD = 2000;
// Порог "это та же заправка" при гео-сопоставлении — разные источники
// цифруют координаты чуть по-разному (свой пин на карте, свой геокодер), 60м
// с запасом покрывает типичный разброс, но не настолько велик, чтобы путать
// две соседние станции на одной АЗК/у дороги.
const MATCH_RADIUS_M = Math.max(1, Number(process.env.BENZINSTATUS_MATCH_RADIUS_M) || 60);
// Размер батча для findNearestStations — каждая точка внутри одного запроса
// делает свой LATERAL-подзапрос по gist-индексу, батч только группирует
// round-trips, не саму работу индекса.
const MATCH_CHUNK = Math.max(1, Number(process.env.BENZINSTATUS_MATCH_CHUNK) || 200);

const rateGate = createRateGate(MIN_INTERVAL_MS);

let tilesFailedCount = 0;

const roundCoord = (n) => Number(n.toFixed(6));

/** Запрос одного тайла. Возвращает массив станций. */
export async function fetchBenzinStatusTile(tile, depth = 0) {
  const { south, west, north, east } = tile;
  const url = `${API_URL}?bbox=${south},${west},${north},${east}`;
  try {
    await rateGate();
    const data = await fetchJson(url);
    const stations = Array.isArray(data?.stations) ? data.stations : [];

    if (stations.length >= TRUNCATION_THRESHOLD && depth <= 6) {
      const midLat = roundCoord((south + north) / 2);
      const midLon = roundCoord((west + east) / 2);
      const sub = [
        { south, west, north: midLat, east: midLon },
        { south, west: midLon, north: midLat, east },
        { south: midLat, west, north, east: midLon },
        { south: midLat, west: midLon, north, east },
      ];
      const out = [];
      for (const s of sub) out.push(...(await fetchBenzinStatusTile(s, depth + 1)));
      return out;
    }
    if (stations.length >= TRUNCATION_THRESHOLD) {
      console.warn(`  benzinstatus: тайл похож на обрезанный на макс. глубине дробления, возможна потеря станций: ${url}`);
    }
    return stations;
  } catch (e) {
    console.warn(`  benzinstatus: не удалось получить тайл (${e.message}): ${url}`);
    tilesFailedCount++;
    return [];
  }
}

export async function runSync({ log = console.log } = {}) {
  tilesFailedCount = 0;

  try {
    const russia = await loadRussiaPolygon();
    const tiles = [];
    for (const r of MACRO_REGIONS) {
      tiles.push(...(r.sparse ? tilesFor(r, SPARSE_TILE_LAT, SPARSE_TILE_LON) : tilesFor(r, TILE_LAT, TILE_LON)));
    }
    const beforeFilter = tiles.length;
    const filteredTiles = tiles.filter((t) => tileTouchesRussia(t, russia));
    log(`benzinstatus: обход РФ, тайлов ${filteredTiles.length} (отброшено по границе: ${beforeFilter - filteredTiles.length}), конкурентность ${CONCURRENCY}`);

    const byId = new Map();
    let tilesFetched = 0;
    let rawRows = 0;
    let nextTileIndex = 0;

    async function processTile(tile) {
      const rows = await fetchBenzinStatusTile(tile);
      tilesFetched++;
      rawRows += rows.length;
      for (const r of rows) {
        if (!r || r.lat == null || r.lng == null || r.id == null) continue;
        const key = String(r.id);
        if (!byId.has(key)) byId.set(key, r);
      }
      if (tilesFetched % 20 === 0 || tilesFetched === filteredTiles.length) {
        log(`benzinstatus тайлы: ${tilesFetched}/${filteredTiles.length}, станций собрано: ${byId.size}`);
      }
    }

    const workerCount = Math.min(CONCURRENCY, filteredTiles.length) || 1;
    const workers = Array.from({ length: workerCount }, async () => {
      while (true) {
        const index = nextTileIndex++;
        if (index >= filteredTiles.length) return;
        await processTile(filteredTiles[index]);
      }
    });
    await Promise.all(workers);
    log(`benzinstatus: получено строк ${rawRows}, уникальных станций ${byId.size}, тайлов не получено ${tilesFailedCount}/${filteredTiles.length}`);

    const candidates = [...byId.values()];

    // Гео-сопоставление с уже существующими станциями (см. коммент в шапке
    // файла) — ДО того как решать, заводить ли новую строку.
    const matchedUuidByIndex = new Map();
    for (let i = 0; i < candidates.length; i += MATCH_CHUNK) {
      const chunk = candidates.slice(i, i + MATCH_CHUNK);
      const points = chunk.map((r) => ({ lat: r.lat, lng: r.lng }));
      const { data, error } = await withSupabaseRetry(() => findNearestStations(points), { label: "benzinstatus гео-сопоставление" });
      if (error) throw new Error(`benzinstatus гео-сопоставление: ${error.message}`);
      for (const [localIdx, match] of data) {
        if (match.distM <= MATCH_RADIUS_M) matchedUuidByIndex.set(i + localIdx, match.id);
      }
      const done = Math.min(i + chunk.length, candidates.length);
      if (done % (MATCH_CHUNK * 5) === 0 || done === candidates.length) {
        log(`benzinstatus: гео-сопоставление ${done}/${candidates.length}, найдено рядом ${matchedUuidByIndex.size}`);
      }
    }

    // Станции, для которых нашлась соседка в пределах MATCH_RADIUS_M —
    // просто привязываем benzinstatus_id к НЕЙ (её координаты/название/бренд
    // не трогаем, они принадлежат источнику, который её завёл первым).
    // Остальные — действительно новые для нас станции, заводим как раньше,
    // с синтетическим osm_id.
    const matchedEntries = [];
    const newStationRows = [];
    const newOsmIds = [];
    const newMeta = [];
    candidates.forEach((r, idx) => {
      const existingUuid = matchedUuidByIndex.get(idx);
      if (existingUuid) {
        matchedEntries.push({ stationId: existingUuid, station: r });
        return;
      }
      const osmId = osmIdFor({ lat: r.lat, lon: r.lng, brand: r.brand, name: r.name, addr: r.address });
      const name = String(r?.name || "").trim() || "АЗС";
      newOsmIds.push(osmId);
      newStationRows.push({
        name,
        brand: r?.brand ? String(r.brand).trim() || null : null,
        lat: r.lat,
        lng: r.lng,
        address: String(r?.address || "").trim() || null,
        source: "osm",
        osm_id: osmId,
        benzinstatus_id: String(r.id),
      });
      newMeta.push({ osm_id: osmId, station: r });
    });
    log(`benzinstatus: привязано к существующим станциям ${matchedEntries.length}, действительно новых ${newStationRows.length}`);

    let linked = 0;
    for (let i = 0; i < matchedEntries.length; i += BATCH) {
      const chunk = matchedEntries.slice(i, i + BATCH).map((e) => ({ id: e.stationId, benzinstatus_id: String(e.station.id) }));
      const { error } = await withSupabaseRetry(
        () => dbQuery(`select bulk_update_benzinstatus_id($1::jsonb)`, [JSON.stringify(chunk)]),
        // Не бесконечно: если функция не создана (миграция не применена),
        // это постоянная ошибка, а не сетевой блип.
        { label: "stations link benzinstatus_id (rpc)", retries: 5 }
      );
      if (error) {
        const hint = /function|schema cache/i.test(error.message || "") ? " Похоже, не применена миграция — выполните: node migrate.mjs" : "";
        throw new Error(`stations link benzinstatus_id: ${error.message}.${hint}`);
      }
      linked += chunk.length;
      log(`benzinstatus привязка к существующим: ${linked}/${matchedEntries.length}`);
    }

    let upserted = 0;
    for (let i = 0; i < newStationRows.length; i += BATCH) {
      const chunk = newStationRows.slice(i, i + BATCH);
      const { error } = await withSupabaseRetry(() => bulkUpsert("stations", chunk, ["osm_id"]), { label: "stations upsert" });
      if (error) throw new Error(`stations upsert: ${error.message}`);
      upserted += chunk.length;
      log(`benzinstatus станции upsert (новые): ${upserted}/${newStationRows.length}`);
    }

    const osmToUuid = new Map();
    for (let i = 0; i < newOsmIds.length; i += SELECT_CHUNK) {
      const slice = newOsmIds.slice(i, i + SELECT_CHUNK);
      const { data, error } = await withSupabaseRetry(() => selectStationIdsByOsmId(slice), { label: "stations select" });
      if (error) throw new Error(`stations select: ${error.message}`);
      for (const row of data) osmToUuid.set(String(row.osm_id), row.id);
    }
    log(`benzinstatus: сопоставлено новых станций ${osmToUuid.size}`);

    // Чистим только устаревшие (>2 суток) отчёты — та же логика, что и у
    // остальных источников: безусловный delete снёс бы отчёты по станциям,
    // не попавшим в текущий обход.
    {
      const cutoffIso = new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toISOString();
      const { error } = await withSupabaseRetry(
        () => dbQuery(`delete from reports where client_id = $1 and created_at < $2`, ["benzinstatus", cutoffIso]),
        { label: "reports cleanup" }
      );
      if (error) throw new Error(`reports cleanup: ${error.message}`);
    }

    const reportRows = [];
    for (const { stationId, station } of matchedEntries) {
      const row = toBenzinStatusTileReportRow(stationId, station);
      if (row) reportRows.push(row);
    }
    for (const m of newMeta) {
      const uuid = osmToUuid.get(String(m.osm_id));
      if (!uuid) continue;
      const row = toBenzinStatusTileReportRow(uuid, m.station);
      if (row) reportRows.push(row);
    }

    let reportsInserted = 0;
    for (let i = 0; i < reportRows.length; i += BATCH) {
      const chunk = reportRows.slice(i, i + BATCH);
      const { error } = await withSupabaseRetry(() => bulkInsert("reports", chunk), { label: "reports insert" });
      if (error) throw new Error(`reports insert: ${error.message}`);
      reportsInserted += chunk.length;
      log(`benzinstatus статусы insert: ${reportsInserted}/${reportRows.length}`);
    }

    const summary = {
      tilesFetched,
      tilesFailed: tilesFailedCount,
      rawRows,
      uniqueStations: byId.size,
      matchedExisting: matchedEntries.length,
      stationsUpserted: upserted,
      mapped: osmToUuid.size + matchedEntries.length,
      reportsInserted,
    };
    log(
      `benzinstatus готово. Тайлов: ${tilesFetched}, привязано к существующим: ${matchedEntries.length}, новых станций: ${upserted}, статусов: ${reportsInserted}.`
    );
    return summary;
  } finally {
    // Нет отдельного HTTP-транспорта, закрывать нечего — только БД (см. runAsScript ниже).
  }
}

const runAsScript = process.argv[1]?.endsWith("sync-benzinstatus.mjs");
if (runAsScript) {
  runSync()
    .catch((e) => {
      console.error("Ошибка синка benzinstatus:", e.message);
      process.exitCode = 1;
    })
    .finally(() => closeDb());
}
