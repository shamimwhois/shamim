<?php

namespace App\Http\Middleware;

use App\Enums\AccountPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level account-permission guard.
 *
 * The component-level checks in policies and `Gate::authorize()` are the real
 * enforcement. This middleware exists so that an unauthorised account never
 * *reaches* a page it cannot use — a 403 before the component boots, rather
 * than a rendered shell with a hidden button.
 *
 * Usage: `->middleware('permission:product:create')`
 *
 * Multiple permissions are OR'd by default (any of them grants access), which
 * matches how a navigation entry that is visible to two different roles should
 * behave. Prefix the list with `all:` to require every permission instead.
 */
class EnsureAccountPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        /*
         * Guests are bounced to the login page rather than shown a 403: they
         * may well hold the permission once they authenticate, and a 403 would
         * be a dead end. `auth` middleware normally runs first, so this is a
         * defensive branch for routes that omit it.
         */
        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        $requireAll = false;

        if (($permissions[0] ?? null) === 'all') {
            $requireAll = true;
            array_shift($permissions);
        }

        $required = array_filter(
            array_map(
                fn (string $permission) => AccountPermission::tryFrom($permission),
                $permissions,
            ),
        );

        /* An alias with no resolvable permission is a typo, not an allow. */
        abort_if($required === [], 500, 'Route declares no valid permission.');

        $granted = $requireAll
            ? $user->hasAllPermissions(...$required)
            : $user->hasAnyPermission(...$required);

        abort_unless($granted, 403);

        return $next($request);
    }
}
