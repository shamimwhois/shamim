<?php

namespace App\Actions\AiHub;

use App\Enums\AiPublicationStatus;
use App\Models\AiArticle;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The single writer of the hub's publication status and publish timestamp.
 *
 * `published_at` and `status` are an invariant rather than two fields: content is
 * publicly readable only when *both* say it is, so every status move has to keep
 * them in step. Having one owner for that rule is what makes the spec requirement
 * "new content stays a draft until explicitly published" a fact of the code
 * instead of a habit of the operator.
 *
 * The timestamp is stamped once and never rewritten. A pulled-back-and-republished
 * article keeps the date it first went out, because readers and learning paths
 * that cite it are reading against that date, not against the operator's edit.
 */
class SyncAiPublicationStatus
{
    /**
     * Move hub content to the requested status.
     *
     * @param  AiArticle|AiLearningPath|AiResource  $content
     * @return AiArticle|AiLearningPath|AiResource
     */
    public function handle(object $content, AiPublicationStatus $target): object
    {
        $current = $content->status ?? AiPublicationStatus::Draft;

        if (! $target->isContainerStatus() && ! $content instanceof AiArticle) {
            throw new InvalidArgumentException('Only articles can be held in review.');
        }

        if (! $current->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                sprintf('Cannot move hub content from %s to %s.', $current->value, $target->value)
            );
        }

        if ($target === AiPublicationStatus::Published) {
            $this->assertReadyToPublish($content);

            if ($content->published_at === null) {
                $content->published_at = Carbon::now();
            }
        }

        $content->status = $target;

        return $content;
    }

    /**
     * Publish hub content, refusing when the workflow is not satisfied.
     *
     * @param  AiArticle|AiLearningPath|AiResource  $content
     * @return AiArticle|AiLearningPath|AiResource
     */
    public function publish(object $content): object
    {
        return $this->handle($content, AiPublicationStatus::Published);
    }

    /**
     * Determine whether the content satisfies the rules for going public.
     *
     * A learning path is a promise about sequence, so publishing one with no
     * lessons, or with a lesson that is still a draft, would publish a route that
     * leads somewhere a reader cannot go.
     */
    protected function assertReadyToPublish(object $content): void
    {
        if (! $content instanceof AiLearningPath) {
            return;
        }

        $articleIds = $content->items()->pluck('ai_article_id');

        if ($articleIds->isEmpty()) {
            throw new InvalidArgumentException('A learning path needs at least one published lesson before it can go public.');
        }

        $unready = AiArticle::query()
            ->whereIn('id', $articleIds)
            ->where(function (Builder $query): void {
                $query->where('status', '!=', AiPublicationStatus::Published)
                    ->orWhereNull('published_at');
            })
            ->exists();

        if ($unready) {
            throw new InvalidArgumentException('Every lesson in a learning path must be published before the path can be.');
        }
    }
}
