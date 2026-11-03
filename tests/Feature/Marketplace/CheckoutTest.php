<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\ProductStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceProduct;
use App\Models\PaymentSetting;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| Checkout is where the payment console meets the buyer, so the tests follow
| the two facts that cross that boundary: the methods offered are exactly the
| ones `PaymentSetting::enabled()` says are live, and placing an order opens a
| claim on money without ever capturing it. A Pending order with an open
| payment attempt is the correct end state of this screen; anything that
| reaches `Paid` from here would be the bug.
|
| The refusals are asserted alongside, because each is a different hole: an
| unpublished listing should not be reachable at all (404), a seller's own
| listing should not be buyable (403), a method the console switched off must
| not be accepted even though the value is a legal enum case, and a guest must
| be sent to sign in rather than handed a form that could not save anything.
|
*/

/**
 * A priced, published listing the signed-in account does not own.
 */
function purchasableListing(): MarketplaceProduct
{
    return MarketplaceProduct::factory()->published()->priced(4900, 'USD')
        ->create(['user_id' => User::factory()->seller()->create()]);
}

/**
 * Turn one provider on in the payment console, as an operator would.
 */
function enablePayment(PaymentProvider $provider, ?string $instructions = null): PaymentSetting
{
    return enablePaymentProvider($provider, ['instructions' => $instructions]);
}

test('a guest is sent to sign in rather than shown the checkout form', function () {
    $product = purchasableListing();

    $this->get(route('marketplace.checkout', $product))->assertRedirect(route('home', ['signin' => 'required']));
});

test('checkout offers exactly the methods the payment console has enabled', function () {
    enablePayment(PaymentProvider::Bkash, 'Pay with your bKash PIN.');
    PaymentSetting::factory()->create(['provider' => PaymentProvider::Card, 'currency' => 'USD', 'enabled' => false]);
    PaymentSetting::factory()->create(['provider' => PaymentProvider::Nagad, 'currency' => 'BDT', 'enabled' => false]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.checkout', ['product' => purchasableListing()]);

    $component
        ->assertSee(PaymentProvider::Bkash->label())
        ->assertSee('Pay with your bKash PIN.')
        ->assertSeeHtml('data-test="checkout-provider-bkash"')
        ->assertDontSeeHtml('data-test="checkout-provider-card"')
        ->assertDontSeeHtml('data-test="checkout-provider-nagad"');
});

test('placing an order opens it as pending with one unpaid attempt', function () {
    enablePayment(PaymentProvider::Card);
    $product = purchasableListing()->fresh();
    $buyer = User::factory()->create();

    $component = Livewire::actingAs($buyer)
        ->test('pages::marketplace.checkout', ['product' => $product])
        ->set('provider', PaymentProvider::Card->value)
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = MarketplaceOrder::query()->sole();

    $component->assertRedirect(route('purchases.show', $order));

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->paid_at)->toBeNull()
        ->and($order->user_id)->toBe($buyer->id)
        ->and($order->marketplace_product_id)->toBe($product->id)
        ->and($order->price_cents)->toBe(4900)
        ->and($order->currency)->toBe('USD')
        ->and($order->payment_provider)->toBe(PaymentProvider::Card)
        ->and(MarketplacePayment::query()->count())->toBe(1)
        // Nothing has been captured, so nothing has been downloaded either.
        ->and($product->fresh()->download_count)->toBe(0);
});

test('a priced listing cannot be ordered without choosing a method', function () {
    enablePayment(PaymentProvider::Card);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.checkout', ['product' => purchasableListing()])
        ->call('placeOrder')
        ->assertHasErrors(['provider']);

    expect(MarketplaceOrder::query()->count())->toBe(0);
});

test('a method the console switched off is refused even when posted directly', function () {
    enablePayment(PaymentProvider::Bkash);

    // `card` is a legal provider; it is simply not enabled. The route renders
    // no radio for it, so this is a hand-built request, exactly the kind the
    // component's own view cannot produce.
    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.checkout', ['product' => purchasableListing()])
        ->set('provider', PaymentProvider::Card->value)
        ->call('placeOrder')
        ->assertHasErrors(['provider']);

    expect(MarketplaceOrder::query()->count())->toBe(0)
        ->and(MarketplacePayment::query()->count())->toBe(0);
});

test('checkout says so when no method is enabled', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.checkout', ['product' => purchasableListing()])
        ->assertSee('No payment method is available right now.')
        ->call('placeOrder')
        ->assertHasErrors(['provider']);
});

test('a free listing is recorded as paid without a payment attempt', function () {
    $product = MarketplaceProduct::factory()->published()->free()
        ->create(['user_id' => User::factory()->seller()->create()]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.checkout', ['product' => $product])
        ->assertSee('Get for free')
        ->assertDontSee('Pay with')
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = MarketplaceOrder::query()->sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->price_cents)->toBe(0)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->payment_provider)->toBeNull()
        ->and(MarketplacePayment::query()->count())->toBe(0)
        ->and($product->fresh()->download_count)->toBe(1);
});

test('buying a listing twice returns the order the buyer already holds', function () {
    enablePayment(PaymentProvider::Card);
    $product = purchasableListing();
    $buyer = User::factory()->create();

    $first = MarketplaceOrder::factory()->forBuyer($buyer)->forProduct($product)->paid()->create();

    $component = Livewire::actingAs($buyer)
        ->test('pages::marketplace.checkout', ['product' => $product])
        ->set('provider', PaymentProvider::Card->value)
        ->call('placeOrder');

    // Redirected to the order that already entitles them, and no second sale.
    $component->assertRedirect(route('purchases.show', $first));

    expect(MarketplaceOrder::query()->count())->toBe(1)
        ->and(MarketplaceOrder::query()->where('status', OrderStatus::Pending)->count())->toBe(0)
        ->and(MarketplacePayment::query()->count())->toBe(0);
});

test('a withdrawn order may be placed again', function () {
    enablePayment(PaymentProvider::Card);
    $product = purchasableListing();
    $buyer = User::factory()->create();

    MarketplaceOrder::factory()->forBuyer($buyer)->forProduct($product)->cancelled()->create();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.checkout', ['product' => $product])
        ->set('provider', PaymentProvider::Card->value)
        ->call('placeOrder')
        ->assertHasNoErrors();

    expect(MarketplaceOrder::query()->count())->toBe(2)
        ->and(MarketplaceOrder::query()->where('status', OrderStatus::Pending)->count())->toBe(1);
});

test('a seller cannot buy their own listing', function () {
    enablePayment(PaymentProvider::Card);
    $seller = User::factory()->seller()->create();
    $product = MarketplaceProduct::factory()->published()->priced()->create(['user_id' => $seller]);

    Livewire::actingAs($seller)
        ->test('pages::marketplace.checkout', ['product' => $product])
        ->assertForbidden();

    expect(MarketplaceOrder::query()->count())->toBe(0);
})->group('security');

test('a listing that is not published has no checkout to reach', function () {
    $product = MarketplaceProduct::factory()->priced()->create([
        'status' => ProductStatus::PendingReview,
        'user_id' => User::factory()->seller()->create(),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.checkout', ['product' => $product])
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get(route('marketplace.checkout', $product))
        ->assertNotFound();
})->group('security');
