<?php

use App\Enums\IdeaStatus;
use App\Models\FeedPost;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Post;
use App\Models\Remote;
use App\Models\RemoteMessage;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Search — every surface, one query
|--------------------------------------------------------------------------
|
| The palette finds developers; this page finds everything else. What is
| pinned here is the contract a reader depends on: a short term searches
| nothing and says so, a term that matches on two surfaces says so in two
| sections, each section links back to the surface whose own filters are the
| right tool for going further, and nothing unpublished ever leaks into the
| results — the surface's own scope is the only matcher, so a draft that is
| invisible on its own index is invisible here too.
|
*/

test('a short term searches nothing and says so', function (): void {
    Post::factory()->published()->create(['title' => 'A post about indexing']);

    Livewire::test('pages::search')
        ->set('q', 'a')
        ->assertSee('at least two characters')
        ->assertDontSee('A post about indexing');
});

test('a term matching two surfaces returns both sections with their counts', function (): void {
    Post::factory()->published()->create(['title' => 'Debounce hooks in depth']);
    Idea::factory()->published()->create(['title' => 'A debounce hook worth keeping']);

    Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->assertSee('Debounce hooks in depth')
        ->assertSee('A debounce hook worth keeping')
        ->assertSee('Writing')
        ->assertSee('Ideas');
});

test('each surface links onward to its own filtered index', function (): void {
    Post::factory()->published()->create(['title' => 'Debounce hooks in depth']);

    $html = Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->html();

    expect($html)->toContain(route('blog.index', ['search' => 'debounce']));
});

test('unpublished work never reaches the results', function (): void {
    Post::factory()->create(['title' => 'Draft debounce notes']);
    Idea::factory()->create(['title' => 'Private debounce sketch', 'status' => IdeaStatus::Draft]);

    Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->assertDontSee('Draft debounce notes')
        ->assertDontSee('Private debounce sketch')
        ->assertSee('Nothing matches this term.');
});

test('a lab snippet is found by the text of its code', function (): void {
    LabSnippet::factory()->published()->create([
        'title' => 'A ring you can actually see',
        'code' => 'const focusRing = "2px solid var(--signal)"',
    ]);

    Livewire::test('pages::search')
        ->set('q', 'focusRing')
        ->assertSee('A ring you can actually see')
        ->assertSee('Developer Lab');
});

test('a commit is found by its body and leads to its own page', function (): void {
    $post = FeedPost::factory()->create(['body' => 'Shipped the debounce hook today']);

    $html = Livewire::test('pages::search')
        ->set('q', 'debounce hook')
        ->assertSee('Shipped the debounce hook today')
        ->html();

    expect($html)->toContain(route('feed.show', ['slug' => $post->slug]));
});

test('a surface with more rows than it shows hands the reader to see all', function (): void {
    Post::factory()->published()->count(8)->create(['title' => 'Debounce part']);

    $component = Livewire::test('pages::search')
        ->set('q', 'debounce');

    // Five rows on the page; the count and the link say how much is behind them.
    $component->assertSee('See all');

    expect(count($component->instance()->sections[0]['rows']))->toBe(5)
        ->and($component->instance()->sections[0]['count'])->toBe(8);
});

test('the term arrives from the palette through the query string', function (): void {
    Post::factory()->published()->create(['title' => 'Debounce hooks in depth']);

    $this->get(route('search.index', ['q' => 'debounce']))
        ->assertOk()
        ->assertSee('Debounce hooks in depth');
});

test('the palette offers the everything page when no developer matches', function (): void {
    $this->get(route('feed.index'))
        ->assertOk()
        ->assertSee('palette-search-everything', false);
});

/*
|--------------------------------------------------------------------------
| Remotes — the one private surface
|--------------------------------------------------------------------------
|
| Everything else on this page is published work, so a bad query costs a
| reader nothing. A remote is different: the results are somebody's private
| messages, and the only thing separating a term from them is the membership
| check. That guard is what these tests pin, along with the two shape
| decisions that follow from it — one row per conversation, and no section at
| all for a guest.
|
*/

test('a signed-in reader finds their own pushes and lands on the thread', function (): void {
    $reader = User::factory()->create(['handle' => 'reader']);
    $other = User::factory()->create(['handle' => 'other']);

    $remote = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $remote->participants()->attach([$reader->id, $other->id]);

    RemoteMessage::query()->create([
        'remote_id' => $remote->id,
        'user_id' => $other->id,
        'body' => 'The debounce was in the accessor all along.',
    ]);

    actingAs($reader);

    $component = Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->assertSee('The debounce was in the accessor all along.');

    $section = $component->instance()->sections[0];

    expect($section['key'])->toBe('remotes')
        // In a direct thread the title is the other person, so the eyebrow
        // stays empty rather than printing the same name twice.
        ->and($section['rows'][0]['title'])->toBe($other->name)
        ->and($section['rows'][0]['eyebrow'])->toBeNull()
        ->and($section['rows'][0]['href'])->toBe(route('remotes.index', ['remote' => $remote->id]));
});

