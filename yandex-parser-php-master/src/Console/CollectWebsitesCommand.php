<?php

declare(strict_types=1);

namespace YandexParser\Console;

use YandexParser\Client;
use YandexParser\Config;
use YandexParser\DTO\Place;
use YandexParser\Language;
use YandexParser\Provider\ApifyPlacesProvider;
use YandexParser\Provider\DirectYandexMapsProvider;
use YandexParser\Provider\PlacesWithWebsitesProvider;

final class CollectWebsitesCommand
{
    private const DEFAULT_QUERIES = [
        'ресторан',
        'кафе',
        'бар',
        'кофейня',
        'доставка еды',
        'стоматология',
        'клиника',
        'медицинский центр',
        'салон красоты',
        'парикмахерская',
        'барбершоп',
        'фитнес клуб',
        'автосервис',
        'шиномонтаж',
        'автомойка',
        'магазин',
        'строительная компания',
        'юридические услуги',
        'бухгалтерские услуги',
        'агентство недвижимости',
        'отель',
        'гостиница',
        'туристическое агентство',
        'образовательный центр',
        'частная школа',
        'детский сад',
    ];

    /**
     * @param  string[]  $argv
     */
    public function run(array $argv): int
    {
        $options = $this->parseArguments($argv);

        if ($options['help'] === true) {
            $this->printHelp();

            return 0;
        }

        $location = trim((string) $options['location']);
        if ($location === '') {
            $this->stderr("Missing --location. Example: --location=Краснодар\n");

            return 1;
        }

        $queries = $this->resolveQueries($options);
        if (count($queries) === 0) {
            $this->stderr("No queries provided. Use --queries or --queries-file.\n");

            return 1;
        }

        $output = (string) $options['output'];
        $providerName = (string) $options['provider'];
        $maxResultsPerQuery = (int) $options['max-results-per-query'];
        $language = $this->resolveLanguage((string) $options['language']);
        $provider = $this->makeProvider($providerName, $options);

        if ($provider === null) {
            return 1;
        }

        $this->stdout(sprintf(
            "Collecting organizations with websites: provider=%s, location=%s, queries=%d, maxResultsPerQuery=%d\n",
            $providerName,
            $location,
            count($queries),
            $maxResultsPerQuery,
        ));

        try {
            $places = $provider->collect(
                queries: $queries,
                location: $location,
                maxResultsPerQuery: $maxResultsPerQuery,
                language: $language,
                options: $this->resolveActorOptions($options),
            );

            $this->writeCsv($output, $places);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage()."\n");

            return 1;
        }

        $this->stdout(sprintf("Done. Found %d organizations with websites. CSV: %s\n", count($places), $output));

