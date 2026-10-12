<?php

namespace App\Rules;

use App\Models\Post;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a post slug.
 *
 * Three constraints, all of which matter for a URL that is meant to be stable:
 *
 *   1. **Format.** Lowercase alphanumerics and single hyphens only. Anything
 *      else has to be percent-encoded, which makes the URL ugly when pasted and
 *      can be mangled by clients that do not encode consistently.
 *
 *   2. **Uniqueness.** Checked in the database rather than in memory, because
 *      the unique index is the real authority — an application-level check races
 *      with a concurrent write.
 *
 *   3. **Exclusion of self.** Editing a post must not collide with its own slug,
 *      so the post being edited is excluded from the uniqueness check.
 *
 * Soft-deleted posts are excluded from the collision check. Their slug is free
 * to be reused; the redirect for the old URL is a separate concern.
 */
class PostSlug implements ValidationRule
{
    public function __construct(
        protected ?int $ignorePostId = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The slug must be a string.'));

            return;
        }

        $slug = trim($value);

        if ($slug === '') {
            $fail(__('The slug is required.'));

            return;
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $fail(__('Use lowercase letters, numbers, and single hyphens only — for example "my-post-title".'));

            return;
        }

        if (mb_strlen($slug) > 255) {
            $fail(__('The slug must not be longer than 255 characters.'));

            return;
        }

        $exists = Post::withTrashed()
            ->where('slug', $slug)
            ->when(
                $this->ignorePostId !== null,
                fn ($query) => $query->whereKeyNot($this->ignorePostId),
            )
            ->exists();

        if ($exists) {
            $fail(__('That slug is already in use by another post.'));
        }
    }
}
