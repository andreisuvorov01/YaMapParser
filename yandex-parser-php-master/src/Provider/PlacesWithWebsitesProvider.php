<?php

declare(strict_types=1);

namespace YandexParser\Provider;

use YandexParser\DTO\Place;
use YandexParser\Language;

interface PlacesWithWebsitesProvider
{
    /**
     * @param  string[]  $queries
     * @param  array<string, mixed>  $options
     * @param  callable(string, array<string, mixed>): void|null  $logger
     * @return Place[]
     */
    public function collect(
        array $queries,
        string $location,
        int $maxResultsPerQuery = 100,
        Language $language = Language::Auto,
        array $options = [],
        ?callable $logger = null,
    ): array;
}
