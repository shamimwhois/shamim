@php
    /**
     * The composer.
     *
     * The rail and the node stay part of the graph: the staged node is hollow
     * and its ring dashed until the body has a character, then it takes the
     * author's lane and the tail below it becomes the dashed rail line the log
     * hangs from.
     *
     * What the reader types into is a framed panel — borderless text inside a
     * bounded surface — because a composer that floats is a composer nobody is
     * sure they can click into. Inside the panel: the staged image grid, the
     * tools (preview, images, schedule), the counter and the verb.
     *
     * The counter, the node's fill and the preview are all client-side: a
     * keystroke must not cost a round trip. The body itself is sent on submit.
     */
@endphp

<form
    class="dn-composer"
    wire:submit="push"
    data-test="composer"
    x-data="commitComposer({
        userId: {{ $reader->id }},
        max: {{ $max }},
        lane: @js($lane),
        restoredLabel: @js(__('stash@{0}')),
    })"
    x-on:commit-published.window="clear()"
>
    <div class="dn-rail">
        <span class="dn-rail-line is-down is-dashed"></span>
        <a
            href="{{ route('profile.edit') }}"
            class="dn-rail-node"
            tabindex="-1"
            aria-hidden="true"
            x-bind:style="`--dn-lane: ${filled ? lane : 'var(--dn-rail)'}`"
        >
            <span class="dn-rail-avatar" x-bind:class="{ 'is-empty': ! filled }">
                <span class="dn-avatar">{{ $initials }}</span>
            </span>
        </a>
    </div>

    <div class="dn-composer-body">
        <div class="dn-composer-inner">
            {{-- The staged images, in the order they will appear on the row.
                 Removing one is a write, not a client toggle: the bytes live on
                 the media disk and the row knows the mime, the size and the
                 dimensions read back from it. --}}
            @if ($this->stagedMedia->isNotEmpty())
                <ul class="dn-composer-media" data-test="composer-media">
                    @foreach ($this->stagedMedia as $attachment)
                        <li class="dn-composer-media-item">
                            <img
                                src="{{ $attachment->url() }}"
                                alt="{{ $attachment->alt() }}"
                                loading="lazy"
                                decoding="async"
                            >
                            <button
                                type="button"
                                class="dn-composer-media-remove"
                                wire:click="removeMedia({{ $attachment->id }})"
                                wire:loading.attr="disabled"
                                wire:target="removeMedia"
                                aria-label="{{ __('Remove this image') }}"
                                data-test="composer-remove-media"
                            >
                                <x-feed.icon name="close" :size="12" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div x-show="! previewing">
                <label for="commit-body" class="sr-only">{{ __('Commit body') }}</label>

                <textarea
                    id="commit-body"
                    data-composer-input
                    x-ref="input"
                    wire:model="draft"
                    x-on:input="onInput($event)"
                    x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                    x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                    rows="1"
                    maxlength="{{ $max }}"
                    placeholder="{{ __('What did you ship?') }}"
                    autocomplete="off"
                    class="dn-composer-input"
                ></textarea>
            </div>

            {{-- The draft, rendered where the posted commit will sit. --}}
            <div class="dn-composer-preview" x-show="previewing" x-cloak x-text="draftText"></div>
            <div class="dn-composer-preview-url" x-show="previewing && link" x-cloak>
                <span x-text="link"></span>
                <span style="display: block; margin-top: 4px;">{{ __('Unfurls into a link card after you commit.') }}</span>
            </div>

            <div class="dn-composer-row">
                <div class="dn-composer-tools">
                    <input
                        type="file"
                        id="composer-media-input"
                        wire:model="uploads"
                        multiple
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="sr-only"
                        aria-label="{{ __('Attach images') }}"
                        data-test="composer-file-input"
                    >

                    <button
                        type="button"
                        class="dn-icon-btn"
                        x-bind:class="{ 'is-on': previewing }"
                        x-on:click="togglePreview()"
                        aria-label="{{ __('Preview') }}"
                        aria-pressed="false"
                        data-test="preview-toggle"
                    >
                        <x-feed.icon name="eye" />
                    </button>

                    {{-- Images. The input is a real field wire:modelled to the
                         component, so the picker is a label away and the upload
                         state is the wire one the panel already speaks. --}}
                    <button
                        type="button"
                        class="dn-icon-btn"
                        x-on:click="$el.closest('form').querySelector('[data-test=\'composer-file-input\']').click()"
                        aria-label="{{ __('Attach images') }}"
                        title="{{ __('Attach images') }}"
                        data-test="composer-images"
                    >
                        <x-feed.icon name="image" />
                    </button>

                    <button
                        type="button"
                        class="dn-icon-btn"
                        x-bind:class="{ 'is-on': $wire.askingPoll }"
                        x-on:click="$wire.togglePoll()"
                        x-bind:aria-expanded="$wire.askingPoll ? 'true' : 'false'"
                        aria-label="{{ __('Ask a poll') }}"
                        title="{{ __('Ask a poll') }}"
                        data-test="composer-poll-toggle"
                    >
                        <x-feed.icon name="poll" />
                    </button>

                    <button
                        type="button"
                        class="dn-icon-btn"
                        x-bind:class="{ 'is-on': $wire.scheduling }"
                        x-on:click="$wire.toggleScheduling()"
                        x-bind:aria-expanded="$wire.scheduling ? 'true' : 'false'"
                        aria-label="{{ __('Schedule this commit') }}"
                        title="{{ __('Schedule this commit') }}"
                        data-test="composer-schedule-toggle"
                    >
                        <x-feed.icon name="clock" />
                    </button>

                    <button
                        type="button"
                        class="dn-icon-btn"
                        x-on:click="if (confirm('{{ __('Clear this draft?') }}')) drop(true)"
                        aria-label="{{ __('Discard draft') }}"
                        x-show="count > 0"
                        x-cloak
                    >
                        <x-feed.icon name="trash" />
                    </button>

                    <span class="dn-stash" x-show="restored" x-cloak title="{{ __('Restored your unsent draft') }}">
                        <span x-text="restoredLabel"></span>
                        <button type="button" x-on:click="drop(true)">{{ __('drop') }}</button>
                    </span>

                    @error('draft')
                        <span style="font-size: 13px; color: var(--dn-destructive);">{{ $message }}</span>
                    @enderror
                </div>

                <span
                    class="dn-counter"
                    x-bind:class="{ 'is-empty': remaining === 0 }"
                    x-text="remaining"
                    aria-live="polite"
                >{{ $max }}</span>

                <button
                    type="submit"
                    class="dn-btn"
                    style="min-width: 120px;"
                    x-bind:disabled="count === 0"
                    wire:loading.attr="disabled"
                    wire:target="commit"
                    data-test="commit-button"
                >
                    <span wire:loading.remove wire:target="commit">
                        {{ $scheduling ? __('Schedule commit') : __('Commit') }}
                    </span>
                    <span wire:loading wire:target="commit">
                        {{ $scheduling ? __('Scheduling…') : __('Committing…') }}
                    </span>
                </button>
            </div>

            {{-- The poll editor. Opened by the bars, closed by the same; the
                 write path decides what survives (two distinct choices is the
                 floor, empties are dropped, votes are re-summed) — the editor
                 only collects. --}}
            @if ($askingPoll)
                <div class="dn-composer-poll" data-test="composer-poll">
                    <p class="dn-composer-poll__label">{{ __('Choices') }}</p>

                    @foreach ($pollOptions as $optionIndex => $optionLabel)
                        <div class="dn-composer-poll__row">
                            <input
                                type="text"
                                wire:model="pollOptions.{{ $optionIndex }}"
                                maxlength="60"
                                placeholder="{{ __('Choice :n', ['n' => $optionIndex + 1]) }}"
                                class="dn-composer-poll__input"
                                aria-label="{{ __('Choice :n', ['n' => $optionIndex + 1]) }}"
                                data-test="composer-poll-option"
                            >

                            @if (count($pollOptions) > 2)
                                <button
                                    type="button"
                                    class="dn-icon-btn"
                                    wire:click="removePollOption({{ $optionIndex }})"
                                    aria-label="{{ __('Remove this choice') }}"
                                    data-test="composer-poll-remove"
                                >
                                    <x-feed.icon name="close" :size="14" />
                                </button>
                            @endif
                        </div>
                    @endforeach

                    @if (count($pollOptions) < $pollMaxOptions)
                        <button
                            type="button"
                            class="dn-composer-poll__add"
                            wire:click="addPollOption"
                            data-test="composer-poll-add"
                        >
                            + {{ __('Add a choice') }}
                        </button>
                    @endif

                    <div class="dn-composer-poll__row">
                        <input
                            type="text"
                            wire:model="pollCloses"
                            maxlength="40"
                            placeholder="{{ __('Closes in… (optional)') }}"
                            class="dn-composer-poll__input"
                            aria-label="{{ __('When the poll closes') }}"
                            data-test="composer-poll-closes"
                        >
                    </div>

                    @error('poll')
                        <p class="dn-composer-error" data-test="composer-poll-error">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            {{-- Scheduling. Opened by the clock, closed by the same; the moment
                 is a local datetime that the scheduler parses and refuses if it
                 is not still ahead. --}}
            @if ($scheduling)
                <div class="dn-composer-schedule" data-test="composer-schedule">
                    <label for="composer-scheduled-at" class="dn-composer-schedule-label">
                        {{ __('Go live at') }}
                    </label>
                    <input
                        id="composer-scheduled-at"
                        type="datetime-local"
                        wire:model="scheduledAt"
                        class="dn-composer-schedule-input"
                        data-test="composer-schedule-input"
                    >
                    <button
                        type="button"
                        class="dn-icon-btn"
                        x-on:click="$wire.toggleScheduling()"
                        aria-label="{{ __('Cancel the schedule') }}"
                        data-test="composer-schedule-cancel"
                    >
                        <x-feed.icon name="close" />
                    </button>
                </div>

                @error('scheduledAt')
                    <p class="dn-composer-error" data-test="composer-schedule-error">{{ $message }}</p>
                @enderror
            @endif

            @error('uploads')
                <p class="dn-composer-error" data-test="composer-media-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</form>
