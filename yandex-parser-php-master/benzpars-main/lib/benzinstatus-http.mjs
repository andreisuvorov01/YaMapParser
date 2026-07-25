// HTTP-транспорт для map.benzin-status.tech. В отличие от gdebenz.org/
// benzinest.ru, сайт не защищён (ни DDoS-Guard, ни Cloudflare challenge, ни
// TLS-фингерпринтингом) — обычный browser-like User-Agent через прямой
// fetch() отрабатывает без прокси, поэтому здесь нет зависимости от
// proxy_server.py/XRay-пула (см. lib/gdebenz-http.mjs) — простой прямой fetch
// с ретраями.

import { sleep } from "./gdebenz-http.mjs";

export const USER_AGENT =
  process.env.BENZINSTATUS_USER_AGENT ||
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
export const REQUEST_TIMEOUT_MS = Number(process.env.BENZINSTATUS_TIMEOUT_MS) || 20000;
export const MAX_RETRIES = Math.max(1, Number(process.env.BENZINSTATUS_MAX_RETRIES) || 3);
export const RETRY_DELAY_MS = Math.max(100, Number(process.env.BENZINSTATUS_RETRY_DELAY_MS) || 500);
// Отдельный, более долгий бэкофф именно для 429 — обычный RETRY_DELAY_MS
// (пара сотен мс) только подливает запросы в тот же самый rate-limit-шторм,
// вместо того чтобы его переждать.
export const RATE_LIMIT_RETRY_DELAY_MS = Math.max(500, Number(process.env.BENZINSTATUS_RATE_LIMIT_RETRY_DELAY_MS) || 5000);

export async function fetchJson(url) {
  let lastErr;
  for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
    try {
      const res = await fetch(url, {
        headers: { "User-Agent": USER_AGENT, Accept: "application/json" },
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      });
      if (res.status === 429) {
        const retryAfterSec = Number(res.headers.get("Retry-After"));
        const waitMs = Number.isFinite(retryAfterSec) && retryAfterSec > 0 ? retryAfterSec * 1000 : RATE_LIMIT_RETRY_DELAY_MS * attempt;
        await res.text().catch(() => {}); // слить тело, не оставлять соединение висеть
        lastErr = new Error(`HTTP 429: rate_limited`);
        if (attempt < MAX_RETRIES) await sleep(waitMs);
        continue;
      }
      const text = await res.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch {
        throw new Error(`не-JSON ответ (HTTP ${res.status}): ${text.slice(0, 200)}`);
      }
      if (!res.ok) {
        const detail = data?.error ? `: ${data.error}` : "";
        throw new Error(`HTTP ${res.status}${detail}`);
      }
      return data;
    } catch (e) {
      lastErr = e;
      if (attempt < MAX_RETRIES) await sleep(RETRY_DELAY_MS * attempt);
    }
  }
  throw lastErr;
}
