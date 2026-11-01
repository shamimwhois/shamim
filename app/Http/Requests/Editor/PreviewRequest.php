<?php

namespace App\Http\Requests\Editor;

use App\Enums\AccountPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request to render Markdown for the editor's live preview.
 */
class PreviewRequest extends FormRequest
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
            'markdown' => ['present', 'string', 'max:100000'],
        ];
    }

    /**
     * The Markdown to render.
     */
    public function markdown(): string
    {
        return (string) $this->input('markdown', '');
    }
}
