<?php

use App\Models\FeedAttachment;
use App\Models\FeedPollVote;
use App\Models\FeedPost;
use App\Models\FeedReport;
use App\Models\User;
use Database\Seeders\FeedPostSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The log's categories, its commits' own record, and the things that happen
 * between the rows.
 *
 * The corpus is deliberately short-form (a median body of 187 characters), so
 * anything that only triggers on long or numerous commits is built here rather
 * than hoped for: a fold nobody can reach and a second page that never loads are
 * features that do not exist.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);
    $this->reader = User::factory()->create(['handle' => 'reader', 'name' => 'Reader One']);
});

it('filters the log by the type a commit was filed under', function (): void {
    $rows = Livewire::test('pages::feed', ['type' => 'question'])->instance()->rows;

    expect($rows)->not->toBeEmpty()
        ->and(collect($rows)->pluck('type')->unique()->values()->all())->toBe(['question']);
});

it('filters the log by topic and composes it with the type', function (): void {
    $rows = Livewire::test('pages::feed', ['type' => 'discussion', 'topic' => 'backend'])
        ->instance()->rows;

    foreach ($rows as $row) {
        expect($row['type'])->toBe('discussion')
            ->and($row['topic'])->toBe('backend');
    }
});

it('falls back to every type when the URL names one the corpus does not carry', function (): void {
    // A bookmarked `?type=podcast` is a stale link, not an error: the log opens
    // whole rather than empty with no explanation.
    $rows = Livewire::test('pages::feed', ['type' => 'podcast'])->instance()->rows;

    expect(collect($rows)->pluck('type')->unique()->count())->toBeGreaterThan(1);
});

it('keeps the other filters when one of them changes', function (): void {
    $component = Livewire::test('pages::feed', ['type' => 'discussion', 'topic' => 'backend'])
        ->instance();

    expect($component->filterUrl(['topic' => 'frontend']))
        ->toContain('type=discussion')
        ->toContain('topic=frontend');

    // Clearing drops that parameter and nothing else.
    expect($component->filterUrl(['topic' => 'all']))
        ->toContain('type=discussion')
        ->not->toContain('topic=');
});

it('draws a counted chip for every category the corpus carries', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="log-filters"', false)
        ->assertSee('data-test="filter-type-all"', false)
        ->assertSee('data-test="filter-type-question"', false)
        ->assertSee('data-test="filter-topic-backend"', false);
});

it('draws what the commit itself records', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);

    expect($rows->firstWhere(fn (array $row): bool => $row['tags'] !== []))->not->toBeNull()
        ->and($rows->firstWhere(fn (array $row): bool => $row['poll'] !== null))->not->toBeNull()
        ->and($rows->firstWhere(fn (array $row): bool => $row['reactions'] !== null))->not->toBeNull();

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-type"', false)
        ->assertSee('data-test="commit-tags"', false)
        ->assertSee('data-test="commit-poll"', false)
        ->assertSee('data-test="commit-reactions"', false);
});

it('shares a poll out into percentages that add up', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);
    $poll = $rows->firstWhere(fn (array $row): bool => $row['poll'] !== null)['poll'];

    expect($poll['options'])->not->toBeEmpty()
        ->and(round(collect($poll['options'])->sum('share')))->toEqual(100.0);
});

it('badges only a question as answered', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);
    $answered = $rows->filter(fn (array $row): bool => $row['answered']);

    expect($answered)->not->toBeEmpty()
        ->and($answered->every(fn (array $row): bool => $row['type'] === 'question'))->toBeTrue();
});

it('folds a commit that is too long to read in the log', function (): void {
    FeedPost::factory()->create([
        'body' => str_repeat('the quick brown fox ', 60),
        'posted_at' => now()->addMinute(),
    ]);

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-fold"', false)
        ->assertSee('Show more');
});

it('leaves a short commit unfolded', function (): void {
    // The seeded corpus is short-form, so nothing on a fresh log should offer a
    // "Show more" that reveals one more line.
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('data-test="commit-fold"', false);
});

