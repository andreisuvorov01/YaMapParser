// Серверные запросы к Nominatim (User-Agent обязателен по политике OSM).

import { SITE_URL } from "./site";

const NOMINATIM = "https://nominatim.openstreetmap.org";
// User-Agent только ASCII — иначе fetch в Node падает на кириллице.
const USER_AGENT = `benzryadom/1.0 (+${SITE_URL})`;

async function nominatimFetch(path: string): Promise<Response> {
  return fetch(`${NOMINATIM}${path}`, {
    headers: {
      Accept: "application/json",
      "User-Agent": USER_AGENT,
    },
    next: { revalidate: 3600 },
  });
}

/** Прямое геокодирование (только на сервере). */
export async function geocodeQueryServer(
  query: string
): Promise<{ lat: number; lng: number; label: string } | null> {
  const url =
    `/search?format=jsonv2&limit=1&countrycodes=ru&accept-language=ru` +
    `&q=${encodeURIComponent(query)}`;
  const res = await nominatimFetch(url);
  if (!res.ok) return null;
  const data = (await res.json()) as Array<{
    lat: string;
    lon: string;
    display_name: string;
  }>;
  if (!data.length) return null;
  const it = data[0];
  return {
    lat: parseFloat(it.lat),
    lng: parseFloat(it.lon),
    label: it.display_name || query,
  };
}

/** Обратное геокодирование (только на сервере). */
export async function reverseGeocodeCityServer(
  lat: number,
  lng: number
): Promise<string | null> {
  const url =
    `/reverse?format=jsonv2&zoom=10&accept-language=ru` +
    `&lat=${lat}&lon=${lng}`;
  const res = await nominatimFetch(url);
  if (!res.ok) return null;
  const data = (await res.json()) as {
    name?: string;
    address?: Record<string, string>;
  };
  const a = data.address ?? {};
  return (
    a.city || a.town || a.village || a.municipality || a.county || data.name || null
  );
}
