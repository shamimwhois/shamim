<?php

use App\Enums\AccountRole;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

test('the taxonomy screen requires authentication', function () {
    $this->get(route('admin.taxonomy'))->assertRedirect(route('home', ['signin' => 'required']));
});

test('the taxonomy screen is an admin screen', function () {
    // What gates the page is `admin:access`, not `taxonomy:manage`: the seller
    // role still holds the taxonomy permission, and still cannot open this.
    $this->actingAs(User::factory()->create())
        ->get(route('admin.taxonomy'))
        ->assertForbidden();

    $this->actingAs(User::factory()->seller()->create())
        ->get(route('admin.taxonomy'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.taxonomy'))
        ->assertOk();
});

test('the admin permission is rechecked on Livewire updates', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('pages::admin.taxonomy');

    $admin->forceFill(['role' => AccountRole::User])->save();

    $component->call('resetCategoryForm')->assertForbidden();
});

test('the taxonomy screen lists categories and tags', function () {
    $author = User::factory()->admin()->create();
    Category::factory()->create(['name' => 'Performance', 'slug' => 'performance']);
    Tag::factory()->create(['name' => 'Caching', 'slug' => 'caching']);

    $this->actingAs($author)
        ->get(route('admin.taxonomy'))
        ->assertOk()
        ->assertSee('Performance')
        ->assertSee('Caching');
});

test('a category counts only published posts', function () {
    $category = Category::factory()->create(['name' => 'Performance', 'slug' => 'performance']);

    Post::factory()->published()->for($category)->count(2)->create();
    Post::factory()->for($category)->count(3)->create();

    expect($category->refresh()->posts_count)->toBe(2);
});

test('a deleted category leaves its posts intact', function () {
    $category = Category::factory()->create();
    $post = Post::factory()->published()->for($category)->create();

    $category->delete();

    expect($post->refresh()->category_id)->toBeNull()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue();
});

test('a tag is shared across posts rather than duplicated', function () {
    $tag = Tag::factory()->create(['slug' => 'caching']);

    $a = Post::factory()->published()->create();
    $b = Post::factory()->published()->create();

    $a->tags()->attach($tag);
    $b->tags()->attach($tag);

    expect($tag->refresh()->posts_count)->toBe(2)
        ->and(Tag::query()->where('slug', 'caching')->count())->toBe(1);
});

test('the category filter on the public index matches the stored slug', function () {
    $category = Category::factory()->create(['name' => 'Performance', 'slug' => 'performance']);
    Post::factory()->published()->for($category)->create(['title' => 'An indexed article']);

    $this->get(route('blog.index', ['category' => 'performance']))
        ->assertOk()
        ->assertSee('An indexed article');
});

test('a category with no published posts is absent from the sitemap', function () {
    $empty = Category::factory()->create(['slug' => 'empty-category']);
    $used = Category::factory()->create(['slug' => 'used-category']);

    Post::factory()->published()->for($used)->create();

    $body = $this->get(route('sitemap'))->getContent();

    expect($body)
        ->toContain('used-category')
        ->not->toContain('empty-category');
});

test('the taxonomy console disables commit buttons while requests run', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.taxonomy'))
        ->assertOk()
        ->assertSee('wire:loading.attr="disabled"', escape: false);
});
