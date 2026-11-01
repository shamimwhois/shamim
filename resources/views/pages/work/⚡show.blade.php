<?php

use App\Data\Project;
use App\Services\ProjectCatalog;
use App\Support\Seo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One selected-work project and its documentation.
 *
 * The page the Details button on the about page opens. `/work/{project}` is the
 * dossier — what the project is, what it changed and where to read next — and
 * `/work/{project}/{doc}` is one full documentation page beside a persistent
 * docs index. Both are public and server-rendered: the docs are content a reader
 * should be able to link to, not a modal state on the about page.
 *
 * The project list is the about page's own list, resolved through
 * {@see ProjectCatalog}, so a project cannot show one role on the card and
 * another on its page. Documentation comes from configuration for the
 * hand-written projects and from this repository's `docs/*.md` for the site's
 * own project.
 *
 * Only primitives are public properties. The catalogue and the rendered document
 * are resolved per request, which keeps a large docs page out of the Livewire
 * snapshot and makes the page safe to reload or share.
 */
new #[Title('Selected work')] class extends Component
{
    public string $projectSlug = '';

    public ?string $docSlug = null;

    protected ?ProjectCatalog $catalog = null;

    public function mount(string $project, ?string $doc = null): void
    {
        $catalog = $this->catalog();
        $found = $catalog->find($project);

        abort_if($found === null, 404);

        $this->projectSlug = $found->slug;

        if ($doc !== null) {
            $known = array_column($catalog->docs($found), 'slug');

            abort_unless(in_array($doc, $known, true), 404);

            $this->docSlug = $doc;
        }

        $this->writeMetadata($found, $catalog);
    }

    /**
     * The project this page is about.
     */
    #[Computed]
    public function project(): Project
    {
        $project = $this->catalog()->find($this->projectSlug);

        abort_if($project === null, 404);

        return $project;
    }

    /**
     * Every documentation page, in reading order.
     *
     * @return array<int, array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string}>
     */
    #[Computed]
    public function docs(): array
    {
        return $this->catalog()->docs($this->project);
    }

    /**
     * The document being read, or null on the project overview.
     *
     * @return array{slug: string, title: string, summary: string, reading_time: int, markdown: string, source: string, html: string}|null
     */
    #[Computed]
    public function doc(): ?array
    {
        if ($this->docSlug === null) {
            return null;
        }

        $doc = $this->catalog()->doc($this->project, $this->docSlug);

        abort_if($doc === null, 404);

        return $doc;
    }

    /**
     * The documents either side of this one.
     *
     * @return array{previous: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    #[Computed]
    public function neighbours(): array
    {
        if ($this->docSlug === null) {
            return ['previous' => null, 'next' => null];
        }

        return $this->catalog()->neighbours($this->project, $this->docSlug);
    }

    /**
     * Resolve the catalogue once per request.
     */
    protected function catalog(): ProjectCatalog
    {
        return $this->catalog ??= app(ProjectCatalog::class);
    }

    /**
     * Describe the overview or the current document to the document head.
     */
    protected function writeMetadata(Project $project, ProjectCatalog $catalog): void
    {
        $doc = $this->docSlug === null
            ? null
            : $catalog->doc($project, $this->docSlug);

        $canonical = $catalog->docUrl($project, $this->docSlug);

        app(Seo::class)->set([
            'title' => $doc === null
                ? $project->name.' — Selected work'
                : $doc['title'].' — '.$project->name,
            'description' => $doc['summary'] ?? $project->summary,
            'canonical' => $canonical,
            'type' => $doc === null ? 'website' : 'article',
            'authorName' => config('portfolio.author.name'),
            'tags' => $project->stack,
            'jsonLd' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => $doc === null ? 'CreativeWork' : 'TechArticle',
                    'name' => $doc['title'] ?? $project->name,
                    'headline' => $doc['title'] ?? $project->name,
                    'description' => $doc['summary'] ?? $project->summary,
                    'url' => $canonical,
                    'author' => [
                        '@type' => 'Person',
                        'name' => config('portfolio.author.name'),
                    ],
                    'keywords' => implode(', ', $project->stack),
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => array_values(array_filter([
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'name' => __('Home'),
                            'item' => route('home'),
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'name' => __('About'),
                            'item' => route('about'),
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 3,
                            'name' => $project->name,
                            'item' => $catalog->docUrl($project),
                        ],
                        $doc === null ? null : [
                            '@type' => 'ListItem',
                            'position' => 4,
                            'name' => $doc['title'],
                            'item' => $canonical,
                        ],
                    ])),
                ],
            ],
        ]);
    }
}; ?>

