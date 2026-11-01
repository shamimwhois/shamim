<?php

namespace App\Actions\AiHub;

use App\Models\AiResource;

/**
 * Remove a resource from the shelf.
 *
 * The link is the only thing owned here, so deletion is a single row and nothing
 * else has to be cleaned up — no article mentions it, no path routes through it.
 */
class DeleteAiResource
{
    public function handle(AiResource $resource): void
    {
        $resource->delete();
    }
}
