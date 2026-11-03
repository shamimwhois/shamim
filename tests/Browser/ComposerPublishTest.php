<?php

/**
 * The composer, driven end to end in a real browser.
 *
 * The feature suite proves the rules; only a browser proves the wiring. These
 * tests walk the whole chain the plan promises — the prompt opens a dialog,
 * typing autosaves a draft, picking a file really uploads it, the staged image
 * previews off a private disk, Publish is a real button on a real form, and the
 * new card lands in the stream with its picture loaded — and then checks the
 * database the server wrote, because a card that renders from a row that does
 * not exist would be a very convincing lie.
 *
 * Two facts about the harness shape what is possible here, and both are the
 * reason these tests are written differently from `FeedPublishingTest`:
 *
 *   - The page is served by a separate `php artisan serve` process against the
 *     same SQLite file, so everything a test needs must be *committed*. That is
 *     why `DuskTestCase::refreshTestDatabase()` seeds instead of rolling back,
 *     and why the author below is a seeded account: a model created inside this
 *     process's transaction would be invisible to the server answering the
 *     browser's requests.
 *   - `loginAs()` authenticates by visiting Dusk's own `/_dusk/login/{id}` route
 *     over HTTP, so the session belongs to the served app, not to this one.
 *
 * The JS fragments below use unquoted attribute selectors (`[data-test=foo]`)
 * even where the Dusk selectors are quoted. A selector that has to survive being
 * embedded in a PHP string, then a JSON payload, then a JS string literal is a
 * selector that should not contain quotes of its own.
 *
 * One premise is environmental rather than the application's: the attach step
 * posts a real multipart body, so the *served* PHP has to be able to open its
 * upload temp file. Where `upload_tmp_dir` is unset and the system temp is not
 * writable by the `artisan serve` process, PHP discards the file before Laravel
 * sees it, Livewire's upload endpoint answers 422 against an empty `$_FILES`,
 * and the composer shows "The uploads failed to upload". That is a machine with
 * no upload directory, not a broken composer — set `upload_tmp_dir` in the php.ini
 * the server runs under (or start it with `php -d upload_tmp_dir=… artisan serve`)
 * before reading a failure here as a defect.
 */

use App\Enums\FeedPostStatus;
use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/** The composer dialog, addressed by the name the modal component gives itself. */
const COMPOSER_DIALOG = 'dialog[data-modal=feed-composer-modal]';

it('carries an author from the composer prompt to a published card in the feed', function () {
    /** @var User $author */
    $author = User::query()->where('email', 'shamimstack@gmail.com')->firstOrFail();

    $token = Str::lower(Str::random(12));
    $body = "Dusk composer {$token}: what I learned shipping a Livewire feed";

    $this->browse(function (Browser $browser) use ($author, $body, $token) {
        $browser
            ->loginAs($author)
            ->visit('/feed')
            ->waitFor('[data-test="composer-open"]')
            ->click('[data-test="composer-open"]')
            ->waitFor(COMPOSER_DIALOG)
            ->assertScript('document.querySelector("'.COMPOSER_DIALOG.'").open', true);

        $browser->within(COMPOSER_DIALOG, function (Browser $modal) use ($body) {
            $modal
                ->waitFor('[data-test="composer-body"]')
                ->type('[data-test="composer-body"]', $body)
                // Autosave is a four-second debounce followed by a round trip,
                // and the badge is the only thing it visibly produces. Waiting
                // for the badge *is* the assertion — a sleep would prove nothing.
                ->waitFor('[data-test="composer-draft-badge"]', 30)
                ->assertSee('Draft saved');

            // The picker input is `display:none` on purpose: it exists for the
            // Photo button to click, not to be looked at. Chrome will not accept
            // a path typed into an element that is not rendered, so reveal it —
            // the file is still chosen through the same input a member uses.
            $modal->script('document.querySelector("'.COMPOSER_DIALOG.' [data-test=composer-file-input]").classList.remove("hidden");');

            $modal
                ->attach('[data-test="composer-file-input"]', __DIR__.'/fixtures/screenshot.png')
                ->waitFor('[data-test="composer-media-list"] img', 90)
                // The post now carries media, so the workflow has to route the
                // author past Preview before it will publish: the first Publish
                // click shows the preview, and the same button publishes on the
                // second one.
                ->click('[data-test="composer-publish"]')
                ->waitFor('[data-test="composer-preview"]', 30)
                ->click('[data-test="composer-publish"]');
        });

        $browser
            ->waitFor('[data-test="feed-media-item"]', 60)
            ->waitForText($token, 30)
            // Closed by itself: the component dispatched `composer-close` after
            // publishing, which is the only reason an author gets the feed back
            // rather than a composer with nothing left to write in it.
            ->assertScript('document.querySelector("'.COMPOSER_DIALOG.'").open', false)
            // Not a broken frame. This image is served by `feed.attachments.show`
            // off a disk that cannot be read by path, over a URL that is signed
            // and short-lived — the byte-level proof of the whole media design.
            ->waitUntil('return document.querySelector("[data-test=feed-media-item]").naturalWidth > 0;', 30);

        // Bring the new card into the frame before shooting it. A screenshot of
        // the masthead would prove that the page loaded, not that the post
        // landed, and the difference is the whole point of the test. `script()`
        // hands back results rather than the browser, so it is its own statement.
        $browser->script('document.querySelector("[data-test=feed-media-item]")'
            .'.scrollIntoView({block: "center"});');

        $browser
            ->pause(400)
            ->screenshot('composer-published');
    });

    /** @var FeedPost|null $post */
    $post = FeedPost::query()->where('body', $body)->first();

    expect($post)->not->toBeNull()
        ->and($post->status)->toBe(FeedPostStatus::Published)
        ->and($post->user_id)->toBe($author->id)
        ->and($post->title)->not->toBeEmpty()
        ->and($post->attachments()->count())->toBe(1);

    /** @var FeedAttachment $attachment */
    $attachment = $post->attachments()->firstOrFail();

    // Staged, then claimed: a published attachment has a post and no longer a
    // temp key, or the nightly prune would eat a live image.
    expect($attachment->disk)->toBe('feed_media')
        ->and($attachment->feed_post_id)->toBe($post->id)
        ->and($attachment->temp_key)->toBeNull()
        ->and($attachment->width)->toBeGreaterThan(0)
        ->and(Storage::disk('feed_media')->exists($attachment->path))->toBeTrue();
});

