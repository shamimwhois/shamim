<?php

use App\Models\Post;

test('an older featured post still leads the homepage', function () {
    Post::factory()->published()->create([
        'title' => 'The featured piece',
        'is_featured' => true,
        'published_at' => now()->subMonths(6),
    ]);

    // Enough newer posts to push the featured one outside the latest window.
    Post::factory()->published()->count(8)->create([
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('The featured piece');
});
