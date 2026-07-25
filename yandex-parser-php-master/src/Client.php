<?php

declare(strict_types=1);

namespace YandexParser;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use YandexParser\DTO\Listing;
use YandexParser\DTO\Place;
use YandexParser\DTO\Product;
use YandexParser\DTO\Review;
use YandexParser\Exception\ApiException;
use YandexParser\Exception\RateLimitException;

final class Client
{
    /**
     * Apify caps the `waitForFinish` query parameter server-side, so actor runs
     * that take longer must be polled explicitly instead of trusting the initial response.
     */
    private const APIFY_MAX_WAIT_FOR_FINISH_SECONDS = 300;

    private const RUN_POLL_INTERVAL_SECONDS = 3;

    private const TERMINAL_RUN_STATUSES = ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT'];

    private ClientInterface $http;

    private Config $config;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    /**
     * @param  (\Closure(int): void)|null  $sleeper  Injectable sleep function, mainly for tests
     */
    public function __construct(
        string $apiToken,
        ?Config $config = null,
        ?ClientInterface $http = null,
        ?\Closure $sleeper = null,
    ) {
        $this->config = $config ?? new Config($apiToken);

        if ($http !== null) {
            $this->http = $http;
        } else {
            $httpOptions = [
                'base_uri' => rtrim($this->config->baseUrl, '/').'/',
                'timeout' => $this->config->timeout,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->config->apiToken,
                    'Content-Type' => 'application/json',
                ],
            ];

            if ($this->config->proxy !== null) {
                $httpOptions['proxy'] = $this->config->proxy;
            }

            $this->http = new HttpClient($httpOptions);
        }

        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * Scrape places/businesses from Yandex Maps.
     *
     * @param  string[]  $query  Search queries
     * @param  array<string, mixed>  $options  Optional filters (filterRating, filterOpenNow, filterOpen24h, filterDelivery, filterTakeaway, filterWifi, filterCardPayment, filterParking, filterPetFriendly, filterWheelchairAccess, filterGoodPlace, filterMichelin, filterBusinessLunch, filterSummerTerrace, filterCuisine, filterPriceCategory, filterPriceMin, filterPriceMax, filterCategoryId, filterChainId, customFilters, sortBy, sortOrigin, enrichBusinessData, maxPhotos, maxPosts, startUrls, businessIds, coordinates, viewportSpan, category)
     * @return Place[]
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function scrapePlaces(
        array $query = ['restaurant'],
        string $location = 'Moscow',
        int $maxResults = 100,
        Language $language = Language::Russian,
        array $options = [],
    ): array {
        $input = [
            'query' => $query,
            'location' => $location,
            'maxResults' => $maxResults,
        ];

        if ($language !== Language::Auto) {
            $input['language'] = $language->value;
        }

        $input = array_merge($input, $options);

        $items = $this->runActor(Config::PLACES_ACTOR_ID, $input);

        return array_map(
            static fn (array $item): Place => Place::fromArray($item),
            $items
        );
    }

    /**
     * Scrape places/businesses from Yandex Maps and keep only cards that expose a website.
     *
     * This is useful for lead lists such as all Milan, Prague or Krasnodar businesses
     * that have their own website in the Yandex Maps card. For broader coverage, use
     * collectPlacesWithWebsites() to run several rubrics/search queries and de-duplicate them.
     *
     * @param  string[]  $query  Search queries or rubrics
     * @param  array<string, mixed>  $options  Optional places actor filters
     * @return Place[]
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function scrapePlacesWithWebsites(
        array $query = ['organization'],
        string $location = 'Moscow',
        int $maxResults = 100,
        Language $language = Language::Auto,
        array $options = [],
    ): array {
        $options += ['enrichBusinessData' => true];

        $places = $this->scrapePlaces(
            query: $query,
            location: $location,
            maxResults: $maxResults,
            language: $language,
            options: $options,
        );

        return array_values(array_filter(
            $places,
            static fn (Place $place): bool => $place->hasWebsite(),
        ));
    }

    /**
     * Run several Yandex Maps searches and return a de-duplicated list of places with websites.
     *
     * @param  string[]  $queries  Search queries or rubrics; each query is executed as a separate actor run
     * @param  array<string, mixed>  $options  Optional places actor filters
     * @return Place[]
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function collectPlacesWithWebsites(
        array $queries,
        string $location,
        int $maxResultsPerQuery = 100,
        Language $language = Language::Auto,
        array $options = [],
    ): array {
        /** @var array<string, Place> $placesByKey */
        $placesByKey = [];

