@props([
    'post',
    'fresh' => false,
    'index' => null,
])

{{--
    The feed card — the wire row.

    One column, as wide as the stream, in four movements:

      1. KICKER  — the wire index, the topic and the age, in the module's
                    mono structural voice, with the status cluster opposite.
      2. BYLINE  — who is speaking, and the community they belong to.
      3. BODY    — the headline at the module's second-largest scale, the
                    excerpt, and the type's own artefact (terminal panel,
                    poll bars, topic tile, accepted answer, media).
      4. META    — reactions, thread, the answer call, views.

    Everything the card shows is the post's own record; the only decoration
    is the accent spine that lights on approach and the always-dark
    terminal panel. The spine and the type scale are the module's
    broadcast layer (resources/css/feed.css); the colours are the same
    mint-as-action / azure-as-navigation roles the rest of the site uses.
--}}

@php
    use App\Models\FeedPost;

    /** @var FeedPost $post */
    use Illuminate\Support\Str;

    $typeTone = match ($post->type) {
        'question' => 'is-azure',
        'showcase' => 'is-mint',
        'snippet' => 'is-azure',
        default => '',
    };

    $hasCode = $post->code !== null;
    $hasPoll = $post->poll !== null;
    $hasThread = $post->hasDetail();
    $topicGlyph = Str::upper(Str::substr($post->topic, 0, 2));
    $topicLabel = Str::headline($post->topic);
    $media = $post->attachments;
@endphp

<article
    @class([
        'feed-card group relative',
        'is-pinned' => $post->pinned,
        'feed-card-enter' => $fresh,
    ])
    wire:key="post-{{ $post->id }}"
    x-data="{ open: false }"
    data-test="feed-card"
