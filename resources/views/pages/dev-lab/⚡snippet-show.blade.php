<?php

use App\Actions\DevLab\CloneSnippetToWorkspace;
use App\Enums\AccountPermission;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
new #[Title('Snippet')] #[Layout('layouts::feed')] class extends Component
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
<x-log.reader
    active="dev-lab"
    data-test="dev-lab-snippet"
    :title="$snippet->title"
    :eyebrow="$snippet->language->label()"
    :back="route('dev-lab.gallery')"
    :back-label="__('Gallery')"
    :meta="array_filter([
        trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]),
        trans_choice(':count view|:count views', $snippet->view_count, ['count' => $snippet->view_count]),
        __('by :author · published :date', [
            'author' => $snippet->user?->name ?? __('Someone'),
            'date' => $snippet->published_at?->translatedFormat('j M Y') ?? __('undated'),
        ]),
    ])"
>
    <div class="dn-reader-body">
        @if (filled($snippet->description))
            <p class="dn-reader-lead">{{ $snippet->description }}</p>
        @endif

        {{-- The code, as text. A snippet page shows source the way a published
             page shows prose: escaped, in a `<pre>`, never as nodes. --}}
        <div class="dn-code">
            <header class="dn-code-head">
                <h2 class="dn-lab-pane-label">.{{ $snippet->language->fileExtension() }}</h2>

                <button
                    type="button"
                    class="dn-lab-link"
                    x-data="{ copied: false }"
                    x-on:click="$copy(@js($snippet->code)); copied = true; setTimeout(() => copied = false, 1800)"
                >
                    <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                </button>
            </header>

            <pre class="dn-lab-code" style="max-height: 36rem; margin: 0; border-radius: 0;"><code>{{ $snippet->code }}</code></pre>
        </div>

        {{-- The preview, or the reason there isn't one --}}
        @if ($this->previewPanes !== null)
            <div x-data="{ opened: false }" class="dn-preview">
                <div x-show="! opened" class="dn-band" style="border-bottom: 0;">
                    <span class="dn-band-body">
                        <span class="dn-band-title">{{ __('Run this in a sandbox') }}</span>
                        <span class="dn-band-text">
                            {{ __('This is somebody else’s code. The frame it runs in has no access to this site, your session, or this page, and nothing happens until you press the button. It can still loop, so Stop replaces it.') }}
                        </span>
                    </span>

                    <span class="dn-band-actions">
                        <button type="button" class="dn-btn dn-btn-sm" x-on:click="opened = true; $nextTick(() => runStored())">
                            {{ __('Preview this snippet') }}
                        </button>
                    </span>
                </div>

                {{-- The factory is mounted here rather than on the outer div, so
                     the offer and the frame keep separate scopes: `opened` belongs
                     to the page, the sandbox's own state belongs to the frame. --}}
                <div
                    x-show="opened"
                    x-cloak
                    x-data="labSandbox({
                        maxLineChars: {{ (int) config('dev_lab.max_log_line_chars') }},
                        panes: @js($this->previewPanes),
                    })"
                    class="dn-lab-running"
                >
                    <div class="dn-lab-frame-column">
                        <div class="dn-lab-pane-head">
                            <span class="dn-lab-pane-label">{{ __('Preview') }}</span>

                            <span class="dn-lab-pane-actions">
                                <span class="dn-lab-pane-label">{{ __('allow-scripts only') }}</span>
                                <button type="button" class="dn-lab-link" x-on:click="runStored()">{{ __('Run again') }}</button>
                                <button type="button" class="dn-lab-link" x-on:click="stop()">{{ __('Stop') }}</button>
                            </span>
                        </div>

                        <div class="dn-lab-frame">
                            <iframe
                                x-ref="preview"
                                wire:ignore
                                :srcdoc="frame"
                                sandbox="allow-scripts"
                                title="{{ __('Sandboxed preview of this snippet') }}"
                                class="dn-lab-iframe"
                                style="height: 24rem;"
                            ></iframe>
                        </div>
                    </div>

                    <div class="dn-lab-console-column">
                        <div class="dn-lab-pane-head">
                            <span class="dn-lab-pane-label">{{ __('Console') }}</span>

                            <span class="dn-lab-pane-actions">
                                <button type="button" class="dn-lab-link" x-on:click="clear()">{{ __('Clear') }}</button>
                            </span>
                        </div>

                        <div wire:ignore class="dn-lab-console" style="max-height: 15rem;">
                            <p x-show="lines.length === 0" class="dn-lab-note">
                                {{ __('Nothing logged yet. console.log, console.warn and uncaught errors from the frame arrive here. alert() is blocked and says so.') }}
                            </p>

                            <ul class="dn-lab-console-list">
                                <template x-for="(line, index) in lines" :key="index">
                                    <li class="dn-lab-console-line">
                                        <span class="dn-lab-console-at" x-text="line.at"></span>
                                        <span
                                            :class="line.level === 'error' ? 'is-error' : (line.level === 'warn' ? 'is-warn' : 'is-log')"
                                            x-text="line.text"
                                        ></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="dn-band" style="border-bottom: 0; margin-top: 16px;">
                <span class="dn-band-body">
                    <span class="dn-band-title">{{ __('There is no Run button for PHP') }}</span>
                    <span class="dn-band-text">
                        {{ $snippet->language->runExplanation() }}
                        {{ __('Copy it and run it where a PHP interpreter lives. There is no PHP pane in the sandbox either, so this has no Run button and no way to open it in an editor: the lab stores this code, lists it, and shows it as text.') }}
                    </span>
                </span>
            </div>
        @endif

        @if ($this->alsoPublished->isNotEmpty())
            <x-log.section
                :title="__('More :language in the gallery', ['language' => $snippet->language->label()])"
                :count="trans_choice(':count snippet|:count snippets', $this->alsoPublished->count(), ['count' => $this->alsoPublished->count()])"
            >
                <div class="dn-entries">
                    @foreach ($this->alsoPublished as $other)
                        <x-log.entry
                            :href="route('dev-lab.snippets.show', ['snippet' => $other->slug])"
                            :title="$other->title"
                            :eyebrow="$other->language->label()"
                            :description="$other->excerpt(120)"
                            :meta="[$other->published_at?->translatedFormat('j M Y')]"
                            :wire:key="'also-'.$other->id"
                        />
                    @endforeach
                </div>
            </x-log.section>
        @endif

        @auth
            <div class="dn-band" data-test="snippet-sandbox-offer" style="border-bottom: 0; margin-top: 24px;">
                <span class="dn-band-body">
                    <span class="dn-band-text">
                        @if ($this->canClone)
                            {{ __('Copy this into a workspace of your own and change it there. It lands in the pane its language names, the two others are emptied, and your copy stays private until you publish it. Pressing this twice re-opens the same buffer rather than filling your shelf with copies.') }}
                        @else
                            {{ __('The sandbox is the same editor this came out of, with a Save button and a byte counter. Your own work stays private until you publish it.') }}
                        @endif
                    </span>
                </span>

                <span class="dn-band-actions">
                    @if ($this->canClone)
                        <button type="button" class="dn-btn dn-btn-sm" wire:click="openInSandbox" data-test="clone-to-workspace">
                            {{ __('Open in your sandbox') }}
                        </button>

                        <a href="{{ route('dev-lab.sandbox') }}" wire:navigate class="dn-btn dn-btn-outline dn-btn-sm">
                            {{ __('Or start a clean buffer') }}
                        </a>
                    @else
                        <a href="{{ route('dev-lab.sandbox') }}" wire:navigate class="dn-btn dn-btn-sm">
                            {{ __('Open the sandbox') }}
                        </a>
                    @endif
                </span>
            </div>
        @endauth
    </div>
</x-log.reader>
