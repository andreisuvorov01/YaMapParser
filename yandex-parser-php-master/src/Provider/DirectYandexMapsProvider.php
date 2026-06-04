<?php

declare(strict_types=1);

namespace YandexParser\Provider;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use YandexParser\DTO\Place;
use YandexParser\Exception\ApiException;
use YandexParser\Language;

/**
 * Experimental Yandex Maps provider that does not require Apify.
 *
 * It uses public Yandex Maps HTML pages, so selectors and embedded JSON formats can
 * change without notice. Prefer the Apify provider for production-grade collection.
 */
final class DirectYandexMapsProvider implements PlacesWithWebsitesProvider
{
    private ClientInterface $http;

    public function __construct(
        ?ClientInterface $http = null,
        private readonly int $delayMs = 750,
    ) {
        $this->http = $http ?? new HttpClient([
            'base_uri' => 'https://yandex.ru',
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru,en;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (compatible; YandexParserPHP/1.0; +https://github.com/Scraper-APIs/yandex-scraper-php)',
            ],
        ]);
    }

    /**
     * @param  string[]  $queries
     * @param  array<string, mixed>  $options
     * @param  callable(string, array<string, mixed>): void|null  $logger
     * @return Place[]
     *
     * @throws ApiException
     */
    public function collect(
        array $queries,
        string $location,
        int $maxResultsPerQuery = 100,
        Language $language = Language::Auto,
        array $options = [],
        ?callable $logger = null,
    ): array {
        unset($language);
        $maxPagesPerQuery = max(1, (int) ($options['maxPagesPerQuery'] ?? 50));

        /** @var array<string, Place> $placesByKey */
        $placesByKey = [];

        foreach ($queries as $index => $query) {
            $query = (string) $query;
            $this->log($logger, 'query.started', [
                'provider' => 'direct',
                'query' => $query,
                'queryNumber' => $index + 1,
                'queryTotal' => count($queries),
                'location' => $location,
                'maxResults' => $maxResultsPerQuery,
                'maxPages' => $maxPagesPerQuery,
            ]);

            $urls = $this->findOrganizationUrls($query, $location, $maxResultsPerQuery, $maxPagesPerQuery, $logger);
            $this->log($logger, 'query.urls_found', [
                'provider' => 'direct',
                'query' => $query,
                'urlsFound' => count($urls),
            ]);

            foreach ($urls as $urlIndex => $url) {
                $this->log($logger, 'place.fetching', [
                    'provider' => 'direct',
                    'query' => $query,
                    'urlNumber' => $urlIndex + 1,
                    'urlTotal' => count($urls),
                    'url' => $url,
                ]);

                $place = $this->fetchPlace($url, $location);

                if ($place === null || ! $place->hasWebsite()) {
                    $this->log($logger, 'place.skipped', [
                        'provider' => 'direct',
                        'query' => $query,
                        'url' => $url,
                        'reason' => 'no website or fetch failed',
                    ]);

                    continue;
                }

                $key = $place->businessId !== ''
                    ? $place->businessId
                    : md5($place->title.'|'.$place->address.'|'.$place->website);

                if (isset($placesByKey[$key])) {
                    $this->log($logger, 'place.duplicate', [
                        'provider' => 'direct',
                        'query' => $query,
                        'title' => $place->title,
                        'businessId' => $place->businessId,
                    ]);
                    $this->sleepBetweenRequests();

                    continue;
                }

                $placesByKey[$key] = $place;
                $this->log($logger, 'place.saved', [
                    'provider' => 'direct',
                    'query' => $query,
                    'title' => $place->title,
                    'website' => $place->website,
                    'totalSaved' => count($placesByKey),
                    'place' => $place,
                ]);
                $this->sleepBetweenRequests();
            }

            $this->log($logger, 'query.finished', [
                'provider' => 'direct',
                'query' => $query,
                'totalSaved' => count($placesByKey),
            ]);
        }

        return array_values($placesByKey);
    }

    /**
     * @return string[]
     *
     * @throws ApiException
     */
    private function findOrganizationUrls(
        string $query,
        string $location,
        int $maxResults,
        int $maxPages,
        ?callable $logger,
    ): array {
        $urls = [];
        $seenUrls = [];
        $unlimited = $maxResults <= 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $queryParams = ['text' => trim($query.' '.$location)];
                if ($page > 1) {
                    $queryParams['page'] = $page;
                }

                $response = $this->http->request('GET', '/maps/', [
                    'query' => $queryParams,
                ]);
            } catch (GuzzleException $e) {
                throw new ApiException('Direct Yandex Maps search request failed: '.$e->getMessage(), 0, $e);
            }

            $pageUrls = $this->extractOrganizationUrls((string) $response->getBody());
            $newOnPage = 0;

            foreach ($pageUrls as $url) {
                if (isset($seenUrls[$url])) {
                    continue;
                }

                $seenUrls[$url] = true;
                $urls[] = $url;
                $newOnPage++;

                if (! $unlimited && count($urls) >= $maxResults) {
                    break 2;
                }
            }

            $this->log($logger, 'query.page_loaded', [
                'provider' => 'direct',
                'query' => $query,
                'page' => $page,
                'urlsOnPage' => count($pageUrls),
                'newUrlsOnPage' => $newOnPage,
                'totalUrls' => count($urls),
            ]);

