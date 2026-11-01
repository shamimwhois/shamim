<?php

use App\Models\AiLearningPath;
use App\Models\AiLearningPathItem;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A single learning path, in order.
 *
 * The page's whole job is to hold the sequence. Lessons are numbered from the
 * stored `sort_order`, never re-sorted by date or title, and an editor's
 * per-path re-label of a lesson is shown in preference to the article's own
 * title because the path is describing a step in a route, not the article as a
 * standalone piece.
 *
 * A lesson whose article has since been archived stays in the list. Dropping it
 * would silently renumber everything after it and break the sequence the path
 * exists to promise, so it is rendered as an unavailable step instead of a link.
 */
new #[Title('Learning Path — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
{
    /**
     * The path being read. Locked so a subsequent request cannot swap it.
     */
    #[Locked]
    public AiLearningPath $path;

    public function mount(AiLearningPath $path): void
    {
        // A 404 rather than a 403: a draft path is not a route the visitor was
        // denied, it is a route that has not been published.
        abort_unless($path->isPublished(), 404);

        $this->path = $path->load(['topic', 'user', 'items' => fn (Relation $query) => $query->with('article')]);

        app(Seo::class)->set([
            'title' => $path->meta_title ?? $path->title,
            'description' => $path->meta_description ?? ($path->description ?? __('An ordered learning path through :count lessons in the AI Knowledge Hub.', ['count' => $path->lessonCount()])),
            'canonical' => route('ai-hub.path', ['path' => $path->slug]),
        ]);
    }

    /**
     * The lesson slots, in path order.
     *
     * @return Collection<int, AiLearningPathItem>
     */
    #[Computed]
    public function lessons(): Collection
    {
        return $this->path->items;
    }

    /**
     * How many of the lessons are actually readable right now.
     *
     * Stated separately from the lesson count because a path can be published
     * and still lose a lesson to the archive afterwards. The reader is told the
     * difference rather than discovering it by clicking.
     */
    #[Computed]
    public function availableCount(): int
    {
        return $this->lessons->filter(fn (AiLearningPathItem $item): bool => $item->article?->isPublished() === true)->count();
    }

    /**
     * The other published paths under the same topic.
     *
     * @return Collection<int, AiLearningPath>
     */
    #[Computed]
    public function relatedPaths(): Collection
    {
        return app(AiHubContent::class)->siblingPaths($this->path);
    }

    /**
     * Published resources filed under the path's topic, for the closing shelf.
     *
     * @return Collection<int, \App\Models\AiResource>
     */
    #[Computed]
    public function resources(): Collection
    {
        $topic = $this->path->topic;

        return $topic === null
            ? new Collection
            : app(AiHubContent::class)->resourcesForTopic($topic);
    }
}; ?>
<x-log.reader
    active="ai-hub"
    data-test="ai-hub-path"
    :title="$this->path->title"
    :eyebrow="$this->path->difficulty->label()"
    :back="route('ai-hub.paths')"
    :back-label="__('All paths')"
    :meta="array_filter([
        $this->path->topic?->name,
        trans_choice(':count lesson|:count lessons', $this->path->lessonCount(), ['count' => $this->path->lessonCount()]),
        $this->path->estimatedMinutesLabel(),
        $this->path->user?->name ?? __('The hub team'),
    ])"
