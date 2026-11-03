@props([
    'post' => null,
    'url' => null,
    'title' => null,
])

@php
    /**
     * The share bar — the site's primary social distribution surface.
     *
     * Three tiers of behaviour, best-first:
     *
     *   1. Native share sheet (Web Share API) on supporting browsers — a single
     *      button that hands off to the OS. This is the only path offered on
     *      small screens, because a row of intent links is unusable there.
     *   2. Explicit intent links to the channels this audience actually uses
     *      (X, LinkedIn, Hacker News), then a disclosure with the rest.
     *   3. Copy-link as the universal fallback, which always works.
     *
     * Intent URLs come from config so the channel list is data, not markup.
     * Substitution happens client-side against the live `location.href` so the
     * shared URL is always the one the reader is actually looking at — which
     * matters when a post is reached via a filter or a campaign parameter.
     *
     * Nothing below the two lines that resolve `$url` and `$title` is blog-specific,
     * so an idea or a snippet passes its own address and heading instead of being
     * sent to a route it does not live on. `:post` stays the default for the article
     * pages that have always passed it.
     */
    $url ??= $post === null ? '' : route('blog.show', $post);
    $title ??= $post?->title;

    $primary = config('portfolio.sharing.primary', []);
    $secondary = config('portfolio.sharing.secondary', []);
    $xHandle = config('portfolio.author.links.x.handle');
@endphp

<div
    {{ $attributes->merge(['class' => '']) }}
    data-share-root
    data-share-url="{{ $url }}"
    data-share-title="{{ $title }}"
    x-data="{
        copied: false,
        async copy() {
            try {
                await navigator.clipboard.writeText(this.$root.dataset.shareUrl);
                this.copied = true;
                setTimeout(() => this.copied = false, 2200);
            } catch (e) {
                // Clipboard API unavailable (insecure context, denied permission).
                // Select the text so the reader can copy manually.
                const el = document.createElement('textarea');
                el.value = this.$root.dataset.shareUrl;
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);
                this.copied = true;
                setTimeout(() => this.copied = false, 2200);
            }
        },
        async nativeShare() {
            try {
                await navigator.share({
                    title: this.$root.dataset.shareTitle,
                    url: this.$root.dataset.shareUrl,
                });
            } catch (e) {
                // Reader dismissed the sheet, or it failed. Nothing to do.
            }
        },
        get canNativeShare() {
            return typeof navigator !== 'undefined' && typeof navigator.share === 'function';
        }
    }"
>
    <div class="flex flex-wrap items-center gap-2">
        <span class="mr-1 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
            Share
        </span>

        {{-- Native share sheet (mobile-first) --}}
        <button
            type="button"
            x-show="canNativeShare"
            x-cloak
            @click="nativeShare()"
            class="inline-flex items-center gap-1.5 rounded-xs border border-ink-200 px-2.5 py-1.5 font-mono text-2xs font-medium text-ink-600 transition-colors hover:border-signal-400 hover:bg-signal-50 hover:text-signal-700 dark:border-ink-700 dark:text-ink-400 dark:hover:border-signal-600 dark:hover:bg-signal-950 dark:hover:text-signal-300"
        >
            <flux:icon name="arrow-up-tray" class="size-3.5" />
            Share
        </button>

        {{-- Primary intent links --}}
        @foreach ($primary as $target)
            @php
                $intent = str_replace(
                    ['{url}', '{title}', '{via}'],
                    [rawurlencode($url), rawurlencode($title), $xHandle ? '&via='.rawurlencode(ltrim($xHandle, '@')) : ''],
                    $target['intent'],
                );
            @endphp

            <a
                href="{{ $intent }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 rounded-xs border border-ink-200 px-2.5 py-1.5 font-mono text-2xs font-medium text-ink-600 transition-colors hover:border-signal-400 hover:bg-signal-50 hover:text-signal-700 dark:border-ink-700 dark:text-ink-400 dark:hover:border-signal-600 dark:hover:bg-signal-950 dark:hover:text-signal-300"
                title="Share on {{ $target['label'] }}"
            >
                <x-app.icon.share :name="$target['icon']" />
                <span class="max-sm:hidden">{{ $target['label'] }}</span>
                <span class="sr-only max-sm:inline">Share on {{ $target['label'] }}</span>
            </a>
        @endforeach

        {{-- Copy link — always available, always works --}}
        <button
            type="button"
            @click="copy()"
            class="inline-flex items-center gap-1.5 rounded-xs border border-ink-200 px-2.5 py-1.5 font-mono text-2xs font-medium text-ink-600 transition-colors hover:border-signal-400 hover:bg-signal-50 hover:text-signal-700 dark:border-ink-700 dark:text-ink-400 dark:hover:border-signal-600 dark:hover:bg-signal-950 dark:hover:text-signal-300"
            :class="copied && 'border-signal-400 bg-signal-50 text-signal-700 dark:border-signal-600 dark:bg-signal-950 dark:text-signal-300'"
        >
            <span x-show="! copied" class="inline-flex items-center gap-1.5">
                <flux:icon name="link" class="size-3.5" />
                Copy link
            </span>
            <span x-show="copied" x-cloak class="inline-flex items-center gap-1.5">
                <flux:icon name="check" class="size-3.5" />
                Copied
            </span>
        </button>
    </div>

    {{-- Secondary channels behind a disclosure --}}
    @if (count($secondary) > 0)
        <details class="group mt-4">
            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 font-mono text-2xs text-ink-400 transition-colors hover:text-ink-700 dark:text-ink-500 dark:hover:text-ink-200">
                <flux:icon name="chevron-right" class="size-3 transition-transform group-open:rotate-90" />
                More ways to share
            </summary>

            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($secondary as $target)
                    @php
                        $intent = str_replace(
                            ['{url}', '{title}', '{via}'],
                            [rawurlencode($url), rawurlencode($title), ''],
                            $target['intent'],
                        );
                    @endphp

                    <a
                        href="{{ $intent }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 rounded-xs border border-ink-200 px-2.5 py-1.5 font-mono text-2xs text-ink-500 transition-colors hover:border-ink-400 hover:text-ink-800 dark:border-ink-800 dark:text-ink-400 dark:hover:border-ink-600 dark:hover:text-ink-100"
                    >
                        <x-app.icon.share :name="$target['icon']" />
                        {{ $target['label'] }}
                    </a>
                @endforeach
            </div>
        </details>
    @endif

    {{-- Announcer for the copy action --}}
    <p class="sr-only" role="status" aria-live="polite">
        <span x-show="copied" x-cloak>Link copied to clipboard</span>
    </p>
</div>
