<?php

namespace App\Actions\About;

use App\Enums\AboutSectionType;
use App\Models\AboutSection;
use Illuminate\Support\Str;

/**
 * Create or update an About page section.
 *
 * Sections own their stable DOM anchor (`key`), which the scrollspy navigation
 * links to, so the key is generated from the label once and preserved on
 * update — renaming a section must not silently break in-page anchors. The
 * uniqueness check runs against the `key` column's UNIQUE index rather than
 * reusing the HasSlug concern, which is hardwired to a `slug` column.
 */
class SaveAboutSection
{
    /**
     * Persist the section, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?AboutSection $section = null): AboutSection
    {
        $section ??= new AboutSection;

        $type = $data['type'] instanceof AboutSectionType
            ? $data['type']
            : AboutSectionType::from($data['type']);

        $attributes = [
            'key' => $this->resolveKey($data, $section),
            'type' => $type,
            'ordinal' => (int) ($data['ordinal'] ?? $section->ordinal ?? 0),
            'label' => $data['label'] ?? null,
            'heading' => $data['heading'] ?? null,
            'lead' => $data['lead'] ?? null,
            'body' => $data['body'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? $section->sort_order ?? 0),
            'is_visible' => (bool) ($data['is_visible'] ?? true),
        ];

        $section->fill($attributes);
        $section->save();

        return $section->refresh();
    }

    /**
     * Keep an existing key, honour an explicit one, or derive a unique slug
     * from the label/heading.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveKey(array $data, AboutSection $section): string
    {
        $requested = trim((string) ($data['key'] ?? ''));

        if ($requested === '' && $section->exists) {
            return $section->key;
        }

        $source = $requested !== '' ? $requested : (($data['label'] ?? $data['heading'] ?? '') ?: 'section');

        $base = Str::slug($source);

        $candidate = $base;
        $suffix = 1;

        while (
            AboutSection::query()
                ->where('key', $candidate)
                ->when($section->exists, fn ($query) => $query->whereKeyNot($section->getKey()))
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
