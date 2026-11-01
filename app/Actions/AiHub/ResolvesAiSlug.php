<?php

namespace App\Actions\AiHub;

use App\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Slug allocation for the hub's addressable content.
 *
 * Every hub surface that has a public URL keys on `slug`, and each slug column
 * carries a real UNIQUE index. A collision therefore cannot be written through:
 * the insert would fail at the database. The suffix strategy mirrors
 * {@see HasSlug::generateUniqueSlug()} so a renamed article reads
 * the same way as a re-titled post.
 */
trait ResolvesAiSlug
{
    /**
     * Resolve a usable slug for the model from an explicit value or a source.
     *
     * An existing model keeps its slug unless the caller asks for a different
     * one, because the slug is already in readers' bookmarks and in the links
     * other hub pages make to it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveSlug(Model $model, array $data, string $source): string
    {
        $requested = Str::slug(trim((string) ($data['slug'] ?? '')));

        if ($requested === '') {
            if ($model->exists) {
                return (string) $model->getAttribute('slug');
            }

            $requested = Str::slug(trim($source));
        }

        $exists = fn (string $candidate): bool => $model::query()
            ->where('slug', $candidate)
            ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()))
            ->exists();

        if (! $exists($requested)) {
            return $requested;
        }

        $base = $requested;
        $suffix = 2;

        while ($exists($base.'-'.$suffix)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }
}
