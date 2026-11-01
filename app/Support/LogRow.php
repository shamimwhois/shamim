<?php

namespace App\Support;

use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\FeedReply;
use App\Models\FeedReport;
use App\Models\FeedStar;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A commit, shaped for the rail.
 *
 * The feed, a profile and the starred list all draw the same row, so the shape
 * is built once here rather than three times in Blade. Presentation decisions
 * that every surface shares live here: the lane and the previous lane, the short
 * hash, the relative time, the escaped body, and whether this reader may act on
 * the row.
 *
 * Thread state is a parameter, not a property of the row: only the page that
 * owns the open thread passes it, so a profile's rows stay closed.
 *
 * The row carries the post's **whole record**, not a curated subset. A commit
 * that stores a poll, a code block or a tag list is a commit that draws them —
 * on the log, on a profile and in the starred list alike — because the shape is
 * the one place that decides what a commit is made of.
 */
final class LogRow
{
    /** Body length, in characters, past which the row folds and offers "Show more". */
    public const FOLD_AFTER = 700;

    /**
     * The URL of the author behind a commit handle, or null when nobody owns it.
     *
     * Muscle memory says a commit always has a profile to open, and for a
     * seeded corpus that is true. It stops being true the moment a corpus
     * author is retired or a row outlives the account it was written against —
     * and then the row must not offer a door to a 404.
     *
     * The set of known handles comes from {@see LogHandleIndex}, a scoped
     * binding rather than a static. A static outlives the accounts it describes
     * under a long-running worker, and it leaked between tests: the map filled
     * in one test was still answering for the next one's rolled-back accounts.
     */
    private static function profileUrlFor(string $handle): ?string
    {
        $normalised = mb_strtolower(LogIdentity::handle($handle));

        if (! app(LogHandleIndex::class)->owns($normalised)) {
            return null;
        }

        return route('profile.show', ['handle' => $normalised]);
    }

    /**
     * Every row of a page of commits, in order.
     *
     * @param  Collection<int, FeedPost>  $posts
     * @param  array<int, bool>  $starred
     * @param  array<int, int>  $freshIds
     * @param  array<int, bool>  $reportedIds
     * @param  array<int, bool>  $sharedIds
     * @param  array<int, int>  $shareCounts
     * @param  array<int, array{counts: array<string, int>, mine: string|null}>  $pollVotes
     * @return array<int, array<string, mixed>>
     */
    public static function rows(
        Collection $posts,
        array $starred = [],
        ?int $threadId = null,
        ?int $replyTo = null,
        ?User $reader = null,
        array $freshIds = [],
        ?int $lastVisitId = null,
        ?int $interludeAfter = null,
        array $reportedIds = [],
        array $pollVotes = [],
        array $sharedIds = [],
        array $shareCounts = [],
        ?int $editingId = null,
        string $editDraft = '',
    ): array {
        $rows = [];
        $previous = null;
        $visited = $lastVisitId === null;

        /*
         * What each author on this page has done on it, counted from the rows
         * the page is already rendering. The hover card prints these numbers,
         * and they have to agree with the page they are printed on: a stat
         * fetched from a wider table would answer "how active is this
         * developer overall" while the card sits inside a filtered log where
         * the reader can count something else. One pass, no queries.
         */
        $totals = [];

        foreach ($posts as $post) {
            $key = LogIdentity::handle($post->handle);

            $totals[$key] ??= ['commits' => 0, 'stars' => 0, 'replies' => 0];
            $totals[$key]['commits']++;
            $totals[$key]['stars'] += (int) ($post->stars_count ?? 0);
            $totals[$key]['replies'] += (int) ($post->replies_count ?? 0);
        }
        foreach ($posts->values() as $index => $post) {
            // Named, not positional: this argument list is long enough that a
            // positional call silently mis-files an array into the wrong slot
            // (a 7-arg call once put the report and poll arrays into
            // `$lastVisitId`/`$interludeAfter` and raised a TypeError at the
            // first string comparison).
            $row = self::make(
                post: $post,
                previous: $previous,
                starred: $starred,
                threadId: $threadId,
                replyTo: $replyTo,
                reader: $reader,
                freshIds: $freshIds,
                reportedIds: $reportedIds,
                pollVotes: $pollVotes,
                sharedIds: $sharedIds,
                shareCounts: $shareCounts,
                editingId: $editingId,
                editDraft: $editDraft,
            );

            $row['authorCard'] = $totals[LogIdentity::handle($post->handle)] ?? null;

            /*
             * The last-visit line belongs above the first commit the reader has
             * not seen. In a newest-first log that is the first row whose id is
             * at or below the marker, so it is claimed once and never again —
             * a second line would turn the log into a ruler.
             */
            if (! $visited && $post->id <= $lastVisitId) {
                $row['lastVisit'] = true;
                $visited = true;
            }

            $rows[] = $row;
            $previous = $post;
        }

        /*
         * The interlude goes after the fifth commit, or after the last one when
         * the page is shorter than that — a "who to follow" block that never
         * appears because the log is quiet is a block nobody will ever see.
         */
        if ($interludeAfter !== null && $rows !== []) {
            $index = max(0, min($interludeAfter, count($rows)) - 1);
            $rows[$index]['interlude'] = true;
        }

        return $rows;
    }

