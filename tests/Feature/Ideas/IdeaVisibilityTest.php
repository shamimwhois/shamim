<?php

use App\Actions\Ideas\SyncIdeaVisibility;
use App\Enums\ContentVisibility;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| What of the Ideas module is readable, and by whom
|--------------------------------------------------------------------------
|
| Publication is a conjunction: `visibility` says public *and* `published_at` is
| set. A row that satisfies only one half is not a bug in the data, it is the
| state between the decision and the act, and these tests pin that it stays
| invisible.
|
| The same rule answers three different questions — is it on the index, is it
| readable at its own URL, is it counted as published — so it is asserted through
| the model's `isVisibleTo()` rather than by scraping three pages that happen to
| agree today.
|
*/

test('a private idea is invisible to a guest and to another account', function () {
    $idea = Idea::factory()->create();

    $this->get(route('ideas.show', ['idea' => $idea->slug]))->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get(route('ideas.show', ['idea' => $idea->slug]))
        ->assertNotFound();

    $this->actingAs($idea->user)
        ->get(route('ideas.show', ['idea' => $idea->slug]))
        ->assertOk();
});

test('the public index never lists a private idea', function () {
    $hidden = collect(Idea::factory()->count(3)->create())->pluck('title');

    $page = $this->get('/ideas');

    foreach ($hidden as $title) {
        $page->assertDontSee($title);
    }
});

test('a public flag without a publish timestamp is not enough', function () {
    $idea = Idea::factory()->unpublishedPublic()->create();

    expect($idea->visibility)->toBe(ContentVisibility::Public)
        ->and($idea->published_at)->toBeNull()
        ->and($idea->isPublished())->toBeFalse()
        ->and($idea->isVisibleTo(null))->toBeFalse();

    $this->get(route('ideas.show', ['idea' => $idea->slug]))->assertNotFound();

    $this->get('/ideas')->assertDontSee($idea->title);
});

test('a published idea is readable by anyone and listed on the index', function () {
    $idea = Idea::factory()->published()->create();

    $this->get(route('ideas.show', ['idea' => $idea->slug]))
        ->assertOk()
        ->assertSee($idea->title);

    $this->get('/ideas')->assertSee($idea->title);
});

test('an operator with the admin panel may read an idea that is not published', function () {
    $idea = Idea::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('ideas.show', ['idea' => $idea->slug]))
        ->assertOk();
});

test('approving is not the same as publishing', function () {
    $idea = Idea::factory()->approved()->create();

    expect($idea->status)->toBe(IdeaStatus::Approved)
        ->and($idea->isPublished())->toBeFalse();

    $this->get(route('ideas.show', ['idea' => $idea->slug]))->assertNotFound();
});

test('a view is counted once per read of the page', function () {
    $idea = Idea::factory()->published()->create(['view_count' => 7]);

    $this->get(route('ideas.show', ['idea' => $idea->slug]))->assertSee('8 views');

    expect($idea->refresh()->view_count)->toBe(8);

    // A refused view must not inflate the number.
    $private = Idea::factory()->create();
    $this->get(route('ideas.show', ['idea' => $private->slug]));

    expect($private->refresh()->view_count)->toBe(0);
});

test('the publish timestamp is stamped once and survives being hidden again', function () {
    $idea = Idea::factory()->create(['status' => IdeaStatus::Approved]);

    app(SyncIdeaVisibility::class)->publish($idea);
    $first = $idea->fresh()->published_at;

    app(SyncIdeaVisibility::class)->hide($idea);
    $after = $idea->fresh();

    expect($after->visibility)->toBe(ContentVisibility::Private)
        ->and($after->published_at?->toDateTimeString())->toBe($first->toDateTimeString());

    app(SyncIdeaVisibility::class)->publish($after);

    // Republishing keeps the original date: the column records when the work
    // first became readable, not when it last was.
    expect($after->fresh()->published_at->toDateTimeString())->toBe($first->toDateTimeString());
});
