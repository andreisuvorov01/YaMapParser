// Применяет SQL-миграции схемы (ALTER TABLE / CREATE INDEX / CREATE FUNCTION)
// напрямую к Postgres по connection string (DATABASE_URL в .env) — тем же
// самым способом, каким теперь работают и все sync-*.mjs (см. lib/db.mjs).
//
// Запуск: node migrate.mjs

import pg from "pg";
import { loadEnv } from "./load-env.mjs";

loadEnv();

const CONNECTION_STRING =
  process.env.DATABASE_URL || process.env.SUPABASE_DB_URL || process.env.POSTGRES_URL || process.env.POSTGRES_URL_NON_POOLING;

if (!CONNECTION_STRING) {
  console.error(
    "Не задана строка подключения к Postgres.\n\n" +
      "Добавьте в .env DATABASE_URL (postgresql://user:password@host:5432/dbname).\n"
  );
  process.exit(1);
}

// Каждая миграция идемпотентна (IF NOT EXISTS) — повторный запуск безопасен.
const MIGRATIONS = [
  {
    name: "stations.benzinest_id",
    sql: `
      alter table public.stations add column if not exists benzinest_id text;
      create index if not exists stations_benzinest_id_idx on public.stations (benzinest_id);
    `,
  },
  {
    name: "stations.last_report_at",
    sql: `
      alter table public.stations add column if not exists last_report_at timestamptz;
      create index if not exists stations_last_report_at_idx on public.stations (last_report_at);
    `,
  },
  {
    // Массовое обновление last_report_at РАЗНЫМИ значениями за один запрос.
    // Обычный upsert() тут не годится: INSERT ... ON CONFLICT DO UPDATE в
    // Postgres строит полную кандидатную строку (со всеми NOT NULL
    // колонками вроде lat/lng) ДО проверки конфликта, даже если сработает
    // путь UPDATE — неполный payload {id, last_report_at} валится с "null
    // value in column lat". Обычный plain UPDATE эту проблему не имеет, но
    // Supabase-js .update() выставляет ОДНО значение сразу для всех строк
    // фильтра — для разных значений на каждую строку нужен отдельный запрос
    // на строку, что на тысячах станций плодит лишнюю сетевую нагрузку и
    // транзиентные "fetch failed". Эта RPC делает то же самое одним
    // запросом на всю пачку.
    name: "rpc.bulk_update_last_report_at",
    sql: `
      create or replace function public.bulk_update_last_report_at(updates jsonb)
      returns void
      language sql
      as $$
        update public.stations s
        set last_report_at = (u.value->>'last_report_at')::timestamptz
        from jsonb_array_elements(updates) as u(value)
        where s.id = (u.value->>'id')::uuid;
      $$;
    `,
  },
  {
    name: "stations.benzinstatus_id",
    sql: `
      alter table public.stations add column if not exists benzinstatus_id text;
      create index if not exists stations_benzinstatus_id_idx on public.stations (benzinstatus_id);
    `,
  },
  {
    // Отдельная отметка попытки (не last_report_at — та же колонка, что и у
    // gdebenz-комментов, отражает время последнего РЕАЛЬНОГО отчёта, а не
    // попытки его забрать) — проставляется в sync-gdebenz-comments.mjs
    // (третий пункт, benzinstatus) при каждой успешной проверке цены,
    // тем же способом, что и gdebenz_comments_synced_at для gdebenz.
    name: "stations.benzinstatus_synced_at",
    sql: `
      alter table public.stations add column if not exists benzinstatus_synced_at timestamptz;
      create index if not exists stations_benzinstatus_synced_idx on public.stations (benzinstatus_synced_at) where benzinstatus_id is not null;
    `,
  },
  {
    // Как и bulk_update_last_report_at выше — привязка benzinstatus_id к УЖЕ
    // существующей станции (найденной геопоиском, см. sync-benzinstatus.mjs)
    // требует РАЗНОГО значения id на каждую строку за один запрос, обычный
    // upsert/update тут не годится по тем же причинам.
    name: "rpc.bulk_update_benzinstatus_id",
    sql: `
      create or replace function public.bulk_update_benzinstatus_id(updates jsonb)
      returns void
      language sql
      as $$
        update public.stations s
        set benzinstatus_id = (u.value->>'benzinstatus_id')
        from jsonb_array_elements(updates) as u(value)
        where s.id = (u.value->>'id')::uuid;
      $$;
    `,
  },
];

async function main() {
  const client = new pg.Client({
    connectionString: CONNECTION_STRING,
    ssl: process.env.PGSSL === "1" ? { rejectUnauthorized: false } : false,
  });
  await client.connect();
  console.log("Подключено к Postgres.");
  try {
    for (const m of MIGRATIONS) {
      process.stdout.write(`Применяю: ${m.name}... `);
      await client.query(m.sql);
      console.log("ok");
    }
    console.log("Готово — все миграции применены.");
  } finally {
    await client.end();
  }
}

main().catch((e) => {
  console.error("\nОшибка миграции:", e.message);
  process.exit(1);
});
