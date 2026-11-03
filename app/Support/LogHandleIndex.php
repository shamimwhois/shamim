<?php

namespace App\Support;

use App\Models\User;

/**
 * Which commit handles belong to a real account.
 *
 * A page draws twenty rows and most of them share an author, so asking "does
 * this handle have a profile" per row would be twenty queries answering three
 * questions. One `pluck` of every handle settles all of them, keyed by the
 * lower-cased value the comparison uses.
 *
 * ## Why this is a `scoped` binding and not a static
 *
 * It used to be `LogRow::$knownHandles` — a static, which lives as long as the
 * process. That is wrong in two ways that are easy to miss:
 *
 *   - Under Octane or a queue worker the first request's answer outlives the
 *     accounts it described, so a handle created after boot is unknown and a
 *     deleted one still resolves.
 *   - In the test suite it leaked between tests. `FeedPublishingTest` filled
 *     the map, the database rolled back, and the next test's authors were
 *     judged against accounts that no longer existed — so every author card
 *     lost its profile link and `FeedParityTest` failed *only* when run after
 *     that file. A test that passes alone and fails in a suite is a bug in the
 *     code, not in the test.
 *
 * A `scoped` binding is flushed with the request and with the application
 * between tests, which is exactly the lifetime this cache should have.
 */
class LogHandleIndex
{
    /** @var array<string, bool>|null */
    private ?array $handles = null;

    /**
     * Every handle an account owns, lower-cased, as a set.
     *
     * The values are booleans rather than a narrower literal because
     * `Collection::all()` widens them; only the keys carry meaning here.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->handles ??= User::query()
            ->whereNotNull('handle')
            ->pluck('handle')
            ->mapWithKeys(fn (string $candidate): array => [mb_strtolower($candidate) => true])
            ->all();
    }

    /**
     * Does an account own this handle? The argument may still carry its `@`.
     */
    public function owns(string $handle): bool
    {
        $normalised = mb_strtolower(LogIdentity::handle($handle));

        return $normalised !== '' && isset($this->all()[$normalised]);
    }
}
