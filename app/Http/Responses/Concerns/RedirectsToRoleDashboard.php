<?php

namespace App\Http\Responses\Concerns;

use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves where an account should land after authenticating.
 *
 * The destination is decided **entirely by the account's role**. There is no
 * team prefix, no `URL::defaults()` seeding, and no shared-destination
 * compromise: a user, a seller and an admin each own a distinct top-level
 * dashboard, because they see different navigation and different data.
 *
 * Sending everyone to one page and hiding links would not be access control —
 * it would only make the wrong page large, and it would leak the layout of a
 * surface the account cannot use.
 */
trait RedirectsToRoleDashboard
{
    /**
     * Resolve the post-authentication redirect path.
     *
     * `$redirect` is the path Fortify is configured to use (from
     * `config/fortify.php`). It is honoured only as a **last-resort fallback**:
     * the role's own dashboard always wins when the route exists.
     *
     * An explicit `intended` URL takes precedence over everything here — that
     * is the caller's job, since the callers apply `intended()` on top of the
     * value this returns. This method supplies only the fallback.
     */
    protected function redirectPathForRole(Request $request, string $redirect): string
    {
        return $this->dashboardPathFor($this->authenticatedUser($request), $redirect);
    }

    /**
     * Resolve the request's authenticated user.
     *
     * Every caller runs *after* the account is authenticated, so a missing user
     * is a programming error rather than a guest case — hence the abort rather
     * than a null return that would silently send someone to a default page.
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * Get the path of the dashboard the given account's role owns.
     *
     * Falls back to the configured path when the role's route is not registered
     * yet, so a role can be added to {@see AccountRole} before its
     * dashboard exists without breaking every login in the meantime.
     */
    protected function dashboardPathFor(User $user, string $redirect): string
    {
        $routeName = $user->dashboardRoute();

        if (app('router')->has($routeName)) {
            return route($routeName, absolute: false);
        }

        return $redirect;
    }
}
