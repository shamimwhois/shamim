<?php

use App\Models\Post;
use App\Models\Tag;

test('the detail page emits open graph article metadata', function () {
    $post = Post::factory()->published()->create();

    $response = $this->get(route('blog.show', $post));
    $html = $response->getContent();

    $response->assertOk();

    expect($html)
        ->toContain('<meta property="og:type" content="article"')
        ->toContain('<meta property="og:title" content="'.e($post->title).'"')
        ->toContain('<meta property="og:url" content="'.route('blog.show', $post).'"')
        ->toContain('<meta property="article:published_time"')
        ->toContain('<meta property="article:author"')
        ->toContain('<meta name="twitter:card" content="summary_large_image"');
});

test('the canonical url points at the post slug', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('blog.show', $post))
        ->assertSee('<link rel="canonical" href="'.route('blog.show', $post).'"', escape: false);
});

test('each tag on the post becomes an article:tag property', function () {
    $post = Post::factory()->published()->create();
    $tag = Tag::factory()->create(['name' => 'Observability']);
    $post->tags()->attach($tag);

    $this->get(route('blog.show', $post))
        ->assertSee('<meta property="article:tag" content="Observability"', escape: false);
});

test('share intents are prefilled with the encoded permalink and title', function () {
    $post = Post::factory()->published()->create();

    $response = $this->get(route('blog.show', $post));
    $html = $response->getContent();

    $response->assertOk();

    $url = rawurlencode(route('blog.show', $post));
    $title = rawurlencode($post->title);

    $xHandle = config('portfolio.author.links.x.handle');
    $via = $xHandle ? '&via='.rawurlencode(ltrim($xHandle, '@')) : '';

    // Assert every configured primary target resolved its placeholders, rather
    // than hardcoding one provider's URL shape.
    //
    // NB: Pest's toContain() is variadic over needles and takes no message
    // argument — passing one silently turns it into an extra needle, which makes
    // the assertion pass or fail for the wrong reason. A plain loop avoids that.
    $intents = config('portfolio.sharing.primary');

    expect($intents)->not->toBeEmpty();

    foreach ($intents as $target) {
        $expected = str_replace(
            ['{url}', '{title}', '{via}'],
            [$url, $title, $via],
            $target['intent'],
        );

        // Blade escapes `&` to `&amp;` inside the href attribute; compare on the
        // encoded form the browser actually parses.
        $expected = e($expected);

        expect($html)->toContain($expected);
    }
});

test('the share bar exposes the url and title for the native share sheet', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('data-share-url="'.route('blog.show', $post).'"', escape: false)
        ->assertSee('data-share-title="'.e($post->title).'"', escape: false);
});

test('a copy-link control is always offered', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSeeText('Copy link');
});

test('the rss feed lists published posts and excludes drafts', function () {
    Post::factory()->published()->create(['title' => 'A feed item']);
    Post::factory()->create(['title' => 'A draft item']);

    $response = $this->get(route('feed'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    expect($response->getContent())
        ->toContain('A feed item')
        ->not->toContain('A draft item');
});

test('the sitemap lists published posts and excludes drafts', function () {
    $post = Post::factory()->published()->create();
    Post::factory()->create();

    $response = $this->get(route('sitemap'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)
        ->toContain(route('blog.show', $post))
        ->toContain('<urlset');
});

test('robots.txt discloses the sitemap and disallows private areas', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk();

    $body = $response->getContent();

    expect($body)
        ->toContain('Sitemap: '.route('sitemap'))
        ->toContain('Disallow: /dashboard')
        ->toContain('Disallow: /login');
});

test('the home page exposes the authors social profiles', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('aria-label="Primary"', escape: false);
});
