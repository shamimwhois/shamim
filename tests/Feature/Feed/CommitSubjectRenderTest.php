<?php

use App\Actions\Feed\ComposeFeedPost;
use App\Actions\Feed\UpdateFeedPost;
use App\Models\FeedPost;
use App\Models\User;
use App\Support\LogRow;
use Database\Seeders\FeedPostSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The reported bug, on the surface the reader actually sees.
 *
 * `CommitSubjectTest` proves the derivation rule in isolation. This proves the
 * consequence: a post written through the real composer, rendered through the
 * real row builder, must not show its opening sentence twice — and a post whose
 * headline was authored rather than derived must keep every line of its body.
 *
 * The unit test cannot make either claim. The lift needs a stored row and the
 * rendered card, and the authored case needs a headline that the body does not
 * repeat, which only a real corpus-shaped row provides.
 */
beforeEach(function (): void {
    $this->seed(FeedPostSeeder::class);

    $this->reader = User::factory()->create(['handle' => 'reader', 'name' => 'Reader One']);
});

/**
 * The rendered `<article>` for one post, sliced out of the whole feed page.
 *
 * A feed page carries twenty cards, so a bare `substr_count` over the response
 * cannot say *which* card printed the sentence. Each card is one `<article
 * class="dn-commit" data-permalink="…">`, and cards do not nest, so slicing
 * from one opening tag to the next is exact.
 *
 * `$visible` strips `<script>` blocks and tags first. The card carries Alpine
 * payloads — the share button embeds the headline so `navigator.share` can
 * offer a title — and a headline quoted in a script is not a second printing
 * of it on screen. Counting raw markup would report a correctly-rendered post
 * as duplicated.
 */
function cardFor(string $html, string $slug, bool $visible = true): string
{
    $start = strpos($html, 'data-permalink="'.url('/feed/'.$slug).'"');

    if ($start === false) {
        return '';
    }

    $open = strrpos(substr($html, 0, $start), '<article');
    $next = strpos($html, '<article', $start);

    if ($open === false) {
        return '';
    }

    $card = substr($html, $open, $next === false ? null : $next - $open);

    if (! $visible) {
        return $card;
    }

    return strip_tags((string) preg_replace('/<script\b.*?<\/script>/i', '', $card));
}

it('renders the opening sentence once on a real card', function (): void {
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => 'Which queue backend do you run?',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($post)->toBeInstanceOf(FeedPost::class);

    $row = LogRow::make($post);

    expect($row['title'])->toBe('Which queue backend do you run?');
    expect(substr_count((string) $row['body'], 'Which queue backend do you run?'))
        ->toBe(0, 'the body must not repeat the headline the card already printed');
});

it('prints the sentence once across the whole card', function (): void {
    /* The reported bug, counted on the surface the reader sees. Scoped to this
       post's own visible card: the reader is also the author, so the composer
       sits above the feed holding the text they just typed and a page-wide
       count would include it. */
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => 'Which queue backend do you run?',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    $html = (string) actingAs($this->reader)->get('/feed')->assertOk()->getContent();

    expect(substr_count(cardFor($html, $post->slug), 'Which queue backend do you run?'))
        ->toBe(1, 'the card must print the opening sentence exactly once');
});

it('renders no body box at all for a one-line post', function (): void {
    /* The lift leaves nothing under the subject, and the card must not then
       reserve an empty paragraph-sized box for it. Raw markup, because the
       claim is about a wrapper element rather than about text. */
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => 'Which queue backend do you run?',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    $html = (string) actingAs($this->reader)->get('/feed')->assertOk()->getContent();

    $card = cardFor($html, $post->slug, visible: false);

    expect($card)->not->toBe('', 'the post must appear on the feed');
    expect($card)->toContain('dn-commit-subject');

    /* One needle only, twice over. `toContain` is variadic — a second argument
       would be a second needle, not a message — and under `not` a description
       makes the assertion vacuous, since the card legitimately does not contain
       the description. The same holds for a second `toContain` needle. */
    expect($card)->not->toContain('class="dn-body-wrap"');
});

it('keeps every line of an authored headline post', function (): void {
    /* The half a naive "does the body contain the title" rule gets wrong. A
       corpus post's headline is a separate field its body never repeats, so a
       lift keyed on absence would eat the first paragraph. It must be keyed on
       the derivation instead: re-deriving from this body yields the body's own
       opening words, which is not the stored headline, so nothing is lifted. */
    $post = FeedPost::factory()->create([
        'user_id' => $this->reader->id,
        'handle' => '@reader',
        'author' => 'Reader One',
        'title' => 'Queue backends',
        'body' => "First paragraph.\n\nSecond paragraph.",
    ]);

    expect($post->bodyUnderHeadline())->toBe("First paragraph.\n\nSecond paragraph.");

    $row = LogRow::make($post);

    expect($row['title'])->toBe('Queue backends');
    expect(trim(strip_tags((string) $row['body'])))->toBe("First paragraph.\n\nSecond paragraph.");
});

