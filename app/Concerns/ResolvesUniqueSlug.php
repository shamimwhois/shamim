<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Slug allocation for a module's addressable content.
 *
 * Every workspace surface with a public URL keys on `slug`, and each slug column
 * carries a real UNIQUE index, so a collision cannot be written through: the
 * insert would fail at the database. The behaviour mirrors
 * {@see HasSlug::generateUniqueSlug()} and the hub's own resolver so a renamed
 * idea reads the same way as a re-titled post or an article filed by the console.
 */
trait ResolvesUniqueSlug
{
    /**
     * Resolve a usable slug for the model from an explicit value or a source.
     *
     * An existing model keeps its slug unless the caller asks for a different one,
     * because the slug is already in readers' bookmarks and in the links other
     * pages make to it.
     *
     * `$withinScope` narrows the collision check for the rows whose namespace is
     * smaller than the table. A category slug only has to be unique inside one
     * account, and checking it against every account would mint
     * "platform-experiments-4" for the fourth person to think of that name.
     *
     * @param  array<string, mixed>  $data
     * @param  (callable(Builder<Model>): mixed)|null  $withinScope
     */
    protected function resolveUniqueSlug(Model $model, array $data, string $source, ?callable $withinScope = null): string
    {
        $requested = Str::slug(trim((string) ($data['slug'] ?? '')));

        if ($requested === '') {
            if ($model->exists) {
                return (string) $model->getAttribute('slug');
            }

            $requested = Str::slug(trim($source)) ?: 'untitled';
        }

        $exists = fn (string $candidate): bool => $model::query()
            ->where('slug', $candidate)
            ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()))
            ->when($withinScope !== null, $withinScope)
            ->exists();

        if (! $exists($requested)) {
            return $requested;
        }

        $suffix = 2;

        while ($exists($requested.'-'.$suffix)) {
            $suffix++;
        }

        return $requested.'-'.$suffix;
    }
}
