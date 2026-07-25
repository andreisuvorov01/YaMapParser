// Утилиты установки PWA / ярлыка.

import { SITE_NAME } from "./site";

export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

export function isIos(): boolean {
  if (typeof navigator === "undefined") return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

export function isStandalone(): boolean {
  if (typeof window === "undefined") return false;
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    ("standalone" in navigator &&
      Boolean((navigator as Navigator & { standalone?: boolean }).standalone))
  );
}

export function isMobileViewport(): boolean {
  if (typeof window === "undefined") return true;
  return window.matchMedia("(max-width: 768px)").matches;
}

/** Ярлык .url для рабочего стола Windows. */
export function downloadDesktopShortcut(): void {
  const url = `${window.location.origin}/`;
  const body = `[InternetShortcut]\r\nURL=${url}\r\nIconIndex=0\r\n`;
  const blob = new Blob([body], { type: "application/octet-stream" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = `${SITE_NAME}.url`;
  a.click();
  URL.revokeObjectURL(a.href);
}