        return 0;
    }

    /**
     * @param  string[]  $argv
     * @return array<string, bool|int|string|null>
     */
    private function parseArguments(array $argv): array
    {
        $options = [
            'provider' => getenv('YANDEX_PARSER_PROVIDER') ?: 'apify',
            'token' => getenv('APIFY_TOKEN') ?: null,
            'location' => 'Краснодар',
            'queries' => null,
            'queries-file' => null,
            'max-results-per-query' => 300,
            'output' => 'krasnodar_websites.csv',
            'language' => 'ru',
            'timeout' => 900,
            'delay-ms' => 750,
            'filter-rating' => null,
            'max-photos' => 0,
            'max-posts' => 0,
            'help' => false,
        ];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
                continue;
            }

            if (! str_starts_with($argument, '--')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');

            if (array_key_exists($key, $options)) {
                $options[$key] = $value;
            }
        }

        foreach (['max-results-per-query', 'timeout', 'delay-ms', 'max-photos', 'max-posts'] as $integerOption) {
            $options[$integerOption] = max(0, (int) $options[$integerOption]);
        }

        return $options;
    }

    /**
     * @param  array<string, bool|int|string|null>  $options
     * @return string[]
     */
    private function resolveQueries(array $options): array
    {
        $queries = self::DEFAULT_QUERIES;

        if (is_string($options['queries-file']) && $options['queries-file'] !== '') {
            $lines = @file($options['queries-file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                $queries = $lines;
            }
        }

        if (is_string($options['queries']) && $options['queries'] !== '') {
            $queries = explode(',', $options['queries']);
        }

        $queries = array_map(static fn (string $query): string => trim($query), $queries);
        $queries = array_filter($queries, static fn (string $query): bool => $query !== '');

        return array_values(array_unique($queries));
    }

    /**
     * @param  array<string, bool|int|string|null>  $options
     */
    private function makeProvider(string $providerName, array $options): ?PlacesWithWebsitesProvider
    {
        if ($providerName === 'apify') {
            $token = is_string($options['token']) ? trim($options['token']) : '';

            if ($token === '') {
                $this->stderr("APIFY_TOKEN is required for --provider=apify. Use --provider=direct to run without Apify.\n");

                return null;
            }

            if (! interface_exists(\GuzzleHttp\ClientInterface::class)) {
                $this->stderr("Guzzle is not installed. Run composer install first.\n");

                return null;
            }

            return new ApifyPlacesProvider(new Client(
                apiToken: $token,
                config: new Config(apiToken: $token, timeout: (int) $options['timeout']),
            ));
        }

        if ($providerName === 'direct') {
            if (! class_exists(\GuzzleHttp\Client::class)) {
                $this->stderr("Guzzle is not installed. Run composer install first.\n");

                return null;
            }

            return new DirectYandexMapsProvider(delayMs: (int) $options['delay-ms']);
        }

        $this->stderr("Unknown provider: {$providerName}. Use apify or direct.\n");

        return null;
    }

    private function resolveLanguage(string $language): Language
    {
        return Language::tryFrom($language) ?? Language::Russian;
    }

    /**
     * @param  array<string, bool|int|string|null>  $options
     * @return array<string, mixed>
     */
    private function resolveActorOptions(array $options): array
    {
        $actorOptions = [
            'maxPhotos' => (int) $options['max-photos'],
            'maxPosts' => (int) $options['max-posts'],
        ];

        if ($options['filter-rating'] !== null && $options['filter-rating'] !== '') {
            $actorOptions['filterRating'] = (float) $options['filter-rating'];
        }

        return $actorOptions;
    }

    /**
     * @param  Place[]  $places
     */
    private function writeCsv(string $path, array $places): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot write CSV file: {$path}");
        }

        fputcsv($handle, [
            'business_id',
            'title',
            'city',
            'address',
            'website',
            'website_host',
            'phone',
            'rating',
            'reviews',
            'yandex_maps_url',
        ]);

        foreach ($places as $place) {
            fputcsv($handle, [
                $place->businessId,
                $place->title,
                $place->city,
                $place->address,
                $place->website,
                $place->getWebsiteHost(),
                $place->getFirstPhone(),
                $place->rating,
                $place->reviewCount,
                $place->url,
            ]);
        }

        fclose($handle);
    }

    private function printHelp(): void
    {
        $this->stdout(<<<'HELP'
Yandex Parser CLI

Collect Yandex Maps organizations that have a website in their card and export them to CSV.

Usage:
  yandex-parser collect:websites [options]
  php bin/yandex-parser collect:websites [options]

Options:
  --provider=apify|direct             Data provider. apify is stable; direct is experimental and does not require Apify. Default: apify
  --token=TOKEN                       Apify token. Defaults to APIFY_TOKEN env variable.
  --location=Краснодар                City/location to search. Default: Краснодар
  --queries="ресторан,кафе"           Comma-separated rubrics/search queries.
  --queries-file=queries.txt          One query per line. Overrides default queries unless --queries is passed.
  --max-results-per-query=300         Max results for each query/rubric. Default: 300
  --output=krasnodar_websites.csv     CSV output path. Default: krasnodar_websites.csv
  --language=ru                       Yandex/actor language: auto, ru, en, tr, uk, kk. Default: ru
  --filter-rating=4.0                 Optional Apify places actor rating filter.
  --max-photos=0                      Optional Apify places actor maxPhotos. Default: 0
  --max-posts=0                       Optional Apify places actor maxPosts. Default: 0
  --timeout=900                       Apify waitForFinish timeout in seconds. Default: 900
  --delay-ms=750                      Direct provider delay between organization page requests. Default: 750
  --help                              Show this help.

Examples:
  APIFY_TOKEN=apify_api_xxx php bin/yandex-parser collect:websites --location=Краснодар --output=krasnodar.csv
  php bin/yandex-parser collect:websites --provider=direct --location=Краснодар --queries="ресторан,кафе,стоматология" --output=krasnodar.csv

Notes:
  The direct provider is best-effort HTML parsing of public Yandex Maps pages. It can break if Yandex changes markup,
  can be rate-limited, and should be used only where it complies with applicable terms and laws.
HELP);
    }

    private function stdout(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    private function stderr(string $message): void
    {
        fwrite(STDERR, $message);
    }
}
