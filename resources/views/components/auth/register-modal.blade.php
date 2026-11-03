@props([])

{{--
    The registration modal.

    Carries the exact fields the standalone register page had — name, email,
    password and confirmation, including the `passwordrules` hints the page
    published from `Password::defaults()` — and posts to the same Fortify
    endpoint (`register.store`), so validation, the least-privileged role
    assignment, and the redirect to the role dashboard are unchanged.

    Opens server-side from the `?signup=required` flag (the redirected
    `/register` URL and guest "Join now" triggers) or, after a failed submit,
    from the flashed `auth_flow` field. Closing it strips the flag the same
    way the login modal strips `signin`.
--}}

@php
    $open = request()->has('signup') || old('auth_flow') === 'register';

    $openLogin = '$flux.modal(\'register\').close(); $flux.modal(\'login\').show(); $event.preventDefault()';
@endphp

@guest
<div
    x-on:modal-close.window="if ($event.detail && $event.detail.name === 'register') {
        const url = new URL(window.location.href);
        if (url.searchParams.has('signup')) {
            url.searchParams.delete('signup');
            window.history.replaceState({}, '', url.pathname + url.hash);
        }
    }"
>
    <x-app.ui.modal
        name="register"
        :title="__('Create an account')"
        :description="__('Enter your details below to create your account')"
        :show="$open"
        max-width="md"
        :data-modal-open="$open ? 'true' : 'false'"
    >
        <form method="POST" action="{{ route('register.store') }}" class="space-y-6" data-test="register-modal-form">
            @csrf
            <input type="hidden" name="auth_flow" value="register">

            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
                data-test="register-modal-name"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
                data-test="register-modal-email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
                data-test="register-modal-password"
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
                data-test="register-modal-password-confirmation"
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-modal-submit">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-ink-600 dark:text-ink-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link
                :href="route('login')"
                x-on:click="{!! $openLogin !!}"
                data-test="register-modal-login-link"
            >
                {{ __('Log in') }}
            </flux:link>
        </div>
    </x-app.ui.modal>
</div>
@endguest
