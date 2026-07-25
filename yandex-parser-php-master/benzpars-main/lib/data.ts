import { unstable_cache } from "next/cache";
import { aggregateStation } from "./freshness";
import { dedupeStationsByLocation } from "./stationDedup";
import { getServiceClient, getSupabaseClient, isSupabaseConfigured } from "./supabase";
import {
  addDemoReport,
  confirmDemoReport,
  getDemoReports,
  getRegisteredInBBox,
  registerStations,
  seedSampleReportsIfEmpty,
} from "./demo-store";
import { fetchFuelStations } from "./osm";
import type {
  BBox,
  CreateReportPayload,
  Report,
  ReportForStatus,
  Station,
  StationStatus,
} from "./types";

// Слой доступа к данным. Прозрачно переключается между Supabase
// (если настроен) и демо-хранилищем в памяти.

// Сервер: предпочитаем service client, иначе anon.
function serverClient() {
  return getServiceClient() ?? getSupabaseClient();
}

// Заправки + агрегированный статус в пределах bbox.
// limit — защита от аномально широкого bbox, а не способ ограничить плотный
// город: Москва в одном экране (DEFAULT_BBOX) — уже 1000+ станций, старый
// лимит 500 без сортировки молча резал больше половины из них. Кластеризация
// маркеров на клиенте (MapLibreMapView) рассчитана на тысячи точек, так что
// сама по себе большая выборка не проблема.
export async function getStationsWithStatus(
  bbox: BBox,
  limit = 3000
): Promise<StationStatus[]> {
  if (!isSupabaseConfigured) {
    // Демо-режим. Краснодар уже зарегистрирован (bundled из OSM).
    let known = getRegisteredInBBox(bbox, limit);

    if (known.length > 0) {
      // Есть данные по области — отдаём сразу, а свежие из OSM подтягиваем
      // в фоне для следующего запроса (не блокируем ответ).
      void fetchFuelStations(bbox)
        .then((list) => registerStations(list))
        .catch(() => {});
    } else {
      // Нет данных — пытаемся загрузить из OSM синхронно.
      try {
        const fetched = await fetchFuelStations(bbox);
        registerStations(fetched);
      } catch {
        // OSM недоступен — оставляем что есть (возможно, пусто)
      }
      known = getRegisteredInBBox(bbox, limit);
    }

    seedSampleReportsIfEmpty(known.map((s) => s.id));
    const ids = known.map((s) => s.id);
    const byStation = groupReports(getDemoReports(ids));
    return dedupeStationsByLocation(
      known.map((s) => aggregateStation(s, byStation.get(s.id) ?? []))
    );
  }

  const client = serverClient();
  if (!client) return [];

  const [south, west, north, east] = bbox;
  // Заправки в пределах bbox.
  const { data: stations, error } = await client
    .from("stations")
    .select("id,name,brand,lat,lng,address,source")
    .gte("lat", south)
    .lte("lat", north)
    .gte("lng", west)
    .lte("lng", east)
    .limit(limit);

  if (error) throw error;
  const list = (stations ?? []) as Station[];
  if (list.length === 0) return [];

  // Свежие отчёты для этих заправок. Запрос .in() с большим числом id
  // даёт слишком длинный URL (HeadersOverflow), поэтому бьём на чанки.
  const ids = list.map((s) => s.id);
  const sinceIso = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  const CHUNK = 120;
  const chunks: string[][] = [];
  for (let i = 0; i < ids.length; i += CHUNK) chunks.push(ids.slice(i, i + CHUNK));

  const results = await Promise.all(
    chunks.map((slice) =>
      client
        .from("reports")
        // Без comment/photo_url — они не нужны для агрегации статуса на карте,
        // только для ленты отчётов конкретной АЗС (см. getStationReports).
        .select(
          "id,station_id,status,fuel_types,limit_liters,queue,confirms,created_at"
        )
        .in("station_id", slice)
        .gte("created_at", sinceIso)
        .order("created_at", { ascending: false })
    )
  );

  const reports: ReportForStatus[] = [];
  for (const { data, error: rErr } of results) {
    if (rErr) throw rErr;
    if (data) reports.push(...(data as ReportForStatus[]));
  }
  const byStation = groupReports(reports);
  return dedupeStationsByLocation(
    list.map((s) => aggregateStation(s, byStation.get(s.id) ?? []))
  );
}

