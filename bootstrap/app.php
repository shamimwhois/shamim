<?php

use App\Http\Middleware\EnsureAccountPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Account-level permission guard, used as `permission:post:publish` on
         * routes that must not even render for an unauthorised account.
         */
        $middleware->alias([
            'permission' => EnsureAccountPermission::class,
        ]);

        /*
         * Where a guest lands when a protected route refuses them.
         *
         * The authentication prompt is a modal dialog, not a page: bouncing a
         * visitor to a full login screen would drop the context they were in.
         * So the guest returns to the site with `?signin=required`, which the
         * login modal reads to open itself on arrival. The destination stays
         * the site's landing page so the redirect can never loop.
         *
         * The attempted URL is not lost — `redirect()->guest()`, which renders
         * this redirect, stores it as `url.intended` first, so signing in from
         * the modal continues to exactly the page the guest tried to open
         * (see App\Http\Responses\LoginResponse, which prefers the intended
         * URL over the role dashboard).
         *
         * JSON requests are unaffected: the exception handler answers them with
         * a 401 before this redirect is ever built.
         */
        $middleware->redirectGuestsTo(fn (Request $request): string => route('home').'?signin=required');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Uncaught exceptions are not `HttpExceptionInterface`, so the
         * framework's `errors.{status}` view discovery never reaches them and
         * a visitor would get the bare fallback page. Render the styled 500
         * for browser requests — but only outside debug mode, where the
         * developer needs the real trace more than the friendly page. JSON
         * clients keep their structured error either way.
         */
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if ($e instanceof HttpExceptionInterface
                || $request->expectsJson()
                || config('app.debug')
            ) {
                return null;
            }

            return response()->view('errors.500', ['code' => 500], 500);
        });
    })->create();
