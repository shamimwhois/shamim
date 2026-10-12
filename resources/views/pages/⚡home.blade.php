<?php

use App\Data\AuthorProfile;
use App\Data\PostMetrics;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The landing page.
 *
 * Structure (docs/UI-UX-BRIEF.md §6.1), top to bottom:
 *   01 Hero          — who, what, and the one action that matters
 *   02 Writing       — the featured/latest posts, because writing is the proof
 *   03 Expertise     — capability areas, not a logo wall
 *   04 Selected work — outcome-first project summaries
 *   05 Stats band    — static professional figures plus live post counts
 *   06 Contact       — a single, unambiguous call to action
 *
 * Data-heavy sections read from `#[Computed]` properties so Livewire caches
 * them for the duration of the request and they are not recomputed on every
 * re-render.
 */
new #[Title('Senior Software Engineer')] class extends Component
{
    /**
     * Declare the page's head metadata. The landing page is the canonical
     * entry point, so it carries the full tagline as its description.
     */
    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => config('portfolio.author.name'),
            'description' => config('portfolio.author.tagline'),
            'canonical' => route('home'),
            'type' => 'profile',
            'authorName' => config('portfolio.author.name'),
        ]);
    }

    /**
     * Author profile from config. Not a computed property — it is pure config
     * and cannot change between requests.
     */
    public function profile(): AuthorProfile
    {
        return AuthorProfile::fromConfig();
    }

    /**
     * Published posts for the writing section.
     *
     * One query, two views: featured posts are pulled from the same collection.
     * Eager-loads are declared here because the card component reads
     * `category`, `tags`, and `comments`.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function posts(): Collection
    {
        return Post::query()
            ->published()
            ->recent()
            ->with(['category', 'tags'])
            ->withCount(['comments' => fn ($query) => $query->approved()])
            ->limit((int) config('portfolio.blog.latest_limit', 6))
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    #[Computed]
    public function featured(): Collection
    {
        return $this->posts->where('is_featured', true)->take(1)->values();
    }

    /**
     * Everything in the writing section that is not the lead item.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function latest(): Collection
    {
        $leadIds = $this->featured->pluck('id');

        return $this->posts->reject(fn (Post $post) => $leadIds->contains($post->id))->values();
    }

    /**
     * Topics for the "browse by topic" strip, ordered by how much has been
     * written about each. Only categories with published posts appear.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->ordered()
            ->whereHas('publishedPosts')
            ->get();
    }

    /**
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->popular()
            ->whereHas('posts', fn ($query) => $query->published())
            ->limit(12)
            ->get();
    }

    /**
     * Live counts used in the stats band. Merged with the static figures from
     * config so the band never claims a number it cannot substantiate.
     *
     * Site-wide rather than per-author: the landing page is public and has no
     * authenticated user to scope by.
     */
    #[Computed]
    public function metrics(): PostMetrics
    {
        return PostMetrics::forSite();
    }
}; ?>

