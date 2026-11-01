<?php

use App\Actions\Ideas\SaveIdea;
use App\Actions\Ideas\SyncIdeaVisibility;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The idea workflow
|--------------------------------------------------------------------------
|
| `docs/Idea.md` describes an idea moving through stages, and a stage machine is
| only real if an illegal move is refused somewhere other than the dropdown.
| These tests drive the action directly with values the edit screen would never
| offer, because the screen is not the boundary — the write is.
|
*/

test('every stage declares the stages it may reach', function () {
    expect(IdeaStatus::Inbox->allowedTransitions())
        ->toContain(IdeaStatus::Draft, IdeaStatus::UnderReview, IdeaStatus::Archived)
        ->and(IdeaStatus::Inbox->canTransitionTo(IdeaStatus::Approved))->toBeFalse()
        ->and(IdeaStatus::Archived->canTransitionTo(IdeaStatus::Approved))->toBeFalse()
        ->and(IdeaStatus::Archived->canTransitionTo(IdeaStatus::Inbox))->toBeTrue();
});

test('staying in the same stage is always allowed', function () {
    // Saving an idea that has not moved is the common case; treating it as a
    // transition would reject the ordinary act of editing a title.
    foreach (IdeaStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeTrue();
    }
});

test('the action refuses a jump from the inbox straight to approved', function () {
    $idea = Idea::factory()->create(['status' => IdeaStatus::Inbox]);

    expect(fn () => app(SaveIdea::class)->handle($idea->user, [
        'title' => $idea->title,
        'status' => IdeaStatus::Approved->value,
    ], $idea))->toThrow(InvalidArgumentException::class);

    expect($idea->fresh()->status)->toBe(IdeaStatus::Inbox);
});

test('a closed idea cannot be reopened straight into approval', function () {
    $idea = Idea::factory()->archived()->create();

    expect(fn () => app(SaveIdea::class)->handle($idea->user, [
        'title' => $idea->title,
        'status' => IdeaStatus::Approved->value,
    ], $idea))->toThrow(InvalidArgumentException::class);

    app(SaveIdea::class)->handle($idea->user, [
        'title' => $idea->title,
        'status' => IdeaStatus::Inbox->value,
    ], $idea);

    expect($idea->fresh()->status)->toBe(IdeaStatus::Inbox);
});

test('the edit screen only offers the stages reachable from the current one', function () {
    $idea = Idea::factory()->archived()->create();

    $component = Livewire::actingAs($idea->user)
        ->test('pages::ideas.edit', ['idea' => $idea->slug]);

    expect($component->instance()->availableStatuses->all())
        ->toBe([IdeaStatus::Archived, IdeaStatus::Inbox]);

    // An archive is a decision, not a bin: it may be revisited, but it cannot be
    // approved without going back through the stages first. The select box never
    // offers the jump, so the request below is one the browser did not build, and
    // the refusal belongs to the action rather than the field's own rules.
    $component->set('status', IdeaStatus::Approved->value)
        ->call('save');

    expect($idea->fresh()->status)->toBe(IdeaStatus::Archived);
});

test('only an approved idea can be published', function () {
    $idea = Idea::factory()->create();

    expect(fn () => app(SyncIdeaVisibility::class)->publish($idea))
        ->toThrow(InvalidArgumentException::class);
});

test('a stage is only publishable when the workflow says so', function () {
    expect(IdeaStatus::Approved->isPublishable())->toBeTrue()
        ->and(IdeaStatus::UnderReview->isPublishable())->toBeFalse()
        ->and(IdeaStatus::Archived->isPublishable())->toBeFalse();
});

test('an account may not move another account idea through the workflow', function () {
    $idea = Idea::factory()->create();
    $intruder = User::factory()->create();

    Livewire::actingAs($intruder)
        ->test('pages::ideas.dashboard')
        ->call('togglePin', $idea->id)
        ->assertNotFound();

    expect($idea->fresh()->is_pinned)->toBeFalse();
});
