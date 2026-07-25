# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project Overview

PHP client library for 4 Apify actors that scrape Yandex data: places/businesses from Yandex Maps, reviews from Yandex Maps, products from Yandex Market, and real estate listings from Yandex Realty. Returns typed DTOs for each data type.

## Build Commands

```bash
composer install          # Install dependencies
composer test             # Run Pest tests
composer test -- --filter TestName  # Run single test
composer cs               # Check code style (Laravel Pint)
composer cs:fix           # Fix code style
composer analyse          # Run PHPStan static analysis (level 8)
```

## Architecture

```
src/
  Client.php              # Main API client with 4 scraping methods
  Config.php              # Configuration (API token, actor IDs, base URL, timeout)
  Language.php            # Enum: auto, ru, en, tr, uk, kk
  ReviewSort.php          # Enum: relevance, newest, highest, lowest
  MarketSort.php          # Enum: default, dpop, aprice, dprice, rating
  MarketRegion.php        # Enum: Moscow=213, SPb=2, etc. (16 cities)
  DealType.php            # Enum: SELL, RENT
  PropertyCategory.php    # Enum: APARTMENT, ROOMS, HOUSE, LOT, COMMERCIAL, GARAGE
  RealtySort.php          # Enum: RELEVANCE, DATE_DESC, PRICE, PRICE_DESC, AREA, AREA_DESC, COMMISSIONING_DATE
  DTO/
    Place.php             # Place/business with contacts, ratings, schedule, reviews, photos, videos
    Review.php            # Review with author info, business reply, AI analysis, translations
    Product.php           # Yandex Market product with pricing, seller, specs, delivery, media
    Listing.php           # Yandex Realty listing with price, location, property details, predictions
  Exception/
    ApiException.php      # Base API exception
    RateLimitException.php
tests/
```

## Actors

| Actor | ID | Description |
|-------|----|-------------|
| Places | `zen-studio/yandex-places-scraper` | Search Yandex Maps by keyword + location |
| Reviews | `zen-studio/yandex-reviews-scraper` | Scrape reviews from Yandex Maps businesses |
| Market | `zen-studio/yandex-market-scraper-parser` | Scrape products from Yandex Market |
| Realty | `zen-studio/yandex-realty-scraper` | Scrape real estate listings from Yandex Realty |

## Key Design Decisions

- DTOs are immutable (readonly class with readonly properties)
- All DTOs use named constructors (`fromArray()`) for Apify JSON mapping
- Nullable types for optional fields from the API
- Backed string enums for all filter/sort/language/region values
- Empty string enum values (Auto/Default) are excluded from API input
- Shared `runActor()` private method starts the actor, polls `actor-runs/{id}` until the run reaches a terminal
  status (Apify caps `waitForFinish` server-side at 300s, so long runs need explicit polling), then fetches the dataset
- All HTTP calls go through `Client::requestJson()`, which validates the JSON body and transparently retries `429`
  responses (honoring `Retry-After`) up to `Config::$maxRetries` before throwing `RateLimitException`
- Client constructor: `new Client('apify_api_token')` or `new Client('token', new Config(...))`; a 4th `$sleeper`
  closure param exists purely so tests can stub out real waiting during retry/poll loops
- `Config` also carries `maxRetries` and an optional `proxy` URL, applied to the underlying Guzzle client
- `DirectYandexMapsProvider` supports an optional `proxy`, a `concurrency` option (Guzzle `Pool`-based parallel page
  fetches, default 1/sequential), and detects Yandex's anti-bot/CAPTCHA interstitial pages instead of silently
  treating them as "no results"
- `DirectYandexMapsProvider::extractWebsite()` only treats JSON-LD `url` as the website; `sameAs` (schema.org's field
  for social profiles) is never used as a candidate, and both it and the regex HTML fallback are filtered through
  `NON_WEBSITE_HOST_FRAGMENTS` (vk.com, instagram.com, t.me, ...) so a social/messenger link is never returned as
  "the website" instead of the real company site (or instead of no website at all)
- `extractWebsite()`'s primary source is the page's own `"urls":[...]` JSON array (the organization's real site);
  `extractPhones()`'s primary source is `"phones":[{"number":...}]`. Both replaced earlier page-wide regexes that
  matched ad banners (`profilePromo`), cross-reference "sources" (Avito/2GIS/Restoran.ru/...), and effectively
  arbitrary digit runs (SVG path data, IDs, coordinates) - on real pages those produced the same wrong website for
  every organization, or hundreds of false-positive "phone numbers" per page. `extractRatingData()` similarly reads
  the dedicated `"ratingData":{...}` object for rating/ratingsCount/reviewCount
- `findOrganizationUrls()` resolves known Russian cities (`KNOWN_CITY_GEO_IDS`, verified against live Maps - do not
  reuse `MarketRegion`'s IDs here, several are wrong for Maps/right only for Market) to a geo-scoped search URL
  (`/maps/{geoId}/.../search/{text}/`) instead of free text; free-text search on `"<rubric> <city>"` can exact-match
  an unrelated place literally named that way instead of listing the rubric in that city. Unknown locations fall
  back to the previous free-text search, so this only ever narrows behavior for the 16 listed cities
- The search-page request retries once (`SEARCH_PAGE_MAX_RETRIES`) before falling back to the bypass proxy/giving up
  on that query, absorbing transient connection errors that previously could stop pagination after one hiccup;
  dedup during URL discovery is now by business ID (not exact URL string), since Yandex serves the same org's page
  under multiple `<link rel="alternate">` domains (yandex.ru/.com/.kz/...) that used to count as separate results
- `Provider\BypassProxyClient` is an optional fallback for `DirectYandexMapsProvider`: it's only ever consulted
  after a direct request already failed or came back as a blocked/CAPTCHA page (never on the normal path), talks
  over HTTP to a separately-run proxy service (`POST /fetch`, `GET /health`), and can best-effort spawn that
  service from a configured directory on first need. Wired via `CollectWebsitesCommand`'s `--bypass-proxy-url` /
  `--bypass-proxy-dir` / `--bypass-proxy-python`; with none of them set, this feature is a complete no-op
- `CollectWebsitesCommand` writes `<output>.progress.json` after every finished query/rubric; `--resume` skips
  queries already marked done there, letting a crashed/rate-limited run continue instead of restarting
