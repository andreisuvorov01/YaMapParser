# Yandex Parser PHP

[English](https://github.com/Scraper-APIs/yandex-scraper-php) | **Русский**

PHP-библиотека для парсинга данных Яндекса: организации и места (Яндекс Карты), отзывы (Яндекс Карты), товары (Яндекс Маркет), недвижимость (Яндекс Недвижимость).

Работает через [Apify API](https://apify.com/) — запускает акторы и возвращает типизированные DTO.

## Установка

```bash
composer require scraper-apis/yandex-parser
```

## Быстрый старт

```php
use YandexParser\Client;

$client = new Client('apify_api_ваш_токен');

// Поиск ресторанов в Москве
$places = $client->scrapePlaces(
    query: ['ресторан'],
    location: 'Москва',
    maxResults: 50,
);

foreach ($places as $place) {
    echo "{$place->title} — {$place->address}" . PHP_EOL;
    echo "Рейтинг: {$place->rating}, отзывов: {$place->reviewCount}" . PHP_EOL;

    if ($place->hasContactInfo()) {
        echo "Тел: {$place->getFirstPhone()}" . PHP_EOL;
    }
}
```


## CLI: сбор организаций с сайтами

После установки зависимостей CLI доступен как `vendor/bin/yandex-parser` при установке пакета через Composer или как `php bin/yandex-parser` при запуске из репозитория.

### Вариант 1: через Apify actor

Это более стабильный вариант: библиотека использует actor `zen-studio/yandex-places-scraper`, ждёт dataset и сохраняет только карточки с `website`. Для запуска нужен токен Apify. У Apify есть свои тарифы/лимиты, поэтому стоимость зависит от вашего аккаунта и выбранного actor.

```bash
composer install
export APIFY_TOKEN="apify_api_ваш_токен"

php bin/yandex-parser collect:websites \
  --provider=apify \
  --location="Краснодар" \
  --queries="ресторан,кафе,стоматология,клиника,салон красоты,автосервис,отель" \
  --max-results-per-query=500 \
  --checkpoint-interval=30 \
  --checkpoint-every=25 \
  --output=krasnodar_websites.csv
```

### Вариант 2: без Apify API

Если Apify не подходит или платный лимит исчерпан, можно попробовать экспериментальный direct-provider. Он не требует `APIFY_TOKEN` и парсит публичные HTML-страницы Яндекс Карт. Этот режим менее стабильный: Яндекс может менять разметку, ограничивать частоту запросов или не отдавать сайт в HTML. Используйте его только там, где это соответствует применимым правилам и законам.

```bash
composer install

php bin/yandex-parser collect:websites \
  --provider=direct \
  --location="Краснодар" \
  --queries="ресторан,кафе,стоматология,клиника,салон красоты,автосервис,отель" \
  --max-results-per-query=0 \
  --max-pages-per-query=50 \
  --delay-ms=1000 \
  --checkpoint-interval=30 \
  --output=krasnodar_websites_direct.csv
```

### Файл рубрик

Для более полного сбора используйте готовый пример `examples/queries-krasnodar.txt` или заведите свой файл `queries-krasnodar.txt` — по одной рубрике на строку:

```text
ресторан
кафе
стоматология
клиника
салон красоты
автосервис
отель
юридические услуги
агентство недвижимости
детский сад
```

И запустите:

```bash
php bin/yandex-parser collect:websites \
  --provider=apify \
  --location="Краснодар" \
  --queries-file=examples/queries-krasnodar.txt \
  --max-results-per-query=500 \
  --checkpoint-interval=30 \
  --checkpoint-every=25 \
  --output=krasnodar_websites.csv
```

CSV содержит: `business_id`, `title`, `city`, `address`, `website`, `website_host`, `phone`, `rating`, `reviews`, `yandex_maps_url`.

Во время работы CLI пишет progress-лог в STDERR: текущую рубрику, сколько URL карточек найдено, какую карточку сейчас открывает, какие организации сохранены, какие пропущены и какие оказались дублями. Если нужен тихий режим для cron/пайплайна, добавьте `--quiet`. Чтобы CSV не терялся при ошибках, CLI периодически перезаписывает файл: `--checkpoint-interval=30` сохраняет не реже раза в 30 секунд, а `--checkpoint-every=25` — каждые 25 новых строк.

Пример логов:

```text
[12:00:01] [direct] query 1/7 started: "ресторан" in "Краснодар" (limit=unlimited)
[12:00:02] [direct] page 1 loaded for "ресторан": urls=10, new=10, total=10
[12:00:03] [direct] found 42 organization urls for "ресторан"
[12:00:04] [direct] card 1/42: https://yandex.ru/maps/org/...
[12:00:05] [direct] saved #1: Название организации — https://example.ru
[12:00:06] checkpoint saved: krasnodar_websites_direct.csv (25 rows)
```

> Важно: «все организации города» технически собираются как агрегация по рубрикам/запросам. Чем шире список рубрик, тем больше покрытие; CLI дедуплицирует карточки по `businessId`. Для direct-провайдера `--max-results-per-query=0` означает «без локального лимита»: CLI будет листать выдачу, пока не получит страницу без новых карточек или пока не достигнет safety-лимита `--max-pages-per-query`.

## Методы

### Организации (Яндекс Карты)

```php
use YandexParser\Language;

$places = $client->scrapePlaces(
    query: ['стоматология', 'клиника'],
    location: 'Санкт-Петербург',
    maxResults: 200,
    language: Language::Russian,
    options: [
        'filterRating' => 4.5,
        'filterOpenNow' => true,
        'filterCardPayment' => true,
        'maxPhotos' => 5,
    ],
);
```

### Организации с сайтами в карточке (Яндекс Карты)

Для лидогенерации можно сразу отфильтровать только те карточки, где Яндекс Карты вернули поле `website`. Метод `scrapePlacesWithWebsites()` сам включает `enrichBusinessData => true` по умолчанию и возвращает только `Place` с непустым сайтом. Для нескольких рубрик используйте `collectPlacesWithWebsites()` — он запускает отдельный поиск по каждой рубрике и дедуплицирует результат по `businessId`.

```php
use YandexParser\Language;

// Милан, Прага или Краснодар — меняйте только location.
$places = $client->collectPlacesWithWebsites(
    queries: [
        'restaurant',
        'hotel',
        'clinic',
        'beauty salon',
        'car service',
        'real estate agency',
    ],
    location: 'Milan, Italy', // или 'Prague, Czechia', 'Краснодар'
    maxResultsPerQuery: 500,
    language: Language::Auto,
    options: [
        // Дополнительные фильтры актора можно передавать как в scrapePlaces().
        'filterRating' => 4.0,
        'maxPhotos' => 0,
    ],
);

foreach ($places as $place) {
    echo implode(';', [
        $place->title,
        $place->city,
        $place->address,
        $place->website,
        $place->getWebsiteHost(),
        $place->getFirstPhone(),
        $place->url,
    ]) . PHP_EOL;
}
```

> Важно: «все организации города» — это практическая агрегация по рубрикам/поисковым запросам. Чем шире список `queries`, тем полнее покрытие; один поисковый запрос обычно ограничен выдачей Яндекс Карт и настройками `maxResultsPerQuery` / `--max-pages-per-query`.

### Отзывы (Яндекс Карты)

```php
use YandexParser\ReviewSort;

$reviews = $client->scrapeReviews(
    startUrls: ['https://yandex.ru/maps/org/pushkin/1124715036/'],
    maxReviewsPerPlace: 100,
    reviewSort: ReviewSort::Newest,
    minRating: 1,
    maxRating: 3,
);

foreach ($reviews as $review) {
    echo "{$review->authorName}: {$review->rating}/5" . PHP_EOL;

    if ($review->hasBusinessReply()) {
        echo "Ответ: {$review->getBusinessReplyText()}" . PHP_EOL;
    }
}
```

### Товары (Яндекс Маркет)

```php
use YandexParser\MarketSort;
use YandexParser\MarketRegion;

$products = $client->scrapeProducts(
    query: 'ноутбук ASUS',
    maxItems: 50,
    region: MarketRegion::Moscow,
    sort: MarketSort::PriceAsc,
    options: [
        'priceFrom' => 30000,
        'priceTo' => 80000,
    ],
);

foreach ($products as $product) {
    echo "{$product->title} — {$product->getPriceFormatted()}" . PHP_EOL;
    echo "Продавец: {$product->sellerName}, рейтинг: {$product->rating}" . PHP_EOL;

    $discount = $product->getYaBankDiscount();
    if ($discount !== null) {
        echo "Скидка по Я.Банку: {$discount}%" . PHP_EOL;
    }
}
```

### Недвижимость (Яндекс Недвижимость)

```php
use YandexParser\DealType;
use YandexParser\PropertyCategory;
use YandexParser\RealtySort;

$listings = $client->scrapeListings(
    location: 'Москва',
    dealType: DealType::Sell,
    category: PropertyCategory::Apartment,
    maxItems: 50,
    sort: RealtySort::PriceAsc,
    roomsTotal: ['1', '2'],
    options: [
        'priceMin' => 5000000,
        'priceMax' => 15000000,
    ],
);

foreach ($listings as $listing) {
    echo "{$listing->getAddress()} — {$listing->getPriceValue()} ₽" . PHP_EOL;
    echo "Площадь: {$listing->getAreaValue()} м², этаж: {$listing->floorsOffered[0] ?? '?'}/{$listing->floorsTotal}" . PHP_EOL;

    if ($listing->hasPhones()) {
        echo "Тел: {$listing->getFirstPhone()}" . PHP_EOL;
    }

    if (!$listing->isFromOwner()) {
        echo "Агентство: {$listing->getSellerName()}" . PHP_EOL;
    }
}
```

## Акторы Apify

| Направление | Метод | DTO | Actor ID |
|-------------|-------|-----|----------|
| Организации и места Яндекс Карт | `scrapePlaces()` / `scrapePlacesWithWebsites()` / `collectPlacesWithWebsites()` | `YandexParser\DTO\Place` | `zen-studio/yandex-places-scraper` |
| Отзывы Яндекс Карт | `scrapeReviews()` | `YandexParser\DTO\Review` | `zen-studio/yandex-reviews-scraper` |
| Товары Яндекс Маркета | `scrapeProducts()` | `YandexParser\DTO\Product` | `zen-studio/yandex-market-scraper-parser` |
| Объявления Яндекс Недвижимости | `scrapeListings()` | `YandexParser\DTO\Listing` | `zen-studio/yandex-realty-scraper` |

## Перечисления

| Enum | Значения |
|------|----------|
| `Language` | `Auto`, `Russian`, `English`, `Turkish`, `Ukrainian`, `Kazakh` |
| `ReviewSort` | `Relevance`, `Newest`, `Highest`, `Lowest` |
| `MarketSort` | `Default`, `Popular`, `PriceAsc`, `PriceDesc`, `Rating` |
| `MarketRegion` | `Moscow`, `SaintPetersburg`, `Yekaterinburg`, `Kazan`, `Novosibirsk`, `NizhnyNovgorod`, `Samara`, `RostovOnDon`, `Krasnodar`, `Chelyabinsk`, `Ufa`, `Perm`, `Voronezh`, `Volgograd`, `Krasnoyarsk`, `Omsk` |
| `DealType` | `Sell`, `Rent` |
| `PropertyCategory` | `Apartment`, `Rooms`, `House`, `Lot`, `Commercial`, `Garage` |
| `RealtySort` | `Relevance`, `Newest`, `PriceAsc`, `PriceDesc`, `AreaAsc`, `AreaDesc`, `CommissioningDate` |

## Конфигурация

```php
use YandexParser\Client;
use YandexParser\Config;

// Изменить таймаут или базовый URL
$client = new Client('токен', new Config(
    apiToken: 'токен',
    timeout: 600,
));
```

## Обработка ошибок

```php
use YandexParser\Exception\ApiException;
use YandexParser\Exception\RateLimitException;

try {
    $places = $client->scrapePlaces(query: ['кафе'], location: 'Казань');
} catch (RateLimitException $e) {
    sleep($e->retryAfter);
    // повторить запрос
} catch (ApiException $e) {
    echo "Ошибка API: {$e->getMessage()}" . PHP_EOL;
}
```

## Требования

- PHP 8.3+
- Токен [Apify API](https://console.apify.com/account/integrations)

## См. также

- [2GIS Parser PHP](https://github.com/Scraper-APIs/2gis-parser-php) — парсинг 2ГИС (организации и отзывы, недвижимость, вакансии)

## Лицензия

MIT
