<?php

use App\Actions\Feed\AttachFeedMedia;
use App\Models\User;
use Livewire\Livewire;

/**
 * The composer's attach row and its verb.
 *
 * Two classes of bug this file exists to catch, both of which have already
 * shipped once in this codebase:
 *
 * 1. A control wired to a method that does not exist, or to a *magic* action
 *    name. `wire:target="commit"` was on the submit button for months: there is
 *    no `commit()` method, so the loading state and the disabled-during-submit
 *    guard never fired in a browser, while every test passed because `call()`
 *    bypasses the wire protocol.
 * 2. A control that promises something the backend cannot do. The media disk
 *    accepts images only, so an "attach anything" button would be a dead
 *    control that opens a dialog the validator then refuses.
 */
function composerContext(): array
{
    $reader = User::factory()->create([
        'handle' => 'composer'.fake()->unique()->numerify('###'),
        'email' => fake()->unique()->safeEmail(),
    ]);

    return [$reader, Livewire::actingAs($reader)->test('pages::feed')->html()];
}

test('the send button targets the action that actually exists', function () {
    [, $html] = composerContext();

    // The verb is `push()`. `commit` is a Livewire magic action and silently
    // reduces to a no-op, so it must never appear as a wire target.
    expect(str_contains($html, 'wire:submit="push"'))->toBeTrue();

    $submitAt = strpos($html, 'data-test="commit-button"');
    expect($submitAt)->not->toBeFalse();

    $buttonHtml = substr($html, $submitAt, 1400);

    expect(str_contains($buttonHtml, 'wire:target="push"'))->toBeTrue();
    expect(str_contains($buttonHtml, 'wire:target="commit"'))->toBeFalse();

    // `push` is the real method; assert it, so a rename cannot pass this test.
    $instance = Livewire::actingAs(User::factory()->create())->test('pages::feed');
    expect(method_exists($instance->instance(), 'push'))->toBeTrue();
});

test('every static wire target on the composer names a real method', function () {
    [, $html] = composerContext();

    // Only the composer form, so the rest of the page cannot mask a gap.
    $start = strpos($html, 'class="dn-composer"');
    $end = strpos($html, '</form>', $start === false ? 0 : $start);
    $form = substr($html, $start, $end - $start);

    preg_match_all('/wire:(?:target|click|submit)="([a-zA-Z]+)/', $form, $matches);

    expect($matches[1])->not->toBeEmpty();

    $instance = Livewire::actingAs(User::factory()->create())->test('pages::feed');
    $component = $instance->instance();

    $magic = ['refresh', 'set', 'sync', 'commit'];

    foreach (array_unique($matches[1]) as $target) {
        expect(in_array($target, $magic, true))->toBeFalse(
            "the composer wires to the magic action [{$target}], which is a silent no-op"
        );
        expect(method_exists($component, $target))->toBeTrue(
            "the composer wires to [{$target}], which the component does not define"
        );
    }
});

test('the attach row offers images, a file, a paste and an overflow menu', function () {
    [, $html] = composerContext();

    foreach ([
        'composer-tools',
        'composer-images',
        'composer-files',
        'composer-paste',
        'composer-more',
        'composer-menu',
        'composer-file-input',
    ] as $hook) {
        expect(str_contains($html, 'data-test="'.$hook.'"'))->toBeTrue("missing composer hook [{$hook}]");
    }
});

test('the attach affordances all point at one real upload field', function () {
    [, $html] = composerContext();

    // Exactly one picker on the whole page, `wire:model`led to the component:
    // every button is an affordance over a single write path rather than a
    // second one. Counting document-wide is the point — a second field
    // anywhere would be a second path.
    expect(substr_count($html, 'type="file"'))->toBe(1);
    expect(substr_count($html, 'data-composer-picker'))->toBe(1);

    // The tool cluster is a real group of real buttons.
    $start = strpos($html, 'data-test="composer-tools"');
    $end = strpos($html, 'data-test="commit-button"', $start);
    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    $tools = substr($html, $start, $end - $start);
    expect(substr_count($tools, 'class="dn-icon-btn"'))->toBeGreaterThanOrEqual(4);
});

