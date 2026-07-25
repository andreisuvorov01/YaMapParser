// Избранные АЗС в localStorage — пользователь отслеживает «свои» заправки.

const STORAGE_KEY = "favoriteStationIds";
const MAX_FAVORITES = 50;

type Listener = (ids: string[]) => void;
const listeners = new Set<Listener>();

function read(): string[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((id): id is string => typeof id === "string").slice(0, MAX_FAVORITES);
  } catch {
    return [];
  }
}

function write(ids: string[]) {
  const list = [...new Set(ids)].slice(0, MAX_FAVORITES);
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
  } catch {
    /* приватный режим — тихо игнорируем */
  }
  listeners.forEach((fn) => fn(list));
  return list;
}

/** Текущий список id избранных заправок. */
export function getFavoriteIds(): string[] {
  return read();
}

export function isFavorite(stationId: string): boolean {
  return read().includes(stationId);
}

/** Добавить или убрать из избранного. Возвращает новое состояние. */
export function toggleFavorite(stationId: string): boolean {
  const ids = read();
  const next = ids.includes(stationId)
    ? ids.filter((id) => id !== stationId)
    : [...ids, stationId];
  write(next);
  return next.includes(stationId);
}

export function subscribeFavorites(fn: Listener): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
