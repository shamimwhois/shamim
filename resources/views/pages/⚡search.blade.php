<?php

use App\Models\AiArticle;
use App\Models\FeedPost;
use App\Models\Idea;
use App\Models\LabSnippet;
use App\Models\Post;
use App\Models\RemoteMessage;
use App\Support\CommitBody;
use App\Support\LogIdentity;
use App\Support\Seo;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Search — every surface, one query.
 *
 * The log's palette finds developers; this page finds everything else. The two
 * exist because they answer different questions: a palette is for "who is
 * this?", a page is for "where did I read that?". Both read the same term, and
 * neither invents its own index — every model's own `search` scope is the only
 * matcher, so a post that matches here matches on the blog too.
 *
 * Each surface is capped rather than paginated: a page of six sections that
 * each page independently is six list pages wearing one title. The count tells
 * the reader how much is behind "see all", and the link lands on the surface
 * whose own filters are the right tool for going further.
 *
 * A short term searches nothing and says so, rather than quietly matching on
 * SQL's `%a%` and returning half the database.
 */
new #[Title('Search')] #[Layout('layouts::feed')] class extends Component
{
    /** Below this length a term is not a search, it is a keystroke. */
    private const int MIN_LENGTH = 2;

    /** How many rows a surface shows before it hands the reader to "see all". */
    private const int PER_SURFACE = 5;

    public string $q = '';

    public function mount(): void
    {
        $this->q = (string) request()->query('q', '');

        app(Seo::class)->set([
            'title' => 'Search',
            'description' => __('Search the log, the writing, the ideas, the knowledge hub, the lab and your own remotes from one place.'),
            'noindex' => true,
        ]);    }

    /** Whether the term is long enough to run. */
    public function getIsSearchingProperty(): bool
    {
        return Str::length(trim($this->q)) >= self::MIN_LENGTH;
    }

    /**
     * One section per surface that has something to say.
     *
     * @return array<int, array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}>
     */
    #[Computed]
    public function sections(): array
    {
        if (! $this->isSearching) {
            return [];
        }

        $term = trim($this->q);

        return array_values(array_filter([
            // First, because it is the only surface whose results are private:
            // if a reader's own thread matches, that is almost always what they
            // were looking for, and it would be odd to find it under the fold.
            $this->remotes($term),
            $this->commits($term),
            $this->posts($term),
            $this->ideas($term),
            $this->articles($term),
            $this->snippets($term),
        ]));
    }

    /** Total matches across every surface, for the header's count. */
    #[Computed]
    public function total(): int
    {
        return array_sum(array_map(fn (array $section): int => $section['count'], $this->sections));
    }

    /**
     * The reader's own remotes, one row per conversation.
     *
     * Two decisions are load-bearing here.
     *
     * **Privacy.** A remote is the one private surface on this page, so the
     * query starts from `visibleTo()` and never from `search()` alone: the
     * membership check is what keeps a term from reaching somebody else's
     * messages. A guest gets no section at all rather than an empty one.
     *
     * **One row per conversation, not per push.** Five hits inside one thread
     * would push the other four surfaces off the page for a single exchange,
     * and the section's link opens the remotes list, not a filtered one, so
     * counting pushes would promise a "see all" that does not exist. The count
     * is therefore conversations, and each row previews the newest matching
     * push in its thread.
     *
     * @return array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}|null
     */
    private function remotes(string $term): ?array
    {
        $reader = auth()->user();

        if ($reader === null) {
            return null;
        }

        // The newest matching push per conversation, in one query: `max(id)`
        // is monotonic with time here, so it is the last thing said that
        // matches rather than the first.
        $newest = RemoteMessage::query()
            ->visibleTo($reader)
            ->search($term)
            ->selectRaw('max(id) as id')
            ->groupBy('remote_id')
            ->pluck('id');

        if ($newest->isEmpty()) {
            return null;
        }

        $rows = RemoteMessage::query()
            ->whereIn('id', $newest->all())
            ->with(['user:id,name', 'remote.participants'])
            ->orderByDesc('id')
            ->take(self::PER_SURFACE)
            ->get()
            ->map(function (RemoteMessage $message) use ($reader): array {
                $title = $message->remote->displayTitle($reader);
                $sender = $message->user_id === $reader->id ? __('you') : $message->user->name;

                return [
                    'href' => route('remotes.index', ['remote' => $message->remote_id]),
                    'title' => $title,
                    // In a direct thread the title is already the other person,
                    // so naming them again as the eyebrow prints the same name
                    // twice. It earns its place only when it says something the
                    // title does not: who spoke in a group, or that it was you.
                    'eyebrow' => $sender === $title ? null : $sender,
                    'description' => Str::limit($message->body, 140),
                    'meta' => [LogIdentity::relative($message->created_at)],
                ];
            })->all();

        return [
            'key' => 'remotes',
            'title' => __('Remotes'),
            'count' => $newest->count(),
            // The list understands `?q=`, so "See all" lands on the threads that
            // matched rather than on the whole inbox.
            'href' => route('remotes.index', ['q' => $term]),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}|null
     */
    private function commits(string $term): ?array
    {
        $query = FeedPost::query()
            ->visible()
            ->where(fn ($query) => $query
                ->where('body', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
                ->orWhere('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->orderByDesc('posted_at');

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $rows = $query->take(self::PER_SURFACE)->get()->map(fn (FeedPost $post): array => [
            'href' => route('feed.show', ['slug' => $post->slug]),
            'title' => $post->title !== '' ? $post->title : Str::limit($post->body, 64),
            'eyebrow' => null,
            'description' => Str::limit($post->body, 140),
            'meta' => [$post->author, $post->posted_at?->translatedFormat('M j, Y')],
        ])->all();

        return [
            'key' => 'log',
            'title' => __('The log'),
            'count' => $count,
            'href' => route('feed.index', ['log' => 'latest']),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}|null
     */
    private function posts(string $term): ?array
    {
        $query = Post::query()->published()->search($term)->recent();

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $rows = $query->with('category')->take(self::PER_SURFACE)->get()->map(fn (Post $post): array => [
            'href' => route('blog.show', ['post' => $post->slug]),
            'title' => $post->title,
            'eyebrow' => $post->category?->name,
            'description' => $post->excerpt,
            'meta' => [$post->published_at?->format('M j, Y'), $post->readingTimeLabel()],
        ])->all();

        return [
            'key' => 'blog',
            'title' => __('Writing'),
            'count' => $count,
            'href' => route('blog.index', ['search' => $term]),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}|null
     */
    private function ideas(string $term): ?array
    {
        $query = Idea::query()->published()->search($term);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $rows = $query->take(self::PER_SURFACE)->get()->map(fn (Idea $idea): array => [
            'href' => route('ideas.show', ['idea' => $idea->slug]),
            'title' => $idea->title,
            'eyebrow' => $idea->status->label(),
            'description' => $idea->excerpt(140),
            'meta' => [$idea->published_at?->translatedFormat('M j, Y')],
        ])->all();

        return [
            'key' => 'ideas',
            'title' => __('Ideas'),
            'count' => $count,
            'href' => route('ideas.index', ['search' => $term]),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}|null
     */
    private function articles(string $term): ?array
    {
        $query = AiArticle::query()->published()->search($term);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $rows = $query->take(self::PER_SURFACE)->get()->map(fn (AiArticle $article): array => [
            'href' => route('ai-hub.article', ['article' => $article->slug]),
            'title' => $article->title,
            'eyebrow' => $article->format->label(),
            'description' => $article->excerpt(140),
            'meta' => [$article->readingTimeLabel(), $article->difficulty->label()],
        ])->all();

        return [
            'key' => 'ai-hub',
            'title' => __('AI Knowledge Hub'),
            'count' => $count,
            'href' => route('ai-hub.articles', ['search' => $term]),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{key: string, title: string, count: int, href: string, rows: array<int, array{href: string, title: string, eyebrow: ?string, description: ?string, meta: array<int, string|null>}>}|null
     */
    private function snippets(string $term): ?array
    {
        $query = LabSnippet::query()->published()->search($term)->recentlyPublished();

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $rows = $query->with('user:id,name')->take(self::PER_SURFACE)->get()->map(fn (LabSnippet $snippet): array => [
            'href' => route('dev-lab.snippets.show', ['snippet' => $snippet->slug]),
            'title' => $snippet->title,
            'eyebrow' => $snippet->language->label(),
            'description' => $snippet->excerpt(140),
            'meta' => [$snippet->user?->name ?? __('Someone'), $snippet->published_at?->translatedFormat('M j, Y')],
        ])->all();

        return [
            'key' => 'dev-lab',
            'title' => __('Developer Lab'),
            'count' => $count,
            'href' => route('dev-lab.gallery', ['search' => $term]),
            'rows' => $rows,
        ];
    }
}; ?>

<x-log.module
    active="log"
    data-test="search-page"
    :title="__('Search')"
    :meta="$this->isSearching ? trans_choice(':count match|:count matches', $this->total, ['count' => $this->total]) : null"
    :note="__('The log, the writing, the ideas, the knowledge hub, the lab — and, when you are signed in, your own remotes.')"
>
    <x-slot:filters>
        <div class="dn-module-tools">
            <div class="dn-search-field">
                <x-feed.icon name="search" :size="16" />
                <label for="search-term" class="sr-only">{{ __('Search everything') }}</label>
                <input
                    id="search-term"
                    type="search"
                    wire:model.live.debounce.400ms="q"
                    placeholder="{{ __('Search everything…') }}"
                    autocomplete="off"
                    autofocus
                />
            </div>
        </div>
    </x-slot:filters>

    @if (! $this->isSearching)
        <div class="dn-module-empty">
            <p class="dn-empty-command">
                @if (trim($q) !== '')
                    fatal: a query needs at least two characters
                @else
                    grep across everything
                @endif
            </p>
            <p class="dn-empty-text">
                {{ __('Type at least two characters. Posts, ideas, hub articles and snippets are all searched as plain text.') }}
            </p>
        </div>
    @elseif ($this->sections === [])
        <div class="dn-module-empty" data-test="search-empty">
            <p class="dn-empty-command">fatal: nothing matches '{{ $q }}'</p>
            <p class="dn-empty-text">
                {{ __('Nothing matches this term. A shorter one usually finds more.') }}
            </p>
        </div>
    @else
        @foreach ($this->sections as $section)
            <x-log.section
                :title="$section['title']"
                :count="trans_choice(':count match|:count matches', $section['count'], ['count' => $section['count']])"
                :href="$section['href']"
                :link-label="$section['count'] > count($section['rows']) ? __('See all') : __('Open :surface', ['surface' => $section['title']])"
                :wire:key="'search-section-'.$section['key']"
            >
                <div class="dn-entries">
                    @foreach ($section['rows'] as $row)
                        <x-log.entry
                            :href="$row['href']"
                            :title="$row['title']"
                            :eyebrow="$row['eyebrow']"
                            :description="$row['description']"
                            :meta="$row['meta']"
                        />
                    @endforeach
                </div>
            </x-log.section>
        @endforeach
    @endif
</x-log.module>
