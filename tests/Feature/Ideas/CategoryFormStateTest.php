<?php

use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Models\User;
use Livewire\Livewire;

/*
 * The category screen reuses one form for creating and renaming, and which of
 * the two it is doing is decided entirely by `editingCategoryId`. Every test
 * here is about that pointer: it must be filled from the row it names, cleared
 * when the author cancels, and never survive a save long enough to make the
 * next keystroke overwrite a folder the author already finished with.
 */

test('opening a folder for renaming loads its own name and description', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create([
        'name' => 'Quarterly experiments',
        'description' => 'Things worth trying in Q3.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('edit', $category->id)
        ->assertSet('editingCategoryId', $category->id)
        ->assertSet('name', 'Quarterly experiments')
        ->assertSet('description', 'Things worth trying in Q3.')
        ->assertSee('Rename category');
});

test('the form is reloaded from the row rather than kept as the client typed it', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create(['name' => 'Real name']);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->set('name', 'Typed over')
        ->call('edit', $category->id)
        ->assertSet('name', 'Real name');
});

test('a folder with no description opens as an empty field rather than a null string', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create(['description' => null]);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('edit', $category->id)
        ->assertSet('description', '');
});

test('editing somebody else\'s folder is refused and the form keeps nothing of it', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $theirs = IdeaCategory::factory()->for($stranger)->create(['name' => 'Private folder']);

    $component = Livewire::actingAs($user)->test('pages::ideas.categories');

    // The row is not the caller's, so the lookup fails the same way it would
    // over HTTP: a 404 out of the endpoint, not a form filled from a stranger.
    $component->call('edit', $theirs->id)->assertStatus(404);

    // A refused edit must not leave the screen half-pointing at the other row.
    $component->assertSet('editingCategoryId', null)
        ->assertSet('name', '')
        ->assertDontSee('Private folder');

    expect(IdeaCategory::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

test('cancelling clears every field the edit filled', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create([
        'name' => 'Clear me',
        'description' => 'and me',
    ]);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('edit', $category->id)
        ->call('resetForm')
        ->assertSet('editingCategoryId', null)
        ->assertSet('name', '')
        ->assertSet('description', '')
        ->assertSee('New category');
});

test('cancelling also clears the errors a failed save left behind', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::ideas.categories');

    $component->call('save')->assertHasErrors(['name' => 'required']);

    expect($component->instance()->getErrorBag()->count())->toBeGreaterThan(0);

    $component->call('resetForm')->assertHasNoErrors();
});

test('a rename writes the row it pointed at and the next save creates instead of overwriting', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create(['name' => 'First name']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('edit', $category->id)
        ->set('name', 'Renamed')
        ->call('save');

    expect($category->fresh()->name)->toBe('Renamed')
        // The save has to forget the pointer; otherwise the next create would
        // silently destroy the folder that was just renamed.
        ->and($component->get('editingCategoryId'))->toBeNull();

    $component->set('name', 'A second folder')->call('save');

    expect(IdeaCategory::query()->where('user_id', $user->id)->pluck('name')->sort()->values()->all())
        ->toBe(['A second folder', 'Renamed']);
});

test('deleting the folder that is open in the form closes the form', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create(['name' => 'Doomed folder']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('edit', $category->id)
        ->call('delete', $category->id);

    expect(IdeaCategory::query()->whereKey($category->id)->exists())->toBeFalse();

    $component->assertSet('editingCategoryId', null)
        ->assertSet('name', '')
        ->assertSee('New category');
});

test('deleting a different folder leaves the form alone', function () {
    $user = User::factory()->create();
    $open = IdeaCategory::factory()->for($user)->create(['name' => 'Still editing']);
    $other = IdeaCategory::factory()->for($user)->create(['name' => 'Remove this one']);

    $component = Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('edit', $open->id)
        ->call('delete', $other->id);

    expect(IdeaCategory::query()->whereKey($other->id)->exists())->toBeFalse();

    // The form was pointing at a row that still exists, so it must keep its
    // pointer and its contents; wiping it would throw away what the author typed.
    $component->assertSet('editingCategoryId', $open->id)
        ->assertSet('name', 'Still editing');
});

test('a saved folder keeps its ideas when the folder is later removed', function () {
    $user = User::factory()->create();
    $category = IdeaCategory::factory()->for($user)->create(['name' => 'Temporary home']);
    $idea = Idea::factory()->create(['user_id' => $user->id, 'idea_category_id' => $category->id]);

    Livewire::actingAs($user)
        ->test('pages::ideas.categories')
        ->call('delete', $category->id);

    expect($idea->fresh()->idea_category_id)->toBeNull()
        ->and(Livewire::actingAs($user)->test('pages::ideas.categories')->instance()->uncategorised)
        ->toBe(1);
});
