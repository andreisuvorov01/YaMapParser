// Сети АЗС: логотип (файл в public/brands) + фирменный цвет для подложки.
// Если файла логотипа нет — рисуется аккуратная монограмма фирменного цвета.

import { normalizeBrand } from "./brands";

export interface BrandMeta {
  slug: string;
  label: string; // короткая подпись на монограмме (1–5 симв.)
  bg: string; // фон монограммы
  fg: string; // цвет текста монограммы
  match: string[]; // ключевые слова (нормализованные) для сопоставления
  logo?: string; // имя файла в /public/brands (png/jpg/svg)
  darkBg?: boolean; // логотип на тёмном фоне (подложка чёрная вместо белой)
}

const BRANDS: BrandMeta[] = [
  { slug: "lukoil", label: "Л", bg: "#E2231A", fg: "#FFFFFF", match: ["лукойл", "лукоил", "lukoil"], logo: "lukoil.svg" },
  { slug: "rosneft", label: "Р", bg: "#FFCC00", fg: "#1A1A1A", match: ["роснефт", "rosneft", "тнк", "tnk", "bashneft", "башнефть"], logo: "rosneft.png" },
  { slug: "gazpromneft", label: "ГПН", bg: "#0073C7", fg: "#FFFFFF", match: ["газпромнефть", "газпром нефть", "gazpromneft", "gpn"], logo: "gazpromneft.svg" },
  { slug: "gazprom", label: "Г", bg: "#1B75BB", fg: "#FFFFFF", match: ["газпром"], logo: "gazprom.png" },
  { slug: "tatneft", label: "ТН", bg: "#E30613", fg: "#FFFFFF", match: ["татнефть", "tatneft"], logo: "tatneft.svg" },
  { slug: "shell", label: "Sh", bg: "#FBCE07", fg: "#D42E12", match: ["shell", "шелл"], logo: "shell.svg" },
  { slug: "bp", label: "bp", bg: "#009639", fg: "#FFE600", match: ["bp", "би пи"], logo: "bp.svg" },
  { slug: "teboil", label: "Tb", bg: "#E2231A", fg: "#FFFFFF", match: ["teboil", "тебойл", "тебоил", "tboil", "тебойль"], logo: "teboil.svg" },
  { slug: "neftm", label: "НМ", bg: "#D6001C", fg: "#FFFFFF", match: ["нефтьмагистраль", "нефтемагистраль", "нефть магистраль", "нефте магистраль"], logo: "neftm.png" },
  { slug: "opti", label: "OPTI", bg: "#F49A00", fg: "#1A1A1A", match: ["опти", "opti"], logo: "opti.svg" },
  { slug: "eka", label: "EKA", bg: "#E2001A", fg: "#FFFFFF", match: ["eka", "эка"] },
  { slug: "ptk", label: "ПТК", bg: "#0061AF", fg: "#FFFFFF", match: ["птк", "ptk"] },
  { slug: "irbis", label: "IRB", bg: "#0A8A3C", fg: "#FFFFFF", match: ["ирбис", "irbis"] },
  { slug: "neftmag", label: "ННК", bg: "#0F3D8A", fg: "#FFFFFF", match: ["ннк", "нефтегаз"] },
  { slug: "trassa", label: "ТР", bg: "#1F6FB2", fg: "#FFFFFF", match: ["трассоил", "trassoil", "трасса", "trassa"] },
  { slug: "stinvest", label: "СТ", bg: "#0A8A3C", fg: "#FFFFFF", match: ["стинвест", "ст инвест", "ст-инвест", "stinvest"] },
];

const PALETTE = ["#0EA5E9", "#8B5CF6", "#10B981", "#F97316", "#EF4444", "#14B8A6", "#6366F1"];

function hashHue(s: string): string {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return PALETTE[h % PALETTE.length];
}

function initials(name: string): string {
  const clean = name.replace(/["'«»№]/g, "").trim();
  const words = clean.split(/[\s.\-/]+/).filter(Boolean);
  if (words.length === 0) return "АЗС";
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

// Ищет известную сеть по бренду+названию через таблицу алиасов (RU+латиница).
// Возвращает BrandMeta известной сети либо null.
function findBrand(
  brand: string | null | undefined,
  name: string
): BrandMeta | null {
  const hay = `${normalizeBrand(brand)} ${normalizeBrand(name)}`;
  for (const b of BRANDS) {
    if (b.match.some((m) => hay.includes(m))) return b;
  }
  return null;
}

// Канонический slug известной сети по бренду/названию, иначе null.
// Единая точка матчинга для логотипов, фильтра брендов и чистых названий.
export function matchBrandSlug(
  brand: string | null | undefined,
  name: string
): string | null {
  return findBrand(brand, name)?.slug ?? null;
}

// Человекочитаемое каноническое название сети по slug (для чистых названий).
export const BRAND_TITLES: Record<string, string> = {
  lukoil: "Лукойл",
  rosneft: "Роснефть",
  gazpromneft: "Газпромнефть",
  gazprom: "Газпром",
  tatneft: "Татнефть",
  shell: "Shell",
  bp: "BP",
  teboil: "Teboil",
  neftm: "Нефтьмагистраль",
  opti: "ОПТИ",
  eka: "EKA",
  ptk: "ПТК",
  irbis: "Ирбис",
  neftmag: "ННК",
  trassa: "Трасса",
  stinvest: "Стинвест",
};

// Подбирает фирменный бренд по бренду/названию, иначе — нейтральный с инициалами.
export function resolveBrandBadge(
  brand: string | null | undefined,
  name: string
): BrandMeta {
  const found = findBrand(brand, name);
  if (found) return found;
  const display = (brand && brand.trim()) || name || "АЗС";
  return {
    slug: "generic",
    label: initials(display),
    bg: hashHue(display),
    fg: "#FFFFFF",
    match: [],
  };
}

export function brandLogoUrl(meta: BrandMeta): string | null {
  return meta.logo ? `/brands/${meta.logo}` : null;
}
