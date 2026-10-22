<?php

use App\Actions\DevLab\CloneSnippetToWorkspace;
use App\Enums\AccountPermission;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One published snippet, on a URL of its own.
 *
 * The read rule is `isVisibleTo()` and nothing else, so a private snippet is a 404
 * rather than a 403: the visitor has not been denied a resource, they have been pointed
 * at one that was never published. An account with the admin panel passes the same
 * predicate, which is what lets an operator open a page before taking it down.
 *
 * ## The code is data on this page
 *
 * It is printed with `{{ }}` inside `<pre><code>`, so a snippet containing `<script>`
 * shows those nine characters and nothing else. Nowhere on this screen is a `{!! !!}`.
 *
 * The preview is the single exception, and it is opt-in on purpose: pressing it hands
 * the stored text to the same sandboxed frame the author's own experiments run in, with
 * `allow-scripts` and no `allow-same-origin`. That frame has an opaque origin, so the
 * stranger's code cannot read this site's cookies or storage, cannot reach this page's
 * DOM, and cannot call the server with the visitor's session. If the stored text
 * contains `</script>` it ends its own tag early and the rest is parsed as markup
 * inside the frame, which is still the frame's own problem and not this site's.
 *
 * A visitor who does not press the button never runs anything.
 *
 * ## The one write this page makes
 *
 * The closing panel offers to copy the snippet into the reader's own workspace. That is
 * a mutation on a public page, so it is authorised on the verb — {@see
 * self::openInSandbox()} checks the Developer Lab permission itself, because Livewire
 * posts to `/livewire/update` and the route group's middleware is not in that path. A
 * guest reads the page with no button on it and no way to make the request.
 */
