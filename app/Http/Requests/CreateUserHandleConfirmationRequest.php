<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\UniqueHandle;
use Illuminate\Foundation\Http\FormRequest;

final class CreateUserHandleConfirmationRequest extends FormRequest
{
    /**
     * The same shape as a GitHub handle: up to 39 letters, digits, and single hyphens, not at either end, and
     * nobody else may already hold it whatever case they wrote it in. Shared by both steps of the change so the
     * confirmation is issued for a handle that could actually land.
     *
     * @return array<int, string|UniqueHandle>
     */
    public static function handleRules(User $user): array
    {
        return [
            'bail',
            'required',
            'string',
            'max:39',
            'regex:/^[a-z\d](?:[a-z\d]|-(?=[a-z\d]))*$/i',
            new UniqueHandle($user->github_id),
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|UniqueHandle>>
     */
    public function rules(): array
    {
        $user = $this->user();
        assert($user instanceof User);

        return [
            'handle' => self::handleRules($user),
        ];
    }

    public function handle(): string
    {
        return $this->string('handle')->value();
    }
}
