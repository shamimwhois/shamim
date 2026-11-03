<?php

namespace App\Http\Controllers;

use App\Support\HttpErrorCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Renders the HTTP error pages under one dynamic route.
 *
 * `/errors/{code}` is the preview surface for the same views the exception
 * handler renders on a real `abort()` — one route, one component, six
 * statuses. The response carries the *actual* status: `/errors/404` is a
 * 404, honestly. An unsupported code (say `/errors/418`) falls through to
 * the 404 page, which is the truthful answer to "show me error 418".
 */
final class ErrorPageController
{
    public function __invoke(Request $request, string $code): Response
    {
        $status = (int) $code;

        /*
         * The route's `whereIn` already filters, but this guard keeps the
         * controller honest on its own: a code outside the catalog never
         * reaches the view, even if the route pattern and the catalog ever
         * drift apart.
         */
        abort_unless(HttpErrorCatalog::describes($status), 404);

        return response()
            ->view('errors.'.$status, ['code' => $status], $status);
    }
}
