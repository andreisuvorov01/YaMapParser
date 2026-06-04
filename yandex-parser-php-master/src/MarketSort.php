<?php

declare(strict_types=1);

namespace YandexParser;

enum MarketSort: string
{
    case Default = '';
    case Popular = 'dpop';
    case PriceAsc = 'aprice';
    case PriceDesc = 'dprice';
    case Rating = 'rating';
}
