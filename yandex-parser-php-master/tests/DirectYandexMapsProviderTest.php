<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use YandexParser\Provider\BypassProxyClient;
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
    "coordinates":[38.976,45.044],
    "phones":[{"number":"+7 861 555-44-33","type":"phone","value":"+78615554433"}]
};
</script>
</head>
<body></body>
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

it('does not treat JSON-LD sameAs social profiles as the website', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(<<<'HTML'
<html>
<head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Кафе Пример",
  "sameAs": ["https://vk.com/cafe_example", "https://ok.ru/cafe_example"]
}
</script>
</head>
<body></body>
</html>
HTML, 'https://yandex.ru/maps/org/cafe_example/1111111111/', 'Краснодар');

    expect($data['website'])->toBeNull();
});

it('still extracts the JSON-LD url as website when sameAs also lists social profiles', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(<<<'HTML'
<html>
<head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Кафе Пример",
  "url": "https://cafe-example.ru",
  "sameAs": ["https://vk.com/cafe_example"]
}
</script>
</head>
<body></body>
</html>
HTML, 'https://yandex.ru/maps/org/cafe_example/1111111111/', 'Краснодар');

    expect($data['website'])->toBe('https://cafe-example.ru');
});

it('picks the real site from the "urls" array, not the page ad banner or aggregator sources', function () {
    $provider = makeDirectYandexMapsProviderParser();

    // Modeled on real Yandex Maps org page JSON: a site-wide ad banner ("profilePromo")
    // and cross-reference "sources" links both use "url"/"href" and appear BEFORE the
    // organization's own "urls" array - this is exactly what caused every scraped
    // organization to end up with the same website (the ad banner's URL).
    $data = $provider->parsePlacePage(
        '<html><body><script>'
        .'{"profilePromo":{"banner":{"url":"http://punkorama.ru/"}},'
        .'"sources":[{"id":"restoran_ru","name":"Restoran.ru","href":"http://www.restoran.ru"},'
        .'{"id":"kupikupon","name":"КупиКупон","href":"https://kupikupon.ru"}],'
        .'"urls":["http://www.r-bazar.ru/"]}'
        .'</script></body></html>',
        'https://yandex.ru/maps/org/rybny_bazar/1070354797/',
        'Москва',
    );

    expect($data['website'])->toBe('http://www.r-bazar.ru/');
});

it('finds no website when the "urls" array is empty and only ads/sources are present', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(
        '<html><body><script>'
        .'{"profilePromo":{"banner":{"url":"http://punkorama.ru/"}},'
        .'"sources":[{"id":"restoran_ru","name":"Restoran.ru","href":"http://www.restoran.ru"}],'
        .'"urls":[]}'
        .'</script></body></html>',
        'https://yandex.ru/maps/org/no_site_example/1000000001/',
        'Москва',
    );

    expect($data['website'])->toBeNull();
});

it('extracts phone numbers from the "phones" array and ignores page-wide numeric noise', function () {
    $provider = makeDirectYandexMapsProviderParser();

    // Modeled on a real Yandex Maps org page: hundreds of digit-heavy strings (SVG path
    // data, coordinates, tracking IDs) surround the real "phones" array elsewhere on the
    // page. A page-wide "any long digit sequence" regex previously matched all of that -
    // this asserts only the organization's own numbers, from its own "phones" array, come back.
    $data = $provider->parsePlacePage(
        '<html><body><script>'
        .'{"svgPath":"M0 .348-.036.509-.098.625.685.116 1.16.334 1.568.218.407.538.727.945.945",'
        .'"appmetricaId":"748922429992101554","photoId":"2a0000019234a1b2c3d4e5f6",'
        .'"phones":[{"number":"+7 (985) 260-54-44","type":"phone","value":"+79852605444","info":"Инфо"},'
        .'{"number":"+7 (495) 650-54-44","type":"phone","value":"+74956505444"}]}'
        .'</script></body></html>',
        'https://yandex.ru/maps/org/rybny_bazar/1070354797/',
        'Москва',
    );

    expect($data['phones'])->toBe(['+7 (985) 260-54-44', '+7 (495) 650-54-44'])
        ->and($data['phones'])->not->toContain('748922429992101554')
        ->and($data['phones'])->not->toContain('2a0000019234a1b2c3d4e5f6');
});

