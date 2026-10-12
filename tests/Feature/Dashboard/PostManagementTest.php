<?php

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\UpdatePost;
use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Rules\PostSlug;
use Livewire\Livewire;

test('the posts index requires authentication', function () {
    $this->get(route('dashboard.posts.index'))->assertRedirect(route('login'));
});

test('the posts index lists the authors own posts only', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();

    Post::factory()->for($author)->create(['title' => 'My own draft']);
    Post::factory()->for($other)->create(['title' => 'Someone elses draft']);

    $this->actingAs($author)
        ->get(route('dashboard.posts.index'))
        ->assertOk()
        ->assertSee('My own draft')
        ->assertDontSee('Someone elses draft');
});

test('the edit screen is reachable by its author', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    $this->actingAs($author)
        ->get(route('dashboard.posts.edit', $post))
        ->assertOk();
});

test('the edit screen is a 404 for another author', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.posts.edit', $post))
        ->assertNotFound();
});

test('the edit route binds by primary key so a retitled slug cannot break it', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create(['slug' => 'original-slug']);

    // Simulate the author changing the slug mid-session.
    $post->forceFill(['slug' => 'a-completely-new-slug'])->save();

    $response = $this->actingAs($author)->get(route('dashboard.posts.edit', $post));

    $response->assertOk();

    // The slug field binds with wire:model.blur, so its value lives in the
    // Livewire snapshot rather than a value="" attribute.
    expect($response->getContent())
        ->toContain('&quot;slug&quot;:&quot;a-completely-new-slug&quot;')
        ->and(route('dashboard.posts.edit', $post))->toContain('/'.$post->id.'/edit');
});

test('a new post is created as a draft even when publication is requested', function () {
    $author = User::factory()->create();
    $category = Category::factory()->create();

    $post = app(CreatePost::class)->handle($author, [
        'title' => 'A brand new article',
        'body' => 'Some body content for the post.',
        'category_id' => $category->id,
        'status' => PostStatus::Published,
    ]);

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull()
        ->and($post->user_id)->toBe($author->id);
});

test('creating a post with no slug derives one from the title', function () {
    $author = User::factory()->create();

    $post = app(CreatePost::class)->handle($author, [
        'title' => 'Understanding Route Model Binding',
        'body' => 'Body text.',
    ]);

    expect($post->slug)->toBe('understanding-route-model-binding');
});

test('an explicit slug is preserved when it is still unique', function () {
    $author = User::factory()->create();

    $post = app(CreatePost::class)->handle($author, [
        'title' => 'A title that would slug differently',
        'slug' => 'my-chosen-slug',
        'body' => 'Body text.',
    ]);

    expect($post->slug)->toBe('my-chosen-slug');
});

test('a colliding slug is suffixed rather than silently reusing the URL', function () {
    $author = User::factory()->create();

    $first = app(CreatePost::class)->handle($author, [
        'title' => 'First', 'slug' => 'shared-slug', 'body' => 'Body.',
    ]);

    $second = app(CreatePost::class)->handle($author, [
        'title' => 'Second', 'slug' => 'shared-slug', 'body' => 'Body.',
    ]);

    expect($first->slug)->toBe('shared-slug')
        ->and($second->slug)->not->toBe('shared-slug')
        ->and($second->slug)->toBe('shared-slug-1');
});

test('a slug held by a soft-deleted post is still treated as taken', function () {
    $author = User::factory()->create();

    $trashed = app(CreatePost::class)->handle($author, [
        'title' => 'Trashed', 'slug' => 'tombstone-slug', 'body' => 'Body.',
    ]);
    $trashed->delete();

    // The unique index still holds the tombstones slug, so a new post must not
    // claim it.
    $fresh = app(CreatePost::class)->handle($author, [
        'title' => 'Fresh', 'slug' => 'tombstone-slug', 'body' => 'Body.',
    ]);

    expect($fresh->slug)->not->toBe('tombstone-slug');
});

test('updating a post re-derives its reading metrics', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create(['word_count' => 1]);

    app(UpdatePost::class)->handle($post, [
        'body' => str_repeat('word ', 500),
    ]);

    expect($post->refresh()->word_count)->toBeGreaterThan(100);
});

