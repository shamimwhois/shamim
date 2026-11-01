<?php

namespace App\Actions\AiHub;

use App\Models\AiTopic;
use InvalidArgumentException;

/**
 * Create or update a hub topic.
 *
 * Topics are the taxonomy the spec asks an operator to own: add, rename, reorder,
 * and deactivate without a deploy. Renaming deliberately does *not* move the
 * slug, because topic slugs appear in public URLs and in the filters that link to
 * them; `MoveAiTopic` and an explicit slug submission are the only ways it changes.
 */
class SaveAiTopic
{
    use ResolvesAiSlug;

    /**
     * Persist the topic, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?AiTopic $topic = null): AiTopic
    {
        $topic ??= new AiTopic;

        $name = trim((string) ($data['name'] ?? $topic->name ?? ''));

        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $topic->parent_id;

        if ($parentId !== null && $parentId !== '') {
            $parentId = (int) $parentId;

            if ($parentId === $topic->getKey()) {
                $parentId = null;
            }

            $this->assertNotDescendantOf($topic, $parentId);
        } else {
            $parentId = null;
        }

        $topic->fill([
            'parent_id' => $parentId,
            'name' => $name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $topic->description,
            'icon' => array_key_exists('icon', $data) ? $data['icon'] : $topic->icon,
            'sort_order' => (int) ($data['sort_order'] ?? $topic->sort_order ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? $topic->is_active ?? true),
        ]);

        $topic->slug = $this->resolveSlug($topic, $data, $name);

        $topic->save();

        return $topic->refresh();
    }

    /**
     * Refuse to file a topic underneath itself or one of its own children.
     */
    protected function assertNotDescendantOf(AiTopic $topic, ?int $parentId): void
    {
        if ($parentId === null || ! $topic->exists) {
            return;
        }

        $cursor = $parentId;
        $guard = 0;

        while ($cursor !== null && $guard++ < 64) {
            if ($cursor === $topic->id) {
                throw new InvalidArgumentException('A topic cannot be filed underneath one of its own children.');
            }

            $cursor = AiTopic::query()->whereKey($cursor)->value('parent_id');
        }
    }
}
