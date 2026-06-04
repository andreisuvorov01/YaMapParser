<?php

declare(strict_types=1);

use YandexParser\Provider\DirectYandexMapsProvider;

it('extracts organization urls from direct Yandex Maps HTML', function () {
    $provider = new DirectYandexMapsProvider(delayMs: 0);

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
    $provider = new DirectYandexMapsProvider(delayMs: 0);

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
