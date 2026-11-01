<?php

use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The six tabs on the Ideas workspace home
|--------------------------------------------------------------------------
|
| A tab is a promise about what is missing. "Open" promises the archived rows are
| not here and "Everything" promises they are, so each tab is pinned against the
| same four rows and the assertion is as much about the titles absent as the one
| present.
|
| Two of these are hand-typed territory. `?tab=` arrives from the address bar, so an
| unrecognised value must land somewhere honest, and a tab must never widen the
| ownership rule — an account that cannot see a stranger idea on "Open" must not see
| it on "Archived" either, which is why the privacy test walks every tab instead of
| one.
|
*/

function dashboardTabs_fourStages(User $author): void
{
    Idea::factory()->create(['user_id' => $author->id, 'title' => 'Caught from the queue', 'status' => IdeaStatus::Inbox]);
    Idea::factory()->underReview()->create(['user_id' => $author->id, 'title' => 'Being read right now']);
    Idea::factory()->approved()->create(['user_id' => $author->id, 'title' => 'Ready to go out']);
    Idea::factory()->archived()->create(['user_id' => $author->id, 'title' => 'Decided against']);
}

test('the default view lists the live work and leaves the closed decisions out', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    dashboardTabs_fourStages($author);

    Livewire::actingAs($author)->test('pages::ideas.dashboard')
        ->assertSee('Caught from the queue')
        ->assertSee('Being read right now')
        ->assertSee('Ready to go out')
        ->assertDontSee('Decided against');
});

test('each stage tab lists exactly the ideas sitting in that stage', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    dashboardTabs_fourStages($author);

    $titles = [
        'Caught from the queue',
        'Being read right now',
        'Ready to go out',
        'Decided against',
    ];

    foreach ([
        'inbox' => 'Caught from the queue',
        'review' => 'Being read right now',
        'approved' => 'Ready to go out',
        'archived' => 'Decided against',
    ] as $tab => $visible) {
        $component = Livewire::actingAs($author)->test('pages::ideas.dashboard')->set('tab', $tab);

        $component->assertSee($visible);

        foreach ($titles as $title) {
            if ($title !== $visible) {
                $component->assertDontSee($title);
            }
        }
    }
});

test('the everything tab is the only one that admits the closed decisions', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    dashboardTabs_fourStages($author);

    Livewire::actingAs($author)->test('pages::ideas.dashboard')
        ->set('tab', 'all')
        ->assertSee('Caught from the queue')
        ->assertSee('Being read right now')
        ->assertSee('Ready to go out')
        ->assertSee('Decided against');
});

test('a tab nobody defined shows the whole pile rather than an empty room', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    dashboardTabs_fourStages($author);

    // An old link naming a tab that no longer exists should show the reader their
    // ideas under a heading that admits it, rather than hide rows behind a stage
    // nobody chose.
    Livewire::actingAs($author)->test('pages::ideas.dashboard')
        ->set('tab', 'last_tuesday')
        ->assertSee('Caught from the queue')
        ->assertSee('Decided against');
});

test('no tab leaks another account ideas, not even the ones that include everything', function () {
    $author = User::factory()->create(['handle' => 'shelf']);
    $stranger = User::factory()->create(['handle' => 'other']);

    dashboardTabs_fourStages($author);
    Idea::factory()->create(['user_id' => $stranger->id, 'title' => 'An open stranger idea']);
    Idea::factory()->archived()->create(['user_id' => $stranger->id, 'title' => 'A closed stranger idea']);

    // The own rows are proven visible once, outside the loop, so the privacy
    // assertions below cannot pass on a page that is empty for everybody.
    Livewire::actingAs($author)->test('pages::ideas.dashboard')
        ->set('tab', 'all')
        ->assertSee('Caught from the queue')
        ->assertDontSee('An open stranger idea')
        ->assertDontSee('A closed stranger idea');

    foreach (['open', 'inbox', 'review', 'approved', 'archived', 'all'] as $tab) {
        Livewire::actingAs($author)->test('pages::ideas.dashboard')
            ->set('tab', $tab)
            ->assertDontSee('An open stranger idea')
            ->assertDontSee('A closed stranger idea');
    }
});

test('search narrows the tab that is already open', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    Idea::factory()->create(['user_id' => $author->id, 'title' => 'Container queries at work', 'status' => IdeaStatus::Inbox]);
    Idea::factory()->create(['user_id' => $author->id, 'title' => 'A quieter settings page', 'status' => IdeaStatus::Inbox]);
    Idea::factory()->approved()->create(['user_id' => $author->id, 'title' => 'Container queries ready to publish']);

    Livewire::actingAs($author)->test('pages::ideas.dashboard')
        ->set('search', 'container')
        ->assertSee('Container queries at work')
        ->assertSee('Container queries ready to publish')
        ->assertDontSee('A quieter settings page')
        ->set('tab', 'approved')
        ->assertDontSee('Container queries at work')
        ->assertSee('Container queries ready to publish');
});

test('a search that matches nothing empties the list instead of falling back to everything', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    Idea::factory()->create(['user_id' => $author->id, 'title' => 'Something somebody wrote down']);

    Livewire::actingAs($author)->test('pages::ideas.dashboard')
        ->set('search', 'zzzznothing')
        ->assertDontSee('Something somebody wrote down');
});

test('turning the page does not survive a tab or search change', function () {
    $author = User::factory()->create(['handle' => 'shelf']);
    $perPage = (int) config('ideas.per_page.workspace');

    Idea::factory()->count($perPage + 1)->create(['user_id' => $author->id]);

    Livewire::actingAs($author)
        ->test('pages::ideas.dashboard')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('tab', 'archived')
        ->assertSet('paginators.page', 1)
        ->call('setPage', 2)
        ->set('search', 'and')
        ->assertSet('paginators.page', 1);
});

test('the metric tiles count the rows the tabs will list', function () {
    $author = User::factory()->create(['handle' => 'shelf']);

    dashboardTabs_fourStages($author);
    Idea::factory()->pinned()->approved()->create(['user_id' => $author->id, 'title' => 'Pinned and therefore counted']);

    $metrics = Livewire::actingAs($author)->test('pages::ideas.dashboard')->instance()->metrics;

    expect($metrics)->toBe(['total' => 5, 'inbox' => 1, 'approved' => 2, 'pinned' => 1]);
});
