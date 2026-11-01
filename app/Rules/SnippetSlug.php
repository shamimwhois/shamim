<?php

namespace App\Rules;

use App\Models\LabSnippet;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates the slug of a snippet.
 *
 * The reserved list is the set of second path segments `/dev-lab` uses for
 * screens, plus the two literals that sit at the same depth as a snippet slug
 * inside `/dev-lab/snippets`. Route registration already puts those ahead of the
 * parametric public route, so a row named `create` would still open the create
 * screen rather than itself; what the row loses is its own URL, and a snippet whose
 * canonical link resolves to somebody else's form is worse than a rejected save.
 */
class SnippetSlug extends SlugRule
{
    /**
     * @return class-string<Model>
     */
    protected function model(): string
    {
        return LabSnippet::class;
    }

    protected function noun(): string
    {
        return 'snippet';
    }

    /**
     * @return array<int, string>
     */
    protected function reserved(): array
    {
        return [
            'dashboard',
            'sandbox',
            'snippets',
            'create',
            'history',
            'gallery',
            'edit',
        ];
    }
}
