/** Ключ и тайминги подсказки «На рабочий стол / главный экран». */

import { readTimestamp } from "./clientStorage";

export const INSTALL_DISMISS_KEY = "bottomHintInstallAt";
export const COOLDOWN_MS = 5 * 24 * 60 * 60 * 1000;

/** Минимум на сайте перед любыми подсказками. */
export const SESSION_MIN_MS = 20_000;
/** Компактная плашка в шапке. */
export const CHIP_AFTER_MS = 25_000;
/** Долго на сайте → полный попап. */
export const LONG_SESSION_MS = 90_000;
/** Бездействие → полный попап. */
export const IDLE_MS = 50_000;

export function wasInstallDismissedRecently(): boolean {
  const ts = readTimestamp(INSTALL_DISMISS_KEY);
  if (!ts) return false;
  return Date.now() - ts < COOLDOWN_MS;
}
