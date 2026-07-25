<?php

declare(strict_types=1);

namespace YandexParser\Console;

use GuzzleHttp\ClientInterface;
use YandexParser\Client;
use YandexParser\Config;
use YandexParser\DTO\Place;
use YandexParser\Language;
use YandexParser\Provider\ApifyPlacesProvider;
use YandexParser\Provider\BypassProxyClient;
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
        $statePath = $output.'.progress.json';

        if ((bool) $options['resume']) {
            $completed = $this->loadCompletedQueries($statePath);
            $remaining = array_values(array_filter($queries, static fn (string $q): bool => ! in_array($q, $completed, true)));

            if (count($remaining) < count($queries)) {
                $this->stdout(sprintf(
                    "Resuming: skipping %d of %d queries already completed (state: %s)\n",
                    count($queries) - count($remaining),
                    count($queries),
                    $statePath,
                ));
            }

            $queries = $remaining;
        }

        if (count($queries) === 0) {
            $this->stdout("Nothing to do: all queries are already marked completed. Delete {$statePath} to start over.\n");

            return 0;
        }

        $providerName = (string) $options['provider'];
        $maxResultsPerQuery = (int) $options['max-results-per-query'];
        $language = $this->resolveLanguage((string) $options['language']);
        $provider = $this->makeProvider($providerName, $options);
        $checkpointInterval = (int) $options['checkpoint-interval'];
        $checkpointEvery = (int) $options['checkpoint-every'];
        /** @var array<string, Place> $checkpointPlacesByKey */
        $checkpointPlacesByKey = [];
        $lastCheckpointAt = time();
        $completedQueries = $this->loadCompletedQueries($statePath);
        $logger = $this->makeLogger(
            quiet: (bool) $options['quiet'],
            checkpointPath: $output,
            checkpointInterval: $checkpointInterval,
            checkpointEvery: $checkpointEvery,
            checkpointPlacesByKey: $checkpointPlacesByKey,
            lastCheckpointAt: $lastCheckpointAt,
            statePath: $statePath,
            completedQueries: $completedQueries,
        );

        if ($provider === null) {
            return 1;
        }

        $this->stdout(sprintf(
            "Collecting organizations with websites: provider=%s, location=%s, queries=%d, maxResultsPerQuery=%s, checkpointInterval=%ds, checkpointEvery=%d\n",
            $providerName,
            $location,
            count($queries),
            $this->formatLimit($maxResultsPerQuery),
            $checkpointInterval,
            $checkpointEvery,
        ));

        try {
            $places = $provider->collect(
                queries: $queries,
                location: $location,
                maxResultsPerQuery: $maxResultsPerQuery,
                language: $language,
                options: $this->resolveActorOptions($options),
                logger: $logger,
            );

            $this->writeCsv($output, $places);
        } catch (\Throwable $e) {
            if (count($checkpointPlacesByKey) > 0) {
                $this->writeCsv($output, array_values($checkpointPlacesByKey));
                $this->stderr(sprintf(
                    "Checkpoint saved after error: %s (%d rows)\n",
                    $output,
                    count($checkpointPlacesByKey),
                ));
            }

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
            'max-pages-per-query' => 50,
            'checkpoint-interval' => 30,
            'checkpoint-every' => 25,
            'filter-rating' => null,
            'max-photos' => 0,
            'max-posts' => 0,
            'proxy' => getenv('YANDEX_PARSER_PROXY') ?: null,
            'concurrency' => 1,
            'bypass-proxy-url' => getenv('YANDEX_PARSER_BYPASS_PROXY_URL') ?: null,
            'bypass-proxy-dir' => getenv('YANDEX_PARSER_BYPASS_PROXY_DIR') ?: null,
            'bypass-proxy-python' => getenv('YANDEX_PARSER_BYPASS_PROXY_PYTHON') ?: 'python',
            'resume' => false,
            'quiet' => false,
            'help' => false,
        ];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;

                continue;
            }

            if ($argument === '--quiet' || $argument === '-q') {
                $options['quiet'] = true;

                continue;
            }

            if ($argument === '--resume') {
                $options['resume'] = true;

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

        foreach (['max-results-per-query', 'timeout', 'delay-ms', 'max-pages-per-query', 'checkpoint-interval', 'checkpoint-every', 'max-photos', 'max-posts', 'concurrency'] as $integerOption) {
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

                if (isset($queries[0])) {
                    $queries[0] = preg_replace('/^\xEF\xBB\xBF/', '', $queries[0]) ?? $queries[0];
                }
            }
        }

        if (is_string($options['queries']) && $options['queries'] !== '') {
            $queries = explode(',', $options['queries']);
        }

        $queries = array_map(static fn (string $query): string => trim($query), $queries);
        $queries = array_filter(
            $queries,
            static fn (string $query): bool => $query !== '' && ! str_starts_with($query, '#'),
        );

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

            if (! interface_exists(ClientInterface::class)) {
                $this->stderr("Guzzle is not installed. Run composer install first.\n");

                return null;
            }

            $proxy = is_string($options['proxy']) && $options['proxy'] !== '' ? $options['proxy'] : null;

            return new ApifyPlacesProvider(new Client(
                apiToken: $token,
                config: new Config(apiToken: $token, timeout: (int) $options['timeout'], proxy: $proxy),
            ));
        }

        if ($providerName === 'direct') {
            if (! class_exists(\GuzzleHttp\Client::class)) {
                $this->stderr("Guzzle is not installed. Run composer install first.\n");

                return null;
            }

            $proxy = is_string($options['proxy']) && $options['proxy'] !== '' ? $options['proxy'] : null;

            return new DirectYandexMapsProvider(
                delayMs: (int) $options['delay-ms'],
                proxy: $proxy,
                concurrency: max(1, (int) $options['concurrency']),
                bypassProxy: $this->makeBypassProxyClient($options),
            );
        }

        $this->stderr("Unknown provider: {$providerName}. Use apify or direct.\n");

        return null;
    }

    /**
     * Builds a BypassProxyClient only if the user opted in via --bypass-proxy-url
     * and/or --bypass-proxy-dir. With neither set, the direct provider gets no
     * bypass proxy at all and behaves exactly as before this feature existed.
     *
     * @param  array<string, bool|int|string|null>  $options
     */
    private function makeBypassProxyClient(array $options): ?BypassProxyClient
    {
        $url = is_string($options['bypass-proxy-url']) && $options['bypass-proxy-url'] !== ''
            ? $options['bypass-proxy-url']
            : null;
        $dir = is_string($options['bypass-proxy-dir']) && $options['bypass-proxy-dir'] !== ''
            ? $options['bypass-proxy-dir']
            : null;

        if ($url === null && $dir === null) {
            return null;
        }

        return new BypassProxyClient(
            baseUrl: $url ?? 'http://127.0.0.1:8765',
            startDir: $dir,
            pythonExecutable: (string) $options['bypass-proxy-python'],
        );
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
            'maxPagesPerQuery' => (int) $options['max-pages-per-query'],
        ];

        if ($options['filter-rating'] !== null && $options['filter-rating'] !== '') {
            $actorOptions['filterRating'] = (float) $options['filter-rating'];
        }

        return $actorOptions;
    }

    /**
     * @return string[]
     */
    private function loadCompletedQueries(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param  string[]  $completedQueries
     */
    private function markQueryCompleted(string $path, string $query, array &$completedQueries): void
    {
        if (in_array($query, $completedQueries, true)) {
            return;
        }

        $completedQueries[] = $query;

        @file_put_contents($path, json_encode(array_values($completedQueries), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]');
    }

    /**
     * @param  array<string, Place>  $checkpointPlacesByKey
     * @param  string[]  $completedQueries
     * @return callable(string, array<string, mixed>): void
     */
    private function makeLogger(
        bool $quiet,
        string $checkpointPath,
        int $checkpointInterval,
        int $checkpointEvery,
        array &$checkpointPlacesByKey,
        int &$lastCheckpointAt,
        string $statePath,
        array &$completedQueries,
    ): callable {
        return function (string $event, array $context) use (
            $quiet,
            $checkpointPath,
            $checkpointInterval,
            $checkpointEvery,
            &$checkpointPlacesByKey,
            &$lastCheckpointAt,
            $statePath,
            &$completedQueries,
        ): void {
            if (! $quiet) {
                $this->stderr($this->formatLogLine($event, $context)."\n");
            }

            if ($event === 'query.finished' && is_string($context['query'] ?? null)) {
                $this->markQueryCompleted($statePath, $context['query'], $completedQueries);
            }

            if ($event !== 'place.saved' || ! ($context['place'] ?? null) instanceof Place) {
                return;
            }

            /** @var Place $place */
            $place = $context['place'];
            $key = $place->businessId !== ''
                ? $place->businessId
                : md5($place->title.'|'.$place->address.'|'.$place->website);
            $checkpointPlacesByKey[$key] = $place;

            $now = time();
            $shouldSaveByRows = $checkpointEvery > 0 && count($checkpointPlacesByKey) % $checkpointEvery === 0;
            $shouldSaveByTime = $checkpointInterval > 0 && ($now - $lastCheckpointAt) >= $checkpointInterval;

            if (! $shouldSaveByRows && ! $shouldSaveByTime) {
                return;
            }

            $this->writeCsv($checkpointPath, array_values($checkpointPlacesByKey));
            $lastCheckpointAt = $now;

            if (! $quiet) {
                $this->stderr(sprintf(
                    "[%s] checkpoint saved: %s (%d rows)\n",
                    date('H:i:s'),
                    $checkpointPath,
                    count($checkpointPlacesByKey),
                ));
            }
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatLogLine(string $event, array $context): string
    {
        $time = date('H:i:s');

        return match ($event) {
            'query.started' => sprintf(
                '[%s] [%s] query %d/%d started: "%s" in "%s" (limit=%s)',
                $time,
                (string) ($context['provider'] ?? 'provider'),
                (int) ($context['queryNumber'] ?? 0),
                (int) ($context['queryTotal'] ?? 0),
                (string) ($context['query'] ?? ''),
                (string) ($context['location'] ?? ''),
                $this->formatLimit((int) ($context['maxResults'] ?? 0)),
            ),
            'query.urls_found' => sprintf(
                '[%s] [direct] found %d organization urls for "%s"',
                $time,
                (int) ($context['urlsFound'] ?? 0),
                (string) ($context['query'] ?? ''),
            ),
            'query.page_loaded' => sprintf(
                '[%s] [direct] page %d loaded for "%s": urls=%d, new=%d, total=%d',
                $time,
                (int) ($context['page'] ?? 0),
                (string) ($context['query'] ?? ''),
                (int) ($context['urlsOnPage'] ?? 0),
                (int) ($context['newUrlsOnPage'] ?? 0),
                (int) ($context['totalUrls'] ?? 0),
            ),
            'query.finished' => sprintf(
                '[%s] [%s] query finished: "%s" (saved=%d, placesWithWebsites=%d)',
                $time,
                (string) ($context['provider'] ?? 'provider'),
                (string) ($context['query'] ?? ''),
                (int) ($context['totalSaved'] ?? 0),
                (int) ($context['placesWithWebsites'] ?? 0),
            ),
            'place.fetching' => sprintf(
                '[%s] [direct] card %d/%d: %s',
                $time,
                (int) ($context['urlNumber'] ?? 0),
                (int) ($context['urlTotal'] ?? 0),
                (string) ($context['url'] ?? ''),
            ),
            'place.saved' => sprintf(
                '[%s] [%s] saved #%d: %s — %s',
                $time,
                (string) ($context['provider'] ?? 'provider'),
                (int) ($context['totalSaved'] ?? 0),
                (string) ($context['title'] ?? ''),
                (string) ($context['website'] ?? ''),
            ),
            'place.skipped' => sprintf(
                '[%s] [%s] skipped: %s (%s)',
                $time,
                (string) ($context['provider'] ?? 'provider'),
                (string) ($context['url'] ?? ''),
                (string) ($context['reason'] ?? 'unknown reason'),
            ),
            'query.blocked' => sprintf(
                '[%s] [direct] blocked by anti-bot/captcha page on "%s" (page %d) — stopping pagination for this query',
                $time,
                (string) ($context['query'] ?? ''),
                (int) ($context['page'] ?? 0),
            ),
            'bypass_proxy.used' => sprintf(
                '[%s] [direct] retrying via bypass proxy: %s',
                $time,
                (string) ($context['url'] ?? ''),
            ),
            'query.error' => sprintf(
                '[%s] [direct] request failed on "%s" (page %d): %s — stopping pagination for this query',
                $time,
                (string) ($context['query'] ?? ''),
                (int) ($context['page'] ?? 0),
                (string) ($context['message'] ?? ''),
            ),
            'place.duplicate' => sprintf(
                '[%s] [%s] duplicate skipped: %s (%s)',
                $time,
                (string) ($context['provider'] ?? 'provider'),
                (string) ($context['title'] ?? ''),
                (string) ($context['businessId'] ?? ''),
            ),
            default => sprintf('[%s] %s %s', $time, $event, json_encode($context, JSON_UNESCAPED_UNICODE) ?: ''),
        };
    }

    private function formatLimit(int $limit): string
    {
        return $limit <= 0 ? 'unlimited' : (string) $limit;
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
        ], ',', '"', '');

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
            ], ',', '"', '');
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
  --max-results-per-query=300         Max results for each query/rubric; 0 means no local limit. Default: 300
  --output=krasnodar_websites.csv     CSV output path. Default: krasnodar_websites.csv
  --language=ru                       Yandex/actor language: auto, ru, en, tr, uk, kk. Default: ru
  --filter-rating=4.0                 Optional Apify places actor rating filter.
  --max-photos=0                      Optional Apify places actor maxPhotos. Default: 0
  --max-posts=0                       Optional Apify places actor maxPosts. Default: 0
  --timeout=900                       Apify waitForFinish timeout in seconds. Default: 900
  --delay-ms=750                      Direct provider delay between organization page requests. Default: 750
  --max-pages-per-query=50            Direct provider page safety limit when max-results-per-query=0. Default: 50
  --checkpoint-interval=30            Rewrite CSV checkpoint at least every N seconds; 0 disables time checkpoints. Default: 30
  --checkpoint-every=25               Rewrite CSV checkpoint every N saved rows; 0 disables row checkpoints. Default: 25
  --proxy=http://user:pass@host:port  Optional HTTP/HTTPS proxy used for API/scraping requests. Defaults to YANDEX_PARSER_PROXY env variable.
  --concurrency=1                     Direct provider: number of organization pages fetched in parallel. Default: 1 (sequential)
  --bypass-proxy-url=URL              Direct provider: base URL of a running bypass-proxy service (see below). Only used as a
                                       fallback after a direct request fails/is blocked. Defaults to YANDEX_PARSER_BYPASS_PROXY_URL.
  --bypass-proxy-dir=PATH             Direct provider: project directory containing proxy_server.py; if the service at
                                       --bypass-proxy-url isn't reachable, it's launched from here on first need (best-effort).
                                       Defaults to YANDEX_PARSER_BYPASS_PROXY_DIR.
  --bypass-proxy-python=python        Python executable used to launch proxy_server.py. Defaults to YANDEX_PARSER_BYPASS_PROXY_PYTHON.
  --resume                            Skip queries already marked completed in <output>.progress.json from a previous run.
  --quiet, -q                         Disable progress logs and print only final status/errors.
  --help                              Show this help.

Examples:
  APIFY_TOKEN=apify_api_xxx php bin/yandex-parser collect:websites --location=Краснодар --output=krasnodar.csv
  php bin/yandex-parser collect:websites --provider=direct --location=Краснодар --queries="ресторан,кафе,стоматология" --output=krasnodar.csv
  php bin/yandex-parser collect:websites --queries-file=examples/queries-krasnodar.txt --output=krasnodar.csv --resume

Notes:
  The direct provider is best-effort HTML parsing of public Yandex Maps pages. It can break if Yandex changes markup,
  can be rate-limited, and should be used only where it complies with applicable terms and laws.
  Every run writes <output>.progress.json, listing queries that finished successfully. Pass --resume to skip them on
  the next run (e.g. after a crash or rate limit); delete that file to force a full re-run.
  Lines starting with "#" in --queries-file are treated as comments and skipped.
  --bypass-proxy-* is entirely optional and never used for normal requests - the direct provider only calls it after
  a request already failed or came back as a Yandex anti-bot/captcha page, so it adds no overhead when nothing is blocked.
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
