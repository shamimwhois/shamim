<?php

use App\Models\AiArticle;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * AI Knowledge Hub — landing.
 *
 * The hub's front door is a set of shelves rather than an index: a reader who
 * has never been here needs to see what the collection is *for* before being
 * handed a filter bar. Each shelf therefore asks the service for a fixed, small
 * number of rows (all of them in `config/ai_hub.php`) and links to the index
 * that can page through the rest.
 *
 * Every figure and every card comes from `AiHubContent`, which reads only
 * published rows. That is the reason the counts printed in the opening band
 * always agree with the shelves underneath them, and the reason a draft written
 * five minutes ago cannot appear here.
 *
 * The search field is a gateway rather than a second catalogue: it hands the
 * term to `/ai-hub/articles`, which owns filtering, sorting and pagination. A
 * landing page with its own result grid would be a second copy of the articles
 * index that can drift from the first.
 */
new #[Title('AI Knowledge Hub')] class extends Component
{
    /** Ceiling for the hero term, so its redirect stays a legal query string. */
    private const int SEARCH_MAX_LENGTH = 200;

    /**
     * The term typed into the hero field, carried to the articles index.
     */
    public string $search = '';

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'AI Knowledge Hub',
            'description' => __('A working knowledge base for AI practice: articles, tutorials and notes, organised into topics and sequenced into learning paths.'),
            'canonical' => route('ai-hub.index'),
        ]);
    }

    /**
     * Send the hero term to the articles index, which owns the search scope.
     *
     * The cap is a redirect guarantee, not a judgement about searching: the
     * index's scope escapes and parameterises whatever it receives, but nothing
     * is obliged to accept a query string the length of an essay, and the hero
     * field never hands the index more than it can meaningfully match.
     */
    public function submitSearch(): void
    {
        $term = mb_substr(trim($this->search), 0, self::SEARCH_MAX_LENGTH);

        $this->redirectRoute(
            'ai-hub.articles',
            $term === '' ? [] : ['q' => $term],
            navigate: true,
        );
    }

    /**
     * Hub-wide totals. Every value is a database aggregate over published rows.
     *
     * @return array{articles: int, topics: int, paths: int, resources: int, minutes: int}
     */
    #[Computed]
    public function stats(): array
    {
        return app(AiHubContent::class)->statistics();
    }

    /**
     * The article the hub currently wants a first-time reader to open.
     */
    #[Computed]
    public function spotlight(): ?AiArticle
    {
        return app(AiHubContent::class)->spotlight();
    }

    /**
     * The topic grid: root topics, each carrying its subtopics and count.
     *
     * @return Collection<int, \App\Models\AiTopic>
     */
    #[Computed]
    public function topics(): Collection
    {
        return app(AiHubContent::class)
            ->topicTree()
            ->take((int) config('ai_hub.landing.topic_cards'));
    }

    /**
     * @return Collection<int, AiArticle>
     */
    #[Computed]
    public function recent(): Collection
    {
        return app(AiHubContent::class)->recent();
    }

    /**
     * @return Collection<int, \App\Models\AiLearningPath>
     */
    #[Computed]
    public function paths(): Collection
    {
        return app(AiHubContent::class)->featuredPaths();
    }

    /**
     * @return Collection<int, \App\Models\AiResource>
     */
    #[Computed]
    public function resources(): Collection
    {
        return app(AiHubContent::class)->featuredResources();
    }
}; ?>