// Заправки по списку id (для избранного — без привязки к bbox карты).
export async function getStationsByIds(ids: string[]): Promise<StationStatus[]> {
  const unique = [...new Set(ids)].filter(Boolean).slice(0, 50);
  if (unique.length === 0) return [];

  if (!isSupabaseConfigured) {
    const known = getRegisteredInBBox([-90, -180, 90, 180], 5000).filter((s) =>
      unique.includes(s.id)
    );
    seedSampleReportsIfEmpty(known.map((s) => s.id));
    const byStation = groupReports(getDemoReports(known.map((s) => s.id)));
    return unique
      .map((id) => known.find((s) => s.id === id))
      .filter((s): s is Station => Boolean(s))
      .map((s) => aggregateStation(s, byStation.get(s.id) ?? []));
  }

  const client = serverClient();
  if (!client) return [];

  const CHUNK = 80;
  const chunks: string[][] = [];
  for (let i = 0; i < unique.length; i += CHUNK) {
    chunks.push(unique.slice(i, i + CHUNK));
  }

  const stations: Station[] = [];
  const stationResults = await Promise.all(
    chunks.map((slice) =>
      client
        .from("stations")
        .select("id,name,brand,lat,lng,address,source")
        .in("id", slice)
    )
  );
  for (const { data, error } of stationResults) {
    if (error) throw error;
    if (data) stations.push(...(data as Station[]));
  }

  if (stations.length === 0) return [];

  const stationIds = stations.map((s) => s.id);
  const sinceIso = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  const reportChunks: string[][] = [];
  for (let i = 0; i < stationIds.length; i += 120) {
    reportChunks.push(stationIds.slice(i, i + 120));
  }

  const reports: ReportForStatus[] = [];
  const reportResults = await Promise.all(
    reportChunks.map((slice) =>
      client
        .from("reports")
        .select(
          "id,station_id,status,fuel_types,limit_liters,queue,confirms,created_at"
        )
        .in("station_id", slice)
        .gte("created_at", sinceIso)
        .order("created_at", { ascending: false })
    )
  );
  for (const { data, error } of reportResults) {
    if (error) throw error;
    if (data) reports.push(...(data as ReportForStatus[]));
  }

  const byStation = groupReports(reports);
  const byId = new Map(stations.map((s) => [s.id, s]));
  return unique
    .map((id) => byId.get(id))
    .filter((s): s is Station => Boolean(s))
    .map((s) => aggregateStation(s, byStation.get(s.id) ?? []));
}

// Кэшированная выборка заправок города для SEO-страницы /azs/[city].
// Тяжёлый запрос в Supabase (~900мс на холодный рендер) кэшируется по slug
// города на 5 минут, поэтому повторные заходы не бьют в БД заново. Тег
// `city-stations:<slug>` позволяет точечно сбросить кэш при необходимости.
export function getCityStationsCached(
  slug: string,
  bbox: BBox,
  limit = 60
): Promise<StationStatus[]> {
  return unstable_cache(
    () => getStationsWithStatus(bbox, limit),
    ["city-stations", slug],
    { revalidate: 300, tags: [`city-stations:${slug}`] }
  )();
}

// Лента отчётов одной заправки.
export async function getStationReports(
  stationId: string,
  limit = 30
): Promise<Report[]> {
  if (!isSupabaseConfigured) {
    return getDemoReports([stationId]).slice(0, limit);
  }
  const client = serverClient();
  if (!client) return [];
  const { data, error } = await client
    .from("reports")
    .select(
      "id,station_id,status,fuel_types,limit_liters,queue,comment,photo_url,confirms,created_at"
    )
    .eq("station_id", stationId)
    .order("created_at", { ascending: false })
    .limit(limit);
  if (error) throw error;
  return (data ?? []) as Report[];
}

