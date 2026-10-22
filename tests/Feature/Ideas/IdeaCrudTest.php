<?php

use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Ideas workspace, end to end
|--------------------------------------------------------------------------
|
| What is pinned here is the module's behaviour under an author's hands: a
| capture that reaches the database with the right owner and stage, a slug that
| cannot collide with another row or shadow a screen, and the two boundaries that
| stop one account reaching another's work.
|
| The second boundary is the one worth reading twice. Livewire mutations are POSTs
| to `/livewire/update`, so the route group's `permission:ideas:use` never sees
| them; the component's own `boot()` is what refuses. A test that only exercised
| `GET /ideas/dashboard` would pass with the guard deleted.
|
*/

test('an account holding the ideas permission reaches the workspace', function () {
    $this->actingAs(User::factory()->create())
        ->get('/ideas/dashboard')
        ->assertOk()
        ->assertSee('data-sui', false);
});

test('a guest is bounced to the sign-in dialog', function () {
    $this->get('/ideas/dashboard')->assertRedirect(route('home', ['signin' => 'required']));
});

test('a guest cannot mutate the module through the livewire endpoint', function () {
    // The route middleware never runs on an update POST, so this asserts the
    // in-component guard rather than the route group.
    Livewire::test('pages::ideas.dashboard')->assertForbidden();
});

test('capture from the dashboard stores an inbox idea owned by the author', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.dashboard')
        ->set('captureTitle', 'A reading list that ranks by time available')
        ->set('captureSummary', 'People say they want fewer books, not more.')
        ->call('capture')
        ->assertHasNoErrors();

    $idea = Idea::query()->firstWhere('title', 'A reading list that ranks by time available');

    expect($idea)->not->toBeNull()
        ->and($idea->user_id)->toBe($user->id)
        ->and($idea->status)->toBe(IdeaStatus::Inbox)
        ->and($idea->summary)->toBe('People say they want fewer books, not more.')
        ->and($idea->slug)->toBe('a-reading-list-that-ranks-by-time-available');
});

test('a capture with no title is refused before anything is written', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.dashboard')
        ->set('captureTitle', '')
        ->call('capture')
        ->assertHasErrors(['captureTitle' => 'required']);

    expect(Idea::query()->count())->toBe(0);
});

test('the edit screen creates an idea with every field it is given', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.edit')
        ->set('title', 'Offline-first reading')
        ->set('summary', 'Cache the last ten posts on the device.')
        ->set('content', "## Why\n\nTrains.")
        ->call('save')
        ->assertHasNoErrors();

    $idea = Idea::query()->firstWhere('title', 'Offline-first reading');

    expect($idea)->not->toBeNull()
        ->and($idea->content)->toContain('Trains')
        ->and($idea->isPublished())->toBeFalse();
});

test('editing an idea rewrites it and keeps the slug it was published under', function () {
    $idea = Idea::factory()->published()->create();

    Livewire::actingAs($idea->user)
        ->test('pages::ideas.edit', ['idea' => $idea->slug])
        ->set('title', 'Rewritten after reading the comments')
        ->call('save')
        ->assertHasNoErrors();

    $idea->refresh();

    expect($idea->title)->toBe('Rewritten after reading the comments')
        // A renamed idea keeps its URL: readers have it bookmarked and other
        // pages link to it.
        ->and($idea->slug)->not->toBe('rewritten-after-reading-the-comments');
});

test('a slug already used by another idea is refused', function () {
    $taken = Idea::factory()->create(['slug' => 'taken-already']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.edit')
        ->set('title', 'Anything')
        ->set('slug', $taken->slug)
        ->call('save')
        ->assertHasErrors(['slug']);
});

test('a slug that names one of the module own screens is refused', function () {
    // `/ideas/plans` is a screen. A row allowed to claim that slug would be
    // unreachable by hand-typed URL while its own listing linked to it.
    Livewire::actingAs(User::factory()->create())
        ->test('pages::ideas.edit')
        ->set('title', 'Plans, the idea')
        ->set('slug', 'plans')
        ->call('save')
        ->assertHasErrors(['slug']);
});

test('an idea owned by somebody else cannot be opened for editing', function () {
    $idea = Idea::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('ideas.edit', ['idea' => $idea->slug]))
        // 404 rather than 403: a private idea is invisible, not forbidden, and
        // 403 would confirm the row exists.
        ->assertNotFound();
});

test('deleting an idea from the dashboard removes the row', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.dashboard')
        ->call('delete', $idea->id)
        ->assertHasNoErrors();

    expect(Idea::query()->whereKey($idea->id)->exists())->toBeFalse();
});

test('one account cannot delete another idea through the dashboard', function () {
    $idea = Idea::factory()->create();
    $intruder = User::factory()->create();

    Livewire::actingAs($intruder)
        ->test('pages::ideas.dashboard')
        // The lookup is scoped to the caller, so a foreign id 404s rather than
        // 403s. It also means the delete is never reached.
        ->call('delete', $idea->id)
        ->assertNotFound();

    expect(Idea::query()->whereKey($idea->id)->exists())->toBeTrue();
});

test('pinning an idea from the dashboard keeps it pinned', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.dashboard')
        ->call('togglePin', $idea->id);

    expect($idea->refresh()->is_pinned)->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::ideas.dashboard')
        ->call('togglePin', $idea->id);

    expect($idea->refresh()->is_pinned)->toBeFalse();
});

test('the workspace lists only the signed-in account ideas', function () {
    $user = User::factory()->create();
    $mine = Idea::factory()->count(2)->create(['user_id' => $user->id]);
    $theirs = Idea::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::ideas.dashboard');

    foreach ($mine as $idea) {
        $component->assertSee($idea->title);
    }

    $component->assertDontSee($theirs->title);
});
