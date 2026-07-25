// Латинские слаги для брендов АЗС (для красивых SEO-URL /seti/<slug>).
// Сопоставление каноничных названий из lib/brands.ts со слагами.

import { GAS_BRANDS } from "./brands";

// Явные слаги для всех известных брендов.
const BRAND_SLUGS: Record<string, string> = {
  "Лукойл": "lukoil",
  "Роснефть": "rosneft",
  "Газпромнефть": "gazprom-neft",
  "Татнефть": "tatneft",
  "Shell": "shell",
  "BP": "bp",
  "Teboil": "teboil",
  "Нефтьмагистраль": "neftmagistral",
  "ОПТИ": "opti",
  "EKA": "eka",
  "Газпром": "gazprom",
  "ПТК": "ptk",
};

export interface BrandEntry {
  name: string; // каноничное название (рус.)
  slug: string; // латинский слаг для URL
}

// Список всех брендов со слагами.
export const BRAND_ENTRIES: BrandEntry[] = GAS_BRANDS.map((name) => ({
  name,
  slug: BRAND_SLUGS[name] ?? name.toLowerCase().replace(/[^a-z0-9]+/g, "-"),
}));

// Найти бренд по слагу.
export function findBrandBySlug(slug: string): BrandEntry | undefined {
  return BRAND_ENTRIES.find((b) => b.slug === slug);
}

// Слаг по названию бренда.
export function brandSlug(name: string): string {
  const found = BRAND_ENTRIES.find((b) => b.name === name);
  return found ? found.slug : name.toLowerCase();
}
