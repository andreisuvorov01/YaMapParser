import { NextResponse } from "next/server";

// UUID v4 (и совместимые варианты RFC 4122).
const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export function isUuid(value: string): boolean {
  return UUID_RE.test(value);
}

/** id заправки: UUID (Supabase) или osm-… / демо-slug. */
export function isStationId(value: string): boolean {
  if (isUuid(value)) return true;
  return /^[a-z0-9][a-z0-9_-]{0,63}$/i.test(value);
}

/** id отчёта: UUID (Supabase) или r123 в демо-режиме. */
export function isReportId(value: string): boolean {
  if (isUuid(value)) return true;
  return /^r\d+$/i.test(value);
}

/**
 * Доверять x-forwarded-for / x-real-ip (только за настроенным reverse proxy).
 * ВАЖНО: сам по себе clientIdFromRequest ниже НЕ годится как ключ для
 * rate-limit/анти-спам счётчиков — см. rateLimitKey() и её применение
 * в app/api/reports/route.ts.
 */
function trustProxy(): boolean {
  const v = process.env.TRUST_PROXY?.trim().toLowerCase();
  return v === "1" || v === "true" || v === "yes";
}

function isPlausibleIp(ip: string): boolean {
  if (ip.length > 45) return false;
  // IPv4
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(ip)) {
    return ip.split(".").every((oct) => {
      const n = Number(oct);
      return Number.isInteger(n) && n >= 0 && n <= 255;
    });
  }
  // Упрощённая проверка IPv6
  return /^[0-9a-f:.]+$/i.test(ip);
}

/**
 * Ключ клиента для rate-limit и client_id в отчётах.
 * IP из заголовков — только при TRUST_PROXY=true (nginx strip на VPS).
 */
export function clientIdFromRequest(request: Request): string {
  if (trustProxy()) {
    const ip =
      request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
      request.headers.get("x-real-ip")?.trim();
    if (ip && isPlausibleIp(ip)) return `ip:${ip}`;
  }
  const header = request.headers.get("x-client-id")?.trim();
  if (header && header.length <= 64 && /^[\w-]+$/.test(header)) {
    return `cid:${header}`;
  }
  return "ip:unknown";
}

/**
 * Ключ для rate-limit/row-budget — НЕ то же самое, что clientIdFromRequest.
 * Там IP (если доверяем прокси) имеет приоритет и полностью перекрывает
 * x-client-id. Для anti-abuse ключа это опасно: если перед Node есть ещё один
 * прокси/балансировщик (например, у хостинга), который переписывает
 * X-Forwarded-For своим адресом, IP становится одинаковым для всех
 * посетителей — и все схлопываются в один общий бакет и блокируются вместе.
 * Здесь вместо выбора одного сигнала комбинируем оба, какие есть: даже если
 * IP-часть сломана и одинакова для всех, x-client-id всё равно разделяет
 * клиентов.
 */
export function rateLimitKey(request: Request): string {
  const parts: string[] = [];
  if (trustProxy()) {
    const ip =
      request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
      request.headers.get("x-real-ip")?.trim();
    if (ip && isPlausibleIp(ip)) parts.push(`ip:${ip}`);
  }
  const header = request.headers.get("x-client-id")?.trim();
  if (header && header.length <= 64 && /^[\w-]+$/.test(header)) {
    parts.push(`cid:${header}`);
  }
  return parts.length > 0 ? parts.join("+") : "anon:unknown";
}

// --- In-memory rate limit (на один инстанс Node; для кластера — Redis) ---

type Bucket = { count: number; resetAt: number; blockedUntil?: number };
const buckets = new Map<string, Bucket>();
const MAX_BUCKETS = 25_000;

function pruneBuckets(now: number) {
  if (buckets.size < MAX_BUCKETS) return;
  for (const [k, b] of buckets) {
    if (now >= b.resetAt) buckets.delete(k);
    if (buckets.size < MAX_BUCKETS * 0.8) break;
  }
}

export type RateLimitResult =
  | { allowed: true }
  | { allowed: false; retryAfterSec: number };

/** Скользящее окно: limit запросов за windowMs на ключ. */
export function checkRateLimit(
  key: string,
  limit: number,
  windowMs: number
): RateLimitResult {
  const now = Date.now();
  pruneBuckets(now);

  let bucket = buckets.get(key);
  if (!bucket || now >= bucket.resetAt) {
    bucket = { count: 0, resetAt: now + windowMs };
    buckets.set(key, bucket);
  }

  if (bucket.count >= limit) {
    return {
      allowed: false,
      retryAfterSec: Math.max(1, Math.ceil((bucket.resetAt - now) / 1000)),
    };
  }

  bucket.count += 1;
  return { allowed: true };
}

/**
 * Лимит по часовому окну с коротким наказанием: если клиент выбрал лимит за
 * час, он получает фиксированный блок на penaltyMs (а не ждёт до конца часа),
 * и после его истечения счётчик обнуляется — обычный пользователь, который
 * ненадолго переборщил с панорамированием, снова работает как обычно через
 * несколько минут, а не «до следующего часа».
 */
export function checkHourlyLimit(
  key: string,
  limit: number,
  windowMs: number,
  penaltyMs: number
): RateLimitResult {
  const now = Date.now();
  pruneBuckets(now);

  let bucket = buckets.get(key);

  if (bucket?.blockedUntil && now < bucket.blockedUntil) {
    return {
      allowed: false,
      retryAfterSec: Math.max(1, Math.ceil((bucket.blockedUntil - now) / 1000)),
    };
  }

  if (!bucket || now >= bucket.resetAt || (bucket.blockedUntil && now >= bucket.blockedUntil)) {
    bucket = { count: 0, resetAt: now + windowMs };
    buckets.set(key, bucket);
  }

  if (bucket.count >= limit) {
    bucket.blockedUntil = now + penaltyMs;
    return { allowed: false, retryAfterSec: Math.max(1, Math.ceil(penaltyMs / 1000)) };
  }

  bucket.count += 1;
  return { allowed: true };
}

