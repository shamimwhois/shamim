<?php

use App\Data\AuthorProfile;
use App\Data\PostMetrics;
use App\Models\Post;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The about page.
 *
 * Deliberately not a résumé. A CV lists what you did; this page explains how you
 * think, which is what a hiring manager or a peer actually wants to know before
 * deciding whether to read the writing or send an email.
 *
 * Structure:
 *   01 The short version  — the bio, unadorned
 *   02 How I work         — the principles that show up in the decisions
 *   03 What I am doing    — current focus and availability
 *   04 Selected work      — the same outcome-first summaries as the landing page
 *   05 Get in touch       — one action
 */
new #[Title('About')] class extends Component
{
    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'About '.config('portfolio.author.name'),
            'description' => config('portfolio.author.bio'),
            'canonical' => route('about'),
            'type' => 'profile',
            'authorName' => config('portfolio.author.name'),
        ]);
    }

    /**
     * The author profile, from config.
     */
    public function profile(): AuthorProfile
    {
        return AuthorProfile::fromConfig();
    }

    /**
     * How I work — the operating principles.
     *
     * Static because they are beliefs, not data. Kept here rather than in config
     * because they are prose, and prose belongs next to the markup that renders
     * it.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public function principles(): array
    {
        return [
            [
                'title' => 'Make the wrong thing hard to do',
                'body' => 'A convention nobody can violate beats a convention everyone agrees with. Constraints that live in the type system, the query layer, or the build pipeline keep working when the team is tired.',
            ],
            [
                'title' => 'Read the query plan before arguing about the abstraction',
                'body' => 'Most performance debates are really debates about measurements nobody took. The database will tell you what is slow if you ask it directly.',
            ],
            [
                'title' => 'Write it down while the reasoning is still recoverable',
                'body' => 'An architecture decision record costs twenty minutes and saves the next engineer a week of archaeology. The value is not the decision — it is the constraint that forced it.',
            ],
            [
                'title' => 'Delete code before adding a flag',
                'body' => 'Every configuration branch doubles the surface you have to test. Most features are not optional; they are just unloved, and the honest move is removal.',
            ],
            [
                'title' => 'Optimise for the second year',
                'body' => 'Shipping is the easy part. Almost everything that makes a system expensive later was a reasonable decision made in the first month.',
            ],
            [
                'title' => 'Leave the codebase friendlier than you found it',
                'body' => 'Not a rewrite. One clearer name, one test that was missing, one comment that explains why rather than what.',
            ],
        ];
    }

    /**
     * Current focus areas.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function focus(): array
    {
        return [
            ['label' => 'Primary stack', 'value' => 'Laravel, PHP 8.5, PostgreSQL, Redis'],
            ['label' => 'Comfortable in', 'value' => 'TypeScript, Go, Kubernetes, Terraform'],
            ['label' => 'Worked on', 'value' => 'Payments, developer tooling, public APIs'],
            ['label' => 'Based in', 'value' => config('portfolio.author.location')],
            ['label' => 'Experience', 'value' => config('portfolio.author.years_of_experience').' years'],
            ['label' => 'Looking for', 'value' => 'Senior platform or backend roles'],
        ];
    }

    /**
     * Recent published posts, to close the loop between the bio and the work.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function recentPosts(): Collection
    {
        return Post::query()
            ->published()
            ->recent()
            ->with(['category', 'tags'])
            ->limit(3)
            ->get();
    }

    /**
     * Site-wide content metrics.
     */
    #[Computed]
    public function metrics(): PostMetrics
    {
        return PostMetrics::forSite();
    }
}; ?>

