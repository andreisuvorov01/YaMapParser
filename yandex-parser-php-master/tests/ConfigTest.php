<?php

declare(strict_types=1);

use YandexParser\Config;

it('has correct default values', function () {
    $config = new Config('test_api_token');

    expect($config->apiToken)->toBe('test_api_token')
        ->and($config->baseUrl)->toBe('https://api.apify.com/v2')
        ->and($config->timeout)->toBe(900)
        ->and($config->maxRetries)->toBe(3)
        ->and($config->proxy)->toBeNull();
});

it('accepts custom values', function () {
    $config = new Config(
        apiToken: 'custom_token',
        baseUrl: 'https://custom.api.com/v2',
        timeout: 600,
        maxRetries: 5,
        proxy: 'http://user:pass@host:8080',
    );

    expect($config->apiToken)->toBe('custom_token')
        ->and($config->baseUrl)->toBe('https://custom.api.com/v2')
        ->and($config->timeout)->toBe(600)
        ->and($config->maxRetries)->toBe(5)
        ->and($config->proxy)->toBe('http://user:pass@host:8080');
});

it('has hardcoded actor IDs', function () {
    expect(Config::PLACES_ACTOR_ID)->toBe('zen-studio/yandex-places-scraper')
        ->and(Config::REVIEWS_ACTOR_ID)->toBe('zen-studio/yandex-reviews-scraper')
        ->and(Config::MARKET_ACTOR_ID)->toBe('zen-studio/yandex-market-scraper-parser')
        ->and(Config::REALTY_ACTOR_ID)->toBe('zen-studio/yandex-realty-scraper');
});
