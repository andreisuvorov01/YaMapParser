<?php

declare(strict_types=1);

namespace YandexParser\Provider;

use YandexParser\Client;
use YandexParser\DTO\Place;
use YandexParser\Language;

final readonly class ApifyPlacesProvider implements PlacesWithWebsitesProvider
{
    public function __construct(
        private Client $client,
    ) {}

    /**
     * @param  string[]  $queries
     * @param  array<string, mixed>  $options
     * @return Place[]
     */
    public function collect(
        array $queries,
        string $location,
        int $maxResultsPerQuery = 100,
        Language $language = Language::Auto,
        array $options = [],
    ): array {
        return $this->client->collectPlacesWithWebsites(
            queries: $queries,
            location: $location,
            maxResultsPerQuery: $maxResultsPerQuery,
            language: $language,
            options: $options,
        );
    }
}
