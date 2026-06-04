<?php

declare(strict_types=1);

namespace YandexParser;

enum ReviewSort: string
{
    case Relevance = 'relevance';
    case Newest = 'newest';
    case Highest = 'highest';
    case Lowest = 'lowest';
}
