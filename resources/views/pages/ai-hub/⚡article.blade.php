<?php

use App\Models\AiArticle;
use App\Models\AiArticleLink;
use App\Services\AiHubContent;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A single hub article.
 *
 * Responsibilities, in document order:
 *
 *   - Resolve the article via slug (route model binding) and authorise reading,
 *     so a draft is a 404 for everyone except the account that wrote it.
 *   - Render the stored `body_html` — the sanitised projection written by the
 *     save action — and derive a table of contents from it.
 *   - Say where the article sits if it is a lesson inside a learning path.
 *   - Show the operator-curated external references, labelled by kind.
 *   - Offer related reading, falling back to the primary topic's shelf when
 *     nothing has been curated.
 *
 * The renderer is never invoked here. `body_html` is produced once, by
 * `MarkdownRenderer`, at write time; re-rendering markdown on read would let the
 * public page disagree with whatever the editor reviewed and would put an
 * unsanitised path in front of readers.
 */
new #[Title('Article — AI Knowledge Hub')] class extends Component
{
    /**
     * The article being read. Locked so a crafted request cannot swap the model
     * out from under the component between the mount and a subsequent action.
     */
    #[Locked]
    public AiArticle $article;

    public function mount(AiArticle $article): void
    {
        // A 404 rather than a 403: an unpublished article is not a resource the
        // visitor has been denied, it is a resource that is not published.
        abort_unless($article->isVisibleTo(auth()->user()), 404);

        $this->article = $article->load(['topics', 'user', 'links']);

        app(Seo::class)->set([
            'title' => $article->meta_title ?? $article->title,
            'description' => $article->meta_description ?? $article->excerpt((int) config('ai_hub.article.excerpt_length')),
            'canonical' => route('ai-hub.article', ['article' => $article->slug]),
            'type' => 'article',
            'publishedAt' => $article->published_at?->toIso8601String(),
            'modifiedAt' => $article->updated_at->toIso8601String(),
            'authorName' => $article->user->name,
        ]);
    }

    /**
     * The sanitised body, ready to print.
     */
    #[Computed]
    public function bodyHtml(): string
    {
        return $this->article->body_html ?? '';
    }

    /**
     * Headings extracted from the rendered body, for the table of contents.
     *
     * @return array<int, array{level: int, id: string, text: string}>
     */
    #[Computed]
    public function outline(): array
    {
        return app(MarkdownRenderer::class)->outline($this->bodyHtml);
    }

    /**
     * The external references, trimmed to what the page can show.
     *
     * @return Collection<int, AiArticleLink>
     */
    #[Computed]
    public function links(): Collection
    {
        return $this->article->links
            ->sortBy('sort_order')
            ->take((int) config('ai_hub.article.links_limit'))
            ->values();
    }

    /**
     * The path this article is a lesson in, with the reader's position in it.
     *
     * @return array{path: \App\Models\AiLearningPath, step: int, total: int}|null
     */
    #[Computed]
    public function pathContext(): ?array
    {
        return app(AiHubContent::class)->pathContext($this->article);
    }

    /**
     * Curated related articles, or the primary topic's shelf when none are set.
     *
     * @return Collection<int, AiArticle>
     */
    #[Computed]
    public function related(): Collection
    {
        $hub = app(AiHubContent::class);

        $related = $hub->relatedArticles($this->article);

        return $related->isNotEmpty()
            ? $related
            : $hub->moreFromTopic($this->article, (int) config('ai_hub.article.related_limit'));
    }

    /**
     * Whether the reader is looking at an article that is not yet public.
     */
    #[Computed]
    public function isPreview(): bool
    {
        return ! $this->article->isPublished();
    }
}; ?>

