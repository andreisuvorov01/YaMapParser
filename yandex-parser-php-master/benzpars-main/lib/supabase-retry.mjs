// Ретраи для запросов к БД в формате `{ data, error }` (изначально —
// supabase-js, сейчас так же оборачивает и lib/db.mjs при прямых pg-запросах).
// И настоящие ошибки Postgres, и сетевые/соединительные сбои (обрыв во время
// долгого прогона) приходят в одной и той же форме, так что различать их
// незачем — при транзиентном сбое повтор чаще всего просто проходит.

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const DEFAULT_RETRIES = Math.max(1, Number(process.env.SUPABASE_RETRIES) || 10);
const DEFAULT_DELAY_MS = Math.max(50, Number(process.env.SUPABASE_RETRY_DELAY_MS) || 1000);
const DEFAULT_MAX_DELAY_MS = Math.max(DEFAULT_DELAY_MS, Number(process.env.SUPABASE_RETRY_MAX_DELAY_MS) || 30000);

function retryDelayMs(attempt, baseDelayMs, maxDelayMs) {
  const linear = baseDelayMs * attempt;
  const jitter = Math.round(Math.random() * Math.max(50, baseDelayMs / 2));
  return Math.min(maxDelayMs, linear + jitter);
}

function formatRetries(retries) {
  return retries === 0 ? "∞" : String(retries);
}

/**
 * Оборачивает `fn` (Supabase-запрос вида `() => sb.from(...).select(...)`,
 * возвращающий `{ data, error }`) ретраями с бэкоффом и джиттером. Возвращает
 * результат первого успешного вызова (`error` falsy); если все попытки
 * вернули ошибку — возвращает последний результат как есть (вызывающий код
 * сам решает, что делать с `error`, как и раньше).
 *
 * `retries: 0` означает бесконечные повторы — используйте только для
 * критичных write-операций, где лучше ждать восстановления Supabase/сети,
 * чем уронить долгий парсинг после нескольких тысяч успешных запросов.
 */
export async function withSupabaseRetry(
  fn,
  {
    retries = DEFAULT_RETRIES,
    delayMs = DEFAULT_DELAY_MS,
    maxDelayMs = DEFAULT_MAX_DELAY_MS,
    label = "supabase",
  } = {}
) {
  let last;
  for (let attempt = 1; retries === 0 || attempt <= retries; attempt++) {
    try {
      last = await fn();
    } catch (e) {
      last = { data: null, error: e };
    }
    if (!last.error) return last;

    const waitMs = retryDelayMs(attempt, delayMs, maxDelayMs);
    if (retries === 0 || attempt < retries) {
      console.warn(
        `  ${label}: попытка ${attempt}/${formatRetries(retries)} не удалась (${last.error.message}), повтор через ${waitMs}мс`
      );
      await sleep(waitMs);
    }
  }
  return last;
}
