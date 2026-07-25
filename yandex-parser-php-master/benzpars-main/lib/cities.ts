// Быстрый переход к городу (как SEO-страницы gdebenz.ru/{city}).

import { distanceKm } from "./geo";

export interface CityPreset {
  slug: string;
  name: string;
  // Падежи названия для корректных SEO-текстов: предложный («в Москве»)
  // и родительный («АЗС Москвы»). Используются хелперами из lib/morph.ts.
  prepositional: string;
  genitive: string;
  lat: number;
  lng: number;
  zoom: number;
}

export const CITY_PRESETS: CityPreset[] = [
  { slug: "krasnodar", name: "Краснодар", prepositional: "Краснодаре", genitive: "Краснодара", lat: 45.0355, lng: 38.9753, zoom: 12 },
  { slug: "moskva", name: "Москва", prepositional: "Москве", genitive: "Москвы", lat: 55.7558, lng: 37.6173, zoom: 11 },
  { slug: "sankt-peterburg", name: "Санкт-Петербург", prepositional: "Санкт-Петербурге", genitive: "Санкт-Петербурга", lat: 59.9343, lng: 30.3351, zoom: 11 },
  { slug: "rostov-na-donu", name: "Ростов-на-Дону", prepositional: "Ростове-на-Дону", genitive: "Ростова-на-Дону", lat: 47.2357, lng: 39.7015, zoom: 12 },
  { slug: "sochi", name: "Сочи", prepositional: "Сочи", genitive: "Сочи", lat: 43.6028, lng: 39.7342, zoom: 12 },
  { slug: "novorossiysk", name: "Новороссийск", prepositional: "Новороссийске", genitive: "Новороссийска", lat: 44.7235, lng: 37.7686, zoom: 13 },
  { slug: "voronezh", name: "Воронеж", prepositional: "Воронеже", genitive: "Воронежа", lat: 51.672, lng: 39.1843, zoom: 12 },
  { slug: "volgograd", name: "Волгоград", prepositional: "Волгограде", genitive: "Волгограда", lat: 48.708, lng: 44.5133, zoom: 12 },
  { slug: "ekaterinburg", name: "Екатеринбург", prepositional: "Екатеринбурге", genitive: "Екатеринбурга", lat: 56.8389, lng: 60.6057, zoom: 12 },
  { slug: "kazan", name: "Казань", prepositional: "Казани", genitive: "Казани", lat: 55.7887, lng: 49.1221, zoom: 12 },
];

export function findCityBySlug(slug: string): CityPreset | undefined {
  return CITY_PRESETS.find((c) => c.slug === slug);
}

// Ближайший город-пресет к координатам без обращения к API.
// Возвращает пресет в радиусе maxKm (км) или null, если все слишком далеко.
export function nearestCity(
  lat: number,
  lng: number,
  maxKm = 70
): CityPreset | null {
  let best: CityPreset | null = null;
  let bestKm = Infinity;
  for (const c of CITY_PRESETS) {
    const km = distanceKm(lat, lng, c.lat, c.lng);
    if (km < bestKm) {
      bestKm = km;
      best = c;
    }
  }
  return best && bestKm <= maxKm ? best : null;
}

// Примерный bbox вокруг центра города [south, west, north, east].
// Радиус зависит от зума пресета (чем меньше зум — тем шире охват).
export function cityBBox(
  city: CityPreset
): [number, number, number, number] {
  const dLat = city.zoom <= 11 ? 0.35 : 0.25;
  const dLng = city.zoom <= 11 ? 0.6 : 0.45;
  return [city.lat - dLat, city.lng - dLng, city.lat + dLat, city.lng + dLng];
}
