<?php

use App\Enums\PlanStatus;
use App\Models\IdeaPlan;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The status tabs on the plans screen
|--------------------------------------------------------------------------
|
| Seven tabs, one per stage plus "open" and "everything", and the interesting
| failure is the one a screenshot never shows: a tab whose heading lies about the
| rows underneath it. The plans screen builds its empty-state sentence out of the
| tab name, so a hand-typed `?tab=` from an old bookmark used to crash the page
| rather than describe it, which is pinned here along with the partitions.
|
*/

test('each stage tab lists exactly the plans sitting in that stage', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    $titles = [
        'ready' => 'An agreed plan waiting to start',
        'in_progress' => 'A plan being worked on',
        'on_hold' => 'A plan set aside for now',
        'completed' => 'A plan that finished',
        'cancelled' => 'A plan that was called off',
    ];

    foreach ($titles as $tab => $title) {
        IdeaPlan::factory()->for($author)->create([
            'user_id' => $author->id,
            'title' => $title,
            'status' => PlanStatus::from($tab),
        ]);
    }

    foreach ($titles as $tab => $visible) {
        $component = Livewire::actingAs($author)->test('pages::ideas.plans')->set('tab', $tab);

        $component->assertSee($visible);

        foreach ($titles as $title) {
            if ($title !== $visible) {
                $component->assertDontSee($title);
            }
        }
    }
});

test('the open tab keeps live work and drops the finished and called-off plans', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    IdeaPlan::factory()->for($author)->ready()->create(['user_id' => $author->id, 'title' => 'Agreed but not started']);
    IdeaPlan::factory()->for($author)->completed()->create(['user_id' => $author->id, 'title' => 'Called it done']);
    IdeaPlan::factory()->for($author)->cancelled()->create(['user_id' => $author->id, 'title' => 'Called it off']);

    Livewire::actingAs($author)->test('pages::ideas.plans')
        ->assertSee('Agreed but not started')
        ->assertDontSee('Called it done')
        ->assertDontSee('Called it off')
        ->set('tab', 'all')
        ->assertSee('Called it done')
        ->assertSee('Called it off');
});

test('a tab nobody defined lists every plan rather than refusing to render', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    IdeaPlan::factory()->for($author)->completed()->create(['user_id' => $author->id, 'title' => 'A finished plan']);

    // The empty-state sentence is built from the tab's own label, so an unknown value
    // must fall through to the general sentence instead of looking a heading up that
    // does not exist.
    Livewire::actingAs($author)
        ->test('pages::ideas.plans')
        ->set('tab', 'last_tuesday')
        ->assertSee('A finished plan')
        ->assertOk();

    $newcomer = User::factory()->create(['handle' => 'empty']);

    Livewire::actingAs($newcomer)
        ->test('pages::ideas.plans')
        ->set('tab', 'last_tuesday')
        ->assertSee('No plans yet')
        ->assertOk();
});

test('no stage tab leaks another account plans', function () {
    $author = User::factory()->create(['handle' => 'shelf']);
    $stranger = User::factory()->create(['handle' => 'other']);

    IdeaPlan::factory()->for($author)->completed()->create(['user_id' => $author->id, 'title' => 'My own finished plan']);
    IdeaPlan::factory()->for($stranger)->completed()->create(['user_id' => $stranger->id, 'title' => 'A finished stranger plan']);
    IdeaPlan::factory()->for($stranger)->create(['user_id' => $stranger->id, 'title' => 'An open stranger plan']);

    foreach (['open', 'ready', 'in_progress', 'on_hold', 'completed', 'cancelled', 'all'] as $tab) {
        Livewire::actingAs($author)->test('pages::ideas.plans')
            ->set('tab', $tab)
            ->assertDontSee('A finished stranger plan')
            ->assertDontSee('An open stranger plan');
    }

    Livewire::actingAs($author)->test('pages::ideas.plans')
        ->set('tab', 'completed')
        ->assertSee('My own finished plan');
});

test('search narrows the tab that is already open', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    IdeaPlan::factory()->for($author)->inProgress()->create(['user_id' => $author->id, 'title' => 'Container queries plan']);
    IdeaPlan::factory()->for($author)->inProgress()->create(['user_id' => $author->id, 'title' => 'A quieter settings plan']);
    IdeaPlan::factory()->for($author)->completed()->create(['user_id' => $author->id, 'title' => 'Container queries finished']);

    Livewire::actingAs($author)->test('pages::ideas.plans')
        ->set('search', 'container')
        ->assertSee('Container queries plan')
        // The tab still narrows while the search is active: a finished plan is not
        // live work, however well it matches the words.
        ->assertDontSee('Container queries finished')
        ->assertDontSee('A quieter settings plan')
        ->set('tab', 'completed')
        ->assertDontSee('Container queries plan')
        ->assertSee('Container queries finished');
});
