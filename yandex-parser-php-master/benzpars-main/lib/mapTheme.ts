import type { MapTheme } from "@/components/MapLibreMapView";

const STORAGE_KEY = "mapTheme";

/** Карта всегда светлая; сбрасываем устаревшую тёмную тему в storage. */
export function readStoredMapTheme(): MapTheme {
  if (typeof window !== "undefined") {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved === "dark") {
        localStorage.setItem(STORAGE_KEY, "light");
      }
    } catch {
      /* storage недоступен */
    }
  }
  return "light";
}

/** Сохранение темы карты (только light). */
export function writeMapTheme(): void {
  try {
    localStorage.setItem(STORAGE_KEY, "light");
  } catch {
    /* storage недоступен */
  }
}

/** @deprecated Карта всегда светлая */
export function systemMapTheme(): MapTheme {
  return "light";
}
