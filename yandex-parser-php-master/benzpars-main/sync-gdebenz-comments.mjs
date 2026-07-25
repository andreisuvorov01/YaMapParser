import { loadEnv } from "./load-env.mjs";
import { PROXY_POOL, checkProxyHealth, closeTransports, createRateGate, createShardedRateGate, looksLikeBlock, requestWithRetries, sleep } from "./lib/gdebenz-http.mjs";
import { toCommentReportRow } from "./lib/gdebenz-parse.mjs";
import { toBenzinestReportRows } from "./lib/benzinest-parse.mjs";
import { fetchJson as fetchBenzinStatusJson } from "./lib/benzinstatus-http.mjs";
import { toBenzinStatusDetailReportRow } from "./lib/benzinstatus-parse.mjs";
import { withSupabaseRetry } from "./lib/supabase-retry.mjs";
import { bulkInsert, closeDb, dbQuery } from "./lib/db.mjs";

loadEnv();

let isMain;
try {
  isMain = process.argv[1] && import.meta.url === `file://${process.argv[1].replace(/\\/g, "/")}`;
} catch {
  isMain = false;
}
const runAsScript = isMain || process.argv[1]?.endsWith("sync-gdebenz-comments.mjs");

// Разбираем CLI-флаги ДО объявления констант ниже (они читают process.env) —
// --bbox=... / позиционный bbox, --all (полный обход без учёта cooldown) и
// --turbo (заметно быстрее, ценой более высокого риска повторно словить
// блок по IP — см. SYNC.md). Флаги не переопределяют явно заданные
// переменные окружения, только достраивают дефолты.
if (runAsScript) {
  const args = process.argv.slice(2);
  const argBbox = args
    .map((a) => (a.startsWith("--bbox=") ? a.slice("--bbox=".length) : a))
    .find((a) => a && !a.startsWith("-"));
  if (argBbox) {
    if (!process.env.SYNC_BBOX) process.env.SYNC_BBOX = argBbox.trim();
  }

  if (args.includes("--all")) {
    if (!process.env.COMMENTS_COOLDOWN_MIN) process.env.COMMENTS_COOLDOWN_MIN = "0";
  }
  if (args.includes("--safe")) {
    process.env.COMMENTS_MIN_INTERVAL_MS = "1500";
    process.env.COMMENTS_CONCURRENCY = "1";
    process.env.COMMENTS_BREAKER_THRESHOLD = "100";
    process.env.COMMENTS_RETRY_UNTIL_SUCCESS = "1";
    process.env.GDEBENZ_BROWSER_ON_BLOCK = "1";
  } else if (args.includes("--turbo") || args.includes("--fast")) {
    // Конкурентность и темп теперь по умолчанию и так подбираются по живым
    // слотам XRay-пула (см. COMMENTS_CONCURRENCY_ENV/MAX_AUTO_CONCURRENCY
    // ниже) — --turbo больше не занижает их плоским числом, а только
    // повышает терпимость к отдельным сбоям, чтобы не останавливаться
    // раньше времени из-за единичных проблем на некоторых слотах пула.
    if (!process.env.COMMENTS_BREAKER_THRESHOLD) process.env.COMMENTS_BREAKER_THRESHOLD = "12";
  }
}

// ---------------------------------------------------------------------------
// Быстрый забор ленты отметок станций (`/api/comments/<id>/recent`) —
// НЕ делает тайловый обход и не upsert'ит станции (это задача
// scripts/sync-gdebenz.mjs). Читает список станций и их `gdebenz_id`
// напрямую из Supabase курсорной пагинацией по `id` и дёргает per-station
// эндпоинт. Использует тот же HTTP/прокси-транспорт
// (scripts/lib/gdebenz-http.mjs), что и тайловый синк — те же ретраи,
// ротация прокси и фоллбэк transports (browser/https), плюс два
// специфичных для этого скрипта предохранителя (см. ниже).
//
// Для каждой станции, где запрос удался, вся предыдущая история
// client_id='gdebenz' удаляется и заменяется свежей лентой целиком (у
// отметок gdebenz нет стабильного id для точечного мержа). Запись идёт
// постранично (чанками по PAGE_SIZE станций), а не одним delete+insert в
// конце прогона — так прогресс сохраняется инкрементально, и падение
// скрипта на середине обхода не теряет уже записанные страницы.
//
// Третий пункт (независимо от исхода gdebenz/benzinest выше) — цена топлива
// с map.benzin-status.tech, см. блок BENZINSTATUS_* ниже: выполняется ВСЕГДА
// для станций с benzinstatus_id, а не только как фоллбэк-по-неудаче — это
// единственный из трёх источников, где вообще есть цены.
// ---------------------------------------------------------------------------

const COMMENTS_API_BASE = "https://gdebenz.org/api/comments";
const COMMENTS_LIMIT = Math.max(1, Number(process.env.COMMENTS_LIMIT) || 20);
/** Сколько станций тянуть из Supabase и обрабатывать за одну "волну". */
const PAGE_SIZE = Math.max(1, Number(process.env.PAGE_SIZE) || 500);
/** Размер батча insert/update-запроса. */
const BATCH = Math.max(1, Number(process.env.BATCH) || 500);
/** Число параллельных запросов к gdebenz.org внутри одной страницы.
 * Без явного COMMENTS_CONCURRENCY подбирается по факту живых слотов
 * XRay-пула proxy_server.py (см. resolveConcurrency ниже) — раньше здесь был
 * плоский дефолт 1-2, актуальный только пока /fetch сериализовал все запросы
 * через один прокси. Теперь пул сам держит независимый темп на каждый IP
 * (см. XRAY_POOL_MIN_INTERVAL_MS в proxy_server.py), так что общая скорость
 * растёт вместе с конкурентностью, а не упирается в неё. */
