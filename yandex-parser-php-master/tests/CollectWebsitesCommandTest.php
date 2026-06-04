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
    $output = tempnam(sys_get_temp_dir(), 'yandex-parser-checkpoint-');

    expect($output)->not->toBeFalse();

    /** @var callable $logger */
    $logger = $method->invokeArgs($command, [
        true,
        $output,
        0,
        1,
        &$checkpointPlacesByKey,
        &$lastCheckpointAt,
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

    expect($csv)->not->toBeFalse()
        ->and($csv)->toContain('website')
        ->and($csv)->toContain('https://checkpoint.example.ru')
        ->and($checkpointPlacesByKey)->toHaveCount(1);
});
