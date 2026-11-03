<?php

namespace App\Actions\About;

use App\Enums\AboutItemType;
use App\Models\AboutItem;
use App\Models\AboutSection;

/**
 * Create or update a content row inside an About section.
 *
 * One entry point serves every section shape: flat lists pass no parent, the
 * Experience tree passes the employer/role row to hang the new node under.
 * Tree membership is validated here rather than in the caller so an item can
 * never point at a parent from a different section — the presenter rebuilds
 * trees by walking children, and a cross-section parent would silently drop
 * rows from the page.
 */
class SaveAboutItem
{
    /**
     * Persist the row inside the given section.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(AboutSection $section, array $data, ?AboutItem $item = null): AboutItem
    {
        $item ??= new AboutItem;

        $type = $data['type'] instanceof AboutItemType
            ? $data['type']
            : AboutItemType::from($data['type'] ?? AboutItemType::Entry->value);

        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null) {
            $parent = AboutItem::query()
                ->whereKey($parentId)
                ->where('about_section_id', $section->id)
                ->firstOrFail();

            if ($item->exists && $parent->isDescendantOf($item)) {
                throw new \InvalidArgumentException('An item cannot become a child of its own descendant.');
            }
        }

        $item->fill([
            'about_section_id' => $section->id,
            'parent_id' => $parentId,
            'type' => $type,
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'body' => $data['body'] ?? null,
            'value' => $data['value'] ?? null,
            'tags' => $data['tags'] ?? null,
            'icon' => $data['icon'] ?? null,
            'url' => $data['url'] ?? null,
            'url_label' => $data['url_label'] ?? null,
            'period' => $data['period'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? $item->sort_order ?? 0),
            'is_visible' => (bool) ($data['is_visible'] ?? true),
        ]);

        $item->save();

        return $item->refresh();
    }
}
