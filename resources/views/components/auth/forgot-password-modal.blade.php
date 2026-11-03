@props([])

{{--
    The forgot-password modal.

    The request half of the password-reset journey: it emails the reset link,
    whose email still opens the full-page `/reset-password/{token}` form —
    email links need a real page, so only this request step became a dialog.

    Opens from the `?forgot=required` flag (the redirected `/forgot-password`
    URL and the login dialog's "Forgot your password?" link), from the flashed
    `auth_flow` field after a validation failure, or from Fortify's success
    `status` — "we have emailed your reset link" — so the visitor sees the
    confirmation in place instead of hunting for it.
--}}

@php
    $open = request()->has('forgot')
        || old('auth_flow') === 'forgot'
        || (filled(session('status')) && ! request()->has('signin') && ! request()->has('signup'));

    $openLogin = '$flux.modal(\'forgot-password\').close(); $flux.modal(\'login\').show(); $event.preventDefault()';
@endphp

@guest
<div
    x-on:modal-close.window="if ($event.detail && $event.detail.name === 'forgot-password') {
        const url = new URL(window.location.href);
        if (url.searchParams.has('forgot')) {
            url.searchParams.delete('forgot');
            window.history.replaceState({}, '', url.pathname + url.hash);
        }
    }"
>
    <x-app.ui.modal
        name="forgot-password"
        :title="__('Forgot your password?')"
        :description="__('Enter your email and we will send you a reset link')"
        :show="$open"
        max-width="md"
        :data-modal-open="$open ? 'true' : 'false'"
    >
        <x-app.ui.auth.session-status class="mb-6" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="space-y-6" data-test="forgot-modal-form">
            @csrf
            <input type="hidden" name="auth_flow" value="forgot">

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
                data-test="forgot-modal-email"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="forgot-modal-submit">
                {{ __('Email password reset link') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-ink-500 dark:text-ink-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link
                :href="route('login')"
                x-on:click="{!! $openLogin !!}"
                data-test="forgot-modal-login-link"
            >
                {{ __('log in') }}
            </flux:link>
        </div>
    </x-app.ui.modal>
</div>
@endguest
