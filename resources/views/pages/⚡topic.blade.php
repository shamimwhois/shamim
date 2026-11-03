<?php

use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Services\PublicTaxonomy;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One subject, both halves of the site.
 *
 * The point of a shared taxonomy is that a subject means the same thing to an idea
 * and to a snippet, so the page that opens from a subject chip shows the pair
 * together: what somebody is thinking about the subject and the working code that
 * came out of thinking about it.
 *
 * ## Why an unreadable topic is a 404 and not an empty page
 *
 * The check is {@see PublicTaxonomy::isBrowseable()}, not `$topic->is_active`. Two
 * different states land in the same refusal: a topic an operator switched off, and a
 * topic that exists because it was seeded but has never carried a published row.
 * Neither has anything to read, and a page that prints its name above two empty
 * shelves would both contradict the operator and confirm to anybody probing slugs
 * that the row exists. A 404 is the answer that is true in both cases.
 *
 * ## The counts and the lists are the same query
 *
 * The headline numbers come from the service that built the chips, and each section
 * reads the published set through the same `inTopic` scope the facet uses, so the
 * number in the heading, the number on the card that linked here, and the rows below
 * it are one answer. A topic page that disagreed with the chip that opened it would
 * be the clearest possible proof that the gate was written twice.
 *
 * ## Why the sections are cut rather than paged
 *
 * Each shelf holds the first page of rows and a link into the module's own list
 * carrying `?topic=`. That is not a shortcut: the landing already has search, sorting
 * and a paginator over exactly this subset, and repeating a worse version of it here
 * would give two places to keep correct. The count in the heading says how many exist,
 * so nothing is hidden behind the cut without saying so.
 */
