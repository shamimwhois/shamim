<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Profile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class UniqueHandle implements ValidationRule
{
    public function __construct(private string $githubId)
    {
        //
    }

    /**
     * Handles are unique case-insensitively, the same way GitHub treats them, so someone else's differently
     * cased handle still blocks this one. The own profile is excluded by its GitHub id, not by handle.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (Profile::query()
            ->whereRaw('LOWER(handle) = ?', [mb_strtolower($value)])
            ->where('github_id', '!=', $this->githubId)
            ->exists()) {
            $fail('That username is taken. Try another.');
        }
    }
}
