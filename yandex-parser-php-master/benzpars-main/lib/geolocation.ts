/** Сообщение при ошибке определения местоположения. */
export const GEO_FAIL_HINT = "Не удалось найти, возможно включен VPN";

export function isGeolocationSupported(): boolean {
  return typeof navigator !== "undefined" && "geolocation" in navigator;
}