>
    {{-- Kicker — the wire index, the topic, the age, and the status cluster --}}
    <header class="px-4 pt-4 sm:px-5 sm:pt-5">
        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
            <p class="feed-card-kicker">
                @if ($index !== null)
                    <span class="text-feed-azure">#{{ str_pad((string) $index, 2, '0', STR_PAD_LEFT) }}</span>
                    <span aria-hidden="true" class="size-1 rounded-full bg-feed-line-strong"></span>
                @endif
                <span>{{ $topicLabel }}</span>
                <span aria-hidden="true" class="size-1 rounded-full bg-feed-line-strong"></span>
                <time datetime="{{ $post->posted_at?->toIso8601String() }}">{{ $post->posted_at?->diffForHumans() }}</time>
            </p>

            <div class="flex shrink-0 items-center gap-1.5">
                @if ($post->pinned)
                    <span class="feed-chip is-mint" title="{{ __('Curated by moderation') }}">★ {{ __('Pinned') }}</span>
                @elseif ($post->featured)
                    <span class="feed-chip is-azure">✦ {{ __('Featured') }}</span>
                @endif
                @if ($post->isQuestion())
                    <span @class(['feed-chip', 'is-mint' => $post->isSolved(), 'text-feed-ink-3' => ! $post->isSolved()])>
                        @if ($post->isSolved())
                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('Solved') }}
                        @else
                            {{ __('Unanswered') }}
                        @endif
                    </span>
                @endif
                <span @class(['feed-chip', $typeTone])>{{ $post->type }}</span>
            </div>
        </div>

        {{-- Byline — who is speaking --}}
        <div class="mt-3 flex items-center gap-3">
            <x-feed.avatar :initials="$post->avatar" :tone="$post->pinned || $post->featured ? 'mint' : 'neutral'" />

            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="text-[13px] font-semibold text-feed-ink">{{ $post->author }}</span>
                    {{-- A corpus row has no account behind it, so its handle stays
                         plain text. A composed post does, and the handle becomes the
                         one author-scoped view the app actually has: the feed asked
                         for this name. There is no public profile route to invent. --}}
                    @if ($post->user_id !== null)
                        <a
                            href="{{ route('feed.index', ['q' => $post->author]) }}"
                            wire:navigate
                            class="feed-meta transition-colors hover:text-feed-azure"
                            data-test="feed-author-link"
                        >{{ $post->handle }}</a>
                    @else
                        <span class="feed-meta">{{ $post->handle }}</span>
                    @endif
                    @if ($post->role)
                        <span class="feed-meta">· {{ $post->role }}</span>
                    @endif
                </p>
            </div>
        </div>
    </header>

    {{-- Body: headline, excerpt, and the type's own artefact --}}
    <div class="px-4 py-3 sm:px-5">
        <div class="flex items-start gap-4">
            <div class="min-w-0 flex-1">
                <h3 class="feed-card-title [text-wrap:balance]">
                    {{ $post->title }}
                </h3>
                <p class="mt-2 line-clamp-3 max-w-[72ch] text-[13px] leading-relaxed text-feed-ink-2">
                    {{ $post->body }}
                </p>
            </div>

            {{-- Thread media panel: the always-dark terminal style, for any post
                 that carries a thread and has no better artefact of its own. --}}
            @if ($hasThread && ! $hasCode && ! $hasPoll)
                <a
                    href="{{ route('feed.show', ['slug' => $post->slug]) }}"
                    wire:navigate
                    class="mt-2 hidden w-44 shrink-0 overflow-hidden rounded-lg border border-feed-line-strong bg-ink-950 sm:block"
                    tabindex="-1"
                    aria-hidden="true"
                >
                    <span class="flex items-center gap-1.5 border-b border-feed-line-strong px-3 py-1.5">
                        <span class="size-2 rounded-full bg-red-400"></span>
                        <span class="size-2 rounded-full bg-amber-400"></span>
                        <span class="size-2 rounded-full bg-green-500"></span>
                        <span class="ml-1 font-mono text-[10px] text-ink-500">{{ Illuminate\Support\Str::limit($post->slug, 18) }}</span>
                    </span>
                    <span class="block p-3 font-mono text-[10px] leading-5 text-ink-500">
                        <span class="block text-ink-300"># {{ Illuminate\Support\Str::limit($post->title, 30) }}</span>
                        <span class="mt-1 block">{{ Illuminate\Support\Str::limit($post->body, 46) }}</span>
                        <span class="mt-1 block text-feed-mint">→ {{ __('open thread') }}</span>
                    </span>
                </a>
            @elseif (! $hasCode && ! $hasPoll)
                {{-- Topic tile: posts whose artefact is the post itself still get
                     the reference's right-hand thumb. --}}
                <div class="mt-2 hidden w-44 shrink-0 overflow-hidden rounded-lg border border-feed-line bg-feed-well sm:block" aria-hidden="true">
                    <div class="grid h-[104px] place-items-center bg-feed-raised">
                        <span class="font-mono text-2xl font-semibold text-feed-azure/70">{{ $topicGlyph }}</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-feed-line px-3 py-1.5">
                        <span class="font-mono text-[10px] uppercase tracking-wider text-feed-ink-3">{{ $topicLabel }}</span>
                        <span class="font-mono text-[10px] text-feed-ink-3">{{ $post->type }}</span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Accepted answer, for solved questions --}}
        @if ($post->answer)
            <div class="mt-3 flex items-start gap-2.5 rounded-lg border border-feed-line bg-feed-card-low p-3">
                <svg class="mt-0.5 size-4 shrink-0 text-feed-mint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span class="font-semibold uppercase tracking-wider text-feed-mint">{{ __('Accepted answer') }}</span>
                        <span class="text-feed-ink-3">{{ __('by :author', ['author' => $post->answer['author']]) }}</span>
                    </p>
                    <p class="mt-1 line-clamp-2 text-[12px] leading-relaxed text-feed-ink-2">{{ $post->answer['body'] }}</p>
                </div>
            </div>
        @endif

        {{-- Code preview: snippets and questions that show work --}}
        @if ($hasCode)
            <div class="mt-3 overflow-hidden rounded-lg border border-feed-line-strong bg-ink-950">
                <div class="flex items-center justify-between border-b border-feed-line-strong px-3 py-1.5">
                    <span class="font-mono text-[11px] text-feed-azure">{{ $post->code_file ?? __('snippet') }}</span>
                    <button
                        type="button"
                        class="feed-ghost px-1.5! py-0.5! text-[11px]!"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard?.writeText($refs.code.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1600) }).catch(() => {})"
                    >
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375c-.621 0-1.125-.504-1.125-1.125v-9.25m0 0H6.375" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                    </button>
                </div>
                <pre class="feed-code rounded-none! border-0! max-h-52" x-ref="code"><code>{{ trim($post->code) }}</code></pre>
            </div>
        @endif

        {{-- Poll: shared bars over the real corpus votes --}}
        @if ($hasPoll)
            <div class="mt-3 space-y-2">
                @foreach ($post->poll['options'] as $option)
                    @php
                        $share = $post->poll['votes'] > 0 ? (int) round($option['votes'] / $post->poll['votes'] * 100) : 0;
                    @endphp
                    <div class="relative overflow-hidden rounded-md border border-feed-line bg-feed-well px-3 py-1.5">
                        <span aria-hidden="true" class="absolute inset-y-0 left-0 bg-feed-azure/10" style="width: {{ $share }}%"></span>
                        <span class="relative flex items-center justify-between text-[11px]">
                            <span class="font-medium text-feed-ink">{{ $option['label'] }}</span>
                            <span class="font-mono tabular-nums text-feed-azure">{{ $share }}%</span>
                        </span>
                    </div>
                @endforeach
                <p class="feed-meta flex items-center justify-between">
                    <span>{{ number_format($post->poll['votes']) }} {{ __('total votes') }}</span>
                    <span>{{ $post->poll['closes'] }}</span>
                </p>
            </div>
        @endif

        {{-- Attached images. The bytes live on a disk that refuses to be served by
             path, so every source here is a short-lived signed URL generated at
             render time; `alt` is the author's own text, never a file name. --}}
        @if ($media->isNotEmpty())
            <ul
                @class([
                    'mt-3 gap-2',
                    'grid grid-cols-1' => $media->count() === 1,
                    'grid grid-cols-2 sm:grid-cols-3' => $media->count() > 1,
                ])
                data-test="feed-media"
            >
                @foreach ($media as $attachment)
                    <li class="overflow-hidden rounded-lg border border-feed-line bg-feed-well" wire:key="media-{{ $attachment->id }}">
                        <img
                            src="{{ $attachment->url() }}"
                            alt="{{ $attachment->alt() }}"
                            @if ($attachment->width && $attachment->height)
                                width="{{ $attachment->width }}" height="{{ $attachment->height }}"
                            @endif
                            loading="lazy"
                            decoding="async"
                            class="h-40 w-full object-cover sm:h-48"
                            data-test="feed-media-item"
                        />
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($post->read_minutes)
            <p class="feed-eyebrow mt-3">{{ $post->read_minutes }} {{ __('min read') }}</p>
        @endif

        @if ($post->tags)
            <ul class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($post->tags as $tag)
                    <li><span class="feed-chip">#{{ $tag }}</span></li>
                @endforeach
            </ul>
        @endif

        @if ($hasThread)
            <a
                href="{{ route('feed.show', ['slug' => $post->slug]) }}"
                wire:navigate
                class="focus-ring mt-4 inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-feed-azure transition-opacity hover:opacity-80"
            >
                {{ __('Open the full thread') }} <span aria-hidden="true">→</span>
            </a>
        @endif
    </div>

    {{-- Meta bar: reactions, thread, the answer call, views --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-feed-line px-4 py-2.5 sm:px-5">
        <span class="feed-reactions" aria-label="{{ __('Reactions') }}">
            <span aria-hidden="true">👍</span><span aria-hidden="true">❤️</span><span aria-hidden="true">💡</span>
            <span class="tabular-nums">{{ $post->reactionSum() }}</span>
        </span>

        <button type="button" class="feed-ghost px-1.5!" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ __('Thread') }} · {{ $post->comments }}
        </button>

        {{-- Only a post that actually resolves to a thread can promise an answer.
             The detail screen renders curated reply content and 404s without it,
             so a question with no detail gets no link rather than a broken one. --}}
        @if ($post->isQuestion() && ! $post->isSolved() && $hasThread)
            <a
                href="{{ route('feed.show', ['slug' => $post->slug]) }}"
                wire:navigate
                class="feed-action px-3! py-1! text-[11px]!"
            >
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                {{ __('Answer') }}
            </a>
        @endif

        <span class="ml-auto inline-flex items-center gap-1 font-mono text-[11px] text-feed-ink-3" title="{{ __('Views') }}">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ $post->views >= 1000 ? number_format($post->views / 1000, 1).'k' : $post->views }}
        </span>
    </div>

    {{-- Thread preview: the opening post only --}}
    <div x-show="open" x-collapse class="border-t border-feed-line px-4 py-4 sm:px-5">
        <p class="feed-eyebrow">{{ __('Recent replies') }}</p>
        <ul class="mt-3 space-y-3">
            <li class="flex items-start gap-2.5">
                <x-feed.avatar :initials="strtoupper(substr($post->author, 0, 2))" size="sm" />
                <div class="min-w-0 flex-1 rounded-lg bg-feed-well px-3 py-2">
                    <p class="text-[12px] leading-relaxed text-feed-ink-2">
                        {{ __('The full conversation lives in the thread — this preview shows the opening post only.') }}
                    </p>
                </div>
            </li>
        </ul>
    </div>
</article>
