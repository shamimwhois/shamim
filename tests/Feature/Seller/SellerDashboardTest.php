<?php

use App\Enums\AccountRole;
use App\Models\User;
use Livewire\Livewire;

/*
 * The seller dashboard prints entitlements it never stores: every row in the
 * panel is derived from the plan attached to the signed-in account's role. So
 * the test that matters is not "does the copy appear" but "does the panel move
 * when the account moves" — a hardcoded panel would pass a render test and
 * fail this one.
 */

test('the entitlement panel reads the limits attached to the account\'s plan', function () {
    $seller = User::factory()->seller()->create();

    $rows = Livewire::actingAs($seller)->test('pages::seller.dashboard')->instance()->entitlements();

    expect(collect($rows)->pluck('value', 'label')->all())
        ->toBe([
            'Product listings' => '25',
            'Platform commission' => '15.0%',
            'Publishing' => 'Without review',
        ]);
});

test('the panel follows the account when its role changes', function () {
    $account = User::factory()->seller()->create();

    $component = Livewire::actingAs($account)->test('pages::seller.dashboard');

    expect($component->instance()->entitlements()[0]['value'])->toBe('25');

    // The platform plan lists without a cap, so the same screen must say so —
    // the number is read from the plan, not remembered from the first render.
    $account->forceFill(['role' => AccountRole::Admin])->save();

    $rows = $component->instance()->entitlements();

    expect($rows[0]['value'])->toBe('Unlimited')
        ->and($rows[1]['value'])->toBe('0.0%')
        ->and($rows[2]['value'])->toBe('Without review');
});

test('an account that may not create products is refused by the component itself', function () {
    $account = User::factory()->create(['role' => AccountRole::User->value]);

    Livewire::actingAs($account)
        ->test('pages::seller.dashboard')
        ->assertForbidden();
});

test('the seller route answers for a seller and refuses an account without the permission', function () {
    $seller = User::factory()->seller()->create();

    $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();

    $plain = User::factory()->create();

    $this->actingAs($plain)->get(route('seller.dashboard'))->assertForbidden();
});
