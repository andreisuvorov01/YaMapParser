// Общий разбор данных gdebenz.org: маппинг статуса и топлива (тайловый API И
// лента отметок используют одинаковый словарь статусов), плюс парсинг
// текстового поля `detail` и таймстампов ленты отметок.

/**
 * gdebenz's raw `status` field is not just "yes"/"low"/"no" — it also uses
 * "queue" ("есть топливо, но очередь"), folding queue info into the status
 * enum instead of a separate field. Our schema keeps them separate
 * (FuelStatus + QueueLevel), so "queue" maps to status "yes" with a queue
 * level. Roughly 20-30% of stations with a live gdebenz status carry this
 * value — treating it as "unknown" (as a plain allowlist check would) drops
 * that fraction of stations entirely instead of just losing queue precision.
 */
export function mapGdebenzStatus(rawStatus) {
  switch (rawStatus) {
    case "yes":
      return { status: "yes", queue: "none" };
    case "low":
      return { status: "low", queue: "none" };
    case "no":
      return { status: "no", queue: "none" };
    case "queue":
      return { status: "yes", queue: "small" };
    default:
      return null;
  }
}

export function parseFuels(s) {
  if (!s) return [];
  const set = new Set();
  const t = String(s).toLowerCase().trim();
  if (/92/.test(t)) set.add("АИ-92");
  if (/95/.test(t)) set.add("АИ-95");
  if (/98/.test(t)) set.add("АИ-98");
  if (/100/.test(t)) set.add("АИ-100");
  if (/дт|diesel|дизель/.test(t)) set.add("ДТ");
  if (/газ|gas|пропан|метан|lpg|cng/.test(t)) set.add("Газ");
  return [...set];
}

/**
 * Разбирает поле `detail` из `/api/comments/<id>/recent` — оно устроено
 * иначе, чем `fuels_now` тайлового API: список топлива (если есть) плюс
 * произвольные аннотации через " · ", например:
 *   "92, 95, 98, 100, ДТ · Большая очередь"
 *   "92, 95 · Очередь ≈5–20 машин"
 *   "92, 95 · Лимит 20 л"
 *   "" (при status "no")
 * Первый сегмент — всегда список топлива (или пусто), остальные —
 * независимые аннотации: очередь ИЛИ лимит на руки, в любом сочетании.
 */
export function parseGdebenzDetail(detail) {
  const parts = String(detail || "")
    .split("·")
    .map((p) => p.trim())
    .filter(Boolean);
  const fuel_types = parts.length > 0 ? parseFuels(parts[0]) : [];

  let queue = null;
  let limit_liters = null;
  for (const part of parts.slice(1)) {
    const limitMatch = part.match(/лимит\s*(\d+)\s*л/i);
    if (limitMatch) {
      limit_liters = Number(limitMatch[1]);
      continue;
    }
    if (/час/i.test(part)) {
      queue = "hours";
      continue;
    }
    // "небольшая" содержит подстроку "больш", поэтому маленькую очередь
    // проверяем ПЕРВОЙ, иначе "Небольшая очередь" ложно матчится как "big".
    if (/неб\S*\s*очеред/i.test(part)) {
      queue = "small";
      continue;
    }
    if (/больш/i.test(part) && /очеред/i.test(part)) {
      queue = "big";
      continue;
    }
    if (/очеред\S*\s*нет/i.test(part)) {
      queue = queue ?? "none";
      continue;
    }
    const carCountMatch = part.match(/(\d+)\s*[–—-]\s*(\d+)\s*машин/i) || part.match(/(\d+)\+?\s*машин/i);
    if (carCountMatch) {
      const hi = Number(carCountMatch[2] ?? carCountMatch[1]);
      if (Number.isFinite(hi)) queue = hi > 10 ? "big" : "small";
    }
  }
  return { fuel_types, queue, limit_liters };
}

/** "2026-07-02 09:58:53" (наивный UTC-таймстамп gdebenz) → ISO-строка. */
export function parseGdebenzTimestamp(s) {
  if (!s) return null;
  const iso = `${String(s).trim().replace(" ", "T")}Z`;
  const d = new Date(iso);
  return Number.isFinite(d.getTime()) ? d.toISOString() : null;
}

/** Одна запись из `/api/comments/<id>/recent` → строка таблицы `reports` (или null). */
export function toCommentReportRow(stationId, entry) {
  const mapped = mapGdebenzStatus(entry?.status);
  if (!mapped) return null;
  const createdAt = parseGdebenzTimestamp(entry?.created_at);
  if (!createdAt) return null;
  const { fuel_types, queue, limit_liters } = parseGdebenzDetail(entry?.detail);
  return {
    station_id: stationId,
    status: mapped.status,
    fuel_types,
    queue: queue ?? mapped.queue,
    limit_liters,
    confirms: 0,
    client_id: "gdebenz",
    created_at: createdAt,
  };
}
