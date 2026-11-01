<?php

use App\Models\LabWorkspace;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * `show($pane)` is the whole of the narrow-screen tab bar: one property, five
 * legal values, and a fallback. Saving, discarding and the cross-account
 * workspace lookup are covered by WorkspaceTest; what would silently rot here
 * is the list itself, because the tabs are rendered from `views()`/`panes()`
 * while the switcher validates against `paneKeys()`. Two sources, one rule.
 */

function sandbox(User $account): Testable
{
    return Livewire::actingAs($account)->test('pages::dev-lab.sandbox');
}

test('every pane the screen can draw is a pane the switcher will switch to', function () {
    $account = User::factory()->create();

    $keys = sandbox($account)->instance()->paneKeys();

    $declared = array_merge(
        array_column(sandbox($account)->instance()->panes(), 'key'),
        array_column(sandbox($account)->instance()->views(), 'key'),
    );

    // A tab whose key the switcher does not recognise would look clickable and
    // do nothing; a recognised pane with no tab is a panel nobody can reach.
    expect($declared)->toBe($keys);
});

test('the tab bar opens with the html pane active', function () {
    $account = User::factory()->create();

    sandbox($account)->assertSet('active', 'html');
});

test('each named pane becomes the active one', function () {
    $account = User::factory()->create();

    foreach (['css', 'js', 'preview', 'console'] as $pane) {
        sandbox($account)->call('show', $pane)->assertSet('active', $pane);
    }
});

test('a pane the screen does not have falls back to the first editor', function () {
    $account = User::factory()->create();

    // The client picks the value, so this is reachable from the outside. It must
    // degrade to a known pane rather than to a blank screen or an exception.
    sandbox($account)->call('show', 'settings')->assertSet('active', 'html');

    sandbox($account)->call('show', '')->assertSet('active', 'html');
});

test('switching panes is a view change and writes nothing', function () {
    $account = User::factory()->create();

    $component = sandbox($account)->set('html', '<h1>Kept</h1>');

    $before = $component->get('html');

    $component->call('show', 'console');

    expect($component->get('html'))->toBe($before)
        // Only a Save creates the row; a tab click must not.
        ->and(LabWorkspace::query()->where('user_id', $account->id)->count())->toBe(0);
});
