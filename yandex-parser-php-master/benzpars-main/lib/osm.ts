import type { BBox, Station } from "./types";

// Загрузка реальных заправок из OpenStreetMap (Overpass API) по bbox.
// Используется в демо-режиме (когда Supabase не настроен), чтобы карта
// показывала настоящие АЗС в любом городе без поднятия бэкенда.

const OVERPASS_ENDPOINTS = [
  "https://maps.mail.ru/osm/tools/overpass/api/interpreter",
  "https://overpass-api.de/api/interpreter",
  "https://overpass.kumi.systems/api/interpreter",
];

// Кэш по квантованному bbox, чтобы не дёргать Overpass на каждое движение.
type CacheEntry = { ts: number; stations: Station[] };
const cache = new Map<string, CacheEntry>();
const TTL_MS = 10 * 60 * 1000; // 10 минут

function quantize(n: number): number {
  return Math.round(n * 10) / 10; // шаг ~0.1°
}

function cacheKey(bbox: BBox): string {
  return bbox.map(quantize).join(",");
}

function pickName(tags: Record<string, string> = {}): string {
  return tags.name || tags.brand || tags.operator || "АЗС";
}

function pickAddress(tags: Record<string, string> = {}): string | null {
  const parts = [
    tags["addr:city"],
    tags["addr:street"],
    tags["addr:housenumber"],
  ].filter(Boolean);
  return parts.length ? parts.join(", ") : null;
}

interface OverpassElement {
  type: string;
  id: number;
  lat?: number;
  lon?: number;
  center?: { lat: number; lon: number };
  tags?: Record<string, string>;
}

async function queryOverpass(bbox: BBox): Promise<Station[]> {
  const [south, west, north, east] = bbox;
  const q = `[out:json][timeout:25];(node["amenity"="fuel"](${south},${west},${north},${east});way["amenity"="fuel"](${south},${west},${north},${east}););out center tags;`;

  let lastErr: unknown = null;
  for (const endpoint of OVERPASS_ENDPOINTS) {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      const res = await fetch(endpoint + "?data=" + encodeURIComponent(q), {
        method: "GET",
        headers: {
          "User-Agent": "benzin-map/1.0",
        },
        signal: controller.signal,
      });
      clearTimeout(timeout);
      if (!res.ok) {
        lastErr = new Error(`Overpass ${res.status}`);
        console.warn(`[osm] ${endpoint} -> HTTP ${res.status}`);
        continue;
      }
      const data = (await res.json()) as { elements?: OverpassElement[] };
      const stations: Station[] = [];
      for (const el of data.elements ?? []) {
        const lat = el.lat ?? el.center?.lat;
        const lng = el.lon ?? el.center?.lon;
        if (typeof lat !== "number" || typeof lng !== "number") continue;
        stations.push({
          id: `osm-${el.type[0]}${el.id}`,
          name: pickName(el.tags),
          brand: el.tags?.brand ?? null,
          lat,
          lng,
          address: pickAddress(el.tags),
          source: "osm",
        });
      }
      return stations;
    } catch (e) {
      lastErr = e;
      console.warn(`[osm] ${endpoint} failed:`, (e as Error)?.message ?? e);
    }
  }
  throw lastErr ?? new Error("Overpass unavailable");
}

// Возвращает заправки в bbox (с кэшем). Бросает ошибку, если все эндпоинты недоступны.
export async function fetchFuelStations(bbox: BBox): Promise<Station[]> {
  const key = cacheKey(bbox);
  const now = Date.now();
  const cached = cache.get(key);
  if (cached && now - cached.ts < TTL_MS) {
    return cached.stations;
  }
  const stations = await queryOverpass(bbox);
  cache.set(key, { ts: now, stations });
  return stations;
}