        foreach ($queries as $query) {
            $places = $this->scrapePlacesWithWebsites(
                query: [(string) $query],
                location: $location,
                maxResults: $maxResultsPerQuery,
                language: $language,
                options: $options,
            );

            foreach ($places as $place) {
                $key = $place->businessId !== ''
                    ? $place->businessId
                    : md5($place->title.'|'.$place->address.'|'.$place->website);

                $placesByKey[$key] = $place;
            }
        }

        return array_values($placesByKey);
    }

    /**
     * Scrape reviews from Yandex Maps businesses.
     *
     * @param  string[]  $startUrls  Yandex Maps business URLs
     * @param  string[]  $businessIds  Direct numeric business IDs
     * @return Review[]
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function scrapeReviews(
        array $startUrls = [],
        array $businessIds = [],
        int $maxReviewsPerPlace = 0,
        ReviewSort $reviewSort = ReviewSort::Relevance,
        int $minRating = 0,
        int $maxRating = 0,
        Language $language = Language::English,
    ): array {
        if (count($startUrls) === 0 && count($businessIds) === 0) {
            throw new ApiException('At least one of startUrls or businessIds must be provided');
        }

        $input = [];

        if (count($startUrls) > 0) {
            $input['startUrls'] = $startUrls;
        }

        if (count($businessIds) > 0) {
            $input['businessIds'] = $businessIds;
        }

        if ($maxReviewsPerPlace > 0) {
            $input['maxReviewsPerPlace'] = $maxReviewsPerPlace;
        }

        $input['reviewSort'] = $reviewSort->value;

        if ($minRating > 0) {
            $input['minRating'] = $minRating;
        }

        if ($maxRating > 0) {
            $input['maxRating'] = $maxRating;
        }

        $input['language'] = $language->value;

        $items = $this->runActor(Config::REVIEWS_ACTOR_ID, $input);

        return array_map(
            static fn (array $item): Review => Review::fromArray($item),
            $items
        );
    }

    /**
     * Scrape products from Yandex Market.
     *
     * @param  array<string, mixed>  $options  Optional (priceFrom, priceTo, categoryId, enrichProducts, includeReviews, proxyUrl)
     * @return Product[]
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function scrapeProducts(
        string $query = 'ноутбук',
        int $maxItems = 100,
        MarketRegion $region = MarketRegion::Moscow,
        MarketSort $sort = MarketSort::Default,
        array $options = [],
    ): array {
        $input = [
            'query' => $query,
            'maxItems' => $maxItems,
            'region' => $region->value,
        ];

        if ($sort !== MarketSort::Default) {
            $input['sortBy'] = $sort->value;
        }

        $input = array_merge($input, $options);

        $items = $this->runActor(Config::MARKET_ACTOR_ID, $input);

        return array_map(
            static fn (array $item): Product => Product::fromArray($item),
            $items
        );
    }

    /**
     * Scrape real estate listings from Yandex Realty.
     *
     * @param  string[]  $roomsTotal  Room count filter: ['STUDIO', '1', '2', '3', 'PLUS_4']
     * @param  array<string, mixed>  $options  Optional (priceMin, priceMax, areaMin, areaMax, floorMin, floorMax, agents, regionId, includePhones, includePriceHistory)
     * @return Listing[]
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function scrapeListings(
        string $location = 'Москва',
        DealType $dealType = DealType::Sell,
        PropertyCategory $category = PropertyCategory::Apartment,
        int $maxItems = 100,
        RealtySort $sort = RealtySort::Relevance,
        array $roomsTotal = [],
        array $options = [],
    ): array {
        $input = [
            'location' => $location,
            'dealType' => $dealType->value,
            'category' => $category->value,
            'maxItems' => $maxItems,
        ];

        if ($sort !== RealtySort::Relevance) {
            $input['sort'] = $sort->value;
        }

        if (count($roomsTotal) > 0) {
            $input['roomsTotal'] = $roomsTotal;
        }

        $input = array_merge($input, $options);

        $items = $this->runActor(Config::REALTY_ACTOR_ID, $input);

        return array_map(
            static fn (array $item): Listing => Listing::fromArray($item),
            $items
        );
    }

    /**
     * Run an Apify actor and return the dataset items.
     *
     * Starts the run, waits (bounded by Apify's server-side cap) for it to finish,
     * then polls the run status until it reaches a terminal state before fetching
     * the dataset. This avoids reading a partial dataset from a run that was
     * still in progress when the initial request returned.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function runActor(string $actorId, array $input): array
    {
        $result = $this->requestJson('POST', "acts/{$actorId}/runs", [
            'json' => $input,
            'query' => ['waitForFinish' => min($this->config->timeout, self::APIFY_MAX_WAIT_FOR_FINISH_SECONDS)],
        ]);

        $run = $this->waitForRunToFinish($this->extractRunData($result));

        $datasetId = $run['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new ApiException('Invalid API response: missing dataset ID');
        }

        return $this->fetchDataset($datasetId);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function waitForRunToFinish(array $run): array
    {
        $deadline = time() + max($this->config->timeout, self::APIFY_MAX_WAIT_FOR_FINISH_SECONDS);

        while (! in_array($this->runStatus($run), self::TERMINAL_RUN_STATUSES, true)) {
            $runId = $this->runId($run);

            if ($runId === '') {
                throw new ApiException('Invalid API response: missing run ID');
            }

            if (time() >= $deadline) {
                throw new ApiException(sprintf(
                    'Actor run %s did not finish within %ds (last status: %s)',
                    $runId,
                    $this->config->timeout,
                    $this->runStatus($run),
                ));
            }

            ($this->sleeper)(self::RUN_POLL_INTERVAL_SECONDS);

            $run = $this->extractRunData($this->requestJson('GET', "actor-runs/{$runId}"));
        }

        if ($this->runStatus($run) !== 'SUCCEEDED') {
            throw new ApiException(sprintf(
                'Actor run %s finished with status %s',
                $this->runId($run),
                $this->runStatus($run),
            ));
        }

        return $run;
    }

    /**
     * @param  array<int|string, mixed>  $result
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function extractRunData(array $result): array
    {
        if (! isset($result['data']) || ! is_array($result['data'])) {
            throw new ApiException('Invalid API response: missing run data');
        }

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runId(array $run): string
    {
        return is_string($run['id'] ?? null) ? $run['id'] : '';
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runStatus(array $run): string
    {
        return is_string($run['status'] ?? null) ? $run['status'] : '';
    }

    /**
     * Fetch items from an Apify dataset.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function fetchDataset(string $datasetId): array
    {
        $items = $this->requestJson('GET', "datasets/{$datasetId}/items");

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new ApiException('Invalid API response: expected a list of dataset items');
            }
        }

        /** @var array<int, array<string, mixed>> $items */
        return $items;
    }

    /**
     * Perform an HTTP request, decode the JSON body, and transparently retry
     * rate-limited (429) requests up to Config::$maxRetries before giving up.
     *
     * @param  array<string, mixed>  $options
     * @return array<int|string, mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function requestJson(string $method, string $uri, array $options = []): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->http->request($method, $uri, $options);

                return $this->decodeJson((string) $response->getBody());
            } catch (GuzzleException $e) {
                $canRetry = $this->guzzleStatusCode($e) === 429 && $attempt < $this->config->maxRetries;

                if (! $canRetry) {
                    $this->handleGuzzleException($e);
                }

                $attempt++;
                ($this->sleeper)($this->retryAfterSeconds($e));
            }
        }
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ApiException
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new ApiException('Invalid API response: expected a JSON payload');
        }

        /** @var array<int|string, mixed> $decoded */
        return $decoded;
    }

    private function guzzleStatusCode(GuzzleException $e): ?int
    {
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

        return $response?->getStatusCode();
    }

    private function retryAfterSeconds(GuzzleException $e): int
    {
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

        if ($response === null) {
            return 60;
        }

        return (int) ($response->getHeader('Retry-After')[0] ?? 60);
    }

    /**
     * @throws ApiException
     * @throws RateLimitException
     */
    private function handleGuzzleException(GuzzleException $e): never
    {
        if ($this->guzzleStatusCode($e) === 429) {
            throw new RateLimitException('Rate limit exceeded', $this->retryAfterSeconds($e));
        }

        throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
    }
}
