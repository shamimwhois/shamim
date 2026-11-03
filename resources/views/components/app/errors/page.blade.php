@props([
    'code',
])

@php
    /**
     * The HTTP error page, for every supported status.
     *
     * One component renders all of them so the six pages can never drift
     * apart in styling or structure: a giant monospace status code in the
     * site's terminal motif, a plain-language title and detail from
     * `App\Support\HttpErrorCatalog`, and one obvious way out.
     *
     * It renders inside the public guest shell — the same header, footer and
     * theme plumbing every public page uses — so an error never looks like
     * the visitor left the site. Error pages are `noindex`: a crawler that
     * lands on one should not file it.
     *
     * An unsupported code refuses to render rather than showing an empty
     * frame; the dynamic route filters codes before reaching here, so this
     * guard is the defensive backstop for direct view calls.
     */
    $code = (int) $code;

    abort_unless(App\Support\HttpErrorCatalog::describes($code), 404);

    $copy = App\Support\HttpErrorCatalog::describe($code);

    /*
     * A real abort renders this page through the exception handler, which
     * runs outside the `web` middleware group — so `ShareErrorsFromSession`
     * never ran, and the guest shell's auth dialogs would die on an
     * undefined `$errors`. Sharing an empty bag covers that render path; on
     * a normal route request this is a no-op, because the middleware has
     * already shared the real one.
     */
    view()->share('errors', $errors ?? new Illuminate\Support\ViewErrorBag);

    app(App\Support\Seo::class)->set([
        'title' => $code.' · '.$copy['title'],
        'description' => $copy['detail'],
        'noindex' => true,
    ]);
@endphp

<x-layouts::guest.home>
    <section class="shell flex min-h-[60vh] flex-col items-center justify-center py-24 text-center">
        <p
            class="font-mono text-xs font-medium uppercase tracking-widest text-signal-700 dark:text-signal-400"
            aria-hidden="true"
        >
            {{ __('Error') }} · HTTP {{ $code }}
        </p>

        {{-- The status code is decoration; the title below carries the meaning.
             Both are hidden from assistive tech so a screen reader hears the
             title once instead of "four zero four" spelled out. --}}
        <p
            class="mt-4 font-mono text-[clamp(5rem,18vw,10rem)] font-semibold leading-none tracking-tight text-ink-900 select-none dark:text-ink-50"
            aria-hidden="true"
        >
            {{ $code }}
        </p>

        <h1 class="mt-8 text-2xl font-semibold tracking-tight text-ink-900 dark:text-ink-50">
            {{ $copy['title'] }}
        </h1>

        <p class="mt-3 max-w-md text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $copy['detail'] }}
        </p>

        <div class="mt-10">
            <flux:button variant="primary" :href="route('home')" icon="arrow-uturn-left" data-test="error-home-link">
                {{ __('Back to the homepage') }}
            </flux:button>
        </div>
    </section>
</x-layouts::guest.home>