<div data-test="ai-hub-landing">
    {{-- ================================================================== --
     | 01 — Hero
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-14 lg:py-20">
            <x-app.ui.section-marker :number="1" label="AI Knowledge Hub" accent />

            <div class="mt-8 grid items-start gap-12 lg:grid-cols-[minmax(0,7fr)_minmax(0,4fr)] lg:gap-16">
                <div class="min-w-0">
                    <h1 class="max-w-3xl text-[clamp(2.25rem,5vw,3.75rem)] font-semibold leading-[1.02] tracking-tightest text-ink-900 dark:text-ink-50">
                        {{ __('Everything we have learned about AI, written down.') }}
                    </h1>

                    <p class="mt-6 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ __('Articles, tutorials and technical notes on how models are actually built, evaluated and shipped. Topics group the material; learning paths put it in an order that works.') }}
                    </p>

                    {{-- Gateway search. The term belongs to the articles index,
                         which is the only place the search scope is written. --}}
                    <form wire:submit="submitSearch" class="mt-8 max-w-xl">
                        <label for="ai-hub-search" class="sr-only">{{ __('Search the hub') }}</label>
                        <div class="relative">
                            <span aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400 dark:text-ink-500">
                                <flux:icon name="magnifying-glass" class="size-4" />
                            </span>

                            <input
                                id="ai-hub-search"
                                type="search"
                                wire:model.debounce.400ms="search"
                                placeholder="{{ __('Search articles, tutorials and notes') }}"
                                class="focus-ring min-h-12 w-full rounded-full border border-ink-200 bg-white py-3 pl-11 pr-4 text-sm text-ink-900 placeholder:text-ink-400 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-500"
                            >
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <flux:button type="submit" variant="primary" size="sm">
                                {{ __('Search the hub') }}
                            </flux:button>

                            <a
                                href="{{ route('ai-hub.articles') }}"
                                wire:navigate
                                class="focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                            >
                                {{ __('Browse everything') }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </form>

                    <dl class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-4">
                        @foreach ([
                            ['label' => __('Articles'), 'value' => $this->stats['articles']],
                            ['label' => __('Topics'), 'value' => $this->stats['topics']],
                            ['label' => __('Paths'), 'value' => $this->stats['paths']],
                            ['label' => __('Resources'), 'value' => $this->stats['resources']],
                            ['label' => __('Reading time'), 'value' => $this->stats['minutes'], 'suffix' => __('min')],
                        ] as $stat)
                            <div>
                                <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ $stat['label'] }}</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-ink-900 dark:text-ink-50">
                                    {{ number_format($stat['value']) }}@isset($stat['suffix'])<span class="ml-1 text-xs font-normal text-ink-400 dark:text-ink-500">{{ $stat['suffix'] }}</span>@endisset
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Spotlight: the one inverted surface on the page, so the
                     single article the hub recommends reads as a recommendation
                     rather than as the first card in a grid. --}}
                @if ($this->spotlight)
                    @php $spotlight = $this->spotlight; @endphp

                    <aside class="relative overflow-hidden rounded-2xl bg-ink-900 text-white dark:border dark:border-ink-800" aria-label="{{ __('Featured article') }}">
                        <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(52,211,153,0.28),transparent_62%)]"></div>

                        <div class="relative p-6 sm:p-8">
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-mono text-2xs uppercase tracking-widest text-ink-300">{{ __('Start here') }}</p>

                                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/15 px-2.5 py-0.5 font-mono text-2xs uppercase tracking-widest text-ink-200">
                                    <flux:icon :name="$spotlight->format->icon()" class="size-3" />
                                    {{ $spotlight->format->label() }}
                                </span>
                            </div>

                            <h2 class="mt-6 text-xl font-semibold leading-snug tracking-tight text-white sm:text-2xl">
                                {{ $spotlight->title }}
                            </h2>

                            <p class="mt-3 text-sm leading-relaxed text-ink-200">
                                {{ $spotlight->excerpt((int) config('ai_hub.article.excerpt_length')) }}
                            </p>

                            <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2 font-mono text-2xs text-ink-300">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon name="clock" class="size-3.5" />
                                    {{ $spotlight->readingTimeLabel() }}
                                </span>
                                <span>{{ $spotlight->difficulty->label() }}</span>
                                @if ($spotlight->published_at)
                                    <time datetime="{{ $spotlight->published_at->toIso8601String() }}">
                                        {{ $spotlight->published_at->translatedFormat('M j, Y') }}
                                    </time>
                                @endif
                            </div>

                            <a
                                href="{{ route('ai-hub.article', ['article' => $spotlight->slug]) }}"
                                wire:navigate
                                class="focus-ring mt-7 inline-flex min-h-10 items-center gap-2 rounded-full bg-white px-5 py-2 text-sm font-semibold text-ink-900 transition-colors hover:bg-ink-100"
                            >
                                {{ __('Read article') }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </aside>
                @endif
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Topics
     --}}
    @if ($this->topics->isNotEmpty())
        <section data-reveal class="border-b border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-topics-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="2" label="Topics" />

                <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
                    <div class="min-w-0">
                        <h2 id="hub-topics-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                            {{ __('What the collection covers') }}
                        </h2>

                        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ __('Topics are maintained by hand and can be nested. A count on a topic is a count of published articles, so it describes what you can actually read.') }}
                        </p>
                    </div>

                    <a
                        href="{{ route('ai-hub.topics') }}"
                        wire:navigate
                        class="focus-ring shrink-0 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                    >
                        {{ __('All topics') }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($this->topics as $topic)
                        <x-app.ai-hub.topic-card :topic="$topic" wire:key="topic-{{ $topic->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 03 — Recent articles
     --}}
    @if ($this->recent->isNotEmpty())
        <section data-reveal class="border-b border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-articles-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="3" label="Articles" />

                <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
                    <div class="min-w-0">
                        <h2 id="hub-articles-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                            {{ __('Most recent reading') }}
                        </h2>

                        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ __('Newest first, shortest read broken out separately. Filter by format, difficulty or topic on the articles index.') }}
                        </p>
                    </div>

                    <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ trans_choice(':count article|:count articles', $this->stats['articles']) }}
                    </p>
                </div>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->recent as $article)
                        <x-app.ai-hub.article-card :article="$article" wire:key="article-{{ $article->id }}" />
                    @endforeach
                </div>

                <div class="mt-8">
                    <flux:button :href="route('ai-hub.articles')" wire:navigate icon="newspaper">
                        {{ __('Browse all articles') }}
                    </flux:button>
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 04 — Learning paths
     --}}
    @if ($this->paths->isNotEmpty())
        <section data-reveal class="border-b border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-paths-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="4" label="Learning paths" />

                <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
                    <div class="min-w-0">
                        <h2 id="hub-paths-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                            {{ __('In an order that works') }}
                        </h2>

                        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ __('A path is a sequence of published articles with an estimated cost in minutes. The order is the point — lessons stay where the operator put them.') }}
                        </p>
                    </div>

                    <a
                        href="{{ route('ai-hub.paths') }}"
                        wire:navigate
                        class="focus-ring shrink-0 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                    >
                        {{ __('All paths') }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->paths as $path)
                        <x-app.ai-hub.path-card :path="$path" wire:key="path-{{ $path->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 05 — Resources
     --}}
    @if ($this->resources->isNotEmpty())
        <section data-reveal class="py-14 lg:py-16" aria-labelledby="hub-resources-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="5" label="Resources" />

                <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
                    <div class="min-w-0">
                        <h2 id="hub-resources-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                            {{ __('Worth keeping open') }}
                        </h2>

                        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ __('Tools, libraries, datasets, papers and courses the articles here depend on. These links leave the site.') }}
                        </p>
                    </div>

                    <a
                        href="{{ route('ai-hub.resources') }}"
                        wire:navigate
                        class="focus-ring shrink-0 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                    >
                        {{ __('All resources') }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($this->resources as $resource)
                        <x-app.ai-hub.resource-card :resource="$resource" wire:key="resource-{{ $resource->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 06 — Empty state
     --}}
    @if ($this->spotlight === null && $this->topics->isEmpty() && $this->recent->isEmpty() && $this->paths->isEmpty() && $this->resources->isEmpty())
        <section class="shell py-20">
            <x-app.ui.empty-state
                icon="sparkles"
                :title="__('The hub has no published content yet')"
                :description="__('Articles, topics and paths stay hidden until an editor publishes them. Nothing here is broken; the shelves are simply empty.')"
            >
                <flux:button :href="route('home')" wire:navigate icon="arrow-path">
                    {{ __('Back to the home page') }}
                </flux:button>
            </x-app.ui.empty-state>
        </section>
    @endif
</div>
