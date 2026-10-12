<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

trait HasSlug
{
    /**
     * Generate a slug that is unique for this model's table.
     *
     * Appends an incrementing suffix when a collision is detected, following the
     * same suffix strategy used by {@see GeneratesUniqueTeamSlugs}.
     *
     * Soft-deleted rows are included in the collision check when the model uses
     * SoftDeletes: `slug` carries a real UNIQUE index, so a slug only becomes
     * reusable once the row is gone for good. `$excludeId` exists for the
     * restore path, where the row playing the role of "the slug I already own"
     * is trashed.
     */
    public static function generateUniqueSlug(string $source, ?int $excludeId = null): string
    {
        $base = Str::slug($source) ?: 'untitled';

        $query = static::query();

        // Soft-deleted rows still hold their slug in a UNIQUE index, so they must
        // be considered when checking for a collision — otherwise a new post
        // could try to reuse the slug of a tombstone and fail at the database.
        //
        // The trait is shared with Category and Tag, which do not soft-delete, so
        // withTrashed() is only reached when the model mixes the trait in. The
        // call is resolved at runtime; PHPStan sees the builder for the concrete
        // model and cannot narrow it, hence the targeted ignore.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            /** @phpstan-ignore method.notFound (WithTrashed is supplied by SoftDeletes at runtime.) */
            $query->withTrashed();
        }

        $query->where(function ($query) use ($base): void {
            $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
        });

        if ($excludeId !== null) {
            $query->whereKeyNot($excludeId);
        }

        $existing = $query->pluck('slug');

        if ($existing->isEmpty()) {
            return $base;
        }

        $highestSuffix = $existing
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 0;
                }

                if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $matches) === 1) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix): bool => $suffix !== null)
            ->max() ?? 0;

        return $base.'-'.($highestSuffix + 1);
    }
}
