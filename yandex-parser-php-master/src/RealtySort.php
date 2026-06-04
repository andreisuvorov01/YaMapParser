<?php

declare(strict_types=1);

namespace YandexParser;

enum RealtySort: string
{
    case Relevance = 'RELEVANCE';
    case Newest = 'DATE_DESC';
    case PriceAsc = 'PRICE';
    case PriceDesc = 'PRICE_DESC';
    case AreaAsc = 'AREA';
    case AreaDesc = 'AREA_DESC';
    case CommissioningDate = 'COMMISSIONING_DATE';
}
