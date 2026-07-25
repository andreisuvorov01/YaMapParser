// HTTP-транспорт для pars/ — все запросы к gdebenz.org проксируются через
// Python proxy_server.py (TLSFingerprinter / curl_cffi), слушающий на
// PROXY_SERVER_URL (по умолчанию http://127.0.0.1:8765).
// Публичный API полностью совместим с оригинальным gdebenz-http.mjs.

export const THROTTLE_MS = Number(process.env.THROTTLE_MS) || 300;
export const BROWSER_RESPONSE_DELAY_MS =
  Number(process.env.BROWSER_RESPONSE_DELAY_MS) || Math.max(80, Math.round(THROTTLE_MS / 3));
export const HTTP_RESPONSE_DELAY_MS =
  Number(process.env.HTTP_RESPONSE_DELAY_MS) || Math.max(25, Math.round(BROWSER_RESPONSE_DELAY_MS / 2));
export const RETRY_DELAY_MS =
  Number(process.env.RETRY_DELAY_MS) || Math.max(50, Math.round(HTTP_RESPONSE_DELAY_MS));
export const REQUEST_TIMEOUT_MS = Number(process.env.REQUEST_TIMEOUT_MS) || 60000;
export const MAX_RETRIES = Number(process.env.MAX_RETRIES) || 3;
export const FETCH_TRANSPORTS = ["python-proxy"];

// Прокси-пул (для совместимости с оригинальным API — не используется здесь,
// прокси настраивается на стороне Python через PROXY_URL / PROXY_LIST).
export const PROXY_POOL = [];
let _proxyIndex = 0;
export function nextProxy() {
  if (PROXY_POOL.length === 0) return undefined;
  return PROXY_POOL[_proxyIndex++ % PROXY_POOL.length];
}

const PROXY_SERVER_URL = (process.env.PROXY_SERVER_URL || "http://127.0.0.1:8765").replace(/\/$/, "");
const BROWSER_ON_BLOCK = /^(1|true|yes)$/i.test(process.env.GDEBENZ_BROWSER_ON_BLOCK || "");

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export function describeFetchError(error) {
  return JSON.stringify({
    name: error?.name,
    message: error?.message,
  });
}

/** Проверяет доступность Python прокси и возвращает текущий статус XRay. */
export async function checkProxyHealth() {
  try {
    const res = await fetch(`${PROXY_SERVER_URL}/health`, {
      signal: AbortSignal.timeout(3000),
    });
    if (!res.ok) return null;
    const json = await res.json();
    if (typeof json !== "object" || json === null) return null;
    return json;
  } catch {
    return null;
  }
}

/** Принудительная ротация XRay конфига через Python прокси. */
export async function rotateXray() {
  try {
    const res = await fetch(`${PROXY_SERVER_URL}/xray/rotate`, {
      method: "POST",
      signal: AbortSignal.timeout(5000),
    });
    if (!res.ok) return false;
    const json = await res.json();
    return json?.ok === true;
  } catch {
    return false;
  }
}

