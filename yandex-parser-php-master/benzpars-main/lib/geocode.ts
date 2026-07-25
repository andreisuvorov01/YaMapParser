// Клиентский геокодер через серверный прокси /api/geocode (Nominatim + User-Agent).

export async function geocodeQuery(
  query: string
): Promise<{ lat: number; lng: number; label: string } | null> {
  const res = await fetch(`/api/geocode?q=${encodeURIComponent(query)}`);
  if (res.status === 404) return null;
  if (!res.ok) throw new Error("geocode_failed");
  return res.json();
}

export async function reverseGeocodeCity(
  lat: number,
  lng: number
): Promise<string | null> {
  const res = await fetch(
    `/api/geocode?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`
  );
  if (!res.ok) return null;
  const data = (await res.json()) as { city?: string | null };
  return data.city ?? null;
}
