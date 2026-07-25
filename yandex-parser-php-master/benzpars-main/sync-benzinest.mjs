import { loadEnv } from "./load-env.mjs";
import { bulkInsert, bulkUpsert, closeDb, dbQuery, selectStationIdsByOsmId } from "./lib/db.mjs";
import {
  MAX_RETRIES,
  REQUEST_TIMEOUT_MS,
  RETRY_DELAY_MS,
  closeTransports,
  createRateGate,
  describeFetchError,
  nextProxy,
  requestJson,
  responseDelayForTransport,
  sleep,
  waitAfterResponse,
} from "./lib/gdebenz-http.mjs";
import { osmIdForBenzinest, toBenzinestReportRow } from "./lib/benzinest-parse.mjs";
import { withSupabaseRetry } from "./lib/supabase-retry.mjs";
import {
  MACRO_REGIONS,
  SPARSE_TILE_LAT,
  SPARSE_TILE_LON,
  loadRussiaPolygon,
  tileTouchesRussia,
  tilesFor,
} from "./sync-gdebenz.mjs";

loadEnv();

// ---------------------------------------------------------------------------
// benzinest.ru — тайловый обход через `/api/stations?bbox=south,west,north,east`.
//
// ВАЖНО: параметры `lat1/lon1/lat2/lon2` (как у gdebenz) сервером тихо
// игнорируются — при них всегда возвращается один и тот же фиксированный
// набор (казалось, что это "только Москва", отсюда прежняя ошибочная версия
// этого скрипта в виде одного запроса без обхода). Реальный гео-фильтр — это
// ОДИН параметр `bbox` строкой `"south,west,north,east"`, с проверкой
// sw < ne и лимитом площади < 4 кв.градуса (иначе `{"error":"Invalid bbox",
// "issues":[...]}"`). Проверено вручную: под этим bbox сайт отдаёт станции
// по всей России (подтверждено на Новосибирске, Владивостоке и т.д. —
// см. также `sitemap-stations.xml`, где ~100 городов от Калининграда до
// Южно-Сахалинска), а не только Москву.
//
// Использует ту же сетку тайлов, что и sync-gdebenz.mjs (макро-регионы,
// разреженные регионы крупным тайлом, отсечка по границе РФ) — импортирует
// её оттуда, чтобы не дублировать геометрию и не расходиться в поведении.
// ---------------------------------------------------------------------------

const API_URL = "https://benzinest.ru/api/stations";
const BATCH = Math.max(1, Number(process.env.BENZINEST_BATCH) || 500);
const SELECT_CHUNK = Math.max(1, Number(process.env.BENZINEST_SELECT_CHUNK) || 1000);
// robots.txt benzinest.ru прямо просит Crawl-delay: 2 — держим ЭТО общим
// гейтом на ВСЕХ воркеров разом (createRateGate — одна очередь на всех
// вызовов, не по одному интервалу на воркера), иначе конкурентность просто
// умножала бы реальный RPS сайту, а не только скрывала сетевую задержку.
const MIN_INTERVAL_MS = Math.max(0, Number(process.env.BENZINEST_MIN_INTERVAL_MS ?? 2000));
const DEFAULT_CONCURRENCY = 2;
const CONCURRENCY = Math.max(1, Number(process.env.BENZINEST_CONCURRENCY) || DEFAULT_CONCURRENCY);

const rateGate = createRateGate(MIN_INTERVAL_MS);

let tilesFailedCount = 0;

const roundCoord = (n) => Number(n.toFixed(6));