test('a group names who spoke, because the title does not', function (): void {
    $reader = User::factory()->create(['handle' => 'reader']);
    $other = User::factory()->create(['handle' => 'other']);

    $group = Remote::query()->create(['kind' => 'group', 'title' => 'Platform', 'created_by' => $other->id]);
    $group->participants()->attach([$reader->id, $other->id]);

    RemoteMessage::query()->create([
        'remote_id' => $group->id,
        'user_id' => $other->id,
        'body' => 'The debounce belongs in the trait.',
    ]);

    actingAs($reader);

    $section = Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->instance()
        ->sections[0];

    expect($section['rows'][0]['title'])->toBe('Platform')
        ->and($section['rows'][0]['eyebrow'])->toBe($other->name);
});

test('a push in somebody else\'s remote never reaches the results', function (): void {
    $reader = User::factory()->create(['handle' => 'reader']);
    $stranger = User::factory()->create(['handle' => 'stranger']);
    $other = User::factory()->create(['handle' => 'other']);

    $theirs = Remote::query()->create(['kind' => 'direct', 'created_by' => $stranger->id]);
    $theirs->participants()->attach([$stranger->id, $other->id]);

    RemoteMessage::query()->create([
        'remote_id' => $theirs->id,
        'user_id' => $stranger->id,
        'body' => 'A private debounce note that is not yours.',
    ]);

    actingAs($reader);

    Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->assertDontSee('A private debounce note that is not yours.')
        ->assertSee('Nothing matches this term.');
});

test('a guest gets no remotes section, only the published surfaces', function (): void {
    $reader = User::factory()->create(['handle' => 'reader']);
    $other = User::factory()->create(['handle' => 'other']);

    $remote = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $remote->participants()->attach([$reader->id, $other->id]);

    RemoteMessage::query()->create([
        'remote_id' => $remote->id,
        'user_id' => $other->id,
        'body' => 'A debounce note behind a sign-in.',
    ]);

    // No actingAs: the page is public, the messages are not.
    Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->assertDontSee('A debounce note behind a sign-in.')
        ->assertSee('Nothing matches this term.');
});

test('several hits in one conversation are one row, and the count is conversations', function (): void {
    $reader = User::factory()->create(['handle' => 'reader']);
    $other = User::factory()->create(['handle' => 'other']);

    $first = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $first->participants()->attach([$reader->id, $other->id]);

    $second = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $second->participants()->attach([$reader->id, $other->id]);

    foreach (['one debounce', 'two debounce', 'three debounce'] as $body) {
        RemoteMessage::query()->create([
            'remote_id' => $first->id,
            'user_id' => $other->id,
            'body' => $body,
        ]);
    }

    RemoteMessage::query()->create([
        'remote_id' => $second->id,
        'user_id' => $other->id,
        'body' => 'four debounce',
    ]);

    actingAs($reader);

    $section = Livewire::test('pages::search')
        ->set('q', 'debounce')
        ->instance()
        ->sections[0];

    expect($section['key'])->toBe('remotes')
        ->and($section['count'])->toBe(2)
        ->and($section['rows'])->toHaveCount(2)
        // "See all" carries the term, so the list it opens is the one that
        // matched rather than the whole inbox.
        ->and($section['href'])->toBe(route('remotes.index', ['q' => 'debounce']))
        // The newest matching push previews its thread, not the first one.
        ->and($section['rows'][0]['description'])->toContain('four debounce');
});

test('a term made of wildcards matches literally instead of everything', function (): void {
    $reader = User::factory()->create(['handle' => 'reader']);
    $other = User::factory()->create(['handle' => 'other']);

    $remote = Remote::query()->create(['kind' => 'direct', 'created_by' => $other->id]);
    $remote->participants()->attach([$reader->id, $other->id]);

    RemoteMessage::query()->create([
        'remote_id' => $remote->id,
        'user_id' => $other->id,
        'body' => 'An ordinary sentence with no wildcards.',
    ]);

    actingAs($reader);

    // Unescaped, `%%` is "match anything": every push would come back.
    Livewire::test('pages::search')
        ->set('q', '%%')
        ->assertDontSee('An ordinary sentence with no wildcards.');
});
