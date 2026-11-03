<?php

namespace App\Http\Requests\Editor;

use App\Enums\AccountPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to generate and store an image.
 *
 * This is the most expensive operation in the editor, so it validates the
 * tightest: a fixed prompt ceiling, an allow-listed set of sizes, and a hard
 * requirement that the caller holds `post:create`.
 */
class ImageGenerationRequest extends FormRequest
{
    /**
     * The sizes the image providers reliably support across the board.
     *
     * Restricted to a known set rather than accepting a free-form string: an
     * unsupported size is rejected by some providers and silently ignored by
     * others, which produces an image at an unexpected aspect ratio rather than
     * an error.
     *
     * @var array<int, string>
     */
    public const SIZES = ['1024x1024', '1792x1024', '1024x1792'];

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
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'alt_text' => ['nullable', 'string', 'max:250'],
            'size' => ['nullable', 'string', Rule::in(self::SIZES)],
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
            'prompt.required' => 'There is no image prompt to generate from.',
            'prompt.max' => 'That image prompt is too long. Shorten it and try again.',
            'size.in' => 'That image size is not supported.',
        ];
    }

    /**
     * The image prompt.
     */
    public function prompt(): string
    {
        return (string) $this->validated('prompt');
    }

    /**
     * Alt text for the resulting image.
     */
    public function altText(): string
    {
        return (string) ($this->validated('alt_text') ?? '');
    }

    /**
     * The requested image size, or null for the provider's default.
     */
    public function size(): ?string
    {
        $size = $this->validated('size');

        return is_string($size) ? $size : null;
    }
}
