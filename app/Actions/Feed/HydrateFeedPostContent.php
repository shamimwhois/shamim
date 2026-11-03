<?php

namespace App\Actions\Feed;

use App\Actions\Posts\HydratePostContent;
use Illuminate\Support\Str;

/**
 * Shared persistence logic for the feed's own content columns.
 *
 * The sibling of {@see HydratePostContent}, and deliberately
 * narrower: a feed card renders `body` escaped, so nothing here produces HTML.
 * Markdown stays the blog's concern — `App\Support\MarkdownRenderer` is not an
 * input to a card, and inventing a `body_html` column the theme never reads
 * would be a second source of truth for text that is already stored once.
 *
 * What it does own is the arithmetic every card prints but nobody types: the
 * headline a composer left blank, the tags it never named, the topic it did not
 * pick, and the reading time it cannot estimate by eye. Create and update both
 * route through here so those four values can never disagree between a newly
 * published post and the same post edited a week later.
 *
 * It normalises rather than validates. `topic`, `type`, `body` and `tags` are
 * NOT NULL columns, so a value that arrives unusable has to leave with a
 * sensible one — the rejection a member should see is raised by the composer's
 * own validation, long before this runs.
 */
class HydrateFeedPostContent
{
    /** Headlines the feed renders on one line; longer derivations are cut. */
    private const TITLE_LIMIT = 120;

    /** `read_minutes` is an unsigned tinyint — 255 is the column's own ceiling. */
    private const READ_MINUTES_LIMIT = 255;

    /** Words a reader gets through in a minute of scrolling the feed. */
    private const WORDS_PER_MINUTE = 200;

    /**
     * Derive every content-derived attribute the feed renders.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function handle(array $attributes): array
    {
        $body = trim((string) ($attributes['body'] ?? ''));
        $attributes['body'] = $body;

        $attributes['type'] = $this->type($attributes['type'] ?? null);
        $attributes['topic'] = $this->topic($attributes['topic'] ?? null);

        /*
         * Storage keeps the author's text whole and writes the headline beside
         * it. The card is what lifts the opening line out of the body — see
         * `feed/commit.blade.php` — so the body in the database stays the text
         * the author typed, which is the thing the inline editor has to open
         * holding. Lifting here instead would leave a one-line post with an
         * empty body and an editor that opens blank.
         */
        $attributes['title'] = $this->subject($attributes['title'] ?? null, $body);

        $attributes['tags'] = $this->tags($attributes['tags'] ?? [], $attributes['topic']);
        $attributes['read_minutes'] = $this->readMinutes($body);

        return $attributes;
    }

    /**
     * Keep the post inside the type vocabulary the feed can render.
     *
     * Each type has a card variant; a type outside the list has no markup, so
     * the safest rendering is the plain discussion card rather than a blank one.
     */
    protected function type(mixed $type): string
    {
        $type = Str::lower(trim((string) $type));
        /** @var array<int, string> $allowed */
        $allowed = (array) config('feed.composer.types');
        /** @var string $fallback */
        $fallback = (string) config('feed.composer.default_type');

        return in_array($type, $allowed, true) ? $type : $fallback;
    }

    /**
     * Normalise the topic into the lowercase slug the rail filters on.
     */
    protected function topic(mixed $topic): string
    {
        $topic = Str::lower(trim((string) $topic));
        /** @var array<int, string> $allowed */
        $allowed = (array) config('feed.composer.topics');

        return in_array($topic, $allowed, true)
            ? $topic
            : (string) config('feed.composer.default_topic');
    }

    /**
     * The headline this body alone would produce, with no authored title applied.
     *
     * Public because the rule has a second caller that must not restate it: the
     * card asks "was this post's headline derived from its opening line?" before
     * deciding to lift that line out of the body, and the only trustworthy way
     * to answer is to derive it again and compare. A caller that re-implemented
     * the strip-and-limit rule here would drift from this one the first time
     * either changed.
     */
    public function headlineFor(string $body): string
    {
        return $this->subject(null, $body);
    }

    /**
     * Write the headline the card will print above the body.
     *
     * A composer with no title field still owes the card a headline, and the
     * first line is the sentence the author already chose to lead with. Markup
     * that would look broken cut in half — a heading sigil, a list dash, a
     * quote bar — is stripped before the cut, not after.
     *
     * The body is **not** touched here. Storage keeps the author's text whole;
     * {@see FeedPost::bodyUnderHeadline()} is what takes the derived line back
     * out at render time. Splitting the two matters because the inline editor
     * has to open holding what the author typed, and a body that had already
     * lost its first line would open blank for every one-line post.
     */
    protected function subject(mixed $title, string $body): string
    {
        $title = trim((string) $title);

        if ($title !== '') {
            return Str::limit($title, self::TITLE_LIMIT, '');
        }

        $firstLine = Str::of($body)->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '');

        if ($firstLine === null || $firstLine === '') {
            return '';
        }

        // The sigil run is greedy (`+`): markdown writes a level-two heading as
        // `##`, and stripping a single marker would leave the card advertising
        // `# Getting the N+1 out` as its subject. Only a *run* at the very start
        // is cut, so `- - spaced` and `3 - 2` keep their punctuation.
        $lead = (string) preg_replace('/^[#>\-*+]+\s*/u', '', (string) $firstLine);

        return Str::limit(trim($lead), self::TITLE_LIMIT, '…');
    }

    /**
     * Clean the tag list, and seed it from the topic when the author gave none.
     *
     * `tags` is NOT NULL json, so an empty post needs `[]` rather than null; the
     * topic is the one label the post is already known by, so a card never shows
     * an untagged post that was in fact filed under something.
     *
     * @return array<int, string>
     */
    protected function tags(mixed $tags, string $topic): array
    {
        $given = is_array($tags) ? $tags : Str::of((string) $tags)->split('/[,;\n]/')->all();

        $clean = [];

        foreach ($given as $tag) {
            $tag = Str::of((string) $tag)->ltrim('#')->trim()->limit(32, '')->value();

            if ($tag !== '' && ! in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        if ($clean === []) {
            $clean[] = Str::ucfirst($topic);
        }

        /** @var int $limit */
        $limit = (int) config('feed.composer.max_tags', 8);

        return array_slice($clean, 0, max(1, $limit));
    }

    /**
     * Estimate how long the body takes to read.
     */
    protected function readMinutes(string $body): int
    {
        if (trim(strip_tags($body)) === '') {
            return 0;
        }

        return min(
            self::READ_MINUTES_LIMIT,
            max(1, (int) ceil(str_word_count(strip_tags($body)) / self::WORDS_PER_MINUTE)),
        );
    }
}
