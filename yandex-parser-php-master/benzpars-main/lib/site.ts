// Общие константы сайта для SEO/мета/sitemap.

// Базовый URL продакшена. Можно переопределить через переменную окружения.
export const SITE_URL = (
  process.env.NEXT_PUBLIC_SITE_URL?.trim() || "https://benzryadom.ru"
).replace(/\/$/, "");

// Название и контакты сервиса.
export const SITE_NAME = "бензрядом";
export const SITE_DESCRIPTION =
  "Краудсорсинговая карта заправок России: где есть бензин, лимиты на руки и очереди рядом с АЗС в реальном времени.";

/** Изображение для Open Graph / соцсетей. */
export const OG_IMAGE_PATH = "/icons/icon-512.png";

// Социальные сети / контакты.
export const TELEGRAM_URL = "https://t.me/BenzRyadom";
export const TELEGRAM_HANDLE = "@BenzRyadom";
export const VK_URL = "https://vk.com/benzryadom";
export const VK_HANDLE = "vk.com/benzryadom";

// Предложения по улучшению — в Telegram с заготовленным текстом.
export const FEEDBACK_TELEGRAM_URL = `${TELEGRAM_URL}?text=${encodeURIComponent(
  "Предложение по улучшению «бензрядом»: "
)}`;

// Донаты (CloudTips / Т-Банк). Задаётся через NEXT_PUBLIC_DONATE_URL на сервере.
export const DONATE_URL = process.env.NEXT_PUBLIC_DONATE_URL?.trim() || "";

// Абсолютный URL для канонических ссылок и OpenGraph.
export function absoluteUrl(path = "/"): string {
  if (!path.startsWith("/")) path = `/${path}`;
  return `${SITE_URL}${path}`;
}