test('the picker never offers a type the media disk would refuse', function () {
    [, $html] = composerContext();

    // `AttachFeedMedia::ACCEPTED_MIMES` is images only. Any `accept` on the
    // field that names a non-image type would be a promise the validator
    // breaks, so the field's accept list must be a subset of the disk's.
    //
    // Anchor on the field's opening tag, not on a hook that sits *after* the
    // accept attribute in the markup — slicing forward from a later anchor
    // walks past the very attribute under test.
    preg_match('/<input[^>]*data-composer-picker[^>]*>/s', $html, $fieldMatch);
    expect($fieldMatch[0] ?? null)->not->toBeNull();

    $field = preg_replace('/\s+/', ' ', $fieldMatch[0]);

    expect(str_contains($field, 'wire:model="uploads"'))->toBeTrue();
    expect(str_contains($field, 'multiple'))->toBeTrue();

    preg_match('/accept="([^"]*)"/', $field, $match);
    expect($match[1] ?? null)->not->toBeNull();

    $accepted = array_values(array_filter(array_map('trim', explode(',', $match[1]))));

    $disk = (new ReflectionClass(AttachFeedMedia::class))
        ->getConstant('ACCEPTED_MIMES');

    expect($disk)->toBeArray();
    expect($disk)->not->toBeEmpty();
    expect($accepted)->not->toBeEmpty();

    foreach ($accepted as $mime) {
        expect(in_array($mime, $disk, true))->toBeTrue(
            "the picker invites [{$mime}], which the disk does not accept"
        );
    }

    // And the invitation is complete: the picker offers every type the disk
    // takes, so a supported format is never unreachable from the UI.
    foreach ($disk as $mime) {
        expect(in_array($mime, $accepted, true))->toBeTrue(
            "the disk accepts [{$mime}], but the picker never offers it"
        );
    }
});

test('the clipboard paths feed the same upload property a picked file uses', function () {
    // The Alpine component lives in the built bundle; assert the authored
    // source, which is what a future edit would actually change.
    $source = file_get_contents(resource_path('js/log-shell.js'));

    expect($source)->not->toBeFalse();

    // The paste button and the paste-into-body handler.
    expect(str_contains($source, 'async paste()'))->toBeTrue();
    expect(str_contains($source, 'onPaste(event)'))->toBeTrue();

    // One shared staging route, and it targets the array property the picker
    // writes to — not a second upload path.
    expect(str_contains($source, 'stage(files)'))->toBeTrue();
    expect(preg_match("/uploadMultiple\(\s*'uploads'/s", $source))->toBe(1);

    // `updatedUploads` is the component hook that stages what arrived; without
    // the explicit call the files would sit in the property untriaged.
    expect(str_contains($source, "call('updatedUploads')"))->toBeTrue();

    // Non-image clipboard payloads must be dropped rather than forwarded.
    expect(str_contains($source, "startsWith('image/')"))->toBeTrue();
});

test('the staging call sits in the finish slot, not the progress one', function () {
    /* Presence is not enough. `uploadMultiple(name, files, finish, error,
       progress, cancel)` takes six slots and the staging call used to sit in
       the fifth — `progress` — so `updatedUploads` fired on every progress tick
       of the transfer instead of once when it landed. A search for the method
       name passes in both shapes, which is exactly why this asserts position.

       Nothing about the wrong slot was visible: the hook nulls `uploads` on its
       first run, so every later call found nothing and the composer still
       worked. It just did dozens of round trips to get there. */
    $source = file_get_contents(resource_path('js/log-shell.js'));

    expect(preg_match(
        "/uploadMultiple\(\s*'uploads',\s*[^,]+,\s*\(\)\s*=>\s*this\.\\\$wire->call|"
        ."uploadMultiple\(\s*'uploads',\s*[^,]+,\s*\(\)\s*=>\s*this\.\\\$wire\.call/s",
        $source,
    ))->toBe(1, 'the finish callback must be the third argument, immediately after the files');

    // And a failure must be reported rather than swallowed: the fifth-slot
    // shape left the error callback as `null`, so a refused transfer said
    // nothing at all.
    expect(str_contains($source, 'reportAttachFailure'))->toBeTrue(
        'a transfer that never arrives must reach the reader'
    );

    $component = file_get_contents(resource_path('views/pages/⚡feed.blade.php'));
    expect(str_contains($component, 'public function reportAttachFailure()'))->toBeTrue();
});

