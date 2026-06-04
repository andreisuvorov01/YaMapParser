<?php

declare(strict_types=1);

use YandexParser\DTO\Product;

it('creates product from array', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->title)->toBe('ASUS VivoBook 15 X1504VA-BQ613')
        ->and($product->modelId)->toBe(1971655847)
        ->and($product->marketSku)->toBe('101946073291')
        ->and($product->productUrl)->toContain('market.yandex.ru')
        ->and($product->price)->toBe(54990.0)
        ->and($product->currency)->toBe('RUB')
        ->and($product->priceYaBank)->toBe(49491.0)
        ->and($product->rating)->toBe(4.7)
        ->and($product->ratingCount)->toBe(856)
        ->and($product->reviewCount)->toBe(234)
        ->and($product->purchaseCount)->toBe(5600)
        ->and($product->brand)->toBe('ASUS')
        ->and($product->categoryName)->toBe('Laptops')
        ->and($product->description)->toContain('Intel Core i5')
        ->and($product->isAvailable)->toBeTrue()
        ->and($product->stockCount)->toBe(42)
        ->and($product->sellerName)->toBe('TechStore')
        ->and($product->sellerRating)->toBe(4.8)
        ->and($product->sellerRatingCount)->toBe(12500)
        ->and($product->businessId)->toBe(987654)
        ->and($product->shopId)->toBe(123456)
        ->and($product->isExpress)->toBeTrue()
        ->and($product->isBnpl)->toBeTrue()
        ->and($product->sponsored)->toBeFalse()
        ->and($product->searchPosition)->toBe(3)
        ->and($product->scrapedAt)->toBe('2026-02-15T14:22:00Z');
});

it('populates new identity fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->wareId)->toBe('msrGU123abc')
        ->and($product->oskuId)->toBe(103594374410)
        ->and($product->articleNumber)->toBe('103594374410')
        ->and($product->feedId)->toBe(637707)
        ->and($product->modelName)->toBe('ASUS VivoBook 15')
        ->and($product->canonicalUrl)->toContain('market.yandex.ru')
        ->and($product->productSlug)->toBe('asus-vivobook-15')
        ->and($product->oskuSlug)->toBe('asus-vivobook-15-x1504va');
});

it('populates new pricing fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->priceWithoutVat)->toBe(45825.0);
});

it('populates new seller fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->vendorId)->toBe(789012)
        ->and($product->supplierId)->toBe(456789)
        ->and($product->sellerSlug)->toBe('techstore')
        ->and($product->placementType)->toBe('3P');
});

it('populates new category fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->hid)->toBe(91013)
        ->and($product->navnodeId)->toBe(34512430)
        ->and($product->departmentId)->toBe(54440);
});

it('populates new availability fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->minOrderQuantity)->toBe(1)
        ->and($product->maxOrderQuantity)->toBe(5)
        ->and($product->isCrossBorder)->toBeFalse();
});

it('populates new offer flag fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->isOnDemand)->toBeFalse()
        ->and($product->isUltima)->toBeFalse()
        ->and($product->supplierType)->toBe(1)
        ->and($product->vat)->toBe('VAT_20')
        ->and($product->paymentType)->toBe('PREPAYMENT')
        ->and($product->paymentMethods)->toBe(['BY_CARD_ONLINE', 'IN_CASH'])
        ->and($product->offerFlags)->toBe(['isFby', 'isPartialCheckoutAvailable'])
        ->and($product->isInstallments)->toBeFalse()
        ->and($product->isDigital)->toBeFalse()
        ->and($product->deliveryPartnerTypes)->toBe(['YANDEX_MARKET'])
        ->and($product->hasVariants)->toBeFalse();
});

it('populates delivery fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->delivery)->not->toBeNull()
        ->and($product->delivery['deliveryType'])->toBe('PICKUP')
        ->and($product->deliveryAlternatives)->toHaveCount(1);
});

it('populates new media fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->ugcImages)->toHaveCount(1)
        ->and($product->hasUgcImages())->toBeTrue()
        ->and($product->videos)->toHaveCount(1)
        ->and($product->hasVideos())->toBeTrue();
});

