<?php

declare(strict_types=1);

use YandexParser\DTO\Review;

it('creates review from array', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->reviewId)->toBe('rev_abc123def456')
        ->and($review->businessId)->toBe('1124715036')
        ->and($review->businessTitle)->toBe('Pushkin')
        ->and($review->businessUrl)->toBe('https://yandex.ru/maps/org/pushkin/1124715036/')
        ->and($review->businessAddress)->toBe('Tverskoy Boulevard, 26A')
        ->and($review->businessCity)->toBe('Moscow')
        ->and($review->businessRating)->toBe(4.6)
        ->and($review->businessRatingsCount)->toBe(3150)
        ->and($review->businessCategories)->toBe(['Restaurant', 'Banquet hall'])
        ->and($review->rating)->toBe(5)
        ->and($review->text)->toContain('Excellent restaurant')
        ->and($review->date)->toBe('2026-01-20T18:30:00Z')
        ->and($review->authorName)->toBe('Alexei Petrov')
        ->and($review->authorId)->toBe('user_789012')
        ->and($review->authorLevel)->toBe('Expert')
        ->and($review->likeCount)->toBe(12)
        ->and($review->dislikeCount)->toBe(1)
        ->and($review->neurosummary)->toContain('food quality')
        ->and($review->isAnonymous)->toBeFalse();
});

it('populates new review fields', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->reviewAspects)->toHaveCount(1)
        ->and($review->textLanguage)->toBe('en')
        ->and($review->textTranslations)->toHaveCount(1)
        ->and($review->hasTranslation())->toBeTrue()
        ->and($review->isPublicRating)->toBeTrue()
        ->and($review->bold)->toBeFalse()
        ->and($review->commentCount)->toBe(3)
        ->and($review->authorAchievements)->toBe(['Top Reviewer', '100+ Reviews'])
        ->and($review->authorProfessions)->toBe(['Food Critic']);
});

it('checks business reply presence', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->hasBusinessReply())->toBeTrue();
});

it('gets business reply text', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->getBusinessReplyText())->toBe('Thank you for your kind words, Alexei! We are glad you enjoyed your visit.');
});

it('checks photos presence', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->hasPhotos())->toBeTrue()
        ->and($review->photos)->toHaveCount(2);
});

it('checks videos presence', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->hasVideos())->toBeTrue()
        ->and($review->videos)->toHaveCount(1);
});

it('identifies positive review', function () {
    $review = Review::fromArray(getSampleReviewData());

    expect($review->isPositive())->toBeTrue()
        ->and($review->isNegative())->toBeFalse();
});

it('identifies negative review', function () {
    $data = getSampleReviewData();
    $data['rating'] = 1;
    $review = Review::fromArray($data);

    expect($review->isNegative())->toBeTrue()
        ->and($review->isPositive())->toBeFalse();
});

it('identifies neutral review', function () {
    $data = getSampleReviewData();
    $data['rating'] = 3;
    $review = Review::fromArray($data);

    expect($review->isPositive())->toBeFalse()
        ->and($review->isNegative())->toBeFalse();
});

it('handles missing optional fields', function () {
    $minimal = [
        'reviewId' => 'rev_001',
        'businessId' => '123',
        'rating' => 4,
    ];

    $review = Review::fromArray($minimal);

    expect($review->reviewId)->toBe('rev_001')
        ->and($review->businessId)->toBe('123')
        ->and($review->rating)->toBe(4)
        ->and($review->businessTitle)->toBeNull()
        ->and($review->businessUrl)->toBeNull()
        ->and($review->businessAddress)->toBeNull()
        ->and($review->businessCity)->toBeNull()
        ->and($review->businessRating)->toBeNull()
        ->and($review->text)->toBeNull()
        ->and($review->date)->toBeNull()
        ->and($review->authorName)->toBeNull()
        ->and($review->authorId)->toBeNull()
        ->and($review->businessComment)->toBeNull()
        ->and($review->businessCommentDate)->toBeNull()
        ->and($review->neurosummary)->toBeNull()
        ->and($review->reviewAspects)->toBeNull()
        ->and($review->textLanguage)->toBeNull()
        ->and($review->isPublicRating)->toBeNull()
        ->and($review->bold)->toBeNull()
        ->and($review->commentCount)->toBeNull()
        ->and($review->businessCategories)->toBeEmpty()
        ->and($review->photos)->toBeEmpty()
        ->and($review->videos)->toBeEmpty()
        ->and($review->textTranslations)->toBeEmpty()
        ->and($review->authorAchievements)->toBeEmpty()
        ->and($review->authorProfessions)->toBeEmpty()
        ->and($review->isAnonymous)->toBeFalse();
});

it('returns false for hasTranslation when no translations', function () {
    $minimal = ['reviewId' => 'rev_001', 'businessId' => '123', 'rating' => 4];
    $review = Review::fromArray($minimal);

    expect($review->hasTranslation())->toBeFalse();
});

it('returns false for business reply when not present', function () {
    $data = getSampleReviewData();
    $data['businessComment'] = null;
    $review = Review::fromArray($data);

    expect($review->hasBusinessReply())->toBeFalse()
        ->and($review->getBusinessReplyText())->toBeNull();
});

it('returns false for business reply when empty string', function () {
    $data = getSampleReviewData();
    $data['businessComment'] = '';
    $review = Review::fromArray($data);

    expect($review->hasBusinessReply())->toBeFalse()
        ->and($review->getBusinessReplyText())->toBeNull();
});

it('returns false for hasPhotos when no photos', function () {
    $data = getSampleReviewData();
    $data['photos'] = [];
    $review = Review::fromArray($data);

    expect($review->hasPhotos())->toBeFalse();
});

it('returns false for hasVideos when no videos', function () {
    $data = getSampleReviewData();
    $data['videos'] = [];
    $review = Review::fromArray($data);

    expect($review->hasVideos())->toBeFalse();
});