<div>
    {{-- ================================================================== --
     | 01 — Hero
     | The grid field fades out behind the headline; the accent underline on
     | the tagline is the only saturated colour above the fold.
     --}}
    <section class="relative overflow-hidden" aria-labelledby="hero-heading">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative pb-20 pt-16 sm:pt-24 lg:pb-28 lg:pt-32">
            <x-section-marker :number="1" :label="$this->profile()->availability ?? 'Available'" accent />

            <h1
                id="hero-heading"
                class="mt-8 max-w-4xl text-4xl font-semibold leading-[1.08] tracking-tightest text-ink-900 sm:text-5xl dark:text-ink-50"
            >
                {{ $this->profile()->name }}
                <span class="block mt-3 text-2xl font-normal leading-snug tracking-tight text-ink-400 sm:text-3xl dark:text-ink-500">
                    {{ $this->profile()->role }}
                </span>
            </h1>

            <p class="mt-8 max-w-2xl text-lg leading-relaxed text-ink-600 dark:text-ink-300">
                {{ $this->profile()->tagline }}
            </p>

            <div class="mt-10 flex flex-wrap items-center gap-3">
                <flux:button variant="primary" :href="route('blog.index')" icon-trailing="arrow-right">
                    Read the writing
                </flux:button>

                @if ($email = $this->profile()->link('email'))
                    <flux:button variant="ghost" :href="$email['url']" icon="envelope">
                        Get in touch
                    </flux:button>
                @endif

                @if ($github = $this->profile()->link('github'))
                    <flux:button
                        variant="ghost"
                        :href="$github['url']"
                        target="_blank"
                        rel="noopener noreferrer"
                        icon="code-bracket"
                    >
                        GitHub
                    </flux:button>
                @endif
            </div>

            {{-- Quick facts rail --}}
            <dl class="mt-16 grid grid-cols-2 gap-x-8 gap-y-6 border-t border-ink-200 pt-8 sm:grid-cols-4 dark:border-ink-800">
                @foreach ($this->profile()->stats as $stat)
                    <div>
                        <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            {{ $stat['label'] }}
                        </dt>
                        <dd class="mt-2 text-2xl font-semibold tabular-nums tracking-tight text-ink-900 dark:text-ink-50">
                            {{ $stat['value'] }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — Writing
     --}}
    <section class="shell py-20 lg:py-24" aria-labelledby="writing-heading">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <x-section-marker :number="2" label="Writing" />
                <h2 id="writing-heading" class="mt-5 max-w-xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    Notes on building systems that last
                </h2>
            </div>

            <a
                href="{{ route('blog.index') }}"
                wire:navigate
                class="group inline-flex items-center gap-2 font-mono text-xs font-medium text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
            >
                All {{ $this->metrics->publishedPosts }} posts
                <span aria-hidden="true" class="transition-transform group-hover:translate-x-0.5">→</span>
            </a>
        </div>

        @if ($this->posts->isEmpty())
            <x-empty-state
                class="mt-10"
                title="No writing published yet"
                description="Posts will appear here once the first one goes out."
                icon="document-text"
            />
        @else
            {{-- Lead item: full-width featured post --}}
            @foreach ($this->featured as $post)
                <div class="mt-10">
                    <x-post.card :post="$post" featured :heading-level="'h3'" />
                </div>
            @endforeach

            {{-- Remaining posts: a three-column grid --}}
            @if ($this->latest->isNotEmpty())
                <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->latest as $post)
                        <x-post.card :post="$post" :heading-level="'h3'" />
                    @endforeach
                </div>
            @endif
        @endif

        {{-- Browse by topic --}}
        @if ($this->categories->isNotEmpty() || $this->tags->isNotEmpty())
            <div class="mt-16 border-t border-ink-200 pt-10 dark:border-ink-800">
                <div class="grid gap-10 lg:grid-cols-[200px_1fr]">
                    <div>
                        <h3 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            Categories
                        </h3>
                        <ul class="mt-4 space-y-2.5">
                            @foreach ($this->categories as $category)
                                <li>
                                    <a
                                        href="{{ route('blog.index', ['category' => $category->slug]) }}"
                                        wire:navigate
                                        class="group flex items-center justify-between gap-4 text-sm text-ink-600 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                                    >
                                        <span>{{ $category->name }}</span>
                                        <span class="font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-600">
                                            {{ $category->posts_count }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div>
                        <h3 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            Topics
                        </h3>
                        <ul class="mt-4 flex flex-wrap gap-1.5">
                            @foreach ($this->tags as $tag)
                                <li>
                                    <x-tag-pill :tag="$tag" :count="$tag->posts_count" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </section>

    {{-- ================================================================== --
     | 03 — Expertise
     --}}
    <section class="border-y border-ink-200 bg-ink-50/60 py-20 lg:py-24 dark:border-ink-800 dark:bg-ink-900/30" aria-labelledby="expertise-heading">
        <div class="shell">
            <x-section-marker :number="3" label="Expertise" />
            <h2 id="expertise-heading" class="mt-5 max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                What I am actually good at
            </h2>

            <div class="mt-12 grid gap-px overflow-hidden rounded-lg border border-ink-200 bg-ink-200 sm:grid-cols-2 lg:grid-cols-3 dark:border-ink-800 dark:bg-ink-800">
                @foreach ($this->profile()->expertise as $item)
                    <div class="group bg-white p-6 transition-colors hover:bg-ink-50/80 dark:bg-ink-950 dark:hover:bg-ink-900/60">
                        <div class="flex size-9 items-center justify-center rounded-md border border-ink-200 bg-white text-ink-500 transition-colors group-hover:border-signal-400 group-hover:text-signal-600 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-400 dark:group-hover:border-signal-600 dark:group-hover:text-signal-400">
                            <flux:icon :name="$item['icon']" class="size-4.5" />
                        </div>

                        <h3 class="mt-5 text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                            {{ $item['title'] }}
                        </h3>

                        <p class="mt-2.5 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ $item['description'] }}
                        </p>

                        <ul class="mt-5 flex flex-wrap gap-1.5">
                            @foreach ($item['tags'] as $tag)
                                <li class="rounded-xs border border-ink-200 px-2 py-0.5 font-mono text-2xs text-ink-500 dark:border-ink-700 dark:text-ink-400">
                                    {{ $tag }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 04 — Selected work
     --}}
    <section class="shell py-20 lg:py-24" aria-labelledby="work-heading">
        <x-section-marker :number="4" label="Selected work" />
        <h2 id="work-heading" class="mt-5 max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
            Systems I have built and shipped
        </h2>

        <div class="mt-12 space-y-px overflow-hidden rounded-lg border border-ink-200 dark:border-ink-800">
            @foreach ($this->profile()->projects as $index => $project)
                <article class="group relative bg-white p-6 transition-colors hover:bg-ink-50/80 sm:p-8 dark:bg-ink-950 dark:hover:bg-ink-900/60">
                    <div class="grid gap-6 lg:grid-cols-[240px_1fr]">
                        <div>
                            <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-600">
                                {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                            </p>
                            <h3 class="mt-3 text-xl font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ $project['name'] }}
                            </h3>
                            <p class="mt-1.5 font-mono text-2xs text-signal-700 dark:text-signal-400">
                                {{ $project['role'] }}
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-ink-500 dark:text-ink-400">
                                {{ $project['summary'] }}
                            </p>

                            <p class="mt-4 text-base leading-relaxed text-ink-800 dark:text-ink-200">
                                {{ $project['outcome'] }}
                            </p>

                            <ul class="mt-5 flex flex-wrap gap-1.5">
                                @foreach ($project['stack'] as $tech)
                                    <li class="rounded-xs bg-ink-100 px-2 py-0.5 font-mono text-2xs font-medium text-ink-600 dark:bg-ink-900 dark:text-ink-400">
                                        {{ $tech }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ================================================================== --
     | 05 — Contact
     --}}
    <section class="border-t border-ink-200 py-20 lg:py-24 dark:border-ink-800" aria-labelledby="contact-heading">
        <div class="shell">
            <div class="grid gap-10 lg:grid-cols-[1fr_1.2fr] lg:items-center">
                <div>
                    <x-section-marker :number="5" label="Contact" />
                    <h2 id="contact-heading" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                        Let us talk about the hard part
                    </h2>
                    <p class="mt-4 max-w-md text-base leading-relaxed text-ink-500 dark:text-ink-400">
                        I am most useful when a system has outgrown its first design and nobody agrees on what to do next.
                    </p>
                </div>

                <div class="rounded-lg border border-ink-200 p-6 sm:p-8 dark:border-ink-800">
                    <p class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        Currently
                    </p>
                    <p class="mt-3 text-lg font-medium tracking-tight text-ink-900 dark:text-ink-50">
                        {{ $this->profile()->availability }}
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        @if ($email = $this->profile()->link('email'))
                            <flux:button variant="primary" :href="$email['url']" icon="envelope">
                                {{ str_replace('mailto:', '', $email['url']) }}
                            </flux:button>
                        @endif

                        @if ($linkedin = $this->profile()->link('linkedin'))
                            <flux:button
                                variant="ghost"
                                :href="$linkedin['url']"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                LinkedIn
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