it('extracts rating, ratings count and review count from "ratingData"', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(
        '<html><body><script>'
        .'{"ratingData":{"ratingCount":5480,"ratingValue":4.9,"reviewCount":2624}}'
        .'</script></body></html>',
        'https://yandex.ru/maps/org/rybny_bazar/1070354797/',
        'Москва',
    );

    expect($data['rating'])->toBe(4.9)
        ->and($data['ratingsCount'])->toBe(5480)
        ->and($data['reviewCount'])->toBe(2624);
});

it('returns no rating fields when "ratingData" is absent', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(
        '<html><body></body></html>',
        'https://yandex.ru/maps/org/no_rating_example/1000000002/',
        'Москва',
    );

    expect($data['rating'])->toBeNull()
        ->and($data['ratingsCount'])->toBeNull()
        ->and($data['reviewCount'])->toBeNull();
});

it('skips a vk.com match from the regex fallback and returns no website when nothing else is found', function () {
    $provider = makeDirectYandexMapsProviderParser();

    $data = $provider->parsePlacePage(
        '<html><body><script>{"href":"https://vk.com/cafe_example"}</script></body></html>',
        'https://yandex.ru/maps/org/cafe_example/1111111111/',
        'Краснодар',
    );

    expect($data['website'])->toBeNull();
});

it('stops paginating and logs a blocked event on anti-bot captcha pages', function () {
    $mock = new MockHandler([
        new Response(200, [], '<html><body>Please wait... showCaptcha({"key":"1"})</body></html>'),
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
        maxResultsPerQuery: 5,
        logger: static function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    expect($places)->toHaveCount(0)
        ->and(array_column($events, 0))->toContain('query.blocked');
});

function makeMockedBypassProxy(array $responses, array &$history): BypassProxyClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $http = new HttpClient([
        'base_uri' => 'http://127.0.0.1:8765/',
        'handler' => $stack,
    ]);

    return new BypassProxyClient(http: $http);
}

it('falls back to the bypass proxy when a place page is blocked by anti-bot', function () {
    $mainMock = new MockHandler([
        new Response(200, [], '<a href="/maps/org/blocked_place/9988776655/">Blocked Place</a>'),
        new Response(200, [], '<html><body>showCaptcha({"key":"1"})</body></html>'),
    ]);

    $bypassHistory = [];
    $bypassProxy = makeMockedBypassProxy([
        new Response(200, [], '{"ok":true}'),
        new Response(200, [], json_encode([
            'status' => 200,
            'headers' => [],
            'body' => base64_encode('<script>{"website":"https:\/\/unblocked.example.ru","address":"Addr"}</script><title>Unblocked</title>'),
        ], JSON_THROW_ON_ERROR)),
    ], $bypassHistory);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mainMock),
        ]),
        delayMs: 0,
        bypassProxy: $bypassProxy,
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
        ->and($places[0]->website)->toBe('https://unblocked.example.ru')
        ->and(array_column($events, 0))->toContain('bypass_proxy.used')
        ->and($bypassHistory)->toHaveCount(2);
});

it('falls back to the bypass proxy when the search results page is blocked', function () {
    $mainMock = new MockHandler([
        new Response(200, [], '<html><body>showCaptcha({"key":"1"})</body></html>'),
        new Response(200, [], '<script>{"website":"https:\/\/place.example.ru"}</script><title>Place</title>'),
    ]);

    $bypassHistory = [];
    $bypassProxy = makeMockedBypassProxy([
        new Response(200, [], '{"ok":true}'),
        new Response(200, [], json_encode([
            'status' => 200,
            'headers' => [],
            'body' => base64_encode('<a href="/maps/org/unblocked_place/1231231234/">Unblocked Place</a>'),
        ], JSON_THROW_ON_ERROR)),
    ], $bypassHistory);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mainMock),
        ]),
        delayMs: 0,
        bypassProxy: $bypassProxy,
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
        ->and($places[0]->website)->toBe('https://place.example.ru')
        ->and(array_column($events, 0))->toContain('bypass_proxy.used')
        ->and($bypassHistory)->toHaveCount(2);
});

