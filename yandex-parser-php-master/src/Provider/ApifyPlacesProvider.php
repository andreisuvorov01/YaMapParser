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
    ): array {
        /** @var array<string, Place> $placesByKey */
        $placesByKey = [];

        foreach ($queries as $index => $query) {
            $query = (string) $query;
            $this->log($logger, 'query.started', [
                'provider' => 'apify',
                'query' => $query,
                'queryNumber' => $index + 1,
                'queryTotal' => count($queries),
                'location' => $location,
                'maxResults' => $maxResultsPerQuery,
            ]);

            $places = $this->client->scrapePlacesWithWebsites(
                query: [$query],
                location: $location,
                maxResults: $maxResultsPerQuery,
                language: $language,
                options: $options,
            );

            $this->log($logger, 'query.finished', [
                'provider' => 'apify',
                'query' => $query,
                'placesWithWebsites' => count($places),
            ]);

            foreach ($places as $place) {
                $key = $place->businessId !== ''
                    ? $place->businessId
                    : md5($place->title.'|'.$place->address.'|'.$place->website);

                if (isset($placesByKey[$key])) {
                    $this->log($logger, 'place.duplicate', [
                        'provider' => 'apify',
                        'query' => $query,
                        'title' => $place->title,
                        'businessId' => $place->businessId,
                    ]);

                    continue;
                }

                $placesByKey[$key] = $place;
                $this->log($logger, 'place.saved', [
                    'provider' => 'apify',
                    'query' => $query,
                    'title' => $place->title,
                    'website' => $place->website,
                    'totalSaved' => count($placesByKey),
                ]);
            }
        }

        return array_values($placesByKey);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  callable(string, array<string, mixed>): void|null  $logger
     */
    private function log(?callable $logger, string $event, array $context = []): void
    {
        if ($logger !== null) {
            $logger($event, $context);
        }
    }
}