/** Запрос одного тайла. Возвращает массив станций (см. формат в lib/benzinest-parse.mjs). */
export async function fetchBenzinestTile(tile, depth = 0) {
  const { south, west, north, east } = tile;
  const url = `${API_URL}?bbox=${south},${west},${north},${east}`;

  let lastErr;
  for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
    await rateGate();
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    const proxyUrl = nextProxy();
    try {
      const res = await requestJson(url, controller.signal, proxyUrl);
      await waitAfterResponse(res, responseDelayForTransport(res.transport));
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = res.data;

      if (data && !Array.isArray(data) && data.error) {
        const issues = Array.isArray(data.issues) ? data.issues.map((i) => i?.message).filter(Boolean) : [];
        const tooLarge = issues.some((m) => /too large/i.test(m));
        // Наш тайл (TILE_LAT×TILE_LON или SPARSE_TILE_LAT×LON, см.
        // sync-gdebenz.mjs) заведомо меньше лимита 4 кв.градуса, так что это
        // на практике не должно случаться — но дробим на всякий случай тем
        // же способом, что и gdebenz, а не просто фейлим тайл целиком.
        if (tooLarge && depth <= 6) {
          const midLat = roundCoord((south + north) / 2);
          const midLon = roundCoord((west + east) / 2);
          const sub = [
            { south, west, north: midLat, east: midLon },
            { south, west: midLon, north: midLat, east },
            { south: midLat, west, north, east: midLon },
            { south: midLat, west: midLon, north, east },
          ];
          const out = [];
          for (const s of sub) out.push(...(await fetchBenzinestTile(s, depth + 1)));
          return out;
        }
        throw new Error(`benzinest bbox error: ${data.error} (${issues.join("; ")})`);
      }

      if (!Array.isArray(data)) return [];
      return data;
    } catch (e) {
      lastErr = e;
      if (attempt < MAX_RETRIES) await sleep(RETRY_DELAY_MS * attempt);
    } finally {
      clearTimeout(timer);
    }
  }
  console.warn(`  benzinest: не удалось получить тайл после ${MAX_RETRIES} попыток (${describeFetchError(lastErr)}): ${url}`);
  tilesFailedCount++;
  return [];
}

/**
 * @param {object} [opts]
 * @param {(msg: string) => void} [opts.log]
 * @param {Array<{south:number,west:number,north:number,east:number}>} [opts.tiles]
 *   Явный список тайлов вместо полного обхода России — используется
 *   sync-gdebenz.mjs для failover: те же прямоугольники, на которых gdebenz
 *   не смог отдать данные, запрашиваются здесь же, у другого источника,
 *   вместо того чтобы просто терять станции внутри них.
 */