it('falls back to the bypass proxy when a place page request errors out', function () {
    $mainMock = new MockHandler([
        new Response(200, [], '<a href="/maps/org/errored_place/5544332211/">Errored Place</a>'),
        new Response(500, [], 'server error'),
    ]);

    $bypassHistory = [];
    $bypassProxy = makeMockedBypassProxy([
        new Response(200, [], '{"ok":true}'),
        new Response(200, [], json_encode([
            'status' => 200,
            'headers' => [],
            'body' => base64_encode('<script>{"website":"https:\/\/recovered.example.ru"}</script><title>Recovered</title>'),
        ], JSON_THROW_ON_ERROR)),
    ], $bypassHistory);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mainMock),
        ]),
        delayMs: 0,
        bypassProxy: $bypassProxy,
    );

    $places = $provider->collect(
        queries: ['кафе'],
        location: 'Краснодар',
        maxResultsPerQuery: 1,
    );

    expect($places)->toHaveCount(1)
        ->and($places[0]->website)->toBe('https://recovered.example.ru')
        ->and($bypassHistory)->toHaveCount(2);
});

it('subdivides the search area into a grid when a pass looks like it hit the ~60-result ceiling', function () {
    // Yandex's own city-wide search stops after ~60 results regardless of how many
    // organizations actually exist (confirmed directly against yandex.ru). 55 unique
    // orgs on the first page is enough to cross AREA_SUBDIVISION_TRIGGER and force a
    // grid of sub-area searches - each sub-area response below repeats the exact same
    // business IDs (nothing genuinely new there), so subdivision must stop at depth 1
    // and merged results must still dedupe down to exactly those 55 organizations.
    $links = [];
    for ($i = 1; $i <= 55; $i++) {
        $links[] = sprintf('<a href="/maps/org/place_%d/%d/">Place %d</a>', $i, 100000000 + $i, $i);
    }
    $cityPageOne = '<html><body>'.implode('', $links).'</body></html>';

    $mock = new MockHandler([
        new Response(200, [], $cityPageOne),
        new Response(200, [], '<html>No more</html>'),
        new Response(200, [], $cityPageOne),
        new Response(200, [], $cityPageOne),
        new Response(200, [], $cityPageOne),
        new Response(200, [], $cityPageOne),
    ]);
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => $stack,
        ]),
        delayMs: 0,
    );
    $events = [];
    $logger = static function (string $event, array $context) use (&$events): void {
        $events[] = [$event, $context];
    };

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('findOrganizationUrls');
    /** @var string[] $urls */
    $urls = $method->invoke($provider, 'кафе', 'Москва', 0, 50, $logger);

    expect($urls)->toHaveCount(55)
        ->and($history)->toHaveCount(6)
        ->and(array_column($events, 0))->toContain('query.area_expanded');
});

it('uses a geo-scoped search URL for known Russian cities instead of free text', function () {
    $mock = new MockHandler([
        new Response(200, [], '<html>No results</html>'),
    ]);
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => $stack,
        ]),
        delayMs: 0,
    );

    $provider->collect(queries: ['ресторан'], location: 'Москва', maxResultsPerQuery: 5);

    expect($history)->toHaveCount(1);

    $uri = $history[0]['request']->getUri();

    expect(rawurldecode($uri->getPath()))->toBe('/maps/213/city/search/ресторан/')
        ->and($uri->getQuery())->toBe('');
});

