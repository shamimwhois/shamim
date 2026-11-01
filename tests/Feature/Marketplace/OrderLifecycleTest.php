<?php

use App\Actions\Marketplace\CancelOrder;
use App\Actions\Marketplace\ConfirmPayment;
use App\Actions\Marketplace\PlaceOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The order lifecycle
|--------------------------------------------------------------------------
|
| Four numbers have to survive everything this file throws at them: the price
| the buyer agreed to, the platform's cut, the listing's download count, and
| the status itself. Each is written once, at the moment the money moves, and
| never re-derived afterwards — which is why the listing's price is changed
| mid-flight here rather than deleted, and why the counter is asserted as a
| number rather than "it went up".
|
| The other subject is idempotency. `ConfirmPayment` stands where a gateway
| callback will stand, and callbacks arrive twice. A second confirmation must
| not capture twice, must not sell the listing twice, and must not move an
| order that has already resolved.
|
*/

/**
 * A payable order placed through the real checkout path.
 *
 * Building the row with the factory would skip the payment attempt, and the
 * attempt is half of what these tests are about, so the fixture is an order
 * the application actually opened.
 */
function openOrder(int $cents = 4900, ?User $creator = null): MarketplaceOrder
{
    enablePaymentProvider(PaymentProvider::Card);

    $product = MarketplaceProduct::factory()->published()->priced($cents, 'USD')
        ->create(['user_id' => $creator ?? User::factory()->seller()->create()]);

    return app(PlaceOrder::class)->handle($product, User::factory()->create(), PaymentProvider::Card);
}

test('capturing a payment settles the order exactly once', function () {
    $order = openOrder();
    $product = $order->product;

    $paid = app(ConfirmPayment::class)->handle($order, true, 'SANDBOX-ABC123');
    $again = app(ConfirmPayment::class)->handle($paid->fresh(), true, 'SANDBOX-XYZ987');

    expect($again->status)->toBe(OrderStatus::Paid)
        ->and($again->paid_at)->not->toBeNull()
        ->and($again->currentPayment->status)->toBe(PaymentStatus::Succeeded)
        // The reference of the first confirmation is the one that stands.
        ->and($again->currentPayment->reference)->toBe('SANDBOX-ABC123')
        // One sale, one download, however many times the callback lands.
        ->and($product->fresh()->download_count)->toBe(1);
});

test('the order keeps the price it was bought at rather than the current one', function () {
    $order = openOrder(cents: 4900);
    $product = $order->product;

    $product->update(['price_cents' => 99900, 'currency' => 'EUR']);

    $order->refresh();

    expect($order->price_cents)->toBe(4900)
        ->and($order->currency)->toBe('USD')
        ->and($order->product_name)->toBe($product->name)
        ->and($order->product_slug)->toBe($product->slug);
});

test('the platform take is snapshotted when the payment is captured', function () {
    $order = openOrder(cents: 4900, creator: User::factory()->seller()->create());

    $paid = app(ConfirmPayment::class)->handle($order, true);

    // A seller account is on the 15% plan: 4900 minor units → 735 kept.
    expect($paid->commission_cents)->toBe(735);
});

test('an account outside the seller programme pays no cut', function () {
    // The Free plan has no commission, so a listing sold by an account outside
    // the seller programme costs the buyer the same but pays the platform 0.
    $order = openOrder(cents: 4900, creator: User::factory()->create());

    $paid = app(ConfirmPayment::class)->handle($order, true);

    expect($paid->commission_cents)->toBe(0);
});

test('a declined attempt fails the order and closes it out', function () {
    $order = openOrder();

    $failed = app(ConfirmPayment::class)->handle($order, false);
    $product = $failed->product;

    expect($failed->status)->toBe(OrderStatus::Failed)
        ->and($failed->failure_reason)->not->toBeNull()
        ->and($failed->paid_at)->toBeNull()
        ->and($failed->currentPayment->status)->toBe(PaymentStatus::Failed)
        ->and($failed->currentPayment->failure_reason)->not->toBeNull()
        ->and($product->fresh()->download_count)->toBe(0);
});

test('a declined order may be bought again as a fresh attempt', function () {
    $order = openOrder();
    $buyer = $order->buyer;
    $product = $order->product;

    app(ConfirmPayment::class)->handle($order, false);

    // A Failed order does not entitle anyone, so the same buyer placing the
    // same listing again is a new order rather than a returned old one.
    $retry = app(PlaceOrder::class)->handle($product, $buyer, PaymentProvider::Card);

    expect($retry->id)->not->toBe($order->id)
        ->and($retry->status)->toBe(OrderStatus::Pending)
        ->and(MarketplaceOrder::query()->count())->toBe(2);
});

test('a resolved order refuses every later outcome', function (OrderStatus $settled) {
    $order = openOrder();

    match ($settled) {
        OrderStatus::Paid => app(ConfirmPayment::class)->handle($order, true),
        OrderStatus::Failed => app(ConfirmPayment::class)->handle($order, false),
        default => app(CancelOrder::class)->handle($order),
    };

    $before = $order->fresh();

    // Whichever way it resolved, a later click on the sandbox buttons is inert.
    app(ConfirmPayment::class)->handle($before->fresh(), true);
    app(CancelOrder::class)->handle($before->fresh());

    expect($before->fresh()->status)->toBe($settled)
        ->and($before->fresh()->currentPayment->status)->toBe(
            $settled === OrderStatus::Paid ? PaymentStatus::Succeeded : PaymentStatus::Failed,
        );
})->with([
    'paid' => [OrderStatus::Paid],
    'failed' => [OrderStatus::Failed],
    'cancelled' => [OrderStatus::Cancelled],
]);

test('cancelling withdraws the order and settles its open attempt', function () {
    $order = openOrder();

    $cancelled = app(CancelOrder::class)->handle($order);

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->currentPayment->status)->toBe(PaymentStatus::Failed)
        ->and($cancelled->currentPayment->failure_reason)->toBe('The order was cancelled before the payment completed.')
        ->and($cancelled->product->fresh()->download_count)->toBe(0);
});

test('a paid order cannot be cancelled, only refunded', function () {
    $order = app(ConfirmPayment::class)->handle(openOrder(), true);

    $after = app(CancelOrder::class)->handle($order->fresh());

    expect($after->status)->toBe(OrderStatus::Paid)
        ->and($after->cancelled_at)->toBeNull();
});

test('an order may still be paid while it is being processed', function () {
    $order = openOrder();
    $order->update(['status' => OrderStatus::Processing]);

    $paid = app(ConfirmPayment::class)->handle($order->fresh(), true);

    expect($paid->status)->toBe(OrderStatus::Paid);
});
