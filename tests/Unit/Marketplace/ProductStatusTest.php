<?php

use App\Enums\ProductStatus;

/*
|--------------------------------------------------------------------------
| ProductStatus
|--------------------------------------------------------------------------
|
| The enum is the contract between the database column, the admin filter, the
| badge colour and the public catalogue. These tests pin the parts a view
| would otherwise have to guess: which states are public, which one means
| "somebody should look at this", and the stored values themselves.
|
*/

test('every status has a label, a description and a badge colour', function () {
    foreach (ProductStatus::cases() as $status) {
        expect($status->label())->not->toBe('', "[{$status->value}] has no label.")
            ->and($status->description())->not->toBe('', "[{$status->value}] has no description.")
            ->and($status->badgeColor())->not->toBe('', "[{$status->value}] has no badge colour.");
    }
});

test('only the published status is visible to the public', function () {
    expect(ProductStatus::Published->isPubliclyVisible())->toBeTrue();

    foreach ([ProductStatus::Draft, ProductStatus::PendingReview, ProductStatus::Archived] as $status) {
        expect($status->isPubliclyVisible())->toBeFalse("[{$status->value}] must not be public.");
    }
});

test('only the pending status awaits review', function () {
    expect(ProductStatus::PendingReview->awaitsReview())->toBeTrue();

    foreach ([ProductStatus::Draft, ProductStatus::Published, ProductStatus::Archived] as $status) {
        expect($status->awaitsReview())->toBeFalse();
    }
});

test('the stored values are the ones the migrations default to', function () {
    expect(ProductStatus::Draft->value)->toBe('draft')
        ->and(ProductStatus::PendingReview->value)->toBe('pending')
        ->and(ProductStatus::Published->value)->toBe('published')
        ->and(ProductStatus::Archived->value)->toBe('archived');
});

test('the options list preserves case order and stored values', function () {
    $options = ProductStatus::options();

    expect(array_column($options, 'value'))->toBe(['draft', 'pending', 'published', 'archived'])
        ->and(array_column($options, 'label'))->toBe([
            __('Draft'),
            __('In review'),
            __('Published'),
            __('Archived'),
        ]);
});
