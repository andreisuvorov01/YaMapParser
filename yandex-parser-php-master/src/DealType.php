<?php

declare(strict_types=1);

namespace YandexParser;

enum DealType: string
{
    case Sell = 'SELL';
    case Rent = 'RENT';
}
