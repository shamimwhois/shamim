<?php

use App\Models\AiTopic;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The hub's topic index.
 *
 * Topics are the hub's navigation, and they are operator-managed: an editor can
 * add one, rename it, reorder it, or switch it off. This page therefore renders
 * whatever is currently active, in the current order, rather than a fixed list
 * compiled into the view. Deactivating a topic removes it from here and from
 * every shelf in the hub, which is why the grid is read through
 * `AiHubContent::paginateTopics()` instead of queried directly.
 *
 * A topic's count is the count of *published* articles filed under it. That rule
 * is the service's, not this component's, so a card can never promise more
 * reading than the topic page it links to can deliver.
 */
new #[Title('Topics — AI Knowledge Hub')] class extends Component
{
    use WithPagination;

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Topics — AI Knowledge Hub',
            'description' => __('The subject map of the AI Knowledge Hub: every active topic, its subtopics, and how much published reading each one holds.'),
            'canonical' => route('ai-hub.topics'),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, AiTopic>
     */
    #[Computed]
    public function topics(): LengthAwarePaginator
    {
        return app(AiHubContent::class)->paginateTopics((int) config('ai_hub.per_page.topics'));
    }

    /**
     * Total number of active topics, roots and subtopics together.
     */
    #[Computed]
    public function subjectCount(): int
    {
        return app(AiHubContent::class)->topicChips()->count();
    }
}; ?>

<div data-test="ai-hub-topics">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <x-app.ui.section-marker :number="1" label="Topics" accent />

            <nav aria-label="{{ __('Breadcrumb') }}" class="mt-8">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('ai-hub.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('AI Knowledge Hub') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Topics') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('The subject map') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('Topics hold the articles; learning paths sequence them. A topic that is switched off disappears from the whole hub, not just from this page.') }}
            </p>

            <dl class="mt-8 flex flex-wrap items-center gap-x-8 gap-y-3">
                @foreach ([
                    ['label' => __('Root topics'), 'value' => $this->topics->total()],
                    ['label' => __('Topics and subtopics'), 'value' => $this->subjectCount],
                ] as $stat)
                    <div>
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ $stat['label'] }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ number_format($stat['value']) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Grid
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="hub-topics-grid-heading">
        <x-app.ui.section-marker :number="2" label="The grid" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <h2 id="hub-topics-grid-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Every active topic') }}
            </h2>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count topic|:count topics', $this->topics->total()) }}
            </p>
        </div>

        @if ($this->topics->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->topics as $topic)
                    <x-app.ai-hub.topic-card :topic="$topic" wire:key="topic-{{ $topic->id }}" />
                @endforeach
            </div>

            <x-app.ui.pagination :paginator="$this->topics" label="{{ __('Topics pagination') }}" class="mt-10" />
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="tag"
                :title="__('No topics are active yet')"
                :description="__('Topics are created by an editor in the hub console. Until one exists and is switched on, the shelves above stay empty.')"
            >
                <flux:button :href="route('ai-hub.index')" wire:navigate icon="sparkles">
                    {{ __('Back to the hub') }}
                </flux:button>
            </x-app.ui.empty-state>
        @endif
    </section>
</div>