test('updating a post cannot silently steal another posts slug', function () {
    $author = User::factory()->create();

    app(CreatePost::class)->handle($author, [
        'title' => 'Existing', 'slug' => 'taken-slug', 'body' => 'Body.',
    ]);

    $other = app(CreatePost::class)->handle($author, [
        'title' => 'Other', 'slug' => 'other-slug', 'body' => 'Body.',
    ]);

    app(UpdatePost::class)->handle($other, ['slug' => 'taken-slug']);

    expect($other->refresh()->slug)->not->toBe('taken-slug');
});

test('the PostSlug rule rejects malformed slugs', function () {
    $rule = new PostSlug;

    $fails = function (string $slug) use ($rule): bool {
        $failed = false;
        $rule->validate('slug', $slug, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    };

    expect($fails('has spaces'))->toBeTrue()
        ->and($fails('Has-Uppercase'))->toBeTrue()
        ->and($fails('trailing-dash-'))->toBeTrue()
        ->and($fails('-leading-dash'))->toBeTrue()
        ->and($fails(''))->toBeTrue()
        ->and($fails('a-valid-slug-123'))->toBeFalse();
});

test('the PostSlug rule rejects a slug already in use', function () {
    $post = Post::factory()->create(['slug' => 'already-taken']);

    $failed = false;
    (new PostSlug)->validate('slug', 'already-taken', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('the PostSlug rule permits a post to keep its own slug', function () {
    $post = Post::factory()->create(['slug' => 'my-own-slug']);

    $failed = false;
    (new PostSlug($post->id))->validate('slug', 'my-own-slug', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

test('publishing records a publish timestamp and unpublishing clears it', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    app(UpdatePost::class)->handle($post, ['status' => PostStatus::Published]);
    expect($post->refresh()->published_at)->not->toBeNull();

    app(UpdatePost::class)->handle($post, ['status' => PostStatus::Draft]);
    expect($post->refresh()->published_at)->toBeNull();
});

test('a published post keeps its published_at when edited without a status change', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();

    $original = $post->published_at;

    app(UpdatePost::class)->handle($post, ['title' => 'A revised title']);

    expect($post->refresh()->published_at->equalTo($original))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The rich text editor
|--------------------------------------------------------------------------
*/

test('the edit screen mounts the rich text editor over the body field', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create(['body' => '# Existing draft']);

    $this->actingAs($author)
        ->get(route('dashboard.posts.edit', $post))
        ->assertOk()
        ->assertSee('richTextEditor', escape: false)
        ->assertSee('data-editor', escape: false);
});

test('the rich text editor is mounted on the create screen too', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.posts.create'))
        ->assertOk()
        ->assertSee('richTextEditor', escape: false);
});

test('the editor receives the persisted body as its initial value', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create([
        'body' => 'Body text that must survive into the editor',
    ]);

    $this->actingAs($author)
        ->get(route('dashboard.posts.edit', $post))
        ->assertOk()
        ->assertSee('Body text that must survive into the editor');
});

test('the editor toolbar no longer drives a Livewire mode property', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    $response = $this->actingAs($author)->get(route('dashboard.posts.edit', $post));

    $response->assertOk();

    // The mode switch is Alpine-owned now. A Livewire round trip per toggle
    // would replace the textarea and drop the caret, which is the exact thing
    // the editor was built to avoid.
    expect($response->getContent())
        ->not->toContain('setMode(&#039;')
        ->not->toContain('setMode(\'');
});

test('the edit screen still renders the publishing sidebar', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    $this->actingAs($author)
        ->get(route('dashboard.posts.edit', $post))
        ->assertOk()
        ->assertSee('Status')
        ->assertSee('Featured');
});

test('saving a post from the editor persists the body', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create(['body' => 'Original body']);

    Livewire::actingAs($author)
        ->test('pages::dashboard.posts.edit', ['post' => $post])
        ->set('body', 'A completely rewritten body')
        ->call('save');

    expect($post->refresh()->body)->toBe('A completely rewritten body');
});
