import { distanceKm } from "./geo";
import type { StationStatus } from "./types";

/** Расстояние от точки до отрезка (приближённо, в км). */
function pointToSegmentKm(
  plat: number,
  plng: number,
  a: [number, number],
  b: [number, number]
): number {
  const [alat, alng] = a;
  const [blat, blng] = b;
  const dAB = distanceKm(alat, alng, blat, blng);
  if (dAB < 0.001) return distanceKm(plat, plng, alat, alng);
  const t = Math.max(
    0,
    Math.min(
      1,
      ((plat - alat) * (blat - alat) + (plng - alng) * (blng - alng)) /
        ((blat - alat) ** 2 + (blng - alng) ** 2 + 1e-12)
    )
  );
  const clat = alat + t * (blat - alat);
  const clng = alng + t * (blng - alng);
  return distanceKm(plat, plng, clat, clng);
}

/** АЗС в коридоре вдоль маршрута (не дальше maxKm от линии). */
export function stationsAlongRoute(
  stations: StationStatus[],
  line: GeoJSON.LineString,
  maxKm = 2.5
): StationStatus[] {
  const coords = line.coordinates as [number, number][];
  if (coords.length < 2) return [];

  return stations
    .map((s) => {
      let min = Infinity;
      for (let i = 0; i < coords.length - 1; i++) {
        const [lng1, lat1] = coords[i];
        const [lng2, lat2] = coords[i + 1];
        min = Math.min(
          min,
          pointToSegmentKm(s.lat, s.lng, [lat1, lng1], [lat2, lng2])
        );
      }
      return { s, min };
    })
    .filter(({ min }) => min <= maxKm)
    .sort((a, b) => a.min - b.min)
    .map(({ s }) => s);
}
