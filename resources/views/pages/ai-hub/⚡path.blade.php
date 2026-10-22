<?php

use App\Models\AiLearningPath;
use App\Models\AiLearningPathItem;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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
new #[Title('Learning Path — AI Knowledge Hub')] class extends Component
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

<div data-test="ai-hub-path">
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
                        <a href="{{ route('ai-hub.paths') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Learning paths') }}
                        </a>
                    </li>

                    @if ($this->path->topic)
                        <li aria-hidden="true">/</li>
                        <li>
                            <a
                                href="{{ route('ai-hub.topic', ['topic' => $this->path->topic->slug]) }}"
                                wire:navigate
                                class="transition-colors hover:text-signal-700 dark:hover:text-signal-400"
                            >
                                {{ $this->path->topic->name }}
                            </a>
                        </li>
                    @endif

                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ $this->path->title }}</li>
                </ol>
            </nav>

            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <x-app.ai-hub.pill icon="map" tone="signal">{{ __('Learning path') }}</x-app.ai-hub.pill>
                    <x-app.ai-hub.pill>{{ $this->path->difficulty->label() }}</x-app.ai-hub.pill>
                </div>

                <h1 class="mt-6 text-3xl font-semibold leading-[1.15] tracking-tightest text-ink-900 dark:text-ink-50 sm:text-4xl lg:text-5xl">
                    {{ $this->path->title }}
                </h1>

                @if (filled($this->path->description))
                    <p class="mt-6 text-lg leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ $this->path->description }}
                    </p>
                @endif

                <dl class="mt-8 grid gap-5 sm:grid-cols-3">
                    <div>
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Lessons') }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ $this->path->lessonCount() }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Total read') }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ $this->path->estimatedMinutesLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ __('Assembled by') }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-ink-900 dark:text-ink-50">{{ $this->path->user?->name ?? __('The hub team') }}</dd>
                    </div>
                </dl>

                @if (filled($this->path->prerequisites))
                    <div class="mt-8 rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                        <h2 class="flex items-center gap-2 font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                            <flux:icon name="information-circle" class="size-4" />
                            {{ __('Before you start') }}
                        </h2>

                        <p class="mt-3 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                            {{ $this->path->prerequisites }}
                        </p>
                    </div>
                @endif

                @if ($this->availableCount !== $this->lessons->count())
                    <p class="mt-6 flex items-start gap-2 rounded-lg border border-signal-300 bg-signal-50 p-4 text-sm text-signal-900 dark:border-signal-800 dark:bg-signal-950/40 dark:text-signal-200">
                        <flux:icon name="exclamation-triangle" class="mt-0.5 size-4 shrink-0" />
                        <span>
                            {{ trans_choice(':count lesson in this path is temporarily unavailable.|:count lessons in this path are temporarily unavailable.', $this->lessons->count() - $this->availableCount) }}
                        </span>
                    </p>
                @endif
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — The sequence
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="hub-path-lessons-heading">
        <x-app.ui.section-marker :number="1" label="The route" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <h2 id="hub-path-lessons-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Read them in this order') }}
            </h2>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count lesson|:count lessons', $this->lessons->count()) }}
            </p>
        </div>

        @if ($this->lessons->isEmpty())
            <x-app.ui.empty-state
                class="mt-8"
                icon="map"
                :title="__('This path has no lessons yet')"
                :description="__('An editor has published the route but has not placed any articles on it. The lesson list fills up as the sequence is assembled.')"
            >
                <flux:button :href="route('ai-hub.paths')" wire:navigate icon="arrow-left">
                    {{ __('Browse other paths') }}
                </flux:button>
            </x-app.ui.empty-state>
        @else
            <ol class="mt-8 space-y-3" role="list">
                @foreach ($this->lessons as $index => $item)
                    @php
                        $article = $item->article;
                        $readable = $article?->isPublished() === true;
                    @endphp

                    <li wire:key="lesson-{{ $item->id }}" class="group relative flex gap-4 rounded-xl border border-ink-200 bg-white p-5 transition duration-200 ease-soft dark:border-ink-800 dark:bg-ink-950">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full border border-ink-200 bg-ink-50 font-mono text-xs font-semibold tabular-nums text-ink-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-300">
                            {{ $index + 1 }}
                        </span>

                        <div class="min-w-0 flex-1">
                            @if ($readable)
                                <h3 class="text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
                                    <a
                                        href="{{ route('ai-hub.article', ['article' => $article->slug]) }}"
                                        wire:navigate
                                        class="focus-ring rounded transition-colors group-hover:text-signal-700 dark:group-hover:text-signal-400"
                                    >
                                        {{ $item->displayTitle() }}
                                    </a>
                                </h3>
                            @else
                                <h3 class="text-base font-semibold leading-snug tracking-tight text-ink-400 dark:text-ink-500">
                                    {{ $item->displayTitle() }}
                                </h3>
                            @endif

                            @if (filled($item->note))
                                <p class="mt-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ $item->note }}
                                </p>
                            @elseif ($readable && filled($article->summary))
                                <p class="mt-2 line-clamp-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ $article->summary }}
                                </p>
                            @endif

                            <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                @if ($readable)
                                    <span>{{ $article->readingTimeLabel() }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $article->format->label() }}</span>

                                    @if ($article->primaryTopic())
                                        <span aria-hidden="true">·</span>
                                        <a
                                            href="{{ route('ai-hub.topic', ['topic' => $article->primaryTopic()->slug]) }}"
                                            wire:navigate
                                            class="transition-colors hover:text-signal-700 dark:hover:text-signal-400"
                                        >
                                            {{ $article->primaryTopic()->name }}
                                        </a>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-signal-700 dark:text-signal-400">
                                        <flux:icon name="exclamation-triangle" class="size-3.5" />
                                        {{ __('Temporarily unavailable') }}
                                    </span>
                                @endif

                                @if ($readable)
                                    <span class="ml-auto inline-flex shrink-0 items-center gap-1 text-xs font-medium text-ink-500 transition-colors group-hover:text-signal-700 dark:text-ink-400 dark:group-hover:text-signal-400">
                                        {{ $index === 0 ? __('Start here') : __('Open lesson') }}
                                        <span aria-hidden="true">&rarr;</span>
                                    </span>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    {{-- ================================================================== --
     | 03 — Related paths
     --}}
    @if ($this->relatedPaths->isNotEmpty())
        <section data-reveal class="border-t border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-path-related-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="2" label="Next routes" />

                <h2 id="hub-path-related-heading" class="mt-8 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ __('Other paths in :topic', ['topic' => $this->path->topic?->name]) }}
                </h2>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->relatedPaths as $sibling)
                        <x-app.ai-hub.path-card :path="$sibling" wire:key="sibling-path-{{ $sibling->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 04 — Resources
     --}}
    @if ($this->resources->isNotEmpty())
        <section data-reveal class="border-t border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="hub-path-resources-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="3" label="Resources" />

                <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
                    <h2 id="hub-path-resources-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                        {{ __('Worth keeping open') }}
                    </h2>

                    <a
                        href="{{ route('ai-hub.resources') }}"
                        wire:navigate
                        class="focus-ring shrink-0 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                    >
                        {{ __('All resources') }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __('These link outside the hub. Third-party material changes without the hub being told, so the destination is shown with each one.') }}
                </p>

                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($this->resources as $resource)
                        <x-app.ai-hub.resource-card :resource="$resource" wire:key="path-resource-{{ $resource->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
