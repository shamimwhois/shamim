<?php

use App\Actions\Marketplace\ConfirmPayment;
use App\Actions\Marketplace\PlaceOrder;
use App\Actions\Marketplace\SaveBillingProfile;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Models\BillingProfile;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The invoice
|--------------------------------------------------------------------------
|
| Three rules make a receipt worth printing, and all three are testable from
| the outside: only the buyer and the sales book may open it, and everyone
| else is told there is no such document; nothing is invoiced until money is
| captured; and what is printed is the snapshot the order carries, never the
| live profile or the current listing.
|
| The fixtures are placed through {@see PlaceOrder} rather than written by the
| factory because the billing snapshot is taken at checkout. A factory row has
| no billing identity to print, which would let a regression in the copy pass
| as a rendering problem.
|
*/

/**
 * A captured order for a buyer whose billing profile was on file at checkout.
 */
function invoicedOrder(bool $withProfile = true): array
{
    enablePaymentProvider(PaymentProvider::Card);

    $buyer = User::factory()->create(['name' => 'Rafa Rahman', 'email' => 'rafa@example.test']);

    if ($withProfile) {
        app(SaveBillingProfile::class)->handle($buyer, [
            'legal_name' => 'Acme Workshop Ltd',
            'company' => 'Acme',
            'tax_id' => 'VAT-4471',
            'email' => 'accounts@acme.test',
            'phone' => null,
            'address_line1' => '12 Gulshan Avenue',
            'address_line2' => null,
            'city' => 'Dhaka',
            'postal_code' => '1212',
            'country' => 'BD',
        ]);
    }

    $product = MarketplaceProduct::factory()->published()->priced(5000, 'USD')
        ->create(['name' => 'Invoice Test Kit', 'user_id' => User::factory()->seller()->create()]);

    $order = app(PlaceOrder::class)->handle($product, $buyer, PaymentProvider::Card);

    app(ConfirmPayment::class)->handle($order, true, 'SANDBOX-INVOICE');

    return ['buyer' => $buyer, 'order' => $order->fresh()];
}

test('the buyer opens their own invoice and prints it', function () {
    $fixture = invoicedOrder();

    $this->actingAs($fixture['buyer'])
        ->get(route('purchases.invoice', $fixture['order']))
        ->assertOk()
        ->assertSee($fixture['order']->invoiceNumber())
        ->assertSee('Acme Workshop Ltd')
        ->assertSee('$50.00')
        ->assertSee('SANDBOX-INVOICE')
        // The print affordance is on the page; the chrome that drives it is not
        // on the paper. Both are contracts of the document, not of the styling.
        ->assertSeeHtml('data-test="invoice-print"')
        ->assertSeeHtml('print:hidden');
});

test('the invoice number is the capture year and the order’s own id', function () {
    $order = invoicedOrder()['order'];

    expect($order->invoiceNumber())->toBe(sprintf('INV-%s-%05d', $order->paid_at->format('Y'), $order->id));
});

test('an account that is not the buyer is told there is no such document', function () {
    $fixture = invoicedOrder();

    $this->actingAs(User::factory()->create())
        ->get(route('purchases.invoice', $fixture['order']))
        ->assertNotFound();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::marketplace.invoice', ['order' => $fixture['order']])
        ->assertNotFound();
})->group('security');

test('an operator holding the sales book may open any invoice', function () {
    $fixture = invoicedOrder();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('purchases.invoice', $fixture['order']))
        ->assertOk()
        ->assertSee($fixture['order']->invoiceNumber());
});

test('a guest is sent to sign in rather than shown a receipt', function () {
    $fixture = invoicedOrder();

    $this->get(route('purchases.invoice', $fixture['order']))
        ->assertRedirect(route('home', ['signin' => 'required']));
})->group('security');

test('an order with no captured money has no invoice', function (OrderStatus $status) {
    enablePaymentProvider(PaymentProvider::Card);

    $buyer = User::factory()->create();
    $product = MarketplaceProduct::factory()->published()->priced(1500, 'USD')
        ->create(['user_id' => User::factory()->seller()->create()]);

    $order = app(PlaceOrder::class)->handle($product, $buyer, PaymentProvider::Card);

    $order->update(['status' => $status]);

    expect($order->invoiceNumber())->toBeNull();

    $this->actingAs($buyer)
        ->get(route('purchases.invoice', $order))
        ->assertNotFound();
})->with([
    OrderStatus::Pending,
    OrderStatus::Processing,
    OrderStatus::Failed,
    OrderStatus::Cancelled,
])->group('security');

test('the invoice prints the identity on file at checkout, not the profile as it reads now', function () {
    $fixture = invoicedOrder();

    app(SaveBillingProfile::class)->handle($fixture['buyer'], [
        'legal_name' => 'Renamed Trading Co',
        'company' => null,
        'tax_id' => null,
        'email' => 'moved@example.test',
        'phone' => null,
        'address_line1' => '99 Other Road',
        'address_line2' => null,
        'city' => 'Chattogram',
        'postal_code' => null,
        'country' => null,
    ]);

    $this->actingAs($fixture['buyer'])
        ->get(route('purchases.invoice', $fixture['order']))
        ->assertOk()
        ->assertSee('Acme Workshop Ltd')
        ->assertSee('accounts@acme.test')
        ->assertDontSee('Renamed Trading Co')
        ->assertDontSee('moved@example.test');
})->group('security');

test('a buyer who never set up billing is still invoiced, in the name on their account', function () {
    $fixture = invoicedOrder(withProfile: false);

    expect(BillingProfile::forAccount($fixture['buyer']))->toBeNull()
        ->and($fixture['order']->billing_name)->toBe('Rafa Rahman');

    $this->actingAs($fixture['buyer'])
        ->get(route('purchases.invoice', $fixture['order']))
        ->assertOk()
        ->assertSee('Rafa Rahman')
        ->assertSee('rafa@example.test');
});

test('refunds reduce the total charged', function () {
    $fixture = invoicedOrder();

    MarketplaceRefund::factory()->partial(2000)->create([
        'marketplace_order_id' => $fixture['order']->getKey(),
    ]);

    $this->actingAs($fixture['buyer'])
        ->get(route('purchases.invoice', $fixture['order']->fresh()))
        ->assertOk()
        ->assertSee('$50.00')
        ->assertSee('$20.00')
        ->assertSee('$30.00');
});

test('a renamed listing cannot restate the line it was sold on', function () {
    $fixture = invoicedOrder();

    $fixture['order']->product->update(['name' => 'Something Else Entirely']);

    $this->actingAs($fixture['buyer'])
        ->get(route('purchases.invoice', $fixture['order']->fresh()))
        ->assertOk()
        ->assertSee('Invoice Test Kit')
        ->assertDontSee('Something Else Entirely');
});

test('the order screen offers the invoice only once the money is captured', function () {
    enablePaymentProvider(PaymentProvider::Card);

    $buyer = User::factory()->create();
    $product = MarketplaceProduct::factory()->published()->priced(900, 'USD')
        ->create(['user_id' => User::factory()->seller()->create()]);

    $pending = app(PlaceOrder::class)->handle($product, $buyer, PaymentProvider::Card);

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.payment', ['order' => $pending])
        ->assertDontSeeHtml('data-test="order-invoice"');

    app(ConfirmPayment::class)->handle($pending, true);

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.payment', ['order' => $pending->fresh()])
        ->assertSeeHtml(route('purchases.invoice', $pending));
});
