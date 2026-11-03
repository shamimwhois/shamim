<?php

use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\FeedStar;
use App\Models\User;
use App\Support\CommitBody;
use App\Support\FeedCorpus;
use Database\Seeders\FeedPostSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The Log — the /feed surface.
 *
 * The corpus is seeded from {@see FeedCorpus} exactly as a deploy
 * would, so these tests render the same rows production does: migration rows,
 * not a static array. Writes are asserted against the tables, because the
 * surface's claims (a star count, a reply count) are row counts and nothing
 * else.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
});

it('renders the log as a graph of commits', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="log"', false)
        ->assertSee('data-test="commit"', false)
        ->assertSee('data-test="log-root"', false)
        ->assertSee("That's the root post. There's nothing before it.")
        ->assertSee('Following')
        ->assertSee('Latest')
        ->assertSee('Trending');
});

it('shows the three logs as tabs, with the current one marked', function (): void {
    get(route('feed.index', ['log' => 'trending']))
        ->assertOk()
        ->assertSee('data-test="log-tab-trending"', false)
        ->assertSee('aria-current="page"', false);
});

it('falls back to the latest log when the URL names an unknown one', function (): void {
    get(route('feed.index', ['log' => 'nonsense']))
        ->assertOk()
        ->assertSee('data-test="commit"', false);
});

it('orders the latest log newest first', function (): void {
    $component = Livewire::test('pages::feed', ['log' => 'latest']);

    $rows = $component->instance()->rows;
    $times = array_map(fn (array $row): string => $row['iso'], $rows);

    expect($times)->toBe(collect($times)->sortDesc()->values()->all());
});

it('ranks the trending log by its engagement', function (): void {
    $latest = Livewire::test('pages::feed', ['log' => 'latest'])->instance()->rows;
    $target = $latest[1]['id'];

    $readers = User::factory()->count(60)->create();

    foreach ($readers as $reader) {
        FeedStar::query()->create([
            'feed_post_id' => $target,
            'user_id' => $reader->id,
        ]);
    }

    $rows = Livewire::test('pages::feed', ['log' => 'trending'])->instance()->rows;

    expect($rows[0]['id'])->toBe($target)
        ->and($rows[0]['stars'])->toBe(60);
});

it('gives a guest an honest empty following log', function (): void {
    get(route('feed.index', ['log' => 'following']))
        ->assertOk()
        ->assertSee('fatal: your log does not have any posts yet')
        ->assertSee('data-test="log-empty"', false);
});

it('hides the composer from a guest and shows it to a signed-in reader', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('data-test="composer"', false);

    actingAs(User::factory()->create());

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="composer"', false)
        ->assertSee('What did you ship?');
});

it('stars a commit once per account', function (): void {
    $reader = User::factory()->create();
    $post = FeedPost::query()->visible()->firstOrFail();

    actingAs($reader);

    Livewire::test('pages::feed')->call('toggleStar', $post->id);

    expect(FeedStar::query()->where('feed_post_id', $post->id)->count())->toBe(1)
        ->and(FeedStar::query()->where('user_id', $reader->id)->count())->toBe(1);
});

it('unstars a commit when it is starred twice', function (): void {
    $reader = User::factory()->create();
    $post = FeedPost::query()->visible()->firstOrFail();

    actingAs($reader);

    $component = Livewire::test('pages::feed');
    $component->call('toggleStar', $post->id);
    $component->call('toggleStar', $post->id);

    expect(FeedStar::query()->count())->toBe(0);
});

it('sends a guest to sign in instead of starring', function (): void {
    $post = FeedPost::query()->visible()->firstOrFail();

    Livewire::test('pages::feed')
        ->call('toggleStar', $post->id)
        ->assertRedirect(route('feed.index', ['signin' => 'required']));

    expect(FeedStar::query()->count())->toBe(0);
});

it('publishes a commit from the composer', function (): void {
    $reader = User::factory()->create();

    actingAs($reader);

    Livewire::test('pages::feed')
        ->set('draft', 'Shipped the log rail today.')
        ->call('push')
        ->assertSet('draft', '');

    $post = FeedPost::query()->where('body', 'Shipped the log rail today.')->first();

    expect($post)->not->toBeNull()
        ->and($post->user_id)->toBe($reader->id)
        ->and($post->status->value)->toBe('published');
});

it('refuses an empty commit', function (): void {
    actingAs(User::factory()->create());

    Livewire::test('pages::feed')
        ->set('draft', '')
        ->call('push')
        ->assertHasErrors('draft');
});

it('adds a reply to a thread', function (): void {
    $reader = User::factory()->create();
    $post = FeedPost::query()->visible()->firstOrFail();

    actingAs($reader);

    Livewire::test('pages::feed')
        ->call('openReply', $post->id)
        ->assertSet('threadId', $post->id)
        ->set('replyDraft', 'Same here.')
        ->call('postReply', $post->id)
        ->assertSet('replyTo', null);

    expect(FeedReply::query()->where('feed_post_id', $post->id)->count())->toBe(1);
});

it('removes a commit its author deleted from the log', function (): void {
    $reader = User::factory()->create();
    $post = FeedPost::query()->visible()->where('user_id', $reader->id)->first()
        ?? FeedPost::query()->visible()->firstOrFail();

    $post->forceFill(['user_id' => $reader->id])->save();

    actingAs($reader);

    Livewire::test('pages::feed')->call('deleteCommit', $post->id);

    expect(FeedPost::query()->visible()->whereKey($post->id)->exists())->toBeFalse();
});

it('will not delete a commit somebody else wrote', function (): void {
    $reader = User::factory()->create();
    $post = FeedPost::query()->visible()->where('user_id', '!=', $reader->id)->firstOrFail();

    actingAs($reader);

    Livewire::test('pages::feed')->call('deleteCommit', $post->id);

    expect(FeedPost::query()->visible()->whereKey($post->id)->exists())->toBeTrue();
});

it('offers developers in the palette, filtered by the query', function (): void {
    $component = Livewire::test('pages::feed');

    $all = $component->instance()->activeAuthors;
    $first = $all->first();

    $component->set('search', $first['handle']);

    expect($component->instance()->results)->toHaveCount(1)
        ->and($component->instance()->results[0]['handle'])->toBe($first['handle']);
});

it('filters the log by a text query', function (): void {
    $post = FeedPost::query()->visible()->firstOrFail();

    $component = Livewire::test('pages::feed', ['q' => mb_substr($post->handle, 0, 4)]);

    expect($component->instance()->rows)->not->toBeEmpty();
});

it('renders a commit body with escaped HTML and linked handles', function (): void {
    $html = CommitBody::toHtml("Hello <script>alert(1)</script> @shamimstack #laravel\n\n```php\necho 'hi';\n```");

    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('dn-mention')
        ->and($html)->toContain('<pre class="dn-code">');
});

it('derives a stable seven-character short hash from the slug', function (): void {
    expect(CommitBody::shortHash('the-log'))->toBe(CommitBody::shortHash('the-log'))
        ->and(strlen(CommitBody::shortHash('the-log')))->toBe(7);
});
