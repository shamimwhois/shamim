<?php

use App\Enums\AiDifficulty;
use App\Models\AiLearningPath;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A single topic's shelf.
 *
 * The topic page is the hub's one true reading room: everything filed under the
 * topic that is actually published, the subtopics underneath it, the paths that
 * start from it, and the external resources an editor attached to it.
 *
 * An inactive topic is a 404 rather than an empty page. Switching a topic off is
 * an operator's statement that it should not be visited, and a page that renders
 * its name with nothing in it would contradict that while still confirming the
 * slug exists.
 *
 * The article list is built from `AiHubContent::topicArticles()`, the same query
 * the topic card's count is derived from, so the number on the card and the
 * number in the pager cannot drift apart.
 */
new #[Title('Topic — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
{
    use WithPagination;

    /**
     * The topic being viewed. Locked so a subsequent request cannot swap it.
     */
    #[Locked]
    public AiTopic $topic;

    #[Url(as: 'difficulty', except: '')]
    public string $difficulty = '';

    public function mount(AiTopic $topic): void
    {
        abort_unless($topic->is_active, 404);

        $this->topic = $topic->load(['children' => fn (Relation $query) => $query->active()->ordered(), 'parent']);

        app(Seo::class)->set([
            'title' => $topic->name,
            'description' => $topic->description ?? __('Published articles, learning paths and resources filed under :topic in the AI Knowledge Hub.', ['topic' => $topic->name]),
            'canonical' => route('ai-hub.topic', ['topic' => $topic->slug]),
        ]);
    }

    /**
     * Reset to page 1 when the difficulty filter changes.
     */
    public function updating(string $property): void
    {
        if ($property === 'difficulty') {
            $this->resetPage();
        }
    }

    /**
     * Apply or clear a difficulty filter.
     */
    public function setDifficulty(string $value): void
    {
        $this->difficulty = $this->difficulty === $value ? '' : $value;
        $this->resetPage();
    }

    /**
     * The paged articles filed under this topic.
     *
     * @return LengthAwarePaginator<int, \App\Models\AiArticle>
     */
    #[Computed]
    public function articles(): LengthAwarePaginator
    {
        return app(AiHubContent::class)
            ->topicArticles($this->topic)
            ->when(AiDifficulty::tryFrom($this->difficulty) !== null, fn (Builder $query) => $query->atDifficulty(AiDifficulty::from($this->difficulty)))
            ->with(['topics', 'user'])
            ->recent()
            ->paginate((int) config('ai_hub.per_page.articles'))
            ->withQueryString();
    }

    /**
     * Published reading this topic holds, before any filter is applied.
     */
    #[Computed]
    public function articleCount(): int
    {
        return $this->topic->articles_count ?? app(AiHubContent::class)->topicArticles($this->topic)->count();
    }

    /**
     * Active subtopics underneath this one.
     *
     * @return Collection<int, AiTopic>
     */
    #[Computed]
    public function children(): Collection
    {
        return $this->topic->children;
    }

    /**
     * Published learning paths anchored to this topic.
     *
     * @return Collection<int, AiLearningPath>
     */
    #[Computed]
    public function paths(): Collection
    {
        return app(AiHubContent::class)->pathsForTopic($this->topic);
    }

    /**
     * Published resources filed against this topic.
     *
     * @return Collection<int, AiResource>
     */
    #[Computed]
    public function resources(): Collection
    {
        return app(AiHubContent::class)->resourcesForTopic($this->topic);
    }
}; ?>
<x-log.reader
    active="ai-hub"
    data-test="ai-hub-topic"
    :title="$this->topic->name"
    :eyebrow="$this->topic->parent?->name ?? __('Topic')"
    :back="route('ai-hub.topics')"
    :back-label="__('All topics')"
    :meta="array_filter([
        trans_choice(':count article|:count articles', $this->articleCount, ['count' => $this->articleCount]),
        $this->children->isNotEmpty() ? trans_choice(':count subtopic|:count subtopics', $this->children->count(), ['count' => $this->children->count()]) : null,
    ])"
>
    <div class="dn-reader-body">
        @if (filled($this->topic->description))
            <p class="dn-reader-lead">{{ $this->topic->description }}</p>
        @endif

        @if ($this->children->isNotEmpty())
            <div style="margin-top: 20px;">
                <p class="dn-entry-eyebrow">{{ __('Subtopics') }}</p>

                <ul class="dn-reader-topics" style="margin-top: 8px; padding-top: 0; border-top: 0;" role="list">
                    @foreach ($this->children as $child)
                        <li>
                            <a href="{{ route('ai-hub.topic', ['topic' => $child->slug]) }}" wire:navigate class="dn-status-chip">
                                {{ $child->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    @if ($this->paths->isNotEmpty())
        <x-log.section
            :title="__('Learning paths')"
            :count="trans_choice(':count path|:count paths', $this->paths->count(), ['count' => $this->paths->count()])"
        >
            <div class="dn-entries">
                @foreach ($this->paths as $path)
                    <x-log.entry
                        :href="route('ai-hub.path', ['path' => $path->slug])"
                        :title="$path->title"
                        :eyebrow="$path->difficulty->label()"
                        :description="$path->description"
                        :meta="[
                            trans_choice(':count lesson|:count lessons', $path->lessonCount(), ['count' => $path->lessonCount()]),
                            $path->estimatedMinutesLabel(),
                        ]"
                        :wire:key="'topic-path-'.$path->id"
                    />
                @endforeach
            </div>
        </x-log.section>
    @endif

    <x-log.section
        :title="__('Articles')"
        :count="trans_choice(':count article|:count articles', $this->articleCount, ['count' => $this->articleCount])"
    >
        <x-slot:filters>
            <div class="dn-module-tools">
                <span class="dn-field-key">{{ __('difficulty') }}</span>

                @foreach (AiDifficulty::options() as $value => $label)
                    <button
                        type="button"
                        class="dn-filter {{ $difficulty === $value ? 'is-active' : '' }}"
                        wire:click="setDifficulty('{{ $value }}')"
                        @if ($difficulty === $value) aria-current="true" @endif
                        style="padding: 2px 6px;"
                    >{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:filters>

        @if ($this->articles->isEmpty())
            <div class="dn-module-empty">
                <p class="dn-empty-command">fatal: no articles here yet</p>
                <p class="dn-empty-text">
                    {{ __('Nothing has been filed under this topic at this difficulty.') }}
                </p>
            </div>
        @else
            <div class="dn-entries">
                @foreach ($this->articles as $article)
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
                        :wire:key="'topic-article-'.$article->id"
                    />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->articles" label="{{ __('AI hub topic article pagination') }}" class="dn-pagination" />
        @endif
    </x-log.section>

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
                        :wire:key="'topic-resource-'.$resource->id"
                    />
                @endforeach
            </div>
        </x-log.section>
    @endif
</x-log.reader>
