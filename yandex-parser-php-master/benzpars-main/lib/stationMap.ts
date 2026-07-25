import type { BBox, StationStatus } from "./types";

/** Объединяет списки АЗС: новые данные перекрывают старые, далёкие точки отбрасываем. */
export function mergeStationLists(
  prev: StationStatus[],
  next: StationStatus[],
  keepBBox: BBox,
  padDeg = 0.08
): StationStatus[] {
  const [south, west, north, east] = keepBBox;
  const minLat = south - padDeg;
  const maxLat = north + padDeg;
  const minLng = west - padDeg;
  const maxLng = east + padDeg;

  const byId = new Map<string, StationStatus>();
  for (const st of prev) {
    if (
      st.lat >= minLat &&
      st.lat <= maxLat &&
      st.lng >= minLng &&
      st.lng <= maxLng
    ) {
      byId.set(st.id, st);
    }
  }
  for (const st of next) byId.set(st.id, st);
  return Array.from(byId.values());
}
