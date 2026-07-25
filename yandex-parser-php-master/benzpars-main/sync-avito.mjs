// Парсинг отзывов пользователя авито.
// Получает HTML страницы через Python proxy_server.py (TLS fingerprint),
// парсит отзывы и сохраняет в avito-reviews-<userId>.json
//
// Запуск:
//   node sync-avito.mjs https://www.avito.ru/user/<id>/reviews
//   node sync-avito.mjs https://www.avito.ru/user/<id>/reviews --out=result.json

import { writeFile } from "node:fs/promises";
import { loadEnv } from "./load-env.mjs";
import { parseAvitoReviews } from "./lib/avito-parse.mjs";
import { sleep } from "./lib/gdebenz-http.mjs";

loadEnv();

const PROXY_SERVER_URL = (process.env.PROXY_SERVER_URL || "http://127.0.0.1:8765").replace(/\/$/, "");
const REQUEST_TIMEOUT_MS = Number(process.env.REQUEST_TIMEOUT_MS) || 60000;
const MAX_RETRIES = Number(process.env.MAX_RETRIES) || 3;
const RETRY_DELAY_MS = Number(process.env.RETRY_DELAY_MS) || 2000;

/**
 * Загружает HTML страницы через /fetch-browser (Playwright stealth).
 * Авито — SPA: отзывы рендерятся JS, нужен полноценный браузер.
 * proxy_server.py ждёт networkidle перед возвратом HTML.
 */
async function fetchHtml(url) {
  let lastErr;
  for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    try {
      const res = await fetch(`${PROXY_SERVER_URL}/fetch-browser`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          url,
          timeout: Math.floor(REQUEST_TIMEOUT_MS / 1000),
          // Ждём появления блока отзывов или карточки товара
          wait_for_selector: '[data-marker="review(0)/body"],[data-marker="item-view/item-id"]',
        }),
        signal: controller.signal,
      });

      if (!res.ok) throw new Error(`Proxy server HTTP ${res.status}`);

      const rawText = await res.text();
      let json;
      try {
        json = JSON.parse(rawText);
      } catch {
        throw new Error(`Прокси вернул не-JSON: ${rawText.slice(0, 120)}`);
      }
      if (typeof json !== "object" || json === null || typeof json.status !== "number" || typeof json.body !== "string") {
        throw new Error(`Прокси вернул неожиданную структуру ответа`);
      }
      if (json.status === 403) throw new Error(`HTTP 403 — авито заблокировало запрос`);
      if (json.status !== 200) throw new Error(`HTTP ${json.status}`);

      return Buffer.from(json.body, "base64").toString("utf8");
    } catch (e) {
      lastErr = e;
      console.warn(`  Попытка ${attempt}/${MAX_RETRIES}: ${e.message}`);
      if (attempt < MAX_RETRIES) await sleep(RETRY_DELAY_MS * attempt);
    } finally {
      clearTimeout(timer);
    }
  }
  throw new Error(`Не удалось загрузить страницу: ${lastErr?.message}`);
}

function outFilename(url, override) {
  if (override) return override;
  const m = url.match(/avito\.ru\/user\/([^/]+)/);
  const userId = m ? m[1] : "unknown";
  return `avito-reviews-${userId}.json`;
}

async function main() {
  const args = process.argv.slice(2);
  const url = args.find((a) => a.startsWith("http"));
  const outArg = args.find((a) => a.startsWith("--out="));
  const outFile = outFilename(url || "", outArg ? outArg.slice(6) : null);

  if (!url) {
    console.error("Укажите URL профиля: node sync-avito.mjs https://www.avito.ru/user/<id>/reviews");
    process.exit(1);
  }

  console.log(`Загружаем: ${url}`);
  console.log(`Прокси: ${PROXY_SERVER_URL}`);

  const html = await fetchHtml(url);
  console.log(`HTML получен (${(html.length / 1024).toFixed(1)} КБ), парсим...`);

  const result = await parseAvitoReviews(html);

  console.log(`Рейтинг: ${result.summary.rating} (${result.summary.total} отзывов)`);
  console.log(`Отзывов распарсено: ${result.reviews.length}`);

  const output = {
    url,
    scraped_at: new Date().toISOString(),
    summary: result.summary,
    reviews: result.reviews,
  };

  await writeFile(outFile, JSON.stringify(output, null, 2), "utf8");
  console.log(`Сохранено: ${outFile}`);
}

main().catch((e) => {
  console.error("Ошибка:", e.message);
  process.exit(1);
});
