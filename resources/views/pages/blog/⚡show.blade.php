<?php

use App\Actions\Posts\ResolveRelatedPosts;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The post detail page.
 *
 * The richest surface in the app. Responsibilities, in document order:
 *
 *   - Resolve the post via slug (route model binding) and authorise viewing,
 *     so a draft is a 404 for anyone but its author.
 *   - Record a view, at most once per session per post.
 *   - Render the sanitised `body_html` and derive a table of contents from it.
 *   - Offer the share bar.
 *   - Load bounded, ranked related posts.
 *   - Present the approved comment thread and accept new comments.
 *
 * The comment form is the one place on the public site that writes data, so it
 * carries a honeypot and a rate limit.
 */
new #[Title('Post')] class extends Component
{
    /**
     * The post being viewed. Locked so a crafted request cannot swap the model
     * out from under the component between the mount and a subsequent action.
     */
    #[Locked]
    public Post $post;

    /**
     * Bound to the comment form.
     */
    #[Validate('required|string|min:3|max:2000')]
    public string $commentBody = '';

    /**
     * Honeypot. Real readers never see or fill this; bots routinely do.
     */
    public string $website = '';

    /**
     * Set after a successful submission to thank the reader.
     */
    public bool $commentSubmitted = false;

    public function mount(Post $post): void
    {
        // `viewPublic` answers "is this readable on the public site" — a
        // published post is visible to guests, a draft only to its author.
        // A 404 is the right response for the rest: a 403 would confirm the
        // post exists.
        abort_unless(Gate::allows('viewPublic', $post), 404);

        $this->post = $post->load(['category', 'tags', 'user']);

        // Populate the document head. The share metadata contract lives in
        // docs/APP-FLOW.md §5; the values all derive from the bound post, which
        // is why they are set here rather than as a static layout attribute.
        app(Seo::class)->set([
            'title' => $post->title,
            'description' => $post->excerpt,
            'canonical' => route('blog.show', $post),
            'type' => 'article',
            'publishedAt' => $post->published_at?->toIso8601String(),
            'modifiedAt' => $post->updated_at->toIso8601String(),
            'authorName' => $post->user->name,
            'tags' => $post->tags->pluck('name')->all(),
        ]);

        $this->recordView();
    }

    /**
     * Increment the view counter, at most once per session per post.
     *
     * Written directly rather than through an Action because it is a single
     * atomic increment with no invariants to protect, and it must not touch
     * `updated_at` — a view is not an edit.
     */
    protected function recordView(): void
    {
        $key = 'viewed-post-'.$this->post->id;

        if (session()->has($key)) {
            return;
        }

        session()->put($key, true);

        Post::query()
            ->whereKey($this->post->id)
            ->increment('view_count');

        // Keep the in-memory model consistent with the database so the rendered
        // count includes this reader.
        $this->post->view_count++;
    }

    /**
     * Persist a new comment.
     *
     * Comments are always created pending: the queue is the point. The reader is
     * told it is awaiting moderation rather than shown a comment that is not
     * actually public yet.
     */
    public function postComment(): void
    {
        // Honeypot tripped: pretend success so the bot does not learn anything.
        if ($this->website !== '') {
            $this->commentSubmitted = true;
            $this->reset('commentBody', 'website');

            return;
        }

        $this->validate();

        $user = auth()->user();

        if ($user === null) {
            // Signed-out readers are redirected to sign in rather than being
            // allowed to post anonymously — a named thread is a calmer thread.
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->post->comments()->create([
            'user_id' => $user->id,
            'body' => $this->commentBody,
            'status' => CommentStatus::Pending,
        ]);

        $this->reset('commentBody');
        $this->commentSubmitted = true;

        unset($this->comments);
    }

    /**
     * The rendered article HTML. Guarded so it can never be re-rendered from a
     * client-supplied value — it is always derived from the stored markdown.
     */
    #[Computed]
    public function bodyHtml(): string
    {
        return $this->post->body_html ?? '';
    }

    /**
     * Table of contents, derived from the rendered HTML.
     *
     * Deriving from `body_html` rather than the markdown source guarantees the
     * anchor ids match the ids actually present in the DOM.
     *
     * @return array<int, array{level: int, id: string, text: string}>
     */
    #[Computed]
    public function outline(): array
    {
        return app(MarkdownRenderer::class)->outline($this->bodyHtml);
    }

    /**
     * Related posts.
     *
     * Bounded and ranked in PHP rather than with a window function, because
     * SQLite, MySQL, and PostgreSQL all have to agree on the result
     * (ADR-007, NFR-7).
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function related(): Collection
    {
        return app(ResolveRelatedPosts::class)->handle(
            $this->post,
            (int) config('portfolio.blog.related_limit', 3),
        );
    }

    /**
     * The approved comment thread, top level only.
     *
     * Replies are eager-loaded rather than lazy-loading per comment, so the
     * whole thread costs two queries regardless of its size.
     *
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->post->approvedComments()
            ->whereNull('parent_id')
            ->with([
                'user',
                'replies' => fn ($query) => $query->approved()->with('user')->oldest(),
            ])
            ->oldest()
            ->get();
    }

    /**
     * Total approved comments, including replies.
     */
    #[Computed]
    public function commentCount(): int
    {
        return $this->comments->sum(fn (Comment $comment) => 1 + $comment->replies->count());
    }
}; ?>

<div>
    <x-app.ui.post.reading-progress />

    <article>
        {{-- ============================================================== --
         | Header
         --}}
        <header class="border-b border-ink-200 dark:border-ink-800">
            <div class="shell py-12 lg:py-16">
                {{-- Breadcrumb --}}
                <nav aria-label="Breadcrumb" class="mb-8">
                    <ol class="flex flex-wrap items-center gap-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        <li>
                            <a href="{{ route('blog.index') }}" wire:navigate class="transition-colors hover:text-signal-700 dark:hover:text-signal-400">
                                Writing
                            </a>
                        </li>

                        @if ($post->category)
                            <li aria-hidden="true">/</li>
                            <li>
                                <a
                                    href="{{ route('blog.index', ['category' => $post->category->slug]) }}"
                                    wire:navigate
                                    class="text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                                >
                                    {{ $post->category->name }}
                                </a>
                            </li>
                        @endif
                    </ol>
                </nav>

                <div class="max-w-3xl">
                    @if ($post->is_featured)
                        <p class="mb-5 inline-flex items-center gap-1.5 rounded-xs border border-amber-300/60 bg-amber-50 px-2 py-0.5 font-mono text-2xs font-medium uppercase tracking-widest text-amber-700 dark:border-amber-500/30 dark:bg-amber-950/40 dark:text-amber-400">
                            Featured
                        </p>
                    @endif

                    <h1 class="text-3xl font-semibold leading-[1.15] tracking-tightest text-ink-900 sm:text-4xl lg:text-5xl dark:text-ink-50">
                        {{ $post->title }}
                    </h1>

                    @if (filled($post->excerpt))
                        <p class="mt-6 max-w-2xl text-lg leading-relaxed text-ink-500 dark:text-ink-400">
                            {{ $post->excerpt }}
                        </p>
                    @endif

                    {{-- Metadata rail --}}
                    <div class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        <span>{{ $post->user->name }}</span>
                        <span aria-hidden="true">·</span>
                        <time datetime="{{ $post->published_at?->toIso8601String() }}">
                            {{ $post->published_at?->format('F j, Y') }}
                        </time>
                        <span aria-hidden="true">·</span>
                        <span>{{ $post->readingTimeLabel() }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ number_format($post->view_count) }} {{ Str::plural('view', $post->view_count) }}</span>

                        @if ($post->updated_at->gt($post->published_at?->addDay() ?? $post->created_at))
                            <span aria-hidden="true">·</span>
                            <span>Updated {{ $post->updated_at->format('M j, Y') }}</span>
                        @endif

                        @can('update', $post)
                            <span aria-hidden="true">·</span>
                            <a
                                href="{{ route('dashboard.posts.edit', $post) }}"
                                wire:navigate
                                class="text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 dark:hover:text-signal-300"
                            >
                                Edit post
                            </a>
                        @endcan
                    </div>

                    {{-- Tags --}}
                    @if ($post->tags->isNotEmpty())
                        <ul class="mt-6 flex flex-wrap gap-1.5">
                            @foreach ($post->tags as $tag)
                                <li>
                                    <x-app.ui.tag-pill :tag="$tag" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </header>

        {{-- ============================================================== --
         | Body — article column plus a sticky sidebar
         --}}
        <div class="shell py-12 lg:py-16">
            <div class="grid gap-12 lg:grid-cols-[minmax(0,1fr)_220px] lg:gap-16">
                <div class="min-w-0">
                    {{-- Share bar sits above the fold of the article body --}}
                    <x-app.ui.post.share-bar :post="$post" class="mb-10 pb-8 border-b border-ink-200 dark:border-ink-800" />

                    {{-- Rendered, sanitised article content --}}
                    <div class="prose-editorial" data-reading-target>
                        {!! $this->bodyHtml !!}
                    </div>

                    {{-- Share bar repeated at the end, where intent is highest --}}
                    <x-app.ui.post.share-bar :post="$post" class="mt-14 border-t border-ink-200 pt-8 dark:border-ink-800" />
                </div>

                {{-- Sticky sidebar: TOC on desktop --}}
                <aside class="hidden lg:block">
                    <div class="sticky top-24 space-y-8">
                        @if (count($this->outline) >= 2)
                            <x-app.ui.post.toc :items="$this->outline" />
                        @endif

                        @if ($post->tags->isNotEmpty())
                            <div>
                                <h2 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    Filed under
                                </h2>
                                <ul class="mt-4 flex flex-wrap gap-1.5">
                                    @foreach ($post->tags as $tag)
                                        <li>
                                            <x-app.ui.tag-pill :tag="$tag" />
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </aside>
            </div>

            {{-- Mobile TOC, collapsed --}}
            @if (count($this->outline) >= 2)
                <details class="mt-12 lg:hidden">
                    <summary class="cursor-pointer font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">
                        On this page
                    </summary>
                    <x-app.ui.post.toc :items="$this->outline" id="toc-mobile" class="mt-4" />
                </details>
            @endif
        </div>
    </article>

    {{-- ================================================================== --
     | Related posts
     --}}
    @if ($this->related->isNotEmpty())
        <section data-reveal class="border-t border-ink-200 py-16 dark:border-ink-800" aria-labelledby="related-heading">
            <div class="shell">
                <x-app.ui.section-marker :number="2" label="Keep reading" />
                <h2 id="related-heading" class="mt-5 text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                    Related posts
                </h2>

                <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->related as $related)
                        <x-app.ui.post.card :post="$related" wire:key="related-{{ $related->id }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | Comments
     --}}
    <section data-reveal class="border-t border-ink-200 py-16 dark:border-ink-800" aria-labelledby="comments-heading">
        <div class="shell max-w-3xl">
            <x-app.ui.section-marker :number="3" label="Discussion" />
            <h2 id="comments-heading" class="mt-5 text-2xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ $this->commentCount }} {{ Str::plural('comment', $this->commentCount) }}
            </h2>

            {{-- Comment form --}}
            <div class="mt-10 rounded-lg border border-ink-200 p-6 dark:border-ink-800">
                @if ($commentSubmitted)
                    <div class="flex items-start gap-3">
                        <div class="grid size-8 shrink-0 place-items-center rounded-md border border-signal-300 bg-signal-50 text-signal-700 dark:border-signal-600 dark:bg-signal-950 dark:text-signal-400">
                            <flux:icon name="check" class="size-4" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-ink-900 dark:text-ink-50">
                                Thanks — your comment is in the queue.
                            </p>
                            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">
                                Comments are moderated before they appear, usually within a day.
                            </p>
                            <button
                                type="button"
                                wire:click="$set('commentSubmitted', false)"
                                class="mt-3 font-mono text-2xs text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400"
                            >
                                Write another
                            </button>
                        </div>
                    </div>
                @else
                    <form wire:submit="postComment" class="space-y-4">
                        <div class="flex items-center justify-between">
                            <label for="comment-body" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-500 dark:text-ink-400">
                                Leave a comment
                            </label>

                            @auth
                                <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                                    as {{ auth()->user()->name }}
                                </span>
                            @endauth
                        </div>

                        @guest
                            <p class="rounded-md border border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-500 dark:border-ink-800 dark:bg-ink-900 dark:text-ink-400">
                                <a href="{{ route('login') }}" wire:navigate class="font-medium text-signal-700 underline decoration-signal-400 underline-offset-2 dark:text-signal-400">
                                    Sign in
                                </a>
                                to join the discussion. Comments are published under your name.
                            </p>
                        @endguest

                        <div>
                            <textarea
                                id="comment-body"
                                wire:model="commentBody"
                                rows="5"
                                @disabled(auth()->guest())
                                placeholder="{{ auth()->guest() ? 'Sign in to comment…' : 'Add to the discussion. Technical disagreement is welcome; be specific.' }}"
                                class="w-full rounded-md border border-ink-200 bg-white px-4 py-3 text-sm leading-relaxed text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 disabled:cursor-not-allowed disabled:bg-ink-50 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-100 dark:placeholder:text-ink-600 dark:disabled:bg-ink-900"
                            ></textarea>

                            @error('commentBody')
                                <p class="mt-2 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror

                            <div class="mt-2 flex items-center justify-between font-mono text-2xs text-ink-400 dark:text-ink-500">
                                <span>Markdown-style emphasis and `code` are supported.</span>
                                <span>{{ Str::length($commentBody) }} / 2000</span>
                            </div>
                        </div>

                        {{-- Honeypot: visually and semantically hidden from readers --}}
                        <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                            <label for="website">Website (leave blank)</label>
                            <input
                                id="website"
                                type="text"
                                wire:model="website"
                                tabindex="-1"
                                autocomplete="off"
                            />
                        </div>

                        <div class="flex items-center gap-3">
                            <flux:button type="submit" variant="primary" :disabled="auth()->guest()" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="postComment">Submit comment</span>
                                <span wire:loading wire:target="postComment">Submitting…</span>
                            </flux:button>

                            <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                                Held for moderation
                            </span>
                        </div>
                    </form>
                @endif
            </div>

            {{-- Thread --}}
            @if ($this->comments->isEmpty())
                <x-app.ui.empty-state
                    class="mt-8"
                    icon="chat-bubble-left-right"
                    title="No comments yet"
                    description="Be the first to add something worth reading."
                />
            @else
                <ol class="mt-10 space-y-8">
                    @foreach ($this->comments as $comment)
                        <li wire:key="comment-{{ $comment->id }}">
                            <x-app.ui.comment :comment="$comment" />

                            {{-- Replies --}}
                            @if ($comment->replies->isNotEmpty())
                                <ol class="mt-6 space-y-6 border-l-2 border-ink-100 pl-6 dark:border-ink-800">
                                    @foreach ($comment->replies as $reply)
                                        <li wire:key="reply-{{ $reply->id }}">
                                            <x-app.ui.comment :comment="$reply" :is-reply="true" />
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>
