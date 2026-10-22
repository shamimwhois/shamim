<?php

use App\Models\FeedPost;
use App\Models\User;
use App\Support\Seo;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The discussion detail — the feed's fully designed thread surface.
 *
 * A post resolves here only when the corpus authored detail content for it
 * (a code sample, a reviewed diff, a reply thread); every other slug 404s
 * rather than pretending to be a thread it is not. The query is
 * `visible()->withDetail()`, so a moderated-away slug cannot be read either.
 */
new #[Title('Discussion')] class extends Component
{
    /** The post being read, resolved once in `mount`. */
    public FeedPost $post;

    /**
     * Resolve the slug to the one discussion that carries detail content.
     *
     * `visible()` is the corpus-wide gate; `isVisibleTo()` is the reader-shaped
     * one. A composed post can be shared with its author alone or with one
     * community, and neither belongs on a public thread page — so the second
     * check runs on the resolved row and answers 404, the same answer a missing
     * slug gives, rather than leaking that the post exists.
     */
    public function mount(string $slug): void
    {
        /** @var User|null $reader */
        $reader = auth()->user();

        $post = FeedPost::query()
            ->visible()
            ->withDetail()
            ->with('attachments')
            ->where('slug', $slug)
            ->first();

        abort_unless($post !== null, 404);
        abort_unless($post->isVisibleTo($reader), 404);

        $this->post = $post;

        // Title and description ride the per-request Seo singleton, which is
        // what the head partial actually reads — the #[Title] attribute alone
        // never reaches it.
        app(Seo::class)->set([
            'title' => $this->post->detail['title'],
            'description' => str($this->post->body)->squish()->limit(155),
        ]);
    }
}; ?>

@php
    $detail = $post->detail;
    $diff = $detail['diff'];
    $signs = ['ctx' => ' ', 'add' => '+', 'del' => '-', 'meta' => ' '];

    // The reply composer's avatar: the signed-in reader's initials, matching
    // the derivation the feed index and app header use, or a neutral dash.
    $readerInitials = collect(preg_split('/\s+/', trim((string) auth()->user()?->name)) ?: [])
        ->filter()
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->take(2)
        ->implode('');
@endphp

