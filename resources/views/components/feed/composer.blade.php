{{--
    The composer — the platform's verb.

    It is an entry point, not an editor. Collapsed it costs a single
    transmit bar: the reader's avatar, a "what's on your mind" line that
    promises to become an input, and the Post button. Underneath it, the
    six type chips ride a segmented rail so the mode is legible at a
    glance instead of hiding in a row of identical chips.

    Every one of those controls opens `feed-composer-modal`, which is
    where the writing surface, the media, the audience and the publish verb
    actually live.

    A guest never reaches the modal. There is no composer for an account
    that cannot authorise `create`, so the same controls open the login
    dialog the rest of the feed uses — the honest answer to "I want to
    post" is "sign in", not a form that will fail on submit.

    The avatar initials arrive as a prop — the expanded state used to carry
    a hardcoded "SH", which is exactly the screenshot identity the PRD
    forbids.
--}}

@props([
    'initials' => '',
])

@php
    $composerTypes = [
        'question' => [__('Question'), 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z'],
        'discussion' => [__('Discussion'), 'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.056-4.019-.163-2.01-.24-3.86-1.14-3.86-3.955v-4.286c0-3.135 2.34-4.11 5.34-4.11h3.14c3 0 5.4 1.06 5.4 4.11zM12.02 3.638l-1.02-.163C9.47 3.474 7.5 4.436 7.5 6.22v3.71'],
        'showcase' => [__('Showcase'), 'M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0120.25 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3V9c.828 0 1.5.672 1.5 1.5v3m0 0V15a1.5 1.5 0 01-1.5 1.5H21M4.5 9.878V15a1.5 1.5 0 001.5 1.5h.75m11.25-6.378a2.25 2.25 0 012.25 2.25v3.75m-2.25-2.25a2.25 2.25 0 00-2.25 2.25v.75m-8.25-3.75h9m-9 3H12'],
        'snippet' => [__('Snippet'), 'M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5'],
        'article' => [__('Article'), 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.099 5.976l-3.475 3.475m0 0l-2.475 2.475a1.125 1.125 0 001.591 1.591l2.474-2.474m0 0l9.713-.001.001-9.714-9.713 9.714z'],
        'poll' => [__('Poll'), 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125H9.75c-.621 0-1.125-.504-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25c-.621 0-1.125-.504-1.125-1.125V4.125z'],
    ];

    /**
     * Members open the composer; guests open the login dialog. The string is the
     * same one the feed header and inspector already use, so a guest's "Sign in"
     * and a guest's "Post" are the same conversation.
     */
    $openLogin = '$flux.modal(\'login\').show(); $event.preventDefault()';
    $member = auth()->check();
    $defaultType = (string) config('feed.composer.default_type', 'discussion');
@endphp

<div
    class="feed-transmit"
    aria-labelledby="composer-heading"
    x-data="{ mode: @js($defaultType) }"
    x-on:focus-composer.window="$dispatch('feed-composer-open', { type: mode })"
>
    <h3 id="composer-heading" class="sr-only">{{ __('Create a post') }}</h3>

    <div class="flex items-center gap-3">
        <x-feed.avatar :initials="$initials !== '' ? $initials : '––'" tone="mint" />

        @if ($member)
            <x-app.ui.modal.trigger name="feed-composer-modal">
                <button
                    type="button"
                    class="feed-transmit-prompt flex-1"
                    x-on:click="$dispatch('feed-composer-open', { type: mode })"
                    data-test="composer-prompt"
                >
                    <span class="feed-live-dot size-1.5 shrink-0 rounded-full bg-feed-mint" aria-hidden="true"></span>
                    {{ __("What's on your mind? Share, ask, or start a discussion…") }}
                </button>
            </x-app.ui.modal.trigger>
        @else
            <button
                type="button"
                class="feed-transmit-prompt flex-1"
                x-on:click="{!! $openLogin !!}"
                data-test="composer-prompt"
            >
                <span class="feed-live-dot size-1.5 shrink-0 rounded-full bg-feed-mint" aria-hidden="true"></span>
                {{ __("What's on your mind? Share, ask, or start a discussion…") }}
            </button>
        @endif

        <div class="flex shrink-0 items-center gap-2">
            <span class="hidden font-mono text-[10px] text-feed-ink-3 sm:inline">⌘ + ↵</span>

            @if ($member)
                <x-app.ui.modal.trigger name="feed-composer-modal">
                    <button
                        type="button"
                        class="feed-action"
                        x-on:click="$dispatch('feed-composer-open', { type: mode })"
                        data-test="composer-open"
                    >
                        {{ __('Post') }}
                    </button>
                </x-app.ui.modal.trigger>
            @else
                <button type="button" class="feed-action" x-on:click="{!! $openLogin !!}" data-test="composer-open">
                    {{ __('Post') }}
                </button>
            @endif
        </div>
    </div>

    {{-- The chips choose the shape of the post, so they carry that choice into
         the modal instead of setting a local mode that nothing downstream
         reads. `aria-pressed` still describes the selected chip. --}}
    <div class="feed-type-rail mt-3">
        @foreach ($composerTypes as $composerType => [$composerLabel, $composerPath])
            <button
                type="button"
                @if ($member)
                    x-on:click="mode = '{{ $composerType }}'; $dispatch('feed-composer-open', { type: '{{ $composerType }}' })"
                @else
                    x-on:click="{!! $openLogin !!}"
                @endif
                class="feed-type-button"
                :aria-pressed="mode === '{{ $composerType }}' ? 'true' : 'false'"
                data-test="composer-type-{{ $composerType }}"
            >
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $composerPath }}" /></svg>
                {{ $composerLabel }}
            </button>
        @endforeach
    </div>

    <p class="feed-meta mt-3 flex items-center gap-1.5">
        <kbd class="feed-chip">⌘</kbd> <kbd class="feed-chip">↵</kbd>
        <span class="ml-1.5">{{ __('Opens the composer; publishes from inside it') }}</span>
    </p>
</div>
