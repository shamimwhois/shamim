<?php

use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Models\IdeaPlan;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Ideas admin console
|--------------------------------------------------------------------------
|
| Three things are worth pinning about a console that reads across accounts:
|
|   1. It really does read across accounts, which is the only reason it exists.
|   2. It cannot write across accounts except by removal, because
|      `IdeaPolicy::update()` is authors only.
|   3. Its guard is the component's own `boot()`, not the route. A Livewire
|      mutation is a POST to `/livewire/update` and never passes through
|      `permission:admin:access`, so the last test here is the one that would fail
|      if the guard were deleted while every other test stayed green.
|
*/

test('guests are bounced to the sign-in dialog', function () {
    $this->get('/admin/ideas')->assertRedirect(route('home', ['signin' => 'required']));
});

test('accounts without admin:access are refused the console', function () {
    $this->actingAs(User::factory()->create())->get('/admin/ideas')->assertForbidden();
    $this->actingAs(User::factory()->seller()->create())->get('/admin/ideas')->assertForbidden();
});

test('an admin reads ideas from every account in the workspace shell', function () {
    $first = Idea::factory()->create(['title' => 'One account thought']);
    $second = Idea::factory()->create(['title' => 'Another account thought']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/ideas')
        ->assertOk()
        ->assertSee($first->title)
        ->assertSee($second->title)
        // Staff chrome, and nothing here may be indexed.
        ->assertSee('data-sui', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
});

test('the console lists the author beside the idea', function () {
    $author = User::factory()->create(['name' => 'Roya', 'email' => 'roya@example.test']);
    Idea::factory()->for($author)->create(['title' => 'A reading list that waits']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/ideas')
        ->assertOk()
        ->assertSee('Roya')
        ->assertSee('roya@example.test');
});

test('a stage tab narrows the list to that stage', function () {
    Idea::factory()->create(['title' => 'Still in the inbox', 'status' => IdeaStatus::Inbox]);
    Idea::factory()->create(['title' => 'Waiting for review', 'status' => IdeaStatus::UnderReview]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ideas')
        ->set('tab', IdeaStatus::UnderReview->value)
        ->assertSee('Waiting for review')
        ->assertDontSee('Still in the inbox');
});

test('the search reaches the note body as well as the title', function () {
    Idea::factory()->create([
        'title' => 'Something else entirely',
        'summary' => null,
        'content' => 'A paragraph about offline reading on trains.',
    ]);
    Idea::factory()->create(['title' => 'Unrelated', 'summary' => 'A note about the shop', 'content' => null]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ideas')
        ->set('search', 'trains')
        ->assertSee('Something else entirely')
        ->assertDontSee('Unrelated');
});

test('an admin may take an idea down', function () {
    $idea = Idea::factory()->published()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.ideas')
        ->call('remove', $idea->id);

    expect(Idea::query()->whereKey($idea->id)->exists())->toBeFalse();
});

test('a plan is linked from the idea it came from', function () {
    $idea = Idea::factory()->approved()->create(['title' => 'Convert me once']);
    $plan = IdeaPlan::factory()->for($idea->user)->fromIdea($idea)->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.ideas')
        ->assertSee('Has a plan')
        ->assertSee($plan->title);
});

test('an account without admin:access cannot reach the console through the endpoint', function () {
    $idea = Idea::factory()->create();

    // The route never sees this request, so the only thing standing between an
    // ordinary account and somebody else's row is the component's own guard.
    Livewire::actingAs($idea->user)
        ->test('pages::admin.ideas')
        ->assertForbidden();

    expect(Idea::query()->whereKey($idea->id)->exists())->toBeTrue();
});
