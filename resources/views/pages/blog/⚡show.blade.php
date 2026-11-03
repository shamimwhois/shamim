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
use Livewire\Attributes\Layout;
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
new #[Title('Post')] #[Layout('layouts::feed')] class extends Component
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
            // Same contract as the feed's membership toggle: keep the reader
            // on the article with the login dialog open rather than walking
            // them to a separate screen. The extra `signin` entry is not a
            // route parameter, so it is appended as the query flag the dialog
            // reads; `navigate: true` reloads this page with it.
            $this->redirectRoute('blog.show', ['post' => $this->post->slug, 'signin' => 'required'], navigate: true);

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
@php
    $post = $this->post;
@endphp

<x-log.reader
    active="blog"
    data-test="blog-show"
    :title="$post->title"
    :eyebrow="$post->category?->name ?? __('Writing')"
    :back="route('blog.index')"
    :back-label="__('All posts')"
    :meta="array_filter([
        $post->user->name,
        $post->published_at?->format('F j, Y'),
        $post->readingTimeLabel(),
        number_format($post->view_count).' '.Str::plural('view', $post->view_count),
        $post->is_featured ? __('Featured') : null,
        $post->updated_at->gt($post->published_at?->addDay() ?? $post->created_at) ? __('Updated :date', ['date' => $post->updated_at->format('M j, Y')]) : null,
    ])"
>
    @if (count($this->outline) >= 2)
        <x-log.toc :items="$this->outline" />
    @endif

    <div class="dn-reader-body">
        @if (filled($post->excerpt))
            <p class="dn-reader-lead">{{ $post->excerpt }}</p>
        @endif

        <x-app.ui.post.share-bar :post="$post" class="dn-share" />

        {{-- Rendered, sanitised post content --}}
        <div class="dn-prose" data-reading-target>
            {!! $this->bodyHtml !!}
        </div>

        @if ($post->tags->isNotEmpty())
            <ul class="dn-reader-topics" role="list">
                @foreach ($post->tags as $tag)
                    <li>
                        <a href="{{ route('blog.index', ['tag' => $tag->slug]) }}" wire:navigate class="dn-status-chip">
                            #{{ $tag->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $post)
            @if (auth()->user()?->hasPermission(\App\Enums\AccountPermission::AccessAdminPanel))
                <p class="dn-reader-edit">
                    <a href="{{ route('admin.posts.edit', $post) }}" wire:navigate class="dn-back">
                        {{ __('Edit post') }}
                    </a>
                </p>
            @endif
        @endcan

        @if ($this->related->isNotEmpty())
            <x-log.section :title="__('Keep reading')">
                <div class="dn-entries">
                    @foreach ($this->related as $related)
                        <x-log.entry
                            :href="route('blog.show', ['post' => $related->slug])"
                            :title="$related->title"
                            :eyebrow="$related->category?->name"
                            :description="$related->excerpt"
                            :meta="[
                                $related->published_at?->format('M j, Y'),
                                $related->readingTimeLabel(),
                            ]"
                            :wire:key="'related-post-'.$related->id"
                        />
                    @endforeach
                </div>
            </x-log.section>
        @endif

        {{-- The discussion. The form is the one place on a reading page that
             writes data, and every comment is created pending: the queue is the
             point, so the reader is told their comment is held rather than
             shown a comment that is not there yet. --}}
        <x-log.section
            :title="__('Discussion')"
            :count="$this->commentCount.' '.Str::plural('comment', $this->commentCount)"
            anchor="comments-heading"
        >
            @if ($commentSubmitted)
                <div class="dn-band" style="border-bottom: 0;">
                    <span class="dn-band-body">
                        <span class="dn-band-title">{{ __('Thanks — your comment is in the queue.') }}</span>
                        <span class="dn-band-text">
                            {{ __('Comments are moderated before they appear, usually within a day.') }}
                        </span>
                    </span>

                    <span class="dn-band-actions">
                        <button type="button" class="dn-btn dn-btn-outline dn-btn-sm" wire:click="$set('commentSubmitted', false)">
                            {{ __('Write another') }}
                        </button>
                    </span>
                </div>
            @else
                <form wire:submit="postComment" class="dn-comment-form">
                    <div class="dn-comment-form-head">
                        <label for="comment-body" class="dn-field-key">{{ __('Leave a comment') }}</label>

                        @auth
                            <span class="dn-field-key">{{ __('as :name', ['name' => auth()->user()->name]) }}</span>
                        @endauth
                    </div>

                    @guest
                        <p class="dn-lab-note">
                            <a href="{{ route('login') }}" x-on:click="$flux.modal('login').show(); $event.preventDefault()" class="dn-link">{{ __('Sign in') }}</a>
                            {{ __('to join the discussion. Comments are published under your name.') }}
                        </p>
                    @endguest

                    <textarea
                        id="comment-body"
                        wire:model="commentBody"
                        rows="5"
                        @disabled(auth()->guest())
                        placeholder="{{ auth()->guest() ? __('Sign in to comment…') : __('Add to the discussion. Technical disagreement is welcome; be specific.') }}"
                        class="dn-input"
                        style="min-height: 7rem;"
                    ></textarea>

                    @error('commentBody')
                        <p class="dn-form-error">{{ $message }}</p>
                    @enderror

                    <div class="dn-comment-form-foot">
                        <span>{{ __('Markdown-style emphasis and `code` are supported.') }}</span>
                        <span>{{ Str::length($commentBody) }} / 2000</span>
                    </div>

                    {{-- Honeypot: visually and semantically hidden from readers --}}
                    <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                        <label for="website">Website (leave blank)</label>
                        <input id="website" type="text" wire:model="website" tabindex="-1" autocomplete="off" />
                    </div>

                    <div class="dn-form-row">
                        <button type="submit" class="dn-btn" @disabled(auth()->guest()) wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="postComment">{{ __('Submit comment') }}</span>
                            <span wire:loading wire:target="postComment">{{ __('Submitting…') }}</span>
                        </button>

                        <span class="dn-field-key">{{ __('Held for moderation') }}</span>
                    </div>
                </form>
            @endif

            @if ($this->comments->isEmpty())
                <p class="dn-lab-note" style="margin-top: 20px;">
                    {{ __('No comments yet. Be the first to add something worth reading.') }}
                </p>
            @else
                <ol class="dn-thread" role="list">
                    @foreach ($this->comments as $comment)
                        <li wire:key="comment-{{ $comment->id }}" class="dn-thread-row">
                            <x-app.ui.comment :comment="$comment" />

                            @if ($comment->replies->isNotEmpty())
                                <ol class="dn-thread-replies" role="list">
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
        </x-log.section>
    </div>
</x-log.reader>
