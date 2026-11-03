<?php

use App\Actions\Ideas\SaveIdea;
use App\Actions\Ideas\SaveIdeaCategory;
use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Models\User;
use Database\Seeders\IdeaCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the categories screen lists only the signed-in account folders', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    IdeaCategory::factory()->for($user)->create(['name' => 'My own folder']);
    IdeaCategory::factory()->for($other)->create(['name' => 'Somebody else folder']);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->assertSee('My own folder')
        ->assertDontSee('Somebody else folder');
});

test('a category created from the screen belongs to its author', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->set('name', 'Platform experiments')
        ->call('save');

    $category = IdeaCategory::query()->firstWhere('name', 'Platform experiments');

    expect($category)->not->toBeNull()
        ->and($category->user_id)->toBe($user->id)
        ->and($category->slug)->toBe('platform-experiments');
});

test('an empty category name is refused and writes nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(IdeaCategory::query()->count())->toBe(0);
});

test('renaming a category keeps its slug unless a new one is asked for', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create(['name' => 'Someday', 'slug' => 'someday']);

    app(SaveIdeaCategory::class)->handle($user, ['name' => 'Someday maybe'], $category);

    $category->refresh();

    // The folder is referenced by nothing outside this account's own screens, but a
    // rename still has to be an explicit decision rather than a side effect of
    // editing the label.
    expect($category->name)->toBe('Someday maybe')
        ->and($category->slug)->toBe('someday');
});

test('two accounts may both have a folder with the same name', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $one = app(SaveIdeaCategory::class)->handle($first, ['name' => 'Security']);
    $two = app(SaveIdeaCategory::class)->handle($second, ['name' => 'Security']);

    // A category is never addressed by URL, so there is nothing to namespace and
    // nothing to collide with.
    expect($one->slug)->toBe('security')
        ->and($two->slug)->toBe('security')
        ->and($two->user_id)->toBe($second->id);
});

test('an account may not rename or delete another account category', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $category = IdeaCategory::factory()->for($owner)->create();

    $component = Livewire::actingAs($intruder)
        ->test('pages::ideas.categories')
        ->set('editingCategoryId', $category->id)
        ->set('name', 'Hijacked');

    // The screen resolves the folder through the signed-in account before it touches
    // it, so the refusal is a 404 rather than a 403: an intruder is not told the
    // id exists.
    $component->call('save')->assertNotFound();

    Livewire::actingAs($intruder)
        ->test('pages::ideas.categories')
        ->call('delete', $category->id)
        ->assertNotFound();

    expect(IdeaCategory::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and($category->fresh()->name)->not->toBe('Hijacked');
});

test('deleting a category leaves its ideas behind uncategorised', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create();

    $idea = app(SaveIdea::class)->handle($user, [
        'title' => 'A thought in a folder',
        'idea_category_id' => $category->id,
    ]);

    expect($idea->idea_category_id)->toBe($category->id);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('delete', $category->id);

    expect(IdeaCategory::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and($idea->fresh()->idea_category_id)->toBeNull();
});

test('the category seeder gives every permitted account its own copy and is safe to re-run', function () {
    $user = User::factory()->create();

    $counts = collect(IdeaCategorySeeder::defaults())->count();

    $this->seed(IdeaCategorySeeder::class);

    expect(IdeaCategory::query()->where('user_id', $user->id)->count())->toBe($counts);

    $this->seed(IdeaCategorySeeder::class);

    // Idempotency is the reason this seeder may sit in `DatabaseSeeder`: re-running
    // it renames nothing and doubles nothing.
    expect(IdeaCategory::query()->count())->toBe($counts);
});

test('the category seeder gives each account its own copy of the folders', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $counts = collect(IdeaCategorySeeder::defaults())->count();

    $this->seed(IdeaCategorySeeder::class);

    // The filter in the seeder is `hasPermission(UseIdeas)`, and every account this
    // app's matrix can produce holds it, so the case worth pinning is that the copy
    // is per account rather than one shared list: two holders, two sets of folders.
    expect(IdeaCategory::query()->where('user_id', $first->id)->count())->toBe($counts)
        ->and(IdeaCategory::query()->where('user_id', $second->id)->count())->toBe($counts)
        ->and(IdeaCategory::query()->count())->toBe($counts * 2);
});

test('filing an idea refuses a category that belongs to someone else', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $category = IdeaCategory::factory()->for($owner)->create();

    expect(fn () => app(SaveIdea::class)->handle($intruder, [
        'title' => 'Filed where I do not belong',
        'idea_category_id' => $category->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(Idea::query()->count())->toBe(0);
});
