@props([])

{{--
    The login modal — the application's authentication prompt.

    Guest-facing surfaces render this through `x-auth.modals`; it renders
    nothing for a signed-in account, so a layout can always include the group
    and never needs a guest check of its own. It is the real Fortify login
    form: a plain POST to `route('login.store')`, so the credentials are
    validated, the session is regenerated, throttling and two-factor all run
    exactly as they did on the standalone login page — which no longer exists,
    its URL now redirects here with the dialog already open.

    ## Why it exists

    A guest who tries to enter a protected area used to be bounced to a
    separate login page and lost the page they were reading. `bootstrap/app.php`
    returns them to the site with `?signin=required`; this component sees that
    flag and opens itself, so the reader never leaves the context. The
    attempted URL survives as `url.intended`, so a successful sign-in lands on
    exactly the page that refused them (`App\Http\Responses\LoginResponse`
    prefers the intended URL over the role dashboard).

    ## The four ways it opens

      1. `?signin=required` — after a protected route bounced the guest, or
         via the redirected `/login` URL (the no-JS fallback path).
      2. A failed submit — the hidden `auth_flow` field comes back through
         Fortify's `withInput()` flash, so the dialog that failed reopens even
         when another dialog shares an error key. `old('email')` keeps the
         typed address intact.
      3. A click — any guest control wired to `$flux.modal('login').show()`.
      4. A direct POST with no page behind it — the error-key fallback below
         keeps that legacy path opening this dialog rather than nothing.

    ## The flag is cleaned up

    A query flag left in the address bar would reopen the modal on every
    reload, so closing the dialog strips it with `history.replaceState` — one
    history entry rewritten, no navigation, no Livewire re-render.
--}}

@php
    $flow = old('auth_flow');

    $open = (request()->has('signin')
            && ! request()->has('signup')
            && ! request()->has('forgot')
            && ! in_array($flow, ['register', 'forgot'], true))
        || $flow === 'login'
        || ($flow === null
            && ! request()->has('signup')
            && ! request()->has('forgot')
            && ($errors->has('email') || $errors->has('password')));

    // Cross-modal switches close the dialog that is open first, so two
    // dialogs never stack. The plain href stays as the no-JS fallback: it
    // reaches the Fortify route, which redirects home with the target
    // dialog's flag set.
    $openForgot = '$flux.modal(\'login\').close(); $flux.modal(\'forgot-password\').show(); $event.preventDefault()';
    $openRegister = '$flux.modal(\'login\').close(); $flux.modal(\'register\').show(); $event.preventDefault()';
@endphp

@guest
<div
    x-on:modal-close.window="if ($event.detail && $event.detail.name === 'login') {
        const url = new URL(window.location.href);
        if (url.searchParams.has('signin')) {
            url.searchParams.delete('signin');
            window.history.replaceState({}, '', url.pathname + url.hash);
        }
    }"
>
    <x-app.ui.modal
        name="login"
        :title="__('Sign in')"
        :description="__('Enter your credentials to continue to your workspace.')"
        :show="$open"
        max-width="md"
        :data-modal-open="$open ? 'true' : 'false'"
    >
        <x-app.ui.auth.session-status class="mb-6" :status="session('status')" />

        <x-app.ui.auth.passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="space-y-6" data-test="login-modal-form">
            @csrf
            {{-- Names the flow through the failure redirect: `old('auth_flow')`
                 is what reopens this dialog instead of a sibling's. --}}
            <input type="hidden" name="auth_flow" value="login">

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                data-test="login-modal-email"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                    data-test="login-modal-password"
                />

                @if (Route::has('password.request'))
                    <flux:link
                        class="absolute top-0 text-sm end-0"
                        :href="route('password.request')"
                        x-on:click="{!! $openForgot !!}"
                        data-test="login-modal-forgot-link"
                    >
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-modal-submit">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-ink-600 dark:text-ink-400">
            <span>{{ __("Don't have an account?") }}</span>
            <flux:link
                :href="route('register')"
                x-on:click="{!! $openRegister !!}"
                data-test="login-modal-register-link"
            >
                {{ __('Sign up') }}
            </flux:link>
        </div>
    </x-app.ui.modal>
</div>
@endguest
