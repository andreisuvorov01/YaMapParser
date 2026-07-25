// Ссылки «Построить маршрут» (как на gdebenz.ru) — Яндекс.Навигатор, Яндекс.Карты, 2ГИС.

export function buildRouteLinks(lat: number, lng: number, name: string) {
  void name;
  const ll = `${lng},${lat}`;
  return [
    {
      id: "yandex-maps",
      label: "Яндекс Карты",
      href: `https://yandex.ru/maps/?rtext=~${lat},${lng}&rtt=auto`,
    },
    {
      id: "yandex-point",
      label: "На карте",
      href: `https://yandex.ru/maps/?pt=${ll}&z=17&l=map`,
    },
    {
      id: "yandex-navi",
      label: "Навигатор",
      href: `yandexnavi://build_route_on_map?lat_to=${lat}&lon_to=${lng}`,
    },
    {
      id: "2gis",
      label: "2ГИС",
      href: `https://2gis.ru/routeSearch/rsType/car/to/${ll}`,
    },
    {
      id: "google",
      label: "Google",
      href: `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`,
    },
  ] as const;
}

export function shareStationUrl(stationId: string): string {
  if (typeof window === "undefined") return "";
  const u = new URL(window.location.href);
  u.searchParams.set("station", stationId);
  return u.toString();
}
