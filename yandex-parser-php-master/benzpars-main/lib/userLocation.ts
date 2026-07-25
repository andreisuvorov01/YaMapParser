const STORAGE_KEY = "bz_user_location_v1";
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

interface StoredLocation {
  lat: number;
  lng: number;
  at: number;
}

/** Сохранить последнее известное местоположение. */
export function saveUserLocation(lat: number, lng: number): void {
  if (typeof window === "undefined") return;
  try {
    const payload: StoredLocation = { lat, lng, at: Date.now() };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
  } catch {
    /* storage недоступен */
  }
}

/** Прочитать сохранённое местоположение (не старше maxAgeMs). */
export function readUserLocation(
  maxAgeMs = MAX_AGE_MS
): [number, number] | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw) as StoredLocation;
    if (
      !Number.isFinite(data.lat) ||
      !Number.isFinite(data.lng) ||
      !Number.isFinite(data.at)
    ) {
      return null;
    }
    if (Date.now() - data.at > maxAgeMs) return null;
    return [data.lat, data.lng];
  } catch {
    return null;
  }
}
