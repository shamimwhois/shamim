<?php

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('a team can be created through the modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('create-team-modal')
        ->set('teamName', 'Studio Team')
        ->call('createTeam')
        ->assertHasNoErrors();

    expect(Team::query()->where('name', 'Studio Team')->exists())->toBeTrue();
});

test('profile settings save', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'New Name')
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->name)->toBe('New Name');
});

test('the settings pages render', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['pages::settings.profile', 'pages::settings.appearance', 'pages::settings.security', 'pages::teams.index'] as $component) {
        Livewire::test($component)->assertOk();
    }
});

test('a category in use refuses deletion', function () {
    $author = User::factory()->create();
    $category = Category::factory()->create();
    Post::factory()->for($author)->published()->for($category)->create();

    $this->actingAs($author);

    Livewire::test('pages::dashboard.taxonomy')
        ->call('deleteCategory', $category->id)
        ->assertHasNoErrors();

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('a tag can be deleted through the UI', function () {
    $author = User::factory()->create();
    $tag = Tag::factory()->create();
    $this->actingAs($author);

    Livewire::test('pages::dashboard.taxonomy')
        ->call('deleteTag', $tag->id)
        ->assertHasNoErrors();

    expect(Tag::query()->whereKey($tag->id)->exists())->toBeFalse();
});

test('the editor persists tags typed without pressing add', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();
    $this->actingAs($author);

    Livewire::test('pages::dashboard.posts.edit', ['post' => $post])
        ->set('tagInput', 'inline-tag')
        ->call('save')
        ->assertHasNoErrors();

    expect($post->refresh()->tags->pluck('slug')->all())->toContain('inline-tag');
});

test('autosave persists changes', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();
    $this->actingAs($author);

    Livewire::test('pages::dashboard.posts.edit', ['post' => $post])
        ->set('title', 'Autosaved title')
        ->call('autosave')
        ->assertHasNoErrors();

    expect($post->refresh()->title)->toBe('Autosaved title');
});

test('the posts table archives and unpublishes', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();
    $this->actingAs($author);

    Livewire::test('pages::dashboard.posts.index')->call('archive', $post->id);
    expect($post->refresh()->status)->toBe(PostStatus::Archived);

    Livewire::test('pages::dashboard.posts.index')->call('publish', $post->id);
    expect($post->refresh()->status)->toBe(PostStatus::Published);
});

test('the editor deletes a post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();
    $this->actingAs($author);

    Livewire::test('pages::dashboard.posts.edit', ['post' => $post])
        ->call('delete')
        ->assertRedirect();

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
});

test('a draft is visible to its author and hidden from others', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    $this->get(route('blog.show', $post))->assertNotFound();
    $this->actingAs($author)->get(route('blog.show', $post))->assertOk();
});
