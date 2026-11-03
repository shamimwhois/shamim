<?php

use App\Enums\PaymentMode;
use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use App\Models\User;
use Database\Seeders\PaymentProviderSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The payment console
|--------------------------------------------------------------------------
|
| This screen writes gateway credentials, so the tests care about two things
| more than any other admin page: who may reach it (only a role holding
| payment:configure), and what ends up in the database (ciphertext at rest,
| a secret never lost, and no half-keyed gateway marked enabled).
|
*/

/**
 * Sign in as an admin who may configure payments, and seed the provider rows.
 */
function paymentActor(): User
{
    $actor = User::factory()->admin()->create(['name' => 'Payment Actor']);

    test()->actingAs($actor);
    test()->seed(PaymentProviderSeeder::class);

    return $actor;
}

test('roles without the payment permission never reach the console', function () {
    test()->seed(PaymentProviderSeeder::class);

    $this->actingAs(User::factory()->create())->get('/admin/payment')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/payment')->assertForbidden();

    Livewire::test('pages::admin.payment')->assertForbidden();
})->group('security');

test('the console lists every provider with a card', function () {
    paymentActor();

    $component = Livewire::test('pages::admin.payment');

    foreach (PaymentProvider::cases() as $provider) {
        $component->assertSee($provider->label());
    }

    expect(PaymentSetting::query()->count())->toBe(5);
})->group('security');

test('credentials are stored encrypted, never in plain text', function () {
    paymentActor();
    $bkash = PaymentSetting::query()->where('provider', PaymentProvider::Bkash)->firstOrFail();

    Livewire::test('pages::admin.payment')
        ->call('edit', $bkash->id)
        ->set('credentials.username', 'bkash-user')
        ->set('credentials.app_key', 'key-123')
        ->set('credentials.app_secret', 'super-secret-value')
        ->call('save')
        ->assertHasNoErrors();

    $raw = DB::table('payment_settings')->where('id', $bkash->id)->value('credentials');

    expect($raw)->not->toContain('super-secret-value')
        ->and($raw)->not->toContain('key-123')
        ->and($bkash->refresh()->credentials)->toBe([
            'username' => 'bkash-user',
            'app_key' => 'key-123',
            'app_secret' => 'super-secret-value',
        ]);
})->group('security');

test('a blank secret field keeps the stored secret and updates public fields', function () {
    paymentActor();
    $bkash = PaymentSetting::query()->where('provider', PaymentProvider::Bkash)->firstOrFail();
    $bkash->update(['credentials' => ['username' => 'old', 'app_key' => 'old-key', 'app_secret' => 'old-secret']]);

    Livewire::test('pages::admin.payment')
        ->call('edit', $bkash->id)
        // edit() must not have leaked the stored secret back into the form.
        ->assertSet('credentials.app_secret', '')
        ->set('credentials.username', 'new')
        ->set('credentials.app_key', 'new-key')
        ->call('save')
        ->assertHasNoErrors();

    expect($bkash->refresh()->credentials)->toBe([
        'username' => 'new',
        'app_key' => 'new-key',
        'app_secret' => 'old-secret',
    ]);
})->group('security');

test('a gateway cannot be enabled while credentials are missing', function () {
    paymentActor();
    $paypal = PaymentSetting::query()->where('provider', PaymentProvider::Paypal)->firstOrFail();

    Livewire::test('pages::admin.payment')
        ->call('edit', $paypal->id)
        ->set('credentials.client_id', 'cid')
        ->set('enabled', true)
        ->call('save')
        ->assertHasErrors(['credentials']);

    expect($paypal->refresh()->enabled)->toBeFalse();
})->group('security');

test('a fully keyed gateway can be enabled and its settings round-trip', function () {
    paymentActor();
    $paypal = PaymentSetting::query()->where('provider', PaymentProvider::Paypal)->firstOrFail();

    Livewire::test('pages::admin.payment')
        ->call('edit', $paypal->id)
        ->set('credentials.client_id', 'cid')
        ->set('credentials.client_secret', 'csecret')
        ->set('enabled', true)
        ->set('mode', PaymentMode::Live->value)
        ->set('currency', 'EUR')
        ->set('minAmount', '10')
        ->set('maxAmount', '5000.50')
        ->set('instructions', 'Pay via PayPal checkout.')
        ->call('save')
        ->assertHasNoErrors();

    $paypal->refresh();

    expect($paypal->enabled)->toBeTrue()
        ->and($paypal->mode)->toBe(PaymentMode::Live)
        ->and($paypal->currency)->toBe('EUR')
        ->and((float) $paypal->min_amount)->toBe(10.0)
        ->and((float) $paypal->max_amount)->toBe(5000.5)
        ->and($paypal->instructions)->toBe('Pay via PayPal checkout.');
})->group('security');

test('a maximum below the minimum is refused', function () {
    paymentActor();
    $card = PaymentSetting::query()->where('provider', PaymentProvider::Card)->firstOrFail();

    Livewire::test('pages::admin.payment')
        ->call('edit', $card->id)
        ->set('minAmount', '100')
        ->set('maxAmount', '50')
        ->call('save')
        ->assertHasErrors(['maxAmount']);

    expect($card->refresh()->max_amount)->toBeNull();
})->group('security');

test('an unsupported currency is refused', function () {
    paymentActor();
    $nagad = PaymentSetting::query()->where('provider', PaymentProvider::Nagad)->firstOrFail();

    Livewire::test('pages::admin.payment')
        ->call('edit', $nagad->id)
        ->set('currency', 'BDU')
        ->call('save')
        ->assertHasErrors(['currency']);
})->group('security');
