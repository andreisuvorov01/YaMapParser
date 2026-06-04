<?php

declare(strict_types=1);

use YandexParser\DealType;
use YandexParser\Language;
use YandexParser\MarketRegion;
use YandexParser\MarketSort;
use YandexParser\PropertyCategory;
use YandexParser\RealtySort;
use YandexParser\ReviewSort;

it('has correct language values', function () {
    expect(Language::Auto->value)->toBe('auto')
        ->and(Language::Russian->value)->toBe('ru')
        ->and(Language::English->value)->toBe('en')
        ->and(Language::Turkish->value)->toBe('tr')
        ->and(Language::Ukrainian->value)->toBe('uk')
        ->and(Language::Kazakh->value)->toBe('kk');
});

it('can create language from value', function () {
    expect(Language::from('auto'))->toBe(Language::Auto)
        ->and(Language::from('ru'))->toBe(Language::Russian)
        ->and(Language::from('en'))->toBe(Language::English)
        ->and(Language::from('tr'))->toBe(Language::Turkish)
        ->and(Language::from('uk'))->toBe(Language::Ukrainian)
        ->and(Language::from('kk'))->toBe(Language::Kazakh);
});

it('has correct review sort values', function () {
    expect(ReviewSort::Relevance->value)->toBe('relevance')
        ->and(ReviewSort::Newest->value)->toBe('newest')
        ->and(ReviewSort::Highest->value)->toBe('highest')
        ->and(ReviewSort::Lowest->value)->toBe('lowest');
});

it('can create review sort from value', function () {
    expect(ReviewSort::from('relevance'))->toBe(ReviewSort::Relevance)
        ->and(ReviewSort::from('newest'))->toBe(ReviewSort::Newest)
        ->and(ReviewSort::from('highest'))->toBe(ReviewSort::Highest)
        ->and(ReviewSort::from('lowest'))->toBe(ReviewSort::Lowest);
});

it('has correct market sort values', function () {
    expect(MarketSort::Default->value)->toBe('')
        ->and(MarketSort::Popular->value)->toBe('dpop')
        ->and(MarketSort::PriceAsc->value)->toBe('aprice')
        ->and(MarketSort::PriceDesc->value)->toBe('dprice')
        ->and(MarketSort::Rating->value)->toBe('rating');
});

it('can create market sort from value', function () {
    expect(MarketSort::from(''))->toBe(MarketSort::Default)
        ->and(MarketSort::from('dpop'))->toBe(MarketSort::Popular)
        ->and(MarketSort::from('aprice'))->toBe(MarketSort::PriceAsc)
        ->and(MarketSort::from('dprice'))->toBe(MarketSort::PriceDesc)
        ->and(MarketSort::from('rating'))->toBe(MarketSort::Rating);
});

it('has correct market region values', function () {
    expect(MarketRegion::Moscow->value)->toBe('213')
        ->and(MarketRegion::SaintPetersburg->value)->toBe('2')
        ->and(MarketRegion::Yekaterinburg->value)->toBe('54')
        ->and(MarketRegion::Kazan->value)->toBe('43')
        ->and(MarketRegion::Novosibirsk->value)->toBe('65')
        ->and(MarketRegion::NizhnyNovgorod->value)->toBe('69')
        ->and(MarketRegion::Samara->value)->toBe('51')
        ->and(MarketRegion::RostovOnDon->value)->toBe('39')
        ->and(MarketRegion::Krasnodar->value)->toBe('35')
        ->and(MarketRegion::Chelyabinsk->value)->toBe('56')
        ->and(MarketRegion::Ufa->value)->toBe('61')
        ->and(MarketRegion::Perm->value)->toBe('47')
        ->and(MarketRegion::Voronezh->value)->toBe('62')
        ->and(MarketRegion::Volgograd->value)->toBe('63')
        ->and(MarketRegion::Krasnoyarsk->value)->toBe('66')
        ->and(MarketRegion::Omsk->value)->toBe('68');
});

it('can create market region from value', function () {
    expect(MarketRegion::from('213'))->toBe(MarketRegion::Moscow)
        ->and(MarketRegion::from('2'))->toBe(MarketRegion::SaintPetersburg)
        ->and(MarketRegion::from('54'))->toBe(MarketRegion::Yekaterinburg)
        ->and(MarketRegion::from('43'))->toBe(MarketRegion::Kazan)
        ->and(MarketRegion::from('65'))->toBe(MarketRegion::Novosibirsk)
        ->and(MarketRegion::from('69'))->toBe(MarketRegion::NizhnyNovgorod);
});

it('has correct deal type values', function () {
    expect(DealType::Sell->value)->toBe('SELL')
        ->and(DealType::Rent->value)->toBe('RENT');
});

it('can create deal type from value', function () {
    expect(DealType::from('SELL'))->toBe(DealType::Sell)
        ->and(DealType::from('RENT'))->toBe(DealType::Rent);
});

it('has correct property category values', function () {
    expect(PropertyCategory::Apartment->value)->toBe('APARTMENT')
        ->and(PropertyCategory::Rooms->value)->toBe('ROOMS')
        ->and(PropertyCategory::House->value)->toBe('HOUSE')
        ->and(PropertyCategory::Lot->value)->toBe('LOT')
        ->and(PropertyCategory::Commercial->value)->toBe('COMMERCIAL')
        ->and(PropertyCategory::Garage->value)->toBe('GARAGE');
});

it('can create property category from value', function () {
    expect(PropertyCategory::from('APARTMENT'))->toBe(PropertyCategory::Apartment)
        ->and(PropertyCategory::from('ROOMS'))->toBe(PropertyCategory::Rooms)
        ->and(PropertyCategory::from('HOUSE'))->toBe(PropertyCategory::House)
        ->and(PropertyCategory::from('LOT'))->toBe(PropertyCategory::Lot)
        ->and(PropertyCategory::from('COMMERCIAL'))->toBe(PropertyCategory::Commercial)
        ->and(PropertyCategory::from('GARAGE'))->toBe(PropertyCategory::Garage);
});

it('has correct realty sort values', function () {
    expect(RealtySort::Relevance->value)->toBe('RELEVANCE')
        ->and(RealtySort::Newest->value)->toBe('DATE_DESC')
        ->and(RealtySort::PriceAsc->value)->toBe('PRICE')
        ->and(RealtySort::PriceDesc->value)->toBe('PRICE_DESC')
        ->and(RealtySort::AreaAsc->value)->toBe('AREA')
        ->and(RealtySort::AreaDesc->value)->toBe('AREA_DESC')
        ->and(RealtySort::CommissioningDate->value)->toBe('COMMISSIONING_DATE');
});

it('can create realty sort from value', function () {
    expect(RealtySort::from('RELEVANCE'))->toBe(RealtySort::Relevance)
        ->and(RealtySort::from('DATE_DESC'))->toBe(RealtySort::Newest)
        ->and(RealtySort::from('PRICE'))->toBe(RealtySort::PriceAsc)
        ->and(RealtySort::from('PRICE_DESC'))->toBe(RealtySort::PriceDesc)
        ->and(RealtySort::from('AREA'))->toBe(RealtySort::AreaAsc)
        ->and(RealtySort::from('AREA_DESC'))->toBe(RealtySort::AreaDesc)
        ->and(RealtySort::from('COMMISSIONING_DATE'))->toBe(RealtySort::CommissioningDate);
});