export async function runSync({ log = console.log, tiles: explicitTiles } = {}) {
  tilesFailedCount = 0;

  try {
    let tiles;
    if (explicitTiles) {
      tiles = explicitTiles;
      log(`benzinest: выборочный обход (failover), тайлов: ${tiles.length}`);
    } else {
      const russia = await loadRussiaPolygon();
      tiles = [];
      for (const r of MACRO_REGIONS) {
        tiles.push(...(r.sparse ? tilesFor(r, SPARSE_TILE_LAT, SPARSE_TILE_LON) : tilesFor(r)));
      }
      const beforeFilter = tiles.length;
      tiles = tiles.filter((t) => tileTouchesRussia(t, russia));
      log(`benzinest: полный обход РФ, тайлов ${tiles.length} (отброшено по границе: ${beforeFilter - tiles.length})`);
    }

    const byOsm = new Map();
    let tilesFetched = 0;
    let rawRows = 0;
    let nextTileIndex = 0;
    const workerCount = Math.min(CONCURRENCY, tiles.length) || 1;

    async function processTile(tile) {
      const rows = await fetchBenzinestTile(tile);
      tilesFetched++;
      rawRows += rows.length;
      for (const r of rows) {
        if (!r || r.lat == null || r.lng == null) continue;
        const osmId = osmIdForBenzinest(r);
        if (osmId == null) continue; // см. osmIdForBenzinest — на сегодня в выдаче таких нет
        const key = String(osmId);
        const prev = byOsm.get(key);
        if (!prev || (!prev.status && r.status)) byOsm.set(key, r);
      }
      if (tilesFetched % 10 === 0 || tilesFetched === tiles.length) {
        log(`benzinest тайлы: ${tilesFetched}/${tiles.length}, станций собрано: ${byOsm.size}`);
      }
    }

    const workers = Array.from({ length: workerCount }, async () => {
      while (true) {
        const index = nextTileIndex++;
        if (index >= tiles.length) return;
        await processTile(tiles[index]);
      }
    });
    await Promise.all(workers);
    log(`benzinest: получено строк ${rawRows}, уникальных станций ${byOsm.size}, тайлов не получено ${tilesFailedCount}/${tiles.length}`);

    const stationRows = [];
    const osmIds = [];
    const meta = [];
    for (const r of byOsm.values()) {
      const osmId = osmIdForBenzinest(r);
      const name = String(r?.name || "").trim() || "АЗС";
      osmIds.push(osmId);
      stationRows.push({
        name,
        brand: name,
        lat: r.lat,
        lng: r.lng,
        address: String(r?.address || "").trim() || null,
        source: "osm",
        osm_id: osmId,
        benzinest_id: String(r.id),
      });
      meta.push({ osm_id: osmId, station: r });
    }

    let upserted = 0;
    for (let i = 0; i < stationRows.length; i += BATCH) {
      const chunk = stationRows.slice(i, i + BATCH);
      const { error } = await withSupabaseRetry(() => bulkUpsert("stations", chunk, ["osm_id"]), { label: "stations upsert" });
      if (error) throw new Error(`stations upsert: ${error.message}`);
      upserted += chunk.length;
      log(`benzinest станции upsert: ${upserted}/${stationRows.length}`);
    }

    const osmToUuid = new Map();
    for (let i = 0; i < osmIds.length; i += SELECT_CHUNK) {
      const slice = osmIds.slice(i, i + SELECT_CHUNK);
      const { data, error } = await withSupabaseRetry(() => selectStationIdsByOsmId(slice), {
        label: "stations select",
      });
      if (error) throw new Error(`stations select: ${error.message}`);
      for (const row of data) osmToUuid.set(String(row.osm_id), row.id);
    }
    log(`benzinest: сопоставлено станций ${osmToUuid.size}`);

    // Чистим только устаревшие (>2 суток) отчёты benzinest — та же логика,
    // что и у gdebenz: безусловный delete перед вставкой сносил бы отчёты по
    // станциям, не попавшим в текущий (возможно выборочный, failover) обход.
    {
      const cutoffIso = new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toISOString();
      const { error } = await withSupabaseRetry(
        () => dbQuery(`delete from reports where client_id = $1 and created_at < $2`, ["benzinest", cutoffIso]),
        { label: "reports cleanup" }
      );
      if (error) throw new Error(`reports cleanup: ${error.message}`);
    }

    const reportRows = [];
    for (const m of meta) {
      const uuid = osmToUuid.get(String(m.osm_id));
      if (!uuid) continue;
      const row = toBenzinestReportRow(uuid, m.station);
      if (row) reportRows.push(row);
    }

    let reportsInserted = 0;
    for (let i = 0; i < reportRows.length; i += BATCH) {
      const chunk = reportRows.slice(i, i + BATCH);
      const { error } = await withSupabaseRetry(() => bulkInsert("reports", chunk), { label: "reports insert" });
      if (error) throw new Error(`reports insert: ${error.message}`);
      reportsInserted += chunk.length;
      log(`benzinest статусы insert: ${reportsInserted}/${reportRows.length}`);
    }

    const summary = {
      tilesFetched,
      tilesFailed: tilesFailedCount,
      rawRows,
      uniqueStations: byOsm.size,
      stationsUpserted: upserted,
      mapped: osmToUuid.size,
      reportsInserted,
    };
    log(`benzinest готово. Тайлов: ${tilesFetched}, станций upsert: ${upserted}, статусов: ${reportsInserted}.`);
    return summary;
  } finally {
    await closeTransports();
  }
}

const runAsScript = process.argv[1]?.endsWith("sync-benzinest.mjs");
if (runAsScript) {
  runSync()
    .catch((e) => {
      console.error("Ошибка синка benzinest:", e.message);
      process.exitCode = 1;
    })
    .finally(() => closeDb());
}
