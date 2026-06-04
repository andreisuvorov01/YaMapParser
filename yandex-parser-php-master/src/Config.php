<?php

declare(strict_types=1);

namespace YandexParser;

final readonly class Config
{
    public const PLACES_ACTOR_ID = 'zen-studio/yandex-places-scraper';

    public const REVIEWS_ACTOR_ID = 'zen-studio/yandex-reviews-scraper';

    public const MARKET_ACTOR_ID = 'zen-studio/yandex-market-scraper-parser';

    public const REALTY_ACTOR_ID = 'zen-studio/yandex-realty-scraper';

    public function __construct(
        public string $apiToken,
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 900,
    ) {}
}