const DEFAULT_CONCURRENCY = 4;
const MAX_AUTO_CONCURRENCY = 32;
const COMMENTS_CONCURRENCY_ENV = process.env.COMMENTS_CONCURRENCY ? Math.max(1, Number(process.env.COMMENTS_CONCURRENCY)) : null;
/** Не дёргать станцию, если её ленту уже забирали успешно в последние N минут —
 * без этого каждый прогон долбит ВСЕ станции заново, даже те, что не устарели.
 * Держите заметно ниже интервала расписания (см. SYNC.md), чтобы плановый
 * прогон не считал их "недавними" и не пропускал. */
const COMMENTS_COOLDOWN_MIN = Math.max(0, Number(process.env.COMMENTS_COOLDOWN_MIN ?? 25));
/** Доп. глобальный потолок частоты СВЕРХУ per-slot пейсинга proxy_server.py.
 * По умолчанию 0 (выключен): раньше это был единственный ограничитель темпа
 * и на деле сериализовал все воркеры друг за другом (общий "next allowed at"
 * на всех), сводя COMMENTS_CONCURRENCY>1 почти к нулю. Теперь пейсинг на
 * каждый IP держит proxy_server.py (XRAY_POOL_MIN_INTERVAL_MS), а этот гейт
 * можно включить как доп. подстраховку через env, если понадобится. */
const COMMENTS_MIN_INTERVAL_MS = Math.max(0, Number(process.env.COMMENTS_MIN_INTERVAL_MS ?? 0));
/** Сколько подряд неудачных запросов считать признаком блокировки по IP и
 * прерывать весь прогон, а не продолжать долбить уже заблокированный сервер. */
const COMMENTS_BREAKER_THRESHOLD = Math.max(1, Number(process.env.COMMENTS_BREAKER_THRESHOLD) || 6);
const COMMENTS_RETRY_UNTIL_SUCCESS = /^(1|true|yes)$/i.test(process.env.COMMENTS_RETRY_UNTIL_SUCCESS || "");
const COMMENTS_MAX_STATION_ATTEMPTS = Math.max(0, Number(process.env.COMMENTS_MAX_STATION_ATTEMPTS) || 0);
// 0 = бесконечно повторять критичные записи в Supabase. Это защищает долгий
// парс от кратковременных `TypeError: fetch failed` при insert/delete после
// уже собранной страницы комментариев.
const COMMENTS_DB_WRITE_RETRIES = Math.max(0, Number(process.env.COMMENTS_DB_WRITE_RETRIES ?? 0));

// ---------------------------------------------------------------------------
// Failover на benzinest.ru — та же идея, что и в sync-gdebenz.mjs для
// тайлового обхода, но на уровне отдельной станции: если gdebenz исчерпал
// все свои попытки (requestWithRetries внутри fetchStationComments уже
// перепробовал MAX_RETRIES раз через разные слоты пула — это и есть "все
// попытки неуспешны"), пробуем ТУ ЖЕ станцию через отдельный эндпоинт
// benzinest с лентой отметок — `benzinest.ru/api/stations/<id>/reports`
// (вернёт до BENZINEST_REPORTS_LIMIT записей за BENZINEST_REPORTS_SINCE,
// каждая со своим created_at и votes.up/down) — прямой аналог gdebenz-ленты
// `/api/comments/<id>/recent`, а не один текущий снимок станции.
const BENZINEST_API_BASE = "https://benzinest.ru/api/stations";
const BENZINEST_FALLBACK_ENABLED = !/^(0|false|no)$/i.test(process.env.BENZINEST_FALLBACK ?? "1");
const BENZINEST_REPORTS_SINCE = process.env.BENZINEST_REPORTS_SINCE || "7d";
const BENZINEST_REPORTS_LIMIT = Math.max(1, Number(process.env.BENZINEST_REPORTS_LIMIT) || 50);
// robots.txt benzinest.ru просит Crawl-delay: 2 — но запросы реально идут
// через пул из XRAY_POOL_SIZE разных исходящих IP (см. XRayFleet в
// proxy_server.py), а не с одного адреса. Единый глобальный гейт на 2с
// душил бы весь скрипт до ~0.5 запроса в секунду ВНЕ ЗАВИСИМОСТИ от размера
// пула — на 20000+ станций (режим "gdebenz недоступен целиком", см.
// gdebenzDown ниже) это часы впустую. Шардированный гейт держит вежливый
// 2-секундный интервал на КАЖДЫЙ "виртуальный IP" по отдельности, а
// суммарная скорость растёт вместе с BENZINEST_RATE_SHARDS (по умолчанию —
// как типичный размер пула в proxy_server.py, XRAY_POOL_SIZE=16).
const BENZINEST_MIN_INTERVAL_MS = Math.max(0, Number(process.env.BENZINEST_MIN_INTERVAL_MS ?? 2000));
const BENZINEST_RATE_SHARDS = Math.max(1, Number(process.env.BENZINEST_RATE_SHARDS) || 16);
const benzinestRateGate = createShardedRateGate(BENZINEST_RATE_SHARDS, BENZINEST_MIN_INTERVAL_MS);

/** id станции на benzinest.ru — либо явно известный (benzinest_id), либо тот
 * же числовой OSM id, что и gdebenz_id (оба сайта используют OSM node id
 * напрямую — см. разведку в SYNC.md/истории чата). Нечисловые gdebenz_id
 * (синтетические "usr_..." для пользовательских станций gdebenz) сюда не
 * годятся — на benzinest это заведомо другая станция или её вообще нет. */
function benzinestIdFor(station) {
  if (station?.benzinest_id) return String(station.benzinest_id);
  const gid = String(station?.gdebenz_id || "");
  return /^\d+$/.test(gid) ? gid : null;
}

