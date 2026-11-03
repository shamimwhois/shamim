<?php

use App\Actions\Marketplace\SaveBillingProfile;
use App\Models\BillingProfile;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Support\Str;

test('the settings panel carries a billing tab with the profile form', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.billing')
        ->assertOk()
        ->assertSee('data-test="billing-legal_name"', false)
        ->assertSee('data-test="billing-country"', false)
        ->assertSee('data-test="billing-save"', false);

    // The form is prefilled from the account, not blank.
    expect($response->instance()->billing['legal_name'])->toBe($user->name)
        ->and($response->instance()->billing['email'])->toBe($user->email)
        ->and($response->instance()->hasBillingProfile())->toBeFalse();
});

test('saving builds the billing profile through the shared action', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.billing')
        ->set('billing.legal_name', 'Amara Diallo')
        ->set('billing.email', 'amara@example.test')
        ->set('billing.address_line1', '12 Plateau Road')
        ->set('billing.city', 'Lagos')
        ->set('billing.country', 'NG')
        ->call('saveBilling')
        ->assertHasNoErrors();

    $profile = BillingProfile::query()->where('user_id', $user->id)->firstOrFail();

    expect($profile->legal_name)->toBe('Amara Diallo')
        ->and($profile->city)->toBe('Lagos')
        ->and($profile->country)->toBe('NG');
});

test('an incomplete profile is refused with the field named', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.billing')
        ->set('billing.legal_name', '')
        ->call('saveBilling')
        ->assertHasErrors(['billing.legal_name']);

    expect(BillingProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('the page lists the account orders and payments', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $order = MarketplaceOrder::factory()->forBuyer($user)->paid()->create();

    Livewire::test('pages::settings.billing')
        ->assertSee($order->product_name, false)
        ->assertSee($order->order_number, false)
        ->assertSee('billing-order', false);
});

test('an account with no purchases gets the honest empty state', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.billing')
        ->assertSee('Nothing bought yet.', false)
        ->assertSee('No payments yet.', false);
});

test('the settings tab strip links every panel including billing', function () {
    $user = User::factory()->create(['handle' => Str::random(8)]);

    $this->actingAs($user);

    $this->get(route('settings.billing'))
        ->assertOk()
        ->assertSee('href="'.route('settings.billing').'"', false)
        ->assertSee('href="'.route('profile.edit').'"', false)
        ->assertSee('href="'.route('security.edit').'"', false);
});

/*
|--------------------------------------------------------------------------
| The action owns the contract
|--------------------------------------------------------------------------
|
| The rules live on SaveBillingProfile, not on whichever page happens to be
| rendering a form, so a write that bypasses the form is still refused. These
| tests call the action directly — the way a seeder, an API or an artisan
| command would — because that is the door a form-only rule leaves open.
|
*/

test('the shared rule set is the one both pages enforce', function () {
    $rules = SaveBillingProfile::rules();

    expect($rules)->toHaveKeys([
        'billing.legal_name',
        'billing.email',
        'billing.address_line1',
        'billing.city',
        'billing.country',
    ]);
});

test('the action refuses a country the storefront does not ship to', function () {
    $user = User::factory()->create();

    expect(fn () => app(SaveBillingProfile::class)->handle($user, [
        'legal_name' => 'Amara Diallo',
        'email' => 'amara@example.test',
        'address_line1' => '12 Plateau Road',
        'city' => 'Lagos',
        'country' => 'XX',
    ]))->toThrow(InvalidArgumentException::class);

    expect(BillingProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('the action accepts a known country and stores it upper-cased', function () {
    $user = User::factory()->create();

    $profile = app(SaveBillingProfile::class)->handle($user, [
        'legal_name' => 'Amara Diallo',
        'email' => 'amara@example.test',
        'address_line1' => '12 Plateau Road',
        'city' => 'Lagos',
        'country' => 'ng',
    ]);

    expect($profile->country)->toBe('NG');
});

test('a blank country is still allowed — it is optional', function () {
    $user = User::factory()->create();

    $profile = app(SaveBillingProfile::class)->handle($user, [
        'legal_name' => 'Amara Diallo',
        'email' => 'amara@example.test',
        'address_line1' => '12 Plateau Road',
        'city' => 'Lagos',
    ]);

    expect($profile->country)->toBeNull();
});

test('the blank form prefills only what the account already knows', function () {
    $user = User::factory()->create(['name' => 'Amara Diallo']);

    $blank = SaveBillingProfile::blankFor($user);

    expect($blank['legal_name'])->toBe('Amara Diallo')
        ->and($blank['email'])->toBe($user->email)
        ->and($blank['company'])->toBeNull()
        ->and($blank['city'])->toBeNull();
});
