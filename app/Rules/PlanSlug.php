<?php

namespace App\Rules;

use App\Models\IdeaPlan;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates the slug of a plan.
 *
 * A plan lives at `/ideas/plans/{slug}`, so the segment it may claim is narrower than
 * an idea's: only the literals registered under `/ideas/plans` itself. `create` and
 * `edit` are refused for the same reason `IdeaSlug` refuses `plans` one level up: a
 * row named after a screen makes that screen harder to reach, and the fix is cheaper
 * at the write than in a routing discussion six months later.
 */
class PlanSlug extends SlugRule
{
    /**
     * @return class-string<Model>
     */
    protected function model(): string
    {
        return IdeaPlan::class;
    }

    protected function noun(): string
    {
        return 'plan';
    }

    /**
     * @return array<int, string>
     */
    protected function reserved(): array
    {
        return ['create', 'edit'];
    }
}
