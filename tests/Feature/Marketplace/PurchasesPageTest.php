<?php

use App\Actions\Marketplace\ConfirmPayment;
use App\Actions\Marketplace\PlaceOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The buyer's own screens
|--------------------------------------------------------------------------
|
| Purchases is a list of one account's rows and nothing else, and the order
| screen is reachable by its owner alone. Both rules answer with 404 rather
| than 403: an order number is not public information, and the difference
| between "you may not see this" and "there is nothing here" would tell a
| caller which ids exist.
|
| The routes are `auth`-only by decision — buying is not a permission — so
| none of these screens appear in the guard sweeps. What is asserted here
| instead is that ownership is checked where a Livewire POST actually lands,
| which is the component, not the route.
|
*/

/**
 * A payable order belonging to a freshly created buyer.
 */
function buyerOrder(?User $buyer = null): MarketplaceOrder
{
    enablePaymentProvider(PaymentProvider::Card);

    $product = MarketplaceProduct::factory()->published()->priced(2500, 'USD')
        ->create(['user_id' => User::factory()->seller()->create()]);

    return app(PlaceOrder::class)->handle($product, $buyer ?? User::factory()->create(), PaymentProvider::Card);
}

test('a guest reaching a purchase screen is sent to sign in', function () {
    $order = buyerOrder();

    $this->get(route('purchases.index'))->assertRedirect(route('home', ['signin' => 'required']));
    $this->get(route('purchases.show', $order))->assertRedirect(route('home', ['signin' => 'required']));
})->group('security');

test('the list shows only orders that belong to the signed-in account', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $owned = buyerOrder($mine);
    $stranger = buyerOrder($theirs);

    $this->actingAs($mine)
        ->get(route('purchases.index'))
        ->assertOk()
        ->assertSee($owned->product_name)
        ->assertSee($owned->order_number)
        ->assertDontSee($stranger->order_number);
});

test('an empty list invites the buyer to the catalogue instead of failing', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('purchases.index'))
        ->assertOk()
        ->assertSee('Nothing bought yet.');
});

test('the order screen belongs to its buyer alone', function () {
    $order = buyerOrder();

    $this->actingAs($order->buyer)->get(route('purchases.show', $order))->assertOk();

    $this->actingAs(User::factory()->create())
        ->get(route('purchases.show', $order))
        ->assertNotFound();
})->group('security');

test('mounting the order of another account answers 404 rather than 403', function () {
    $order = buyerOrder();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.payment', ['order' => $order])
        ->assertNotFound();
})->group('security');

test('a buyer settles their own order with the sandbox buttons', function () {
    $order = buyerOrder();
    $buyer = $order->buyer;

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.payment', ['order' => $order])
        ->assertSee('Sandbox payment')
        ->assertSeeHtml('data-test="order-confirm-paid"')
        ->call('confirmPaid')
        ->assertHasNoErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->currentPayment->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->currentPayment->reference)->toStartWith('SANDBOX-');
});

test('a declined attempt leaves no sandbox on screen', function () {
    $order = buyerOrder();

    Livewire::actingAs($order->buyer)
        ->test('pages::marketplace.payment', ['order' => $order])
        ->call('confirmFailed')
        ->assertSee(OrderStatus::Failed->label());

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->currentPayment->status)->toBe(PaymentStatus::Failed);

    // Once resolved, the controls that move money are gone from the page.
    Livewire::actingAs($order->buyer)
        ->test('pages::marketplace.payment', ['order' => $order])
        ->assertDontSeeHtml('data-test="order-confirm-paid"')
        ->assertDontSeeHtml('data-test="order-cancel"');
});

test('cancelling from the order screen withdraws it', function () {
    $order = buyerOrder();

    Livewire::actingAs($order->buyer)
        ->test('pages::marketplace.payment', ['order' => $order])
        ->call('cancelOrder');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->cancelled_at)->not->toBeNull();
});

test('the sandbox buttons refuse an account that has lost ownership', function (string $action) {
    /*
     * The mount check passed when the order was theirs; the mutation happens
     * after it does not. A Livewire update POST never re-traverses the route
     * middleware, so this is the case the in-action ownership check exists for.
     */
    $order = buyerOrder();

    $component = Livewire::actingAs($order->buyer)
        ->test('pages::marketplace.payment', ['order' => $order]);

    $order->update(['user_id' => User::factory()->create()->id]);

    $component->call($action)->assertNotFound();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with(['confirmPaid', 'confirmFailed', 'cancelOrder'])->group('security');

test('a resolved order still renders for its buyer with what they now own', function () {
    $order = buyerOrder();

    app(ConfirmPayment::class)->handle($order, true);

    Livewire::actingAs($order->buyer)
        ->test('pages::marketplace.payment', ['order' => $order->fresh()])
        ->assertSee(OrderStatus::Paid->label())
        ->assertSee('You own this listing.')
        ->assertSee($order->product->install_command);
});

test('a free order shows no payment method and nothing to settle', function () {
    // A free order records no provider, so the screen must print neither a
    // method that was never chosen nor the controls that move money.
    $product = MarketplaceProduct::factory()->published()->free()
        ->create(['user_id' => User::factory()->seller()->create()]);
    $buyer = User::factory()->create();

    $order = app(PlaceOrder::class)->handle($product, $buyer, null);

    expect($order->payment_provider)->toBeNull()
        ->and($order->payment_mode)->toBeNull();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.payment', ['order' => $order])
        ->assertSee('Free')
        ->assertSee('—')
        ->assertDontSeeHtml('data-test="order-sandbox"');
});

test('the purchases list links each row to its own order screen', function () {
    $order = buyerOrder();

    Livewire::actingAs($order->buyer)
        ->test('pages::marketplace.purchases')
        ->assertSeeHtml(route('purchases.show', $order))
        ->assertSee($order->order_number);
});
