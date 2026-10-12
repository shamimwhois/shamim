<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Resolves where a user should land after authenticating.
 *
 * Two concerns are deliberately separated here:
 *
 * 1. **Which dashboard** — decided by the account's role. A user, a seller and
 *    an admin each have their own dashboard home, because they see different
 *    navigation and different data. Sending everyone to one page and hiding
 *    links is not access control; it just makes the wrong page large.
 *
 * 2. **The team URL prefix** — the starter kit nests team pages under
 *    `/{current_team}/`, and `URL::defaults()` must be seeded so `route()`
 *    inside those pages can generate relative names. That requirement applies
 *    *only* to team-scoped destinations. The role dashboards are not team-scoped
 *    (see `routes/web.php`), so prefixing them with a team slug would produce a
 *    404 on a URL segment that carries no meaning.
 */
trait RedirectsToCurrentTeam
{
    /**
     * Resolve the post-authentication redirect path.
     *
     * `$redirect` is the path Fortify is configured to use (typically
     * `/dashboard`). It is honoured only as a hint: we take its *path* and
     * re-point it at the destination the account's role actually owns.
     */
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $user = $this->authenticatedUser($request);

        $this->seedTeamUrlDefaults($user);

        /*
         * An explicit `intended` URL always wins — that is the whole point of
         * `redirect()->intended()`, and it is how a user who was bounced off a
         * deep link returns to it. The caller applies `intended()` on top of
         * this value, so we only need to supply the fallback.
         */
        return $this->dashboardPathFor($user, $redirect);
    }

    /**
     * Resolve the request's authenticated user.
     *
     * Registration and login both call this *after* the user is authenticated,
     * so a missing user is a programming error rather than a guest case.
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
     * Falls back to the role's route when the named route is not registered
     * yet, so a role can be added to the enum before its dashboard exists
     * without breaking every login. The `$redirect` path is the last resort.
     */
    protected function dashboardPathFor(User $user, string $redirect): string
    {
        $routeName = $user->dashboardRoute();

        if (app('router')->has($routeName)) {
            return route($routeName, absolute: false);
        }

        return $redirect;
    }

    /**
     * Seed the `current_team` URL default, if the account has a team.
     *
     * Separate from the redirect decision because it is only needed by routes
     * that are nested under `/{current_team}/`. It is harmless when unused, so
     * it runs unconditionally rather than being conditionally threaded through
     * every caller.
     */
    protected function seedTeamUrlDefaults(User $user): void
    {
        $team = $this->currentTeam($user);

        if ($team instanceof Team) {
            URL::defaults(['current_team' => $team->slug]);
        }
    }

    /**
     * Get the user's current team, falling back to their personal team.
     *
     * Returns null rather than aborting: an account with no team is still a
     * perfectly valid account, and it must be able to log in. Only team-scoped
     * pages require a team, and those are guarded by `EnsureTeamMembership`.
     */
    protected function currentTeam(User $user): ?Team
    {
        return $user->currentTeam ?? $user->personalTeam();
    }
}
