// Общие типы данных приложения "Карта заправок РФ"

// Виды топлива
export type FuelType = "АИ-92" | "АИ-95" | "АИ-98" | "АИ-100" | "ДТ" | "Газ";

export const FUEL_TYPES: FuelType[] = [
  "АИ-92",
  "АИ-95",
  "АИ-98",
  "АИ-100",
  "ДТ",
  "Газ",
];

// Статус наличия топлива
export type FuelStatus = "yes" | "low" | "no" | "unknown";

export const STATUS_LABELS: Record<FuelStatus, string> = {
  yes: "Есть бензин",
  low: "Мало / лимит",
  no: "Нет топлива",
  unknown: "Нет данных",
};

// Короткие подписи для компактных бейджей (без обрезки по словам).
export const STATUS_SHORT: Record<FuelStatus, string> = {
  yes: "Есть",
  low: "Мало",
  no: "Нет",
  unknown: "Нет данных",
};

// Цвета статусов — насыщенные, читаемые на карте города и в UI.
export const STATUS_HEX: Record<FuelStatus, string> = {
  yes: "#00C853",
  low: "#FF9100",
  no: "#FF3D00",
  unknown: "#90A4AE",
};

// Длина очереди
export type QueueLevel = "none" | "small" | "big" | "hours";

export const QUEUE_LABELS: Record<QueueLevel, string> = {
  none: "Без очереди",
  small: "Небольшая очередь",
  big: "Большая очередь",
  hours: "Очередь на часы",
};

// Заправка
export interface Station {
  id: string;
  name: string;
  brand: string | null;
  lat: number;
  lng: number;
  address: string | null;
  source: "osm" | "user";
}

// Отчёт пользователя
export interface Report {
  id: string;
  station_id: string;
  status: FuelStatus;
  fuel_types: FuelType[];
  limit_liters: number | null;
  queue: QueueLevel;
  comment: string | null;
  photo_url: string | null;
  confirms: number;
  created_at: string; // ISO
}

// Поля отчёта, реально нужные для агрегации статуса станции (без comment/photo_url —
// они нужны только для ленты отчётов конкретной АЗС, не для карты).
export type ReportForStatus = Pick<
  Report,
  | "id"
  | "station_id"
  | "status"
  | "fuel_types"
  | "limit_liters"
  | "queue"
  | "confirms"
  | "created_at"
>;

// Агрегированный статус заправки (то, что отрисовывается на карте)
export interface StationStatus extends Station {
  status: FuelStatus;
  queue: QueueLevel | null;
  limit_liters: number | null;
  fuel_types: FuelType[];
  last_report_at: string | null; // ISO
  reports_count: number; // кол-во отчётов в окне свежести
  stale: boolean; // данные устарели
  conflicting: boolean; // противоречивые отчёты
}

// Полезная нагрузка для создания отчёта
export interface CreateReportPayload {
  station_id: string;
  status: FuelStatus;
  fuel_types: FuelType[];
  limit_liters?: number | null;
  queue: QueueLevel;
  comment?: string | null;
  photo_url?: string | null;
  // honeypot — должно быть пустым (защита от ботов)
  website?: string;
}

// Bounding box карты: [south, west, north, east]
export type BBox = [number, number, number, number];