    /**
     * One commit as the rail template needs it.
     *
     * @param  array<int, bool>  $starred
     * @param  array<int, int>  $freshIds
     * @param  array<int, bool>  $reportedIds
     * @param  array<int, bool>  $sharedIds
     * @param  array<int, int>  $shareCounts
     * @param  array<int, array{counts: array<string, int>, mine: string|null}>  $pollVotes
     * @return array<string, mixed>
     */
    public static function make(
        FeedPost $post,
        ?FeedPost $previous = null,
        array $starred = [],
        ?int $threadId = null,
        ?int $replyTo = null,
        ?User $reader = null,
        array $freshIds = [],
        array $reportedIds = [],
        array $pollVotes = [],
        array $sharedIds = [],
        array $shareCounts = [],
        ?int $editingId = null,
        string $editDraft = '',
    ): array {
        $createdAt = $post->posted_at ?? $post->created_at;
        $isOpen = $threadId === $post->id;
        /*
         * The body as the card prints it: with the line that became the headline
         * taken out of the top. Storage keeps the author's text whole so the
         * inline editor has something to open with, and `git log` does not print
         * a subject inside the body either — so the removal belongs to the row
         * that pairs the two, not to the write that stored them.
         */
        $body = CommitBody::toHtml($post->bodyUnderHeadline());
        $plain = trim(strip_tags($body));

        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'name' => $post->author,
            'handle' => LogIdentity::handle($post->handle),
            /*
             * The author's profile, or null when no account owns the handle.
             *
             * A commit's `handle` is a display string, not a key: a corpus row
             * carries `@priya` while the account table carries `priya`, and a
             * retired corpus author carries a handle no account ever claimed.
             * `route('profile.show')` would still build `/@ghost` for it and the
             * reader would land on a 404 — so the row answers "is there anyone
             * to open" here, once, and the markup renders the link only when
             * there is.
             */
            'profileUrl' => self::profileUrlFor($post->handle),
            'initials' => $post->avatar,
            'role' => $post->role,
            'lane' => LogIdentity::lane($post->handle),
            'previousLane' => $previous === null ? null : LogIdentity::lane($previous->handle),
            'hash' => LogIdentity::shortHash($post->slug),
            'time' => LogIdentity::relative($createdAt),
            'iso' => $createdAt?->toIso8601String() ?? '',
            'fullDate' => $createdAt?->format('D, M j, Y \a\t H:i') ?? '',
            'day' => LogIdentity::day($createdAt),
            'title' => $post->title,
            'body' => $body,
            'plain' => $plain,
            'long' => mb_strlen($plain) > self::FOLD_AFTER,
            'url' => route('feed.show', ['slug' => $post->slug]),
            'stars' => (int) $post->getAttribute('stars_count'),
            'starred' => isset($starred[$post->id]),
            'replies' => (int) $post->getAttribute('replies_count'),
            'isOwn' => $reader !== null && $post->user_id === $reader->id,
            'fresh' => in_array($post->id, $freshIds, true),
            'threadOpen' => $isOpen,
            'replyOpen' => $replyTo === $post->id,
            'repliesList' => $isOpen ? self::replies($post) : [],
            // Sharing is a row count, never a column, so the number printed
            // here and the ledger behind it cannot disagree.
            'shares' => $shareCounts[$post->id] ?? 0,
            'shared' => isset($sharedIds[$post->id]),
            // An edit is inline, so the row carries both the fact that it is
            // being edited and the text being edited — the form has to open
            // holding the current body, and a second round trip to fetch it
            // would blank the row while it arrived.
            'editing' => $editingId === $post->id,
            'editDraft' => $editingId === $post->id ? $editDraft : '',
            // The body as plain text, for the editor to start from: the
            // rendered `body` is HTML and would put markup in the textarea.
            'rawBody' => $post->body,
            'tags' => array_values($post->tags ?? []),
            'topic' => $post->topic,
            'type' => $post->type,
            'pinned' => (bool) $post->pinned,
            'featured' => (bool) $post->featured,
            'answered' => $post->isSolved(),
            'readMinutes' => $post->read_minutes,
            'reactions' => self::reactions($post),
            'poll' => self::poll(
                $post,
                $pollVotes[$post->id]['counts'] ?? [],
                $pollVotes[$post->id]['mine'] ?? null,
            ),
            'code' => self::code($post),
            'answer' => self::answer($post),
            'activity' => self::activity($post),
            'attachments' => self::attachments($post),
            'hasDetail' => $post->hasDetail(),
            'reported' => isset($reportedIds[$post->id]),
            'canReport' => $reader !== null && $post->user_id !== $reader->id,
            'canVote' => $reader !== null,
            'reportReasons' => FeedReport::REASONS,
            'lastVisit' => false,
            'interlude' => false,
        ];
    }

    /**
     * The commit's three reactions, with a total, or null when it has none.
     *
     * A reaction is a count on the row, not a table of its own: the corpus
     * carries them denormalised, so there is nothing to count and nothing to
     * drift.
     *
     * @return array{like: int, love: int, insight: int, total: int}|null
     */
    public static function reactions(FeedPost $post): ?array
    {
        $raw = $post->reactions ?? [];
        $like = (int) ($raw['like'] ?? 0);
        $love = (int) ($raw['love'] ?? 0);
        $insight = (int) ($raw['insight'] ?? 0);
        $total = $like + $love + $insight;

        return $total === 0 ? null : compact('like', 'love', 'insight', 'total');
    }

    /**
     * A commit's poll, with each option's share already worked out.
     *
     * The share is computed here rather than in the template so the bar widths
     * and the printed percentages cannot disagree.
     *
     * The tally is the sum of two things: the corpus baseline that shipped
     * with the poll, and the votes cast on this install. The baseline is never
     * written, so there is no counter here that can drift from the rows.
     *
     * @param  array<string, int>  $counts  The votes cast on this install, by option.
     * @param  string|null  $mine  The option this reader chose.
     * @return array{votes: int, closes: string|null, voted: string|null, options: array<int, array{label: string, votes: int, share: float, mine: bool}>}|null
     */
    public static function poll(FeedPost $post, array $counts = [], ?string $mine = null): ?array
    {
        // A JSON column's shape is a promise the database does not enforce, so
        // the record is read as `mixed` and trusted only as far as it checks
        // out. Narrowing through the model's docblock would make every guard
        // below look redundant to a reader that has believed the type.
        /** @var mixed $poll */
        $poll = $post->poll;

        if (! is_array($poll) || ! isset($poll['options']) || ! is_array($poll['options'])) {
            return null;
        }

        $votes = (int) ($poll['votes'] ?? 0);
        $options = [];

        foreach ($poll['options'] as $option) {
            if (! is_array($option)) {
                continue;
            }

            $label = (string) ($option['label'] ?? '');
            $count = (int) ($option['votes'] ?? 0) + (int) ($counts[$label] ?? 0);

            $options[] = [
                'label' => $label,
                'votes' => $count,
                'share' => 0.0,
                'mine' => $mine !== null && $mine === $label,
            ];
        }

        if ($options === []) {
            return null;
        }

        // The baseline total is the sum of its options, not the number the
        // corpus printed: a poll whose stated total disagrees with its own
        // options is a poll whose percentages would lie.
        $total = array_sum(array_column($options, 'votes'));

        foreach ($options as $index => $option) {
            $options[$index]['share'] = $total > 0 ? round($option['votes'] / $total * 100, 1) : 0.0;
        }

        return [
            'votes' => $total,
            'closes' => isset($poll['closes']) ? (string) $poll['closes'] : null,
            'voted' => $mine,
            'options' => $options,
        ];
    }

    /**
     * A commit's accepted answer, when it has one.
     *
     * The answer is body text, so it is rendered the way a commit body is
     * rendered: a solved question whose answer is a code fragment must show the
     * fragment as code, not as a run of backticks.
     *
     * @return array{author: string, initials: string, body: string}|null
     */
    public static function answer(FeedPost $post): ?array
    {
        /** @var mixed $answer */
        $answer = $post->answer;

        if (! is_array($answer) || ! is_string($answer['body'] ?? null) || trim($answer['body']) === '') {
            return null;
        }

        return [
            'author' => (string) ($answer['author'] ?? ''),
            'initials' => (string) ($answer['avatar'] ?? ''),
            'body' => CommitBody::toHtml($answer['body']),
        ];
    }

    /**
     * A commit's activity line — the "this is still moving" note.
     *
     * @return array{label: string, detail: string}|null
     */
    public static function activity(FeedPost $post): ?array
    {
        /** @var mixed $activity */
        $activity = $post->activity;

        if (! is_array($activity)) {
            return null;
        }

        $label = trim((string) ($activity['label'] ?? ''));
        $detail = trim((string) ($activity['detail'] ?? ''));

        if ($label === '' && $detail === '') {
            return null;
        }

        return ['label' => $label, 'detail' => $detail];
    }

    /**
     * A commit's images, at most four.
     *
     * The relation is read only when it has already been loaded: a row is
     * drawn on three surfaces, and a row that quietly fetched its images would
     * turn a page of commits into a page of queries.
     *
     * @return array<int, array{url: string, alt: string, width: int|null, height: int|null}>
     */
    public static function attachments(FeedPost $post): array
    {
        if (! $post->relationLoaded('attachments')) {
            return [];
        }

        return $post->attachments
            ->sortBy('sort_order')
            ->take(4)
            ->map(fn (FeedAttachment $attachment): array => [
                'url' => $attachment->url(),
                'alt' => $attachment->alt(),
                'width' => $attachment->width,
                'height' => $attachment->height,
            ])
            ->values()
            ->all();
    }

    /**
     * A commit's code block, when it carries one.
     *
     * @return array{file: string|null, body: string}|null
     */
    public static function code(FeedPost $post): ?array
    {
        $code = $post->code;

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return [
            'file' => $post->code_file !== null && $post->code_file !== '' ? $post->code_file : null,
            'body' => rtrim($code, "\n"),
        ];
    }

    /**
     * A commit's replies, oldest first.
     *
     * @return array<int, array{name: string, handle: string, initials: string, lane: string, time: string, body: string}>
     */
    public static function replies(FeedPost $post): array
    {
        return $post->replies
            ->map(fn (FeedReply $reply): array => [
                'name' => $reply->user->name,
                'handle' => LogIdentity::handle($reply->user->handle),
                'initials' => LogIdentity::initials($reply->user->name),
                'lane' => LogIdentity::lane((string) $reply->user->handle),
                'time' => LogIdentity::relative($reply->created_at),
                'body' => e($reply->body),
            ])
            ->values()
            ->all();
    }

    /**
     * The commits a reader has starred, as a lookup for the template.
     *
     * @return array<int, bool>
     */
    public static function starredLookup(?User $reader): array
    {
        if ($reader === null) {
            return [];
        }

        return FeedStar::query()
            ->where('user_id', $reader->id)
            ->pluck('feed_post_id')
            ->mapWithKeys(fn (int|string $id): array => [(int) $id => true])
            ->all();
    }
}
