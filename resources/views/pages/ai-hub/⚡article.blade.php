<?php

use App\Models\AiArticle;
use App\Models\AiArticleLink;
use App\Services\AiHubContent;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
new #[Title('Article — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
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
<x-log.reader
    active="ai-hub"
    data-test="ai-hub-article"
    :title="$this->article->title"
    :eyebrow="$this->article->format->label()"
    :back="route('ai-hub.articles')"
    :back-label="__('All articles')"
    :meta="array_filter([
        $this->article->user->name,
        $this->article->published_at?->translatedFormat('F j, Y'),
        $this->article->readingTimeLabel(),
        number_format($this->article->word_count).' '.__('words'),
        $this->isPreview ? __('Unpublished preview') : null,
    ])"
>
    @if (count($this->outline) >= 2)
        <x-log.toc :items="$this->outline" />
    @endif

    <div class="dn-reader-body">
        @if ($this->pathContext)
            @php $context = $this->pathContext; @endphp

            <aside class="dn-band" style="margin: 0 0 20px; border: 1px solid var(--dn-hairline); border-radius: var(--dn-radius-lg);">
                <span class="dn-band-body">
                    <span class="dn-entry-eyebrow">{{ __('Part of a learning path') }}</span>
                    <span class="dn-band-title">
                        <a href="{{ route('ai-hub.path', ['path' => $context['path']->slug]) }}" wire:navigate>{{ $context['path']->title }}</a>
                    </span>
                    <span class="dn-band-text">
                        {{ __('Lesson :step of :total in this path.', ['step' => $context['step'], 'total' => $context['total']]) }}
                    </span>
                </span>

                <span class="dn-band-actions">
                    <a href="{{ route('ai-hub.path', ['path' => $context['path']->slug]) }}" wire:navigate class="dn-btn dn-btn-outline dn-btn-sm">
                        {{ __('See the whole path') }}
                    </a>
                </span>
            </aside>
        @endif

        @if (filled($this->article->prerequisites))
            <div class="dn-band" style="margin: 0 0 20px; border: 1px solid var(--dn-hairline); border-radius: var(--dn-radius-lg);">
                <span class="dn-band-body">
                    <span class="dn-entry-eyebrow">{{ __('Before you read') }}</span>
                    <span class="dn-band-text">{{ $this->article->prerequisites }}</span>
                </span>
            </div>
        @endif

        @if (filled($this->article->summary))
            <p class="dn-reader-lead">{{ $this->article->summary }}</p>
        @endif

        {{-- Rendered, sanitised article content --}}
        <div class="dn-prose" data-reading-target>
            {!! $this->bodyHtml !!}
        </div>

        @if ($this->article->topics->isNotEmpty())
            <ul class="dn-reader-topics" role="list">
                @foreach ($this->article->topics as $topic)
                    <li>
                        <a href="{{ route('ai-hub.topic', ['topic' => $topic->slug]) }}" wire:navigate class="dn-status-chip">
                            {{ $topic->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($this->links->isNotEmpty())
            <x-log.section :title="__('References')" class="dn-reader-section">
                <div class="dn-entries">
                    @foreach ($this->links as $link)
                        <a class="dn-entry" href="{{ $link->url }}" target="_blank" rel="noopener noreferrer nofollow">
                            <span class="dn-entry-main">
                                <span class="dn-entry-head">
                                    <span class="dn-entry-title">{{ $link->label }}</span>
                                </span>

                                @if (filled($link->note))
                                    <span class="dn-entry-desc">{{ $link->note }}</span>
                                @endif

                                <span class="dn-entry-meta">
                                    <span>{{ $link->kind->label() }}</span>
                                    <span>{{ $link->host() }}</span>
                                </span>
                            </span>

                            <span class="dn-entry-arrow" aria-hidden="true">
                                <x-feed.icon name="link" :size="14" />
                            </span>
                        </a>
                    @endforeach
                </div>
            </x-log.section>
        @endif

        @if ($this->related->isNotEmpty())
            <x-log.section :title="__('Next in the collection')" class="dn-reader-section">
                <div class="dn-entries">
                    @foreach ($this->related as $related)
                        <x-log.entry
                            :href="route('ai-hub.article', ['article' => $related->slug])"
                            :title="$related->title"
                            :eyebrow="$related->format->label()"
                            :description="$related->excerpt((int) config('ai_hub.article.excerpt_length'))"
                            :badge="$related->difficulty->label()"
                            :meta="[$related->readingTimeLabel()]"
                            :wire:key="'related-'.$related->id"
                        />
                    @endforeach
                </div>
            </x-log.section>
        @endif
    </div>
</x-log.reader>
