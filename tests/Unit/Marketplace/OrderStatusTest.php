<?php

use App\Enums\OrderStatus;

/*
|--------------------------------------------------------------------------
| OrderStatus
|--------------------------------------------------------------------------
|
| The status is the order's whole story: whether the buyer holds the listing,
| whether a payment outcome may still land on the row, and whether money can
| still be sent back. Every surface that moves an order asks these questions
| of this enum, so a wrong answer here is wrong in checkout, on the buyer's
| screen and in the admin console at once.
|
| The transitions are asserted as a matrix rather than as a list of happy
| paths, because the refusals are the part that protects the ledger: an order
| that could still be "paid" after being refunded would let a double-click
| hand the buyer the listing again for free.
|
*/

test('every status has a label, a description and a badge colour', function () {
    foreach (OrderStatus::cases() as $status) {
        expect($status->label())->not->toBe('', "[{$status->value}] has no label.")
            ->and($status->description())->not->toBe('', "[{$status->value}] has no description.")
            ->and($status->badgeColor())->not->toBe('', "[{$status->value}] has no badge colour.");
    }
});

test('the stored values match the ones the migration and the filters use', function () {
    expect(array_column(OrderStatus::cases(), 'value'))
        ->toBe(['pending', 'processing', 'paid', 'failed', 'cancelled', 'partially_refunded', 'refunded']);
});

test('the paid states are the ones that grant the buyer access', function () {
    foreach ([OrderStatus::Paid, OrderStatus::PartiallyRefunded] as $status) {
        expect($status->isEntitling())->toBeTrue("[{$status->value}] must entitle the buyer.");
    }

    foreach ([OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Refunded] as $status) {
        expect($status->isEntitling())->toBeFalse("[{$status->value}] must not entitle the buyer.");
    }
});

test('only an unresolved order may still receive a payment outcome', function () {
    foreach ([OrderStatus::Pending, OrderStatus::Processing] as $status) {
        expect($status->isPayable())->toBeTrue("[{$status->value}] is still awaiting its payment.");
    }

    foreach ([OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::PartiallyRefunded, OrderStatus::Refunded] as $status) {
        expect($status->isPayable())->toBeFalse("[{$status->value}] has already resolved its payment.");
    }
});

test('money may flow back only from an order that captured it', function () {
    foreach ([OrderStatus::Paid, OrderStatus::PartiallyRefunded] as $status) {
        expect($status->isRefundable())->toBeTrue("[{$status->value}] holds money that can be returned.");
    }

    foreach ([OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Refunded] as $status) {
        expect($status->isRefundable())->toBeFalse("[{$status->value}] has nothing to refund.");
    }
});

test('the payable states resolve to exactly one of paid, failed or cancelled', function () {
    expect(OrderStatus::Pending->allowedTransitions())->toBe([
        OrderStatus::Processing,
        OrderStatus::Paid,
        OrderStatus::Failed,
        OrderStatus::Cancelled,
    ])->and(OrderStatus::Processing->allowedTransitions())->toBe([
        OrderStatus::Paid,
        OrderStatus::Failed,
        OrderStatus::Cancelled,
    ]);
});

test('a paid order can only move into the refund states', function () {
    expect(OrderStatus::Paid->allowedTransitions())->toBe([
        OrderStatus::PartiallyRefunded,
        OrderStatus::Refunded,
    ])->and(OrderStatus::PartiallyRefunded->allowedTransitions())->toBe([
        OrderStatus::PartiallyRefunded,
        OrderStatus::Refunded,
    ]);

    // A partial refund repeated is still a partial refund; anything else, no.
    expect(OrderStatus::PartiallyRefunded->canTransitionTo(OrderStatus::Paid))->toBeFalse()
        ->and(OrderStatus::PartiallyRefunded->canTransitionTo(OrderStatus::PartiallyRefunded))->toBeTrue();
});

test('a resolved order is closed, and never reopens', function () {
    foreach ([OrderStatus::Failed, OrderStatus::Cancelled, OrderStatus::Refunded] as $status) {
        expect($status->isTerminal())->toBeTrue("[{$status->value}] should be terminal.")
            ->and($status->allowedTransitions())->toBe([]);
    }

    // The refund states are the reverse of the payment, not a new attempt:
    // nothing may step back from them into a payable state.
    foreach ([OrderStatus::PartiallyRefunded, OrderStatus::Refunded] as $status) {
        expect($status->canTransitionTo(OrderStatus::Pending))->toBeFalse()
            ->and($status->canTransitionTo(OrderStatus::Processing))->toBeFalse();
    }

    foreach ([OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Paid, OrderStatus::PartiallyRefunded] as $status) {
        expect($status->isTerminal())->toBeFalse();
    }
});

test('the options list preserves case order and stored values', function () {
    $options = OrderStatus::options();

    expect(array_column($options, 'value'))
        ->toBe(['pending', 'processing', 'paid', 'failed', 'cancelled', 'partially_refunded', 'refunded'])
        ->and(array_column($options, 'label'))->toBe([
            __('Pending'),
            __('Processing'),
            __('Paid'),
            __('Failed'),
            __('Cancelled'),
            __('Partially refunded'),
            __('Refunded'),
        ]);
});
