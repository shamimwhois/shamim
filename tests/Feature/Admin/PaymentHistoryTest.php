<?php

use App\Enums\AccountPermission;
use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceRefund;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The admin payment ledger
|--------------------------------------------------------------------------
|
| This screen's whole job is to read across accounts, so the tests are built
| around what must not leak into the read and what must not be lost from it: an
| under-privileged role refuses at both the route and the component, every
| attempt on the platform appears whoever bought it, and the money is reported
| in the currency it was taken in rather than added into one meaningless total.
|
| The row assertions read `instance()->rows` because the ledger is a query
| shaped into arrays — how a badge is coloured says nothing about whether
| somebody else's deposit slipped into the page.
|
*/

/**
 * Three attempts and one refund spread over two buyers and two currencies.
 *
 * @return array{admin: User, captured: MarketplaceOrder, declined: MarketplaceOrder, foreign: MarketplaceOrder, refund: MarketplaceRefund}
 */
function ledgerRows(): array
{
    $admin = User::factory()->admin()->create(['name' => 'Ines Operator']);

    $captured = MarketplaceOrder::factory()
        ->forBuyer(User::factory()->create(['name' => 'Rosa Attempt']))
        ->paid()
        ->create([
            'order_number' => 'ORD-LED-CAPTURED',
            'price_cents' => 5000,
            'currency' => 'USD',
        ]);

    $declined = MarketplaceOrder::factory()
        ->forBuyer(User::factory()->create(['name' => 'Kamal Attempt']))
        ->create([
            'order_number' => 'ORD-LED-DECLINED',
            'price_cents' => 5000,
            'currency' => 'USD',
            'payment_provider' => PaymentProvider::Bkash,
            'payment_mode' => PaymentMode::Live,
        ]);

    $foreign = MarketplaceOrder::factory()
        ->forBuyer(User::factory()->create(['name' => 'Nila Attempt']))
        ->paid()
        ->create([
            'order_number' => 'ORD-LED-BDT',
            'price_cents' => 250000,
            'currency' => 'BDT',
            'payment_mode' => PaymentMode::Live,
        ]);

    MarketplacePayment::factory()->create([
        'marketplace_order_id' => $captured->getKey(),
        'provider' => PaymentProvider::Card,
        'mode' => PaymentMode::Test,
        'amount_cents' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'reference' => 'LED-CAPTURED-REF',
        'processed_at' => now(),
    ]);

    MarketplacePayment::factory()->create([
        'marketplace_order_id' => $declined->getKey(),
        'provider' => PaymentProvider::Bkash,
        'mode' => PaymentMode::Live,
        'amount_cents' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Failed,
        'failure_reason' => 'LED-DECLINED-BY-BANK',
        'processed_at' => now(),
    ]);

    MarketplacePayment::factory()->create([
        'marketplace_order_id' => $foreign->getKey(),
        'provider' => PaymentProvider::Card,
        'mode' => PaymentMode::Live,
        'amount_cents' => 250000,
        'currency' => 'BDT',
        'status' => PaymentStatus::Succeeded,
        'reference' => 'LED-BDT-REF',
        'processed_at' => now(),
    ]);

    $refund = MarketplaceRefund::factory()->create([
        'marketplace_order_id' => $captured->getKey(),
        'amount_cents' => 2000,
        'currency' => 'USD',
        'status' => RefundStatus::Succeeded,
        'reason' => 'LED-REFUND-NOTE',
        'processed_by' => $admin->getKey(),
        'processed_at' => now(),
    ]);

    return [
        'admin' => $admin,
        'captured' => $captured,
        'declined' => $declined,
        'foreign' => $foreign,
        'refund' => $refund,
    ];
}

/**
 * The order numbers on the filtered page, ordered by name rather than by the
 * instant each row was written.
 *
 * @return array<int, string>
 */
function ledgerOrderNumbers(Testable $component): array
{
    return $component->instance()->rows->pluck('order_number')->sort()->values()->all();
}

test('a guest is bounced to the sign-in dialog', function () {
    $this->get('/admin/payments')->assertRedirect(route('home', ['signin' => 'required']));
});

test('an account without sale:view-any cannot open or mount the ledger', function () {
    $plain = User::factory()->create();
    $seller = User::factory()->seller()->create();

    expect($plain->hasPermission(AccountPermission::ViewAnySales))->toBeFalse()
        ->and($seller->hasPermission(AccountPermission::ViewAnySales))->toBeFalse();

    $this->actingAs($plain)->get('/admin/payments')->assertForbidden();
    $this->actingAs($seller)->get('/admin/payments')->assertForbidden();

    // The component guard has to hold on its own: a POST to the Livewire
    // endpoint never passes through the route's permission middleware.
    Livewire::actingAs($plain)->test('pages::admin.payments')->assertForbidden();
    Livewire::actingAs($seller)->test('pages::admin.payments')->assertForbidden();
})->group('security');

