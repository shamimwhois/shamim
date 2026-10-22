<?php

use App\Actions\Marketplace\ConfirmPayment;
use App\Actions\Marketplace\PlaceOrder;
use App\Actions\Marketplace\RefundOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\RefundStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Refunds
|--------------------------------------------------------------------------
|
| A refund is an amount, not a switch, so the tests are arithmetic over the
| ledger: what has already gone back is subtracted from what may go back, and
| the order's status is the consequence of that sum rather than a flag somebody
| sets. That is the whole difference between `PartiallyRefunded` and `Refunded`,
| and it is the difference the buyer experiences — one keeps the listing, the
| other does not.
|
| Every refusal is asserted as a refusal *and* as an unchanged ledger, because
| a guard that throws its exception after writing the row is the failure mode
| that would actually cost somebody money.
|
*/

/**
 * A paid order, with the money captured through the real actions.
 */
function capturedOrder(int $cents = 4900): MarketplaceOrder
{
    enablePaymentProvider(PaymentProvider::Card);

    $product = MarketplaceProduct::factory()->published()->priced($cents, 'USD')
        ->create(['user_id' => User::factory()->seller()->create()]);

    $order = app(PlaceOrder::class)->handle($product, User::factory()->create(), PaymentProvider::Card);

    return app(ConfirmPayment::class)->handle($order, true)->fresh();
}

test('returning the whole price settles the order as refunded', function () {
    $order = capturedOrder(cents: 4900);
    $actor = User::factory()->admin()->create();

    $refund = app(RefundOrder::class)->handle($order, 4900, $actor, 'Duplicate purchase.');

    $order->refresh();

    expect($refund->amount_cents)->toBe(4900)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reason)->toBe('Duplicate purchase.')
        ->and($refund->processed_by)->toBe($actor->id)
        ->and($refund->currency)->toBe('USD')
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refunded_at)->not->toBeNull()
        ->and($order->refundedCents())->toBe(4900)
        ->and($order->refundableCents())->toBe(0)
        ->and($order->isEntitling())->toBeFalse();
});

test('two refunds that together reach the price leave the order refunded', function () {
    $order = capturedOrder(cents: 4900);
    $actor = User::factory()->admin()->create();
    $refund = app(RefundOrder::class);

    $refund->handle($order, 2000, $actor);
    $refund->handle($order->fresh(), 2900, $actor);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refundedCents())->toBe(4900)
        ->and(MarketplaceRefund::query()->count())->toBe(2);
});

test('a partial refund returns money and keeps the buyer entitled', function () {
    $order = capturedOrder(cents: 4900);

    app(RefundOrder::class)->handle($order, 1000, User::factory()->admin()->create(), 'A goodwill gesture.');

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->refunded_at)->toBeNull()
        ->and($order->refundableCents())->toBe(3900)
        // The listing stays bought: entitlement covers a partial refund.
        ->and($order->isEntitling())->toBeTrue()
        ->and($order->formattedRefundedAmount())->not->toBe($order->formattedAmount());
});

test('an over-refund is refused and writes nothing', function () {
    $order = capturedOrder(cents: 4900);

    try {
        app(RefundOrder::class)->handle($order, 4901, User::factory()->admin()->create());
        $this->fail('An over-refund was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('amount');
    }

    expect(MarketplaceRefund::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('a second refund beyond what remains is refused', function () {
    $order = capturedOrder(cents: 4900);
    $actor = User::factory()->admin()->create();

    app(RefundOrder::class)->handle($order, 3000, $actor);

    try {
        app(RefundOrder::class)->handle($order->fresh(), 2000, $actor);
        $this->fail('A refund past the remaining balance was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['amount'][0])->not->toBe('');
    }

    expect(MarketplaceRefund::query()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded);
});

test('nothing may be refunded on an order that never captured money', function (OrderStatus $unsettled) {
    $order = capturedOrder();

    // Roll the order back to an unresolved state to prove the guard, rather
    // than trusting a factory row that skipped checkout entirely.
    $order->update(['status' => $unsettled, 'paid_at' => null, 'refunded_at' => null]);

    try {
        app(RefundOrder::class)->handle($order->fresh(), 100, User::factory()->admin()->create());
        $this->fail("[{$unsettled->value}] accepted a refund.");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('amount');
    }

    expect(MarketplaceRefund::query()->count())->toBe(0);
})->with([
    'pending' => [OrderStatus::Pending],
    'processing' => [OrderStatus::Processing],
    'failed' => [OrderStatus::Failed],
    'cancelled' => [OrderStatus::Cancelled],
    'refunded' => [OrderStatus::Refunded],
]);

test('a refund of nothing is refused', function () {
    $order = capturedOrder();

    foreach ([0, -100] as $cents) {
        try {
            app(RefundOrder::class)->handle($order, $cents, User::factory()->admin()->create());
            $this->fail("An amount of {$cents} was accepted.");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('amount');
        }
    }

    expect(MarketplaceRefund::query()->count())->toBe(0);
});

test('a fully refunded order cannot be refunded again', function () {
    $order = capturedOrder(cents: 4900);
    $actor = User::factory()->admin()->create();

    app(RefundOrder::class)->handle($order, 4900, $actor);

    expect($order->fresh()->status->isRefundable())->toBeFalse();
});

test('a refunded order may be bought again by the same account', function () {
    $order = capturedOrder(cents: 4900);
    $buyer = $order->buyer;
    $product = $order->product;

    app(RefundOrder::class)->handle($order, 4900, User::factory()->admin()->create());

    // The refund withdrew the entitlement, so checkout treats the listing as
    // unbought instead of handing back the order that no longer entitles.
    $replacement = app(PlaceOrder::class)->handle($product, $buyer, PaymentProvider::Card);

    expect($replacement->id)->not->toBe($order->id)
        ->and($replacement->status)->toBe(OrderStatus::Pending);
});
