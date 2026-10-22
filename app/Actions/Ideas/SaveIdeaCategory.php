<?php

namespace App\Actions\Ideas;

use App\Concerns\ResolvesUniqueSlug;
use App\Models\IdeaCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or rename one of an account's idea categories.
 *
 * Categories are per-account, so the owner is a required argument rather than a
 * column the caller may set: a category created through this action always belongs
 * to whoever asked for it, and renaming it cannot move it to somebody else.
 */
class SaveIdeaCategory
{
    use ResolvesUniqueSlug;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?IdeaCategory $category = null): IdeaCategory
    {
        return DB::transaction(function () use ($author, $data, $category): IdeaCategory {
            $category ??= new IdeaCategory;

            $name = trim((string) ($data['name'] ?? $category->name ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException(__('A category needs a name.'));
            }

            if (mb_strlen($name) > 120) {
                throw new InvalidArgumentException(__('A category name has to stay under 120 characters.'));
            }

            $category->user_id ??= $author->id;
            $category->name = $name;
            $category->description = array_key_exists('description', $data) ? $data['description'] : $category->description;
            $category->slug = $this->resolveUniqueSlug(
                $category,
                $data,
                $name,
                fn (Builder $query) => $query->where('user_id', $category->user_id),
            );

            $category->save();

            return $category->refresh();
        });
    }
}