new #[Title('Snippet')] class extends Component
{
    /**
     * The snippet being read. Locked so a crafted request cannot swap the model out
     * from under the component after the read has been authorised.
     */
    #[Locked]
    public LabSnippet $snippet;

    public function mount(LabSnippet $snippet): void
    {
        abort_unless($snippet->isVisibleTo(auth()->user()), 404);

        $this->snippet = $snippet->load('user:id,name');

        $this->recordView();

        app(Seo::class)->set([
            'title' => $snippet->title,
            'description' => $snippet->excerpt(),
            'canonical' => route('dev-lab.snippets.show', ['snippet' => $snippet->slug]),
            'type' => 'article',
            'authorName' => $snippet->user?->name,
            'publishedAt' => $snippet->published_at?->toIso8601String(),
            'modifiedAt' => $snippet->updated_at?->toIso8601String(),
            // The author's own edit screen and an operator's moderation read both come
            // here for a snippet that is not public, and neither is a page worth
            // announcing. The same flag suppresses the structured data.
            'noindex' => ! $snippet->isPublished(),
            'jsonLd' => $snippet->isPublished() ? $this->structuredData($snippet) : [],
        ]);
    }

    /**
     * What this page tells a machine about itself.
     *
     * Two nodes: the snippet as `SoftwareSourceCode`, and the trail that leads to it.
     * `codeText` is the source, which is already the visible content of the page, and
     * JSON encoding plus the hex flags in `partials/head` is what makes putting it in
     * a `<script>` element safe rather than a second copy of the escaping problem this
     * page spends the rest of its markup avoiding.
     *
     * `runtime` is the honest field: it claims a browser, and only for the three
     * languages the frame will actually execute. A PHP snippet gets no `runtime` and no
     * `license` rather than either invented, because structured data a reader can check
     * is worth more than structured data that looks complete.
     *
     * @return array<int, array<string, mixed>>
     */
    private function structuredData(LabSnippet $snippet): array
    {
        $url = route('dev-lab.snippets.show', ['snippet' => $snippet->slug]);
        $label = $snippet->language->label();

        $code = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareSourceCode',
            'name' => $snippet->title,
            'url' => $url,
            'codeLanguage' => $label,
            'programmingLanguage' => $label,
            'codeText' => (string) $snippet->code,
            'lines' => $snippet->lineCount(),
            'author' => [
                '@type' => 'Person',
                'name' => $snippet->user?->name ?? __('Someone'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => route('home'),
            ],
            'isPartOf' => [
                '@type' => 'WebPage',
                '@id' => route('dev-lab.gallery'),
            ],
        ];

        if (filled($snippet->description)) {
            $code['description'] = $snippet->description;
        }

        if ($snippet->language->isBrowserRunnable()) {
            $code['runtime'] = 'Browser';
        }

        if ($snippet->published_at !== null) {
            $code['datePublished'] = $snippet->published_at->toIso8601String();
        }

        if ($snippet->updated_at !== null) {
            $code['dateModified'] = $snippet->updated_at->toIso8601String();
        }

        return [
            $code,
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => __('Home'),
                        'item' => route('home'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => __('Developer Lab'),
                        'item' => route('dev-lab.index'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 3,
                        'name' => __('Gallery'),
                        'item' => route('dev-lab.gallery'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 4,
                        'name' => $snippet->title,
                        'item' => $url,
                    ],
                ],
            ],
        ];
    }

    /**
     * Copy this snippet into the reader's own workspace and send them to it.
     *
     * The permission is tested on the verb rather than inherited from the route
     * group because Livewire posts mutations to `/livewire/update`, where the
     * `permission:dev-lab:use` middleware on `/dev-lab/*` never runs. The sandbox
     * screen solves the same problem in `boot()`; this page cannot, because a guest
     * is supposed to be able to read it, so the guard sits on the one method that
     * changes anything.
     */
    public function openInSandbox(CloneSnippetToWorkspace $clone): void
    {
        $reader = auth()->user();

        abort_unless(
            $reader?->hasPermission(AccountPermission::UseDeveloperLab),
            403,
            __('You may not open the Developer Lab.')
        );

        try {
            $workspace = $clone->handle($reader, $this->snippet);
        } catch (InvalidArgumentException $e) {
            // The refusal is a message, not a redirect: the reader is still on the
            // snippet they came for, and the sentence says why there is nothing to
            // open.
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('dev-lab.sandbox', ['workspace' => $workspace->id], navigate: true);
    }

    /**
     * Whether to offer the button at all.
     *
     * Two conditions, and the second is the one a reader cannot see for themselves:
     * a PHP snippet has no pane, so there is no workspace it could become. The server
     * refuses that request as well, so hiding the button is courtesy rather than
     * control.
     */
    #[Computed]
    public function canClone(): bool
    {
        return auth()->user()?->hasPermission(AccountPermission::UseDeveloperLab) === true
            && $this->snippet->language->isBrowserRunnable();
    }

    /**
     * The three panes the preview would compose, or null when there is nothing to run.
     *
     * A CSS snippet previews as a page with only a stylesheet in it, which is a
     * sensible render of an odd choice, and a PHP snippet has no entry here at all.
     *
     * @return array{html: string, css: string, js: string}|null
     */
    #[Computed]
    public function previewPanes(): ?array
    {
        if (! $this->snippet->language->isBrowserRunnable()) {
            return null;
        }

        return match ($this->snippet->language) {
            LabLanguage::Css => ['html' => '', 'css' => $this->snippet->code, 'js' => ''],
            LabLanguage::Javascript => ['html' => '', 'css' => '', 'js' => $this->snippet->code],
            default => ['html' => $this->snippet->code, 'css' => '', 'js' => ''],
        };
    }

    /**
     * Three more published snippets in the same language.
     *
     * Related-by-language rather than related-by-author: a reader who came for one
     * debounce helper wants the next one, whoever wrote it.
     *
     * @return Collection<int, LabSnippet>
     */
    #[Computed]
    public function alsoPublished(): Collection
    {
        return LabSnippet::query()
            ->published()
            ->inLanguage($this->snippet->language)
            ->whereKeyNot($this->snippet->id)
            ->recentlyPublished()
            ->limit(3)
            ->get(['id', 'slug', 'title', 'user_id', 'published_at']);
    }

    /**
     * Increment the view counter, at most once per session per snippet.
     *
     * Written directly rather than through an Action because it is a single atomic
     * increment with no invariants to protect, and it must not touch `updated_at`: a
     * read is not an edit, and a gallery sorted by "recently touched" would otherwise
     * be sorted by traffic.
     */
    protected function recordView(): void
    {
        $key = 'viewed-snippet-' . $this->snippet->id;

        if (session()->has($key)) {
            return;
        }

        session()->put($key, true);

        LabSnippet::query()
            ->whereKey($this->snippet->id)
            ->increment('view_count');

        // Keep the in-memory model consistent with the database so the rendered
        // count includes this reader.
        $this->snippet->view_count++;
    }
}; ?>

<div
    data-test="dev-lab-snippet"
    @if ($this->previewPanes !== null)
        x-data="labSandbox({
            maxLineChars: {{ (int) config('dev_lab.max_log_line_chars') }},
            panes: @js($this->previewPanes),
        })"
    @endif
