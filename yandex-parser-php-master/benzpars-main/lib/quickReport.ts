import { getClientId } from "./clientId";
import type { FuelStatus } from "./types";

/** Быстрый отчёт «есть / мало / нет» без полной формы. */
export async function submitQuickReport(
  stationId: string,
  status: Extract<FuelStatus, "yes" | "low" | "no">
): Promise<void> {
  const res = await fetch("/api/reports", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "x-client-id": getClientId(),
    },
    body: JSON.stringify({
      station_id: stationId,
      status,
      fuel_types: [],
      queue: "none",
      website: "",
    }),
  });
  if (!res.ok) {
    const j = await res.json().catch(() => ({}));
    throw new Error(String(j.error ?? "Не удалось отправить"));
  }
}
