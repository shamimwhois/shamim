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
new #[Title('Topic — AI Knowledge Hub')] class extends Component
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

@php
    $selectClasses = 'focus-ring min-h-10 appearance-none rounded-lg border border-ink-200 bg-white py-2 pl-3 pr-9 text-sm text-ink-700 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200 dark:hover:border-ink-700';
@endphp

<div data-test="ai-hub-topic">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
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
                        <a href="{{ route('ai-hub.topics') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Topics') }}
                        </a>
                    </li>

                    @if ($this->topic->parent)
                        <li aria-hidden="true">/</li>
                        <li>
                            <a href="{{ route('ai-hub.topic', ['topic' => $this->topic->parent->slug]) }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                                {{ $this->topic->parent->name }}
                            </a>
                        </li>
                    @endif

                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ $this->topic->name }}</li>
                </ol>
            </nav>

            <div class="flex items-start gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-500 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-400">
                    <flux:icon :name="$this->topic->icon ?? 'tag'" class="size-6" />
                </span>

                <div class="min-w-0">
                    <h1 class="text-3xl font-semibold leading-[1.1] tracking-tightest text-ink-900 dark:text-ink-50 sm:text-4xl">
                        {{ $this->topic->name }}
                    </h1>

                    @if (filled($this->topic->description))
                        <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                            {{ $this->topic->description }}
                        </p>
                    @endif

                    <p class="mt-5 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ trans_choice(':count published article|:count published articles', $this->articleCount) }}
                    </p>
                </div>
            </div>

            @if ($this->children->isNotEmpty())
                <div class="mt-10">
                    <h2 class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                        {{ __('Subtopics') }}
                    </h2>

                    <ul class="mt-4 flex flex-wrap gap-2" role="list">
                        @foreach ($this->children as $child)
                            <li>
                                <a
                                    href="{{ route('ai-hub.topic', ['topic' => $child->slug]) }}"
                                    wire:navigate
                                    class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-white px-4 py-2 text-sm text-ink-600 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-600 dark:hover:text-signal-400"
                                >
                                    <flux:icon :name="$child->icon ?? 'tag'" class="size-4" />
                                    <span>{{ $child->name }}</span>
                                    @if ($child->articles_count > 0)
                                        <span class="font-mono text-2xs tabular-nums opacity-70">{{ $child->articles_count }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Learning paths
     --}}
    @if ($this->paths->isNotEmpty())
        <section data-reveal class="border-b border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-topic-paths-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="1" label="Learning paths" />

                <h2 id="hub-topic-paths-heading" class="mt-8 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ __('Start with a sequence') }}
                </h2>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->paths as $path)
                        <x-app.ai-hub.path-card :path="$path" wire:key="topic-path-{{ $path->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 03 — Articles
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="hub-topic-articles-heading">
        <x-app.ui.section-marker :number="2" label="Reading" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <h2 id="hub-topic-articles-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Everything filed here') }}
            </h2>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count article|:count articles', $this->articles->total()) }}
            </p>
        </div>

        <div class="mt-8 flex flex-wrap items-end gap-3 border-y border-ink-200 py-4 dark:border-ink-800">
            <div>
                <label for="hub-topic-difficulty" class="mb-1.5 block font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    {{ __('Difficulty') }}
                </label>
                <div class="relative">
                    <select id="hub-topic-difficulty" wire:model.live="difficulty" class="{{ $selectClasses }}">
                        <option value="">{{ __('Any level') }}</option>
                        @foreach (AiDifficulty::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <flux:icon name="chevron-down" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
                </div>
            </div>

            @if ($this->difficulty !== '')
                <button
                    type="button"
                    wire:click="setDifficulty('{{ $this->difficulty }}')"
                    class="focus-ring inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-xs font-medium text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                >
                    {{ __('Clear level') }}
                    <flux:icon name="x-mark" class="size-3.5" />
                </button>
            @endif

            <a
                href="{{ route('ai-hub.articles', ['topic' => $this->topic->slug]) }}"
                wire:navigate
                class="focus-ring ml-auto inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
            >
                {{ __('Filter in the full index') }}
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        @if ($this->articles->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->articles as $article)
                    <x-app.ai-hub.article-card :article="$article" wire:key="topic-article-{{ $article->id }}" />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->articles" label="{{ __('Topic articles pagination') }}" class="mt-10" />
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="newspaper"
                :title="$this->difficulty === '' ? __('Nothing published under this topic yet') : __('No articles at this level yet')"
                :description="$this->difficulty === '' ? __('The topic exists and is switched on; its articles are still drafts or have been archived.') : null"
            >
                @if ($this->difficulty !== '')
                    <flux:button wire:click="setDifficulty('{{ $this->difficulty }}')" icon="arrow-path">
                        {{ __('Show every level') }}
                    </flux:button>
                @endif
            </x-app.ui.empty-state>
        @endif
    </section>

    {{-- ================================================================== --
     | 04 — Resources
     --}}
    @if ($this->resources->isNotEmpty())
        <section data-reveal class="border-t border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-topic-resources-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="3" label="Resources" />

                <h2 id="hub-topic-resources-heading" class="mt-8 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ __('Worth keeping open') }}
                </h2>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($this->resources as $resource)
                        <x-app.ai-hub.resource-card :resource="$resource" wire:key="topic-resource-{{ $resource->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
