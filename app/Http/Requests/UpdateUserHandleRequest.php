<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CreateHandleChangeConfirmation;
use App\Models\User;
use App\Rules\UniqueHandle;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Validator;

final class UpdateUserHandleRequest extends FormRequest
{
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
            'handle' => CreateUserHandleConfirmationRequest::handleRules($user),
            'confirmation' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * The confirmation is a one-time secret issued for one specific handle, so it is verified here rather than in
     * the action: it must match both the stored hash and the handle it was issued for, or the change is rejected
     * back onto the confirmation field. A name claimed in the meantime is caught by the unique rule above; the
     * action consumes the secret, so the browser can only redeem it once.
     *
     * @return Closure(Validator): void
     */
    public function after(Validator $validator): Closure
    {
        return function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            assert($user instanceof User);

            $pending = Cache::get(CreateHandleChangeConfirmation::key($user));

            $valid = is_array($pending)
                && isset($pending['confirmation'], $pending['handle'])
                && is_string($pending['confirmation'])
                && is_string($pending['handle'])
                && hash_equals($pending['confirmation'], hash('sha256', $this->string('confirmation')->value()))
                && hash_equals($pending['handle'], $this->string('handle')->value());

            if (! $valid) {
                $validator->errors()->add('confirmation', 'That confirmation is missing, expired, or no longer matches. Start the change again.');
            }
        };
    }

    public function handle(): string
    {
        return $this->string('handle')->value();
    }

    public function confirmation(): string
    {
        return $this->string('confirmation')->value();
    }
}
