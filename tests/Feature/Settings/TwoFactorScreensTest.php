<?php

use App\Models\User;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Features;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * The two screens the security page delegates to: the modal that turns
 * two-factor on, and the recovery-code list that lets an account back in when
 * the device is gone. Neither is reachable on its own — the security page
 * embeds them — so both are driven here as components, the way a Livewire
 * mutation reaches them anyway.
 *
 * The point worth pinning is that enabling two-factor is a *staged* state: a
 * secret exists before it is confirmed, and the confirmation is what the locked
 * properties protect. A client that could flip `requiresConfirmation` or push
 * its own `recoveryCodes` would skip the step that proves the device works.
 */

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

/**
 * The setup modal after it has generated a secret for the given account.
 */
function startedSetupModal(User $user): Testable
{
    return Livewire::actingAs($user)
        ->test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->dispatch('start-two-factor-setup');
}

test('starting setup stores a secret and shows the key the authenticator app needs', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user);

    $secret = $component->get('manualSetupKey');

    $fresh = $user->fresh();

    expect($secret)->toMatch('/^[A-Z2-7]+$/')
        // The key the authenticator app is asked to type is the same secret the
        // server holds, decrypted — not a second value that merely looks right.
        ->toBe(decrypt($fresh->two_factor_secret))
        ->and($component->get('qrCodeSvg'))->toContain('<svg')
        // Enabled is not confirmed: until a code is proved, the account must
        // still be able to sign in with a password alone.
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
});

test('a locked setup flag cannot be downgraded from the client', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user);

    // The whole confirmation step is switched on by this one flag, so a client
    // that could clear it would enable two-factor without ever proving a code.
    expect(fn () => $component->set('requiresConfirmation', false))
        ->toThrow(CannotUpdateLockedPropertyException::class, 'requiresConfirmation');

    expect($component->get('requiresConfirmation'))->toBeTrue();
});

test('a locked setup key cannot be replaced with a value the server never issued', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user);

    $issued = $component->get('manualSetupKey');

    expect(fn () => $component->set('manualSetupKey', 'a key the server never issued'))
        ->toThrow(CannotUpdateLockedPropertyException::class, 'manualSetupKey');

    expect($component->get('manualSetupKey'))->toBe($issued);
});

test('when confirmation is required the modal asks for a code instead of reporting success', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user)->call('showVerificationIfNecessary');

    expect($component->get('showVerificationStep'))->toBeTrue()
        ->and($component->get('setupComplete'))->toBeFalse()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $component->assertNotDispatched('two-factor-enabled');
});

test('a valid six digit code confirms two factor and leaves recovery codes behind', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user);

    $code = app(Google2FA::class)->getCurrentOtp($component->get('manualSetupKey'));

    $component->set('code', $code)
        ->call('confirmTwoFactor')
        ->assertDispatched('two-factor-enabled');

    $fresh = $user->fresh();

    expect($fresh->two_factor_confirmed_at)->not->toBeNull();

    $stored = json_decode(decrypt($fresh->two_factor_recovery_codes), true);

    expect($stored)->toHaveCount(8)
        ->and($stored[0])->not->toBe('recovery-code-1');
});

test('a wrong six digit code leaves two factor unconfirmed', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user);

    // Any six digits will do as long as they are not the current window's code.
    $wrong = app(Google2FA::class)->getCurrentOtp($component->get('manualSetupKey')) === '000000'
        ? '111111'
        : '000000';

    $component->set('code', $wrong)
        ->call('confirmTwoFactor')
        ->assertHasErrors(['code']);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $component->assertNotDispatched('two-factor-enabled');
});

test('a code that is not six digits is refused before the provider is consulted', function () {
    $user = User::factory()->create();

    startedSetupModal($user)
        ->set('code', '1234')
        ->call('confirmTwoFactor')
        ->assertHasErrors(['code' => 'size']);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('closing the modal stops the component from carrying the setup key', function () {
    $user = User::factory()->create();

    $component = startedSetupModal($user);

    $issued = $component->get('manualSetupKey');

    $component->assertSee($issued, false);

    $component->call('closeModal')
        ->assertSet('manualSetupKey', '')
        ->assertSet('qrCodeSvg', '')
        ->assertDontSee($issued, false);

    // Closing the dialog is not disabling two-factor: the secret the account
    // has already enrolled stays on the row.
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('the recovery codes screen shows only the codes belonging to the signed in account', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $stranger = User::factory()->withTwoFactor()->create();

    expect(Livewire::actingAs($owner)->test('pages::settings.two-factor.recovery-codes')->get('recoveryCodes'))
        ->toBe(['recovery-code-1']);

    // Both accounts were seeded with the same fixture code, so this only proves
    // isolation once one account's set is regenerated.
    app(GenerateNewRecoveryCodes::class)($stranger);

    $component = Livewire::actingAs($stranger)->test('pages::settings.two-factor.recovery-codes');

    $codes = $component->get('recoveryCodes');

    expect($codes)->toHaveCount(8)
        ->and($codes)->not->toContain('recovery-code-1')
        ->and(json_decode(decrypt($owner->fresh()->two_factor_recovery_codes), true))
        ->toBe(['recovery-code-1']);
});

test('regenerating replaces the stored set and the screen shows the new codes', function () {
    $user = User::factory()->withTwoFactor()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::settings.two-factor.recovery-codes')
        ->call('regenerateRecoveryCodes');

    $stored = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);

    expect($stored)->toHaveCount(8)
        ->and($stored)->not->toContain('recovery-code-1')
        ->and($component->get('recoveryCodes'))->toBe($stored);
});

test('an account without two factor has no codes to show', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::settings.two-factor.recovery-codes');

    expect($component->get('recoveryCodes'))->toBe([])
        ->and($user->two_factor_recovery_codes)->toBeNull();

    $component->assertHasNoErrors();
});

test('the recovery codes are locked so a client cannot push its own set', function () {
    $user = User::factory()->withTwoFactor()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::settings.two-factor.recovery-codes');

    expect(fn () => $component->set('recoveryCodes', ['a code that was never issued']))
        ->toThrow(CannotUpdateLockedPropertyException::class, 'recoveryCodes');

    expect($component->get('recoveryCodes'))->toBe(['recovery-code-1']);
});
