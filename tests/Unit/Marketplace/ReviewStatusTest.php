<?php

use App\Enums\ReviewStatus;

/*
|--------------------------------------------------------------------------
| ReviewStatus
|--------------------------------------------------------------------------
|
| A review's status is the only thing standing between user-generated text and
| the public product page. The single rule that matters is encoded here:
| `isPublic()` is true for exactly one case.
|
*/

test('only an approved review is public', function () {
    expect(ReviewStatus::Approved->isPublic())->toBeTrue()
        ->and(ReviewStatus::Pending->isPublic())->toBeFalse()
        ->and(ReviewStatus::Rejected->isPublic())->toBeFalse();
});

test('every review status has a label and a badge colour', function () {
    foreach (ReviewStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            ->and($status->badgeColor())->not->toBe('');
    }
});

test('the stored values are the ones the migration defaults to', function () {
    expect(ReviewStatus::Pending->value)->toBe('pending')
        ->and(ReviewStatus::Approved->value)->toBe('approved')
        ->and(ReviewStatus::Rejected->value)->toBe('rejected');
});

test('the options list covers every case', function () {
    expect(array_column(ReviewStatus::options(), 'value'))
        ->toBe(['pending', 'approved', 'rejected']);
});
