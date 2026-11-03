<?php

use App\Actions\Marketplace\ConfirmPayment;
use App\Actions\Marketplace\PlaceOrder;
use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The orders console
|--------------------------------------------------------------------------
|
| Two abilities govern this screen and they are deliberately different, so the
| tests split along that seam. `sale:view-any` opens it; `refund:process` arms
| its mutations. An account that may read the ledger must not be able to settle
| an order by posting to the component, which is exactly what a Livewire update
| request looks like to the server: no route middleware, no rendered button,
| just an action name and an order id.
|
| The fixtures are placed through {@see PlaceOrder} and captured through
| {@see ConfirmPayment} rather than written by the factory, because the console
| reads the payment and refund ledger — a row with no attempt behind it is not
| the thing this screen is for.
|
*/

/**
 * One priced listing, sold once, in the given order state.
 */
function consoleOrder(bool $captured): MarketplaceOrder
{
    enablePaymentProvider(PaymentProvider::Card);

    $product = MarketplaceProduct::factory()->published()->priced(2500, 'USD')
        ->create(['name' => $captured ? 'Console Paid Kit' : 'Console Pending Kit']);

    $order = app(PlaceOrder::class)->handle($product, User::factory()->create(['name' => 'Ines Broker']), PaymentProvider::Card);

    return $captured
        ? app(ConfirmPayment::class)->handle($order, true, 'SANDBOX-CONSOLE')
        : $order;
}

function consolePendingOrder(): MarketplaceOrder
{
    return consoleOrder(captured: false);
}

function consolePaidOrder(): MarketplaceOrder
{
    return consoleOrder(captured: true);
}

test('a guest is bounced to the sign-in dialog', function () {
    $this->get('/admin/orders')->assertRedirect(route('home', ['signin' => 'required']));
});

test('an account without sale:view-any cannot open or mount the console', function () {
    $plain = User::factory()->create();
    $seller = User::factory()->seller()->create();

    expect($plain->hasPermission(AccountPermission::ViewAnySales))->toBeFalse()
        ->and($seller->hasPermission(AccountPermission::ViewAnySales))->toBeFalse();

    $this->actingAs($plain)->get('/admin/orders')->assertForbidden();
    $this->actingAs($seller)->get('/admin/orders')->assertForbidden();

    // The component guard has to hold on its own: a POST to the Livewire
    // endpoint never passes through the route's permission middleware.
    Livewire::actingAs($plain)->test('pages::admin.orders')->assertForbidden();
    Livewire::actingAs($seller)->test('pages::admin.orders')->assertForbidden();
})->group('security');

test('the console lists orders across every account', function () {
    consolePendingOrder();
    consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->assertOk()
        ->assertSee('Console Pending Kit')
        ->assertSee('Console Paid Kit')
        ->assertSee('Ines Broker');
});

test('a payable order offers the reconcile controls to an operator who may move money', function () {
    $order = consolePendingOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->call('toggle', $order->id)
        ->assertSeeHtml("data-test=\"order-mark-paid-{$order->id}\"")
        ->assertSeeHtml("data-test=\"order-mark-failed-{$order->id}\"")
        // Nothing has been captured, so there is nothing to refund yet.
        ->assertDontSeeHtml("data-test=\"order-refund-{$order->id}\"");
});

test('marking an order paid runs the same capture the buyer screen does', function () {
    $order = consolePendingOrder();
    $product = $order->product;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->call('markPaid', $order->id)
        ->assertHasNoErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->currentPayment->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->currentPayment->reference)->toStartWith('MANUAL-')
        ->and($product->fresh()->download_count)->toBe(1);
});

test('marking an order failed settles its attempt as declined', function () {
    $order = consolePendingOrder();
    $product = $order->product;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->call('markFailed', $order->id)
        ->assertHasNoErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->currentPayment->status)->toBe(PaymentStatus::Failed)
        ->and($order->paid_at)->toBeNull()
        ->and($product->fresh()->download_count)->toBe(0);
});

test('settling an already paid order changes nothing', function () {
    $order = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->call('markPaid', $order->id);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        // The capture that stands is the original one, not the extra click.
        ->and($order->currentPayment->reference)->toBe('SANDBOX-CONSOLE')
        ->and($order->product->fresh()->download_count)->toBe(1);
});

