<?php

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BillingProfile;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The purchases hub: three views over one account's money
|--------------------------------------------------------------------------
|
| The hub's rules are the ones the tab is not allowed to break: the view is
| chosen from a whitelist, every list is pinned to the session's account
| before it is filtered, and the billing form writes an identity that future
| invoices read — never one that rewrites a receipt already issued.
|
| The `?tab=` and `?kind=` guards are asserted twice on purpose, at the route
| and at the component. A Livewire update POST never re-traverses the route
| middleware, so the component check is the one that actually holds when the
| query string arrives from a client.
|
*/

/**
 * A buyer holding one paid order with an attempt on file, plus the same shape
 * belonging to an account that must never appear in their lists.
 *
 * @return array{buyer: User, order: MarketplaceOrder, foreign: MarketplaceOrder}
 */
function hubLedger(): array
{
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();

    $mine = MarketplaceOrder::factory()->forBuyer($buyer)->paid()->create();
    $theirs = MarketplaceOrder::factory()->forBuyer($stranger)->paid()->create();

    MarketplacePayment::factory()->create([
        'marketplace_order_id' => $mine->getKey(),
        'status' => PaymentStatus::Succeeded,
        'reference' => 'HUB-MINE-REF',
        'processed_at' => now(),
    ]);

    MarketplacePayment::factory()->create([
        'marketplace_order_id' => $theirs->getKey(),
        'status' => PaymentStatus::Succeeded,
        'reference' => 'HUB-THEIRS-REF',
        'processed_at' => now(),
    ]);

    return ['buyer' => $buyer, 'order' => $mine, 'foreign' => $theirs];
}

test('the hub renders the view the tab asks for', function (string $tab, string $marker) {
    $buyer = hubLedger()['buyer'];

    $this->actingAs($buyer)
        ->get(route('purchases.index', ['tab' => $tab]))
        ->assertOk()
        ->assertSee($marker);
})->with([
    ['orders', 'All orders'],
    ['payments', 'Payment attempts'],
    ['billing', 'Billing details'],
]);

test('a view that does not exist answers 404 at the route and in the component', function () {
    $buyer = hubLedger()['buyer'];

    $this->actingAs($buyer)->get(route('purchases.index', ['tab' => 'transactions']))->assertNotFound();
    $this->actingAs($buyer)->get(route('purchases.index', ['tab' => 'payments', 'kind' => 'ledger']))->assertNotFound();
    $this->actingAs($buyer)->get(route('purchases.index', ['tab' => 'orders', 'status' => 'yesterday']))->assertNotFound();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.purchases')
        ->call('showTab', 'transactions')
        ->assertNotFound();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.purchases')
        ->call('showKind', 'ledger')
        ->assertNotFound();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.purchases')
        ->call('filterStatus', 'yesterday')
        ->assertNotFound();
})->group('security');

test('the orders tab narrows to one status group', function () {
    $ledger = hubLedger();

    $buyer = $ledger['buyer'];
    $owned = $ledger['order'];
    $cancelled = MarketplaceOrder::factory()->forBuyer($buyer)->cancelled()->create();

    $this->actingAs($buyer)
        ->get(route('purchases.index', ['status' => 'cancelled']))
        ->assertOk()
        ->assertSee($cancelled->order_number)
        ->assertDontSee($owned->order_number);

    $this->actingAs($buyer)
        ->get(route('purchases.index', ['status' => 'owned']))
        ->assertOk()
        ->assertSee($owned->order_number)
        ->assertDontSee($cancelled->order_number);
});

test('the ledger lists this account’s attempts and nothing belonging to anyone else', function () {
    $ledger = hubLedger();

    $this->actingAs($ledger['buyer'])
        ->get(route('purchases.index', ['tab' => 'payments']))
        ->assertOk()
        ->assertSee('HUB-MINE-REF')
        ->assertSee($ledger['order']->order_number)
        ->assertDontSee('HUB-THEIRS-REF')
        ->assertDontSee($ledger['foreign']->order_number);
})->group('security');