new #[Title('Topic')] class extends Component
{
    /**
     * The topic being read. Locked so a later request cannot swap it.
     */
    #[Locked]
    public Topic $topic;

    public function mount(Topic $topic): void
    {
        abort_unless(app(PublicTaxonomy::class)->isBrowseable($topic), 404);

        $this->topic = $topic;

        app(Seo::class)->set([
            'title' => $topic->name.' — Topics',
            'description' => filled($topic->description)
                ? $topic->description
                : __('Published ideas and code snippets filed under :topic.', ['topic' => $topic->name]),
            'canonical' => route('topics.show', ['topic' => $topic->slug]),
            'type' => 'website',
            'tags' => [$topic->name],
            'jsonLd' => $this->structuredData(),
        ]);
    }

    /**
     * Ideas published under this subject, newest first, up to one page.
     *
     * @return Collection<int, Idea>
     */
    #[Computed]
    public function ideas(): Collection
    {
        return Idea::query()
            ->published()
            ->inTopic($this->topic->slug)
            ->with(['user', 'category'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit((int) config('ideas.per_page.public'))
            ->get();
    }

    /**
     * Snippets published under this subject, newest first, up to one page.
     *
     * @return Collection<int, LabSnippet>
     */
    #[Computed]
    public function snippets(): Collection
    {
        return LabSnippet::query()
            ->published()
            ->inTopic($this->topic->slug)
            ->with(['user', 'topics'])
            ->recentlyPublished()
            ->limit((int) config('dev_lab.per_page.public'))
            ->get();
    }

    /**
     * How many published ideas the subject holds, whatever this page displays.
     */
    #[Computed]
    public function ideaCount(): int
    {
        return app(PublicTaxonomy::class)->publishedCount(PublicTaxonomy::IDEAS, $this->topic->slug);
    }

    /**
     * How many published snippets the subject holds, whatever this page displays.
     */
    #[Computed]
    public function snippetCount(): int
    {
        return app(PublicTaxonomy::class)->publishedCount(PublicTaxonomy::SNIPPETS, $this->topic->slug);
    }

    /**
     * Determine whether the ideas shelf is longer than what is shown.
     */
    #[Computed]
    public function ideasCut(): bool
    {
        return $this->ideaCount > $this->ideas->count();
    }

    /**
     * Determine whether the snippets shelf is longer than what is shown.
     */
    #[Computed]
    public function snippetsCut(): bool
    {
        return $this->snippetCount > $this->snippets->count();
    }

    /**
     * A collection page for this shelf, plus the trail back through the index.
     *
     * `mainEntity` points at the two module lists rather than at the rows on this
     * page, because those lists are the complete answer for the subject and this
     * shelf is a cut of it. `@id` for the page itself is the canonical URL above.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function structuredData(): array
    {
        $url = route('topics.show', ['topic' => $this->topic->slug]);

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $this->topic->name,
                'url' => $url,
                'description' => filled($this->topic->description)
                    ? $this->topic->description
                    : __('Published ideas and code snippets filed under :topic.', ['topic' => $this->topic->name]),
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => config('app.name'),
                    'url' => route('home'),
                ],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'name' => $this->topic->name,
                    'numberOfItems' => $this->ideaCount + $this->snippetCount,
                    'itemListElement' => array_values(array_filter([
                        $this->ideaCount > 0
                            ? [
                                '@type' => 'ListItem',
                                'position' => 1,
                                'name' => __('Ideas filed under :topic', ['topic' => $this->topic->name]),
                                'url' => route('ideas.index', ['topic' => $this->topic->slug]),
                            ]
                            : null,
                        $this->snippetCount > 0
                            ? [
                                '@type' => 'ListItem',
                                'position' => $this->ideaCount > 0 ? 2 : 1,
                                'name' => __('Snippets filed under :topic', ['topic' => $this->topic->name]),
                                'url' => route('dev-lab.gallery', ['topic' => $this->topic->slug]),
                            ]
                            : null,
                    ])),
                ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Topics', 'item' => route('topics.index')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $this->topic->name, 'item' => $url],
                ],
            ],
        ];
    }
}; ?>

<div data-test="topic-show">
    {{-- ================================================================== --
     | 01 — Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <nav aria-label="{{ __('Breadcrumb') }}">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('home') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Home') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li>
                        <a href="{{ route('topics.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Topics') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="text-ink-600 dark:text-ink-300">{{ $this->topic->name }}</li>
                </ol>
            </nav>

            <div class="mt-8 flex items-start gap-4">
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
                        {{ trans_choice(':count idea|:count ideas', $this->ideaCount) }}
                        <span aria-hidden="true">·</span>
                        {{ trans_choice(':count snippet|:count snippets', $this->snippetCount) }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Ideas
     --}}
    <section data-reveal class="shell py-14 lg:py-16" aria-labelledby="topic-ideas-heading">
        <x-app.ui.section-marker :number="1" label="Ideas" />

        <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
            <h2 id="topic-ideas-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ __('What people are working out') }}
            </h2>

            <a
                href="{{ route('ideas.index', ['topic' => $this->topic->slug]) }}"
                wire:navigate
                class="focus-ring shrink-0 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
            >
                {{ __('All ideas filed here') }}
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        @if ($this->ideas->isNotEmpty())
            <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->ideas as $idea)
                    <x-app.ideas.card
                        :idea="$idea"
                        :href="route('ideas.show', ['idea' => $idea->slug])"
                        wire:key="topic-idea-{{ $idea->id }}"
                    />
                @endforeach
            </div>

            @if ($this->ideasCut)
                <p class="mt-6 text-sm text-ink-500 dark:text-ink-400">
                    {{ __('Showing the first :shown of :total — the ideas index paginates the rest, sorted and searched.', [
                        'shown' => $this->ideas->count(),
                        'total' => $this->ideaCount,
                    ]) }}
                </p>
            @endif
        @else
            <p class="mt-6 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                {{ __(':topic has published code under it but no published idea yet. Ideas only appear here once their author approves them and chooses to publish.', ['topic' => $this->topic->name]) }}
            </p>
        @endif
    </section>

    {{-- ================================================================== --
     | 03 — Snippets
     --}}
    <section data-reveal class="border-t border-ink-200 py-14 dark:border-ink-800 lg:py-16" aria-labelledby="topic-snippets-heading">
        <div class="shell">
            <x-app.ui.section-marker :number="2" label="Code" />

            <div class="mt-8 flex flex-wrap items-end justify-between gap-6">
                <h2 id="topic-snippets-heading" class="text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    {{ __('What came out of it') }}
                </h2>

                <a
                    href="{{ route('dev-lab.gallery', ['topic' => $this->topic->slug]) }}"
                    wire:navigate
                    class="focus-ring shrink-0 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-300 dark:hover:text-signal-400"
                >
                    {{ __('All snippets filed here') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>

            @if ($this->snippets->isNotEmpty())
                <div class="mt-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->snippets as $snippet)
                        <x-app.dev-lab.snippet-card
                            :snippet="$snippet"
                            :href="route('dev-lab.snippets.show', ['snippet' => $snippet->slug])"
                            wire:key="topic-snippet-{{ $snippet->id }}"
                        />
                    @endforeach
                </div>

                @if ($this->snippetsCut)
                    <p class="mt-6 text-sm text-ink-500 dark:text-ink-400">
                        {{ __('Showing the first :shown of :total — the gallery paginates the rest, filtered by language and search.', [
                            'shown' => $this->snippets->count(),
                            'total' => $this->snippetCount,
                        ]) }}
                    </p>
                @endif
            @else
                <p class="mt-6 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                    {{ __(':topic has published ideas under it but no published snippet yet. Code only appears here once its author chooses to publish it.', ['topic' => $this->topic->name]) }}
                </p>
            @endif
        </div>
    </section>
</div>
