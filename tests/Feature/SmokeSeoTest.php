<?php

use App\Models\Post;
use App\Support\Seo;
use Livewire\Livewire;

test('seo metadata follows the search filter', function () {
    Post::factory()->published()->create(['title' => 'Caching at scale']);

    Livewire::test('pages::blog.index')
        ->set('search', 'Caching');

    expect(app(Seo::class)->title)->toBe('Search: Caching')
        ->and(app(Seo::class)->noindex)->toBeTrue();
});
