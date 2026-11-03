<?php

namespace App\Actions\Ideas;

use App\Models\Idea;
use App\Models\IdeaPlan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turn an approved idea into a plan, and keep the idea.
 *
 * `docs/Idea.md` asks for exactly two things from conversion, and they are the
 * reason this is an action rather than a button that creates an empty row: the idea
 * is preserved, and the plan is linked back to it. Copying the thinking across is
 * what makes the second half worth doing at all, and leaving the idea where it was
 * means the account can still publish, archive, or delete either object without
 * damaging the other.
 *
 * The plan's slug derives from the idea's with a `-plan` suffix before any collision
 * handling, so a converted row is recognisable in a URL as something that came from
 * elsewhere rather than pretending to be original.
 *
 * One idea converts once. The `idea_plans.idea_id` index is UNIQUE, so a second
 * attempt cannot be written even by a request that skipped this class, and the
 * refusal here is what makes that read as a decision rather than a database error.
 *
 * The idea's status does not move. `Approved` is the stage that says the account
 * committed to the thought, and no later idea stage is implied by a plan existing, so
 * the workflow on the idea and the workflow on the plan stay separate objects.
 */
class ConvertIdeaToPlan
{
    public function __construct(
        protected SaveIdeaPlan $savePlan,
    ) {}

    /**
     * Convert the idea and return the plan it produced.
     */
    public function handle(Idea $idea): IdeaPlan
    {
        return DB::transaction(function () use ($idea): IdeaPlan {
            if (! $idea->status->isPublishable()) {
                throw new InvalidArgumentException(
                    __('Only an approved idea can become a plan. This one is :status.', [
                        'status' => $idea->status->label(),
                    ])
                );
            }

            if ($idea->plan()->exists()) {
                throw new InvalidArgumentException(__(
                    'This idea already has a plan. Open it instead of converting the idea twice.'
                ));
            }

            return $this->savePlan->handle($idea->user, [
                'title' => $idea->title,
                'slug' => $idea->slug.'-plan',
                'summary' => $idea->summary,
                'problem_statement' => $idea->summary,
                'notes' => $idea->content,
                'priority' => $idea->priority,
            ], idea: $idea);
        });
    }
}
