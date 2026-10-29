<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Models\Tag;

final readonly class CreateCommitTags
{
    /**
     * Tags are shared: two commits written weeks apart under the same name point at one row, which is what makes a
     * tag page possible. Names arrive normalized, from the form request, so "Design" and "design" are already one.
     *
     * @param  list<string>  $names
     */
    public function handle(Commit $commit, array $names): void
    {
        $names = array_slice(array_values(array_unique($names)), 0, Tag::MAX_PER_COMMIT);

        if ($names === []) {
            return;
        }

        $commit->tags()->attach(array_map(
            // Created on first use, and reused after that. "createOrFirst" rather than "firstOrCreate" so that two
            // commits tagged at the same moment can't both try to insert the name and have one of them fail.
            fn (string $name): string => Tag::query()->createOrFirst(['name' => $name])->id,
            $names,
        ));
    }
}
