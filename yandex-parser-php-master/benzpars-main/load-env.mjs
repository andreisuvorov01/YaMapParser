import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join, resolve, normalize } from "node:path";

const root = dirname(fileURLToPath(import.meta.url));
// Фиксированный путь к .env — не зависит от пользовательского ввода
const ENV_PATH = resolve(root, ".env");

export function loadEnv() {
  try {
    const env = readFileSync(ENV_PATH, "utf8");
    for (const line of env.split("\n")) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith("#")) continue;
      const m = trimmed.match(/^([A-Z_][A-Z0-9_.]*?)\s*=\s*(.*)$/i);
      if (!m) continue;
      const key = m[1];
      const val = m[2].replace(/^["']|["']$/g, "");
      // Не перезаписываем уже заданные переменные и не пишем пустые строки
      if (!process.env[key] && val !== "") {
        process.env[key] = val;
      }
    }
  } catch {
    /* .env может отсутствовать */
  }
}

export function requireEnv(name) {
  const v = process.env[name]?.trim();
  if (!v) throw new Error(`Не задана переменная ${name} в .env`);
  return v;
}
