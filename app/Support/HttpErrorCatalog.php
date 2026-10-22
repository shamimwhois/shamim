<?php

namespace App\Support;

/**
 * The copy for the application's HTTP error pages.
 *
 * One catalog, three consumers: the reusable error-page component renders
 * from it, the dynamic `/errors/{code}` route validates against it, and the
 * route's `whereIn` pattern is derived from it — so a status code can never
 * exist in one place and not the others.
 *
 * The list is deliberately short. These are the statuses a *visitor* can
 * meaningfully see as a full page in this application: 400 (malformed
 * request), 401 (unauthenticated — the JSON API's answer to a guest), 403
 * (the permission guard's abort), 404 (unknown route or model), 500 (an
 * uncaught failure, rendered outside debug mode), and 503 (maintenance).
 * Everything else stays a bare framework response on purpose — a page for a
 * status nobody can hit is decoration, not error handling.
 */
final class HttpErrorCatalog
{
    /**
     * The supported statuses and their visitor-facing copy, keyed by code.
     *
     * @return array<int, array{title: string, detail: string}>
     */
    public static function all(): array
    {
        return [
            400 => [
                'title' => __('Bad request'),
                'detail' => __('The server could not make sense of that request. It may be malformed, or the link you followed may be out of date.'),
            ],
            401 => [
                'title' => __('Sign in required'),
                'detail' => __('That request needs a signed-in account. Sign in from any page and pick up where you left off.'),
            ],
            403 => [
                'title' => __('No access'),
                'detail' => __('Your account does not have permission to open this page. If you believe that is a mistake, ask the site owner to check your role.'),
            ],
            404 => [
                'title' => __('Page not found'),
                'detail' => __('The page you were looking for does not exist, has moved, or was never here. It is worth checking the address for a typo.'),
            ],
            500 => [
                'title' => __('Something went wrong'),
                'detail' => __('The server hit an unexpected error while building this page. It has been noted — trying again in a moment usually clears it.'),
            ],
            503 => [
                'title' => __('Back shortly'),
                'detail' => __('The site is briefly unavailable, usually for maintenance. Nothing is lost — refresh in a minute and it should be back.'),
            ],
        ];
    }

    /**
     * Whether the catalog carries a page for this status.
     */
    public static function describes(int $code): bool
    {
        return array_key_exists($code, self::all());
    }

    /**
     * The copy for one status. A caller that skips `describes()` gets the
     * 404 copy, never an array index error.
     *
     * @return array{title: string, detail: string}
     */
    public static function describe(int $code): array
    {
        return self::all()[$code] ?? self::all()[404];
    }

    /**
     * The supported statuses, for the route's `whereIn` constraint.
     *
     * @return array<int, int>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }
}
