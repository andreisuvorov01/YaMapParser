import { distanceKm } from "./geo";
import type { StationStatus } from "./types";

/** Минимальное расстояние между «разными» АЗС (м). Ближе — считаем дублем. */
const DEDUP_METERS = 50;

function stationScore(s: StationStatus): number {
  const reports = s.reports_count ?? 0;
  const sourceBonus = s.source === "osm" ? 2 : 0;
  const freshBonus = s.stale ? 0 : 5;
  return reports * 10 + sourceBonus + freshBonus;
}

/** Убирает визуальные дубли (одна точка — несколько записей в БД). */
export function dedupeStationsByLocation(
  list: StationStatus[]
): StationStatus[] {
  const kept: StationStatus[] = [];

  for (const s of list) {
    const dup = kept.find(
      (k) => distanceKm(s.lat, s.lng, k.lat, k.lng) * 1000 < DEDUP_METERS
    );
    if (!dup) {
      kept.push(s);
      continue;
    }
    if (stationScore(s) > stationScore(dup)) {
      kept[kept.indexOf(dup)] = s;
    }
  }

  return kept;
}
