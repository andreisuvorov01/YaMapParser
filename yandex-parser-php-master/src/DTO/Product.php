<?php

declare(strict_types=1);

namespace YandexParser\DTO;

final readonly class Product
{
    /**
     * @param  array<int, array{name: string, value: string}>|null  $specifications
     * @param  string[]  $images
     * @param  string[]  $ugcImages
     * @param  array<int, array{url: string, duration?: int, previewUrl?: string}>  $videos
     * @param  array<int, mixed>  $reviews
     * @param  array<string, int>|null  $ratingDistribution
     * @param  array<int, array{name: string, url?: string, position?: string}>|null  $breadcrumbs
     * @param  array<string, mixed>|null  $delivery
     * @param  array<int, array<string, mixed>>|null  $deliveryAlternatives
     * @param  string[]|null  $paymentMethods
     * @param  string[]|null  $offerFlags
     * @param  string[]|null  $deliveryPartnerTypes
     * @param  array<int, array<string, mixed>>|null  $promos
     * @param  array<int, mixed>|null  $featureBadges
     */
    public function __construct(
        // Identity
        public ?string $title,
        public ?int $modelId,
        public ?string $marketSku,
        public ?string $wareId,
        public ?int $oskuId,
        public ?string $articleNumber,
        public ?int $businessId,
        public ?int $feedId,

        // Product info
        public ?string $modelName,
        public ?string $brand,
        public ?string $description,
        public ?string $productUrl,
        public ?string $canonicalUrl,
        public ?string $productSlug,
        public ?string $oskuSlug,

        // Pricing
        public ?float $price,
        public ?string $currency,
        public ?float $priceYaBank,
        public ?float $priceWithoutVat,

        // Rating & reviews
        public ?float $rating,
        public ?int $ratingCount,
        public ?int $reviewCount,
        public ?int $purchaseCount,
        public ?array $ratingDistribution,

        // Seller
        public ?int $shopId,
        public ?int $vendorId,
        public ?int $supplierId,
        public ?string $sellerName,
        public ?float $sellerRating,
        public ?int $sellerRatingCount,
        public ?string $sellerLogo,
        public ?string $sellerSlug,
        public ?string $placementType,

        // Category
        public ?int $hid,
        public ?int $navnodeId,
        public ?int $departmentId,
        public ?string $categoryName,
        public ?array $breadcrumbs,

        // Availability & stock
        public bool $isAvailable,
        public ?int $stockCount,
        public ?int $minOrderQuantity,
        public ?int $maxOrderQuantity,
        public ?bool $isCrossBorder,

        // Offer flags
        public bool $isExpress,
        public ?bool $isOnDemand,
        public ?bool $isUltima,
        public bool $sponsored,
        public ?int $supplierType,
        public ?string $vat,
        public ?string $paymentType,
        public ?array $paymentMethods,
        public bool $isBnpl,
        public ?array $offerFlags,
        public ?bool $isInstallments,
        public ?bool $isDigital,
        public ?array $deliveryPartnerTypes,
        public ?bool $hasVariants,

        // Delivery
        public ?array $delivery,
        public ?array $deliveryAlternatives,

        // Media
        public array $images,
        public array $ugcImages,
        public array $videos,

        // Specifications
        public ?array $specifications,

        // Promos & badges
        public ?array $promos,
        public ?array $featureBadges,
        public ?string $benefitBadge,

        // Internals
        public ?string $shopSku,
        public ?string $feedOfferId,
        public ?string $warehouseId,
        public ?int $searchPosition,
        public ?string $scrapedAt,

        // Reviews (optional, from includeReviews)
        public array $reviews,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            // Identity
            title: $data['title'] ?? null,
            modelId: isset($data['modelId']) ? (int) $data['modelId'] : null,
            marketSku: isset($data['marketSku']) ? (string) $data['marketSku'] : null,
            wareId: $data['wareId'] ?? null,
            oskuId: isset($data['oskuId']) ? (int) $data['oskuId'] : null,
            articleNumber: isset($data['articleNumber']) ? (string) $data['articleNumber'] : null,
            businessId: isset($data['businessId']) ? (int) $data['businessId'] : null,
            feedId: isset($data['feedId']) ? (int) $data['feedId'] : null,

            // Product info
            modelName: $data['modelName'] ?? null,
            brand: $data['brand'] ?? null,
            description: $data['description'] ?? null,
            productUrl: $data['productUrl'] ?? null,
            canonicalUrl: $data['canonicalUrl'] ?? null,
            productSlug: $data['productSlug'] ?? null,
            oskuSlug: $data['oskuSlug'] ?? null,

            // Pricing
            price: isset($data['price']) ? (float) $data['price'] : null,
            currency: $data['currency'] ?? null,
            priceYaBank: isset($data['priceYaBank']) ? (float) $data['priceYaBank'] : null,
            priceWithoutVat: isset($data['priceWithoutVat']) ? (float) $data['priceWithoutVat'] : null,

            // Rating & reviews
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            ratingCount: isset($data['ratingCount']) ? (int) $data['ratingCount'] : null,
            reviewCount: isset($data['reviewCount']) ? (int) $data['reviewCount'] : null,
            purchaseCount: isset($data['purchaseCount']) ? (int) $data['purchaseCount'] : null,
            ratingDistribution: $data['ratingDistribution'] ?? null,

            // Seller
            shopId: isset($data['shopId']) ? (int) $data['shopId'] : null,
            vendorId: isset($data['vendorId']) ? (int) $data['vendorId'] : null,
            supplierId: isset($data['supplierId']) ? (int) $data['supplierId'] : null,
            sellerName: $data['sellerName'] ?? null,
            sellerRating: isset($data['sellerRating']) ? (float) $data['sellerRating'] : null,
            sellerRatingCount: isset($data['sellerRatingCount']) ? (int) $data['sellerRatingCount'] : null,
            sellerLogo: $data['sellerLogo'] ?? null,
            sellerSlug: $data['sellerSlug'] ?? null,
            placementType: $data['placementType'] ?? null,

            // Category
            hid: isset($data['hid']) ? (int) $data['hid'] : null,
            navnodeId: isset($data['navnodeId']) ? (int) $data['navnodeId'] : null,
            departmentId: isset($data['departmentId']) ? (int) $data['departmentId'] : null,
            categoryName: $data['categoryName'] ?? null,
            breadcrumbs: $data['breadcrumbs'] ?? null,

            // Availability & stock
            isAvailable: $data['isAvailable'] ?? false,
            stockCount: isset($data['stockCount']) ? (int) $data['stockCount'] : null,
            minOrderQuantity: isset($data['minOrderQuantity']) ? (int) $data['minOrderQuantity'] : null,
            maxOrderQuantity: isset($data['maxOrderQuantity']) ? (int) $data['maxOrderQuantity'] : null,
            isCrossBorder: $data['isCrossBorder'] ?? null,

            // Offer flags
            isExpress: $data['isExpress'] ?? false,
            isOnDemand: $data['isOnDemand'] ?? null,
            isUltima: $data['isUltima'] ?? null,
            sponsored: $data['sponsored'] ?? false,
            supplierType: isset($data['supplierType']) ? (int) $data['supplierType'] : null,
            vat: $data['vat'] ?? null,
            paymentType: $data['paymentType'] ?? null,
            paymentMethods: $data['paymentMethods'] ?? null,
            isBnpl: $data['isBnpl'] ?? false,
            offerFlags: $data['offerFlags'] ?? null,
            isInstallments: $data['isInstallments'] ?? null,
            isDigital: $data['isDigital'] ?? null,
            deliveryPartnerTypes: $data['deliveryPartnerTypes'] ?? null,
            hasVariants: $data['hasVariants'] ?? null,

            // Delivery
            delivery: $data['delivery'] ?? null,
            deliveryAlternatives: $data['deliveryAlternatives'] ?? null,

            // Media
            images: $data['images'] ?? [],
            ugcImages: $data['ugcImages'] ?? [],
            videos: $data['videos'] ?? [],

            // Specifications
            specifications: $data['specifications'] ?? null,

            // Promos & badges
            promos: $data['promos'] ?? null,
            featureBadges: $data['featureBadges'] ?? null,
            benefitBadge: $data['benefitBadge'] ?? null,

            // Internals
            shopSku: $data['shopSku'] ?? null,
            feedOfferId: $data['feedOfferId'] ?? null,
            warehouseId: isset($data['warehouseId']) ? (string) $data['warehouseId'] : null,
            searchPosition: isset($data['searchPosition']) ? (int) $data['searchPosition'] : null,
            scrapedAt: $data['scrapedAt'] ?? null,

            // Reviews
            reviews: $data['reviews'] ?? [],
        );
    }

    /**
     * Check if the product has reviews.
     */
    public function hasReviews(): bool
    {
        return count($this->reviews) > 0;
    }

    /**
     * Check if the product has images.
     */
    public function hasImages(): bool
    {
        return count($this->images) > 0;
    }

    /**
     * Check if the product is in stock.
     */
    public function isInStock(): bool
    {
        return $this->isAvailable;
    }

    /**
     * Get price formatted with currency, or null if not available.
     */
    public function getPriceFormatted(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        $formatted = number_format($this->price, 0, '.', ',');

        return $this->currency !== null ? "{$formatted} {$this->currency}" : $formatted;
    }

    /**
     * Get the percentage discount when paying with YaBank card, or null if not available.
     */
    public function getYaBankDiscount(): ?float
    {
        if ($this->price === null || $this->priceYaBank === null || $this->price <= 0) {
            return null;
        }

        return round((1 - $this->priceYaBank / $this->price) * 100, 1);
    }

    /**
     * Check if the product has user-generated images.
     */
    public function hasUgcImages(): bool
    {
        return count($this->ugcImages) > 0;
    }

    /**
     * Check if the product has videos.
     */
    public function hasVideos(): bool
    {
        return count($this->videos) > 0;
    }
}
