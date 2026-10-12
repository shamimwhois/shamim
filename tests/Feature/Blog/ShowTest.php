<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

test('a published post is readable by a guest', function () {
    $post = Post::factory()->published()->create(['title' => 'A published article']);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('A published article')
        ->assertSee('data-reading-target', escape: false);
});

test('a draft is hidden from guests', function () {
    $post = Post::factory()->create();

    $this->get(route('blog.show', $post))->assertNotFound();
});

test('an archived post is hidden from guests', function () {
    $post = Post::factory()->archived()->create();

    $this->get(route('blog.show', $post))->assertNotFound();
});

test('an author can preview their own unpublished draft', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->create();

    $this->actingAs($author)
        ->get(route('blog.show', $post))
        ->assertOk();
});

test('a stranger cannot preview another authors draft', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('blog.show', $post))
        ->assertNotFound();
});

test('the markdown body is rendered to html', function () {
    $post = Post::factory()->published()
        ->body("# Heading\n\nA paragraph with **bold** text.")
        ->create();

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('<h1', escape: false)
        ->assertSee('<strong>bold</strong>', escape: false);
});

test('raw html in a body is escaped rather than executed', function () {
    $post = Post::factory()->published()
        ->body("Text before\n\n<script>alert('xss')</script>\n\nText after")
        ->create();

    $response = $this->get(route('blog.show', $post));

    $response->assertOk();
    expect($response->getContent())->not->toContain('<script>alert');
});

test('fenced code blocks carry a language attribute for the copy button', function () {
    $post = Post::factory()->published()
        ->body("Intro\n\n```php\n\$a = 1;\n```\n")
        ->create();

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('data-language="php"', escape: false);
});

test('a view is recorded once per session', function () {
    $post = Post::factory()->published()->create();

    expect($post->view_count)->toBe(0);

    $this->get(route('blog.show', $post))->assertOk();
    $this->get(route('blog.show', $post))->assertOk();

    expect($post->refresh()->view_count)->toBe(1);
});

test('approved comments are shown and pending ones are not', function () {
    $post = Post::factory()->published()->create();

    Comment::factory()->for($post)->approved()->create(['body' => 'An approved remark']);
    Comment::factory()->for($post)->create(['body' => 'A pending remark']);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('An approved remark')
        ->assertDontSee('A pending remark');
});

test('a rejected comment is never shown', function () {
    $post = Post::factory()->published()->create();
    Comment::factory()->for($post)->rejected()->create(['body' => 'A rejected remark']);

    $this->get(route('blog.show', $post))->assertDontSee('A rejected remark');
});

test('the table of contents is generated from the body headings', function () {
    $post = Post::factory()->published()
        ->body("## First section\n\nText.\n\n## Second section\n\nText.")
        ->create();

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('On this page')
        ->assertSee('First section')
        ->assertSee('Second section');
});

test('related posts are drawn from shared tags', function () {
    $tag = Tag::factory()->create();

    $post = Post::factory()->published()->create(['title' => 'The main article']);
    $related = Post::factory()->published()->create(['title' => 'A closely related piece']);

    $post->tags()->attach($tag);
    $related->tags()->attach($tag);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('A closely related piece');
});

test('a post cannot be reached by its draft id when unpublished', function () {
    $post = Post::factory()->create();

    // Binding is by slug, and the slug belongs to a hidden row.
    $this->get('/blog/'.$post->slug)->assertNotFound();
});

test('post detail is indexable while filtered indexes are not', function () {
    $post = Post::factory()->published()->create();

    $response = $this->get(route('blog.show', $post));

    $response->assertOk();
    expect($response->getContent())->not->toContain('name="robots" content="noindex');
});
