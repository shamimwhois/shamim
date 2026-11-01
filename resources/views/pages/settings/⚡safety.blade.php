<?php

use App\Models\User;
use App\Models\UserBlock;
use App\Support\LogIdentity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Safety — who cannot reach you.
 *
 * A mute hides someone's commits from your log; a block also hides their
 * replies and refuses their remotes. Both are one row, because both answer the
 * same question, and the toggle here is what distinguishes them.
 *
 * Adding is by handle: a person is addressed the way the log addresses them,
 * and an unknown handle is an error rather than a silently empty row.
 */
new #[Title('Safety settings')] #[Layout('layouts::feed')] class extends Component
{
    public string $handle = '';

    public bool $muted = true;

    /**
     * The accounts this one has silenced.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, UserBlock>
     */
    #[Computed]
    public function blocks(): \Illuminate\Database\Eloquent\Collection
    {
        return UserBlock::query()
            ->where('user_id', auth()->id())
            ->with('blocked')
            ->latest()
            ->get();
    }

    /** Silence an account. */
    public function silence(): void
    {
        $validated = $this->validate([
            'handle' => ['required', 'string', 'max:30'],
        ], attributes: ['handle' => __('handle')]);

        $target = User::query()
            ->whereRaw('lower(handle) = ?', [mb_strtolower(LogIdentity::handle($validated['handle']))])
            ->first();

        if ($target === null) {
            $this->addError('handle', __('There is no developer with that handle.'));

            return;
        }

        if ($target->id === auth()->id()) {
            $this->addError('handle', __('You cannot silence yourself.'));

            return;
        }

        UserBlock::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'blocked_id' => $target->id],
            ['muted' => $this->muted],
        );

        $this->reset('handle');

        unset($this->blocks);
    }

    /** Let an account back in. */
    public function release(int $id): void
    {
        UserBlock::query()->where('user_id', auth()->id())->whereKey($id)->delete();

        unset($this->blocks);
    }

    /** Flip a row between muted and blocked. */
    public function toggle(int $id): void
    {
        $block = UserBlock::query()->where('user_id', auth()->id())->whereKey($id)->first();

        if ($block === null) {
            return;
        }

        $block->forceFill(['muted' => ! $block->muted])->save();

        unset($this->blocks);
    }
}; ?>

<x-log.settings tab="safety">
    <section class="dn-settings-section" aria-labelledby="settings-safety">
        <h2 id="settings-safety" class="dn-settings-title">{{ __('Safety') }}</h2>
        <p class="dn-settings-note">
            {{ __('A mute hides a developer from your log. A block also hides their replies and refuses their remotes.') }}
        </p>

        <div class="dn-list">
            @forelse ($this->blocks as $block)
                @php $handle = \App\Support\LogIdentity::handleFor($block->blocked); @endphp
                <div class="dn-list-row" data-test="silenced-user">
                    <span class="dn-avatar" style="--dn-lane: {{ \App\Support\LogIdentity::lane($handle) }};">
                        {{ \App\Support\LogIdentity::initials($block->blocked->name) }}
                    </span>
                    <span class="dn-list-body">
                        <span class="dn-list-title">{{ $block->blocked->name }}</span>
                        <span class="dn-list-meta">{{ '@'.$handle }} · {{ $block->muted ? __('muted') : __('blocked') }}</span>
                    </span>

                    <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" wire:click="toggle({{ $block->id }})">
                        {{ $block->muted ? __('Block instead') : __('Mute instead') }}
                    </button>

                    <button type="button" class="dn-action" wire:click="release({{ $block->id }})" aria-label="{{ __('Release :name', ['name' => $block->blocked->name]) }}">
                        <x-feed.icon name="trash" :size="16" />
                    </button>
                </div>
            @empty
                <p class="dn-list-empty" data-test="no-silenced">fatal: nobody is silenced</p>
            @endforelse
        </div>

        <form wire:submit="silence" class="dn-fields" style="margin-top: 16px;">
            <div class="dn-field">
                <label class="dn-field-key" for="safety-handle">{{ __('handle') }}</label>
                <input id="safety-handle" wire:model="handle" type="text" class="dn-input" placeholder="@handle" />
            </div>

            <div class="dn-field">
                <span class="dn-field-key">{{ __('mode') }}</span>
                <div class="dn-segmented" style="margin-top: 0;">
                    <label class="dn-segment">
                        <input type="radio" wire:model="muted" value="1" class="sr-only" checked />
                        {{ __('Mute') }}
                    </label>
                    <label class="dn-segment">
                        <input type="radio" wire:model="muted" value="0" class="sr-only" />
                        {{ __('Block') }}
                    </label>
                </div>
            </div>

            @error('handle')
                <p class="dn-field"><span class="dn-field-key">{{ __('error') }}</span><span class="dn-form-error">{{ $message }}</span></p>
            @enderror

            <div class="dn-field" style="justify-content: flex-end; display: flex;">
                <button type="submit" class="dn-btn" data-test="silence-button">{{ __('Silence') }}</button>
            </div>
        </form>
    </section>
</x-log.settings>
