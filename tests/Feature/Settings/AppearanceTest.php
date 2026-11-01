<?php

use App\Models\User;
use Livewire\Livewire;

/*
 * The appearance screen holds no server state at all: the choice is written to
 * the browser by Flux and read back on the next load, which is why this page is
 * an empty component wrapped around three radios. What is worth pinning is the
 * contract it does own — that the three values the theme script understands are
 * the three values the screen offers, and that the screen is not public.
 *
 * Whether a choice survives a reload is a browser question, and it is answered
 * by the Dusk spec in tests/Browser, not here.
 */

test('the appearance screen offers exactly the three modes the theme understands', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.appearance')
        // The labels are copy; the values are the contract. A renamed option
        // would leave the theme script reading a value it has no branch for.
        ->assertSeeHtml('value="light"')
        ->assertSeeHtml('value="dark"')
        ->assertSeeHtml('value="system"');
});

test('a guest is sent to the sign-in prompt rather than shown the appearance form', function () {
    // This site signs in through a modal on the landing page, so the redirect
    // target is the home page carrying the flag that opens it — not /login.
    $this->get(route('appearance.edit'))->assertRedirect('/?signin=required');
});

test('an unverified account is refused the appearance screen', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('appearance.edit'))->assertRedirect(route('verification.notice'));
});

test('a verified account is shown the screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('Update the appearance settings for your account');
});
