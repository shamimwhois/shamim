<?php

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Collection;

class SyncPostTags
{
    /**
     * Replace the post's tag set.
     *
     * Accepts tag ids or tag names, creating any tag that does not yet exist so
     * the author never has to leave the editor to file a post.
     *
     * @param  array<int, int|string>  $tags
     */
    public function handle(Post $post, array $tags): void
    {
        $ids = collect($tags)
            ->map(fn (int|string $tag): ?int => is_numeric($tag)
                ? (int) $tag
                : $this->resolveTagId((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $post->tags()->sync($ids);
        $post->unsetRelation('tags');
    }

    /**
     * Resolve a list of tag names into tag ids, creating any that are new.
     *
     * Used by the editor's inline tag input, where the author types a name and
     * expects the tag to exist immediately — before the post is saved.
     *
     * @param  Collection<int, string>|array<int, string>  $names
     * @return array<int, int>
     */
    public function resolveIds(Collection|array $names): array
    {
        return collect($names)
            ->map(fn (string $name): ?int => $this->resolveTagId($name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Find an existing tag by name or slug, or create one.
     */
    protected function resolveTagId(string $name): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $slug = str($name)->slug()->value();

        if ($slug === '') {
            return null;
        }

        /** @var Tag $tag */
        $tag = Tag::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name],
        );

        return $tag->id;
    }

    /**
     * Resolve a list of tag names into existing tag ids, without creating any.
     *
     * @param  Collection<int, string>  $names
     * @return Collection<int, int>
     */
    public function existingIds(Collection $names): Collection
    {
        $slugs = $names->map(fn (string $name): string => str($name)->slug()->value())->filter();

        return Tag::query()->whereIn('slug', $slugs)->pluck('id');
    }
}
