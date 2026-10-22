<?php

/**
 * The theme a reader picked is the theme they get next time they arrive.
 *
 * `Settings\AppearanceTest` pins the three options, the guard, and the copy. What
 * it cannot pin is the half of the feature that has no server side at all:
 * `$flux.appearance` is an Alpine store on the client, and the thing it is for —
 * `document.documentElement` carrying or not carrying `dark`, and still carrying
 * it after the browser throws this document away and asks for another one — is
 * done entirely in the tab. A PHP test can assert the markup writes
 * `value="dark"`; only a browser can assert that clicking it changes what the
 * page looks like and that the change outlives the page.
 *
 * This is also the only place the persistence *store* is tested. The radio group
 * is wired with `x-model`, so a broken binding would still render three options
 * and still let a reader click them; the page would look right for one load and
 * silently forget every choice.
 *
 * The third option, "System", is not asserted across a reload. It paints from
 * `prefers-color-scheme` and Flux retires the stored key for it, so a
 * reload-after-System assertion measures what this Chrome instance reports about
 * its operating system rather than what the application remembered. A spec that
 * can pass or fail depending on the machine running it is not a spec, and the
 * click itself — the page stops overriding — is covered by the two legs above.
 */

use App\Models\User;
use Laravel\Dusk\Browser;

it('keeps the theme across a real reload, in both directions', function () {
    /** @var User $account */
    $account = User::query()->where('email', 'test@example.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($account) {
        $browser
            ->loginAs($account)
            ->visit('/settings/appearance')
            ->waitFor('[data-test="appearance-dark"]')
            ->click('[data-test="appearance-dark"]')
            // The class is what the stylesheet keys off (`:root.dark`), so this is
            // the assertion that the click reached the thing that paints.
            ->waitUntil('document.documentElement.classList.contains("dark")')
            ->refresh()
            ->waitFor('[data-test="appearance-dark"]')
            ->assertScript('document.documentElement.classList.contains("dark")', true)
            // Back to light, and reload again: a store that only ever remembers the
            // first choice would pass the assertions above.
            ->click('[data-test="appearance-light"]')
            ->waitUntil('!document.documentElement.classList.contains("dark")')
            ->refresh()
            ->waitFor('[data-test="appearance-light"]')
            ->assertScript('document.documentElement.classList.contains("dark")', false);
    });
});
