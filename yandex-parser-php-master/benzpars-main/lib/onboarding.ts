import { isDismissed } from "./clientStorage";

/** Ключ для онбординга (меняйте суффикс при существенном обновлении). */
export const ONBOARDING_STORAGE_KEY = "onboarding_dismissed_v1";

export function isOnboardingComplete(): boolean {
  return isDismissed(ONBOARDING_STORAGE_KEY);
}