            if ($newOnPage === 0) {
                break;
            }
        }

        return $urls;
    }

    private function fetchPlace(string $url, string $location): ?Place
    {
        try {
            $response = $this->http->request('GET', $url);
        } catch (GuzzleException) {
            return null;
        }

        $html = (string) $response->getBody();
        $data = $this->parsePlacePage($html, $url, $location);

        if (($data['website'] ?? null) === null || trim((string) $data['website']) === '') {
            return null;
        }

        return Place::fromArray($data);
    }

    /**
     * @return string[]
     */
    public function extractOrganizationUrls(string $html): array
    {
        $normalized = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(['\\/', '\\u002F'], '/', $normalized);

        preg_match_all('~(?:(?:https?:)?//yandex\.[^\s"\'<>]+)?/maps/org/[^\s"\'<>]+/\d+/?~u', $normalized, $matches);

        $urls = [];
        foreach ($matches[0] as $url) {
            $url = preg_replace('~[?#].*$~', '', $url) ?? $url;
            $url = str_starts_with($url, '//') ? 'https:'.$url : $url;
            $url = str_starts_with($url, 'http') ? $url : 'https://yandex.ru'.$url;
            $urls[$url] = $url;
        }

        return array_values($urls);
    }

    /**
     * @return array<string, mixed>
     */
    public function parsePlacePage(string $html, string $url, string $location): array
    {
        $jsonLd = $this->extractJsonLdPlace($html);
        $businessId = $this->extractBusinessId($url) ?? $this->extractBusinessId($html) ?? '';
        $website = $this->extractWebsite($jsonLd, $html);
        $coordinates = $this->extractCoordinates($jsonLd, $html);

        return [
            'businessId' => $businessId,
            'title' => $this->extractTitle($jsonLd, $html),
            'url' => $url,
            'address' => $this->extractAddress($jsonLd, $html),
            'city' => $location,
            'phones' => $this->extractPhones($jsonLd, $html),
            'website' => $website,
            'longitude' => $coordinates['longitude'] ?? null,
            'latitude' => $coordinates['latitude'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonLdPlace(string $html): ?array
    {
        preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

            if (! is_array($decoded)) {
                continue;
            }

            $place = $this->findPlaceLikeJsonLd($decoded);
            if ($place !== null) {
                return $place;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>|null
     */
    private function findPlaceLikeJsonLd(array $data): ?array
    {
        $type = $data['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        $placeTypes = ['LocalBusiness', 'Organization', 'Place', 'Restaurant', 'Store', 'MedicalBusiness'];

        if (count(array_intersect($placeTypes, array_filter($types, 'is_string'))) > 0) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }

            $found = $this->findPlaceLikeJsonLd($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     */
    private function extractWebsite(?array $jsonLd, string $html): ?string
    {
        $candidates = [];

        foreach (['url', 'sameAs'] as $key) {
            $value = $jsonLd[$key] ?? null;
            if (is_string($value)) {
                $candidates[] = $value;
            }
            if (is_array($value)) {
                $candidates = array_merge($candidates, array_filter($value, 'is_string'));
            }
        }

        preg_match_all('~"(?:website|site|url|href)"\s*:\s*"((?:https?:)?//[^"\\\\]+)"~u', str_replace('\\/', '/', $html), $matches);
        $candidates = array_merge($candidates, $matches[1]);

        foreach ($candidates as $candidate) {
            $candidate = str_starts_with($candidate, '//') ? 'https:'.$candidate : $candidate;
            $host = parse_url($candidate, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            if (str_contains($host, 'yandex.') || str_contains($host, 'ya.ru') || str_contains($host, 'yastatic.net')) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     */
    private function extractTitle(?array $jsonLd, string $html): string
    {
        if (isset($jsonLd['name']) && is_string($jsonLd['name'])) {
            return $jsonLd['name'];
        }

        foreach ([
            '~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']~i',
            '~<title[^>]*>(.*?)</title>~is',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     */
    private function extractAddress(?array $jsonLd, string $html): ?string
    {
        $address = $jsonLd['address'] ?? null;
        if (is_string($address)) {
            return $address;
        }
        if (is_array($address)) {
            $parts = array_filter([
                $address['streetAddress'] ?? null,
                $address['addressLocality'] ?? null,
                $address['addressRegion'] ?? null,
                $address['postalCode'] ?? null,
            ], 'is_string');

            if (count($parts) > 0) {
                return implode(', ', $parts);
            }
        }

        if (preg_match('~"address"\s*:\s*"([^"\\\\]+)"~u', $html, $match) === 1) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     * @return string[]
     */
    private function extractPhones(?array $jsonLd, string $html): array
    {
        $phones = [];
        $telephone = $jsonLd['telephone'] ?? null;

        if (is_string($telephone)) {
            $phones[] = $telephone;
        }
        if (is_array($telephone)) {
            $phones = array_merge($phones, array_filter($telephone, 'is_string'));
        }

        preg_match_all('~(?:tel:)?(\+?\d[\d\s().-]{7,}\d)~u', $html, $matches);
        $phones = array_merge($phones, $matches[1]);

        return array_values(array_unique(array_map('trim', $phones)));
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     * @return array{longitude?: float, latitude?: float}
     */
    private function extractCoordinates(?array $jsonLd, string $html): array
    {
        $geo = $jsonLd['geo'] ?? null;
        if (is_array($geo) && isset($geo['longitude'], $geo['latitude'])) {
            return [
                'longitude' => (float) $geo['longitude'],
                'latitude' => (float) $geo['latitude'],
            ];
        }

        if (preg_match('~"coordinates"\s*:\s*\[\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\]~', $html, $match) === 1) {
            return ['longitude' => (float) $match[1], 'latitude' => (float) $match[2]];
        }

        return [];
    }

    private function extractBusinessId(string $value): ?string
    {
        if (preg_match('~/maps/org/[^/]+/(\d+)/?~', $value, $match) === 1) {
            return $match[1];
        }

        if (preg_match('~"(?:businessId|oid|id)"\s*:\s*"?(\d{5,})"?~', $value, $match) === 1) {
            return $match[1];
        }

        return null;
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

    private function sleepBetweenRequests(): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
    }
}
