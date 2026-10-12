<x-layouts::auth :title="__('Two-factor authentication')">
    {{--
        The two-factor challenge.

        Two interchangeable inputs share one form: a six-digit TOTP code, or a
        recovery code. Alpine swaps between them client-side so a failure does
        not cost a round trip, and the server keeps the two fields distinct.

        The heading is rendered twice, inside the same `x-show` guards as the
        inputs it describes. That is intentional and it is what the previous
        version got wrong: it put the headings *outside* the form and before
        the `x-data` scope, so the visible heading and the visible input could
        be swapped independently of one another. Keeping them adjacent means a
        visitor can never read "Enter the authentication code" above a recovery
        code field.
    --}}
    <div class="flex flex-col gap-6">
        <div
            class="w-full"
            x-cloak
            x-data="{
                showRecoveryInput: @js($errors->has('recovery_code')),
                code: '',
                recovery_code: '',
                focusOtp() {
                    this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
                },
                init() {
                    if (! this.showRecoveryInput) {
                        this.focusOtp();
                    }
                },
                toggleInput() {
                    this.showRecoveryInput = !this.showRecoveryInput;

                    this.code = '';
                    this.recovery_code = '';

                    $nextTick(() => {
                        this.showRecoveryInput
                            ? this.$refs.recovery_code?.focus()
                            : this.focusOtp();
                    });
                },
            }"
        >
            <div x-show="!showRecoveryInput">
                <x-auth-header
                    :eyebrow="__('Security')"
                    :title="__('Authentication code')"
                    :description="__('Enter the 6-digit code from your authenticator app.')"
                />
            </div>

            <div x-show="showRecoveryInput">
                <x-auth-header
                    :eyebrow="__('Security')"
                    :title="__('Recovery code')"
                    :description="__('Enter one of the recovery codes you saved when enabling two-factor authentication.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-6">
                @csrf

                <div class="space-y-5">
                    <div x-show="!showRecoveryInput">
                        <div class="my-5 flex items-center justify-center" x-ref="otp">
                            <flux:otp
                                x-model="code"
                                length="6"
                                name="code"
                                label="OTP Code"
                                label:sr-only
                                class="mx-auto"
                            />
                        </div>
                    </div>

                    <div x-show="showRecoveryInput">
                        <div class="my-5">
                            <flux:input
                                type="text"
                                name="recovery_code"
                                x-ref="recovery_code"
                                x-bind:required="showRecoveryInput"
                                autocomplete="one-time-code"
                                x-model="recovery_code"
                                :label="__('Recovery code')"
                            />
                        </div>

                        @error('recovery_code')
                            <flux:text class="text-center text-red-600 dark:text-red-400">
                                {{ $message }}
                            </flux:text>
                        @enderror
                    </div>

                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('Continue') }}
                    </flux:button>
                </div>

                <div class="mt-6 text-center text-sm leading-5">
                    <span class="text-ink-500 dark:text-ink-400">{{ __('or you can') }}</span>
                    <button
                        type="button"
                        class="cursor-pointer font-medium text-signal-600 underline-offset-4 hover:underline dark:text-signal-400"
                        @click="toggleInput()"
                    >
                        <span x-show="!showRecoveryInput">{{ __('sign in with a recovery code') }}</span>
                        <span x-show="showRecoveryInput">{{ __('sign in with an authentication code') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::auth>