<div class="shell py-10 lg:py-14" data-test="feed-show">
    {{-- ================================================================== --}}
    {{-- 01 — Thread header                                                 --}}
    {{-- ================================================================== --}}
    <nav aria-label="{{ __('Breadcrumb') }}" class="mb-8">
        <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-feed-ink-3">
            <li>
                <a href="{{ route('feed.index') }}" wire:navigate class="transition-colors hover:text-feed-azure">{{ __('Feed') }}</a>
            </li>
            <li aria-hidden="true">/</li>
            <li><span class="text-feed-ink-2" aria-current="page">{{ __('Discussion') }}</span></li>
        </ol>
    </nav>

    <header class="feed-card p-5 sm:p-6">
        <div class="flex flex-wrap items-center gap-1.5">
            @if ($post->pinned)
                <span class="feed-chip is-mint">★ {{ __('Pinned') }}</span>
            @endif
            @if ($post->isSolved())
                <span class="feed-chip is-mint">
                    <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    {{ __('Resolved') }}
                </span>
            @endif
            <span class="feed-chip">{{ $post->topic }}</span>
            @foreach ($post->tags as $tag)
                <span class="feed-chip">#{{ $tag }}</span>
            @endforeach
        </div>

        <h1 class="mt-4 text-[clamp(1.5rem,3.5vw,2rem)] font-semibold leading-tight tracking-tightest text-feed-ink">
            {{ $detail['title'] }}
        </h1>

        <div class="mt-5 flex flex-wrap items-center gap-4">
            <x-feed.avatar :initials="$post->avatar" tone="mint" />
            <div class="min-w-0">
                <p class="text-sm font-semibold text-feed-ink">{{ $post->author }} <span class="font-mono text-2xs font-normal text-feed-ink-3">{{ $post->handle }}</span></p>
                <p class="font-mono text-2xs text-feed-ink-3">{{ $post->role }} · {{ $post->posted_at?->diffForHumans() }}</p>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <span class="feed-vote" title="{{ __('Community score') }}">
                    <span class="feed-vote-score">↑ {{ $post->formattedScore() }}</span>
                </span>
                <span class="feed-chip">👍 {{ $post->reactions['like'] }}</span>
                <span class="feed-chip">❤️ {{ $post->reactions['love'] }}</span>
                <span class="feed-chip">💡 {{ $post->reactions['insight'] }}</span>
            </div>
        </div>

        <p class="mt-6 text-[15px] leading-relaxed text-feed-ink-2">{{ $post->body }}</p>

        {{-- Attached images, signed for this request only. A reader who cannot see
             the post never reaches this markup, because `mount` already refused
             them, so the URL below is a convenience rather than the permission. --}}
        @if ($post->attachments->isNotEmpty())
            <ul
                @class([
                    'mt-6 gap-3',
                    'grid grid-cols-1' => $post->attachments->count() === 1,
                    'grid grid-cols-2 sm:grid-cols-3' => $post->attachments->count() > 1,
                ])
                data-test="feed-media"
            >
                @foreach ($post->attachments as $attachment)
                    <li class="overflow-hidden rounded-lg border border-feed-line bg-feed-well">
                        <img
                            src="{{ $attachment->url() }}"
                            alt="{{ $attachment->alt() }}"
                            @if ($attachment->width && $attachment->height)
                                width="{{ $attachment->width }}" height="{{ $attachment->height }}"
                            @endif
                            loading="lazy"
                            decoding="async"
                            class="h-44 w-full object-cover"
                            data-test="feed-media-item"
                        />
                    </li>
                @endforeach
            </ul>
        @endif
    </header>

    {{-- ================================================================== --}}
    {{-- 02 — The patch: sample code + interactive review diff              --}}
    {{-- ================================================================== --}}
    <section class="mt-10" aria-labelledby="patch-heading">
        <h2 id="patch-heading" class="text-xl font-semibold tracking-tightest text-feed-ink">
            {{ __('The streaming ingest, before and after') }}
        </h2>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-feed-ink-2">
            {{ __('The current shape, then the reviewed patch. Lines carrying review comments expand — click one to read the discussion where it was written.') }}
        </p>

        <div class="mt-8 grid items-start gap-8 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <div>
                <h3 class="font-mono text-2xs font-semibold uppercase tracking-wider text-feed-ink-3">{{ __('As it ships today') }}</h3>
                <div class="mt-3 overflow-hidden rounded-lg border border-feed-line-strong bg-ink-950">
                    <div class="flex items-center justify-between border-b border-feed-line-strong px-3 py-1.5 font-mono text-[11px] text-feed-ink-3">
                        <span class="text-feed-azure">src/ingest.rs</span>
                        <span>{{ __('read-only preview') }}</span>
                    </div>
                    <pre class="feed-code rounded-none! border-0!"><code>{{ trim($detail['code']) }}</code></pre>
                </div>
                <p class="mt-3 text-xs leading-relaxed text-feed-ink-3">
                    {{ __('A 4 GB rolling log, hundreds of thousands of lines, one worker parked on every 8 MB page fetch.') }}
                </p>
            </div>

            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-mono text-2xs font-semibold uppercase tracking-wider text-feed-ink-3">{{ __('Reviewed patch') }}</h3>
                    <span class="font-mono text-2xs text-feed-ink-3">{{ $diff['filename'] }}</span>
                </div>

                <p class="mt-3 rounded-lg border border-feed-line bg-feed-card-low px-4 py-3 text-xs leading-relaxed text-feed-ink-2">
                    {{ $diff['summary'] }}
                </p>

                <div class="diff-view mt-4 overflow-hidden rounded-lg border border-feed-line-strong" data-test="feed-diff">
                    @foreach ($diff['lines'] as $line)
                        @if (isset($line['comment']))
                            <details class="diff-line has-comment kind-{{ $line['kind'] }}" wire:key="line-{{ $loop->index }}">
                                <summary class="diff-summary">
                                    <span class="line-no">{{ sprintf('%02d', $line['num']) }}</span>
                                    <span class="line-sign" aria-hidden="true">{{ $signs[$line['kind']] }}</span>
                                    <span class="line-code">{{ $line['code'] }}</span>
                                    <span class="comment-count" aria-hidden="true">💬 {{ count($line['comment']['replies']) + 1 }}</span>
                                    <span class="sr-only">{{ __('— has review comments, activate to read') }}</span>
                                </summary>
                                <div class="comment-thread">
                                    <div class="comment">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-ink-800 font-mono text-2xs font-semibold text-ink-300">{{ $line['comment']['avatar'] }}</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-2xs font-semibold text-ink-100">{{ $line['comment']['author'] }} <span class="ml-1 font-mono text-2xs font-normal text-sky-400">{{ __('review') }}</span></p>
                                            <p class="mt-1 text-xs leading-relaxed text-ink-300">{{ $line['comment']['body'] }}</p>
                                        </div>
                                    </div>
                                    @foreach ($line['comment']['replies'] as $reply)
                                        <div class="comment is-reply">
                                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-ink-800 font-mono text-2xs font-semibold text-ink-300">{{ $reply['avatar'] }}</span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-2xs font-semibold text-ink-100">{{ $reply['author'] }} <span class="ml-1 font-mono text-2xs font-normal text-ink-400">{{ $reply['time'] }}</span></p>
                                                <p class="mt-1 text-xs leading-relaxed text-ink-300">{{ $reply['body'] }}</p>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            <div class="diff-line kind-{{ $line['kind'] }}" wire:key="line-{{ $loop->index }}">
                                <span class="line-no">{{ sprintf('%02d', $line['num']) }}</span>
                                <span class="line-sign" aria-hidden="true">{{ $signs[$line['kind']] }}</span>
                                <span class="line-code">{{ $line['code'] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --}}
    {{-- 03 — Replies                                                       --}}
    {{-- ================================================================== --}}
    <section class="mt-12" aria-labelledby="replies-heading">
        <h2 id="replies-heading" class="text-xl font-semibold tracking-tightest text-feed-ink">
            {{ __('The conversation') }}
        </h2>

        <ul class="mt-6 max-w-3xl space-y-4">
            @foreach ($detail['replies'] as $reply)
                <li class="feed-card flex items-start gap-3 p-4 sm:p-5" wire:key="reply-{{ $reply['author'] }}">
                    <x-feed.avatar :initials="$reply['avatar']" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-feed-ink">{{ $reply['author'] }} <span class="ml-1 font-mono text-2xs font-normal text-feed-ink-3">{{ $reply['time'] }}</span></p>
                        <p class="mt-2 text-sm leading-relaxed text-feed-ink-2">{{ $reply['body'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>

        {{-- Composer stub --}}
        <div class="feed-card mt-6 max-w-3xl p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <x-feed.avatar :initials="$readerInitials !== '' ? $readerInitials : '––'" tone="mint" />
                <div class="flex-1">
                    <label class="sr-only" for="reply-box">{{ __('Add a reply') }}</label>
                    <textarea
                        id="reply-box"
                        rows="2"
                        placeholder="{{ __('Add to the conversation…') }}"
                        class="w-full resize-none rounded-md border border-feed-line bg-feed-well px-3 py-2 text-sm text-feed-ink placeholder:text-feed-ink-3 focus:border-feed-azure focus:outline-none focus:ring-2 focus:ring-feed-azure/20"
                    ></textarea>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="feed-action">
                            {{ __('Reply') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <a
            href="{{ route('feed.index') }}"
            wire:navigate
            class="mt-10 inline-flex items-center gap-2 font-mono text-2xs font-semibold uppercase tracking-wider text-feed-ink-3 transition-colors hover:text-feed-azure"
        >
            <span aria-hidden="true">←</span> {{ __('Back to the feed') }}
        </a>
    </section>
</div>