async function fetchBenzinestReports(benzinestId) {
  const url = `${BENZINEST_API_BASE}/${encodeURIComponent(benzinestId)}/reports?since=${encodeURIComponent(BENZINEST_REPORTS_SINCE)}&limit=${BENZINEST_REPORTS_LIMIT}`;
  await benzinestRateGate();
  try {
    return await requestWithRetries(url, (res) => (Array.isArray(res.data?.items) ? res.data.items : []));
  } catch (e) {
    console.warn(`  [benzinest ${benzinestId}] фоллбэк тоже не удался: ${e.message}`);
    return null;
  }
}

// ---------------------------------------------------------------------------
// map.benzin-status.tech — третий, полностью независимый пункт: выполняется
// для КАЖДОЙ станции с benzinstatus_id, регардless от того, как сложилось с
// gdebenz/benzinest выше (успех, фоллбэк или неудача) — это единственный из
// трёх источников с ценами на топливо, и цену нужно забирать всегда, когда
// она есть, а не только когда gdebenz недоступен. Сайт не защищён анти-ботом
// (см. lib/benzinstatus-http.mjs) — работает без proxy_server.py/XRay-пула,
// своим собственным темпом. Неудачи здесь НЕ считаются к брейкеру/
// consecutiveFailures выше — это отдельный источник, его сбои не признак
// блокировки gdebenz.
// ---------------------------------------------------------------------------
const BENZINSTATUS_API_BASE = "https://map.benzin-status.tech/api/stations";
const BENZINSTATUS_ENABLED = !/^(0|false|no)$/i.test(process.env.BENZINSTATUS_ENABLED ?? "1");
// 150мс (~6.7 зап/с) оказалось быстрее, чем терпит их сервер (HTTP 429
// rate_limited на реальном прогоне) — 500мс (~2 зап/с) заметно безопаснее.
// Учтите: sync-benzinstatus.mjs (тайловый обход) читает ту же переменную
// окружения отдельным процессом — если оба таймера пересекутся по времени,
// суммарный темп к сайту будет выше, чем каждый по отдельности.
const BENZINSTATUS_MIN_INTERVAL_MS = Math.max(0, Number(process.env.BENZINSTATUS_MIN_INTERVAL_MS ?? 500));
const benzinstatusRateGate = createRateGate(BENZINSTATUS_MIN_INTERVAL_MS);

async function fetchBenzinStatusDetail(benzinstatusId) {
  await benzinstatusRateGate();
  try {
    return await fetchBenzinStatusJson(`${BENZINSTATUS_API_BASE}/${encodeURIComponent(benzinstatusId)}`);
  } catch (e) {
    console.warn(`  [benzinstatus ${benzinstatusId}] ${e.message}`);
    return null;
  }
}

const rateGate = createRateGate(COMMENTS_MIN_INTERVAL_MS);

async function hasAvailableXrayConfig() {
  const health = await checkProxyHealth();
  if (!health || !Array.isArray(health.configs)) return false;
  return health.configs.some((c) => c?.healthy === true && Number(c?.blocked_for_sec || 0) <= 0);
}

async function fetchStationComments(gdebenzId) {
  const url = `${COMMENTS_API_BASE}/${encodeURIComponent(gdebenzId)}/recent?limit=${COMMENTS_LIMIT}`;
  await rateGate();
  try {
    return await requestWithRetries(url, (res) => (Array.isArray(res.data) ? res.data : []));
  } catch (e) {
    // Детальный вывод: показываем тип ошибки, статус (если есть), и тело ответа
    const cause = e.cause;
    const detail = cause?.message || "";
    // Если в ошибке есть признаки блокировки — подсвечиваем
    const blockHints = [];
    if (/403|429|Cloudflare|challenge|captcha|DDoS/i.test(detail)) blockHints.push("🔒 БЛОКИРОВКА");
    if (/500|502|503|504/i.test(detail)) blockHints.push("💥 СЕРВЕР");
    if (/timeout|timed out|ETIMEDOUT/i.test(detail)) blockHints.push("⏱ ТАЙМАУТ");
    const hint = blockHints.length ? ` ${blockHints.join(" ")}` : "";
    console.warn(`  [${gdebenzId}]${hint} ${e.message}`);
    return { error: e };
  }
}

/** Добавляет условие bbox к SQL-запросу и параметрам (мутирует params, возвращает SQL-фрагмент). */
function bboxClause(bbox, params) {
  if (!bbox) return "";
  const [south, west, north, east] = bbox;
  params.push(south, north, west, east);
  const n = params.length;
  return ` and lat >= $${n - 3} and lat <= $${n - 2} and lng >= $${n - 1} and lng <= $${n}`;
}

/** Общее число станций-кандидатов (те же фильтры, что и у страниц обхода,
 * но без cooldown) — знаменатель для прогресса в логе, чтобы "N/M" значило
 * "N из всех станций в БД", а не "N из размера текущей страницы" (PAGE_SIZE). */
async function countCandidateStations({ bbox }) {
  const params = [];
  let sql = `select count(*)::int as count from stations where gdebenz_id is not null`;
  sql += bboxClause(bbox, params);
  const { data, error } = await withSupabaseRetry(() => dbQuery(sql, params), { label: "stations count" });
  if (error) throw new Error(`stations count: ${error.message}`);
  return data?.[0]?.count ?? 0;
}

async function fetchNeverSyncedPage({ bbox, afterId }) {
  const params = [];
  let sql = `select id, gdebenz_id, benzinest_id, benzinstatus_id from stations where gdebenz_id is not null and gdebenz_comments_synced_at is null`;
  sql += bboxClause(bbox, params);
  if (afterId) {
    params.push(afterId);
    sql += ` and id > $${params.length}`;
  }
  params.push(PAGE_SIZE);
  sql += ` order by id asc limit $${params.length}`;
  const { data, error } = await withSupabaseRetry(() => dbQuery(sql, params), { label: "stations select (никогда не собирались)" });
  if (error) throw new Error(`stations select: ${error.message}`);
  return data ?? [];
}

