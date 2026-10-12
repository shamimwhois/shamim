<?php

namespace App\Http\Requests\Editor;

use App\Enums\AccountPermission;
use App\Enums\ContentCategory;
use App\Enums\ContentTone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A request to generate a draft.
 *
 * The word-count bound is not a fixed number: it depends on the chosen
 * category, because a 2500-word social post is nonsense and a 40-word product
 * description is a different thing from a short one. The rule is therefore
 * built from {@see ContentCategory::wordRange()} at validation time rather than
 * declared as static min/max, so the category definitions remain the single
 * source of truth for what is a reasonable length.
 */
class GenerationRequest extends FormRequest
{
    /**
     * Determine whether the account may make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(AccountPermission::CreatePost) ?? false;
    }

    /**
     * Get the validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ContentCategory::class)],
            'tone' => ['sometimes', Rule::enum(ContentTone::class)],
            'subject' => ['nullable', 'string', 'max:500'],
            'word_count' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'source_text' => ['nullable', 'string', 'max:20000'],
            'audience' => ['nullable', 'string', 'max:200'],
            'keywords' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $category = $this->category();

                if ($category === null) {
                    return; // Already reported by the enum rule.
                }

                [$min, $max] = $category->wordRange();
                $requested = $this->input('word_count');

                if (is_numeric($requested) && ((int) $requested < $min || (int) $requested > $max)) {
                    $validator->errors()->add(
                        'word_count',
                        "A {$category->label()} should be between {$min} and {$max} words.",
                    );
                }

                // A rewrite with nothing to rewrite is not an operation. The UI
                // disables the action, but the endpoint must not trust that.
                if ($category->requiresSourceText() && blank($this->input('source_text')) && blank($this->input('subject'))) {
                    $validator->errors()->add(
                        'source_text',
                        'This category rewrites existing text, so there needs to be something to rewrite.',
                    );
                }

                // Every other category needs a subject or it has nothing to aim at.
                if (! $category->requiresSourceText() && blank($this->input('subject'))) {
                    $validator->errors()->add(
                        'subject',
                        'Describe what you want written.',
                    );
                }
            },
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Choose what kind of content to write.',
            'category.enum' => 'That content type is not supported.',
            'subject.max' => 'That subject is too long. Summarise it in a sentence or two.',
            'source_text.max' => 'That source text is too long. Select a smaller part of it.',
        ];
    }

    /**
     * The chosen category.
     */
    public function category(): ?ContentCategory
    {
        $value = $this->input('category');

        return is_string($value) ? ContentCategory::tryFrom($value) : null;
    }

    /**
     * The chosen tone, defaulting to the category's own default.
     */
    public function tone(): ContentTone
    {
        $value = $this->input('tone');

        $tone = is_string($value) ? ContentTone::tryFrom($value) : null;

        return $tone ?? $this->category()?->defaultTone() ?? ContentTone::Professional;
    }

    /**
     * The requested word count, or 0 meaning "use the category default".
     */
    public function wordCount(): int
    {
        $value = $this->input('word_count');

        return is_numeric($value) ? (int) $value : 0;
    }
}