test('the refunds half of the ledger shows money coming back', function () {
    $ledger = hubLedger();

    MarketplaceRefund::factory()->create([
        'marketplace_order_id' => $ledger['order']->getKey(),
        'status' => RefundStatus::Succeeded,
        'reason' => 'Duplicate charge reversed',
    ]);

    $this->actingAs($ledger['buyer'])
        ->get(route('purchases.index', ['tab' => 'payments', 'kind' => 'refunds']))
        ->assertOk()
        ->assertSee('Duplicate charge reversed')
        ->assertSee(RefundStatus::Succeeded->label())
        ->assertDontSee('HUB-MINE-REF');
});

test('the ledger keeps its kind across pages', function () {
    $buyer = User::factory()->create();

    MarketplaceOrder::factory()->count(12)->forBuyer($buyer)->paid()->create()
        ->each(fn (MarketplaceOrder $order) => MarketplaceRefund::factory()->create([
            'marketplace_order_id' => $order->getKey(),
        ]));

    $this->actingAs($buyer)
        ->get(route('purchases.index', ['tab' => 'payments', 'kind' => 'refunds']))
        ->assertOk()
        ->assertSeeHtml('kind=refunds');
});

test('the billing tab stores the identity future invoices will be issued to', function () {
    $buyer = User::factory()->create();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.purchases')
        ->call('showTab', 'billing')
        ->set('billing.legal_name', 'Acme Workshop Ltd')
        ->set('billing.company', 'Acme Workshop')
        ->set('billing.tax_id', 'vat-4471')
        ->set('billing.email', 'billing@acme.test')
        ->set('billing.address_line1', '12 Gulshan Avenue')
        ->set('billing.city', 'Dhaka')
        ->set('billing.postal_code', '1212')
        ->set('billing.country', 'BD')
        ->call('saveBilling')
        ->assertHasNoErrors();

    $profile = BillingProfile::forAccount($buyer);

    expect($profile)->not->toBeNull()
        ->and($profile->legal_name)->toBe('Acme Workshop Ltd')
        ->and($profile->tax_id)->toBe('VAT-4471')
        ->and($profile->email)->toBe('billing@acme.test')
        ->and($profile->country)->toBe('BD')
        ->and($profile->address_line2)->toBeNull();
});

test('a country that is not one of the offered codes is refused', function () {
    $buyer = User::factory()->create();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.purchases')
        ->set('billing.address_line1', '12 Gulshan Avenue')
        ->set('billing.city', 'Dhaka')
        ->set('billing.country', 'bd')
        ->call('saveBilling')
        ->assertHasErrors(['billing.country']);

    expect(BillingProfile::forAccount($buyer))->toBeNull();
});

test('the billing form refuses an identity it could not print an invoice for', function (string $field) {
    $buyer = User::factory()->create();

    Livewire::actingAs($buyer)
        ->test('pages::marketplace.purchases')
        ->set("billing.$field", '')
        ->call('saveBilling')
        ->assertHasErrors(["billing.$field" => 'required']);
})->with(['legal_name', 'email', 'address_line1', 'city']);

test('the order screen lists every attempt against it, not just the last one', function () {
    $ledger = hubLedger();

    $declined = MarketplacePayment::factory()->create([
        'marketplace_order_id' => $ledger['order']->getKey(),
        'status' => PaymentStatus::Failed,
        'reference' => null,
        'failure_reason' => 'The card’s issuer declined the attempt.',
        'processed_at' => now(),
    ]);

    Livewire::actingAs($ledger['buyer'])
        ->test('pages::marketplace.payment', ['order' => $ledger['order']])
        ->assertSee('Payment attempts')
        ->assertSee('HUB-MINE-REF')
        ->assertSee($declined->failure_reason)
        ->assertSee(PaymentStatus::Failed->label());
});
