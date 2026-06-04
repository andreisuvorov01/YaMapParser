<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use YandexParser\Provider\DirectYandexMapsProvider;

function makeDirectYandexMapsProviderParser(): DirectYandexMapsProvider
{
    $reflection = new ReflectionClass(DirectYandexMapsProvider::class);

    /** @var DirectYandexMapsProvider $provider */
    $provider = $reflection->newInstanceWithoutConstructor();

    return $provider;
}

it('extracts organization urls from direct Yandex Maps HTML', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $urls = $provider->extractOrganizationUrls(<<<'HTML'
<html>
<a href="/maps/org/test_place/1234567890/">Test</a>
<script>{"url":"https:\/\/yandex.ru\/maps\/org\/another_place\/9876543210\/"}</script>
</html>
HTML);

    expect($urls)->toBe([
        'https://yandex.ru/maps/org/test_place/1234567890/',
        'https://yandex.ru/maps/org/another_place/9876543210/',
    ]);
});

it('parses place data from direct Yandex Maps organization page HTML', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(<<<'HTML'
<html>
<head>
<title>Fallback title</title>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Краснодар Клиника",
  "url": "https://www.clinic-example.ru/services",
  "telephone": "+7 861 123-45-67",
  "address": {
    "streetAddress": "Красная, 1",
    "addressLocality": "Краснодар",
    "postalCode": "350000"
  },
  "geo": {
    "latitude": 45.035470,
    "longitude": 38.975313
  }
}
</script>
</head>
<body></body>
</html>
HTML, 'https://yandex.ru/maps/org/krasnodar_klinika/1234567890/', 'Краснодар');

    expect($data['businessId'])->toBe('1234567890')
        ->and($data['title'])->toBe('Краснодар Клиника')
        ->and($data['website'])->toBe('https://www.clinic-example.ru/services')
        ->and($data['address'])->toBe('Красная, 1, Краснодар, 350000')
        ->and($data['phones'])->toContain('+7 861 123-45-67')
        ->and($data['latitude'])->toBe(45.035470)
        ->and($data['longitude'])->toBe(38.975313);
});

it('parses fallback website and address fields without regex warnings', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(<<<'HTML'
<html>
<head>
<meta property="og:title" content="Краснодар Сервис">
<script>
window.__INITIAL_STATE__ = {
    "website":"https:\/\/www.service-example.ru\/contacts",
    "address":"Краснодар, Северная, 10",
    "coordinates":[38.976,45.044]
};
</script>
</head>
<body>+7 861 555-44-33</body>
</html>
HTML, 'https://yandex.ru/maps/org/krasnodar_servis/2233445566/', 'Краснодар');

    expect($data['businessId'])->toBe('2233445566')
        ->and($data['title'])->toBe('Краснодар Сервис')
        ->and($data['website'])->toBe('https://www.service-example.ru/contacts')
        ->and($data['address'])->toBe('Краснодар, Северная, 10')
        ->and($data['phones'])->toContain('+7 861 555-44-33')
        ->and($data['longitude'])->toBe(38.976)
        ->and($data['latitude'])->toBe(45.044);
});

it('emits progress logs while collecting direct places', function () {
    $mock = new MockHandler([
        new Response(200, [], '<a href="/maps/org/krasnodar_servis/2233445566/">Краснодар Сервис</a>'),
        new Response(200, [], <<<'HTML'
<html>
<head>
<meta property="og:title" content="Краснодар Сервис">
<script>{"website":"https:\/\/www.service-example.ru\/contacts","address":"Краснодар, Северная, 10"}</script>
</head>
<body>+7 861 555-44-33</body>
</html>
HTML),
    ]);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mock),
        ]),
        delayMs: 0,
    );
    $events = [];

    $places = $provider->collect(
        queries: ['кафе'],
        location: 'Краснодар',
        maxResultsPerQuery: 1,
        logger: static function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    expect($places)->toHaveCount(1)
        ->and(array_column($events, 0))->toContain('query.started')
        ->and(array_column($events, 0))->toContain('query.urls_found')
        ->and(array_column($events, 0))->toContain('place.fetching')
        ->and(array_column($events, 0))->toContain('place.saved')
        ->and(array_column($events, 0))->toContain('query.finished')
        ->and($events[3][1]['website'])->toBe('https://www.service-example.ru/contacts');
});

it('loads direct result pages until an empty page when query limit is unlimited', function () {
    $mock = new MockHandler([
        new Response(200, [], '<a href="/maps/org/place_one/111111/">One</a>'),
        new Response(200, [], '<a href="/maps/org/place_two/222222/">Two</a>'),
        new Response(200, [], '<html>No more places</html>'),
        new Response(200, [], '<script>{"website":"https:\/\/one.example.ru","address":"Addr 1"}</script><title>One</title>'),
        new Response(200, [], '<script>{"website":"https:\/\/two.example.ru","address":"Addr 2"}</script><title>Two</title>'),
    ]);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mock),
        ]),
        delayMs: 0,
    );
    $events = [];

    $places = $provider->collect(
        queries: ['кафе'],
        location: 'Краснодар',
        maxResultsPerQuery: 0,
        options: ['maxPagesPerQuery' => 5],
        logger: static function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $pageEvents = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'query.page_loaded'));

    expect($places)->toHaveCount(2)
        ->and(array_map(static fn ($place): ?string => $place->website, $places))->toBe(['https://one.example.ru', 'https://two.example.ru'])
        ->and($pageEvents)->toHaveCount(3)
        ->and($pageEvents[0][1]['newUrlsOnPage'])->toBe(1)
        ->and($pageEvents[1][1]['newUrlsOnPage'])->toBe(1)
        ->and($pageEvents[2][1]['newUrlsOnPage'])->toBe(0);
});