test('a paid order offers a refund form, and issuing one writes the ledger', function () {
    $order = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->call('toggle', $order->id)
        ->assertSeeHtml("data-test=\"order-refund-{$order->id}\"")
        ->set("refundAmount.{$order->id}", '25.00')
        ->set('refundReason', 'Duplicate charge.')
        ->call('refund', $order->id)
        ->assertHasNoErrors();

    $order->refresh();
    $refund = MarketplaceRefund::query()->sole();

    expect($refund->amount_cents)->toBe(2500)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reason)->toBe('Duplicate charge.')
        ->and($refund->currency)->toBe('USD')
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refunded_at)->not->toBeNull();
});

test('a partial refund leaves the order partially refunded and still entitling', function () {
    $order = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->set("refundAmount.{$order->id}", '10.00')
        ->call('refund', $order->id)
        ->assertHasNoErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->refundedCents())->toBe(1000)
        ->and($order->refundableCents())->toBe(1500)
        ->and($order->isEntitling())->toBeTrue()
        ->and($order->refunded_at)->toBeNull();
});

test('an over-refund typed into the form becomes a field error, not a write', function () {
    $order = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->set("refundAmount.{$order->id}", '99.00')
        ->call('refund', $order->id)
        ->assertHasErrors(['refundAmount.'.$order->id]);

    expect(MarketplaceRefund::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('a refund typed as nothing is refused before the action runs', function () {
    $order = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->set("refundAmount.{$order->id}", '0')
        ->call('refund', $order->id)
        ->assertHasErrors(['refundAmount.'.$order->id]);

    expect(MarketplaceRefund::query()->count())->toBe(0);
});

test('a refund is written by the account that issued it', function () {
    $order = consolePaidOrder();
    $actor = User::factory()->admin()->create();

    Livewire::actingAs($actor)
        ->test('pages::admin.orders')
        ->set("refundAmount.{$order->id}", '5.00')
        ->call('refund', $order->id);

    expect(MarketplaceRefund::query()->sole()->processed_by)->toBe($actor->id);
});

test('no shipped role may read the ledger without also being able to move money', function () {
    /*
     * `boot()` asks for `sale:view-any` and every mutation asks for
     * `refund:process`, so the two abilities are separable in principle. Today
     * they are not separable in practice: the only role holding the read
     * ability is the admin, who holds the money one too. That is worth pinning
     * — it is why the refusals above are asserted at the boot guard, and the
     * day a read-only moderator role lands, this test names the console that
     * must then refuse its posts.
     */
    $readers = array_filter(
        AccountRole::cases(),
        fn (AccountRole $role): bool => $role->hasPermission(AccountPermission::ViewAnySales),
    );

    expect($readers)->not->toBe([]);

    foreach ($readers as $role) {
        expect($role->hasPermission(AccountPermission::ProcessRefunds))
            ->toBeTrue("[{$role->value}] may open the console but not settle it.");
    }
});

test('a refund posted against an order that never captured money is refused', function () {
    $pending = consolePendingOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->set("refundAmount.{$pending->id}", '10.00')
        ->call('refund', $pending->id)
        ->assertHasErrors(['refundAmount.'.$pending->id]);

    expect(MarketplaceRefund::query()->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(OrderStatus::Pending);
});

test('the status filter and the search narrow the ledger', function () {
    $pending = consolePendingOrder();
    $paid = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->set('statusFilter', OrderStatus::Pending->value)
        ->assertSee($pending->order_number)
        ->assertDontSee($paid->order_number)
        ->set('statusFilter', OrderStatus::Paid->value)
        ->assertSee($paid->order_number)
        ->assertDontSee($pending->order_number)
        ->set('search', 'Console Paid Kit')
        ->assertSee($paid->order_number)
        ->assertDontSee($pending->order_number);
});

test('searching by an order number finds that one order', function () {
    $pending = consolePendingOrder();
    $paid = consolePaidOrder();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->set('search', $pending->order_number)
        ->assertSee($pending->order_number)
        ->assertDontSee($paid->order_number);
});

test('expanding a row reveals the attempts and refunds behind it', function () {
    $order = consolePaidOrder();

    MarketplaceRefund::factory()->create([
        'marketplace_order_id' => $order->getKey(),
        'amount_cents' => 500,
        'currency' => $order->currency,
        'reason' => 'A goodwill gesture.',
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->call('toggle', $order->id)
        ->assertSee('SANDBOX-CONSOLE')
        ->assertSee('A goodwill gesture.')
        ->assertSee('$5.00')
        ->call('toggle', $order->id)
        ->assertDontSee('A goodwill gesture.');
});

test('a console with nothing in it still renders', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.orders')
        ->assertOk()
        ->assertSee('No orders match');
});
