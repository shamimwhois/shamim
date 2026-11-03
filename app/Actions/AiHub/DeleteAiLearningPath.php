<?php

namespace App\Actions\AiHub;

use App\Models\AiLearningPath;
use Illuminate\Support\Facades\DB;

/**
 * Remove a learning path.
 *
 * Only the route is deleted, never the lessons: the slots go with the path and the
 * articles they pointed at stay published in the hub.
 */
class DeleteAiLearningPath
{
    public function handle(AiLearningPath $path): void
    {
        DB::transaction(function () use ($path): void {
            $path->items()->delete();
            $path->delete();
        });
    }

    /**
     * Count the lessons that will lose this route.
     */
    public function lessonCount(AiLearningPath $path): int
    {
        return $path->items()->count();
    }
}