@php
    $project = $this->project;
    $doc = $this->doc;
    $docs = $this->docs;
    $previous = $this->neighbours['previous'];
    $next = $this->neighbours['next'];
@endphp

<div data-test="project-show">
    {{-- ================================================================== --
     | Header — the project identity, or the document inside it
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
                        <a href="{{ route('about') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('About') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li>
                        <a
                            href="{{ route('work.show', ['project' => $project->slug]) }}"
                            @if ($doc !== null) wire:navigate @endif
                            class="transition-colors hover:text-signal-700 dark:hover:text-signal-400"
                        >
                            {{ $project->name }}
                        </a>
                    </li>
                    @if ($doc !== null)
                        <li aria-hidden="true">/</li>
                        <li class="max-w-[16rem] truncate text-ink-600 dark:text-ink-300">{{ $doc['title'] }}</li>
                    @endif
                </ol>
            </nav>

            <div class="mt-8 grid gap-10 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-end lg:gap-16">
                <div class="min-w-0">
                    <p class="font-mono text-2xs uppercase tracking-widest text-signal-700 dark:text-signal-400">
                        {{ $project->marker() }} — {{ $project->role }}
                        @if ($project->isSite)
                            <span class="ml-2 rounded-full border border-signal-700/30 px-2 py-0.5 text-[10px] normal-case tracking-normal text-signal-700 dark:border-signal-400/30 dark:text-signal-400">
                                {{ __('This site') }}
                            </span>
                        @endif
                    </p>

                    <h1 class="mt-5 max-w-3xl text-[clamp(2rem,4.5vw,3.25rem)] font-semibold leading-[1.05] tracking-tightest text-ink-900 dark:text-ink-50">
                        {{ $doc['title'] ?? $project->name }}
                    </h1>

                    <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-600 md:text-lg dark:text-ink-300">
                        {{ $doc['summary'] ?? $project->summary }}
                    </p>

                    @if ($doc !== null)
                        <p class="mt-4 font-mono text-2xs text-ink-400 dark:text-ink-500">
                            {{ __('Documentation for') }} {{ $project->name }}
                            @if ($doc['reading_time'] > 0)
                                <span aria-hidden="true">·</span>
                                {{ trans_choice(':count min read', $doc['reading_time'], ['count' => $doc['reading_time']]) }}
                            @endif
                        </p>
                    @else
                        <div class="mt-6 flex flex-wrap gap-2">
                            @foreach ($project->stack as $tech)
                                <span class="rounded-md border border-ink-200 bg-ink-100/70 px-2.5 py-1 font-mono text-xs text-ink-600 dark:border-ink-700/80 dark:bg-ink-900/70 dark:text-ink-400">
                                    {{ $tech }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- The card that keeps the way out beside the way in. --}}
                <aside class="rounded-xl border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-950">
                    <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ $doc === null ? __('At a glance') : __('In this dossier') }}
                    </p>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-ink-500 dark:text-ink-400">{{ __('Role') }}</dt>
                            <dd class="text-right font-medium text-ink-900 dark:text-ink-50">{{ $project->role }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-ink-500 dark:text-ink-400">{{ __('Docs') }}</dt>
                            <dd class="text-right font-medium tabular-nums text-ink-900 dark:text-ink-50">{{ count($docs) }}</dd>
                        </div>
                        @if ($doc !== null)
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-ink-500 dark:text-ink-400">{{ __('Reading') }}</dt>
                                <dd class="text-right font-medium text-ink-900 dark:text-ink-50">
                                    {{ trans_choice(':count min', $doc['reading_time'], ['count' => $doc['reading_time']]) }}
                                </dd>
                            </div>
                        @endif
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-ink-500 dark:text-ink-400">{{ __('Project') }}</dt>
                            <dd class="text-right font-mono text-xs text-ink-900 dark:text-ink-50">{{ $project->marker() }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex flex-col gap-2">
                        @if ($doc !== null)
                            <a
                                href="{{ route('work.show', ['project' => $project->slug]) }}"
                                wire:navigate
                                class="focus-ring inline-flex min-h-10 items-center justify-center gap-2 rounded-full border border-ink-200 bg-white px-4 text-sm font-medium text-ink-800 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-100 dark:hover:border-signal-400 dark:hover:text-signal-400"
                            >
                                <flux:icon name="arrow-left" class="size-4" />
                                {{ __('Project overview') }}
                            </a>
                        @elseif ($docs !== [])
                            <a
                                href="{{ route('work.show', ['project' => $project->slug, 'doc' => $docs[0]['slug']]) }}"
                                class="focus-ring inline-flex min-h-10 items-center justify-center gap-2 rounded-full bg-signal-600 px-4 text-sm font-medium text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:text-ink-950 dark:hover:bg-signal-400"
                            >
                                {{ __('Start reading') }}
                                <flux:icon name="arrow-right" class="size-4" />
                            </a>
                        @endif

                        @if ($project->url !== null)
                            <a
                                href="{{ $project->url }}"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="focus-ring inline-flex min-h-10 items-center justify-center gap-2 rounded-full px-4 text-sm font-medium text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                            >
                                {{ __('Visit the project') }}
                                <flux:icon name="arrow-up-right" class="size-4" />
                            </a>
                        @endif
                    </div>
                </aside>
            </div>
        </div>
    </section>

    @if ($doc !== null)
        {{-- ============================================================== --
         | Reading a document — index on the left, prose on the right
         --}}
        <section class="shell py-12 lg:py-16">
            <div class="grid gap-10 lg:grid-cols-[260px_minmax(0,1fr)] lg:gap-16">
                <aside class="min-w-0">
                    <details class="rounded-xl border border-ink-200 bg-white open:pb-2 lg:hidden dark:border-ink-800 dark:bg-ink-950">
                        <summary class="focus-ring cursor-pointer list-none px-4 py-3 font-mono text-2xs uppercase tracking-widest text-ink-500 dark:text-ink-400">
                            {{ __('Documentation') }}
                            <span class="normal-case tracking-normal">({{ count($docs) }})</span>
                        </summary>

                        <x-app.work.docs-nav :project="$project" :docs="$docs" :current="$docSlug" class="px-3 pb-3" />
                    </details>

                    <div class="hidden lg:sticky lg:top-28 lg:block">
                        <x-app.work.docs-nav :project="$project" :docs="$docs" :current="$docSlug" />

                        @if ($project->url !== null)
                            <a
                                href="{{ $project->url }}"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="focus-ring mt-6 inline-flex items-center gap-2 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                            >
                                {{ __('Visit project') }}
                                <flux:icon name="arrow-up-right" class="size-3.5" />
                            </a>
                        @endif
                    </div>
                </aside>

                <div class="min-w-0">
                    <article class="prose-editorial max-w-3xl" data-doc-body>
                        {!! $doc['html'] !!}
                    </article>

                    <nav aria-label="{{ __('Documentation pages') }}" class="mt-14 grid gap-4 border-t border-ink-200 pt-8 sm:grid-cols-2 dark:border-ink-800">
                        @if ($previous !== null)
                            <a
                                href="{{ route('work.show', ['project' => $project->slug, 'doc' => $previous['slug']]) }}"
                                rel="prev"
                                class="focus-ring group rounded-xl border border-ink-200 bg-white p-5 transition-colors hover:border-signal-400 dark:border-ink-800 dark:bg-ink-950"
                            >
                                <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ __('Previous') }}
                                </span>
                                <span class="mt-2 flex items-center gap-2 text-sm font-medium leading-snug text-ink-900 dark:text-ink-50">
                                    <flux:icon name="arrow-left" class="size-4 shrink-0 text-ink-400 transition-transform group-hover:-translate-x-0.5" />
                                    {{ $previous['title'] }}
                                </span>
                            </a>
                        @endif

                        @if ($next !== null)
                            <a
                                href="{{ route('work.show', ['project' => $project->slug, 'doc' => $next['slug']]) }}"
                                rel="next"
                                class="focus-ring group rounded-xl border border-ink-200 bg-white p-5 transition-colors hover:border-signal-400 sm:text-right dark:border-ink-800 dark:bg-ink-950"
                            >
                                <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ __('Next') }}
                                </span>
                                <span class="mt-2 flex items-center gap-2 text-sm font-medium leading-snug text-ink-900 sm:justify-end dark:text-ink-50">
                                    {{ $next['title'] }}
                                    <flux:icon name="arrow-right" class="size-4 shrink-0 text-ink-400 transition-transform group-hover:translate-x-0.5" />
                                </span>
                            </a>
                        @endif
                    </nav>
                </div>
            </div>
        </section>
    @else
        {{-- ============================================================== --
         | The dossier — brief, result, and every documentation page
         --}}
        <section class="shell py-12 lg:py-16">
            <div class="grid gap-5 lg:grid-cols-12">
                <div class="rounded-xl border border-ink-200 bg-white p-6 lg:col-span-7 lg:p-8 dark:border-ink-800 dark:bg-ink-950">
                    <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ __('The brief') }}
                    </p>

                    <p class="mt-4 text-xl font-medium leading-relaxed tracking-tight text-ink-900 md:text-2xl dark:text-ink-50">
                        {{ $project->summary }}
                    </p>

                    <div class="hairline my-7"></div>

                    <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        {{ __('The result') }}
                    </p>

                    <p class="mt-3 text-base leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ $project->outcome }}
                    </p>
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-ink-200 bg-ink-50/70 p-6 lg:col-span-5 lg:p-8 dark:border-ink-800 dark:bg-ink-900/40">
                    <div>
                        <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            {{ __('Built with') }}
                        </p>

                        <ul class="mt-4 flex flex-wrap gap-2">
                            @foreach ($project->stack as $tech)
                                <li class="rounded-md border border-ink-200 bg-white px-2.5 py-1 font-mono text-xs text-ink-700 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-300">
                                    {{ $tech }}
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="mt-8 border-t border-ink-200 pt-5 dark:border-ink-800">
                        <p class="text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ $project->isSite
                                ? __('The documentation for this project is the repository itself. Every page below is one of its docs, rendered in full.')
                                : __('The documentation below is the project’s own: the decisions, the constraints, and the runbook that keeps it boring to operate.') }}
                        </p>

                        <a
                            href="{{ route('about') }}#about-work"
                            wire:navigate
                            class="focus-ring mt-5 inline-flex items-center gap-2 font-mono text-2xs uppercase tracking-widest text-signal-700 transition-colors hover:text-signal-600 dark:text-signal-400 dark:hover:text-signal-300"
                        >
                            <flux:icon name="arrow-left" class="size-3.5" />
                            {{ __('All selected work') }}
                        </a>
                    </div>
                </div>
            </div>

            @if ($docs !== [])
                <div class="mt-14">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                                {{ __('Documentation') }}
                            </h2>
                            <p class="mt-2 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                {{ trans_choice(
                                    'Every page of :name documentation: :count page.|Every page of :name documentation: :count pages.',
                                    count($docs),
                                    ['name' => $project->name, 'count' => count($docs)],
                                ) }}
                            </p>
                        </div>

                        @if ($docs !== [])
                            <a
                                href="{{ route('work.show', ['project' => $project->slug, 'doc' => $docs[0]['slug']]) }}"
                                class="focus-ring inline-flex min-h-10 items-center gap-2 rounded-full bg-signal-600 px-4 text-sm font-medium text-white transition-colors hover:bg-signal-700 dark:bg-signal-500 dark:text-ink-950 dark:hover:bg-signal-400"
                            >
                                {{ __('Start with') }} {{ $docs[0]['title'] }}
                                <flux:icon name="arrow-right" class="size-4" />
                            </a>
                        @endif
                    </div>

                    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($docs as $entry)
                            <a
                                href="{{ route('work.show', ['project' => $project->slug, 'doc' => $entry['slug']]) }}"
                                class="focus-ring group flex h-full flex-col rounded-xl border border-ink-200 bg-white p-5 transition-[border-color,transform,box-shadow] duration-300 hover:-translate-y-0.5 hover:border-signal-400 hover:shadow-lift dark:border-ink-800 dark:bg-ink-950 dark:hover:border-signal-400"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-500">
                                        {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                                    </span>

                                    <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                                        {{ trans_choice(':count min', $entry['reading_time'], ['count' => $entry['reading_time']]) }}
                                    </span>
                                </div>

                                <h3 class="mt-4 text-base font-semibold leading-snug tracking-tight text-ink-900 transition-colors group-hover:text-signal-700 dark:text-ink-50 dark:group-hover:text-signal-400">
                                    {{ $entry['title'] }}
                                </h3>

                                <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ $entry['summary'] }}
                                </p>

                                <span class="mt-auto inline-flex items-center gap-1.5 pt-5 font-mono text-2xs uppercase tracking-widest text-ink-400 transition-colors group-hover:text-signal-700 dark:text-ink-500 dark:group-hover:text-signal-400">
                                    {{ __('Read') }}
                                    <flux:icon name="arrow-right" class="size-3.5 transition-transform group-hover:translate-x-0.5" />
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="mt-12 rounded-xl border border-dashed border-ink-300 px-6 py-12 text-center dark:border-ink-700">
                    <p class="text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        {{ __('Documentation is being written') }}
                    </p>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ __('This project is documented in the repository rather than here. The brief above is the current public summary.') }}
                    </p>
                </div>
            @endif
        </section>
    @endif
</div>
