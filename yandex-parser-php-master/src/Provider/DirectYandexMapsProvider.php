<?php

declare(strict_types=1);

namespace YandexParser\Provider;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use YandexParser\DTO\Place;
use YandexParser\Language;

/**
 * Experimental Yandex Maps provider that does not require Apify.
 *
 * It uses public Yandex Maps HTML pages, so selectors and embedded JSON formats can
 * change without notice. Prefer the Apify provider for production-grade collection.
 *
 * An optional BypassProxyClient can be supplied: it is only ever consulted after a
 * direct request already failed or came back as an anti-bot/CAPTCHA page, never on
 * the normal path, so it "works only when necessary" instead of routing every request.
 */
final class DirectYandexMapsProvider implements PlacesWithWebsitesProvider
{
    /**
     * Markers that show up on Yandex's anti-bot / SmartCaptcha interstitial pages.
     * A page matching these is not a "0 results" search or a missing website - it's a block.
     */
    private const BLOCK_MARKERS = [
        'showcaptcha',
        'smartcaptcha',
        'checkcaptcha',
        'confirm that requests are not automatically generated',
        'вы робот',
        'подтвердите, что запросы отправляете не вы',
    ];

    /**
     * Yandex Maps geobase region IDs for major Russian cities, keyed by lowercased name.
     * Verified directly against https://yandex.ru/maps/{id}/x/search/... (the <title>
     * of each ID's search results names the expected city) - these do NOT match
     * MarketRegion's IDs, which are a separate Yandex Market numbering and are wrong
     * for several of these same cities on Maps (e.g. Market's Perm=47 is Nizhny
     * Novgorod on Maps; Market's Ufa=61 doesn't resolve on Maps at all).
     *
     * When the requested location matches one of these, search uses a geo-scoped URL
     * instead of free text. This matters because free text ("<rubric> <city>") can
     * accidentally exact-match an unrelated place literally named that way (e.g.
     * searching "ресторан Москва" can resolve to a single restaurant named "Москва"
     * in a completely different city) instead of listing the rubric in that city.
     */
    private const KNOWN_CITY_GEO_IDS = [
        'москва' => '213',
        'moscow' => '213',
        'санкт-петербург' => '2',
        'спб' => '2',
        'saint petersburg' => '2',
        'st petersburg' => '2',
        'екатеринбург' => '54',
        'yekaterinburg' => '54',
        'казань' => '43',
        'kazan' => '43',
        'новосибирск' => '65',
        'novosibirsk' => '65',
        'нижний новгород' => '47',
        'nizhny novgorod' => '47',
        'самара' => '51',
        'samara' => '51',
        'ростов-на-дону' => '39',
        'rostov-on-don' => '39',
        'краснодар' => '35',
        'krasnodar' => '35',
        'челябинск' => '56',
        'chelyabinsk' => '56',
        'уфа' => '172',
        'ufa' => '172',
        'пермь' => '50',
        'perm' => '50',
        'воронеж' => '193',
        'voronezh' => '193',
        'волгоград' => '38',
        'volgograd' => '38',
        'красноярск' => '62',
        'krasnoyarsk' => '62',
        'омск' => '66',
        'omsk' => '66',
    ];

    /**
     * Social/messenger/aggregator domains that show up in Yandex Maps card data
     * (JSON-LD `sameAs`, share buttons, etc.) but are never the organization's
     * own website. Without this, extractWebsite() can pick a VK/Instagram/etc.
     * profile link instead of the company site - or instead of no site at all.
     */
    private const NON_WEBSITE_HOST_FRAGMENTS = [
        'yandex.',
        'ya.ru',
        'yastatic.net',
        'vk.com',
        'vk.ru',
        'vkontakte.ru',
        'ok.ru',
        'odnoklassniki.ru',
        'instagram.com',
        'facebook.com',
        'fb.com',
        'twitter.com',
        'x.com',
        't.me',
        'telegram.me',
        'telegram.org',
        'whatsapp.com',
        'wa.me',
        'viber.com',
        'youtube.com',
        'youtu.be',
        'tiktok.com',
        'threads.net',
        'avito.ru',
        '2gis.',
        'google.com',
        'rutube.ru',
        'dzen.ru',
        'my.mail.ru',
        'pinterest.com',
        'linkedin.com',
        // Review/listing aggregators that show up in Maps card "sources" (cross-references
        // to other directories the org is also listed on) - never the org's own site.
        'advizzer.com',
        'kupikupon.ru',
        'restoran.ru',
        'zoon.ru',
        'flamp.ru',
        'foursquare.com',
        'tripadvisor.',
    ];