/** Шлёт запрос через Python proxy_server.py POST /fetch → { status, headers, body(base64) } */
async function requestViaProxy(url, signal) {
  // Валидация URL — не передаём произвольные строки на прокси
  try { new URL(url); } catch { throw new Error(`Неверный URL: ${url}`); }

  async function postProxy(endpoint) {
    try {
      return await fetch(`${PROXY_SERVER_URL}${endpoint}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url, timeout: Math.floor(REQUEST_TIMEOUT_MS / 1000) }),
        signal,
      });
    } catch (e) {
      throw new Error(`Прокси-сервер недоступен (${PROXY_SERVER_URL}): ${e.message}`);
    }
  }

  let proxyRes = await postProxy("/fetch");
  let rawText = await proxyRes.text();
  if (BROWSER_ON_BLOCK && (proxyRes.status === 403 || proxyRes.status === 429)) {
    proxyRes = await postProxy("/fetch-browser");
    rawText = await proxyRes.text();
  }
  let json;
  try {
    json = JSON.parse(rawText);
  } catch {
    throw new Error(`Прокси-сервер HTTP ${proxyRes.status}: неожиданный ответ (${rawText.slice(0, 120)})`);
  }

  if (!proxyRes.ok) {
    throw new Error(`Прокси ошибка HTTP ${proxyRes.status}: ${json?.detail ?? rawText.slice(0, 120)}`);
  }

  // Валидация структуры ответа прокси
  if (typeof json.status !== "number" || typeof json.body !== "string") {
    throw new Error(`Прокси вернул неожиданную структуру ответа`);
  }

  const bodyText = Buffer.from(json.body, "base64").toString("utf8");

  let data = null;
  if (bodyText) {
    try {
      const parsed = JSON.parse(bodyText);
      // Принимаем только объекты и массивы — не примитивы
      if (parsed !== null && typeof parsed === "object") {
        data = parsed;
      }
    } catch {
      if (json.status === 403) throw new Error(`HTTP 403`);
      if (json.status === 429) throw new Error(`HTTP 429`);

      // Детальный разбор не-JSON ответа: извлекаем title, meta, script src,
      // статус и ключевые признаки блокировки/редиректа
      const snippet = bodyText.slice(0, 2000);
      const titleMatch = snippet.match(/<title>([^<]*)<\/title>/i);
      const metaRefresh = snippet.match(/<meta[^>]*http-equiv=["']refresh["'][^>]*content=["']([^"']*)["']/i);
      const cfChallenge = snippet.includes("cf-browser-verification") || snippet.includes("__cf_challenge");
      const ddosGuard = snippet.includes("DDoS-Guard") || snippet.includes("ddos-guard");
      const jsChallenge = snippet.includes("/cdn-cgi/challenge") || snippet.includes("challenge-platform");
      const captcha = snippet.includes("recaptcha") || snippet.includes("hcaptcha") || snippet.includes("turnstile");

      const details = [
        titleMatch ? `title="${titleMatch[1]}"` : null,
        metaRefresh ? `meta-refresh="${metaRefresh[1]}"` : null,
        cfChallenge ? "Cloudflare challenge" : null,
        ddosGuard ? "DDoS-Guard" : null,
        jsChallenge ? "JS challenge" : null,
        captcha ? "captcha" : null,
        snippet.length < 2000 ? null : `body=${bodyText.length}B`,
      ].filter(Boolean).join(", ");

      throw new Error(
        `gdebenz вернул не-JSON после HTTP ${json.status}: ${details || snippet.slice(0, 200)}`
      );
    }
  }

  return {
    ok: json.status >= 200 && json.status < 300,
    status: json.status,
    headers: {
      get(name) {
        const v = json.headers?.[name.toLowerCase()];
        return typeof v === "string" ? v : null;
      },
    },
    data,
    transport: "python-proxy",
  };
}

/** Запрашивает JSON по url через Python-прокси. */
export async function requestJson(url, signal) {
  return requestViaProxy(url, signal);
}

export async function waitAfterResponse(res, fallbackMs) {
  const retryAfter = res.headers.get("Retry-After");
  const retrySec = Number(retryAfter);
  if (Number.isFinite(retrySec) && retrySec > 0) {
    await sleep(retrySec * 1000);
    return;
  }
  const jitter = Math.round(Math.random() * Math.max(10, fallbackMs / 4));
  await sleep(fallbackMs + jitter);
}

export function responseDelayForTransport(_transport) {
  return HTTP_RESPONSE_DELAY_MS;
}

export async function requestWithRetries(url, onResponse) {
  let lastErr;
  for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    try {
      const res = await requestJson(url, controller.signal);
      await waitAfterResponse(res, responseDelayForTransport(res.transport));
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      return await onResponse(res);
    } catch (e) {
      lastErr = e;
      // Раньше здесь форсировался /xray/rotate на 403/429 — при одном
      // активном прокси на весь сервер это было единственным способом
      // сменить IP между попытками. Теперь proxy_server.py раздаёт запросы
      // по пулу из XRAY_POOL_SIZE параллельных прокси (см. XRayFleet), и
      // КАЖДАЯ попытка (включая повторные) уже сама по себе идёт через
      // случайно выбранный свободный слот пула — ручная ротация здесь
      // больше ничего не меняет и только добавляет лишний round-trip.
      // Сервер сам карантинит конкретный проблемный слот при 403/429/5xx.
      if (attempt < MAX_RETRIES) await sleep(RETRY_DELAY_MS * attempt);
    } finally {
      clearTimeout(timer);
    }
  }
  // Сохраняем полную цепочку ошибок: последняя причина + все предыдущие
  const error = new Error(`Все попытки исчерпаны (${MAX_RETRIES}): ${describeFetchError(lastErr)}`);
  error.cause = lastErr;
  throw error;
}

export function createRateGate(minIntervalMs) {
  let nextAt = 0;
  return async function gate() {
    if (minIntervalMs <= 0) return;
    const now = Date.now();
    const waitMs = Math.max(0, nextAt - now);
    nextAt = Math.max(now, nextAt) + minIntervalMs;
    if (waitMs > 0) await sleep(waitMs);
  };
}

/**
 * N независимых createRateGate-очередей, выбираемых round-robin —
 * приближение к "не больше одного запроса в minIntervalMs НА КАЖДЫЙ из N
 * прокси", а не на весь скрипт разом. Нужен, когда сайт просит вежливый
 * Crawl-delay, но запросы реально идут через пул из N разных исходящих IP
 * (см. XRayFleet в proxy_server.py): единый глобальный гейт душит суммарную
 * скорость в N раз, хотя каждый отдельный IP и так соблюдает интервал —
 * сайт видит N разных клиентов, каждый по отдельности вежливый.
 */
export function createShardedRateGate(shardCount, minIntervalMs) {
  const gates = Array.from({ length: Math.max(1, shardCount) }, () => createRateGate(minIntervalMs));
  let next = 0;
  return async function gate() {
    const g = gates[next % gates.length];
    next++;
    await g();
  };
}

export function looksLikeBlock(error) {
  return /HTTP 403/.test(String(error?.message || ""));
}

/** Заглушка — браузерных транспортов нет, закрывать нечего. */
export async function closeTransports() {}