<div>
    {{-- ================================================================== --
     | Header
     --}}
    <section class="relative overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative py-16 lg:py-20">
            <x-app.ui.section-marker :number="1" label="About" accent />

            <div class="mt-8 grid gap-10 lg:grid-cols-[1fr_280px] lg:items-start lg:gap-16">
                <div>
                    <h1 class="max-w-2xl text-3xl font-semibold leading-[1.15] tracking-tightest text-ink-900 sm:text-4xl lg:text-5xl dark:text-ink-50">
                        {{ $this->profile()->bio }}
                    </h1>

                    <div class="mt-8 max-w-2xl space-y-5 text-base leading-relaxed text-ink-600 dark:text-ink-300">
                        {!! nl2br(e($this->profile()->longBio)) !!}
                    </div>
                </div>

                {{-- Profile card --}}
                <aside class="rounded-lg border border-ink-200 bg-white p-6 dark:border-ink-800 dark:bg-ink-900">
                    <x-app.icon.logo-mark
                        size="lg"
                        class="border border-ink-900/10 bg-ink-900 font-mono text-signal-400 dark:border-ink-50/10 dark:bg-ink-50 dark:text-ink-950"
                    />

                    <p class="mt-5 text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        {{ $this->profile()->name }}
                    </p>
                    <p class="mt-1 font-mono text-2xs text-ink-500 dark:text-ink-400">
                        {{ $this->profile()->role }}
                    </p>

                    <div class="hairline my-5"></div>

                    <dl class="space-y-3">
                        @foreach ($this->focus() as $item)
                            <div>
                                <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ $item['label'] }}
                                </dt>
                                <dd class="mt-1 text-sm text-ink-700 dark:text-ink-300">
                                    {{ $item['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="hairline my-5"></div>

                    {{-- Published counts --}}
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                Posts
                            </dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums tracking-tight text-ink-900 dark:text-ink-50">
                                {{ $this->metrics->publishedPosts }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                Views
                            </dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums tracking-tight text-ink-900 dark:text-ink-50">
                                {{ number_format($this->metrics->totalViews) }}
                            </dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 02 — How I work
     --}}
    <section class="shell py-20" aria-labelledby="principles-heading">
        <x-app.ui.section-marker :number="2" label="How I work" />
        <h2 id="principles-heading" class="mt-5 max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
            The things I keep coming back to
        </h2>

        <div class="mt-12 grid gap-x-12 gap-y-10 sm:grid-cols-2">
            @foreach ($this->principles() as $index => $principle)
                <div class="border-t border-ink-200 pt-6 dark:border-ink-800">
                    <p class="font-mono text-2xs tabular-nums text-signal-600 dark:text-signal-400">
                        {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                    </p>
                    <h3 class="mt-3 text-lg font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        {{ $principle['title'] }}
                    </h3>
                    <p class="mt-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                        {{ $principle['body'] }}
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ================================================================== --
     | 03 — Expertise
     --}}
    <section class="border-y border-ink-200 bg-ink-50/60 py-20 dark:border-ink-800 dark:bg-ink-900/30" aria-labelledby="expertise-heading">
        <div class="shell">
            <x-app.ui.section-marker :number="3" label="Expertise" />
            <h2 id="expertise-heading" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                Where I am most useful
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
    <section class="shell py-20" aria-labelledby="work-heading">
        <x-app.ui.section-marker :number="4" label="Selected work" />
        <h2 id="work-heading" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
            Things I have built
        </h2>

        <div class="mt-12 space-y-px overflow-hidden rounded-lg border border-ink-200 dark:border-ink-800">
            @foreach ($this->profile()->projects as $index => $project)
                <article class="bg-white p-6 transition-colors hover:bg-ink-50/80 sm:p-8 dark:bg-ink-950 dark:hover:bg-ink-900/60">
                    <div class="grid gap-6 lg:grid-cols-[220px_1fr]">
                        <div>
                            <p class="font-mono text-2xs text-ink-400 dark:text-ink-600">
                                {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                            </p>
                            <h3 class="mt-3 text-lg font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ $project['name'] }}
                            </h3>
                            <p class="mt-1.5 font-mono text-2xs text-signal-700 dark:text-signal-400">
                                {{ $project['role'] }}
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-ink-500 dark:text-ink-400">{{ $project['summary'] }}</p>
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
     | 05 — Recent writing
     --}}
    @if ($this->recentPosts->isNotEmpty())
        <section class="border-t border-ink-200 py-20 dark:border-ink-800" aria-labelledby="recent-heading">
            <div class="shell">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <x-app.ui.section-marker :number="5" label="Writing" />
                        <h2 id="recent-heading" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                            Recently published
                        </h2>
                    </div>

                    <a
                        href="{{ route('blog.index') }}"
                        wire:navigate
                        class="group inline-flex items-center gap-2 font-mono text-xs font-medium text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                    >
                        All posts
                        <span aria-hidden="true" class="transition-transform group-hover:translate-x-0.5">→</span>
                    </a>
                </div>

                <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->recentPosts as $post)
                        <x-app.ui.post.card :post="$post" wire:key="about-post-{{ $post->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 06 — Contact
     --}}
    <section class="border-t border-ink-200 py-20 dark:border-ink-800" aria-labelledby="contact-heading">
        <div class="shell max-w-3xl">
            <x-app.ui.section-marker :number="6" label="Get in touch" />
            <h2 id="contact-heading" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ $this->profile()->availability }}
            </h2>

            <p class="mt-4 text-base leading-relaxed text-ink-500 dark:text-ink-400">
                The fastest way to reach me is email. I read everything and reply to most things, though not always quickly.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                @if ($email = $this->profile()->link('email'))
                    <flux:button variant="primary" :href="$email['url']" icon="envelope">
                        {{ str_replace('mailto:', '', $email['url']) }}
                    </flux:button>
                @endif

                @foreach (['github', 'linkedin', 'x'] as $network)
                    @if ($link = $this->profile()->link($network))
                        <flux:button
                            variant="ghost"
                            :href="$link['url']"
                            target="_blank"
                            rel="noopener noreferrer me"
                        >
                            {{ $link['label'] }}
                        </flux:button>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
</div>