test('the paste handler is bound to the body field', function () {
    [, $html] = composerContext();

    // `data-composer-input` appears twice: once inside the rail's write button
    // as a `querySelector` argument, and once on the real textarea. Anchor on
    // the `<textarea …>` element so the nav button cannot satisfy the test.
    preg_match('/<textarea[^>]*data-composer-input[^>]*>/s', $html, $match);
    expect($match[0] ?? null)->not->toBeNull();

    $textarea = preg_replace('/\s+/', ' ', $match[0]);

    expect(str_contains($textarea, 'wire:model="draft"'))->toBeTrue();
    expect(str_contains($textarea, 'x-on:paste="onPaste($event)"'))->toBeTrue();
    expect(str_contains($textarea, 'x-on:input="onInput($event)"'))->toBeTrue();
});

test('the overflow menu holds preview, poll, schedule and discard', function () {
    [, $html] = composerContext();

    $menuAt = strpos($html, 'data-test="composer-menu"');
    expect($menuAt)->not->toBeFalse();

    $menu = substr($html, $menuAt, 4200);

    // Each of these opens a panel or toggles a client state, so each must be a
    // real control rather than a label.
    expect(str_contains($menu, 'composer-poll-toggle'))->toBeTrue();
    expect(str_contains($menu, 'composer-schedule-toggle'))->toBeTrue();
    expect(str_contains($menu, 'preview-toggle'))->toBeTrue();
    expect(str_contains($menu, 'composer-discard'))->toBeTrue();

    // And the panel wires to the methods behind them.
    expect(str_contains($menu, '$wire.togglePoll()'))->toBeTrue();
    expect(str_contains($menu, '$wire.toggleScheduling()'))->toBeTrue();
    expect(str_contains($menu, 'togglePreview()'))->toBeTrue();
});

test('the preview toggle announces the state it paints', function () {
    [, $html] = composerContext();

    $at = strpos($html, 'data-test="preview-toggle"');
    expect($at)->not->toBeFalse();

    // The button's `is-on` class is painted from `previewing`; the accessible
    // name is not. Three siblings toggle a panel, and the other two bind
    // `aria-expanded` — the preview toggle used to hardcode `aria-pressed`,
    // so a screen reader heard a permanently-unpressed control while the eye
    // watched the preview appear.
    $open = strrpos(substr($html, 0, $at), '<button');
    $toggle = substr($html, $open, $at - $open + 200);

    expect(str_contains($toggle, "x-bind:aria-expanded=\"previewing ? 'true' : 'false'\""))
        ->toBeTrue('the preview toggle must bind aria-expanded to `previewing`');
    expect(str_contains($toggle, 'aria-pressed'))->toBeFalse(
        'a hardcoded aria-pressed is a control that never announces its state'
    );

    // And it names the panel it opens, which must exist and be reachable.
    expect(str_contains($toggle, 'aria-controls="composer-preview"'))->toBeTrue();
    expect(str_contains($html, 'id="composer-preview"'))->toBeTrue();
});

test('the send corner holds the counter and the verb, in that order', function () {
    [, $html] = composerContext();

    $sendAt = strpos($html, 'class="dn-composer-send"');
    expect($sendAt)->not->toBeFalse();

    $send = substr($html, $sendAt, 1600);

    $counterAt = strpos($send, 'class="dn-counter"');
    $buttonAt = strpos($send, 'data-test="commit-button"');

    expect($counterAt)->not->toBeFalse();
    expect($buttonAt)->not->toBeFalse();
    expect($counterAt)->toBeLessThan($buttonAt);

    // The composer owns exactly one verb. The page has a second submit control
    // — the account menu's Log out — so the assertion is scoped to the form
    // rather than the document.
    $formAt = strpos($html, 'class="dn-composer"');
    $formEnd = strpos($html, '</form>', $formAt === false ? 0 : $formAt);
    $form = substr($html, $formAt, $formEnd - $formAt);

    expect(substr_count($form, 'type="submit"'))->toBe(1);
});