test('the attempts tab lists every account\'s deposits, not just one buyer\'s', function () {
    $ledger = ledgerRows();

    Livewire::actingAs($ledger['admin'])
        ->test('pages::admin.payments')
        ->assertOk()
        ->assertSee('LED-CAPTURED-REF')
        ->assertSee('LED-BDT-REF')
        ->assertSee('LED-DECLINED-BY-BANK')
        ->assertSee('Rosa Attempt')
        ->assertSee('Nila Attempt');
});

test('the band counts the platform and reports value one line per currency', function () {
    $ledger = ledgerRows();

    $component = Livewire::actingAs($ledger['admin'])->test('pages::admin.payments');

    expect($component->instance()->counts)->toBe([
        'attempts' => 3,
        'captured' => 2,
        'declined' => 1,
        'open' => 0,
        'refunds' => 1,
        'refunds_issued' => 1,
    ]);

    $values = $component->instance()->valueByCurrency;

    // Two dollars and one taka are never added together into one total.
    expect($values)->toHaveKeys(['USD', 'BDT'])
        ->and($values['USD']['captured_cents'])->toBe(5000)
        ->and($values['USD']['refunded_cents'])->toBe(2000)
        ->and($values['BDT']['captured_cents'])->toBe(250000)
        ->and($values['BDT']['refunded_cents'])->toBe(0);
});

test('the gateway, mode and status filters each narrow the attempts', function (string $filter, string $value, array $expected) {
    $ledger = ledgerRows();

    $component = Livewire::actingAs($ledger['admin'])
        ->test('pages::admin.payments')
        ->set($filter, $value);

    expect(ledgerOrderNumbers($component))->toBe($expected);
})->with([
    ['provider', 'bkash', ['ORD-LED-DECLINED']],
    ['provider', 'card', ['ORD-LED-BDT', 'ORD-LED-CAPTURED']],
    ['mode', 'test', ['ORD-LED-CAPTURED']],
    ['mode', 'live', ['ORD-LED-BDT', 'ORD-LED-DECLINED']],
    ['status', 'succeeded', ['ORD-LED-BDT', 'ORD-LED-CAPTURED']],
    ['status', 'failed', ['ORD-LED-DECLINED']],
]);

test('the search box finds a row by its gateway reference or by its order number', function (string $term) {
    $ledger = ledgerRows();

    $component = Livewire::actingAs($ledger['admin'])
        ->test('pages::admin.payments')
        ->set('search', $term);

    expect(ledgerOrderNumbers($component))->toBe(['ORD-LED-CAPTURED']);
})->with(['LED-CAPTURED-REF', 'ORD-LED-CAPTURED']);

test('the refunds tab reads the other half of the ledger', function () {
    $ledger = ledgerRows();

    Livewire::actingAs($ledger['admin'])
        ->test('pages::admin.payments')
        ->call('showTab', 'refunds')
        ->assertOk()
        ->assertSee('LED-REFUND-NOTE')
        ->assertSee('Ines Operator')
        // An attempt's reference belongs to the other tab; a refund row carries its reason.
        ->assertDontSee('LED-CAPTURED-REF');
});

test('switching tab drops the filters the other half does not have', function () {
    $ledger = ledgerRows();

    Livewire::actingAs($ledger['admin'])
        ->test('pages::admin.payments')
        ->set('provider', 'card')
        ->set('mode', 'test')
        ->set('status', 'succeeded')
        ->call('showTab', 'refunds')
        ->assertSet('provider', 'all')
        ->assertSet('mode', 'all')
        ->assertSet('status', 'all')
        ->assertSee('LED-REFUND-NOTE');
});

test('a filter that names nothing answers not found rather than listing everything', function (array $query) {
    $ledger = ledgerRows();

    $this->actingAs($ledger['admin'])
        ->get(route('admin.payments', $query))
        ->assertNotFound();
})->with([
    [['tab' => 'ledger']],
    [['provider' => 'netscape']],
    [['mode' => 'yesterday']],
    [['status' => 'green']],
    [['tab' => 'refunds', 'status' => 'green']],
]);

test('an attempt row links its invoice to the order, not to itself', function () {
    // Two paid orders are created before the attempt so the ledger row carries
    // a higher id than the order it belongs to: a link built from the row's own
    // id would open somebody else's receipt.
    $unlisted = MarketplaceOrder::factory()->paid()->create();

    $order = MarketplaceOrder::factory()
        ->forBuyer(User::factory()->create(['name' => 'Ida Ledger']))
        ->paid()
        ->create(['order_number' => 'ORD-LED-LINK']);

    $attempt = MarketplacePayment::factory()->create([
        'marketplace_order_id' => $order->getKey(),
        'status' => PaymentStatus::Succeeded,
        'reference' => 'LED-LINK-REF',
        'processed_at' => now(),
    ]);

    expect($attempt->getKey())->not->toBe($order->getKey())
        ->and(route('purchases.invoice', ['order' => $attempt->getKey()], absolute: false))
        ->not->toBe('/purchases/'.$order->getKey().'/invoice');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.payments')
        ->assertSeeHtml('/purchases/'.$order->getKey().'/invoice')
        ->assertDontSeeHtml('/purchases/'.$unlisted->getKey().'/invoice');
});
