import type { StationStatus } from "./types";

const CACHE_KEY = "favorites_offline_v1";

/** Кэш избранных АЗС для офлайн-просмотра последних статусов. */
export function cacheFavoriteStations(stations: StationStatus[]) {
  if (typeof window === "undefined") return;
  try {
    localStorage.setItem(
      CACHE_KEY,
      JSON.stringify({ at: Date.now(), stations })
    );
  } catch {
    /* quota */
  }
}

export function readFavoriteStationsCache(): {
  at: number;
  stations: StationStatus[];
} | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as {
      at?: number;
      stations?: StationStatus[];
    };
    if (!parsed.stations?.length) return null;
    return {
      at: parsed.at ?? 0,
      stations: parsed.stations,
    };
  } catch {
    return null;
  }
}

export function isOffline(): boolean {
  return typeof navigator !== "undefined" && !navigator.onLine;
}
