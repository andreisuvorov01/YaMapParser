<?php

declare(strict_types=1);

use YandexParser\Console\CollectWebsitesCommand;
use YandexParser\DTO\Place;

it('writes checkpoint csv while logging saved places', function () {
    $command = new CollectWebsitesCommand;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('makeLogger');
    $checkpointPlacesByKey = [];
    $lastCheckpointAt = time();
    $completedQueries = [];
    $output = tempnam(sys_get_temp_dir(), 'yandex-parser-checkpoint-');
    $statePath = tempnam(sys_get_temp_dir(), 'yandex-parser-state-');

    expect($output)->not->toBeFalse()
        ->and($statePath)->not->toBeFalse();

    /** @var callable $logger */
    $logger = $method->invokeArgs($command, [
        true,
        $output,
        0,
        1,
        &$checkpointPlacesByKey,
        &$lastCheckpointAt,
        $statePath,
        &$completedQueries,
    ]);

    $logger('place.saved', [
        'provider' => 'direct',
        'title' => 'Checkpoint Place',
        'website' => 'https://checkpoint.example.ru',
        'totalSaved' => 1,
        'place' => Place::fromArray([
            'businessId' => 'checkpoint-1',
            'title' => 'Checkpoint Place',
            'city' => 'Краснодар',
            'address' => 'Краснодар, Тестовая, 1',
            'website' => 'https://checkpoint.example.ru',
            'url' => 'https://yandex.ru/maps/org/checkpoint/123/',
        ]),
    ]);

    $csv = file_get_contents($output);
    @unlink($output);
    @unlink($statePath);

    expect($csv)->not->toBeFalse()
        ->and($csv)->toContain('website')
        ->and($csv)->toContain('https://checkpoint.example.ru')
        ->and($checkpointPlacesByKey)->toHaveCount(1);
});

it('marks a query as completed in the state file once it finishes', function () {
    $command = new CollectWebsitesCommand;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('makeLogger');
    $checkpointPlacesByKey = [];
    $lastCheckpointAt = time();
    $completedQueries = [];
    $output = tempnam(sys_get_temp_dir(), 'yandex-parser-checkpoint-');
    $statePath = tempnam(sys_get_temp_dir(), 'yandex-parser-state-');

    /** @var callable $logger */
    $logger = $method->invokeArgs($command, [
        true,
        $output,
        0,
        1,
        &$checkpointPlacesByKey,
        &$lastCheckpointAt,
        $statePath,
        &$completedQueries,
    ]);

    $logger('query.finished', ['provider' => 'direct', 'query' => 'ресторан', 'totalSaved' => 0]);

    $loadMethod = $reflection->getMethod('loadCompletedQueries');
    $persisted = $loadMethod->invoke($command, $statePath);

    @unlink($output);
    @unlink($statePath);

    expect($completedQueries)->toBe(['ресторан'])
        ->and($persisted)->toBe(['ресторан']);
});