// Создание отчёта.
export async function createReport(
  payload: CreateReportPayload,
  clientId: string
): Promise<Report> {
  if (!isSupabaseConfigured) {
    return addDemoReport(payload);
  }
  const client = serverClient();
  if (!client) throw new Error("Supabase client unavailable");
  const { data, error } = await client
    .from("reports")
    .insert({
      station_id: payload.station_id,
      status: payload.status,
      fuel_types: payload.fuel_types,
      limit_liters: payload.limit_liters ?? null,
      queue: payload.queue,
      comment: payload.comment ?? null,
      photo_url: payload.photo_url ?? null,
      client_id: clientId,
    })
    .select(
      "id,station_id,status,fuel_types,limit_liters,queue,comment,photo_url,confirms,created_at"
    )
    .single();
  if (error) throw error;
  return data as Report;
}

// Подтверждение отчёта (увеличивает вес).
// Дедуп: один client_id — одно подтверждение на отчёт (таблица report_confirms).
export async function confirmReport(
  reportId: string,
  clientId: string
): Promise<boolean> {
  if (!isSupabaseConfigured) {
    return Boolean(confirmDemoReport(reportId));
  }
  const client = serverClient();
  if (!client) return false;

  // Пытаемся зафиксировать факт подтверждения. Конфликт по PK
  // (report_id, client_id) означает, что клиент уже подтверждал — не инкрементим.
  // Прочие ошибки (например, не применена миграция report_confirms) не глушим.
  const { error: dupErr } = await client
    .from("report_confirms")
    .insert({ report_id: reportId, client_id: clientId });
  if (dupErr) {
    if (dupErr.code === "23505") return false; // уже подтверждал
    throw dupErr;
  }

  // Атомарный инкремент через RPC.
  const { error } = await client.rpc("increment_confirms", {
    report_id: reportId,
  });
  if (error) throw error;
  return true;
}

// Кол-во подтверждений с одного client_id за последние minutes минут (rate-limit).
export async function countRecentConfirms(
  clientId: string,
  minutes = 5
): Promise<number> {
  if (!isSupabaseConfigured) return 0;
  const client = serverClient();
  if (!client) return 0;
  const sinceIso = new Date(Date.now() - minutes * 60000).toISOString();
  const { count, error } = await client
    .from("report_confirms")
    .select("report_id", { count: "exact", head: true })
    .eq("client_id", clientId)
    .gte("created_at", sinceIso);
  if (error) throw error;
  return count ?? 0;
}

// Кол-во отчётов с одного client_id за последние minutes минут (rate-limit).
export async function countRecentReports(
  clientId: string,
  minutes = 5
): Promise<number> {
  if (!isSupabaseConfigured) {
    // В демо-режиме rate-limit не критичен.
    return 0;
  }
  const client = serverClient();
  if (!client) return 0;
  const sinceIso = new Date(Date.now() - minutes * 60000).toISOString();
  const { count, error } = await client
    .from("reports")
    .select("id", { count: "exact", head: true })
    .eq("client_id", clientId)
    .gte("created_at", sinceIso);
  if (error) throw error;
  return count ?? 0;
}

function groupReports(reports: ReportForStatus[]): Map<string, ReportForStatus[]> {
  const map = new Map<string, ReportForStatus[]>();
  for (const r of reports) {
    const arr = map.get(r.station_id);
    if (arr) arr.push(r);
    else map.set(r.station_id, [r]);
  }
  return map;
}

// Пользовательская заправка (долгое нажатие на карте).
export async function createUserStation(
  input: {
    lat: number;
    lng: number;
    name: string;
    brand: string | null;
  },
  _clientId: string
): Promise<Station> {
  const name = input.name.trim().slice(0, 120) || "Заправка";
  const brand = input.brand?.trim().slice(0, 80) || null;

  if (!isSupabaseConfigured) {
    const id = `user-${Date.now().toString(36)}`;
    const station: Station = {
      id,
      name,
      brand,
      lat: input.lat,
      lng: input.lng,
      address: null,
      source: "user",
    };
    registerStations([station]);
    return station;
  }

  const client = serverClient();
  if (!client) throw new Error("Supabase client unavailable");

  const { data, error } = await client
    .from("stations")
    .insert({
      name,
      brand,
      lat: input.lat,
      lng: input.lng,
      address: null,
      source: "user",
    })
    .select("id,name,brand,lat,lng,address,source")
    .single();

  if (error) throw error;
  return data as Station;
}