/**
 * Устаревшие станции БЕЗ ни одного реального отчёта в `last_report_at`
 * (станция никогда не давала данных, либо колонка ещё не заполнилась после
 * миграции). Ориентироваться не на что, кроме собственной отметки о попытке
 * синка — курсор и порядок по (gdebenz_comments_synced_at, id), как раньше.
 */
async function fetchStaleNoReportPage({ bbox, cutoffIso, cursor }) {
  const params = [cutoffIso];
  let sql =
    `select id, gdebenz_id, benzinest_id, benzinstatus_id, gdebenz_comments_synced_at from stations ` +
    `where gdebenz_id is not null and gdebenz_comments_synced_at < $1 and last_report_at is null`;
  sql += bboxClause(bbox, params);
  if (cursor) {
    params.push(cursor.syncedAt, cursor.id);
    const a = params.length - 1;
    const b = params.length;
    sql += ` and (gdebenz_comments_synced_at > $${a} or (gdebenz_comments_synced_at = $${a} and id > $${b}))`;
  }
  params.push(PAGE_SIZE);
  sql += ` order by gdebenz_comments_synced_at asc, id asc limit $${params.length}`;
  const { data, error } = await withSupabaseRetry(() => dbQuery(sql, params), { label: "stations select (устаревшие, без отчётов)" });
  if (error) throw new Error(`stations select: ${error.message}`);
  return data ?? [];
}

/**
 * Устаревшие станции С реальным последним отчётом (`last_report_at` не
 * null) — приоритет по фактической свежести ВИДИМЫХ пользователю данных, а
 * не по нашей внутренней отметке о попытке синка. Станция могла быть
 * проверена недавно (gdebenz_comments_synced_at свежий), но если тогда
 * пришла пустая лента, реальные данные по ней как были, так и остались
 * старыми — этот проход именно это и ловит, не давая таким станциям
 * бесконечно застревать позади очереди только потому, что мы их "недавно
 * проверяли".
 *
 * Курсорная пагинация по паре (last_report_at, id) — та же защита от
 * пропусков/дублей, что и у fetchStaleNoReportPage: как только станция
 * обработана, ей заодно обновляется gdebenz_comments_synced_at на "сейчас",
 * так что она перестаёт проходить фильтр `lt(cutoffIso)` независимо от того,
 * куда сдвинулся её last_report_at.
 */
async function fetchStaleWithReportPage({ bbox, cutoffIso, cursor }) {
  const params = [cutoffIso];
  let sql =
    `select id, gdebenz_id, benzinest_id, benzinstatus_id, gdebenz_comments_synced_at, last_report_at from stations ` +
    `where gdebenz_id is not null and gdebenz_comments_synced_at < $1 and last_report_at is not null`;
  sql += bboxClause(bbox, params);
  if (cursor) {
    params.push(cursor.lastReportAt, cursor.id);
    const a = params.length - 1;
    const b = params.length;
    sql += ` and (last_report_at > $${a} or (last_report_at = $${a} and id > $${b}))`;
  }
  params.push(PAGE_SIZE);
  sql += ` order by last_report_at asc, id asc limit $${params.length}`;
  const { data, error } = await withSupabaseRetry(() => dbQuery(sql, params), { label: "stations select (устаревшие, по времени отчёта)" });
  if (error) throw new Error(`stations select: ${error.message}`);
  return data ?? [];
}

/**
 * Отдаёт страницы станций в порядке приоритета "самые старые данные —
 * первыми", в три прохода:
 *   1. никогда не собирали вообще (нет даже приблизительной свежести —
 *      хуже любой просроченной);
 *   2. собирали, но реального отчёта так и нет — приоритет по тому, когда
 *      мы последний раз пытались (больше ориентироваться не на что);
 *   3. собирали, и реальный отчёт есть — приоритет по фактическому времени
 *      этого отчёта (last_report_at), а НЕ по тому, когда мы его в последний
 *      раз проверяли: иначе станция с недавней проверкой, но старыми
 *      реальными данными (например, лента в тот раз пришла пустой) осела бы
 *      в хвосте очереди только из-за своей "свежей" отметки синка.
 * Если прогон прервётся (брейкер, падение, лимит времени), необработанным
 * гарантированно останется только "менее просроченный" хвост текущей фазы,
 * а не случайный набор по id.
 */
async function* iterateStationPages({ bbox, cutoffIso }) {
  let afterId;
  while (true) {
    const page = await fetchNeverSyncedPage({ bbox, afterId });
    if (page.length === 0) break;
    yield { page, phase: "never-synced" };
    afterId = page[page.length - 1].id;
    if (page.length < PAGE_SIZE) break;
  }

  let syncCursor;
  while (true) {
    const page = await fetchStaleNoReportPage({ bbox, cutoffIso, cursor: syncCursor });
    if (page.length === 0) break;
    yield { page, phase: "stale-no-report" };
    const last = page[page.length - 1];
    syncCursor = { syncedAt: last.gdebenz_comments_synced_at, id: last.id };
    if (page.length < PAGE_SIZE) break;
  }

  let reportCursor;
  while (true) {
    const page = await fetchStaleWithReportPage({ bbox, cutoffIso, cursor: reportCursor });
    if (page.length === 0) break;
    yield { page, phase: "stale-with-report" };
    const last = page[page.length - 1];
    reportCursor = { lastReportAt: last.last_report_at, id: last.id };
    if (page.length < PAGE_SIZE) break;
  }
}

