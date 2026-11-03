<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Policies\UserPolicy;

/**
 * Permanently delete an account.
 *
 * This is a hard delete, and it is loud about it: every row that references
 * the user with `cascadeOnDelete` — posts, comments, ideas, lab work,
 * marketplace listings and reviews, memberships — is destroyed with the
 * account and cannot be restored. Authorisation (no self-delete, no deleting
 * a peer or superior) is enforced by {@see UserPolicy::delete()}
 * at the call site; this action assumes it has passed.
 */
class DeleteUser
{
    public function handle(User $target): void
    {
        $target->delete();
    }
}
