<?php

use App\Enums\AccountPermission;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
new #[Title('Ideas')] #[Layout('layouts::feed')] class extends Component
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
<x-log.reader
    active="ideas"
    data-test="idea-show"
    :title="$this->shownIdea->title"
    :eyebrow="$this->shownIdea->status->label()"
    :back="route('ideas.index')"
    :back-label="__('All ideas')"
    :meta="array_filter([
        $this->shownIdea->user?->name ?? __('Someone'),
        $this->shownIdea->published_at?->translatedFormat('j F Y'),
        $this->wordCount > 0 ? trans_choice(':count min read', $this->readingMinutes, ['count' => $this->readingMinutes]) : null,
        $this->wordCount > 0 ? trans_choice(':count word|:count words', $this->wordCount, ['count' => $this->wordCount]) : null,
        trans_choice(':count view|:count views', $this->shownIdea->view_count, ['count' => $this->shownIdea->view_count]),
        $this->shownIdea->priority?->label(),
        $this->shownIdea->potential?->label(),
    ])"
>
    <x-app.ui.post.reading-progress />

    @if (count($this->outline) >= 2)
        {{-- The outline is rendered twice, as the site's reading pages do: once
             as the block at the top of the column, once inside a disclosure for
             a reader who has scrolled and wants it again. Each carries its own
             heading id, so both are valid HTML. --}}
        <x-log.toc :items="$this->outline" id="toc" />
        <details class="dn-toc-details">
            <summary class="dn-toc-title">{{ __('On this page') }}</summary>
            <x-log.toc :items="$this->outline" id="toc-mobile" />
        </details>
    @endif

    @if (filled($this->shownIdea->user?->handle))
        <x-slot:byline>
            <a href="{{ route('ideas.index', ['author' => $this->shownIdea->user->handle]) }}" wire:navigate class="dn-link">
                {{ __('More from :name', ['name' => $this->shownIdea->user->name]) }}
            </a>
        </x-slot:byline>
    @endif

    @php
        $hasOutline = count($this->outline) >= 2;
        $earlierIdea = $this->adjacent['previous'];
        $laterIdea = $this->adjacent['next'];
        $shareUrl = route('ideas.show', ['idea' => $this->shownIdea->slug]);
    @endphp

    <div class="dn-reader-body">
        @if (filled($this->shownIdea->summary))
            <p class="dn-reader-lead">{{ $this->shownIdea->summary }}</p>
        @endif

        <x-app.ui.post.share-bar :url="$shareUrl" :title="$this->shownIdea->title" class="dn-share" />

        @if ($this->bodyHtml !== '')
            <div class="dn-prose" data-reading-target>
                {!! $this->bodyHtml !!}
            </div>
        @else
            <p class="dn-lab-note" style="font-size: 15px;">
                {{ __('This idea is still just a line. Its author has not written the rest yet.') }}
            </p>
        @endif

        @if ($this->shownIdea->status === IdeaStatus::Approved)
            <p class="dn-band" style="margin: 20px 0 0; border-left: 2px solid var(--dn-primary); border-bottom: 0; padding-left: 14px;">
                <span class="dn-band-text">
                    {{ __('Approved: the account behind this idea has decided it is worth building. Whether it has been started is another question.') }}
                </span>
            </p>
        @endif

        @if ($this->shownIdea->topics->isNotEmpty())
            <ul class="dn-reader-topics" role="list">
                @foreach ($this->shownIdea->topics as $topic)
                    <li>
                        <a href="{{ route('ideas.index', ['topic' => $topic->slug]) }}" wire:navigate class="dn-status-chip">
                            {{ $topic->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($this->inWorkspace)
            <p class="dn-reader-edit">
                <a href="{{ route('ideas.dashboard') }}" wire:navigate class="dn-back">
                    {{ __('Open your ideas') }}
                </a>
            </p>
        @endif

        @if ($earlierIdea !== null || $laterIdea !== null)
            <x-log.section :title="__('Neighbouring ideas')">
                <div class="dn-entries">
                    @if ($earlierIdea !== null)
                        <x-log.entry
                            :href="route('ideas.show', ['idea' => $earlierIdea->slug])"
                            :title="$earlierIdea->title"
                            :eyebrow="__('Earlier')"
                            :description="$earlierIdea->excerpt()"
                            :meta="[$earlierIdea->published_at?->translatedFormat('j M Y')]"
                            rel="prev"
                            :wire:key="'prev-idea-'.$earlierIdea->id"
                        />
                    @endif

                    @if ($laterIdea !== null)
                        <x-log.entry
                            :href="route('ideas.show', ['idea' => $laterIdea->slug])"
                            :title="$laterIdea->title"
                            :eyebrow="__('Later')"
                            :description="$laterIdea->excerpt()"
                            :meta="[$laterIdea->published_at?->translatedFormat('j M Y')]"
                            rel="next"
                            :wire:key="'next-idea-'.$laterIdea->id"
                        />
                    @endif
                </div>
            </x-log.section>
        @endif

        @if ($this->related->isNotEmpty())
            <x-log.section :title="__('More from :name', ['name' => $this->shownIdea->user->name])">
                <div class="dn-entries">
                    @foreach ($this->related as $related)
                        <x-log.entry
                            :href="route('ideas.show', ['idea' => $related->slug])"
                            :title="$related->title"
                            :description="$related->excerpt()"
                            :meta="[$related->published_at?->translatedFormat('j M Y')]"
                            :wire:key="'related-idea-'.$related->id"
                        />
                    @endforeach
                </div>
            </x-log.section>
        @endif
    </div>
</x-log.reader>
