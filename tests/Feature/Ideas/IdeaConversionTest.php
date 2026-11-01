<?php

use App\Actions\Ideas\ConvertIdeaToPlan;
use App\Enums\IdeaStatus;
use App\Enums\PlanStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\IdeaPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('converting an idea writes a plan that links back to it', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->for($user)->approved()->create([
        'title' => 'Let a reader save a post for later',
        'slug' => 'read-later',
        'summary' => 'A short list of things to come back to.',
        'content' => 'Notes about why the browser tabs trick is not enough.',
        'priority' => WorkPriority::High,
    ]);

    $plan = app(ConvertIdeaToPlan::class)->handle($idea);

    expect($plan->idea_id)->toBe($idea->id)
        ->and($plan->user_id)->toBe($user->id)
        ->and($plan->title)->toBe($idea->title)
        ->and($plan->slug)->toBe('read-later-plan')
        ->and($plan->status)->toBe(PlanStatus::Draft)
        ->and($plan->priority)->toBe(WorkPriority::High)
        ->and($plan->problem_statement)->toBe('A short list of things to come back to.')
        ->and($plan->notes)->toBe('Notes about why the browser tabs trick is not enough.');
});

test('the idea survives conversion unchanged', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->for($user)->approved()->create();

    $before = $idea->fresh()->toArray();
    app(ConvertIdeaToPlan::class)->handle($idea);

    // Conversion is additive. If the idea moved, archived itself, or lost its
    // publication, then writing a plan down would be a way to destroy the thing it
    // came from, and the account could not read the original thought again.
    expect($idea->fresh()->toArray())->toBe($before);
});

test('an idea that has not been approved cannot become a plan', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->for($user)->create(['status' => IdeaStatus::Inbox]);

    expect(fn () => app(ConvertIdeaToPlan::class)->handle($idea))
        ->toThrow(InvalidArgumentException::class)
        ->and(IdeaPlan::query()->count())->toBe(0);
});

test('one idea converts once and a second attempt is refused', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->for($user)->approved()->create(['slug' => 'once']);

    app(ConvertIdeaToPlan::class)->handle($idea);

    expect(fn () => app(ConvertIdeaToPlan::class)->handle($idea->fresh()))
        ->toThrow(InvalidArgumentException::class)
        ->and(IdeaPlan::query()->count())->toBe(1);
});

test('a derived plan slug that is already taken still resolves to a unique one', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->for($user)->approved()->create(['slug' => 'read-later']);

    // Somebody wrote this plan by hand before the idea was approved. Conversion must
    // not fail on the index and must not overwrite the row it collided with.
    IdeaPlan::factory()->for($user)->create(['slug' => 'read-later-plan', 'title' => 'An older plan']);

    $plan = app(ConvertIdeaToPlan::class)->handle($idea);

    expect($plan->slug)->toBe('read-later-plan-2')
        ->and(IdeaPlan::query()->where('slug', 'read-later-plan')->value('title'))
        ->toBe('An older plan');
});

test('the edit screen offers conversion only once the idea is approved', function () {
    $user = User::factory()->create();
    $inbox = Idea::factory()->for($user)->create(['status' => IdeaStatus::Inbox, 'title' => 'Cold idea']);
    $approved = Idea::factory()->for($user)->approved()->create(['title' => 'Warm idea']);

    Livewire::actingAs($user)
        ->test('pages::ideas.edit', ['idea' => $inbox->slug])
        ->assertDontSee('Convert to plan');

    Livewire::actingAs($user)
        ->test('pages::ideas.edit', ['idea' => $approved->slug])
        ->assertSee('Convert to plan');
});

test('converting from the screen lands on the new plan and leaves the idea readable', function () {
    $user = User::factory()->create();
    $idea = Idea::factory()->for($user)->approved()->create(['slug' => 'from-the-screen']);

    Livewire::actingAs($user)
        ->test('pages::ideas.edit', ['idea' => 'from-the-screen'])
        ->call('convertToPlan')
        ->assertRedirectToRoute('ideas.plan.edit', ['plan' => 'from-the-screen-plan']);

    expect($idea->fresh())->not->toBeNull()
        ->and(IdeaPlan::query()->where('slug', 'from-the-screen-plan')->exists())->toBeTrue();
});

test('somebody else cannot reach an idea to convert it', function () {
    $author = User::factory()->create();
    $intruder = User::factory()->create();
    $idea = Idea::factory()->for($author)->approved()->create(['slug' => 'not-yours']);

    // 404 rather than 403: an idea that is not yours is not visible, and the
    // response must not confirm that the slug exists.
    $this->actingAs($intruder)
        ->get(route('ideas.edit', ['idea' => $idea->slug]))
        ->assertNotFound();

    expect(IdeaPlan::query()->count())->toBe(0);
});

test('a guest cannot convert through the livewire endpoint', function () {
    // The route middleware never runs on an update POST, so this asserts the
    // in-component guard rather than the route group.
    Livewire::test('pages::ideas.edit')->assertForbidden();
});
