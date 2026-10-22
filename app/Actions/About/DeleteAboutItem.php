<?php

namespace App\Actions\About;

use App\Models\AboutItem;
use Illuminate\Support\Facades\DB;

/**
 * Remove an About content row together with its subtree.
 *
 * The `parent_id` foreign key is `nullOnDelete`, which would orphan children
 * into the top level of a section and silently change how the Experience tree
 * renders. Deleting the descendants first keeps the surviving page exactly as
 * the operator sees it in the admin preview.
 */
class DeleteAboutItem
{
    /**
     * Delete the row and every row nested beneath it.
     */
    public function handle(AboutItem $item): void
    {
        DB::transaction(function () use ($item): void {
            foreach ($item->children as $child) {
                $this->handle($child);
            }

            $item->delete();
        });
    }
}
