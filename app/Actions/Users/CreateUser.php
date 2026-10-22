<?php

namespace App\Actions\Users;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Create an account on behalf of an administrator.
 *
 * Deliberately separate from {@see CreateNewUser}, which
 * serves public registration and never reads a role from input. Here the role
 * IS an input, so the escalation guard lives in this action rather than in the
 * form: the chosen role is re-checked against
 * {@see AccountRole::assignableBy()} no matter what the caller validated.
 * The menu is a convenience; this check is the control.
 */
class CreateUser
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): User
    {
        $role = AccountRole::tryFrom((string) ($data['role'] ?? AccountRole::User->value))
            ?? AccountRole::User;

        $allowed = collect(AccountRole::assignableBy($actor->accountRole()))
            ->pluck('value')
            ->all();

        if (! in_array($role->value, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => __('You cannot assign that role.'),
            ]);
        }

        // `password` is hashed by the model's `hashed` cast; the account is
        // created unverified, same as one that registered itself.
        return User::create([
            'name' => $data['name'],
            'handle' => $data['handle'] ?? null,
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $role,
        ]);
    }
}
