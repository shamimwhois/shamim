<?php

use App\Models\AiArticle;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
new #[Title('AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
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
<x-log.module
    active="ai-hub"
    data-test="ai-hub-landing"
    :title="__('AI Knowledge Hub')"
    :meta="trans_choice(':count article|:count articles', $this->stats['articles'], ['count' => $this->stats['articles']])"
    :note="__('Everything we have learned about AI, written down. Articles, tutorials and technical notes on how models are actually built, evaluated and shipped.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            {{-- Gateway search. The term belongs to the articles index, which is
                 the only place the search scope is written. --}}
            <form wire:submit="submitSearch" class="dn-search-field" style="flex: 1; max-width: 420px;">
                <x-feed.icon name="search" :size="16" />
                <label for="ai-hub-search" class="sr-only">{{ __('Search the hub') }}</label>
                <input
                    id="ai-hub-search"
                    type="search"
                    wire:model.debounce.400ms="search"
                    placeholder="{{ __('Search articles, tutorials and notes') }}"
                    autocomplete="off"
                />
                <button type="submit" class="dn-btn dn-btn-sm">{{ __('Search') }}</button>
            </form>

            <div class="dn-module-stats">
                @foreach ([
                    ['label' => __('Topics'), 'value' => $this->stats['topics']],
                    ['label' => __('Paths'), 'value' => $this->stats['paths']],
                    ['label' => __('Resources'), 'value' => $this->stats['resources']],
                    ['label' => __('Reading time'), 'value' => $this->stats['minutes'], 'suffix' => __('min')],
                ] as $stat)
                    <span class="dn-module-stat">
                        <span class="dn-module-stat-label">{{ $stat['label'] }}</span>
                        <span class="dn-module-stat-value">
                            {{ number_format($stat['value']) }}@isset($stat['suffix'])<span class="dn-module-stat-suffix">{{ $stat['suffix'] }}</span>@endisset
                        </span>
                    </span>
                @endforeach
            </div>
        </div>
    </x-slot:filters>

    @if ($this->spotlight === null && $this->topics->isEmpty() && $this->recent->isEmpty() && $this->paths->isEmpty() && $this->resources->isEmpty())
        <div class="dn-module-empty" data-test="ai-hub-empty">
            <p class="dn-empty-command">fatal: the hub has no published content</p>
            <p class="dn-empty-text">
                {{ __('Articles, topics and paths stay hidden until an editor publishes them. Nothing here is broken; the shelves are simply empty.') }}
            </p>
        </div>
    @endif

    @if ($this->spotlight !== null)
        <x-log.section :title="__('Start here')" :count="__('spotlight')">
            @php $spotlight = $this->spotlight; @endphp

            <x-log.entry
                :href="route('ai-hub.article', ['article' => $spotlight->slug])"
                :title="$spotlight->title"
                :eyebrow="$spotlight->format->label()"
                :description="$spotlight->excerpt((int) config('ai_hub.article.excerpt_length'))"
                :badge="$spotlight->difficulty->label()"
                :meta="[
                    $spotlight->readingTimeLabel(),
                    $spotlight->published_at?->translatedFormat('M j, Y'),
                ]"
                :glyph="__('★')"
            />
        </x-log.section>
    @endif

    @if ($this->topics->isNotEmpty())
        <x-log.section
            :title="__('Topics')"
            :count="trans_choice(':count topic|:count topics', $this->stats['topics'], ['count' => $this->stats['topics']])"
            :href="route('ai-hub.topics')"
        >
            @foreach ($this->topics as $topic)
                <x-log.entry
                    :href="route('ai-hub.topic', ['topic' => $topic->slug])"
                    :title="$topic->name"
                    :description="$topic->description"
                    :meta="[
                        trans_choice(':count article|:count articles', (int) $topic->articles_count, ['count' => (int) $topic->articles_count]),
                        $topic->children->isNotEmpty() ? trans_choice(':count subtopic|:count subtopics', $topic->children->count(), ['count' => $topic->children->count()]) : null,
                    ]"
                    :glyph="mb_substr((string) $topic->name, 0, 2)"
                    :wire:key="'hub-topic-'.$topic->id"
                />
            @endforeach
        </x-log.section>
    @endif

    @if ($this->recent->isNotEmpty())
        <x-log.section
            :title="__('Most recent reading')"
            :count="trans_choice(':count article|:count articles', $this->stats['articles'], ['count' => $this->stats['articles']])"
            :href="route('ai-hub.articles')"
        >
            @foreach ($this->recent as $article)
                <x-log.entry
                    :href="route('ai-hub.article', ['article' => $article->slug])"
                    :title="$article->title"
                    :eyebrow="$article->format->label()"
                    :description="$article->excerpt((int) config('ai_hub.article.excerpt_length'))"
                    :badge="$article->difficulty->label()"
                    :meta="[
                        $article->readingTimeLabel(),
                        $article->published_at?->translatedFormat('M j, Y'),
                    ]"
                    :wire:key="'hub-article-'.$article->id"
                />
            @endforeach
        </x-log.section>
    @endif

    @if ($this->paths->isNotEmpty())
        <x-log.section
            :title="__('Learning paths')"
            :count="trans_choice(':count path|:count paths', $this->stats['paths'], ['count' => $this->stats['paths']])"
            :href="route('ai-hub.paths')"
        >
            @foreach ($this->paths as $path)
                <x-log.entry
                    :href="route('ai-hub.path', ['path' => $path->slug])"
                    :title="$path->title"
                    :eyebrow="$path->difficulty->label()"
                    :description="$path->description"
                    :meta="[
                        trans_choice(':count lesson|:count lessons', $path->lessonCount(), ['count' => $path->lessonCount()]),
                        $path->estimatedMinutesLabel(),
                        $path->topic?->name,
                    ]"
                    :wire:key="'hub-path-'.$path->id"
                />
            @endforeach
        </x-log.section>
    @endif

    @if ($this->resources->isNotEmpty())
        <x-log.section
            :title="__('Resources')"
            :count="trans_choice(':count resource|:count resources', $this->stats['resources'], ['count' => $this->stats['resources']])"
            :href="route('ai-hub.resources')"
        >
            @foreach ($this->resources as $resource)
                <x-log.entry
                    :href="$resource->url"
                    :title="$resource->title"
                    :eyebrow="$resource->kind->label()"
                    :description="$resource->description"
                    :meta="[$resource->provider, $resource->host()]"
                    :wire:key="'hub-resource-'.$resource->id"
                />
            @endforeach
        </x-log.section>
    @endif
</x-log.module>
