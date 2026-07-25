import { createClient, type SupabaseClient } from "@supabase/supabase-js";

// Конфигурация Supabase. Если переменные окружения не заданы,
// приложение работает в демо-режиме (мок-данные), чтобы его можно
// было запустить и посмотреть без настройки бэкенда.

const url = process.env.NEXT_PUBLIC_SUPABASE_URL?.trim();
const anonKey = process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY?.trim();
const serviceKey = process.env.SUPABASE_SERVICE_ROLE_KEY?.trim();

// Заглушки из .env.example не считаем реальной настройкой.
const PLACEHOLDER_MARKERS = [
  "your-project",
  "your-anon-key",
  "your-service-role-key",
  "xxxxxxxx",
  "example.com",
];

function isRealCredential(value: string | undefined): boolean {
  if (!value || value.length < 8) return false;
  const lower = value.toLowerCase();
  return !PLACEHOLDER_MARKERS.some((m) => lower.includes(m));
}

// Настроен ли Supabase (есть ли реальные креды, не шаблон из .env.example)
export const isSupabaseConfigured =
  isRealCredential(url) && isRealCredential(anonKey);
// Браузерный/публичный клиент (anon ключ, ограничен RLS)
let browserClient: SupabaseClient | null = null;
export function getSupabaseClient(): SupabaseClient | null {
  if (!isSupabaseConfigured) return null;
  if (!browserClient) {
    browserClient = createClient(url as string, anonKey as string, {
      auth: { persistSession: false },
    });
  }
  return browserClient;
}

// Серверный клиент с service role (обходит RLS). Использовать ТОЛЬКО на сервере.
export function getServiceClient(): SupabaseClient | null {
  if (!isSupabaseConfigured || !isRealCredential(serviceKey)) return null;
  return createClient(url as string, serviceKey as string, {
    auth: { persistSession: false },
  });
}