it('falls back to free-text search for locations without a known geoId', function () {
    $mock = new MockHandler([
        new Response(200, [], '<html>No results</html>'),
    ]);
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => $stack,
        ]),
        delayMs: 0,
    );

    $provider->collect(queries: ['restaurant'], location: 'Milan, Italy', maxResultsPerQuery: 5);

    expect($history)->toHaveCount(1);

    $uri = $history[0]['request']->getUri();
    parse_str($uri->getQuery(), $queryParams);

    expect($uri->getPath())->toBe('/maps/')
        ->and($queryParams['text'])->toBe('restaurant Milan, Italy');
});

it('does not abort the whole run when a search page request fails mid-pagination', function () {
    $mock = new MockHandler([
        new Response(200, [], '<a href="/maps/org/place_one/111111/">One</a>'),
        new Response(500, [], 'server error'),
        new Response(500, [], 'server error (retry also fails)'),
        new Response(200, [], '<script>{"website":"https:\/\/one.example.ru"}</script><title>One</title>'),
        new Response(200, [], '<a href="/maps/org/place_two/222222/">Two</a>'),
        new Response(200, [], '<html>No more places</html>'),
        new Response(200, [], '<script>{"website":"https:\/\/two.example.ru"}</script><title>Two</title>'),
    ]);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mock),
        ]),
        delayMs: 0,
        sleeper: static function (int $ms): void {},
    );
    $events = [];

    $places = $provider->collect(
        queries: ['кафе', 'бар'],
        location: 'Москва',
        maxResultsPerQuery: 0,
        options: ['maxPagesPerQuery' => 5],
        logger: static function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $websites = array_map(static fn ($place): ?string => $place->website, $places);

    expect($places)->toHaveCount(2)
        ->and($websites)->toContain('https://one.example.ru')
        ->and($websites)->toContain('https://two.example.ru')
        ->and(array_column($events, 0))->toContain('query.error')
        ->and(array_column($events, 0))->toContain('query.finished');
});

it('deduplicates same-organization alternate-domain urls during discovery to avoid repeat fetches', function () {
    // Real Yandex Maps org pages carry <link rel="canonical"/"alternate"> variants of the
    // SAME organization on yandex.ru/.com/.kz/... - without business-id-based dedup, each
    // one is (wrongly) treated as a distinct search result and fetched separately.
    $mock = new MockHandler([
        new Response(200, [], implode('', [
            '<link rel="canonical" href="https://yandex.ru/maps/org/restoran_moskva/1183998015/">',
            '<link rel="alternate" href="https://yandex.com/maps/org/restoran_moskva/1183998015/" hreflang="en">',
            '<link rel="alternate" href="https://yandex.kz/maps/org/restoran_moskva/1183998015/" hreflang="kk">',
        ])),
        new Response(200, [], '<html>No more places</html>'),
        new Response(200, [], '<script>{"website":"https:\/\/example.ru"}</script><title>One</title>'),
    ]);

    $provider = new DirectYandexMapsProvider(
        http: new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'handler' => HandlerStack::create($mock),
        ]),
        delayMs: 0,
    );

    // Only 3 responses are queued above (2 search pages + 1 place fetch). If the alternate
    // domains weren't deduplicated, the pool would try to fetch 3 place pages and this
    // would fail with an empty mock queue instead of asserting the wrong place count.
    $places = $provider->collect(
        queries: ['ресторан'],
        location: 'Москва',
        maxResultsPerQuery: 0,
        options: ['maxPagesPerQuery' => 5],
    );

    expect($places)->toHaveCount(1);
});

it('fetches organization pages concurrently when concurrency is greater than one', function () {
    $mock = new MockHandler([
        new Response(200, [], '<a href="/maps/org/place_one/111111/">One</a><a href="/maps/org/place_two/222222/">Two</a>'),
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
        concurrency: 2,
    );

    $places = $provider->collect(
        queries: ['кафе'],
        location: 'Краснодар',
        maxResultsPerQuery: 0,
        options: ['maxPagesPerQuery' => 5],
    );

    $websites = array_map(static fn ($place): ?string => $place->website, $places);

    expect($places)->toHaveCount(2)
        ->and($websites)->toContain('https://one.example.ru')
        ->and($websites)->toContain('https://two.example.ru');
});