it('populates promo and badge fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->promos)->toHaveCount(1)
        ->and($product->featureBadges)->toHaveCount(1)
        ->and($product->benefitBadge)->toBe('Great price from a trusted seller');
});

it('populates internal fields', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->shopSku)->toBe('904106.ASUS-X1504VA')
        ->and($product->feedOfferId)->toBe('637707.904106.ASUS-X1504VA')
        ->and($product->warehouseId)->toBe('172');
});

it('checks reviews availability', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->hasReviews())->toBeTrue();
});

it('checks images availability', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->hasImages())->toBeTrue()
        ->and($product->images)->toHaveCount(3);
});

it('checks in stock status', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->isInStock())->toBeTrue();
});

it('formats price with currency', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->getPriceFormatted())->toBe('54,990 RUB');
});

it('calculates YaBank discount', function () {
    $product = Product::fromArray(getSampleProductData());

    expect($product->getYaBankDiscount())->toBe(10.0);
});

it('handles missing optional fields', function () {
    $minimal = [];

    $product = Product::fromArray($minimal);

    expect($product->title)->toBeNull()
        ->and($product->modelId)->toBeNull()
        ->and($product->marketSku)->toBeNull()
        ->and($product->wareId)->toBeNull()
        ->and($product->oskuId)->toBeNull()
        ->and($product->feedId)->toBeNull()
        ->and($product->modelName)->toBeNull()
        ->and($product->canonicalUrl)->toBeNull()
        ->and($product->productUrl)->toBeNull()
        ->and($product->price)->toBeNull()
        ->and($product->currency)->toBeNull()
        ->and($product->priceYaBank)->toBeNull()
        ->and($product->priceWithoutVat)->toBeNull()
        ->and($product->rating)->toBeNull()
        ->and($product->ratingCount)->toBeNull()
        ->and($product->reviewCount)->toBeNull()
        ->and($product->brand)->toBeNull()
        ->and($product->description)->toBeNull()
        ->and($product->isAvailable)->toBeFalse()
        ->and($product->stockCount)->toBeNull()
        ->and($product->sellerName)->toBeNull()
        ->and($product->sellerSlug)->toBeNull()
        ->and($product->delivery)->toBeNull()
        ->and($product->promos)->toBeNull()
        ->and($product->benefitBadge)->toBeNull()
        ->and($product->isExpress)->toBeFalse()
        ->and($product->isBnpl)->toBeFalse()
        ->and($product->sponsored)->toBeFalse()
        ->and($product->searchPosition)->toBeNull()
        ->and($product->scrapedAt)->toBeNull()
        ->and($product->images)->toBeEmpty()
        ->and($product->ugcImages)->toBeEmpty()
        ->and($product->videos)->toBeEmpty()
        ->and($product->reviews)->toBeEmpty();
});

it('returns null for price formatted when price is null', function () {
    $product = Product::fromArray([]);

    expect($product->getPriceFormatted())->toBeNull();
});

it('formats price without currency when currency is null', function () {
    $data = getSampleProductData();
    $data['currency'] = null;

    $product = Product::fromArray($data);

    expect($product->getPriceFormatted())->toBe('54,990');
});

it('returns null for YaBank discount when price is null', function () {
    $product = Product::fromArray([]);

    expect($product->getYaBankDiscount())->toBeNull();
});

it('returns null for YaBank discount when priceYaBank is null', function () {
    $data = getSampleProductData();
    $data['priceYaBank'] = null;

    $product = Product::fromArray($data);

    expect($product->getYaBankDiscount())->toBeNull();
});

it('returns false for hasReviews when no reviews', function () {
    $product = Product::fromArray([]);

    expect($product->hasReviews())->toBeFalse();
});

it('returns false for hasImages when no images', function () {
    $product = Product::fromArray([]);

    expect($product->hasImages())->toBeFalse();
});

it('returns false for isInStock when not available', function () {
    $product = Product::fromArray([]);

    expect($product->isInStock())->toBeFalse();
});

it('returns false for hasUgcImages and hasVideos when empty', function () {
    $product = Product::fromArray([]);

    expect($product->hasUgcImages())->toBeFalse()
        ->and($product->hasVideos())->toBeFalse();
});