/** Обрабатывает одну страницу станций: тянет отметки конкурентно (с рейт-гейтом и брейкером), пишет в БД сразу по завершении страницы. */
async function processPage(page, state, concurrency, totalStations, gdebenzDown) {
  // Каждая запись — { stationId, clientId, rows } — один источник может
  // отдать 0..N строк для станции, а сама станция за один проход страницы
  // может попасть в ДО ДВУХ записей: одна от gdebenz/benzinest (основной
  // статус/очередь), другая — независимо — от benzinstatus (цена). Раньше
  // тут был Map с ОДНОЙ записью на station.id — так нельзя было держать оба
  // источника разом.
  const entries = [];
  const processedIds = [];
  const benzinstatusProcessedIds = [];
  const queue = [...page];
  const attemptsByStation = new Map();
  const workerCount = Math.min(concurrency, page.length);

  /** gdebenz (+ фоллбэк на benzinest при неудаче) — прежняя логика, только
   * `continue` внутри цикла воркера заменён на `return` из отдельной
   * функции, чтобы после неё БЕЗ УСЛОВИЙ выполнялся третий пункт
   * (benzinstatus) ниже, независимо от того, как эта функция завершилась. */
  async function handleGdebenzBenzinest(station, attempt) {
    try {
      // Если gdebenz недоступен целиком (gdebenzDown) — не тратим MAX_RETRIES
      // попыток на заведомо мёртвый источник, сразу идём в фоллбэк ниже с
      // синтетической "ошибкой".
      const result = gdebenzDown
        ? { error: new Error("gdebenz пропущен: источник недоступен целиком") }
        : await fetchStationComments(station.gdebenz_id);
      state.totals.attempted++;

      if (result.error) {
        // Реальная неудача gdebenz исчерпала все его попытки
        // (requestWithRetries внутри fetchStationComments уже перепробовал
        // MAX_RETRIES раз через разные слоты пула), либо gdebenz намеренно
        // пропущен целиком (gdebenzDown) — в обоих случаях пробуем ту же
        // станцию на benzinest.ru через ленту отчётов (см.
        // BENZINEST_FALLBACK_ENABLED выше). При gdebenzDown это происходит
        // для КАЖДОЙ станции, а не как редкое исключение — именно поэтому
        // benzinestRateGate шардирован по пулу (см. BENZINEST_RATE_SHARDS),
        // а не один общий гейт на весь скрипт.
        const benzinestId = BENZINEST_FALLBACK_ENABLED ? benzinestIdFor(station) : null;
        if (benzinestId) {
          const fallback = await fetchBenzinestReports(benzinestId);
          if (fallback) {
            state.consecutiveFailures = 0;
            processedIds.push(station.id);
            state.totals.benzinestFallback++;
            const rows = toBenzinestReportRows(station.id, fallback);
            if (rows.length > 0) {
              entries.push({ stationId: station.id, clientId: "benzinest", rows });
              state.totals.ok++;
            } else {
              state.totals.empty++;
            }
            return;
          }
        }

        if (gdebenzDown) {
          // gdebenz пропущен осознанно — не считаем это неожиданной ошибкой
          // gdebenz и не крутим по нему брейкер/ретраи, это не всплеск
          // блокировки, а заранее известное состояние источника.
          state.totals.failed++;
          return;
        }

        const canRetry = COMMENTS_RETRY_UNTIL_SUCCESS && (COMMENTS_MAX_STATION_ATTEMPTS === 0 || attempt < COMMENTS_MAX_STATION_ATTEMPTS);
        if (canRetry) {
          state.totals.retried++;
          queue.push(station);
        } else {
          state.totals.failed++;
        }
        state.consecutiveFailures++;
        if (state.consecutiveFailures >= COMMENTS_BREAKER_THRESHOLD) {
          if (await hasAvailableXrayConfig()) {
            console.warn(
              `  breaker: ${state.consecutiveFailures} ошибок подряд, но в прокси ещё есть доступные XRay-конфиги — продолжаю`
            );
            state.consecutiveFailures = 0;
          } else {
            state.breakerTripped = true;
            state.breakerError = result.error;
          }
        }
        if (canRetry) await sleep(COMMENTS_MIN_INTERVAL_MS || 1000);
        return;
      }
      state.consecutiveFailures = 0;
      processedIds.push(station.id);

      const commentEntries = result;
      if (commentEntries.length === 0) {
        state.totals.empty++;
        return;
      }
      const rows = commentEntries.map((e) => toCommentReportRow(station.id, e)).filter(Boolean);
      if (rows.length === 0) {
        state.totals.empty++;
        return;
      }
      entries.push({ stationId: station.id, clientId: "gdebenz", rows });
      state.totals.ok++;
    } finally {
      // try/finally, а не отдельная проверка в конце "успешной" ветки —
      // раньше прогресс печатался только после gdebenz-успеха, и в режиме
      // gdebenzDown (ВСЕ станции уходят в benzinest-фоллбэк с ранними
      // `return`) не печатался вообще, хотя запросы реально шли. finally
      // отрабатывает при любом return из try — прогресс виден при любом
      // исходе (gdebenz, benzinest-фоллбэк или неудача).
      if (state.totals.attempted % 10 === 0) {
        console.log(
          `  ... ${state.totals.attempted}/${totalStations}: ok=${state.totals.ok} empty=${state.totals.empty} failed=${state.totals.failed}` +
            (state.totals.benzinestFallback ? ` benzinest=${state.totals.benzinestFallback}` : "") +
            (state.totals.benzinstatusAttempted ? ` benzinstatus(ok=${state.totals.benzinstatusOk} failed=${state.totals.benzinstatusFailed})` : "")
        );
      }
    }
  }

  /** Третий, независимый пункт — цена с benzinstatus. Неудача здесь НЕ
   * трогает state.consecutiveFailures/breakerTripped (это отдельный
   * источник, его сбои — не признак блокировки gdebenz). */
  async function handleBenzinStatus(station) {
    state.totals.benzinstatusAttempted++;
    const detail = await fetchBenzinStatusDetail(station.benzinstatus_id);
    if (!detail) {
      state.totals.benzinstatusFailed++;
      return;
    }
    benzinstatusProcessedIds.push(station.id);
    const row = toBenzinStatusDetailReportRow(station.id, detail);
    if (row) {
      entries.push({ stationId: station.id, clientId: "benzinstatus", rows: [row] });
      state.totals.benzinstatusOk++;
    } else {
      state.totals.benzinstatusEmpty++;
    }
  }

  async function worker() {
    while (true) {
      if (state.breakerTripped) return;
      const station = queue.shift();
      if (!station) return;
      const attempt = (attemptsByStation.get(station.id) || 0) + 1;
      attemptsByStation.set(station.id, attempt);

      await handleGdebenzBenzinest(station, attempt);

      // attempt === 1 — только на первый заход по станции: если
      // handleGdebenzBenzinest положил её обратно в queue на повтор (canRetry),
      // benzinstatus для неё уже отработал в этом заходе и повторять его
      // синхронно с ретраями gdebenz незачем (цена не станет "более неудачной").
      if (BENZINSTATUS_ENABLED && station.benzinstatus_id && attempt === 1) {
        await handleBenzinStatus(station);
      }
    }
  }

  await Promise.all(Array.from({ length: workerCount }, worker));

  if (entries.length > 0) {
    // Станции сгруппированы по фактическому источнику их строк (gdebenz,
    // benzinest-фоллбэк или benzinstatus) — удаляем/пишем отдельно по
    // client_id, иначе безусловный delete по "gdebenz" стёр бы историю
    // станций, которые в этом прогоне вообще не спрашивались у gdebenz
    // (обработаны только через фоллбэк/benzinstatus), а delete по одному
    // client_id не тронул бы строки других client_id той же станции.
    const idsByClient = new Map();
    for (const { stationId, clientId } of entries) {
      if (!idsByClient.has(clientId)) idsByClient.set(clientId, []);
      idsByClient.get(clientId).push(stationId);
    }
    for (const [clientId, ids] of idsByClient) {
      const { error: delError } = await withSupabaseRetry(
        () => dbQuery(`delete from reports where client_id = $1 and station_id = any($2::uuid[])`, [clientId, ids]),
        { label: `reports delete (${clientId})`, retries: COMMENTS_DB_WRITE_RETRIES }
      );
      if (delError) throw new Error(`reports delete: ${delError.message}`);
    }

    const allRows = entries.flatMap((e) => e.rows);
    for (let i = 0; i < allRows.length; i += BATCH) {
      const chunk = allRows.slice(i, i + BATCH);
      const { error: insError } = await withSupabaseRetry(() => bulkInsert("reports", chunk), {
        label: "reports insert",
        retries: COMMENTS_DB_WRITE_RETRIES,
      });
      if (insError) throw new Error(`reports insert: ${insError.message}`);
      state.totals.rowsInserted += chunk.length;
    }
    state.totals.stationsWritten += new Set(entries.map((e) => e.stationId)).size;

    // last_report_at — САМОЕ СВЕЖЕЕ время среди только что записанных строк
    // ТОЛЬКО от gdebenz/benzinest (а не "сейчас", как у
    // gdebenz_comments_synced_at ниже) — именно эта отметка используется в
    // fetchStaleWithReportPage как истинный сигнал "насколько устарели
    // ВИДИМЫЕ данные" (статус/очередь), независимо от того, когда мы
    // последний раз пытались станцию проверить. benzinstatus сюда сознательно
    // НЕ входит: это отдельный сигнал "цена обновилась", не имеющий
    // отношения к очереди устаревания статуса/очереди — иначе станция с
    // единственно свежей ценой ошибочно выглядела бы как "недавно видели
    // статус" и опускалась бы в конец очереди фазы 3.
    //
    // Один RPC-вызов на всю пачку (bulk_update_last_report_at, см.
    // migrate.mjs), а НЕ upsert и НЕ поштучные update: у каждой станции
    // своё значение. `INSERT ... ON CONFLICT DO UPDATE` в Postgres строит
    // полную кандидатную строку (со всеми NOT NULL колонками — lat/lng/
    // name/...) ДО проверки конфликта, даже если сработает путь UPDATE —
    // неполный payload {id, last_report_at} валится с "null value in
    // column lat". Обычный .update() эту проблему не имеет, но выставляет
    // ОДНО значение сразу для всех строк фильтра — для разных значений на
    // каждую строку нужен отдельный запрос НА СТРОКУ, что на тысячах
    // станций плодит лишнюю сетевую нагрузку (см. рост "fetch failed" в
    // логе после перехода на поштучные update). RPC делает то же самое
    // одним запросом на всю пачку.
    const lastReportByStation = new Map();
    for (const e of entries) {
      if (e.clientId !== "gdebenz" && e.clientId !== "benzinest") continue;
      let maxCreatedAt = lastReportByStation.has(e.stationId) ? lastReportByStation.get(e.stationId) : null;
      for (const row of e.rows) {
        if (row?.created_at && (!maxCreatedAt || row.created_at > maxCreatedAt)) maxCreatedAt = row.created_at;
      }
      lastReportByStation.set(e.stationId, maxCreatedAt);
    }
    const lastReportUpdates = [];
    for (const [stationId, maxCreatedAt] of lastReportByStation) {
      if (maxCreatedAt) lastReportUpdates.push({ id: stationId, last_report_at: maxCreatedAt });
    }
    if (lastReportUpdates.length > 0) {
      const { error: reportAtError } = await withSupabaseRetry(
        () => dbQuery(`select bulk_update_last_report_at($1::jsonb)`, [JSON.stringify(lastReportUpdates)]),
        // Не бесконечно: если функция не создана (миграция не применена),
        // это постоянная ошибка, а не сетевой блип — незачем ретраить вечно.
        { label: "stations mark last_report_at (rpc)", retries: 5 }
      );
      if (reportAtError) {
        const hint = /function|schema cache/i.test(reportAtError.message || "")
          ? " Похоже, не применена миграция — выполните: node migrate.mjs"
          : "";
        throw new Error(`stations mark last_report_at: ${reportAtError.message}.${hint}`);
      }
    }
  }

  // Помечаем свежими ВСЕ успешно опрошенные станции (в т.ч. с пустой лентой) —
  // так они не полезут под фильтр cooldown в ближайшие COMMENTS_COOLDOWN_MIN
  // минут. Провалившиеся НЕ помечаем — им нужно повторить попытку.
  if (processedIds.length > 0) {
    const nowIso = new Date().toISOString();
    for (let i = 0; i < processedIds.length; i += BATCH) {
      const chunk = processedIds.slice(i, i + BATCH);
      const { error: syncError } = await withSupabaseRetry(
        () => dbQuery(`update stations set gdebenz_comments_synced_at = $1 where id = any($2::uuid[])`, [nowIso, chunk]),
        { label: "stations mark synced", retries: COMMENTS_DB_WRITE_RETRIES }
      );
      if (syncError) throw new Error(`stations mark synced: ${syncError.message}`);
    }
  }

  // Отдельная отметка для benzinstatus (своя колонка, свой независимый цикл
  // проверки — см. коммент у BENZINSTATUS_* выше).
  if (benzinstatusProcessedIds.length > 0) {
    const nowIso = new Date().toISOString();
    for (let i = 0; i < benzinstatusProcessedIds.length; i += BATCH) {
      const chunk = benzinstatusProcessedIds.slice(i, i + BATCH);
      const { error: syncError } = await withSupabaseRetry(
        () => dbQuery(`update stations set benzinstatus_synced_at = $1 where id = any($2::uuid[])`, [nowIso, chunk]),
        { label: "stations mark benzinstatus synced", retries: COMMENTS_DB_WRITE_RETRIES }
      );
      if (syncError) throw new Error(`stations mark benzinstatus synced: ${syncError.message}`);
    }
  }
}

