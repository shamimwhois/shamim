<?php

use App\Concerns\ProfileValidationRules;
use App\Models\User;
/* @chisel-email-verification */
use Illuminate\Contracts\Auth\MustVerifyEmail;
/* @end-chisel-email-verification */
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Profile settings — who you are on the log.
 *
 * Three fields and a handle. The handle is the account's public address
 * (`/@{handle}`), so it is validated as a route segment and kept unique; the
 * name is what a commit's byline prints; the email is the account key, and
 * changing it re-opens verification.
 */
new #[Title('Profile settings')] #[Layout('layouts::feed')] class extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public string $handle = '';

    /**
     * The handle check's verdict, or null while there is nothing to say.
     *
     * Set by {@see checkHandle()} — the reader should learn whether a handle
     * is free while typing, not after the form refuses to save.
     */
    public ?string $handleStatus = null;

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->handle = (string) Auth::user()->handle;
    }

    /**
     * Check whether the typed handle is free.
     *
     * Runs on blur, one indexed query: cheap for the reader, and far kinder
     * than learning the handle was taken when the form refuses to save.
     */
    public function checkHandle(): void
    {
        $handle = trim($this->handle);

        // Nothing to say about the handle the account already owns.
        if ($handle === '' || $handle === (string) Auth::user()->handle) {
            $this->handleStatus = null;

            return;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{2,30}$/', $handle)) {
            $this->handleStatus = 'invalid';

            return;
        }

        $this->handleStatus = User::query()->where('handle', $handle)->exists()
            ? 'taken'
            : 'free';
    }

    /**
     * The name the byline preview signs, falling back to the saved one while
     * the field is being cleared.
     */
    #[Computed]
    public function previewName(): string
    {
        return trim($this->name) !== '' ? trim($this->name) : (string) Auth::user()->name;
    }

    /**
     * The handle the preview signs, without the address prefix.
     */
    #[Computed]
    public function previewHandle(): string
    {
        return trim($this->handle) !== '' ? ltrim(trim($this->handle), '@') : (string) Auth::user()->handle;
    }

    /**
     * Save the profile.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        // An account that has never had a handle keeps one derived from its
        // email rather than being blocked from saving its own name.
        $this->handle = trim($this->handle) !== ''
            ? trim($this->handle)
            : \App\Support\LogIdentity::handleFor($user);

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'handle' => [
                'required',
                'string',
                'min:2',
                'max:30',
                'regex:/^[A-Za-z0-9_-]+$/',
                'unique:users,handle,'.$user->id,
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // The handle, if it changed, now belongs to this account.
        $this->handleStatus = null;

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /* @chisel-email-verification */
    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route(auth()->user()->dashboardRoute(), absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
    /* @end-chisel-email-verification */
}; ?>

<x-log.settings tab="profile">
    <section class="dn-settings-section" aria-labelledby="settings-profile">
        <h2 id="settings-profile" class="dn-settings-title">{{ __('Profile') }}</h2>
        <p class="dn-settings-note">{{ __('Update the name and handle your posts are signed with.') }}</p>

        {{-- The signature the log will print. Live, because the reader is
             editing exactly the words it is built from — and it is the reason
             the name and handle fields exist at all. --}}
        <div class="dn-byline-preview" data-test="byline-preview">
            <p class="dn-byline-preview__label">{{ __('How your posts are signed') }}</p>

            <div class="dn-byline-preview__row">
                <span
                    class="dn-avatar"
                    style="--dn-lane: {{ \App\Support\LogIdentity::lane($this->previewHandle) }}; width: 34px; height: 34px; font-size: 12px;"
                >{{ \App\Support\LogIdentity::initials($this->previewName) }}</span>

                <div class="dn-byline-preview__who">
                    <p class="dn-byline-preview__name">{{ $this->previewName }}</p>
                    @if ($this->previewHandle !== '')
                        <p class="dn-byline-preview__meta">
                            {{ '@'.$this->previewHandle }} · {{ Auth::user()->accountRole()->label() }}
                        </p>
                    @else
                        <p class="dn-byline-preview__meta">{{ Auth::user()->accountRole()->label() }}</p>
                    @endif
                </div>

                @if ($this->previewHandle !== '')
                    <a
                        href="{{ route('profile.show', ['handle' => $this->previewHandle]) }}"
                        wire:navigate
                        class="dn-byline-preview__go"
                    >{{ __('View profile') }}</a>
                @endif
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="dn-fields">
            <div class="dn-field">
                <label class="dn-field-key" for="settings-name">{{ __('name') }}</label>
                <input
                    id="settings-name"
                    wire:model.lazy="name"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    class="dn-input"
                    data-test="name-input"
                />
            </div>

            <div class="dn-field">
                <label class="dn-field-key" for="settings-handle">{{ __('handle') }}</label>
                <div style="display: flex; align-items: center; gap: 6px; min-width: 0;">
                    <span class="dn-field-key" style="font-size: 14px;">@</span>
                    <input
                        id="settings-handle"
                        wire:model="handle"
                        wire:blur="checkHandle"
                        type="text"
                        required
                        autocomplete="username"
                        class="dn-input"
                        data-test="handle-input"
                    />
                </div>

                @if ($handleStatus !== null)
                    <p class="dn-handle-status is-{{ $handleStatus }}" data-test="handle-status">
                        {{ [
                            'free' => __('That handle is free.'),
                            'taken' => __('That handle is taken.'),
                            'invalid' => __('Use 2–30 letters, numbers, dashes or underscores.'),
                        ][$handleStatus] }}
                    </p>
                @endif
            </div>

            <div class="dn-field">
                <label class="dn-field-key" for="settings-email">{{ __('email') }}</label>
                <input
                    id="settings-email"
                    wire:model="email"
                    type="email"
                    required
                    autocomplete="email"
                    class="dn-input"
                    data-test="email-input"
                />
            </div>

            @error('name')
                <p class="dn-field"><span class="dn-field-key">{{ __('error') }}</span><span class="dn-form-error">{{ $message }}</span></p>
            @enderror
            @error('handle')
                <p class="dn-field"><span class="dn-field-key">{{ __('error') }}</span><span class="dn-form-error">{{ $message }}</span></p>
            @enderror
            @error('email')
                <p class="dn-field"><span class="dn-field-key">{{ __('error') }}</span><span class="dn-form-error">{{ $message }}</span></p>
            @enderror

            <div class="dn-field" style="justify-content: flex-end; display: flex;">
                <button type="submit" class="dn-btn" data-test="update-profile-button">
                    {{ __('Save') }}
                </button>
            </div>
        </form>

        @if ($this->hasUnverifiedEmail)
            <p class="dn-settings-note" style="margin-top: 12px;">
                {{ __('Your email address is unverified.') }}
                <a href="#" wire:click.prevent="resendVerificationNotification" class="dn-link">
                    {{ __('Re-send the verification email.') }}
                </a>
            </p>

            @if (session('status') === 'verification-link-sent')
                <p class="dn-form-status" style="margin-top: 8px;">
                    {{ __('A new verification link has been sent to your email address.') }}
                </p>
            @endif
        @endif

    </section>

    {{-- @chisel-email-verification --}}
    @if ($this->showDeleteUser)
        <section class="dn-settings-section dn-danger-zone" aria-labelledby="settings-delete">
            {{-- The delete flow keeps its own component (and its confirmation
                 dialog); the zone only gives it the log's frame and a red
                 hairline so it reads as the last thing on the page. --}}
            <livewire:pages::settings.delete-user-form />
        </section>
    @endif
    {{-- @end-chisel-email-verification --}}
</x-log.settings>
