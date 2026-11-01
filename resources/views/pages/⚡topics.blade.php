<?php

use App\Models\Topic;
use App\Services\PublicTaxonomy;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Every subject a stranger can browse, with what is behind each one.
 *
 * This is the taxonomy's own shelf: one place that answers "what is this site about"
 * without asking the reader to pick a module first. Ideas and the Developer Lab both
 * offer the same chips inside their own pages; this page is where the two lists are
 * the same list, because a topic means the same thing to an idea and to a snippet.
 *
 * What appears here is decided by {@see PublicTaxonomy::indexed()}, which is the
 * count gate written once so this page, the ideas landing and the gallery cannot
 * disagree. Two consequences are visible in the markup below and neither is this
 * page's invention:
 *
 * - A topic with nothing published under it is absent, not shown at zero. An empty
 *   shelf is a dead end, and the seeded taxonomy is longer than what has been
 *   published, so "listed but unreadable" would be the common case rather than the
 *   exception.
 * - The numbers on a card are the same numbers the topic page will render, because
 *   both are counted through the `published()` scope on the pivot relation. A card
 *   cannot promise reading it will not open.
 *
 * Nothing here is hierarchical, which is deliberate: {@see AiTopic} is the hub's
 * tree with parents and subtopics, and this flat list is the platform's shared
 * subject vocabulary. Two shapes of "topic" in one site is confusing, so the two
 * live at different URLs and say so in their own copy.
 */