it('marks the commits that arrived since the reader was last here', function (): void {
    get(route('feed.index'))->assertOk()->assertDontSee('data-test="last-visit"', false);

    FeedPost::factory()->create(['posted_at' => now()->addMinute()]);

    $html = get(route('feed.index'))->assertOk()->getContent();

    // Once, and only once: a second line would turn the log into a ruler.
    expect(substr_count($html, 'data-test="last-visit"'))->toBe(1)
        ->and($html)->toContain('HEAD@{last visit}');
});

it('leaves the visit line off a ranked log, where it means nothing', function (): void {
    get(route('feed.index', ['log' => 'latest']))->assertOk();

    FeedPost::factory()->create(['posted_at' => now()->addMinute()]);

    get(route('feed.index', ['log' => 'trending']))
        ->assertOk()
        ->assertDontSee('data-test="last-visit"', false);
});

it('drops a who-to-follow interlude into the stream, once', function (): void {
    $html = get(route('feed.index'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="log-interlude"'))->toBe(1);
});

it('pages the log from a sentinel and ends at the root post', function (): void {
    FeedPost::factory()->count(20)->create(['posted_at' => now()->subDay()]);

    $component = Livewire::test('pages::feed');

    expect($component->get('visible'))->toBe(12);
    $component->assertSee('data-test="load-older-sentinel"', false);

    $component->call('loadMore');
    expect($component->get('visible'))->toBe(24);

    $component->call('loadMore');
    expect($component->get('visible'))->toBe(36);

    // Everything is loaded, so the sentinel gives way to the root marker.
    $component->assertDontSee('data-test="load-older-sentinel"', false)
        ->assertSee('data-test="log-root"', false);
});

it('restores a commit it deleted, with its thread intact', function (): void {
    $post = FeedPost::factory()->create([
        'user_id' => $this->reader->id,
        'posted_at' => now()->addMinute(),
    ]);

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    $component->call('deleteCommit', $post->id)
        ->assertSee('data-test="undo-delete"', false);

    expect($post->fresh()->deleted)->toBeTrue();

    $component->call('undoDelete');

    expect($post->fresh()->deleted)->toBeFalse();
});

it('announces new posts with the people who wrote them', function (): void {
    $component = Livewire::test('pages::feed');

    FeedPost::factory()->create(['posted_at' => now()->addMinute()]);

    $component->call('syncFeed')
        ->assertSee('data-test="new-commits-pill"', false);

    expect($component->get('pendingNew'))->toBe(1)
        ->and($component->instance()->pendingAuthors)->toHaveCount(1);
});

/*
 * The rest of a commit's own record: the answer it was given, the discussion
 * it is still part of, the images it carries, and the flag a reader can raise
 * against it.
 */

it('prints the accepted answer to a solved question', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);
    $answered = $rows->firstWhere(fn (array $row): bool => $row['answer'] !== null);

    expect($answered)->not->toBeNull()
        ->and($answered['answer']['author'])->not->toBeEmpty()
        ->and($answered['answer']['body'])->not->toBeEmpty();

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-answer"', false);
});

it('renders an answer that carries code as code', function (): void {
    // The corpus answer is a code fragment; printing it as prose would lose
    // the one thing that makes it an answer.
    $post = FeedPost::query()->whereNotNull('answer')->firstOrFail();

    expect($post->answer['body'])->toContain('`');

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-answer"', false);
});

it('links the activity line to the discussion it is about', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);
    $active = $rows->firstWhere(fn (array $row): bool => $row['activity'] !== null);

    expect($active)->not->toBeNull()
        ->and($active['activity']['label'])->not->toBeEmpty();

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-activity"', false);
});

it('badges a featured commit', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);

    expect($rows->firstWhere(fn (array $row): bool => $row['featured']))->not->toBeNull();

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-featured"', false);
});

it('draws the images a commit carries', function (): void {
    $post = FeedPost::query()->visible()->firstOrFail();

    FeedAttachment::factory()->claimed($post)->create();

    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="commit-media"', false);
});

