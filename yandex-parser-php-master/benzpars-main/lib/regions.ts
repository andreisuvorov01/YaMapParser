// Статичный список крупных регионов (субъектов РФ) для индексной SEO-страницы.
// Где есть город-пресет — ссылаемся на его страницу /azs/<slug>,
// иначе ведём на карту.

export interface RegionEntry {
  name: string; // название субъекта РФ
  citySlug?: string; // слаг города-пресета (если есть страница /azs/...)
  cityName?: string; // подпись главного города
}

export const REGIONS: RegionEntry[] = [
  { name: "Москва", citySlug: "moskva", cityName: "Москва" },
  {
    name: "Санкт-Петербург",
    citySlug: "sankt-peterburg",
    cityName: "Санкт-Петербург",
  },
  { name: "Краснодарский край", citySlug: "krasnodar", cityName: "Краснодар" },
  {
    name: "Ростовская область",
    citySlug: "rostov-na-donu",
    cityName: "Ростов-на-Дону",
  },
  { name: "Воронежская область", citySlug: "voronezh", cityName: "Воронеж" },
  { name: "Волгоградская область", citySlug: "volgograd", cityName: "Волгоград" },
  {
    name: "Свердловская область",
    citySlug: "ekaterinburg",
    cityName: "Екатеринбург",
  },
  { name: "Республика Татарстан", citySlug: "kazan", cityName: "Казань" },
  { name: "Московская область" },
  { name: "Ленинградская область" },
  { name: "Нижегородская область" },
  { name: "Самарская область" },
  { name: "Республика Башкортостан" },
  { name: "Челябинская область" },
  { name: "Новосибирская область" },
  { name: "Красноярский край" },
  { name: "Пермский край" },
  { name: "Ставропольский край" },
  { name: "Тюменская область" },
  { name: "Саратовская область" },
  { name: "Республика Крым" },
  { name: "Иркутская область" },
];
