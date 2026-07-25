import { markDismissed, isDismissed } from "./clientStorage";

const KEY = "city_search_history_v1";
const MAX = 8;

export interface SearchHistoryItem {
  label: string;
  lat: number;
  lng: number;
  zoom?: number;
}

function read(): SearchHistoryItem[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw) as SearchHistoryItem[];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function write(items: SearchHistoryItem[]) {
  try {
    localStorage.setItem(KEY, JSON.stringify(items.slice(0, MAX)));
  } catch {
    /* ignore */
  }
}

/** Недавние города/адреса для быстрого повтора поиска. */
export function getSearchHistory(): SearchHistoryItem[] {
  return read();
}

export function pushSearchHistory(item: SearchHistoryItem) {
  const label = item.label.trim();
  if (!label) return;
  const next = [
    item,
    ...read().filter(
      (x) => x.label.toLowerCase() !== label.toLowerCase()
    ),
  ].slice(0, MAX);
  write(next);
}

export const SEARCH_HISTORY_HINT_KEY = "search_history_hint_v1";

export function shouldShowSearchHistoryHint(): boolean {
  return !isDismissed(SEARCH_HISTORY_HINT_KEY);
}

export function dismissSearchHistoryHint() {
  markDismissed(SEARCH_HISTORY_HINT_KEY);
}
