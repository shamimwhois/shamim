<?php

use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * The community feed page — the redesigned social + forum hybrid.
 */
it('renders the feed page in the numbered-section design system', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('compare&nbsp;notes', false)
        ->assertSee('Top contributors')
        ->assertSee('House guidelines')
        ->assertSee('Load more posts');
});

it('filters posts by the URL-bound topic and sort state', function (): void {
    Livewire::withQueryParams(['category' => 'backend', 'sort' => 'unanswered'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Daniel Okafor')
        ->assertSee('1 matching posts');
});

it('degrades a crafted query string to the default view', function (): void {
    Livewire::withQueryParams(['category' => 'privilege-escalation', 'sort' => 'hot'])
        ->test('pages::feed')
        ->assertOk()
        ->assertSee('Priya Menon');
});
