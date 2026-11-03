<?php

namespace App\Http\Requests\Editor;

use App\Enums\AccountPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to refine a rough image idea into a generation prompt.
 */
class ImagePromptRequest extends FormRequest
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
            'idea' => ['required', 'string', 'min:3', 'max:1000'],
            'context' => ['nullable', 'string', 'max:6000'],
            'style' => ['nullable', 'string', 'max:100'],
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
            'idea.required' => 'Describe the image you want.',
            'idea.min' => 'Add a little more detail — a few words is not enough to work from.',
            'idea.max' => 'That description is too long. Keep it to a sentence or two.',
        ];
    }

    /**
     * The rough image idea.
     */
    public function idea(): string
    {
        return (string) $this->validated('idea');
    }

    /**
     * Surrounding article context, used to inform subject and mood.
     */
    public function context(): string
    {
        return (string) ($this->validated('context') ?? '');
    }

    /**
     * A requested style, or `auto` to let the model choose.
     */
    public function style(): string
    {
        return (string) ($this->validated('style') ?? 'auto');
    }
}