it('keeps the whole opening line when the title is only its prefix', function (): void {
    /* The case that separates the two rules, found in the seeded corpus.

       An authored headline is capped by `Str::limit` while the body is not, so a
       post whose title *is* its opening line truncated can leave a body whose
       first line merely **starts with** the title — `…TypeScript 5.4` against
       `…TypeScript 5.4. Preserves the original…`. The naive containment rule
       sees the title inside the body, lifts the line, and deletes a whole
       paragraph. Re-deriving answers the real question — the body alone derives
       `…TypeScript 5.4. Preserves…`, which is not the stored title — so nothing
       is lifted and every word survives. */
    $post = FeedPost::factory()->create([
        'user_id' => $this->reader->id,
        'handle' => '@reader',
        'author' => 'Reader One',
        'title' => 'Zero-runtime deep immutable patch utility in TypeScript 5.4',
        'body' => 'Zero-runtime deep immutable patch utility in TypeScript 5.4. Preserves the original object.',
    ]);

    expect($post->bodyUnderHeadline())
        ->toBe('Zero-runtime deep immutable patch utility in TypeScript 5.4. Preserves the original object.');

    $row = LogRow::make($post);

    expect($row['title'])->toBe('Zero-runtime deep immutable patch utility in TypeScript 5.4');
    expect(trim(strip_tags((string) $row['body'])))
        ->toBe('Zero-runtime deep immutable patch utility in TypeScript 5.4. Preserves the original object.');
});

it('does not lift a title that is a substring of a longer word', function (): void {
    /* The other shape from the corpus: the title is a prefix of a *token*, not
       of the line — `dbg-1793485097` against `dbg-1793485097-ed`. Containment
       matches; the derivation does not, because the body's opening line is the
       longer string. Nothing is lifted. */
    $post = FeedPost::factory()->create([
        'user_id' => $this->reader->id,
        'handle' => '@reader',
        'author' => 'Reader One',
        'title' => 'dbg-1793485097',
        'body' => 'dbg-1793485097-ed',
    ]);

    expect($post->bodyUnderHeadline())->toBe('dbg-1793485097-ed');

    $row = LogRow::make($post);

    expect($row['title'])->toBe('dbg-1793485097');
    expect(trim(strip_tags((string) $row['body'])))->toBe('dbg-1793485097-ed');
});

it('lifts only the line the headline was derived from', function (): void {
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => "Hot take on queues.\n\nRedis is fine for this.",
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($post->title)->toBe('Hot take on queues.');
    expect($post->body)->toBe("Hot take on queues.\n\nRedis is fine for this.");

    $row = LogRow::make($post);

    expect(trim(strip_tags((string) $row['body'])))->toBe('Redis is fine for this.');
});

it('opens the editor holding the text the author typed', function (): void {
    /* The reason storage keeps the body whole. `body` is what the form opens
       on, so a one-line post whose body had been emptied by the lift would
       present a blank editor and saving would erase the post. */
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => 'Which queue backend do you run?',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($post->body)->toBe('Which queue backend do you run?');
    expect($post->bodyUnderHeadline())->toBe('');

    Livewire::actingAs($this->reader)->test('pages::feed')
        ->call('openEdit', $post->id)
        ->assertSet('editDraft', 'Which queue backend do you run?');
});

it('keeps the headline when only the body is edited', function (): void {
    /* The editor edits the body — its label says so — so a body-only edit must
       not rewrite the subject. Re-deriving here would replace a derived
       headline with the new text while the old one is what the reader was
       looking at, and the card's subject and body would describe one post each. */
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => 'Which queue backend do you run?',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    app(UpdateFeedPost::class)->handle($this->reader, [
        'body' => 'Redis, actually.',
        'title' => $post->title ?: null,
        'type' => $post->type,
        'topic' => $post->topic,
        'tags' => array_values($post->tags ?? []),
    ], $post);

    $post->refresh();

    expect($post->title)->toBe('Which queue backend do you run?');
    expect($post->body)->toBe('Redis, actually.');

    // And the card shows the headline once, above a body that no longer holds it.
    $row = LogRow::make($post);

    expect($row['title'])->toBe('Which queue backend do you run?');
    expect(trim(strip_tags((string) $row['body'])))->toBe('Redis, actually.');
});

it('shows the corrected body in the row without repeating the headline', function (): void {
    $post = app(ComposeFeedPost::class)->handle($this->reader, [
        'body' => "Hot take on queues.\n\nRedis is fine for this.",
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    Livewire::actingAs($this->reader)->test('pages::feed')
        ->call('openEdit', $post->id)
        ->set('editDraft', 'A corrected body.')
        ->call('saveEdit');

    $post->refresh();

    expect($post->body)->toBe('A corrected body.');

    $row = LogRow::make($post);

    expect($row['title'])->toBe('Hot take on queues.');
    expect(trim(strip_tags((string) $row['body'])))->toBe('A corrected body.');
});
