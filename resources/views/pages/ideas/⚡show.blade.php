<?php

use App\Enums\AccountPermission;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One published idea, read by anyone.
 *
 * The public side of the module. A guest sees what its author chose to publish
 * and nothing else, and the check that decides that is the model's
 * {@see Idea::isVisibleTo()} rather than a condition written into this page, so
 * the index, the workspace card and the admin console cannot disagree with it.
 *
 * The abort is a 404 even for an account that is signed in and not the author:
 * a private idea is invisible, not forbidden, and 403 would announce that the
 * row exists.
 *
 * No `#[Layout]`: this renders in the public shell (`layouts::guest.home`), the
 * way the blog and hub articles do. The ink tokens carry explicit `dark:`
 * variants here because the public side has no `data-sui` scope.
 *
 * Reading is the job of this page, so it borrows the layer the blog articles use
 * rather than inventing a second one: the progress rule at the top of the viewport,
 * the outline beside the body on wide screens and folded under it on narrow ones, and
 * the share bar above and below the prose. All of it is derived from the one Markdown
 * render below, so nothing here can drift from what the reader is looking at.
 */
new #[Title('Ideas')] class extends Component
{
    /**
     * The idea this page is about.
     *
     * Not named `$idea`: Livewire copies a matching route parameter onto a public
     * property of the same name, and `/ideas/{idea:slug}` would deliver the slug
     * string where an `?Idea` is declared.
     */
    public ?Idea $shownIdea = null;

    public function mount(Idea $idea): void
    {
        abort_unless($idea->isVisibleTo(auth()->user()), 404);

        // Loaded here rather than eager-loaded by the route binding because three of
        // the sections below read them: the byline, the topic keywords, and the
        // share bar's heading.
        $idea->loadMissing(['user', 'topics']);

        $this->shownIdea = $idea;

        // Counted after the visibility check, so a refused view never inflates
        // the number. The increment is query-level rather than model-level, which
        // is what makes the line below it correct rather than double: a model
        // `increment()` would already have moved the in-memory attribute.
        Idea::query()->whereKey($idea->id)->increment('view_count');
        $idea->view_count++;

        app(Seo::class)->set([
            'title' => $idea->title,
            'description' => $idea->excerpt(160),
            'canonical' => route('ideas.show', ['idea' => $idea->slug]),
            'type' => 'article',
            'publishedAt' => $idea->published_at?->toIso8601String(),
            'modifiedAt' => $idea->updated_at?->toIso8601String(),
            'authorName' => $idea->user->name,
            'tags' => $idea->topicNames(),
            // An author reading their own unpublished idea is reading a draft, and a
            // draft that announces itself to a search engine is a leak with extra steps.
            // The same flag suppresses the structured data below.
            'noindex' => ! $idea->isPublished(),
            'jsonLd' => $idea->isPublished() ? $this->structuredData($idea) : [],
        ]);
    }

    /**
     * What this page tells a machine about itself.
     *
     * Two nodes: the idea as an `Article`, and the trail of pages that lead to it. The
     * author is a `Person` carrying a name and nothing else — an email address in
     * structured data is an address published for harvesting, and the claim "this person
     * wrote it" does not need one. There is no `image` and no `license` rather than an
     * invented one, and the reading numbers are the same live-derived figures the byline
     * shows, so the metadata cannot drift from the page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function structuredData(Idea $idea): array
    {
        $url = route('ideas.show', ['idea' => $idea->slug]);

        // The byline link the reader can click, offered to the crawler too, so both
        // point at the same author facet. Absent when the account has no handle, for
        // the same reason the page renders a plain name instead of a link.
        $author = [
            '@type' => 'Person',
            'name' => $idea->user->name,
        ];

        if (filled($idea->user->handle)) {
            $author['url'] = route('ideas.index', ['author' => $idea->user->handle]);
        }

        $article = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $idea->title,
            'description' => $idea->excerpt(160),
            'inLanguage' => app()->getLocale(),
            'author' => $author,
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => route('home'),
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $url,
            ],
            'wordCount' => $this->wordCount,
            'timeRequired' => 'PT'.$this->readingMinutes.'M',
        ];

        if ($idea->published_at !== null) {
            $article['datePublished'] = $idea->published_at->toIso8601String();
        }

        if ($idea->updated_at !== null) {
            $article['dateModified'] = $idea->updated_at->toIso8601String();
        }

        if (($keywords = $idea->topicNames()) !== []) {
            $article['keywords'] = implode(', ', $keywords);
        }

        return [
            $article,
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => __('Home'),
                        'item' => route('home'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => __('Ideas'),
                        'item' => route('ideas.index'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 3,
                        'name' => $idea->title,
                        'item' => $url,
                    ],
                ],
            ],
        ];
    }

    /**
     * The rendered, sanitised body.
     *
     * Ideas do not keep a `body_html` projection the way posts and hub articles
     * do: an idea is edited far less often than it is written, and a derived
     * column would be a second place the Markdown has to be kept in sync.
     */
    #[Computed]
    public function bodyHtml(): string
    {
        return filled($this->shownIdea->content)
            ? app(MarkdownRenderer::class)->render($this->shownIdea->content)
            : '';
    }

    /**
     * The heading outline, derived from the render above.
     *
     * Read off the rendered HTML rather than re-parsed from the Markdown, so one
     * request does exactly one render and the anchors the table of contents points at
     * are the ones the renderer wrote. The shared renderer leaves duplicate headings
     * with the same slug, which is how the blog and hub pages behave too.
     *
     * @return array<int, array{level: int, id: string, text: string}>
     */
    #[Computed]
    public function outline(): array
    {
        return app(MarkdownRenderer::class)->outline($this->bodyHtml);
    }

    /**
     * Words in the body, counted live.
     *
     * Not stored: a column would be a second answer to a question the content already
     * answers, and it would be wrong the first time its author edited the idea.
     */
    #[Computed]
    public function wordCount(): int
    {
        return app(MarkdownRenderer::class)->countWords((string) $this->shownIdea->content);
    }

    /**
     * Minutes the body takes to read, at the rate the blog uses.
     */
    #[Computed]
    public function readingMinutes(): int
    {
        return app(MarkdownRenderer::class)->readingTime((string) $this->shownIdea->content);
    }

    /**
     * The published ideas immediately either side of this one in time.
     *
     * Walks the whole published set, while {@see related()} stays with the same
     * author, so the two are different ways out of the page rather than the same way
     * twice. Ordering matches the gallery's "recent" sort — `published_at` then `id`,
     * both descending — and the tie-break is written out because two ideas published
     * in the same second still need one answer for which came first.
     *
     * @return array{previous: Idea|null, next: Idea|null}
     */
    #[Computed]
    public function adjacent(): array
    {
        $idea = $this->shownIdea;
        $publishedAt = $idea->published_at;

        // A reader may reach their own draft through this page, and a draft has no
        // place in a walk over published work.
        if ($publishedAt === null) {
            return ['previous' => null, 'next' => null];
        }

        $previous = Idea::query()
            ->published()
            ->whereKeyNot($idea->id)
            ->where(fn (Builder $query) => $query
                ->where('published_at', '<', $publishedAt)
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('published_at', $publishedAt)
                    ->where('id', '<', $idea->id)))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        $next = Idea::query()
            ->published()
            ->whereKeyNot($idea->id)
            ->where(fn (Builder $query) => $query
                ->where('published_at', '>', $publishedAt)
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('published_at', $publishedAt)
                    ->where('id', '>', $idea->id)))
            ->orderBy('published_at')
            ->orderBy('id')
            ->first();

        return ['previous' => $previous, 'next' => $next];
    }

    /**
     * Other published ideas by the same account.
     *
     * Same-author rather than same-category: the category picker is private
     * filing, so a "related" rail built from it would expose how another account
     * organises its notes.
     *
     * @return Collection<int, Idea>
     */
    #[Computed]
    public function related(): Collection
    {
        return Idea::query()
            ->published()
            ->where('user_id', $this->shownIdea->user_id)
            ->whereKeyNot($this->shownIdea->id)
            ->when($this->shownIdea->idea_category_id !== null, fn (Builder $query) => $query->orderByRaw(
                'case when idea_category_id = ? then 0 else 1 end',
                [$this->shownIdea->idea_category_id],
            ))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();
    }

    /**
     * Whether this visitor may open the workspace this idea came from.
     */
    #[Computed]
    public function inWorkspace(): bool
    {
        return auth()->user()?->hasPermission(AccountPermission::UseIdeas) ?? false;
    }
}; ?>

