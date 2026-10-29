<?php

use App\Models\SshKey;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Terminal — the keys that may push as you.
 *
 * A key is a name and a fingerprint; the private half never reaches the server,
 * which is why the list can print every row in full. Adding one validates the
 * fingerprint shape (SHA256, base64) rather than trusting a free-text field,
 * because a typo there is a key that silently never works.
 */
new #[Title('Terminal settings')] #[Layout('layouts::feed')] class extends Component
{
    public string $name = '';

    public string $fingerprint = '';

    /**
     * The keys this account owns.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SshKey>
     */
    #[Computed]
    public function keys(): \Illuminate\Database\Eloquent\Collection
    {
        return SshKey::query()->where('user_id', auth()->id())->latest()->get();
    }

    /** Register a key. */
    public function addKey(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'fingerprint' => ['required', 'string', 'max:64', 'regex:/^SHA256:[A-Za-z0-9+\/=]{20,}$/'],
        ], attributes: [
            'name' => __('key name'),
            'fingerprint' => __('fingerprint'),
        ]);

        SshKey::query()->create([
            'user_id' => auth()->id(),
            'name' => $validated['name'],
            'fingerprint' => $validated['fingerprint'],
        ]);

        $this->reset('name', 'fingerprint');

        unset($this->keys);
    }

    /** Revoke a key. */
    public function revokeKey(int $id): void
    {
        SshKey::query()->where('user_id', auth()->id())->whereKey($id)->delete();

        unset($this->keys);
    }
}; ?>

<x-log.settings tab="terminal">
    <section class="dn-settings-section" aria-labelledby="settings-keys">
        <h2 id="settings-keys" class="dn-settings-title">{{ __('Keys') }}</h2>
        <p class="dn-settings-note">
            {{ __('A key lets the terminal post commits as you. Only the fingerprint is stored.') }}
        </p>

        <div class="dn-list">
            @forelse ($this->keys as $key)
                <div class="dn-list-row" data-test="ssh-key">
                    <x-feed.icon name="lock" :size="18" />
                    <span class="dn-list-body">
                        <span class="dn-list-title">{{ $key->name }}</span>
                        <span class="dn-list-meta">{{ $key->fingerprint }}</span>
                    </span>
                    <span class="dn-list-meta">{{ $key->last_used_at?->diffForHumans() ?? __('never used') }}</span>
                    <button
                        type="button"
                        class="dn-action"
                        wire:click="revokeKey({{ $key->id }})"
                        aria-label="{{ __('Revoke :name', ['name' => $key->name]) }}"
                    >
                        <x-feed.icon name="trash" :size="16" />
                    </button>
                </div>
            @empty
                <p class="dn-list-empty" data-test="no-keys">fatal: no keys are registered</p>
            @endforelse
        </div>

        <form wire:submit="addKey" class="dn-fields" style="margin-top: 16px;">
            <div class="dn-field">
                <label class="dn-field-key" for="key-name">{{ __('name') }}</label>
                <input id="key-name" wire:model="name" type="text" class="dn-input" placeholder="{{ __('work laptop') }}" />
            </div>

            <div class="dn-field">
                <label class="dn-field-key" for="key-fingerprint">{{ __('fingerprint') }}</label>
                <input id="key-fingerprint" wire:model="fingerprint" type="text" class="dn-input" placeholder="SHA256:…" />
            </div>

            @error('name')
                <p class="dn-field"><span class="dn-field-key">{{ __('error') }}</span><span class="dn-form-error">{{ $message }}</span></p>
            @enderror
            @error('fingerprint')
                <p class="dn-field"><span class="dn-field-key">{{ __('error') }}</span><span class="dn-form-error">{{ $message }}</span></p>
            @enderror

            <div class="dn-field" style="justify-content: flex-end; display: flex;">
                <button type="submit" class="dn-btn" data-test="add-key-button">{{ __('Add key') }}</button>
            </div>
        </form>
    </section>

    <section class="dn-settings-section" aria-labelledby="settings-terminal-script">
        <h2 id="settings-terminal-script" class="dn-settings-title">{{ __('The terminal script') }}</h2>
        <p class="dn-settings-note">{{ __('Install it once, then commit from any repository.') }}</p>

        <div class="dn-code-filename"><span>sh</span></div>
        <pre class="dn-code"><code>curl -fsSL {{ url('/install.sh') }} | sh
git commit -m "what did you ship?" && git push</code></pre>
    </section>
</x-log.settings>
