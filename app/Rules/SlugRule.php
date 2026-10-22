<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Shared validation for a human-editable, URL-visible slug.
 *
 * Three constraints, all of which matter for a URL that is meant to be stable,
 * inherited from the checks `App\Rules\PostSlug` established for blog posts:
 *
 *   1. **Format.** Lowercase alphanumerics and single hyphens only. Anything
 *      else has to be percent-encoded, which makes the URL ugly when pasted and
 *      can be mangled by clients that do not encode consistently.
 *
 *   2. **Uniqueness.** Checked in the database rather than in memory, because the
 *      unique index is the real authority — an application-level check races with
 *      a concurrent write.
 *
 *   3. **Exclusion of self.** Editing a row must not collide with its own slug, so
 *      the row being edited is excluded from the uniqueness check.
 *
 * A fourth constraint belongs to the modules this application builds as
 * workspaces: a slug is a path segment, and the segment is already occupied by
 * screens. A subclass declares which words are reserved so that naming an idea
 * "plans" cannot shadow `/ideas/plans`. Routing order already puts the literals
 * first; this rule is what stops the data from lying about it.
 */
abstract class SlugRule implements ValidationRule
{
    public function __construct(
        protected ?int $ignoreId = null,
    ) {}

    /**
     * The model whose table holds the unique index.
     *
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * The noun used in the failure messages, so "another post" and "another
     * snippet" each read as themselves.
     */
    abstract protected function noun(): string;

    /**
     * The second path segments this URL space already uses for screens.
     *
     * @return array<int, string>
     */
    abstract protected function reserved(): array;

    /**
     * Run the rule.
     *
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
            $fail(__('Use lowercase letters, numbers, and single hyphens only, for example "my-idea-title".'));

            return;
        }

        if (mb_strlen($slug) > 255) {
            $fail(__('The slug must not be longer than 255 characters.'));

            return;
        }

        if (in_array($slug, $this->reserved(), true)) {
            $fail(__('That slug is reserved by a screen in this area. Choose another one.'));

            return;
        }

        $model = $this->model();

        $exists = $model::query()
            ->where('slug', $slug)
            ->when(
                $this->ignoreId !== null,
                fn ($query) => $query->whereKeyNot($this->ignoreId),
            )
            ->exists();

        if ($exists) {
            $fail(__('That slug is already in use by another '.$this->noun().'.'));
        }
    }
}