<div data-test="ai-hub-article">
    <article>
        {{-- ============================================================== --
         | Header
         --}}
        <header class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

            <div class="shell relative py-12 lg:py-16">
                <nav aria-label="{{ __('Breadcrumb') }}" class="mb-8">
                    <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        <li>
                            <a href="{{ route('ai-hub.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                                {{ __('AI Knowledge Hub') }}
                            </a>
                        </li>
                        <li aria-hidden="true">/</li>
                        <li>
                            <a href="{{ route('ai-hub.articles') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                                {{ __('Articles') }}
                            </a>
                        </li>

                        @if ($this->article->primaryTopic())
                            @php $topic = $this->article->primaryTopic(); @endphp
                            <li aria-hidden="true">/</li>
                            <li>
                                <a
                                    href="{{ route('ai-hub.topic', ['topic' => $topic->slug]) }}"
                                    wire:navigate
                                    class="text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                                >
                                    {{ $topic->name }}
                                </a>
                            </li>
                        @endif
                    </ol>
                </nav>

                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-app.ai-hub.pill :icon="$this->article->format->icon()" tone="signal">{{ $this->article->format->label() }}</x-app.ai-hub.pill>
                        <x-app.ai-hub.pill>{{ $this->article->difficulty->label() }}</x-app.ai-hub.pill>

                        @if ($this->isPreview)
                            <x-app.ai-hub.pill icon="eye" tone="signal">{{ __('Unpublished preview') }}</x-app.ai-hub.pill>
                        @endif
                    </div>

                    <h1 class="mt-6 text-3xl font-semibold leading-[1.15] tracking-tightest text-ink-900 dark:text-ink-50 sm:text-4xl lg:text-5xl">
                        {{ $this->article->title }}
                    </h1>

                    @if (filled($this->article->summary))
                        <p class="mt-6 max-w-2xl text-lg leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ $this->article->summary }}
                        </p>
                    @endif

                    <div class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        <span>{{ $this->article->user->name }}</span>
                        <span aria-hidden="true">·</span>

                        @if ($this->article->published_at)
                            <time datetime="{{ $this->article->published_at->toIso8601String() }}">
                                {{ $this->article->published_at->translatedFormat('F j, Y') }}
                            </time>
                            <span aria-hidden="true">·</span>
                        @endif

                        <span>{{ $this->article->readingTimeLabel() }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ number_format($this->article->word_count) }} {{ __('words') }}</span>

                        @if ($this->article->updated_at->gt($this->article->published_at?->addDay() ?? $this->article->created_at))
                            <span aria-hidden="true">·</span>
                            <span>{{ __('Updated :date', ['date' => $this->article->updated_at->translatedFormat('M j, Y')]) }}</span>
                        @endif
                    </div>

                    @if ($this->article->topics->isNotEmpty())
                        <ul class="mt-6 flex flex-wrap gap-1.5" role="list">
                            @foreach ($this->article->topics as $topic)
                                <li>
                                    <a
                                        href="{{ route('ai-hub.topic', ['topic' => $topic->slug]) }}"
                                        wire:navigate
                                        class="focus-ring inline-flex min-w-0 items-center gap-1.5 rounded-full border border-ink-200 bg-white px-2.5 py-0.5 font-mono text-2xs uppercase tracking-widest text-ink-600 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400"
                                    >
                                        <flux:icon :name="$topic->icon ?? 'tag'" class="size-3 shrink-0" />
                                        <span class="truncate">{{ $topic->name }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </header>

        {{-- ============================================================== --
         | Body — article column plus a sticky sidebar
         --}}
        <div class="shell py-12 lg:py-16">
            <div class="grid gap-12 lg:grid-cols-[minmax(0,1fr)_220px] lg:gap-16">
                <div class="min-w-0">
                    @if ($this->pathContext)
                        @php $context = $this->pathContext; @endphp

                        <aside class="mb-10 rounded-xl border border-signal-300 bg-signal-50 p-5 dark:border-signal-800 dark:bg-signal-950/40">
                            <p class="font-mono text-2xs uppercase tracking-widest text-signal-700 dark:text-signal-300">
                                {{ __('Part of a learning path') }}
                            </p>

                            <h2 class="mt-2 text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                <a href="{{ route('ai-hub.path', ['path' => $context['path']->slug]) }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                                    {{ $context['path']->title }}
                                </a>
                            </h2>

                            <p class="mt-2 text-sm text-ink-600 dark:text-ink-300">
                                {{ __('Lesson :step of :total in this path.', ['step' => $context['step'], 'total' => $context['total']]) }}
                            </p>

                            <a
                                href="{{ route('ai-hub.path', ['path' => $context['path']->slug]) }}"
                                wire:navigate
                                class="focus-ring mt-4 inline-flex min-h-9 items-center gap-1.5 text-sm font-medium text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400"
                            >
                                {{ __('See the whole path') }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </aside>
                    @endif

                    @if (filled($this->article->prerequisites))
                        <div class="mb-10 rounded-xl border border-ink-200 bg-ink-50 p-5 dark:border-ink-800 dark:bg-ink-900">
                            <p class="flex items-center gap-2 font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                                <flux:icon name="information-circle" class="size-4" />
                                {{ __('Before you read') }}
                            </p>

                            <p class="mt-2 text-sm leading-relaxed text-ink-700 dark:text-ink-200">
                                {{ $this->article->prerequisites }}
                            </p>
                        </div>
                    @endif

                    {{-- Rendered, sanitised article content --}}
                    <div class="prose-editorial" data-reading-target>
                        {!! $this->bodyHtml !!}
                    </div>

                    @if ($this->links->isNotEmpty())
                        <section class="mt-14 border-t border-ink-200 pt-8 dark:border-ink-800" aria-labelledby="hub-article-links-heading">
                            <h2 id="hub-article-links-heading" class="flex items-center gap-2 font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                                <flux:icon name="link" class="size-4" />
                                {{ __('References') }}
                            </h2>

                            <ul class="mt-5 space-y-3" role="list">
                                @foreach ($this->links as $link)
                                    <li class="rounded-lg border border-ink-200 bg-white p-4 transition-colors hover:border-signal-400 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600">
                                        <a
                                            href="{{ $link->url }}"
                                            target="_blank"
                                            rel="noopener noreferrer nofollow"
                                            class="focus-ring group inline-flex items-start gap-2.5 text-sm font-semibold text-ink-900 dark:text-ink-50"
                                        >
                                            <flux:icon :name="$link->kind->icon()" class="mt-0.5 size-4 shrink-0 text-ink-400 dark:text-ink-500" />
                                            <span class="underline-offset-4 group-hover:underline">{{ $link->label }}</span>
                                            <flux:icon name="arrow-top-right-on-square" class="mt-0.5 size-3.5 shrink-0 text-ink-400 transition-colors group-hover:text-signal-600 dark:text-ink-500" />
                                        </a>

                                        <p class="mt-1.5 pl-[1.625rem] font-mono text-2xs text-ink-400 dark:text-ink-500">
                                            {{ $link->kind->label() }} · {{ $link->host() }}
                                        </p>

                                        @if (filled($link->note))
                                            <p class="mt-2 pl-[1.625rem] text-sm leading-relaxed text-ink-500 dark:text-ink-400">{{ $link->note }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>

                {{-- Sticky sidebar --}}
                <aside class="hidden lg:block">
                    <div class="sticky top-24 space-y-8">
                        @if (count($this->outline) >= 2)
                            <x-app.ui.post.toc :items="$this->outline" />
                        @endif

                        <div>
                            <h2 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                {{ __('Facts') }}
                            </h2>

                            <dl class="mt-4 space-y-3 text-sm">
                                @foreach ([
                                    ['label' => __('Format'), 'value' => $this->article->format->label()],
                                    ['label' => __('Level'), 'value' => $this->article->difficulty->label()],
                                    ['label' => __('Reading time'), 'value' => $this->article->readingTimeLabel()],
                                    ['label' => __('Words'), 'value' => number_format($this->article->word_count)],
                                ] as $fact)
                                    <div class="flex items-baseline justify-between gap-3 border-b border-ink-100 pb-2 dark:border-ink-800">
                                        <dt class="text-ink-500 dark:text-ink-400">{{ $fact['label'] }}</dt>
                                        <dd class="text-right font-medium text-ink-900 dark:text-ink-50">{{ $fact['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        <a
                            href="{{ route('ai-hub.articles') }}"
                            wire:navigate
                            class="focus-ring inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                        >
                            <flux:icon name="arrow-left" class="size-4" />
                            {{ __('All articles') }}
                        </a>
                    </div>
                </aside>
            </div>

            {{-- Mobile TOC, collapsed --}}
            @if (count($this->outline) >= 2)
                <details class="mt-12 lg:hidden">
                    <summary class="cursor-pointer font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">
                        {{ __('On this page') }}
                    </summary>
                    <x-app.ui.post.toc :items="$this->outline" id="toc-mobile" class="mt-4" />
                </details>
            @endif
        </div>
    </article>

    {{-- ================================================================== --
     | Related reading
     --}}
    @if ($this->related->isNotEmpty())
        <section data-reveal class="border-t border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-article-related-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="2" label="Keep reading" />

                <h2 id="hub-article-related-heading" class="mt-5 text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ __('Next in the collection') }}
                </h2>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->related as $related)
                        <x-app.ai-hub.article-card :article="$related" wire:key="related-{{ $related->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
