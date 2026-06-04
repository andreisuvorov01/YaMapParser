<?php

declare(strict_types=1);

namespace YandexParser;

enum PropertyCategory: string
{
    case Apartment = 'APARTMENT';
    case Rooms = 'ROOMS';
    case House = 'HOUSE';
    case Lot = 'LOT';
    case Commercial = 'COMMERCIAL';
    case Garage = 'GARAGE';
}
