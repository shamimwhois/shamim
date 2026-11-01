<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The log's small vocabulary: who someone is, what colour they are, and how a
 * machine would print their commit.
 *
 * Every surface that draws a rail — the feed, a profile, the starred list, the
 * inbox — has to answer the same four questions about a row. Answering them
 * here keeps a developer's lane colour identical on all of them: the lane is
 * hashed from the bare handle, so `@priya` in the corpus and `priya` in
 * `users.handle` are the same colour.
 */
final class LogIdentity
{
    /** How many author lanes the palette holds. */
    public const LANES = 6;

    /**
     * A handle without its leading `@`.
     *
     * The corpus stores `@priya`; `users.handle` stores `priya`. Nothing else in
     * the log should have to know that.
     */
    public static function handle(?string $raw): string
    {
        return ltrim((string) $raw, '@');
    }

    /**
     * An account's handle, with the app's fallback for an account that has none.
     *
     * `users.handle` is nullable, and a route parameter cannot be empty, so the
     * email's local part is slugged the same way the composer's byline is. One
     * definition means a profile URL and the handle printed beside a commit can
     * never disagree.
     */
    public static function handleFor(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        $handle = self::handle($user->handle);

        return $handle !== '' ? $handle : Str::slug(Str::before((string) $user->email, '@'));
    }

    /**
     * The two letters an avatar disc prints.
     */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_substr($part, 0, 1))
            ->implode(''));
    }

    /**
     * The lane index a handle is assigned, 0–5.
     */
    public static function laneIndex(string $handle): int
    {
        return crc32(mb_strtolower(self::handle($handle))) % self::LANES;
    }

    /**
     * The lane as a `var()` reference, so it flips with the theme.
     */
    public static function lane(string $handle): string
    {
        return 'var(--dn-lane-'.self::laneIndex($handle).')';
    }

    /**
     * A commit's seven-character short hash, derived from its slug.
     */
    public static function shortHash(string $slug): string
    {
        return CommitBody::shortHash($slug);
    }

    /**
     * A commit's age in the machine's voice: `2d`, `3h`, `just now`.
     */
    public static function relative(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        if ($date->diffInSeconds(now()) < 45) {
            return 'just now';
        }

        return str_replace([' ago', 'in '], '', $date->diffForHumans(null, null, true));
    }

    /**
     * "Today", "Yesterday", or the date, wherever the local day changes.
     */
    public static function day(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return match (true) {
            $date->isToday() => __('Today'),
            $date->isYesterday() => __('Yesterday'),
            default => $date->format('D, M j'),
        };
    }
}
