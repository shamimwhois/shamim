<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * Update an account's identity fields.
 *
 * `role` is never written here — role changes go through the dedicated
 * `updateRole()` path on the accounts console, which enforces the assignable
 * set and the peer rule. Keeping this action role-blind means the edit form
 * cannot become a second, drifting copy of that logic.
 */
class UpdateUser
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, User $target, array $data): User
    {
        $target->fill([
            'name' => $data['name'],
            'handle' => $data['handle'] ?? null,
            'email' => $data['email'],
        ]);

        // A blank password means "leave it alone"; a filled one is re-hashed
        // by the model's `hashed` cast. Changing the email does not reset the
        // verification timestamp — the account keeps whatever it had.
        if (filled($data['password'] ?? null)) {
            $target->password = $data['password'];
        }

        $target->save();

        return $target->refresh();
    }
}
