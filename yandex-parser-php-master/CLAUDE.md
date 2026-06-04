# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
- Shared `runActor()` private method handles POST to run actor + fetch dataset
- Client constructor: `new Client('apify_api_token')` or `new Client('token', new Config(...))`
