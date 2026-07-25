// Склонение названий городов для корректных русскоязычных текстов.
//
// Для известных городов падежи заданы явно в пресетах (lib/cities.ts), что
// гарантирует точность. Для произвольных названий (например, из обратного
// геокодера) применяется безопасный fallback на исходное имя — лучше
// несклонённое название, чем грамматически неверное.

interface CityCaseSource {
  name: string;
  prepositional?: string;
  genitive?: string;
}

// Предложный падеж: «в Москве», «в Санкт-Петербурге».
export function cityPrepositional(city: CityCaseSource): string {
  return city.prepositional?.trim() || city.name;
}

// Родительный падеж: «АЗС Москвы», «карту Краснодара».
export function cityGenitive(city: CityCaseSource): string {
  return city.genitive?.trim() || city.name;
}