    /**
     * A single retry (2 attempts total) for the search-page request before falling back to
     * the bypass proxy or giving up on this query - absorbs the kind of transient connection
     * blip (reset, truncated response) that would otherwise stop pagination after just one hiccup.
     */
    private const SEARCH_PAGE_MAX_RETRIES = 1;

    private const RETRY_DELAY_MS = 500;

    private ClientInterface $http;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    public function __construct(
        ?ClientInterface $http = null,
        private readonly int $delayMs = 750,
        ?string $proxy = null,
        private readonly int $concurrency = 1,
        private readonly ?BypassProxyClient $bypassProxy = null,
        ?\Closure $sleeper = null,
    ) {
        if ($http !== null) {
            $this->http = $http;
        } else {
            $httpOptions = [
                'base_uri' => 'https://yandex.ru',
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'ru,en;q=0.9',
                    'User-Agent' => 'Mozilla/5.0 (compatible; YandexParserPHP/1.0; +https://github.com/Scraper-APIs/yandex-scraper-php)',
                ],
            ];

            if ($proxy !== null) {
                $httpOptions['proxy'] = $proxy;
            }

            $this->http = new HttpClient($httpOptions);
        }

        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws GuzzleException
     */
    private function requestWithRetry(string $method, string $path, array $options): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->http->request($method, $path, $options);
            } catch (GuzzleException $e) {
                if ($attempt >= self::SEARCH_PAGE_MAX_RETRIES) {
                    throw $e;
                }

                $attempt++;
                ($this->sleeper)(self::RETRY_DELAY_MS * $attempt);
            }
        }
    }

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

            $this->fetchPlaces($urls, $query, $location, $placesByKey, $logger);

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
        $seenBusinessIds = [];
        $unlimited = $maxResults <= 0;

        $geoId = $this->resolveCityGeoId($location);

        for ($page = 1; $page <= $maxPages; $page++) {
            if ($geoId !== null) {
                // Geo-scoped search: reliably lists the rubric within that city instead of
                // risking an exact-name match on unrelated free text (see KNOWN_CITY_GEO_IDS).
                $path = '/maps/'.$geoId.'/city/search/'.rawurlencode($query).'/';
                $queryParams = $page > 1 ? ['page' => $page] : [];
            } else {
                $path = '/maps/';
                $queryParams = ['text' => trim($query.' '.$location)];
                if ($page > 1) {
                    $queryParams['page'] = $page;
                }
            }
            $absoluteUrl = 'https://yandex.ru'.$path.($queryParams !== [] ? '?'.http_build_query($queryParams) : '');

            try {
                $response = $this->requestWithRetry('GET', $path, [
                    'query' => $queryParams,
                ]);
                $body = (string) $response->getBody();
            } catch (GuzzleException $e) {
                $body = $this->fetchViaBypassProxyOrNull($absoluteUrl, $logger);

                if ($body === null) {
                    // A single flaky page must not abort the whole (possibly hundreds of
                    // queries long) run - keep whatever URLs this query already found and
                    // move on, same as an anti-bot block below.
                    $this->log($logger, 'query.error', [
                        'provider' => 'direct',
                        'query' => $query,
                        'page' => $page,
                        'message' => $e->getMessage(),
                    ]);

                    break;
                }
            }

            if ($this->isBlockedPage($body)) {
                $viaBypass = $this->fetchViaBypassProxyOrNull($absoluteUrl, $logger);

                if ($viaBypass !== null && ! $this->isBlockedPage($viaBypass)) {
                    $body = $viaBypass;
                } else {
                    $this->log($logger, 'query.blocked', [
                        'provider' => 'direct',
                        'query' => $query,
                        'page' => $page,
                    ]);

                    break;
                }
            }

            $pageUrls = $this->extractOrganizationUrls($body);
            $newOnPage = 0;

            foreach ($pageUrls as $url) {
                if (isset($seenUrls[$url])) {
                    continue;
                }

                $seenUrls[$url] = true;

                // Yandex Maps org pages carry <link rel="alternate"> variants of the same
                // organization on yandex.ru/.com/.kz/... - extractOrganizationUrls() picks
                // those up as if they were distinct search results, wasting a fetch per
                // domain variant for what dedupes down to one organization anyway. Skipping
                // repeat business IDs here catches that before it costs a request.
                $businessId = $this->extractBusinessId($url);
                if ($businessId !== null) {
                    if (isset($seenBusinessIds[$businessId])) {
                        continue;
                    }

                    $seenBusinessIds[$businessId] = true;
                }

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

    /**
     * @see KNOWN_CITY_GEO_IDS
     */
    private function resolveCityGeoId(string $location): ?string
    {
        return self::KNOWN_CITY_GEO_IDS[mb_strtolower(trim($location), 'UTF-8')] ?? null;
    }

    /**
     * Fetch each organization page (optionally with several requests in flight at once,
     * see $concurrency) and merge places with a website into $placesByKey.
     *
     * @param  string[]  $urls
     * @param  array<string, Place>  $placesByKey
     * @param  callable(string, array<string, mixed>): void|null  $logger
     */
    private function fetchPlaces(array $urls, string $query, string $location, array &$placesByKey, ?callable $logger): void
    {
        $total = count($urls);

        if ($total === 0) {
            return;
        }

        $requests = function () use ($urls, $query, $total, $logger): \Generator {
            foreach ($urls as $urlIndex => $url) {
                $this->log($logger, 'place.fetching', [
                    'provider' => 'direct',
                    'query' => $query,
                    'urlNumber' => $urlIndex + 1,
                    'urlTotal' => $total,
                    'url' => $url,
                ]);

                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->http, $requests(), [
            'concurrency' => max(1, $this->concurrency),
            'fulfilled' => function (ResponseInterface $response, string $url) use ($query, $location, &$placesByKey, $logger): void {
                $html = (string) $response->getBody();

                if ($this->isBlockedPage($html)) {
                    $viaBypass = $this->fetchViaBypassProxyOrNull($url, $logger);

                    if ($viaBypass !== null) {
                        $html = $viaBypass;
                    }
                }

                $status = $this->handleFetchedPlace($url, $html, $location, $query, $placesByKey, $logger);

                if ($status === 'saved' || $status === 'duplicate') {
                    $this->sleepBetweenRequests();
                }
            },
            'rejected' => function (mixed $reason, string $url) use ($query, $location, &$placesByKey, $logger): void {
                $html = $this->fetchViaBypassProxyOrNull($url, $logger);

                if ($html !== null) {
                    $status = $this->handleFetchedPlace($url, $html, $location, $query, $placesByKey, $logger);

                    if ($status === 'saved' || $status === 'duplicate') {
                        $this->sleepBetweenRequests();
                    }

                    return;
                }

                $this->log($logger, 'place.skipped', [
                    'provider' => 'direct',
                    'query' => $query,
                    'url' => $url,
                    'reason' => 'no website or fetch failed',
                ]);
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * Parse a fetched organization page and merge it into $placesByKey when it has a website.
     *
     * @param  array<string, Place>  $placesByKey
     * @param  callable(string, array<string, mixed>): void|null  $logger
     * @return 'saved'|'duplicate'|'skipped'
     */
    private function handleFetchedPlace(string $url, string $html, string $location, string $query, array &$placesByKey, ?callable $logger): string
    {
        if ($this->isBlockedPage($html)) {
            $this->log($logger, 'place.skipped', [
                'provider' => 'direct',
                'query' => $query,
                'url' => $url,
                'reason' => 'blocked by anti-bot page (captcha)',
            ]);

            return 'skipped';
        }

        $data = $this->parsePlacePage($html, $url, $location);

        if (($data['website'] ?? null) === null || trim((string) $data['website']) === '') {
            $this->log($logger, 'place.skipped', [
                'provider' => 'direct',
                'query' => $query,
                'url' => $url,
                'reason' => 'no website or fetch failed',
            ]);

            return 'skipped';
        }

        $place = Place::fromArray($data);

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

            return 'duplicate';
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

        return 'saved';
    }

    /**
     * Retry a URL through the optional bypass proxy (see BypassProxyClient) -
     * only ever called after a direct request already failed or was blocked,
     * never on the normal path. Returns null (never throws) whenever the
     * bypass proxy isn't configured, can't be reached/started, or itself
     * fails, so callers can always fall back to treating the URL as skipped.
     */
    private function fetchViaBypassProxyOrNull(string $url, ?callable $logger): ?string
    {
        if ($this->bypassProxy === null) {
            return null;
        }

        if (! $this->bypassProxy->ensureStarted()) {
            return null;
        }

        try {
            $this->log($logger, 'bypass_proxy.used', [
                'provider' => 'direct',
                'url' => $url,
            ]);

            return $this->bypassProxy->fetch($url)['body'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function isBlockedPage(string $html): bool
    {
        $normalized = mb_strtolower($html, 'UTF-8');

        foreach (self::BLOCK_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
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
        $rating = $this->extractRatingData($html);

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
            'rating' => $rating['rating'] ?? null,
            'ratingsCount' => $rating['ratingsCount'] ?? null,
            'reviewCount' => $rating['reviewCount'] ?? null,
        ];
    }

    /**
     * The organization's rating/review counters live in a dedicated "ratingData" object
     * in the page's embedded JSON state - e.g. "ratingData":{"ratingCount":123,
     * "ratingValue":4.7,"reviewCount":45}. Extracted independently of field order since
     * that's the only assumption real page samples reliably support.
     *
     * @return array{rating?: float, ratingsCount?: int, reviewCount?: int}
     */
    private function extractRatingData(string $html): array
    {
        if (preg_match('~"ratingData"\s*:\s*(\{[^}]*\})~u', $html, $blockMatch) !== 1) {
            return [];
        }

        $block = $blockMatch[1];
        $result = [];

        if (preg_match('~"ratingValue"\s*:\s*([\d.]+)~', $block, $match) === 1) {
            $result['rating'] = (float) $match[1];
        }

        if (preg_match('~"ratingCount"\s*:\s*(\d+)~', $block, $match) === 1) {
            $result['ratingsCount'] = (int) $match[1];
        }

        if (preg_match('~"reviewCount"\s*:\s*(\d+)~', $block, $match) === 1) {
            $result['reviewCount'] = (int) $match[1];
        }

        return $result;
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
        $normalizedHtml = str_replace('\\/', '/', $html);

        // Only `url` represents the organization's own site. `sameAs` is schema.org's
        // field for OTHER profiles (VK, Instagram, ...) and must not be treated as a website.
        // In practice Yandex Maps org pages never embed a LocalBusiness JSON-LD block (only
        // generic WebSite/BreadcrumbList), so this is a defensive fallback, not the common path.
        $value = $jsonLd['url'] ?? null;
        if (is_string($value)) {
            $candidates[] = $value;
        }

        // The organization's own site(s) live in a dedicated "urls" ARRAY in the page's
        // embedded JSON state - e.g. "urls":["http://www.example.ru/"]. This is the reliable,
        // business-scoped source: unlike singular "url"/"href" keys, it isn't shared with the
        // page's ad banner ("profilePromo") or cross-reference "sources" (Avito, 2GIS,
        // Restoran.ru, ...), which is exactly what caused every organization to end up with
        // the SAME "website" - the first "url"/"href" match anywhere on the page, not theirs.
        if (preg_match('~"urls"\s*:\s*\[\s*"([^"]+)"~u', $normalizedHtml, $urlsMatch) === 1) {
            $candidates[] = $urlsMatch[1];
        }

        // Narrow last-resort fallback for page shapes without an "urls" array. Deliberately
        // excludes "url"/"href" - see above for why those are unsafe to scan page-wide.
        preg_match_all('~"(?:website|site)"\s*:\s*"((?:https?:)?//[^"\\\\]+)"~u', $normalizedHtml, $matches);
        $candidates = array_merge($candidates, $matches[1]);

        foreach ($candidates as $candidate) {
            $candidate = str_starts_with($candidate, '//') ? 'https:'.$candidate : $candidate;
            $host = parse_url($candidate, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            if ($this->isNonWebsiteHost($host)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function isNonWebsiteHost(string $host): bool
    {
        foreach (self::NON_WEBSITE_HOST_FRAGMENTS as $fragment) {
            if (str_contains($host, $fragment)) {
                return true;
            }
        }

        return false;
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

        // The organization's own phone numbers live in a dedicated "phones" ARRAY of
        // objects, e.g. "phones":[{"number":"+7 (985) 260-54-44","type":"phone",
        // "value":"+79852605444"}, ...]. A page-wide "any long digit sequence" regex is
        // unusable on real pages: SVG path data, coordinates, prices and IDs alone produce
        // hundreds of false-positive "phone numbers" per page - any one of which could end
        // up as the single phone number kept for a business. Scoping to the "phones" array
        // first, then reading "number" only from within it, avoids that entirely.
        if (preg_match('~"phones"\s*:\s*(\[[^\]]*\])~u', str_replace('\\/', '/', $html), $phonesBlock) === 1) {
            preg_match_all('~"number"\s*:\s*"([^"]+)"~u', $phonesBlock[1], $numberMatches);
            $phones = array_merge($phones, $numberMatches[1]);
        }

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