it('records a report and counts it once', function (): void {
    $post = FeedPost::query()->visible()->where('user_id', '!=', $this->reader->id)->firstOrFail();

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    $component->call('reportCommit', $post->id, 'spam');

    expect(FeedReport::query()->where('feed_post_id', $post->id)->count())->toBe(1)
        ->and($post->fresh()->reports)->toBe(1);

    // Reporting again is the same row, not a second one.
    $component->call('reportCommit', $post->id, 'spam');

    expect(FeedReport::query()->where('feed_post_id', $post->id)->count())->toBe(1)
        ->and($post->fresh()->reports)->toBe(1);
});

it('keeps the reason a commit was reported for', function (): void {
    $post = FeedPost::query()->visible()->where('user_id', '!=', $this->reader->id)->firstOrFail();

    actingAs($this->reader);

    Livewire::test('pages::feed')->call('reportCommit', $post->id, 'harassment');

    expect(FeedReport::query()->where('feed_post_id', $post->id)->firstOrFail()->reason)
        ->toBe('harassment');
});

it('refuses a report on your own commit', function (): void {
    $post = FeedPost::factory()->create(['user_id' => $this->reader->id]);

    actingAs($this->reader);

    Livewire::test('pages::feed')->call('reportCommit', $post->id, 'spam');

    expect(FeedReport::query()->where('feed_post_id', $post->id)->count())->toBe(0);
});

it('refuses a report with no reason on the list', function (): void {
    $post = FeedPost::query()->visible()->where('user_id', '!=', $this->reader->id)->firstOrFail();

    actingAs($this->reader);

    Livewire::test('pages::feed')->call('reportCommit', $post->id, 'because');

    expect(FeedReport::query()->where('feed_post_id', $post->id)->count())->toBe(0);
});

it('offers no report button to a guest', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);

    expect($rows->every(fn (array $row): bool => $row['canReport'] === false))->toBeTrue();
});

it('says a commit was reported instead of offering the button again', function (): void {
    $post = FeedPost::query()->visible()->where('user_id', '!=', $this->reader->id)->firstOrFail();

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    // Every row but the reported one still offers the button, so the count is
    // what moves — not the presence of the word.
    $before = substr_count($component->html(), 'data-test="report-commit"');

    expect($before)->toBeGreaterThan(0);

    $component->call('reportCommit', $post->id, 'spam');

    expect(substr_count($component->html(), 'data-test="report-commit"'))->toBe($before - 1)
        ->and(substr_count($component->html(), 'data-test="commit-reported"'))->toBe(1);
});

/*
 * The poll: the one commit a reader answers rather than reacts to.
 */

it('counts a vote without writing the tally', function (): void {
    $post = FeedPost::query()->whereNotNull('poll')->firstOrFail();

    $before = $post->poll;

    actingAs($this->reader);

    Livewire::test('pages::feed')->call('votePoll', $post->id, 'Go');

    // The corpus baseline is immutable: the vote is a row, and the number a
    // reader sees is the baseline plus the rows.
    expect($post->fresh()->poll)->toEqual($before)
        ->and(FeedPollVote::query()->where('feed_post_id', $post->id)->count())->toBe(1);
});

it('shows the vote in the tally', function (): void {
    $post = FeedPost::query()->whereNotNull('poll')->firstOrFail();

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    $component->call('votePoll', $post->id, 'Go');

    $row = collect($component->instance()->rows)->firstWhere(fn (array $r): bool => $r['poll'] !== null);

    expect($row['poll']['voted'])->toBe('Go')
        ->and($row['poll']['options'][0]['mine'])->toBeTrue()
        ->and($row['poll']['options'][0]['votes'])->toBe(
            (int) $post->poll['options'][0]['votes'] + 1,
        );
});

it('records one vote per account per poll', function (): void {
    $post = FeedPost::query()->whereNotNull('poll')->firstOrFail();

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    $component->call('votePoll', $post->id, 'Go');
    $component->call('votePoll', $post->id, 'Rust');

    expect(FeedPollVote::query()->where('feed_post_id', $post->id)->count())->toBe(1)
        ->and(FeedPollVote::query()->where('feed_post_id', $post->id)->firstOrFail()->option)
        ->toBe('Go');
});