new #[Title('Browse by subject')] class extends Component
{
    public function mount(): void
    {
        $topics = $this->topics;

        app(Seo::class)->set([
            'title' => 'Browse by subject',
            'description' => __('Every subject this site publishes under, with the number of ideas and code snippets each one holds.'),
            'canonical' => route('topics.index'),
            'type' => 'website',
            'tags' => $topics->pluck('name')->all(),
            'jsonLd' => $this->structuredData($topics),
        ]);
    }

    /**
     * The topics the public may browse, largest shelf first.
     *
     * @return Collection<int, Topic>
     */
    #[Computed]
    public function topics(): Collection
    {
        return app(PublicTaxonomy::class)->indexed();
    }

    /**
     * How much published work the whole index advertises.
     *
     * Summed across the cards a reader is about to see, so the headline number and
     * the grid cannot disagree — at the cost of counting a row filed under two
     * subjects twice. That is why the label says "filed", not "unique ideas": the
     * number is a count of filings, and the copy below says so rather than rounding
     * the difference away.
     */
    #[Computed]
    public function filingCount(): int
    {
        return $this->topics->sum(fn (Topic $topic): int => (int) $topic->ideas_count + (int) $topic->lab_snippets_count);
    }

    /**
     * A collection page plus the trail back to the front door.
     *
     * This is the only unfiltered list of topics in the site, which makes it the one
     * place a `CollectionPage` is honest: the two landings carry the reader's filters
     * and pagination in their canonical URL, so describing their contents would
     * describe a list a crawler cannot reach.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function structuredData(Collection $topics): array
    {
        $url = route('topics.index');

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => 'Browse by subject',
                'url' => $url,
                'description' => __('Every subject this site publishes under, with the number of ideas and code snippets each one holds.'),
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => config('app.name'),
                    'url' => route('home'),
                ],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'numberOfItems' => $topics->count(),
                    'itemListElement' => $topics->values()->map(fn (Topic $topic, int $index): array => [
                        '@type' => 'ListItem',
                        'position' => $index + 1,
                        'url' => route('topics.show', ['topic' => $topic->slug]),
                        'name' => $topic->name,
                    ])->all(),
                ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Topics', 'item' => $url],
                ],
            ],
        ];
    }
}; ?>

<div data-test="topics-index">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <x-app.ui.section-marker :number="1" label="Subjects" accent />

            <nav aria-label="{{ __('Breadcrumb') }}" class="mt-8">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('home') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Home') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ __('Topics') }}</li>
                </ol>
            </nav>

            <h1 class="mt-6 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('What this site is about') }}
            </h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('One subject list serves both halves of the site: the ideas people are working out and the code that came out of working them out. Pick a subject and you get both.') }}
            </p>

            <dl class="mt-8 flex flex-wrap items-center gap-x-8 gap-y-3">
                @foreach ([
                    ['label' => __('Subjects to browse'), 'value' => $this->topics->count()],
                    ['label' => __('Rows filed'), 'value' => $this->filingCount],
                ] as $stat)
                    <div>
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">{{ $stat['label'] }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ number_format($stat['value']) }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 max-w-2xl text-xs leading-relaxed text-ink-400 dark:text-ink-500">
                {{ __('A row filed under two subjects is counted twice here, which is what filing means. The idea list and the gallery each count their rows once.') }}
            </p>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Grid
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="topics-grid-heading">
        <x-app.ui.section-marker :number="2" label="The shelves" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <h2 id="topics-grid-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Every subject with something published under it') }}
            </h2>

            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500" aria-live="polite">
                {{ trans_choice(':count subject|:count subjects', $this->topics->count()) }}
            </p>
        </div>

        @if ($this->topics->isNotEmpty())
            <ul class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3" role="list">
                @foreach ($this->topics as $topic)
                    <li>
                        <a
                            href="{{ route('topics.show', ['topic' => $topic->slug]) }}"
                            wire:navigate
                            data-test="topic-card"
                            class="focus-ring group flex h-full flex-col rounded-xl border border-ink-200 bg-white p-5 transition duration-200 ease-soft hover:border-signal-400 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600"
                        >
                            <span class="flex items-center gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-lg border border-ink-200 bg-ink-50 text-ink-500 transition-colors group-hover:text-signal-600 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400 dark:group-hover:text-signal-400">
                                    <flux:icon :name="$topic->icon ?? 'tag'" class="size-5" />
                                </span>

                                <span class="text-base font-semibold leading-snug tracking-tight text-ink-900 dark:text-ink-50">
                                    {{ $topic->name }}
                                </span>
                            </span>

                            @if (filled($topic->description))
                                <span class="mt-3 line-clamp-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ $topic->description }}
                                </span>
                            @endif

                            <span class="mt-auto flex flex-wrap items-center gap-x-5 gap-y-2 pt-5 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                <span class="tabular-nums">
                                    {{ trans_choice(':count idea|:count ideas', (int) $topic->ideas_count) }}
                                </span>
                                <span class="tabular-nums">
                                    {{ trans_choice(':count snippet|:count snippets', (int) $topic->lab_snippets_count) }}
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <x-app.ui.empty-state
                class="mt-8"
                icon="tag"
                :title="__('No subject has anything published under it yet')"
                :description="__('Subjects exist in the taxonomy, but a shelf only appears here once somebody has published a row carrying it. Nothing has been published yet — the ideas index and the gallery say the same thing.')"
            >
                <flux:button :href="route('ideas.index')" wire:navigate icon="light-bulb">
                    {{ __('See the ideas index') }}
                </flux:button>
            </x-app.ui.empty-state>
        @endif
    </section>

    {{-- ================================================================== --
     | 03 — Where the same subjects live inside each module
     --}}
    <section data-reveal class="border-t border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="topics-modules-heading">
        <div class="shell">
            <x-app.ui.section-marker :number="3" label="Inside each module" />

            <h2 id="topics-modules-heading" class="mt-8 max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('Or take a subject into one half of the site') }}
            </h2>

            <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-600 dark:text-ink-300">
                {{ __('Both halves of the site filter by this same list, so a subject you pick here is the subject they already have. The difference is only how much of the site you want to see at once.') }}
            </p>

            <div class="mt-8 grid gap-5 sm:grid-cols-2">
                @foreach ([
                    [
                        'title' => __('Ideas'),
                        'body' => __('Notes in progress, published when their author decided to publish them.'),
                        'label' => __('All ideas'),
                        'href' => route('ideas.index'),
                        'icon' => 'light-bulb',
                    ],
                    [
                        'title' => __('Developer Lab'),
                        'body' => __('Working HTML, CSS and JavaScript, run in a frame with no origin of its own.'),
                        'label' => __('All snippets'),
                        'href' => route('dev-lab.gallery'),
                        'icon' => 'window',
                    ],
                ] as $module)
                    <a
                        href="{{ $module['href'] }}"
                        wire:navigate
                        class="focus-ring group flex items-start gap-4 rounded-xl border border-ink-200 bg-white p-5 transition duration-200 ease-soft hover:border-signal-400 dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-600"
                    >
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg border border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400">
                            <flux:icon :name="$module['icon']" class="size-5" />
                        </span>

                        <span class="min-w-0">
                            <span class="block text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ $module['title'] }}
                            </span>
                            <span class="mt-1.5 block text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                {{ $module['body'] }}
                            </span>
                            <span class="mt-3 inline-flex items-center gap-1.5 font-mono text-2xs uppercase tracking-widest text-signal-700 dark:text-signal-400">
                                {{ $module['label'] }}
                                <span aria-hidden="true">&rarr;</span>
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
</div>
