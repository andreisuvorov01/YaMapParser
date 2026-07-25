<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use YandexParser\Client;
use YandexParser\Config;
use YandexParser\DTO\Place;
use YandexParser\Exception\ApiException;
use YandexParser\Exception\RateLimitException;
use YandexParser\Language;
use YandexParser\Provider\ApifyPlacesProvider;

function makeMockedClient(array $responses, array &$history, ?Config $config = null): Client
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $http = new HttpClient([
        'base_uri' => 'https://api.apify.com/v2/',
        'handler' => $stack,
    ]);

    return new Client(
        apiToken: 'test_token',
        config: $config ?? new Config('test_token'),
        http: $http,
        sleeper: static function (int $seconds): void {},
    );
}

function samplePlacesRunResponse(string $datasetId = 'dataset-123', string $runId = 'run-1'): Response
{
    return new Response(200, [], json_encode([
        'data' => ['id' => $runId, 'status' => 'SUCCEEDED', 'defaultDatasetId' => $datasetId],
    ], JSON_THROW_ON_ERROR));
}

it('scrapes places with websites and filters places without websites', function () {
    $history = [];
    $placeWithWebsite = getSamplePlaceData();
    $placeWithoutWebsite = getSamplePlaceData();
    $placeWithoutWebsite['businessId'] = 'without-website';
    $placeWithoutWebsite['website'] = null;

    $client = makeMockedClient([
        samplePlacesRunResponse(),
        new Response(200, [], json_encode([
            $placeWithWebsite,
            $placeWithoutWebsite,
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    $places = $client->scrapePlacesWithWebsites(
        query: ['restaurant', 'hotel'],
        location: 'Milan, Italy',
        maxResults: 50,
        language: Language::Auto,
    );

    expect($places)->toHaveCount(1)
        ->and($places[0])->toBeInstanceOf(Place::class)
        ->and($places[0]->businessId)->toBe('1124715036')
        ->and($places[0]->website)->toBe('https://cafe-pushkin.ru')
        ->and($history)->toHaveCount(2)
        ->and((string) $history[0]['request']->getUri())->toBe('https://api.apify.com/v2/acts/zen-studio/yandex-places-scraper/runs?waitForFinish=300')
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[1]['request']->getUri())->toBe('https://api.apify.com/v2/datasets/dataset-123/items')
        ->and($history[1]['request']->getMethod())->toBe('GET');

    $input = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($input)->toMatchArray([
        'query' => ['restaurant', 'hotel'],
        'location' => 'Milan, Italy',
        'maxResults' => 50,
        'enrichBusinessData' => true,
    ])
        ->and(array_key_exists('language', $input))->toBeFalse();
});

it('keeps explicit places-with-websites options', function () {
    $history = [];

    $client = makeMockedClient([
        samplePlacesRunResponse(),
        new Response(200, [], json_encode([
            getSamplePlaceData(),
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    $client->scrapePlacesWithWebsites(
        query: ['clinic'],
        location: 'Prague, Czechia',
        options: ['enrichBusinessData' => false, 'filterOpenNow' => true],
    );

    $input = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($input['enrichBusinessData'])->toBeFalse()
        ->and($input['filterOpenNow'])->toBeTrue();
});

it('collects places with websites from several queries and deduplicates by business id', function () {
    $history = [];
    $firstPlace = getSamplePlaceData();
    $duplicatePlace = getSamplePlaceData();
    $duplicatePlace['title'] = 'Pushkin Duplicate';
    $secondPlace = getSamplePlaceData();
    $secondPlace['businessId'] = 'second-place';
    $secondPlace['title'] = 'Second Place';
    $secondPlace['website'] = 'https://second.example';

    $client = makeMockedClient([
        samplePlacesRunResponse(datasetId: 'dataset-1', runId: 'run-1'),
        new Response(200, [], json_encode([$firstPlace], JSON_THROW_ON_ERROR)),
        samplePlacesRunResponse(datasetId: 'dataset-2', runId: 'run-2'),
        new Response(200, [], json_encode([$duplicatePlace, $secondPlace], JSON_THROW_ON_ERROR)),
    ], $history);

    $places = $client->collectPlacesWithWebsites(
        queries: ['restaurant', 'hotel'],
        location: 'Краснодар',
        maxResultsPerQuery: 25,
    );

    expect($places)->toHaveCount(2)
        ->and(array_map(static fn (Place $place): string => $place->businessId, $places))
        ->toBe(['1124715036', 'second-place'])
        ->and($history)->toHaveCount(4);

    $firstInput = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $secondInput = json_decode((string) $history[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($firstInput['query'])->toBe(['restaurant'])
        ->and($firstInput['location'])->toBe('Краснодар')
        ->and($firstInput['maxResults'])->toBe(25)
        ->and($secondInput['query'])->toBe(['hotel']);
});

it('emits progress logs while collecting Apify places with websites', function () {
    $history = [];
    $place = getSamplePlaceData();

    $client = makeMockedClient([
        samplePlacesRunResponse(),
        new Response(200, [], json_encode([$place], JSON_THROW_ON_ERROR)),
    ], $history);
    $provider = new ApifyPlacesProvider($client);
    $events = [];

    $places = $provider->collect(
        queries: ['ресторан'],
        location: 'Краснодар',
        maxResultsPerQuery: 10,
        logger: static function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    expect($places)->toHaveCount(1)
        ->and(array_column($events, 0))->toBe(['query.started', 'query.finished', 'place.saved'])
        ->and($events[0][1]['provider'])->toBe('apify')
        ->and($events[0][1]['query'])->toBe('ресторан')
        ->and($events[2][1]['website'])->toBe('https://cafe-pushkin.ru');
});

it('polls the actor run until it reaches a terminal status before fetching the dataset', function () {
    $history = [];

    $client = makeMockedClient([
        new Response(200, [], json_encode([
            'data' => ['id' => 'run-1', 'status' => 'RUNNING', 'defaultDatasetId' => null],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'data' => ['id' => 'run-1', 'status' => 'SUCCEEDED', 'defaultDatasetId' => 'dataset-123'],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([getSamplePlaceData()], JSON_THROW_ON_ERROR)),
    ], $history);

    $places = $client->scrapePlaces(query: ['restaurant'], location: 'Moscow');

    expect($places)->toHaveCount(1)
        ->and($history)->toHaveCount(3)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[1]['request']->getUri())->toBe('https://api.apify.com/v2/actor-runs/run-1')
        ->and($history[1]['request']->getMethod())->toBe('GET')
        ->and((string) $history[2]['request']->getUri())->toBe('https://api.apify.com/v2/datasets/dataset-123/items');
});

it('throws an ApiException when the actor run finishes with a non-success status', function () {
    $history = [];

    $client = makeMockedClient([
        new Response(200, [], json_encode([
            'data' => ['id' => 'run-1', 'status' => 'FAILED', 'defaultDatasetId' => 'dataset-123'],
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    expect(fn () => $client->scrapePlaces(query: ['restaurant'], location: 'Moscow'))
        ->toThrow(ApiException::class, 'FAILED');
});

it('throws an ApiException on malformed JSON responses', function () {
    $history = [];

    $client = makeMockedClient([
        new Response(200, [], 'not json'),
    ], $history);

    expect(fn () => $client->scrapePlaces(query: ['restaurant'], location: 'Moscow'))
        ->toThrow(ApiException::class, 'Invalid API response');
});

it('automatically retries a rate-limited request and succeeds', function () {
    $history = [];

    $client = makeMockedClient([
        new Response(429, ['Retry-After' => '0']),
        samplePlacesRunResponse(),
        new Response(200, [], json_encode([getSamplePlaceData()], JSON_THROW_ON_ERROR)),
    ], $history);

    $places = $client->scrapePlaces(query: ['restaurant'], location: 'Moscow');

    expect($places)->toHaveCount(1)
        ->and($history)->toHaveCount(3);
});

it('throws a RateLimitException once retries are exhausted', function () {
    $history = [];
    $config = new Config('test_token', maxRetries: 1);

    $client = makeMockedClient([
        new Response(429, ['Retry-After' => '5']),
        new Response(429, ['Retry-After' => '5']),
    ], $history, $config);

    expect(fn () => $client->scrapePlaces(query: ['restaurant'], location: 'Moscow'))
        ->toThrow(RateLimitException::class);
    expect($history)->toHaveCount(2);
});
