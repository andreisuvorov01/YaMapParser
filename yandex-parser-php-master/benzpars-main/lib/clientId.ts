// Анонимный идентификатор клиента для базовой защиты от спама (rate-limit).
// Хранится в localStorage, отправляется на сервер заголовком x-client-id.

const KEY = "bz_client_id";
let memoryId: string | null = null;

function newId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

export function getClientId(): string {
  if (typeof window === "undefined") return "server";
  try {
    let id = window.localStorage.getItem(KEY);
    if (!id) {
      id = newId();
      window.localStorage.setItem(KEY, id);
    }
    return id;
  } catch {
    if (!memoryId) memoryId = newId();
    return memoryId;
  }
}
