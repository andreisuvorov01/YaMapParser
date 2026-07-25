// Построение маршрута «по дороге» через публичный демо-роутер OSRM.
//
// ВАЖНО про источник: https://router.project-osrm.org — бесплатный демо-сервер
// проекта OSRM. Это сервис «по возможности» (best-effort) с ограничением частоты
// запросов и без гарантий доступности. Поэтому:
//   • запрашиваем маршрут ТОЛЬКО по явному действию пользователя (кнопка),
//   • один запрос на нажатие (без авто-перестроения при каждом движении карты),
//   • при ошибке/недоступности — мягкий откат на внешние навигаторы (RouteButtons).

export interface RouteResult {
  geometry: GeoJSON.LineString;
  distanceM: number; // длина маршрута, метры
  durationS: number; // время в пути, секунды
}

const OSRM_BASE = "https://router.project-osrm.org/route/v1/driving";

// from/to передаются как [lat, lng] (как принято в приложении).
// OSRM ожидает порядок lon,lat.
export async function fetchOsrmRoute(
  from: [number, number],
  to: [number, number],
  signal?: AbortSignal
): Promise<RouteResult | null> {
  const coords = `${from[1]},${from[0]};${to[1]},${to[0]}`;
  const url = `${OSRM_BASE}/${coords}?overview=full&geometries=geojson`;
  const res = await fetch(url, { signal });
  if (!res.ok) return null;
  const json = (await res.json()) as {
    code?: string;
    routes?: { geometry: GeoJSON.LineString; distance: number; duration: number }[];
  };
  if (json.code !== "Ok") return null;
  const route = json.routes?.[0];
  if (!route?.geometry) return null;
  return {
    geometry: route.geometry,
    distanceM: route.distance,
    durationS: route.duration,
  };
}

// Длина маршрута: «850 м» / «12.4 км».
export function formatRouteDistance(m: number): string {
  if (m < 1000) return `${Math.round(m)} м`;
  return `${(m / 1000).toFixed(1)} км`;
}

// Время в пути: «3 мин» / «1 ч 12 мин».
export function formatEta(durationS: number): string {
  const min = Math.max(1, Math.round(durationS / 60));
  if (min < 60) return `${min} мин`;
  const h = Math.floor(min / 60);
  const rem = min % 60;
  return rem ? `${h} ч ${rem} мин` : `${h} ч`;
}