it('docks the composer near full screen with a sticky publish bar on a phone', function () {
    /** @var User $author */
    $author = User::query()->where('email', 'shamimstack@gmail.com')->firstOrFail();

    // Long enough that the sheet cannot fit its own editor, which is the only
    // condition under which "sticky" means anything at all.
    $body = str_repeat("A line tall enough that the phone sheet has to scroll.\n", 24);

    $this->browse(function (Browser $browser) use ($author, $body) {
        $browser
            ->loginAs($author)
            ->resize(390, 844)
            ->visit('/feed')
            ->waitFor('[data-test="composer-open"]')
            ->click('[data-test="composer-open"]')
            ->waitFor(COMPOSER_DIALOG)
            ->within(COMPOSER_DIALOG, function (Browser $modal) use ($body) {
                $modal->waitFor('[data-test="composer-body"]')
                    ->type('[data-test="composer-body"]', $body);
            });

        // Geometry, measured rather than read off the class list: the plan asks
        // for a near-full-screen sheet on a phone, and the only honest evidence
        // is the box the browser actually painted.
        [$width, $height, $viewportWidth, $viewportHeight] = $browser->script(
            'var r = document.querySelector("'.COMPOSER_DIALOG.'").getBoundingClientRect();'
            .'return [r.width, r.height, window.innerWidth, window.innerHeight];'
        )[0];

        expect($width)->toBeGreaterThan($viewportWidth * 0.9)
            ->and($height)->toBeGreaterThan($viewportHeight * 0.9);

        // The editor collapses to one column below the `sm` breakpoint, so a
        // phone gets a single reading line instead of five squeezed ones.
        $columns = $browser->script(
            'var grid = document.querySelector("'.COMPOSER_DIALOG.' form > div[class*=grid-cols]");'
            .'return getComputedStyle(grid).gridTemplateColumns.split(" ").length;'
        )[0];

        expect($columns)->toBe(1);

        // Sticky, proven properly: the publish bar has to survive being scrolled
        // to the far end of a sheet that overflows. An author who reaches for a
        // screenshot at the bottom of the form must still be able to find Publish.
        [$overflows, $publishOnScreen, $closeOnScreen] = $browser->script(
            'var sheet = document.querySelector("'.COMPOSER_DIALOG.'");'
            .'var publish = sheet.querySelector("[data-test=composer-publish]").getBoundingClientRect();'
            .'var close = sheet.querySelector("[data-test=composer-close]").getBoundingClientRect();'
            .'return [sheet.scrollHeight > sheet.clientHeight,'
            .'publish.bottom <= window.innerHeight + 1 && publish.top >= 0,'
            .'close.top >= sheet.getBoundingClientRect().top - 1];'
        )[0];

        expect($overflows)->toBeTrue()
            ->and($publishOnScreen)->toBeTrue()
            ->and($closeOnScreen)->toBeTrue();

        $browser->script('document.querySelector("'.COMPOSER_DIALOG.'").scrollTop = 99999;');

        expect($browser->script(
            'var p = document.querySelector("'.COMPOSER_DIALOG.' [data-test=composer-publish]").getBoundingClientRect();'
            .'return p.bottom <= window.innerHeight + 1 && p.top >= 0;'
        )[0])->toBeTrue();

        $browser->screenshot('composer-mobile-390x844');
    });
});
