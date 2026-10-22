<?php

use App\Enums\AccountPermission;
use App\Enums\OrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The seller's sales screen
|--------------------------------------------------------------------------
|
| `sale:view-own` is a scope, not merely a gate: the same permission that opens
| the page also decides which rows the query is allowed to touch, so the test
| that matters is not "a seller is refused" but "a seller cannot see the
| neighbour's money". Both directions are asserted here.
|
| The admin case is asserted too, and it is the odd one. An admin holds this
| permission (every role's list includes it, and the admin holds all of them),
| so the console must mount for them and show their own sales — which, having
| published nothing, is an empty list rather than an error or somebody else's
| ledger. That is what the sweep at the bottom of
| `LivewireEndpointGuardSweepTest` expects of every gated component.
|
*/

/**
 * A seller with one published listing and one paid order against it.
 *
 * @return array{User, MarketplaceProduct, MarketplaceOrder}
 */
function sellerWithSale(?User $seller = null): array
{
    $seller ??= User::factory()->seller()->create();

    $product = MarketplaceProduct::factory()->published()->priced(4900, 'USD')
        ->create(['user_id' => $seller]);

    $order = MarketplaceOrder::factory()->forProduct($product)
        ->forBuyer(User::factory()->create(['name' => 'Wren Assistant']))
        ->paid()
        ->create();

    return [$seller, $product, $order];
}

test('a seller sees the orders placed against their own listings', function () {
    [$seller, $product, $order] = sellerWithSale();

    Livewire::actingAs($seller)
        ->test('pages::seller.sales')
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee($product->name)
        ->assertSee('Wren Assistant')
        ->assertSee('$49.00');
});

test('the money of another seller stays out of the list', function () {
    [, , $theirs] = sellerWithSale();

    $mine = User::factory()->seller()->create();

    Livewire::actingAs($mine)
        ->test('pages::seller.sales')
        ->assertOk()
        ->assertSee('No sales here yet')
        ->assertDontSee($theirs->order_number);
})->group('security');

test('an account without the selling permission is refused, route and component both', function () {
    $plain = User::factory()->create();

    expect($plain->hasPermission(AccountPermission::ViewOwnSales))->toBeFalse();

    $this->actingAs($plain)->get('/seller/sales')->assertForbidden();

    // The endpoint is the one that matters: a Livewire POST never passes
    // through the route middleware, so the boot guard has to hold alone.
    Livewire::actingAs($plain)->test('pages::seller.sales')->assertForbidden();
})->group('security');

test('an admin may open the console and sees their own sales, which are none', function () {
    [, , $stranger] = sellerWithSale();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::seller.sales')
        ->assertOk()
        ->assertSee('No sales here yet')
        ->assertDontSee($stranger->order_number);
});

test('the totals describe the whole book rather than the filtered page', function () {
    $seller = User::factory()->seller()->create();

    [, $product, $first] = sellerWithSale($seller);
    [, , $second] = sellerWithSale($seller);

    $second->update(['status' => OrderStatus::Pending, 'paid_at' => null]);

    $component = Livewire::actingAs($seller)->test('pages::seller.sales');

    // Only entitling orders contribute gross, but the pending row still lists,
    // so the header and the table describe different sets by design.
    $component
        ->assertSee('Gross (USD)')
        ->assertSee('$49.00')
        ->assertSee($second->order_number);

    expect($component->get('totals')['USD']['orders'])->toBe(1)
        ->and($component->get('totals')['USD']['gross_cents'])->toBe(4900)
        ->and($first->marketplace_product_id)->toBe($product->id);
});

test('a refund reaches the totals without erasing the sale', function () {
    [$seller, , $order] = sellerWithSale();

    MarketplaceRefund::factory()->create([
        'marketplace_order_id' => $order->getKey(),
        'amount_cents' => 1900,
        'currency' => $order->currency,
    ]);

    $totals = Livewire::actingAs($seller)
        ->test('pages::seller.sales')
        ->get('totals');

    expect($totals['USD']['gross_cents'])->toBe(4900)
        ->and($totals['USD']['refunded_cents'])->toBe(1900);
});

test('a pending order is not gross the seller has earned', function () {
    [$seller, , $order] = sellerWithSale();

    $order->update(['status' => OrderStatus::Pending]);

    expect(Livewire::actingAs($seller)->test('pages::seller.sales')->get('totals'))->toBeEmpty();
});

test('the status chips filter the list and the search narrows it', function () {
    $seller = User::factory()->seller()->create();
    [, , $paid] = sellerWithSale($seller);
    [, , $pending] = sellerWithSale($seller);

    $pending->update(['status' => OrderStatus::Pending, 'paid_at' => null]);

    Livewire::actingAs($seller)
        ->test('pages::seller.sales')
        ->set('statusFilter', OrderStatus::Paid->value)
        ->assertSee($paid->order_number)
        ->assertDontSee($pending->order_number)
        ->set('statusFilter', OrderStatus::Pending->value)
        ->assertSee($pending->order_number)
        ->assertDontSee($paid->order_number)
        ->set('search', $pending->order_number)
        ->assertSee($pending->order_number)
        ->assertDontSee($paid->order_number);
});

test('a seller searching for a stranger order number finds nothing', function () {
    [, , $theirs] = sellerWithSale();
    $mine = User::factory()->seller()->create();

    Livewire::actingAs($mine)
        ->test('pages::seller.sales')
        ->set('search', $theirs->order_number)
        ->assertSee('No sales here yet');
})->group('security');

test('the sales route is offered to the accounts that may open it', function () {
    $this->actingAs(User::factory()->seller()->create())
        ->get('/seller/sales')
        ->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/seller/sales')
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->get('/seller/sales')
        ->assertForbidden();

    // Both roles hold the permission, so the guard has nothing to refuse yet;
    // the sweeps in Feature/Routing assert the refusal the day a role loses it.
    expect(User::factory()->seller()->create()->hasPermission(AccountPermission::ViewOwnSales))->toBeTrue();
});

test('a guest is bounced from the sales screen before the permission is asked', function () {
    $this->get('/seller/sales')->assertRedirect(route('home', ['signin' => 'required']));
});
