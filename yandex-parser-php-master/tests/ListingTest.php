<?php

declare(strict_types=1);

use YandexParser\DTO\Listing;

it('creates listing from array', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->offerId)->toBe('6352161035621587728')
        ->and($listing->offerType)->toBe('SELL')
        ->and($listing->offerCategory)->toBe('APARTMENT')
        ->and($listing->url)->toContain('realty.yandex.ru')
        ->and($listing->description)->toContain('Sochi')
        ->and($listing->roomsTotal)->toBe(3)
        ->and($listing->floorsTotal)->toBe(5)
        ->and($listing->ceilingHeight)->toBe(2.6)
        ->and($listing->flatType)->toBe('SECONDARY')
        ->and($listing->openPlan)->toBeFalse()
        ->and($listing->active)->toBeTrue()
        ->and($listing->premium)->toBeTrue()
        ->and($listing->promoted)->toBeTrue()
        ->and($listing->newBuilding)->toBeFalse()
        ->and($listing->trust)->toBe('NORMAL');
});

it('has nested price object', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->price)->toBeArray()
        ->and($listing->price['value'])->toBe(16000000)
        ->and($listing->price['currency'])->toBe('RUR')
        ->and($listing->price['trend'])->toBe('DECREASED')
        ->and($listing->price['previous'])->toBe(16900000);
});

it('has nested location object', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->location)->toBeArray()
        ->and($listing->location['address'])->toContain('Войкова')
        ->and($listing->location['point']['latitude'])->toBe(43.584198)
        ->and($listing->location['localityName'])->toBe('Сочи');
});

it('has nested building and apartment objects', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->building)->not->toBeNull()
        ->and($listing->building['builtYear'])->toBe(1966)
        ->and($listing->building['buildingType'])->toBe('BRICK')
        ->and($listing->apartment)->not->toBeNull()
        ->and($listing->apartment['renovation'])->toBe('EURO')
        ->and($listing->house)->not->toBeNull()
        ->and($listing->house['bathroomUnit'])->toBe('MATCHED');
});

it('gets price value', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getPriceValue())->toBe(16000000);
});

it('gets price currency', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getPriceCurrency())->toBe('RUR');
});

it('gets price trend', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getPriceTrend())->toBe('DECREASED');
});

it('gets previous price', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getPreviousPrice())->toBe(16900000);
});

it('gets address', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getAddress())->toContain('Войкова');
});

it('gets coordinates', function () {
    $listing = Listing::fromArray(getSampleListingData());

    $coords = $listing->getCoordinates();
    expect($coords)->not->toBeNull()
        ->and($coords['lat'])->toBe(43.584198)
        ->and($coords['lng'])->toBe(39.72484);
});

it('gets city and region', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getCity())->toBe('Сочи')
        ->and($listing->getRegion())->toBe('Краснодарский край');
});

it('gets area value', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getAreaValue())->toBe(52.0);
});

it('checks phones availability', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->hasPhones())->toBeTrue()
        ->and($listing->getFirstPhone())->toBe('+79123827604');
});

it('gets whatsapp phones', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getWhatsAppPhones())->toBe(['+79324886757']);
});

it('checks images availability', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->hasImages())->toBeTrue()
        ->and($listing->fullImages)->toHaveCount(2)
        ->and($listing->totalImages)->toBe(20);
});

it('gets predicted price', function () {
    $listing = Listing::fromArray(getSampleListingData());

    $predicted = $listing->getPredictedPrice();
    expect($predicted)->not->toBeNull()
        ->and($predicted['min'])->toBe(15806000)
        ->and($predicted['max'])->toBe(19318000)
        ->and($predicted['value'])->toBe(17562000);
});

it('gets seller name from author', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getSellerName())->toBe('Агентство недвижимости «СОВА»');
});

it('checks if listing is from owner', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->isFromOwner())->toBeFalse();
});

it('checks if listing is from owner when category is OWNER', function () {
    $data = getSampleListingData();
    $data['author']['category'] = 'OWNER';
    $listing = Listing::fromArray($data);

    expect($listing->isFromOwner())->toBeTrue();
});

it('gets building year', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->getBuildingYear())->toBe(1966);
});

it('checks price history', function () {
    $listing = Listing::fromArray(getSampleListingData());

    expect($listing->hasPriceHistory())->toBeFalse();
});

it('handles missing optional fields', function () {
    $minimal = [
        'offerId' => '123',
        'offerType' => 'SELL',
        'offerCategory' => 'APARTMENT',
        'price' => ['value' => 5000000, 'currency' => 'RUR'],
        'location' => ['address' => 'Test address'],
    ];

    $listing = Listing::fromArray($minimal);

    expect($listing->offerId)->toBe('123')
        ->and($listing->offerType)->toBe('SELL')
        ->and($listing->offerCategory)->toBe('APARTMENT')
        ->and($listing->description)->toBeNull()
        ->and($listing->roomsTotal)->toBeNull()
        ->and($listing->floorsTotal)->toBeNull()
        ->and($listing->ceilingHeight)->toBeNull()
        ->and($listing->apartment)->toBeNull()
        ->and($listing->building)->toBeNull()
        ->and($listing->house)->toBeNull()
        ->and($listing->lot)->toBeNull()
        ->and($listing->author)->toBeNull()
        ->and($listing->phones)->toBeNull()
        ->and($listing->history)->toBeNull()
        ->and($listing->predictions)->toBeNull()
        ->and($listing->fullImages)->toBeEmpty()
        ->and($listing->tags)->toBeEmpty()
        ->and($listing->floorsOffered)->toBeEmpty();
});

it('returns null for helpers when data is missing', function () {
    $minimal = [
        'offerId' => '123',
        'offerType' => 'SELL',
        'offerCategory' => 'APARTMENT',
        'price' => [],
        'location' => [],
    ];

    $listing = Listing::fromArray($minimal);

    expect($listing->getPriceValue())->toBeNull()
        ->and($listing->getPriceCurrency())->toBeNull()
        ->and($listing->getPriceTrend())->toBeNull()
        ->and($listing->getPreviousPrice())->toBeNull()
        ->and($listing->getAddress())->toBeNull()
        ->and($listing->getCoordinates())->toBeNull()
        ->and($listing->getCity())->toBeNull()
        ->and($listing->getRegion())->toBeNull()
        ->and($listing->getAreaValue())->toBeNull()
        ->and($listing->hasPhones())->toBeFalse()
        ->and($listing->getFirstPhone())->toBeNull()
        ->and($listing->getWhatsAppPhones())->toBeEmpty()
        ->and($listing->hasImages())->toBeFalse()
        ->and($listing->hasPriceHistory())->toBeFalse()
        ->and($listing->getSellerName())->toBeNull()
        ->and($listing->getBuildingYear())->toBeNull()
        ->and($listing->getPredictedPrice())->toBeNull();
});