<div data-test="idea-show">
    <x-app.ui.post.reading-progress />

    {{-- ================================================================== --
     | Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-12 lg:py-16">
            <nav aria-label="{{ __('Breadcrumb') }}">
                <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                    <li>
                        <a href="{{ route('ideas.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                            {{ __('Ideas') }}
                        </a>
                    </li>
                    <li aria-hidden="true">/</li>
                    <li class="max-w-[16rem] truncate text-ink-600 dark:text-ink-300">{{ $this->shownIdea->title }}</li>
                </ol>
            </nav>

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <x-app.ideas.status-badge :status="$this->shownIdea->status" size="sm" />

                @if ($this->shownIdea->priority !== null)
                    <flux:badge :color="$this->shownIdea->priority->badgeColor()" size="sm">
                        {{ $this->shownIdea->priority->label() }}
                    </flux:badge>
                @endif

                @if ($this->shownIdea->potential !== null)
                    <flux:badge :color="$this->shownIdea->potential->badgeColor()" size="sm">
                        {{ $this->shownIdea->potential->label() }}
                    </flux:badge>
                @endif
            </div>

            <h1 class="mt-5 max-w-3xl text-[clamp(1.75rem,4vw,2.75rem)] font-semibold leading-[1.1] tracking-tightest text-ink-900 dark:text-ink-50">
                {{ $this->shownIdea->title }}
            </h1>

            @if (filled($this->shownIdea->summary))
                <p class="mt-5 max-w-2xl text-lg leading-relaxed text-ink-600 dark:text-ink-300">
                    {{ $this->shownIdea->summary }}
                </p>
            @endif

            <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 text-sm text-ink-500 dark:text-ink-400">
                {{-- The byline links to this same page filtered by the author, because
                     there is no public profile route to invent. `?author=` is the facet
                     the landing already gates on published work. --}}
                @if (filled($this->shownIdea->user?->handle))
                    <a
                        href="{{ route('ideas.index', ['author' => $this->shownIdea->user->handle]) }}"
                        wire:navigate
                        class="focus-ring -ml-2 inline-flex min-h-10 items-center gap-2 rounded-lg px-2 transition-colors hover:text-signal-700 dark:hover:text-signal-400"
                    >
                        <flux:icon name="user" class="size-4" />
                        {{ $this->shownIdea->user->name }}
                    </a>
                @else
                    <span class="inline-flex items-center gap-2">
                        <flux:icon name="user" class="size-4" />
                        {{ $this->shownIdea->user?->name ?? __('Someone') }}
                    </span>
                @endif

                @if ($this->shownIdea->published_at !== null)
                    <span class="inline-flex items-center gap-2">
                        <flux:icon name="calendar" class="size-4" />
                        {{ $this->shownIdea->published_at->translatedFormat('j F Y') }}
                    </span>
                @endif

                @if ($this->wordCount > 0)
                    <span class="inline-flex items-center gap-2">
                        <flux:icon name="clock" class="size-4" />
                        {{ trans_choice(':count min read', $this->readingMinutes, ['count' => $this->readingMinutes]) }}
                    </span>

                    <span class="inline-flex items-center gap-2">
                        <flux:icon name="book-open" class="size-4" />
                        {{ trans_choice(':count word|:count words', $this->wordCount, ['count' => $this->wordCount]) }}
                    </span>
                @endif

                <span class="inline-flex items-center gap-2">
                    <flux:icon name="eye" class="size-4" />
                    {{ trans_choice(':count view|:count views', $this->shownIdea->view_count, ['count' => $this->shownIdea->view_count]) }}
                </span>

                @if ($this->inWorkspace)
                    <a
                        href="{{ route('ideas.dashboard') }}"
                        wire:navigate
                        class="focus-ring ml-auto inline-flex min-h-10 items-center gap-2 rounded-full border border-ink-200 bg-white px-4 text-sm font-medium text-ink-700 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-200"
                    >
                        <flux:icon name="light-bulb" class="size-4" />
                        {{ __('Open your ideas') }}
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Body — article column, sticky outline, and the way out
     --}}
    @php
        $hasOutline = count($this->outline) >= 2;
        $earlierIdea = $this->adjacent['previous'];
        $laterIdea = $this->adjacent['next'];
    @endphp

    <section class="shell py-14 lg:py-16">
        <div class="grid gap-12 {{ $hasOutline ? 'lg:grid-cols-[minmax(0,1fr)_220px] lg:gap-16' : '' }}">
            <div class="mx-auto min-w-0 max-w-3xl">
                <x-app.ui.post.share-bar
                    :url="route('ideas.show', ['idea' => $this->shownIdea->slug])"
                    :title="$this->shownIdea->title"
                    class="mb-10 border-b border-ink-200 pb-8 dark:border-ink-800"
                />

                @if ($this->bodyHtml !== '')
                    <div class="prose-editorial" data-reading-target>
                        {!! $this->bodyHtml !!}
                    </div>
                @else
                    <p class="text-base leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ __('This idea is still just a line. Its author has not written the rest yet.') }}
                    </p>
                @endif

                @if ($this->shownIdea->status === IdeaStatus::Approved)
                    <p class="mt-10 border-l-2 border-signal-400 pl-4 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                        {{ __('Approved: the account behind this idea has decided it is worth building. Whether it has been started is another question.') }}
                    </p>
                @endif

                <x-app.ui.post.share-bar
                    :url="route('ideas.show', ['idea' => $this->shownIdea->slug])"
                    :title="$this->shownIdea->title"
                    class="mt-14 border-t border-ink-200 pt-8 dark:border-ink-800"
                />

                @if ($earlierIdea !== null || $laterIdea !== null)
                    <nav aria-label="{{ __('Neighbouring ideas') }}" class="mt-10 grid gap-4 sm:grid-cols-2">
                        @if ($earlierIdea !== null)
                            <a
                                href="{{ route('ideas.show', ['idea' => $earlierIdea->slug]) }}"
                                rel="prev"
                                wire:navigate
                                class="focus-ring block rounded-xl border border-ink-200 bg-white p-5 transition-colors hover:border-signal-400 dark:border-ink-800 dark:bg-ink-950"
                            >
                                <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ __('Earlier') }}
                                </span>
                                <span class="mt-2 block text-sm font-medium leading-snug text-ink-900 dark:text-ink-50">
                                    {{ $earlierIdea->title }}
                                </span>
                                <span class="mt-1 block text-2xs text-ink-500 dark:text-ink-400">
                                    {{ $earlierIdea->published_at?->translatedFormat('j M Y') }}
                                </span>
                            </a>
                        @endif

                        @if ($laterIdea !== null)
                            <a
                                href="{{ route('ideas.show', ['idea' => $laterIdea->slug]) }}"
                                rel="next"
                                wire:navigate
                                class="focus-ring block rounded-xl border border-ink-200 bg-white p-5 transition-colors hover:border-signal-400 sm:text-right dark:border-ink-800 dark:bg-ink-950"
                            >
                                <span class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ __('Later') }}
                                </span>
                                <span class="mt-2 block text-sm font-medium leading-snug text-ink-900 dark:text-ink-50">
                                    {{ $laterIdea->title }}
                                </span>
                                <span class="mt-1 block text-2xs text-ink-500 dark:text-ink-400">
                                    {{ $laterIdea->published_at?->translatedFormat('j M Y') }}
                                </span>
                            </a>
                        @endif
                    </nav>
                @endif
            </div>

            @if ($hasOutline)
                <aside class="hidden lg:block">
                    <div class="sticky top-24 space-y-8">
                        <x-app.ui.post.toc :items="$this->outline" />
                    </div>
                </aside>
            @endif
        </div>

        @if ($hasOutline)
            <div class="mx-auto max-w-3xl">
                <details class="mt-12 lg:hidden">
                    <summary class="focus-ring cursor-pointer list-none font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">
                        {{ __('On this page') }}
                    </summary>
                    <x-app.ui.post.toc :items="$this->outline" id="toc-mobile" class="mt-4" />
                </details>
            </div>
        @endif
    </section>

        @if ($this->related->isNotEmpty())
            <div class="mt-16 border-t border-ink-200 pt-12 dark:border-ink-800">
                <h2 class="font-mono text-2xs font-semibold uppercase tracking-widest text-ink-500 dark:text-ink-400">
                    {{ __('More from :name', ['name' => $this->shownIdea->user->name]) }}
                </h2>

                <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->related as $related)
                        <x-app.ideas.card
                            :idea="$related"
                            :href="route('ideas.show', ['idea' => $related->slug])"
                            :show-status="false"
                            wire:key="related-{{ $related->id }}"
                        />
                    @endforeach
                </div>
            </div>
        @endif
    </section>
</div>
