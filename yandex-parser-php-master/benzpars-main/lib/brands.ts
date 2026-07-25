// Популярные сети АЗС в РФ (для фильтра, как на gdebenz.ru/brand/…).

import { matchBrandSlug, BRAND_TITLES } from "./brand-logos";

export const GAS_BRANDS = [
  "Лукойл",
  "Роснефть",
  "Газпромнефть",
  "Татнефть",
  "Shell",
  "BP",
  "Teboil",
  "Нефтьмагистраль",
  "ОПТИ",
  "EKA",
  "Газпром",
  "ПТК",
] as const;

// Нормализация названия бренда для сопоставления.
export function normalizeBrand(s: string | null | undefined): string {
  if (!s) return "";
  return s.trim().toLowerCase().replace(/ё/g, "е");
}

// Совпадение заправки с выбранным в фильтре брендом.
// Сопоставление идёт по каноническому slug, поэтому выбор «Лукойл» находит и
// «ЛУКОЙЛ», и «Lukoil», «Teboil» — «Тебоил/Тебойл», «Нефтьмагистраль» — «Нефтемагистраль».
export function brandMatches(
  stationBrand: string | null,
  stationName: string,
  filter: string
): boolean {
  if (!filter || filter === "all") return true;
  const filterSlug = matchBrandSlug(filter, "");
  if (filterSlug) {
    return matchBrandSlug(stationBrand, stationName) === filterSlug;
  }
  // Запасной путь для сетей вне таблицы алиасов — подстрочное сравнение.
  const f = normalizeBrand(filter);
  const b = normalizeBrand(stationBrand);
  const n = normalizeBrand(stationName);
  return (b !== "" && (b.includes(f) || f.includes(b))) || n.includes(f);
}

// Список «общих» названий, которые не несут информации (нужно заменить брендом/улицей).
const GENERIC_NAMES = new Set([
  "азс",
  "заправка",
  "автозаправка",
  "автозаправочная станция",
  "gas station",
  "fuel",
  "топливо",
]);

// Название считается «пустым/общим», если оно отсутствует или это просто «АЗС»/«Заправка».
export function isGenericName(name: string | null | undefined): boolean {
  const n = normalizeBrand(name);
  if (!n) return true;
  if (GENERIC_NAMES.has(n)) return true;
  // «азс №12», «азс 5» и т.п. — тоже считаем общим.
  if (/^азс[\s№#]*\d*$/.test(n)) return true;
  return false;
}

// Достаёт «улицу» из адреса (для подписи вида «АЗС · ул. Ленина»).
function streetFromAddress(address: string | null | undefined): string | null {
  if (!address) return null;
  const parts = address
    .split(",")
    .map((p) => p.trim())
    .filter(Boolean);
  if (parts.length === 0) return null;
  const streetRe =
    /(ул\.?|улица|пр-?кт|проспект|пр\.|ш\.|шоссе|пер\.|переулок|наб\.|набережная|б-р|бульвар|тракт|км|трасса|м-?\d)/i;
  const seg = parts.find((p) => streetRe.test(p));
  if (seg) return seg;
  // Иначе — самый длинный сегмент (обычно это «улица дом»), но не город целиком.
  return [...parts].sort((a, b) => b.length - a.length)[0] ?? null;
}

// Чистое отображаемое имя заправки (НЕ мутирует данные — только для показа).
// Приоритеты:
//   1) сеть уверенно распознана канонизацией → канонический титул бренда
//      (например «Teboil» вместо «Тебоил/Тебойл/Tboil»);
//   2) осмысленное «сырое» название → показываем как есть;
//   3) пустое/общее название → бренд или «АЗС» (+ улица из адреса).
export function displayName(s: {
  name: string;
  brand: string | null;
  address: string | null;
}): string {
  const raw = (s.name || "").trim();
  const slug = matchBrandSlug(s.brand, s.name);
  const street = streetFromAddress(s.address);

  // Бренд уверенно распознан — приводим к каноническому написанию.
  if (slug && BRAND_TITLES[slug]) {
    const canonical = BRAND_TITLES[slug];
    // Для осмысленных названий адрес уже выводится отдельной строкой —
    // показываем только канонический бренд; для общих добавляем улицу.
    if (raw && !isGenericName(raw)) return canonical;
    return street ? `${canonical} · ${street}` : canonical;
  }

  if (raw && !isGenericName(raw)) return raw;

  const base = (s.brand && s.brand.trim()) || "АЗС";
  return street ? `${base} · ${street}` : base;
}
