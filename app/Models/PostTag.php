<?php

namespace App\Models;

use Database\Factories\PostTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the post_tag table.
 *
 * Eloquent writes pivot rows through the query builder, which fires no model
 * events, so a plain belongsToMany would let a tag's denormalised
 * `posts_count` drift whenever a tag was attached or detached outside
 * SyncPostTags. Declaring the pivot as a model gives attach/detach write paths
 * that can reconcile the counter.
 */
class PostTag extends Pivot
{
    /** @use HasFactory<PostTagFactory> */
    use HasFactory;

    /**
     * `post_tag` carries no timestamp columns and the relationships never ask
     * for them (`belongsToMany()->using()`, without `withTimestamps()`), so an
     * inherited `true` here would only surface the first time a row is written
     * outside a sync — which is exactly what a factory does.
     */
    public $timestamps = false;

    /**
     * The table associated with the pivot.
     *
     * @var string
     */
    protected $table = 'post_tag';

    /**
     * Indicates the pivot has no single incrementing key.
     *
     * The table uses a composite (post_id, tag_id) primary key, so Eloquent must
     * not assume an `id` column exists.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Reconcile the parent tag's published-post counter after a pivot write.
     */
    protected static function booted(): void
    {
        $sync = function (self $pivot): void {
            Tag::query()->find($pivot->tag_id)?->syncPostsCount();
        };

        static::saved($sync);
        static::deleted($sync);
    }
}