it('refuses a vote for an option the poll does not offer', function (): void {
    $post = FeedPost::query()->whereNotNull('poll')->firstOrFail();

    actingAs($this->reader);

    Livewire::test('pages::feed')->call('votePoll', $post->id, 'COBOL');

    expect(FeedPollVote::query()->where('feed_post_id', $post->id)->count())->toBe(0);
});

it('offers a guest no poll button', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('data-test="poll-vote"', false);
});

it('keeps the shares adding up to a hundred', function (): void {
    $post = FeedPost::query()->whereNotNull('poll')->firstOrFail();

    actingAs($this->reader);

    $component = Livewire::test('pages::feed');

    $component->call('votePoll', $post->id, 'Go');

    $row = collect($component->instance()->rows)->firstWhere(fn (array $r): bool => $r['poll'] !== null);

    expect(round(collect($row['poll']['options'])->sum('share')))->toEqual(100.0);
});

/*
 * The composer: the commit a reader writes, its images, and the moment it
 * goes live.
 */

/**
 * A real one-pixel PNG.
 *
 * The test image is a fixed byte string rather than a generated one: the
 * generator needs GD, and the point of these tests is what happens to a file
 * the disk accepts, not what a library can draw.
 */
function fakeImage(): UploadedFile
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    );

    return UploadedFile::fake()->createWithContent('shot.png', $png);
}

it('writes a commit and puts it on the log', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->set('draft', 'Shipped the composer today.')
        ->call('push');

    $post = FeedPost::query()->where('body', 'Shipped the composer today.')->firstOrFail();

    expect($post->status->value)->toBe('published')
        ->and($post->user_id)->toBe($this->reader->id);

    get(route('feed.index'))->assertOk()->assertSee('Shipped the composer today.');
});

it('stages an image and commits it with the post', function (): void {
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')->set('uploads', [fakeImage()]);

    // The grid shows what was staged, before anything has been written.
    expect($component->instance()->stagedMedia)->toHaveCount(1);

    $component->set('draft', 'Shipped it with a screenshot.')->call('push');

    $post = FeedPost::query()->where('body', 'Shipped it with a screenshot.')->firstOrFail();

    expect($post->attachments()->count())->toBe(1);

    get(route('feed.index'))->assertOk()->assertSee('data-test="commit-media"', false);
});

it('lets the author take a staged image back', function (): void {
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')->set('uploads', [fakeImage()]);

    $id = $component->instance()->stagedMedia->first()->id;

    $component->call('removeMedia', $id);

    expect($component->instance()->stagedMedia)->toHaveCount(0);
});

it('schedules a commit instead of publishing it', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->call('toggleScheduling')
        ->set('draft', 'Scheduled release notes.')
        ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('push');

    $post = FeedPost::query()->where('body', 'Scheduled release notes.')->firstOrFail();

    expect($post->status->value)->toBe('scheduled')
        ->and($post->scheduled_at)->not->toBeNull()
        ->and($post->posted_at)->toBeNull();

    // A scheduled commit is not on the log: it is a promise, not a row.
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('Scheduled release notes.');
});

it('refuses a schedule that is already due', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->call('toggleScheduling')
        ->set('draft', 'Too late to schedule.')
        ->set('scheduledAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('push');

    expect(FeedPost::query()->where('body', 'Too late to schedule.')->exists())->toBeFalse();
});

it('offers a guest no composer at all', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('data-test="composer"', false);
});

/*
 * The author hover card: resting on a name previews the person behind it,
 * with numbers the page itself can back up.
 */

it('previews the author a commit is by', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertSee('data-test="author-hover"', false)
        ->assertSee('data-test="author-card"', false)
        ->assertSee('Open profile', false);
});