export async function runSync({ log = console.log } = {}) {
  // Проверяем доступность Python прокси-сервера перед стартом и заодно
  // используем его же ответ, чтобы подобрать конкурентность по факту живых
  // слотов XRay-пула (см. COMMENTS_CONCURRENCY_ENV выше).
  const proxyServerUrl = (process.env.PROXY_SERVER_URL || "http://127.0.0.1:8765").replace(/\/$/, "");
  let concurrency = COMMENTS_CONCURRENCY_ENV ?? DEFAULT_CONCURRENCY;
  // Если ни один прокси-конфиг не проходит bench по gdebenz (см.
  // proxy_server.py: /health.configs_healthy — тестируется отдельно от
  // configs_healthy_benzinest), значит источник целиком недоступен (домен
  // не резолвится, длительная блокировка и т.п.) — тогда нет смысла гонять
  // per-station попытки gdebenz, которые заведомо провалятся: сразу идём в
  // benzinest.ru как ОСНОВНОЙ источник этого прогона, а не как фоллбэк на
  // каждую отдельную неудачу (см. BENZINEST_FALLBACK_ENABLED ниже).
  let gdebenzDown = false;
  try {
    const healthRes = await fetch(`${proxyServerUrl}/health`, { signal: AbortSignal.timeout(3000) });
    if (!healthRes.ok) throw new Error(`HTTP ${healthRes.status}`);
    const health = await healthRes.json();
    if (typeof health !== "object" || health === null) throw new Error("неверный ответ /health");
    log(
      `Прокси-сервер: ${proxyServerUrl} | XRay-пул: ${health.pool_active ?? 0}/${health.pool_target ?? "?"} слотов | ` +
        `конфигов: ${health.xray_configs_total ?? 0}, рабочих по gdebenz: ${health.configs_healthy ?? "?"}, ` +
        `по benzinest: ${health.configs_healthy_benzinest ?? "?"}, карантин/нерабочие: ${health.configs_unhealthy ?? "?"}`
    );
    if (!COMMENTS_CONCURRENCY_ENV && Number(health.pool_active) > 0) {
      concurrency = Math.min(Number(health.pool_active), MAX_AUTO_CONCURRENCY);
    }
    if (Number(health.xray_configs_total ?? 0) > 0 && Number(health.configs_healthy ?? 0) === 0) {
      gdebenzDown = true;
      log(
        "⚠️  gdebenz.org: 0 рабочих прокси-конфигов — источник, похоже, недоступен целиком " +
          "(проверьте, резолвится ли домен: nslookup gdebenz.org). Пропускаю gdebenz для всех станций " +
          "этого прогона и иду сразу через benzinest.ru, без трат времени на заведомо неудачные попытки."
      );
    }
  } catch (e) {
    throw new Error(
      `Прокси-сервер недоступен (${proxyServerUrl}): ${e.message}\n` +
      `Запустите в отдельном терминале: python proxy_server.py`
    );
  }

  if (gdebenzDown) {
    // Поштучный фоллбэк идёт через benzinestRateGate — раньше это был ОДИН
    // общий гейт на 2с на весь скрипт (уважал Crawl-delay: 2 из robots.txt
    // benzinest.ru буквально, но на 21000+ станций давал ~12 часов). Теперь
    // это createShardedRateGate(BENZINEST_RATE_SHARDS, ...) — каждый из N
    // "виртуальных IP" по-прежнему не чаще раза в 2с, а суммарная скорость
    // растёт вместе с пулом (см. XRayFleet в proxy_server.py) — поэтому
    // полный переход на benzinest.ru/api/stations/<id>/reports для ВСЕХ
    // станций-кандидатов уже не часы, а разумное время, и можно просто
    // продолжить обычный постраничный обход ниже, пропуская gdebenz.
    log(`gdebenz недоступен целиком — иду по всем станциям сразу через benzinest.ru/reports (шардированный гейт: ${BENZINEST_RATE_SHARDS} параллельных 2с-очередей).`);
  }

  // XRay-конфиги тестируются один раз при старте proxy_server.py;
  // здесь не запускаем повторный /xray/bench, чтобы синк не блокировался
  // на долгом тесте сотен конфигов и не дублировал работу прокси.

  if (PROXY_POOL.length > 0) {
    log(`Прокси: ${PROXY_POOL.length} шт. в пуле`);
  }

  let bbox;
  const bboxEnv = process.env.SYNC_BBOX?.trim();
  if (bboxEnv) {
    const p = bboxEnv.split(",").map(Number);
    if (p.length !== 4 || p.some((n) => !Number.isFinite(n))) {
      throw new Error(`Неверный SYNC_BBOX (ожидается "south,west,north,east"): ${bboxEnv}`);
    }
    bbox = p;
    log(`Режим SYNC_BBOX: ${bboxEnv}`);
  }

  log(
    `Конкурентность: ${concurrency}, лимит отметок на станцию: ${COMMENTS_LIMIT}, ` +
      `доп. глобальный интервал: ${COMMENTS_MIN_INTERVAL_MS || "выкл"}${COMMENTS_MIN_INTERVAL_MS ? "мс" : ""}, cooldown: ${COMMENTS_COOLDOWN_MIN}мин, ` +
      `брейкер после ${COMMENTS_BREAKER_THRESHOLD} неудач подряд, benzinest-фоллбэк: ${BENZINEST_FALLBACK_ENABLED ? "вкл" : "выкл"}, ` +
      `benzinstatus-цены: ${BENZINSTATUS_ENABLED ? "вкл" : "выкл"}`
  );

  const cutoffIso = new Date(Date.now() - COMMENTS_COOLDOWN_MIN * 60 * 1000).toISOString();
  const state = {
    totals: {
      attempted: 0,
      ok: 0,
      empty: 0,
      failed: 0,
      retried: 0,
      stationsWritten: 0,
      rowsInserted: 0,
      benzinestFallback: 0,
      benzinstatusAttempted: 0,
      benzinstatusOk: 0,
      benzinstatusEmpty: 0,
      benzinstatusFailed: 0,
    },
    consecutiveFailures: 0,
    breakerTripped: false,
    breakerError: null,
  };

  const totalStations = await countCandidateStations({ bbox });
  log(`Станций-кандидатов в БД: ${totalStations}`);

  try {
    let pageNum = 0;
    let lastPhase = null;
    for await (const { page, phase } of iterateStationPages({ bbox, cutoffIso })) {
      pageNum++;
      if (phase !== lastPhase) {
        const phaseLabel = {
          "never-synced": "Фаза 1: станции без истории вообще",
          "stale-no-report": "Фаза 2: проверялись, но реального отчёта ещё нет",
          "stale-with-report": "Фаза 3: устаревшие по времени последнего отчёта",
        }[phase];
        log(phaseLabel);
        lastPhase = phase;
      }
      await processPage(page, state, concurrency, totalStations, gdebenzDown);
      log(
        `Страница ${pageNum}: обработано ${state.totals.attempted}/${totalStations} станций (ok ${state.totals.ok}, пусто ${state.totals.empty}, ошибок ${state.totals.failed}, повторов ${state.totals.retried}), записано отметок ${state.totals.rowsInserted}`
      );
      if (state.breakerTripped) {
        const blockLike = looksLikeBlock(state.breakerError);
        const causeMsg = state.breakerError?.cause?.message || "";
        // Извлекаем детали блокировки из сообщения об ошибке
        const blockDetail = [];
        if (/Cloudflare challenge/i.test(causeMsg)) blockDetail.push("Cloudflare challenge");
        if (/DDoS-Guard/i.test(causeMsg)) blockDetail.push("DDoS-Guard");
        if (/JS challenge/i.test(causeMsg)) blockDetail.push("JS challenge");
        if (/captcha/i.test(causeMsg)) blockDetail.push("captcha");
        if (/title="/i.test(causeMsg)) {
          const t = causeMsg.match(/title="([^"]*)"/);
          if (t) blockDetail.push(`page="${t[1]}"`);
        }
        const detail = blockDetail.length ? ` (${blockDetail.join(", ")})` : "";
        log(
          `Прервано: ${COMMENTS_BREAKER_THRESHOLD} неудачных запросов подряд` +
            (blockLike
              ? ` — блокировка по IP${detail}. Остановка, чтобы не усугублять.`
              : ` — последняя ошибка: ${state.breakerError?.message}${detail}. Остановка вместо долбления сервера дальше.`)
        );
        break;
      }
    }

    log(
      `Готово. Станций обработано: ${state.totals.attempted}, со свежей историей: ${state.totals.stationsWritten}, отметок записано: ${state.totals.rowsInserted}, ` +
        `через benzinest-фоллбэк: ${state.totals.benzinestFallback}, ошибок запроса: ${state.totals.failed}. ` +
        `benzinstatus (цены): ${state.totals.benzinstatusAttempted} проверено, ${state.totals.benzinstatusOk} с ценой, ${state.totals.benzinstatusFailed} ошибок.`
    );
    return { ...state.totals, breakerTripped: state.breakerTripped };
  } finally {
    await closeTransports();
  }
}

if (runAsScript) {
  runSync()
    .catch((e) => {
      console.error("Ошибка синка отметок:", e.message);
      process.exitCode = 1;
    })
    .finally(() => closeDb());
}