>
    <div class="dn-reader-body">
        @if (filled($this->path->description))
            <p class="dn-reader-lead">{{ $this->path->description }}</p>
        @endif

        @if (filled($this->path->prerequisites))
            <div class="dn-band" style="margin: 0 0 20px; border: 1px solid var(--dn-hairline); border-radius: var(--dn-radius-lg);">
                <span class="dn-band-body">
                    <span class="dn-entry-eyebrow">{{ __('Before you start') }}</span>
                    <span class="dn-band-text">{{ $this->path->prerequisites }}</span>
                </span>
            </div>
        @endif

        @if ($this->availableCount !== $this->lessons->count())
            <p class="dn-band" style="margin: 0 0 20px; border: 1px solid color-mix(in srgb, var(--dn-primary) 30%, transparent); border-radius: var(--dn-radius-lg);">
                <span class="dn-band-body">
                    <span class="dn-band-text">
                        {{ trans_choice(':count lesson in this path is temporarily unavailable.|:count lessons in this path are temporarily unavailable.', $this->lessons->count() - $this->availableCount) }}
                    </span>
                </span>
            </p>
        @endif
    </div>

    <x-log.section
        :title="__('Read them in this order')"
        :count="trans_choice(':count lesson|:count lessons', $this->lessons->count(), ['count' => $this->lessons->count()])"
    >
        @if ($this->lessons->isEmpty())
            <div class="dn-module-empty">
                <p class="dn-empty-command">fatal: this path has no lessons yet</p>
                <p class="dn-empty-text">
                    {{ __('An editor has published the route but has not placed any articles on it. The lesson list fills up as the sequence is assembled.') }}
                </p>
                <a href="{{ route('ai-hub.paths') }}" wire:navigate class="dn-btn dn-btn-outline dn-btn-sm" style="margin-top: 16px;">
                    {{ __('Browse other paths') }}
                </a>
            </div>
        @else
            <ol class="dn-lessons" role="list">
                @foreach ($this->lessons as $index => $item)
                    @php
                        $article = $item->article;
                        $readable = $article?->isPublished() === true;
                    @endphp

                    <li class="dn-lesson" wire:key="lesson-{{ $item->id }}">
                        <span class="dn-lesson-step" aria-hidden="true">{{ $index + 1 }}</span>

                        <span class="dn-entry-main">
                            <span class="dn-entry-head">
                                @if ($readable)
                                    <a href="{{ route('ai-hub.article', ['article' => $article->slug]) }}" wire:navigate class="dn-entry-title">
                                        {{ $item->displayTitle() }}
                                    </a>
                                @else
                                    <span class="dn-entry-title is-unavailable">{{ $item->displayTitle() }}</span>
                                @endif
                            </span>

                            @if (filled($item->note))
                                <span class="dn-entry-desc">{{ $item->note }}</span>
                            @elseif ($readable && filled($article->summary))
                                <span class="dn-entry-desc">{{ $article->summary }}</span>
                            @endif

                            <span class="dn-entry-meta">
                                @if ($readable)
                                    <span>{{ $article->readingTimeLabel() }}</span>
                                    <span>{{ $article->format->label() }}</span>
                                    <span>{{ $article->primaryTopic()?->name }}</span>
                                @else
                                    <span class="dn-status-chip">{{ __('Temporarily unavailable') }}</span>
                                @endif
                            </span>
                        </span>

                        @if ($readable)
                            <span class="dn-entry-arrow" aria-hidden="true">
                                <x-feed.icon name="reply" :size="14" />
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </x-log.section>

    @if ($this->relatedPaths->isNotEmpty())
        <x-log.section
            :title="__('Next routes')"
            :count="trans_choice(':count path|:count paths', $this->relatedPaths->count(), ['count' => $this->relatedPaths->count()])"
        >
            <div class="dn-entries">
                @foreach ($this->relatedPaths as $sibling)
                    <x-log.entry
                        :href="route('ai-hub.path', ['path' => $sibling->slug])"
                        :title="$sibling->title"
                        :eyebrow="$sibling->difficulty->label()"
                        :description="$sibling->description"
                        :meta="[
                            trans_choice(':count lesson|:count lessons', $sibling->lessonCount(), ['count' => $sibling->lessonCount()]),
                            $sibling->estimatedMinutesLabel(),
                        ]"
                        :wire:key="'sibling-path-'.$sibling->id"
                    />
                @endforeach
            </div>
        </x-log.section>
    @endif

    @if ($this->resources->isNotEmpty())
        <x-log.section
            :title="__('Resources')"
            :count="trans_choice(':count resource|:count resources', $this->resources->count(), ['count' => $this->resources->count()])"
        >
            <div class="dn-entries">
                @foreach ($this->resources as $resource)
                    <x-log.entry
                        :href="$resource->url"
                        :title="$resource->title"
                        :eyebrow="$resource->kind->label()"
                        :description="$resource->description"
                        :meta="[$resource->provider, $resource->host()]"
                        :wire:key="'path-resource-'.$resource->id"
                    />
                @endforeach
            </div>
        </x-log.section>
    @endif
</x-log.reader>
