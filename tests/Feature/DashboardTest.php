<?php

use App\Models\Post;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard.index'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('id="dashboard-main"', escape: false);
});

test('the dashboard is excluded from search engines', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
        ->assertSee('<meta name="robots" content="noindex', escape: false);
});

test('the dashboard counts only the signed-in author own posts', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();

    Post::factory()->for($author)->published()->count(3)->create();
    Post::factory()->for($other)->published()->count(5)->create();

    $response = $this->actingAs($author)->get(route('dashboard.index'));

    $response->assertOk();

    // Scrape the value rendered inside the "Published" metric tile. Asserting on
    // the surrounding whitespace would couple the test to Blade's indentation.
    preg_match('/Published\s*<\/p>.*?<p[^>]*>\s*([\d,]+)\s*<\/p>/s', $response->getContent(), $matches);

    expect($matches[1] ?? null)->toBe('3');
});
