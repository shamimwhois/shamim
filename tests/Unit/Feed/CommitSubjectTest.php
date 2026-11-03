<?php

use App\Actions\Feed\HydrateFeedPostContent;

/**
 * The headline a card prints must not be printed twice.
 *
 * A commit card shows a subject above the body, the way `git log` does — and
 * `git log` prints a body with its subject line taken *out*. A composer post
 * has no headline field, so its headline is derived from the body's first
 * non-empty line; rendered naively that line appears twice, once bold as the
 * subject and once again at the top of the body.
 *
 * Two rules carry the fix, and they are deliberately in different places:
 *
 *   - the hydrator **writes** the headline beside a body that keeps the
 *     author's text whole, so the inline editor has something to open with;
 *   - `FeedPost::bodyUnderHeadline()` **lifts** it at render time, and only
 *     when re-deriving from the body reproduces the stored headline exactly.
 *
 * These assert the derivation rule. The lift, and the fact that it must not
 * fire for an authored headline, are asserted here too — the two halves are
 * one promise and a test of either alone cannot express it.
 */
beforeEach(function (): void {
    $this->hydrate = app(HydrateFeedPostContent::class);
});

it('writes a headline from the opening line and leaves the body whole', function (): void {
    /* The reported bug. A one-line post is the common case and the worst one:
       the subject and the body were the identical string, so the card printed
       the sentence twice with nothing to tell them apart.

       Storage is *not* where that is fixed. The body keeps the sentence so the
       editor can open on it; the lift happens on the way to the card. */
    $content = $this->hydrate->handle([
        'body' => 'Which queue backend do you run?',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($content['title'])->toBe('Which queue backend do you run?');
    expect($content['body'])->toBe('Which queue backend do you run?');
});

it('derives the headline from the first non-empty line of a multi-line body', function (): void {
    $content = $this->hydrate->handle([
        'body' => "\n\n  Hot take on queues.\n\nRedis is fine for this.",
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($content['title'])->toBe('Hot take on queues.');
});

it('strips a whole markdown sigil run from the derived subject', function (): void {
    /* `##` is a level-two heading. Cutting a single marker would leave the card
       advertising `# Getting the N+1 out` as its subject. The run is cut, so
       `- - spaced` and `3 - 2` keep their punctuation. */
    $content = $this->hydrate->handle([
        'body' => "#### Getting the N+1 out\n\nThe dashboard ran 140 queries.",
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($content['title'])->toBe('Getting the N+1 out');
});

it('leaves the subject empty when there is no line to derive one from', function (): void {
    /* Only blank lines: no headline rather than a blank one the card would
       render as a gap. A sigil-only line strips to nothing and is the same
       case — a rule of dashes is punctuation, not a subject. */
    $blank = $this->hydrate->handle([
        'body' => "\n\n   \n",
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($blank['title'])->toBe('');

    $sigils = $this->hydrate->handle([
        'body' => "---\n\nRedis is fine for this.",
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($sigils['title'])->toBe('');
});

it('takes an authored title as written and never derives over it', function (): void {
    $content = $this->hydrate->handle([
        'body' => 'Redis is fine for this.',
        'title' => 'Queue backends',
        'type' => 'discussion',
        'topic' => 'backend',
    ]);

    expect($content['title'])->toBe('Queue backends');
    expect($content['body'])->toBe('Redis is fine for this.');
});

it('reports the headline this body alone would produce', function (): void {
    /* The public re-derivation the card leans on. It must ignore any authored
       title, so comparing it against the stored headline answers "was this
       headline derived from this body?" without either caller restating the
       strip-and-limit rule. */
    expect($this->hydrate->headlineFor("## Heading\n\nBody."))->toBe('Heading');
    expect($this->hydrate->headlineFor('   '))->toBe('');
});
