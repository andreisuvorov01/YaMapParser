<?php

declare(strict_types=1);

namespace YandexParser\DTO;

final readonly class Review
{
    /**
     * @param  string[]  $businessCategories
     * @param  array<int, array{name: string, count: int, positive: int, neutral: int, negative: int}>|null  $reviewAspects
     * @param  string[]  $photos
     * @param  string[]  $videos
     * @param  string[]  $textTranslations
     * @param  array<int, mixed>|null  $keyPhrases
     * @param  string[]  $authorAchievements
     * @param  string[]  $authorProfessions
     */
    public function __construct(
        // Review identification
        public string $reviewId,
        public int $rating,
        public ?string $text,
        public ?string $date,

        // Business context
        public string $businessId,
        public ?string $businessTitle,
        public ?string $businessUrl,
        public ?string $businessAddress,
        public ?string $businessCity,
        public ?float $businessRating,
        public ?int $businessRatingsCount,
        public array $businessCategories,
        public ?string $neurosummary,
        public ?array $reviewAspects,

        // Author info
        public ?string $authorName,
        public ?string $authorId,
        public ?string $authorAvatarUrl,
        public ?string $authorProfileUrl,
        public ?string $authorLevel,
        public bool $isAnonymous,
        public array $authorAchievements,
        public array $authorProfessions,

        // Engagement
        public ?int $likeCount,
        public ?int $dislikeCount,
        public ?int $commentCount,

        // Business reply
        public ?string $businessComment,
        public ?string $businessCommentDate,

        // Media
        public array $photos,
        public array $videos,

        // Language & translations
        public ?string $textLanguage,
        public array $textTranslations,

        // Metadata
        public ?bool $isPublicRating,
        public ?bool $bold,
        public ?array $keyPhrases,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            // Review identification
            reviewId: (string) ($data['reviewId'] ?? ''),
            rating: (int) ($data['rating'] ?? 0),
            text: $data['text'] ?? null,
            date: $data['date'] ?? null,

            // Business context
            businessId: (string) ($data['businessId'] ?? ''),
            businessTitle: $data['businessTitle'] ?? null,
            businessUrl: $data['businessUrl'] ?? null,
            businessAddress: $data['businessAddress'] ?? null,
            businessCity: $data['businessCity'] ?? null,
            businessRating: isset($data['businessRating']) ? (float) $data['businessRating'] : null,
            businessRatingsCount: isset($data['businessRatingsCount']) ? (int) $data['businessRatingsCount'] : null,
            businessCategories: $data['businessCategories'] ?? [],
            neurosummary: $data['neurosummary'] ?? null,
            reviewAspects: $data['reviewAspects'] ?? null,

            // Author info
            authorName: $data['authorName'] ?? null,
            authorId: $data['authorId'] ?? null,
            authorAvatarUrl: $data['authorAvatarUrl'] ?? null,
            authorProfileUrl: $data['authorProfileUrl'] ?? null,
            authorLevel: $data['authorLevel'] ?? null,
            isAnonymous: $data['isAnonymous'] ?? false,
            authorAchievements: $data['authorAchievements'] ?? [],
            authorProfessions: $data['authorProfessions'] ?? [],

            // Engagement
            likeCount: isset($data['likeCount']) ? (int) $data['likeCount'] : null,
            dislikeCount: isset($data['dislikeCount']) ? (int) $data['dislikeCount'] : null,
            commentCount: isset($data['commentCount']) ? (int) $data['commentCount'] : null,

            // Business reply
            businessComment: $data['businessComment'] ?? null,
            businessCommentDate: $data['businessCommentDate'] ?? null,

            // Media
            photos: $data['photos'] ?? [],
            videos: $data['videos'] ?? [],

            // Language & translations
            textLanguage: $data['textLanguage'] ?? null,
            textTranslations: $data['textTranslations'] ?? [],

            // Metadata
            isPublicRating: $data['isPublicRating'] ?? null,
            bold: $data['bold'] ?? null,
            keyPhrases: $data['keyPhrases'] ?? null,
        );
    }

    /**
     * Check if the business has replied to this review.
     */
    public function hasBusinessReply(): bool
    {
        return $this->businessComment !== null && $this->businessComment !== '';
    }

    /**
     * Get the business reply text, or null if none.
     */
    public function getBusinessReplyText(): ?string
    {
        if (! $this->hasBusinessReply()) {
            return null;
        }

        return $this->businessComment;
    }

    /**
     * Check if the review has attached photos.
     */
    public function hasPhotos(): bool
    {
        return count($this->photos) > 0;
    }

    /**
     * Check if the review has attached videos.
     */
    public function hasVideos(): bool
    {
        return count($this->videos) > 0;
    }

    /**
     * Check if the review is positive (4-5 stars).
     */
    public function isPositive(): bool
    {
        return $this->rating >= 4;
    }

    /**
     * Check if the review is negative (1-2 stars).
     */
    public function isNegative(): bool
    {
        return $this->rating <= 2;
    }

    /**
     * Check if a translation is available.
     */
    public function hasTranslation(): bool
    {
        return count($this->textTranslations) > 0;
    }
}
