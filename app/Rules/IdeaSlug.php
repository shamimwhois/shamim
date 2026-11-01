<?php

namespace App\Rules;

use App\Models\Idea;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates the slug of an idea.
 *
 * The reserved list is the set of second path segments `/ideas` already uses for
 * screens. Routes are registered so the literals win, but a row could still be
 * given the slug `plans` and then be unreachable by hand-typed URL while its own
 * listing links to it. Rejecting the word at the write is cheaper than explaining
 * the shadow afterwards.
 */
class IdeaSlug extends SlugRule
{
    /**
     * @return class-string<Model>
     */
    protected function model(): string
    {
        return Idea::class;
    }

    protected function noun(): string
    {
        return 'idea';
    }

    /**
     * @return array<int, string>
     */
    protected function reserved(): array
    {
        return ['dashboard', 'create', 'categories', 'plans', 'edit'];
    }
}