>
    {{-- ================================================================== --
     | Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <nav aria-label="{{ __('Breadcrumb') }}">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('home') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Home') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li>
                        <a href="{{ route('dev-lab.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Developer Lab') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li>
                        <a href="{{ route('dev-lab.gallery') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Gallery') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="truncate text-ink-600 dark:text-ink-300">{{ $snippet->title }}</li>
                </ol>
            </nav>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <flux:badge :color="$snippet->language->badgeColor()">
                    {{ $snippet->language->label() }}
                </flux:badge>

                <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]) }}
                    &middot;
                    {{ trans_choice(':count view|:count views', $snippet->view_count, ['count' => $snippet->view_count]) }}
                </span>
            </div>

            <h1 class="mt-4 max-w-3xl text-[clamp(1.75rem,4vw,2.75rem)] font-semibold leading-[1.08] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ $snippet->title }}
            </h1>

            @if (filled($snippet->description))
                <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                    {{ $snippet->description }}
                </p>
            @endif

            <p class="mt-4 font-mono text-2xs text-ink-400 dark:text-ink-500">
                {{ __('by :author · published :date', [
                    'author' => $snippet->user?->name ?? __('Someone'),
                    'date' => $snippet->published_at?->translatedFormat('j M Y') ?? __('undated'),
                ]) }}
            </p>
        </div>
    </section>

    {{-- ================================================================== --
     | The code, as text
     --}}
    <section class="shell py-12 lg:py-14">
        <div class="overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-950">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 px-5 py-3 dark:border-ink-800">
                <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                    .{{ $snippet->language->fileExtension() }}
                </h2>

                <button
                    type="button"
                    x-data="{ copied: false }"
                    x-on:click="$copy(@js($snippet->code)); copied = true; setTimeout(() => copied = false, 1800)"
                    class="focus-ring inline-flex min-h-8 items-center gap-2 rounded-full border border-ink-200 px-3 font-mono text-2xs uppercase tracking-widest text-ink-600 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:text-ink-300"
                >
                    <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                </button>
            </header>

            <div class="max-h-[36rem] overflow-auto bg-ink-950 px-5 py-4">
                <pre class="font-mono text-xs leading-relaxed text-ink-100"><code>{{ $snippet->code }}</code></pre>
            </div>
        </div>

        {{-- ============================================================= --
         | The preview, or the reason there isn't one
         -- ============================================================= --}}
        @if ($this->previewPanes !== null)
            <div x-data="{ opened: false }" class="mt-6">
                <div x-show="! opened" class="rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="max-w-xl">
                            <h2 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ __('Run this in a sandbox') }}
                            </h2>
                            <p class="mt-1.5 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                                {{ __('This is somebody else’s code. The frame it runs in has no access to this site, your session, or this page, and nothing happens until you press the button. It can still loop, so Stop replaces it.') }}
                            </p>
                        </div>

                        <flux:button
                            x-on:click="opened = true; $nextTick(() => runStored())"
                            variant="primary"
                            icon="play"
                        >
                            {{ __('Preview this snippet') }}
                        </flux:button>
                    </div>
                </div>

                <div x-show="opened" x-cloak class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                    <section class="overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-950">
                        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 px-5 py-3 dark:border-ink-800">
                            <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                                {{ __('Preview') }}
                            </h2>

                            <div class="flex items-center gap-3">
                                <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ __('allow-scripts only') }}
                                </span>
                                <button
                                    type="button"
                                    x-on:click="runStored()"
                                    class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-signal-700 transition-colors hover:bg-signal-50 dark:text-signal-400 dark:hover:bg-signal-950/40"
                                >
                                    {{ __('Run again') }}
                                </button>
                                <button
                                    type="button"
                                    x-on:click="stop()"
                                    class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:bg-ink-100 dark:text-ink-400 dark:hover:bg-ink-900"
                                >
                                    {{ __('Stop') }}
                                </button>
                            </div>
                        </header>

                        <div class="bg-ink-100 p-3 dark:bg-ink-900">
                            <iframe
                                x-ref="preview"
                                wire:ignore
                                :srcdoc="frame"
                                sandbox="allow-scripts"
                                title="{{ __('Sandboxed preview of this snippet') }}"
                                class="h-[24rem] w-full rounded-lg border border-ink-200 bg-white dark:border-ink-800"
                            ></iframe>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-950">
                        <header class="flex items-center justify-between gap-3 border-b border-ink-200 px-5 py-3 dark:border-ink-800">
                            <h2 class="font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                                {{ __('Console') }}
                            </h2>

                            <button
                                type="button"
                                x-on:click="clear()"
                                class="focus-ring rounded-full px-2 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:bg-ink-100 dark:text-ink-400 dark:hover:bg-ink-900"
                            >
                                {{ __('Clear') }}
                            </button>
                        </header>

                        <div wire:ignore class="max-h-60 overflow-y-auto bg-ink-950 px-4 py-3">
                            <p x-show="lines.length === 0" class="font-mono text-2xs leading-relaxed text-ink-500">
                                {{ __('Nothing logged yet. console.log, console.warn and uncaught errors from the frame arrive here. alert() is blocked and says so.') }}
                            </p>

                            <ul class="space-y-1.5">
                                <template x-for="(line, index) in lines" :key="index">
                                    <li class="font-mono text-2xs leading-relaxed">
                                        <span class="text-ink-600" x-text="line.at"></span>
                                        <span
                                            :class="line.level === 'error' ? 'text-sui-danger' : (line.level === 'warn' ? 'text-sui-attention-ink' : 'text-ink-100')"
                                            x-text="line.text"
                                        ></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        @else
            <div class="mt-6 rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                <div class="flex items-start gap-3">
                    <span aria-hidden="true" class="mt-0.5 text-ink-400 dark:text-ink-500">
                        <flux:icon name="code-bracket" class="size-5" />
                    </span>
                    <div>
                        <h2 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                            {{ __('There is no Run button for PHP') }}
                        </h2>
                        <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                            {{ $snippet->language->runExplanation() }}
                            {{ __('Copy it and run it where a PHP interpreter lives. There is no PHP pane in the sandbox either, so this has no Run button and no way to open it in an editor: the lab stores this code, lists it, and shows it as text.') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </section>

    {{-- ================================================================== --
     | More in the same language
     --}}
    @if ($this->alsoPublished->isNotEmpty())
        <section class="shell border-t border-ink-200 py-12 dark:border-ink-800 lg:py-14">
            <h2 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                {{ __('More :language in the gallery', ['language' => $snippet->language->label()]) }}
            </h2>

            <ul class="mt-5 divide-y divide-ink-200 dark:divide-ink-800">
                @foreach ($this->alsoPublished as $other)
                    <li wire:key="also-{{ $other->id }}">
                        <a
                            href="{{ route('dev-lab.snippets.show', ['snippet' => $other->slug]) }}"
                            wire:navigate
                            class="focus-ring group flex flex-wrap items-baseline justify-between gap-3 py-3"
                        >
                            <span class="text-sm font-medium text-ink-800 transition-colors group-hover:text-signal-700 dark:text-ink-100 dark:group-hover:text-signal-400">
                                {{ $other->title }}
                            </span>
                            <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                {{ $other->published_at?->translatedFormat('j M Y') }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <a
                href="{{ route('dev-lab.gallery', ['lang' => $snippet->language->value]) }}"
                wire:navigate
                class="mt-6 inline-flex items-center gap-2 font-mono text-2xs uppercase tracking-widest text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400"
            >
                {{ __('See the whole gallery') }}
                <flux:icon name="arrow-long-right" class="size-3.5" />
            </a>
        </section>
    @endif

    @if (auth()->check())
        <section class="shell pb-14" data-test="snippet-sandbox-offer">
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                <p class="max-w-xl text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                    @if ($this->canClone)
                        {{ __('Copy this into a workspace of your own and change it there. It lands in the pane its language names, the two others are emptied, and your copy stays private until you publish it. Pressing this twice re-opens the same buffer rather than filling your shelf with copies.') }}
                    @else
                        {{ __('The sandbox is the same editor this came out of, with a Save button and a byte counter. Your own work stays private until you publish it.') }}
                    @endif
                </p>

                @if ($this->canClone)
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button
                            wire:click="openInSandbox"
                            data-test="clone-to-workspace"
                            variant="primary"
                            icon="arrow-long-right"
                        >
                            {{ __('Open in your sandbox') }}
                        </flux:button>

                        <flux:button :href="route('dev-lab.sandbox')" wire:navigate size="sm" variant="ghost">
                            {{ __('Or start a clean buffer') }}
                        </flux:button>
                    </div>
                @else
                    <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="primary">
                        {{ __('Open the sandbox') }}
                    </flux:button>
                @endif
            </div>
        </section>
    @endif
</div>
