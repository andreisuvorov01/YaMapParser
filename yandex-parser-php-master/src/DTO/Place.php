<?php

declare(strict_types=1);

namespace YandexParser\DTO;

final readonly class Place
{
    /**
     * @param  string[]  $phones
     * @param  array<int, array{type: string, url: string}>  $socialLinks
     * @param  string[]  $categories
     * @param  array<string, mixed>  $features
     * @param  array<int, array{day: string, hours: string}>  $schedule
     * @param  array<int, array{name: string, count: int, positive: int, neutral: int, negative: int}>|null  $reviewAspects
     * @param  array<int, mixed>  $reviews
     * @param  array<int, mixed>  $photos
     * @param  array<int, mixed>  $posts
     * @param  array<int, array{name: string, distance: string}>  $nearbyMetro
     * @param  array<int, array{name: string, distance: string}>  $nearbyStops
     * @param  array<int, array{url: string, thumbnail: string}>  $videos
     * @param  array<int, array{type: string, url: string}>  $bookingLinks
     * @param  string[]  $badges
     * @param  array<string, mixed>|null  $awards
     * @param  array<string, mixed>|null  $panorama
     * @param  array{sw: list<float>, ne: list<float>}|null  $bounds
     * @param  array<int, array{longitude: float, latitude: float, azimuth?: float, tilt?: float}>  $entrances
     * @param  array<string, mixed>|null  $popularityHistogram
     * @param  array<int, array{name: string, url: string}>  $sources
     * @param  array<int, array{name: string, features: array<string, mixed>}>  $featureGroups
     * @param  array<string, mixed>|null  $menu
     * @param  array<string, mixed>|null  $bookingPartner
     * @param  array{name: string, taxId: string}|null  $legalInfo
     * @param  array<int, array{type: string, title: string, url: string}>|null  $actionButtons
     * @param  array{name: string, description: string, url: string, photoUrl: string}|null  $promo
     * @param  array<int, mixed>|null  $relatedPlaces
     * @param  array<string, mixed>|null  $visitsHistogram
     * @param  array<string, mixed>|null  $trustFeatures
     * @param  array<int, mixed>|null  $discoveryCollections
     * @param  array<string, mixed>|null  $bookingAvailability
     * @param  array<int, mixed>|null  $mobileVideos
     * @param  array<int, mixed>|null  $mobilePosts
     * @param  array<string, mixed>|null  $snippet
     */
    public function __construct(
        // Identity
        public string $businessId,
        public string $title,
        public ?string $description,
        public ?string $type,
        public ?string $url,
        public ?string $searchQuery,

        // Location
        public ?float $longitude,
        public ?float $latitude,
        public ?string $address,
        public ?string $country,
        public ?string $region,
        public ?string $city,
        public ?string $street,
        public ?string $house,
        public ?string $postalCode,

        // Status
        public ?string $status,
        public bool $isOpenNow,
        public bool $isVerifiedOwner,

        // Rating & reviews
        public ?float $rating,
        public ?int $ratingsCount,
        public ?int $reviewCount,
        public ?array $reviewAspects,

        // Categories
        public array $categories,

        // Contact
        public array $phones,
        public ?string $website,
        public ?string $email,
        public array $socialLinks,

        // Working hours
        public ?string $workingHoursText,
        public array $schedule,

        // Features
        public array $features,

        // Media
        public ?int $photoCount,
        public ?string $photoUrlTemplate,
        public int $videoCount,
        public array $videos,
        public ?string $logoUrl,

        // Chain
        public ?string $chainName,
        public ?string $chainId,

        // Booking
        public array $bookingLinks,
        public ?array $bookingPartner,
        public ?array $bookingAvailability,

        // Nearby transit
        public array $nearbyMetro,
        public array $nearbyStops,

        // Badges & awards
        public array $badges,
        public ?array $awards,

        // Snippet
        public ?array $snippet,

        // Geo & region
        public ?int $regionId,
        public ?int $geoId,
        public ?string $shortTitle,
        public ?int $timezoneOffset,
        public ?array $panorama,
        public ?array $bounds,
        public array $entrances,
        public ?array $popularityHistogram,

        // External data
        public array $sources,
        public array $featureGroups,

        // Menu, legal, promo
        public ?array $menu,
        public ?string $additionalAddress,
        public ?array $legalInfo,
        public ?array $actionButtons,
        public ?array $promo,

        // Enrichment
        public ?string $neurosummary,
        public ?array $relatedPlaces,
        public ?array $visitsHistogram,
        public ?array $trustFeatures,
        public ?array $discoveryCollections,
        public ?array $mobileVideos,
        public ?array $mobilePosts,

        // User-generated content
        public array $reviews,
        public array $photos,
        public array $posts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            // Identity
            businessId: (string) ($data['businessId'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            description: $data['description'] ?? null,
            type: $data['type'] ?? null,
            url: $data['url'] ?? null,
            searchQuery: $data['searchQuery'] ?? null,

            // Location
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            address: $data['address'] ?? null,
            country: $data['country'] ?? null,
            region: $data['region'] ?? null,
            city: $data['city'] ?? null,
            street: $data['street'] ?? null,
            house: $data['house'] ?? null,
            postalCode: $data['postalCode'] ?? null,

            // Status
            status: $data['status'] ?? null,
            isOpenNow: $data['isOpenNow'] ?? false,
            isVerifiedOwner: $data['isVerifiedOwner'] ?? false,

            // Rating & reviews
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            ratingsCount: isset($data['ratingsCount']) ? (int) $data['ratingsCount'] : null,
            reviewCount: isset($data['reviewCount']) ? (int) $data['reviewCount'] : null,
            reviewAspects: $data['reviewAspects'] ?? null,

            // Categories
            categories: $data['categories'] ?? [],

            // Contact
            phones: $data['phones'] ?? [],
            website: $data['website'] ?? null,
            email: $data['email'] ?? null,
            socialLinks: $data['socialLinks'] ?? [],

            // Working hours
            workingHoursText: $data['workingHoursText'] ?? null,
            schedule: $data['schedule'] ?? [],

            // Features
            features: $data['features'] ?? [],

            // Media
            photoCount: isset($data['photoCount']) ? (int) $data['photoCount'] : null,
            photoUrlTemplate: $data['photoUrlTemplate'] ?? null,
            videoCount: (int) ($data['videoCount'] ?? 0),
            videos: $data['videos'] ?? [],
            logoUrl: $data['logoUrl'] ?? null,

            // Chain
            chainName: $data['chainName'] ?? null,
            chainId: $data['chainId'] ?? null,

            // Booking
            bookingLinks: $data['bookingLinks'] ?? [],
            bookingPartner: $data['bookingPartner'] ?? null,
            bookingAvailability: $data['bookingAvailability'] ?? null,

            // Nearby transit
            nearbyMetro: $data['nearbyMetro'] ?? [],
            nearbyStops: $data['nearbyStops'] ?? [],

            // Badges & awards
            badges: $data['badges'] ?? [],
            awards: $data['awards'] ?? null,

            // Snippet
            snippet: $data['snippet'] ?? null,

            // Geo & region
            regionId: isset($data['regionId']) ? (int) $data['regionId'] : null,
            geoId: isset($data['geoId']) ? (int) $data['geoId'] : null,
            shortTitle: $data['shortTitle'] ?? null,
            timezoneOffset: isset($data['timezoneOffset']) ? (int) $data['timezoneOffset'] : null,
            panorama: $data['panorama'] ?? null,
            bounds: $data['bounds'] ?? null,
            entrances: $data['entrances'] ?? [],
            popularityHistogram: $data['popularityHistogram'] ?? null,

            // External data
            sources: $data['sources'] ?? [],
            featureGroups: $data['featureGroups'] ?? [],

            // Menu, legal, promo
            menu: $data['menu'] ?? null,
            additionalAddress: $data['additionalAddress'] ?? null,
            legalInfo: $data['legalInfo'] ?? null,
            actionButtons: $data['actionButtons'] ?? null,
            promo: $data['promo'] ?? null,

            // Enrichment
            neurosummary: $data['neurosummary'] ?? null,
            relatedPlaces: $data['relatedPlaces'] ?? null,
            visitsHistogram: $data['visitsHistogram'] ?? null,
            trustFeatures: $data['trustFeatures'] ?? null,
            discoveryCollections: $data['discoveryCollections'] ?? null,
            mobileVideos: $data['mobileVideos'] ?? null,
            mobilePosts: $data['mobilePosts'] ?? null,

            // User-generated content
            reviews: $data['reviews'] ?? [],
            photos: $data['photos'] ?? [],
            posts: $data['posts'] ?? [],
        );
    }

    /**
     * Check if the place has any contact information (phones or email).
     */
    public function hasContactInfo(): bool
    {
        return count($this->phones) > 0 || ($this->email !== null && $this->email !== '');
    }

    /**
     * Get the first phone number, or null if none available.
     */
    public function getFirstPhone(): ?string
    {
        return $this->phones[0] ?? null;
    }

    /**
     * Check if the place has a website.
     */
    public function hasWebsite(): bool
    {
        return $this->website !== null && $this->website !== '';
    }

    /**
     * Get coordinates as an associative array, or null if not available.
     *
     * @return array{lng: float, lat: float}|null
     */
    public function getCoordinates(): ?array
    {
        if ($this->longitude === null || $this->latitude === null) {
            return null;
        }

        return ['lng' => $this->longitude, 'lat' => $this->latitude];
    }

    /**
     * Check if the place owner is verified.
     */
    public function isVerified(): bool
    {
        return $this->isVerifiedOwner;
    }

    /**
     * Check if the place has videos.
     */
    public function hasVideos(): bool
    {
        return $this->videoCount > 0;
    }

    /**
     * Check if the place has a delivery/service menu.
     */
    public function hasMenu(): bool
    {
        return $this->menu !== null;
    }
}
