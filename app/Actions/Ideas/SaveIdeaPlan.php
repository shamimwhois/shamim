<?php

namespace App\Actions\Ideas;

use App\Concerns\ResolvesUniqueSlug;
use App\Enums\PlanStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\IdeaPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or update a plan.
 *
 * One entry point owns the write, so the plan screen, the conversion action's
 * follow-up edits, and anything added later cannot disagree about what a saved plan
 * means. Status is guarded here by {@see PlanStatus::canTransitionTo()} for the same
 * reason it is guarded on an idea: a console can be driven by a request the browser
 * never built.
 *
 * Three things are not taken from the request. The `slug` is resolved, because the
 * column carries a real UNIQUE index. The `user_id` is set once, on create, so no
 * edit can hand a plan to a different account. And `idea_id` is only ever written by
 * {@see ConvertIdeaToPlan}, because a provenance link is a fact about what happened,
 * not a field to type into.
 */
class SaveIdeaPlan
{
    use ResolvesUniqueSlug;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?IdeaPlan $plan = null, ?Idea $idea = null): IdeaPlan
    {
        return DB::transaction(function () use ($author, $data, $plan, $idea): IdeaPlan {
            $plan ??= new IdeaPlan;

            $isNew = ! $plan->exists;
            $title = trim((string) ($data['title'] ?? $plan->title ?? ''));

            if ($title === '') {
                throw new InvalidArgumentException(__('A plan needs a title before it can be saved.'));
            }

            if (mb_strlen($title) > IdeaPlan::TITLE_MAX) {
                throw new InvalidArgumentException(__('A plan title has to stay under :count characters.', [
                    'count' => IdeaPlan::TITLE_MAX,
                ]));
            }

            $currentStatus = $isNew ? PlanStatus::Draft : $plan->status;
            $requestedStatus = $this->resolveStatus($data['status'] ?? null) ?? $currentStatus;

            if (! $currentStatus->canTransitionTo($requestedStatus)) {
                throw new InvalidArgumentException(
                    __('A plan cannot move from :current to :target.', [
                        'current' => $currentStatus->label(),
                        'target' => $requestedStatus->label(),
                    ])
                );
            }

            $plan->user_id ??= $author->id;

            if ($plan->user_id !== $author->id) {
                throw new InvalidArgumentException(__('This plan belongs to another account.'));
            }

            $plan->fill([
                'title' => $title,
                'summary' => $this->nullableText($data, 'summary', $plan->summary),
                'problem_statement' => $this->nullableText($data, 'problem_statement', $plan->problem_statement),
                'proposed_solution' => $this->nullableText($data, 'proposed_solution', $plan->proposed_solution),
                'scope' => $this->nullableText($data, 'scope', $plan->scope),
                'out_of_scope' => $this->nullableText($data, 'out_of_scope', $plan->out_of_scope),
                'goals' => $this->nullableText($data, 'goals', $plan->goals),
                'notes' => $this->nullableText($data, 'notes', $plan->notes),
                'estimated_effort' => $this->nullableText($data, 'estimated_effort', $plan->estimated_effort),
                'priority' => $this->resolvePriority($data['priority'] ?? $plan->priority),
                'target_start_date' => $this->resolveDate($data, 'target_start_date', $plan->target_start_date),
                'target_end_date' => $this->resolveDate($data, 'target_end_date', $plan->target_end_date),
            ]);

            if ($idea !== null) {
                $this->assertIdeaIsWritable($author, $idea);

                $plan->idea_id = $idea->id;
            }

            $plan->slug = $this->resolveUniqueSlug($plan, $data, $title);

            $plan->forceFill(['status' => $requestedStatus]);

            $plan->save();

            return $plan->refresh();
        });
    }

    /**
     * Read a submitted text field, treating an absent key as "leave it alone".
     *
     * A blank submission clears the field, which is what an account means when it
     * deletes a section it no longer wants; a missing key means the caller is not
     * writing that part of the document at all.
     *
     * @param  array<string, mixed>  $data
     */
    protected function nullableText(array $data, string $key, ?string $current): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $current;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Resolve a submitted date, keeping the previous value when the key is absent.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveDate(array $data, string $key, mixed $current): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $current === null ? null : (string) $current;
        }

        $value = $data[$key];

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Coerce a submitted status.
     */
    protected function resolveStatus(mixed $value): ?PlanStatus
    {
        if ($value instanceof PlanStatus) {
            return $value;
        }

        return $value === null || $value === '' ? null : PlanStatus::tryFrom((string) $value);
    }

    /**
     * Coerce a submitted priority.
     */
    protected function resolvePriority(mixed $value): ?WorkPriority
    {
        if ($value instanceof WorkPriority) {
            return $value;
        }

        return $value === null || $value === '' ? null : WorkPriority::tryFrom((string) $value);
    }

    /**
     * Refuse to link a plan to an idea the account does not own.
     */
    protected function assertIdeaIsWritable(User $author, Idea $idea): void
    {
        if ($idea->user_id !== $author->id) {
            throw new InvalidArgumentException(__('That idea belongs to another account.'));
        }
    }
}
