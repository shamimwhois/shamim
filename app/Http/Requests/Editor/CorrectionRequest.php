<?php

namespace App\Http\Requests\Editor;

use App\Enums\AccountPermission;
use App\Enums\CorrectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to review a passage for corrections.
 */
class CorrectionRequest extends FormRequest
{
    /**
     * Determine whether the account may make this request.
     *
     * Authorisation is enforced on the route by `permission:post:create`; this
     * is a second, local check so the rule still holds if the route guard is
     * ever changed.
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
            'text' => ['required', 'string', 'min:2', 'max:20000'],
            'types' => ['sometimes', 'array', 'max:6'],
            'types.*' => [Rule::enum(CorrectionType::class)],
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
            'text.required' => 'There is nothing to review yet.',
            'text.min' => 'Add a few more words before asking for a review.',
            'text.max' => 'This passage is too long to review at once. Select a smaller part of it.',
            'types.*.enum' => 'One of the requested correction types is not supported.',
        ];
    }

    /**
     * Get the correction types to filter by.
     *
     * An empty list means "all types", which is also what the agent defaults
     * to when its allowlist is empty.
     *
     * @return array<int, CorrectionType>
     */
    public function types(): array
    {
        /** @var array<int, string>|null $values */
        $values = $this->validated('types');

        if (! is_array($values) || $values === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $value): ?CorrectionType => CorrectionType::tryFrom($value),
            $values,
        )));
    }

    /**
     * The text to review.
     */
    public function text(): string
    {
        return (string) $this->validated('text');
    }
}
