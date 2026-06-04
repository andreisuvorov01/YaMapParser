<?php

declare(strict_types=1);

namespace YandexParser\DTO;

final readonly class Listing
{
    /**
     * @param  array<string, mixed>  $price  Price object: {value, currency, period, trend, previous, hasPriceHistory, valuePerPart, unitPerPart, ...}
     * @param  array{value: float, unit: string}|null  $area  Total area: {value, unit}
     * @param  array<string, mixed>  $location  Location object: {address, point, structuredAddress, subjectFederationName, localityName, parks, ponds, station, ...}
     * @param  array<string, mixed>|null  $author  Author/seller: {id, category, organization, agentName, phones, whatsappPhones, profile, ...}
     * @param  array<string, mixed>|null  $apartment  Apartment details: {renovation, improvements, ...}
     * @param  array<string, mixed>|null  $building  Building info: {builtYear, buildingType, parkingType, improvements, ...}
     * @param  array<string, mixed>|null  $house  House-specific: {bathroomUnit, balconyType, ...}
     * @param  array<string, mixed>|null  $lot  Land plot info
     * @param  array{value: float, unit: string}|null  $kitchenSpace
     * @param  array{value: float, unit: string}|null  $livingSpace
     * @param  array{value: float, unit: string}|null  $roomSpace
     * @param  string[]  $fullImages
     * @param  string[]  $tags
     * @param  int[]  $floorsOffered
     * @param  array<string, mixed>|null  $predictions  Price predictions: {predictedPrice: {min, max, value}}
     * @param  array<int, mixed>|null  $history  Price change history
     * @param  array<string, mixed>|null  $phones  Phone data: {phones: [{phoneNumber}], contacts: [...]}
     * @param  array<string, mixed>|null  $supplyMap  Supply map: {GAS: true, ...}
     * @param  array<string, mixed>|null  $transactionConditionsMap  Transaction conditions: {MORTGAGE: true, ...}
     * @param  array<string, mixed>|null  $rentConditionsMap  Rent conditions
     * @param  array<string, string>|null  $offerLinks  Related search links
     * @param  array<string, mixed>|null  $tuzInfo  Promoted listing info
     * @param  array<string, mixed>|null  $trustedOfferInfo  Trust verification info
     * @param  array<string, mixed>|null  $remoteReview  Online show / video tour
     * @param  string[]|null  $allowedCommunicationChannels
     * @param  array<int, mixed>|null  $salesDepartments
     * @param  array<string, mixed>|null  $siteInfo
     * @param  array<string, mixed>|null  $enrichedFields
     */
    public function __construct(
        // Identity
        public string $offerId,
        public string $offerType,
        public string $offerCategory,
        public ?string $url,
        public ?string $shareUrl,
        public ?string $description,

        // Price
        public array $price,

        // Property details
        public ?array $area,
        public ?int $roomsTotal,
        public array $floorsOffered,
        public ?int $floorsTotal,
        public ?float $ceilingHeight,
        public ?string $flatType,
        public bool $openPlan,

        // Spaces
        public ?array $kitchenSpace,
        public ?array $livingSpace,
        public ?array $roomSpace,

        // Location
        public array $location,

        // Seller / author
        public ?array $author,
        public ?array $phones,

        // Dates
        public ?string $creationDate,
        public ?string $updateDate,

        // Property structures
        public ?array $apartment,
        public ?array $building,
        public ?array $house,
        public ?array $lot,

        // Media
        public array $fullImages,
        public ?int $totalImages,

        // Price history & predictions
        public ?array $history,
        public ?array $predictions,

        // Status & flags
        public bool $active,
        public ?string $dealStatus,
        public bool $exclusive,
        public bool $premium,
        public bool $promoted,
        public bool $raised,
        public bool $newBuilding,
        public bool $notForAgents,
        public ?bool $suspicious,
        public ?string $trust,

        // Metadata
        public array $tags,
        public ?int $views,
        public ?string $uid,
        public ?string $platform,
        public ?string $partnerId,
        public ?string $partnerName,

        // Clustering
        public ?string $clusterId,
        public ?bool $clusterHeader,
        public ?int $clusterSize,

        // Conditions & supply
        public ?array $supplyMap,
        public ?array $transactionConditionsMap,
        public ?string $agentFee,
        public ?string $minRentPeriod,
        public ?array $rentConditionsMap,
        public ?bool $utilitiesIncluded,

        // Links & channels
        public ?array $offerLinks,
        public ?array $allowedCommunicationChannels,

        // Promotions & trust
        public ?array $tuzInfo,
        public ?array $trustedOfferInfo,
        public ?array $remoteReview,

        // Enriched fields
        public ?array $enrichedFields,

        // Other
        public ?bool $hasPaidCalls,
        public ?bool $newFlatSale,
        public ?bool $primarySaleV2,
        public ?bool $yandexRent,
        public ?bool $yandexProdaja,
        public ?bool $cashbackYandexPlus,
        public ?bool $withExcerpt,
        public ?array $salesDepartments,
        public ?array $siteInfo,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            // Identity
            offerId: (string) ($data['offerId'] ?? ''),
            offerType: (string) ($data['offerType'] ?? ''),
            offerCategory: (string) ($data['offerCategory'] ?? ''),
            url: $data['url'] ?? $data['shareUrl'] ?? $data['shareURL'] ?? null,
            shareUrl: $data['shareUrl'] ?? $data['shareURL'] ?? null,
            description: $data['description'] ?? null,

            // Price
            price: $data['price'] ?? [],

            // Property details
            area: $data['area'] ?? null,
            roomsTotal: isset($data['roomsTotal']) ? (int) $data['roomsTotal'] : null,
            floorsOffered: $data['floorsOffered'] ?? [],
            floorsTotal: isset($data['floorsTotal']) ? (int) $data['floorsTotal'] : null,
            ceilingHeight: isset($data['ceilingHeight']) ? (float) $data['ceilingHeight'] : null,
            flatType: $data['flatType'] ?? null,
            openPlan: $data['openPlan'] ?? false,

            // Spaces
            kitchenSpace: $data['kitchenSpace'] ?? null,
            livingSpace: $data['livingSpace'] ?? null,
            roomSpace: $data['roomSpace'] ?? null,

            // Location
            location: $data['location'] ?? [],

            // Seller / author
            author: $data['author'] ?? null,
            phones: $data['phones'] ?? null,

            // Dates
            creationDate: $data['creationDate'] ?? null,
            updateDate: $data['updateDate'] ?? null,

            // Property structures
            apartment: $data['apartment'] ?? null,
            building: $data['building'] ?? null,
            house: $data['house'] ?? null,
            lot: $data['lot'] ?? null,

            // Media
            fullImages: $data['fullImages'] ?? [],
            totalImages: isset($data['totalImages']) ? (int) $data['totalImages'] : null,

            // Price history & predictions
            history: $data['history'] ?? null,
            predictions: $data['predictions'] ?? null,

            // Status & flags
            active: $data['active'] ?? true,
            dealStatus: $data['dealStatus'] ?? null,
            exclusive: $data['exclusive'] ?? false,
            premium: $data['premium'] ?? false,
            promoted: $data['promoted'] ?? false,
            raised: $data['raised'] ?? false,
            newBuilding: $data['newBuilding'] ?? false,
            notForAgents: $data['notForAgents'] ?? false,
            suspicious: $data['suspicious'] ?? null,
            trust: $data['trust'] ?? null,

            // Metadata
            tags: $data['tags'] ?? [],
            views: isset($data['views']) ? (int) $data['views'] : null,
            uid: isset($data['uid']) ? (string) $data['uid'] : null,
            platform: $data['platform'] ?? null,
            partnerId: isset($data['partnerId']) ? (string) $data['partnerId'] : null,
            partnerName: $data['partnerName'] ?? null,

            // Clustering
            clusterId: isset($data['clusterId']) ? (string) $data['clusterId'] : null,
            clusterHeader: $data['clusterHeader'] ?? null,
            clusterSize: isset($data['clusterSize']) ? (int) $data['clusterSize'] : null,

            // Conditions & supply
            supplyMap: $data['supplyMap'] ?? null,
            transactionConditionsMap: $data['transactionConditionsMap'] ?? null,
            agentFee: $data['agentFee'] ?? null,
            minRentPeriod: $data['minRentPeriod'] ?? null,
            rentConditionsMap: $data['rentConditionsMap'] ?? null,
            utilitiesIncluded: $data['utilitiesIncluded'] ?? null,

            // Links & channels
            offerLinks: $data['offerLinks'] ?? null,
            allowedCommunicationChannels: $data['allowedCommunicationChannels'] ?? null,

            // Promotions & trust
            tuzInfo: $data['tuzInfo'] ?? null,
            trustedOfferInfo: $data['trustedOfferInfo'] ?? null,
            remoteReview: $data['remoteReview'] ?? null,

            // Enriched fields
            enrichedFields: $data['enrichedFields'] ?? null,

            // Other
            hasPaidCalls: $data['hasPaidCalls'] ?? null,
            newFlatSale: $data['newFlatSale'] ?? null,
            primarySaleV2: $data['primarySaleV2'] ?? null,
            yandexRent: $data['yandexRent'] ?? null,
            yandexProdaja: $data['yandexProdaja'] ?? null,
            cashbackYandexPlus: $data['cashbackYandexPlus'] ?? null,
            withExcerpt: $data['withExcerpt'] ?? null,
            salesDepartments: $data['salesDepartments'] ?? null,
            siteInfo: $data['siteInfo'] ?? null,
        );
    }

    /**
     * Get the listing price value in rubles, or null if not available.
     */
    public function getPriceValue(): ?int
    {
        return isset($this->price['value']) ? (int) $this->price['value'] : null;
    }

    /**
     * Get the price currency (typically "RUR").
     */
    public function getPriceCurrency(): ?string
    {
        return $this->price['currency'] ?? null;
    }

    /**
     * Get the price trend (INCREASED, DECREASED, UNCHANGED), or null.
     */
    public function getPriceTrend(): ?string
    {
        return $this->price['trend'] ?? null;
    }

    /**
     * Get the previous price value, or null if no price change.
     */
    public function getPreviousPrice(): ?int
    {
        return isset($this->price['previous']) ? (int) $this->price['previous'] : null;
    }

    /**
     * Get the full address string.
     */
    public function getAddress(): ?string
    {
        return $this->location['address'] ?? null;
    }

    /**
     * Get coordinates as an associative array, or null if not available.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function getCoordinates(): ?array
    {
        $point = $this->location['point'] ?? null;
        if (! is_array($point) || ! isset($point['latitude'], $point['longitude'])) {
            return null;
        }

        return ['lat' => (float) $point['latitude'], 'lng' => (float) $point['longitude']];
    }

    /**
     * Get the city name from the location.
     */
    public function getCity(): ?string
    {
        return $this->location['localityName'] ?? null;
    }

    /**
     * Get the region/subject federation name.
     */
    public function getRegion(): ?string
    {
        return $this->location['subjectFederationName'] ?? null;
    }

    /**
     * Get the total area value in square meters, or null.
     */
    public function getAreaValue(): ?float
    {
        return isset($this->area['value']) ? (float) $this->area['value'] : null;
    }

    /**
     * Check if the listing has phone numbers available.
     */
    public function hasPhones(): bool
    {
        if ($this->phones === null) {
            return false;
        }

        return count($this->phones['phones'] ?? []) > 0;
    }

    /**
     * Get the first phone number, or null.
     */
    public function getFirstPhone(): ?string
    {
        $phoneList = $this->phones['phones'] ?? [];
        if (count($phoneList) === 0) {
            return null;
        }

        $first = $phoneList[0];

        return is_array($first) ? ($first['phoneNumber'] ?? null) : (string) $first;
    }

    /**
     * Get WhatsApp phone numbers from the author, or empty array.
     *
     * @return string[]
     */
    public function getWhatsAppPhones(): array
    {
        return $this->author['whatsappPhones'] ?? [];
    }

    /**
     * Check if the listing has images.
     */
    public function hasImages(): bool
    {
        return count($this->fullImages) > 0;
    }

    /**
     * Check if the listing has price history.
     */
    public function hasPriceHistory(): bool
    {
        return $this->history !== null && count($this->history) > 0;
    }

    /**
     * Get the seller/author organization name, or null.
     */
    public function getSellerName(): ?string
    {
        return $this->author['organization'] ?? $this->author['agentName'] ?? $this->author['name'] ?? null;
    }

    /**
     * Check if the listing is from an owner (not an agency).
     */
    public function isFromOwner(): bool
    {
        $category = $this->author['category'] ?? null;

        return $category === 'OWNER';
    }

    /**
     * Get the building year, or null.
     */
    public function getBuildingYear(): ?int
    {
        return isset($this->building['builtYear']) ? (int) $this->building['builtYear'] : null;
    }

    /**
     * Get the predicted price range, or null.
     *
     * @return array{min: int, max: int, value: int}|null
     */
    public function getPredictedPrice(): ?array
    {
        $predicted = $this->predictions['predictedPrice'] ?? null;
        if (! is_array($predicted) || ! isset($predicted['value'])) {
            return null;
        }

        return [
            'min' => (int) ($predicted['min'] ?? 0),
            'max' => (int) ($predicted['max'] ?? 0),
            'value' => (int) $predicted['value'],
        ];
    }
}
