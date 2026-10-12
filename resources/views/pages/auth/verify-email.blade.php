<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :eyebrow="__('Verification')"
            :title="__('Verify your email')"
            :description="__('We sent a verification link to :email.', ['email' => auth()->user()?->email ?? __('your inbox')])"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:text class="text-center text-pretty">
            {{ __('Click the link in that email to activate your account. If it has not arrived within a few minutes, check your spam folder or send another.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:callout variant="success" icon="check-circle">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:callout>
        @endif

        <div class="flex flex-col items-stretch gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="w-full cursor-pointer text-sm" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