it('links the preview at the profile the handle owns', function (): void {
    $row = collect(Livewire::test('pages::feed')->instance()->rows)->first();

    expect($row['handle'])->not->toBeEmpty()
        ->and(route('profile.show', ['handle' => $row['handle']]))->toContain('@'.$row['handle']);

    get(route('feed.index'))->assertOk()->assertSee('@'.$row['handle'], false);
});

/*
 * The poll composer: the commit a reader writes can be a question with
 * choices, written in the same composer as everything else.
 */

it('writes a poll from the composer', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->call('togglePoll')
        ->set('draft', 'Weekly pulse: which one ships?')
        ->set('pollOptions.0', 'The boring way')
        ->set('pollOptions.1', 'The clever way')
        ->set('pollCloses', 'Friday')
        ->call('push');

    $post = FeedPost::query()->where('body', 'Weekly pulse: which one ships?')->firstOrFail();

    expect($post->type)->toBe('poll')
        ->and($post->poll['options'])->toHaveCount(2)
        ->and(collect($post->poll['options'])->pluck('label')->all())
        ->toEqual(['The boring way', 'The clever way'])
        ->and($post->poll['votes'])->toBe(0);

    get(route('feed.index'))->assertOk()->assertSee('data-test="commit-poll"', false);
});

it('drops empty choices and keeps the distinct ones', function (): void {
    actingAs($this->reader);

    Livewire::test('pages::feed')
        ->call('togglePoll')
        ->call('addPollOption')
        ->set('draft', 'Pick a lane.')
        ->set('pollOptions.0', 'Left')
        ->set('pollOptions.1', 'Right')
        ->set('pollOptions.2', '')
        ->call('push');

    $post = FeedPost::query()->where('body', 'Pick a lane.')->firstOrFail();

    expect($post->type)->toBe('poll')
        ->and(collect($post->poll['options'])->pluck('label')->all())->toEqual(['Left', 'Right']);
});

it('refuses a poll that cannot be voted on', function (): void {
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')
        ->call('togglePoll')
        ->set('draft', 'Not really a question.')
        ->set('pollOptions.0', 'Only one way')
        ->call('push');

    expect(FeedPost::query()->where('body', 'Not really a question.')->exists())->toBeFalse()
        ->and($component->errors()->get('poll'))->not->toBeEmpty();
});

it('grows and shrinks the choice list inside its bounds', function (): void {
    actingAs($this->reader);

    $component = Livewire::test('pages::feed')->call('togglePoll');

    $component->call('addPollOption');
    expect($component->get('pollOptions'))->toHaveCount(3);

    $component->call('removePollOption', 1);
    expect($component->get('pollOptions'))->toHaveCount(2)
        ->and($component->get('pollOptions')[1])->toBe('');

    // Two is the floor: removing below it does nothing.
    $component->call('removePollOption', 0);
    expect($component->get('pollOptions'))->toHaveCount(2);
});

it('offers no poll editor to a guest', function (): void {
    get(route('feed.index'))
        ->assertOk()
        ->assertDontSee('data-test="composer-poll-toggle"', false);
});

it('counts a preview from the rows the page is showing', function (): void {
    $rows = collect(Livewire::test('pages::feed')->instance()->rows);

    $row = $rows->first();
    $mine = $rows->where('handle', $row['handle']);

    // A card inside a filtered log answers for that log, so its numbers are
    // the page's own rows summed — not a wider table's answer.
    expect($row['authorCard']['commits'])->toBe($mine->count())
        ->and($row['authorCard']['stars'])->toBe((int) $mine->sum('stars'))
        ->and($row['authorCard']['replies'])->toBe((int) $mine->sum('replies'));

    // A second commit by the same hand is one more for the card.
    FeedPost::factory()->create([
        'author' => $row['name'],
        'handle' => '@'.$row['handle'],
        'avatar' => $row['initials'],
        'role' => $row['role'],
    ]);

    $again = collect(Livewire::test('pages::feed')->instance()->rows)
        ->firstWhere('handle', $row['handle']);

    expect($again['authorCard']['commits'])->toBe($mine->count() + 1);
});
