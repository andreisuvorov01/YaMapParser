<?php

declare(strict_types=1);

use YandexParser\DTO\Place;

it('creates place from array', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->businessId)->toBe('1124715036')
        ->and($place->title)->toBe('Pushkin')
        ->and($place->description)->toBe('Legendary restaurant of Russian cuisine in a historic mansion on Tverskoy Boulevard.')
        ->and($place->type)->toBe('restaurant')
        ->and($place->url)->toBe('https://yandex.ru/maps/org/pushkin/1124715036/')
        ->and($place->searchQuery)->toBe('restaurant')
        ->and($place->longitude)->toBe(37.600312)
        ->and($place->latitude)->toBe(55.764353)
        ->and($place->address)->toContain('Tverskoy Boulevard')
        ->and($place->country)->toBe('Russia')
        ->and($place->city)->toBe('Moscow')
        ->and($place->postalCode)->toBe('125009')
        ->and($place->status)->toBe('open')
        ->and($place->isOpenNow)->toBeTrue()
        ->and($place->isVerifiedOwner)->toBeTrue()
        ->and($place->rating)->toBe(4.6)
        ->and($place->ratingsCount)->toBe(3150)
        ->and($place->reviewCount)->toBe(2847)
        ->and($place->website)->toBe('https://cafe-pushkin.ru')
        ->and($place->email)->toBe('info@cafe-pushkin.ru')
        ->and($place->chainName)->toBe('Maison Dellos')
        ->and($place->photoCount)->toBe(245)
        ->and($place->neurosummary)->toContain('exquisite Russian cuisine');
});

it('populates new media fields', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->videoCount)->toBe(3)
        ->and($place->videos)->toHaveCount(1)
        ->and($place->videos[0]['url'])->toContain('youtube.com')
        ->and($place->posts)->toHaveCount(1);
});

it('populates geo and region fields', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->regionId)->toBe(213)
        ->and($place->geoId)->toBe(213)
        ->and($place->shortTitle)->toBe('Pushkin')
        ->and($place->timezoneOffset)->toBe(10800)
        ->and($place->panorama)->not->toBeNull()
        ->and($place->bounds)->not->toBeNull()
        ->and($place->entrances)->toHaveCount(1);
});

it('populates booking fields', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->bookingLinks)->toHaveCount(1)
        ->and($place->bookingPartner)->not->toBeNull()
        ->and($place->bookingPartner['partner'])->toBe('YandexEda');
});

it('populates menu and legal fields', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->menu)->not->toBeNull()
        ->and($place->menu['totalItems'])->toBe(116)
        ->and($place->hasMenu())->toBeTrue()
        ->and($place->legalInfo)->not->toBeNull()
        ->and($place->legalInfo['name'])->toBe('OOO Maison Dellos')
        ->and($place->additionalAddress)->toBe('2nd floor, entrance from the courtyard')
        ->and($place->promo)->not->toBeNull()
        ->and($place->actionButtons)->toHaveCount(1);
});

it('populates transit and badge fields', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->nearbyMetro)->toHaveCount(1)
        ->and($place->nearbyStops)->toHaveCount(1)
        ->and($place->badges)->toBe(['Michelin', 'Popular'])
        ->and($place->awards)->not->toBeNull();
});

it('has snippet as array', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->snippet)->toBeArray()
        ->and($place->snippet['text'])->toBe('Famous Russian cuisine restaurant');
});

it('checks contact info availability', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->hasContactInfo())->toBeTrue();
});

it('gets first phone number', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->getFirstPhone())->toBe('+74955992664');
});

it('checks website availability', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->hasWebsite())->toBeTrue();
});

it('gets coordinates', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->getCoordinates())->toBe(['lng' => 37.600312, 'lat' => 55.764353]);
});

it('checks verified status', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->isVerified())->toBeTrue();
});

it('checks video availability', function () {
    $place = Place::fromArray(getSamplePlaceData());

    expect($place->hasVideos())->toBeTrue();
});

it('handles missing optional fields', function () {
    $minimal = [
        'businessId' => '123',
        'title' => 'Test Place',
    ];

    $place = Place::fromArray($minimal);

    expect($place->businessId)->toBe('123')
        ->and($place->title)->toBe('Test Place')
        ->and($place->description)->toBeNull()
        ->and($place->type)->toBeNull()
        ->and($place->url)->toBeNull()
        ->and($place->longitude)->toBeNull()
        ->and($place->latitude)->toBeNull()
        ->and($place->address)->toBeNull()
        ->and($place->rating)->toBeNull()
        ->and($place->reviewCount)->toBeNull()
        ->and($place->website)->toBeNull()
        ->and($place->email)->toBeNull()
        ->and($place->isOpenNow)->toBeFalse()
        ->and($place->isVerifiedOwner)->toBeFalse()
        ->and($place->neurosummary)->toBeNull()
        ->and($place->snippet)->toBeNull()
        ->and($place->videoCount)->toBe(0)
        ->and($place->regionId)->toBeNull()
        ->and($place->geoId)->toBeNull()
        ->and($place->menu)->toBeNull()
        ->and($place->legalInfo)->toBeNull()
        ->and($place->promo)->toBeNull()
        ->and($place->bookingPartner)->toBeNull();
});

it('handles empty arrays for collections', function () {
    $minimal = [
        'businessId' => '123',
        'title' => 'Test Place',
    ];

    $place = Place::fromArray($minimal);

    expect($place->phones)->toBeEmpty()
        ->and($place->categories)->toBeEmpty()
        ->and($place->reviews)->toBeEmpty()
        ->and($place->photos)->toBeEmpty()
        ->and($place->posts)->toBeEmpty()
        ->and($place->videos)->toBeEmpty()
        ->and($place->bookingLinks)->toBeEmpty()
        ->and($place->nearbyMetro)->toBeEmpty()
        ->and($place->nearbyStops)->toBeEmpty()
        ->and($place->badges)->toBeEmpty()
        ->and($place->entrances)->toBeEmpty()
        ->and($place->sources)->toBeEmpty()
        ->and($place->featureGroups)->toBeEmpty()
        ->and($place->socialLinks)->toBeEmpty()
        ->and($place->schedule)->toBeEmpty();
});

it('returns false for contact info when no phones or email', function () {
    $minimal = [
        'businessId' => '123',
        'title' => 'Test Place',
    ];

    $place = Place::fromArray($minimal);

    expect($place->hasContactInfo())->toBeFalse()
        ->and($place->getFirstPhone())->toBeNull();
});

it('returns null coordinates when longitude or latitude missing', function () {
    $data = getSamplePlaceData();
    unset($data['longitude']);

    $place = Place::fromArray($data);

    expect($place->getCoordinates())->toBeNull();
});

it('returns false for hasWebsite when website is null', function () {
    $data = getSamplePlaceData();
    $data['website'] = null;

    $place = Place::fromArray($data);

    expect($place->hasWebsite())->toBeFalse();
});

it('returns false for isVerified when not verified', function () {
    $data = getSamplePlaceData();
    $data['isVerifiedOwner'] = false;

    $place = Place::fromArray($data);

    expect($place->isVerified())->toBeFalse();
});

it('returns false for hasMenu when no menu', function () {
    $minimal = ['businessId' => '123', 'title' => 'Test'];
    $place = Place::fromArray($minimal);

    expect($place->hasMenu())->toBeFalse();
});

it('returns false for hasVideos when video count is 0', function () {
    $minimal = ['businessId' => '123', 'title' => 'Test'];
    $place = Place::fromArray($minimal);

    expect($place->hasVideos())->toBeFalse();
});
