<?php

declare(strict_types=1);

namespace YandexParser;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use YandexParser\DTO\Listing;
use YandexParser\DTO\Place;
use YandexParser\DTO\Product;
use YandexParser\DTO\Review;
use YandexParser\Exception\ApiException;
use YandexParser\Exception\RateLimitException;

final class Client
{
    private HttpClient $http;

    private Config $config;

    public function __construct(string $apiToken, ?Config $config = null)
    {
        $this->config = $config ?? new Config($apiToken);
        $this->http = new HttpClient([
            'base_uri' => $this->config->baseUrl,
            'timeout' => $this->config->timeout,
            'headers' => [
                'Authorization' => 'Bearer '.$this->config->apiToken,
                'Content-Type' => 'application/json',
            ],
        ]);
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

        try {
            $items = $this->runActor(Config::PLACES_ACTOR_ID, $input);

            return array_map(
                static fn (array $item): Place => Place::fromArray($item),
                $items
            );
        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        }
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

        try {
            $items = $this->runActor(Config::REVIEWS_ACTOR_ID, $input);

            return array_map(
                static fn (array $item): Review => Review::fromArray($item),
                $items
            );
        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        }
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

        try {
            $items = $this->runActor(Config::MARKET_ACTOR_ID, $input);

            return array_map(
                static fn (array $item): Product => Product::fromArray($item),
                $items
            );
        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        }
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

        try {
            $items = $this->runActor(Config::REALTY_ACTOR_ID, $input);

            return array_map(
                static fn (array $item): Listing => Listing::fromArray($item),
                $items
            );
        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e);
        }
    }

    /**
     * Run an Apify actor and return the dataset items.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     * @throws GuzzleException
     */
    private function runActor(string $actorId, array $input): array
    {
        $response = $this->http->post("/acts/{$actorId}/runs", [
            'json' => $input,
            'query' => ['waitForFinish' => $this->config->timeout],
        ]);

        /** @var array<string, mixed> $result */
        $result = json_decode($response->getBody()->getContents(), true);

        if (! isset($result['data']['defaultDatasetId'])) {
            throw new ApiException('Invalid API response: missing dataset ID');
        }

        /** @var string $datasetId */
        $datasetId = $result['data']['defaultDatasetId'];

        return $this->fetchDataset($datasetId);
    }

    /**
     * Fetch items from an Apify dataset.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function fetchDataset(string $datasetId): array
    {
        try {
            $response = $this->http->get("/datasets/{$datasetId}/items");

            /** @var array<int, array<string, mixed>> $items */
            $items = json_decode($response->getBody()->getContents(), true);

            return $items;
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to fetch dataset: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws ApiException
     * @throws RateLimitException
     */
    private function handleGuzzleException(GuzzleException $e): never
    {
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

        if ($response !== null) {
            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 60);
                throw new RateLimitException('Rate limit exceeded', $retryAfter);
            }
        }

        throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
    }
}
