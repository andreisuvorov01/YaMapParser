import type { FuelStatus } from "./types";
import { STATUS_LABELS } from "./types";

const ENABLED_KEY = "favorite_alerts_enabled_v1";
const STATUS_KEY = "favorite_alerts_status_v1";

export function favoriteAlertsEnabled(): boolean {
  if (typeof window === "undefined") return false;
  try {
    return Boolean(localStorage.getItem(ENABLED_KEY));
  } catch {
    return false;
  }
}

export function setFavoriteAlertsEnabled(on: boolean) {
  try {
    if (on) localStorage.setItem(ENABLED_KEY, "1");
    else localStorage.removeItem(ENABLED_KEY);
  } catch {
    /* ignore */
  }
}

function readStatusMap(): Record<string, FuelStatus> {
  try {
    const raw = localStorage.getItem(STATUS_KEY);
    if (!raw) return {};
    return JSON.parse(raw) as Record<string, FuelStatus>;
  } catch {
    return {};
  }
}

function writeStatusMap(map: Record<string, FuelStatus>) {
  try {
    localStorage.setItem(STATUS_KEY, JSON.stringify(map));
  } catch {
    /* ignore */
  }
}

/** Сравнивает статусы избранных и показывает системное уведомление при изменении. */
export function checkFavoriteStatusChanges(
  stations: { id: string; name: string; status: FuelStatus }[]
) {
  if (!favoriteAlertsEnabled()) return;
  if (typeof window === "undefined" || !("Notification" in window)) return;
  if (Notification.permission !== "granted") return;

  const prev = readStatusMap();
  const next: Record<string, FuelStatus> = { ...prev };

  for (const st of stations) {
    const old = prev[st.id];
    next[st.id] = st.status;
    if (old && old !== st.status) {
      const body = `${st.name}: ${STATUS_LABELS[old]} → ${STATUS_LABELS[st.status]}`;
      try {
        new Notification("бензрядом — избранная АЗС", {
          body,
          icon: "/icons/icon-192.png",
          tag: `fav-${st.id}`,
        });
      } catch {
        /* ignore */
      }
    }
  }
  writeStatusMap(next);
}

export async function requestFavoriteAlertPermission(): Promise<boolean> {
  if (!favoriteAlertsEnabled()) setFavoriteAlertsEnabled(true);
  if (typeof window === "undefined" || !("Notification" in window)) return false;
  if (Notification.permission === "granted") return true;
  if (Notification.permission === "denied") return false;
  const perm = await Notification.requestPermission();
  return perm === "granted";
}