/** Проверка часового лимита; при превышении — готовый 429-ответ с предупреждением. */
export function enforceHourlyLimit(
  request: Request,
  scope: string,
  limit: number,
  windowMs: number,
  penaltyMs: number
): NextResponse | null {
  const key = `${scope}:${rateLimitKey(request)}`;
  const result = checkHourlyLimit(key, limit, windowMs, penaltyMs);
  if (!result.allowed) {
    return rateLimitExceededResponse(result.retryAfterSec);
  }
  return null;
}

export function rateLimitExceededResponse(retryAfterSec: number): NextResponse {
  return NextResponse.json(
    { error: "Слишком много запросов. Попробуйте позже." },
    {
      status: 429,
      headers: {
        "Retry-After": String(retryAfterSec),
        // Ответ персонален для конкретного клиента — если это не запретить явно,
        // кэширующий прокси перед Node может отдать этот 429 всем остальным
        // посетителям с тем же URL (например, стартовый bbox одинаков почти
        // у всех новых сессий).
        "Cache-Control": "private, no-store",
      },
    }
  );
}

/** Проверка лимита; при превышении — готовый 429-ответ, иначе null. */
export function enforceRateLimit(
  request: Request,
  scope: string,
  limit: number,
  windowMs: number
): NextResponse | null {
  const key = `${scope}:${rateLimitKey(request)}`;
  const result = checkRateLimit(key, limit, windowMs);
  if (!result.allowed) {
    return rateLimitExceededResponse(result.retryAfterSec);
  }
  return null;
}

// --- Бюджет строк на клиента (защита от массовой выгрузки через нарезку bbox) ---
//
// checkRateLimit ограничивает число запросов, но не объём данных в них — при
// широком (но допустимом) bbox один запрос может вернуть тысячи станций, и
// нарезка территории на много запросов внутри лимита всё равно позволяет
// выкачать всю базу. rowBudget ограничивает суммарное число возвращённых строк
// за окно, независимо от того, как атакующий делит область на запросы.

function getOrCreateBucket(key: string, windowMs: number, now: number): Bucket {
  let bucket = buckets.get(key);
  if (!bucket || now >= bucket.resetAt) {
    bucket = { count: 0, resetAt: now + windowMs };
    buckets.set(key, bucket);
  }
  return bucket;
}

/**
 * Проверка бюджета строк (до запроса к БД); при превышении — готовый 429-ответ.
 * Как и checkHourlyLimit, блокирует на фиксированные penaltyMs, а не до конца
 * часового окна — чтобы не наказывать случайно зацепивший лимит браузер на
 * полчаса-час.
 */
export function checkRowBudget(
  request: Request,
  scope: string,
  budget: number,
  windowMs: number,
  penaltyMs: number
): NextResponse | null {
  const now = Date.now();
  pruneBuckets(now);
  const key = `${scope}:${rateLimitKey(request)}`;
  let bucket = buckets.get(key);

  if (bucket?.blockedUntil && now < bucket.blockedUntil) {
    return rateLimitExceededResponse(Math.max(1, Math.ceil((bucket.blockedUntil - now) / 1000)));
  }

  if (!bucket || now >= bucket.resetAt || (bucket.blockedUntil && now >= bucket.blockedUntil)) {
    bucket = { count: 0, resetAt: now + windowMs };
    buckets.set(key, bucket);
  }

  if (bucket.count >= budget) {
    bucket.blockedUntil = now + penaltyMs;
    return rateLimitExceededResponse(Math.max(1, Math.ceil(penaltyMs / 1000)));
  }
  return null;
}

/** Списывает фактически возвращённые строки с бюджета (после запроса к БД). */
export function consumeRowBudget(
  request: Request,
  scope: string,
  rows: number,
  windowMs: number
): void {
  const now = Date.now();
  const bucket = getOrCreateBucket(`${scope}:${rateLimitKey(request)}`, windowMs, now);
  bucket.count += rows;
}

// --- Origin/Referer: лёгкий фильтр от наивных скриптов и кросс-сайтовых запросов ---
//
// Не защита от целенаправленного парсинга (заголовки легко подделать), но
// бесплатно отсекает curl/скрипты без браузерного контекста и встраивание
// с чужих сайтов. Пропускаем, если сигналов нет вовсе (старые браузеры,
// прокси, вырезающие заголовки) — чтобы не ломать реальных пользователей.

function siteOrigin(): string | null {
  const url = process.env.NEXT_PUBLIC_SITE_URL;
  if (!url) return null;
  try {
    return new URL(url).origin;
  } catch {
    return null;
  }
}

export function isAllowedOrigin(request: Request): boolean {
  const secFetchSite = request.headers.get("sec-fetch-site");
  if (secFetchSite === "cross-site") return false;

  const origin = siteOrigin();
  if (!origin) return true;

  const originHeader = request.headers.get("origin");
  if (originHeader) return originHeader === origin;

  const referer = request.headers.get("referer");
  if (referer) {
    try {
      return new URL(referer).origin === origin;
    } catch {
      return false;
    }
  }

  return true;
}

export function forbiddenOriginResponse(): NextResponse {
  return NextResponse.json(
    { error: "Forbidden" },
    { status: 403, headers: { "Cache-Control": "private, no-store" } }
  );
}
