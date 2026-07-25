import type { BBox } from "./types";

/** Максимальный размер области одного запроса (градусы). При большем — обрезаем, не отклоняем. */
export const QUERY_MAX_SPAN_DEG = 20;

const KM_PER_DEG_LAT = 111.32;

/** Bbox вокруг точки с заданным радиусом (км). */
export function bboxAroundPoint(
  lat: number,
  lng: number,
  radiusKm: number
): BBox {
  const dLat = radiusKm / KM_PER_DEG_LAT;
  const cos = Math.cos((lat * Math.PI) / 180);
  const dLng = radiusKm / (KM_PER_DEG_LAT * Math.max(cos, 0.15));
  return [lat - dLat, lng - dLng, lat + dLat, lng + dLng];
}

/** Обрезает слишком большой bbox до maxSpan, сохраняя центр. */
export function clampBBoxSpan(bbox: BBox, maxSpan = QUERY_MAX_SPAN_DEG): BBox {
  const [south, west, north, east] = bbox;
  const latSpan = north - south;
  const lngSpan = east - west;
  if (latSpan <= maxSpan && lngSpan <= maxSpan) return bbox;

  const cLat = (south + north) / 2;
  const cLng = (west + east) / 2;
  const halfLat = Math.min(latSpan / 2, maxSpan / 2);
  const halfLng = Math.min(lngSpan / 2, maxSpan / 2);
  return [cLat - halfLat, cLng - halfLng, cLat + halfLat, cLng + halfLng];
}

/** Парсинг координат bbox с нормализацией (swap/reorder при микропогрешностях). */
export function parseBBoxCoords(param: string): BBox | null {
  const parts = param.split(",").map((s) => Number(s.trim()));
  if (parts.length !== 4 || parts.some((n) => !Number.isFinite(n))) {
    return null;
  }
  let [south, west, north, east] = parts;
  if (south > north) [south, north] = [north, south];
  if (west > east) [west, east] = [east, west];
  south = Math.max(-90, Math.min(90, south));
  north = Math.max(-90, Math.min(90, north));
  west = Math.max(-180, Math.min(180, west));
  east = Math.max(-180, Math.min(180, east));
  if (south >= north) return null;
  if (east < west) return null;
  return [south, west, north, east];
}

/** Парсинг и валидация bbox: south,west,north,east (с обрезкой большой области). */
export function parseBBoxParam(param: string): BBox | null {
  const bbox = parseBBoxCoords(param);
  if (!bbox) return null;
  return clampBBoxSpan(bbox);
}

/** Почти одинаковые bbox (для пропуска лишних запросов при микродвижении карты). */
export function bboxNearlyEqual(a: BBox, b: BBox, eps = 0.0008): boolean {
  return a.every((v, i) => Math.abs(v - b[i]) < eps);
}

/** Ключ bbox для сравнения/кэша. */
export function bboxKey(bbox: BBox): string {
  return bbox.map((n) => n.toFixed(4)).join(",");
}

/** Расширяет bbox на долю от текущего размера (подгрузка «с запасом» при панорамировании). */
export function expandBBox(bbox: BBox, factor = 0.25): BBox {
  const [south, west, north, east] = bbox;
  const latPad = ((north - south) * factor) / 2;
  const lngPad = ((east - west) * factor) / 2;
  return clampBBoxSpan([
    south - latPad,
    west - lngPad,
    north + latPad,
    east + lngPad,
  ]);
}

/** Грубый ключ кэша (~1 км) — для повторных запросов той же области. */
export function bboxCacheKey(bbox: BBox): string {
  return bbox.map((n) => n.toFixed(2)).join(",");
}

/** Bbox по координатам маршрута (GeoJSON LineString, порядок lon,lat). */
export function bboxFromLineString(
  line: GeoJSON.LineString,
  padDeg = 0.04
): BBox {
  let south = Infinity;
  let west = Infinity;
  let north = -Infinity;
  let east = -Infinity;
  for (const [lng, lat] of line.coordinates) {
    south = Math.min(south, lat);
    north = Math.max(north, lat);
    west = Math.min(west, lng);
    east = Math.max(east, lng);
  }
  return clampBBoxSpan([
    south - padDeg,
    west - padDeg,
    north + padDeg,
    east + padDeg,
  ]);
}
