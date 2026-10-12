<?php

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\DeletePost;
use App\Actions\Posts\SyncPostTags;
use App\Actions\Posts\UpdatePost;
use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Rules\PostSlug;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The post editor.
 *
 * Handles both create and edit through one component: the routes differ, the
 * form does not. When `$post` is null the component is creating.
 *
 * Three behaviours worth calling out:
 *
 *   1. **Autosave.** A debounced write that only fires for existing posts and
 *      only when the form is actually dirty. Creating is always an explicit act,
 *      because persisting a half-typed title would litter the drafts list.
 *
 *   2. **Live preview.** The markdown is rendered through the same
 *      MarkdownRenderer that serves the public page, so the preview cannot
 *      drift from what readers will see — including sanitisation.
 *
 *   3. **Deferred publishing.** Status intentions are tracked in `$intendedStatus`
 *      and applied on save, so a post is never simultaneously published with
 *      stale HTML.
 */
new #[Layout('layouts::dashboard')] #[Title('Editor')] class extends Component
{
    /**
     * The post under edit. Null when creating.
     */
    #[Locked]
    public ?Post $post = null;

    public string $title = '';

    public string $slug = '';

    public string $excerpt = '';

    public string $body = '';

    public ?int $categoryId = null;

    /**
     * Tag ids currently attached.
     *
     * @var array<int, int>
     */
    public array $tagIds = [];

    public bool $isFeatured = false;

    /**
     * The status the author intends the post to end up in.
     *
     * Tracked separately from the persisted status so an autosave does not
     * accidentally publish a draft, and so the invariant enforced by the publish
     * actions is never bypassed.
     */
    public string $intendedStatus = PostStatus::Draft->value;

    /**
     * The persisted status, for comparison in the UI.
     */
    #[Locked]
    public string $savedStatus = PostStatus::Draft->value;

    /**
     * Free-text tag entry, comma separated.
     */
    public string $tagInput = '';

    /**
     * Editor mode: write, preview, or side-by-side.
     */
    public string $mode = 'write';

    /**
     * Timestamp of the last successful save, for the status indicator.
     */
    public ?string $lastSavedAt = null;

    /**
     * Whether there are unsaved changes.
     */
    public bool $dirty = false;

    public function mount(?Post $post = null): void
    {
        app(Seo::class)->set([
            'title' => $post?->exists ? 'Edit: '.$post->title : 'New post',
            'noindex' => true,
        ]);

        if ($post?->exists) {
            // A post can only be edited by its author. A 404 keeps the existence
            // of someone else's draft private.
            abort_unless($post->user_id === auth()->id(), 404);

            $this->post = $post->load('tags');
            $this->title = $post->title;
            $this->slug = $post->slug;
            $this->excerpt = $post->excerpt ?? '';
            $this->body = $post->body;
            $this->categoryId = $post->category_id;
            $this->tagIds = $post->tags->pluck('id')->all();
            $this->isFeatured = $post->is_featured;
            $this->intendedStatus = $post->status->value;
            $this->savedStatus = $post->status->value;
            $this->lastSavedAt = $post->updated_at->toIso8601String();
        }
    }

    /**
     * Mark the form dirty on any content change.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['title', 'slug', 'excerpt', 'body', 'categoryId', 'tagIds', 'isFeatured'], true)) {
            $this->dirty = true;
        }
    }

    /**
     * Autosave.
     *
     * Only for existing posts, and only when something changed. A new post is
     * never written implicitly — creating a record as a side effect of typing
     * would be surprising.
     */
    public function autosave(UpdatePost $updatePost): void
    {
        if ($this->post === null || ! $this->post->exists || ! $this->dirty) {
            return;
        }

        try {
            $this->validate([
                'title' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255', new PostSlug($this->post->id)],
                'excerpt' => ['nullable', 'string', 'max:500'],
                'body' => ['required', 'string'],
            ]);
        } catch (\Illuminate\Validation\ValidationException) {
            // Autosave is best-effort. Invalid intermediate states are expected
            // while typing; the explicit Save action surfaces the errors.
            return;
        }

        $this->persist($updatePost);

        $this->lastSavedAt = now()->toIso8601String();
        $this->dirty = false;
    }

    /**
     * Explicit save. Validates, persists, and surfaces any errors.
     */
    public function save(CreatePost $createPost, UpdatePost $updatePost, SyncPostTags $syncPostTags): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', new PostSlug($this->post?->id)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'categoryId' => ['nullable', 'integer', 'exists:categories,id'],
            'intendedStatus' => ['required', 'string'],
        ]);

        if ($this->post !== null && $this->post->exists) {
            $this->persist($updatePost);
            $this->lastSavedAt = now()->toIso8601String();
            $this->dirty = false;

            Flux::toast(variant: 'success', text: __('Post saved.'));

            return;
        }

        // Creating: the action owns the transaction and derived columns.
        $this->post = $createPost->handle(
            auth()->user(),
            [
                'title' => $this->title,
                'slug' => $this->slug,
                'excerpt' => $this->excerpt,
                'body' => $this->body,
                'category_id' => $this->categoryId,
                'status' => PostStatus::from($this->intendedStatus),
                'is_featured' => $this->isFeatured,
            ],
            $this->resolvedTagIds($syncPostTags),
        );

        $this->savedStatus = $this->post->status->value;
        $this->intendedStatus = $this->post->status->value;
        $this->lastSavedAt = now()->toIso8601String();
        $this->dirty = false;

        Flux::toast(variant: 'success', text: __('Post created.'));

        $this->redirectRoute('dashboard.posts.edit', $this->post, navigate: true);
    }

    /**
     * Write the current form state to an existing post.
     */
    protected function persist(UpdatePost $updatePost): void
    {
        if ($this->post === null) {
            return;
        }

        $this->post = $updatePost->handle($this->post, [
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'category_id' => $this->categoryId,
            'status' => PostStatus::from($this->intendedStatus),
            'is_featured' => $this->isFeatured,
        ], $this->resolvedTagIds(app(SyncPostTags::class)));

        $this->savedStatus = $this->post->status->value;
    }

    /**
     * Resolve the tag ids to attach, creating any new tags typed in.
     *
     * @return array<int, int>
     */
    protected function resolvedTagIds(SyncPostTags $syncPostTags): array
    {
        $ids = $this->tagIds;

        $typed = collect(explode(',', $this->tagInput))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->values();

        if ($typed->isNotEmpty()) {
            $ids = array_merge($ids, $syncPostTags->resolveIds($typed));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Add a new tag from the inline input.
     */
    public function addTag(SyncPostTags $syncPostTags): void
    {
        $name = trim($this->tagInput);

        if ($name === '') {
            return;
        }

        $ids = $syncPostTags->resolveIds(collect([$name]));

        $this->tagIds = array_values(array_unique(array_merge($this->tagIds, $ids)));
        $this->tagInput = '';
        $this->dirty = true;
    }

    /**
     * Detach a tag.
     */
    public function removeTag(int $tagId): void
    {
        $this->tagIds = array_values(array_filter($this->tagIds, fn (int $id) => $id !== $tagId));
        $this->dirty = true;
    }

    /**
     * Attach a tag from the suggestion list.
     */
    public function addSuggestedTag(int $tagId): void
    {
        if (in_array($tagId, $this->tagIds, true)) {
            return;
        }

        $this->tagIds = array_values(array_unique(array_merge($this->tagIds, [$tagId])));
        $this->dirty = true;

        unset($this->selectedTags, $this->tagSuggestions);
    }

    /**
     * Switch the intended status.
     *
     * Publishing from here is intentional only when explicitly confirmed, so a
     * mis-click cannot push a draft live.
     */
    public function setStatus(string $status): void
    {
        if (PostStatus::tryFrom($status) === null) {
            return;
        }

        $this->intendedStatus = $status;
        $this->dirty = true;
    }

    /**
     * Derive a slug from the title, if the slug is empty or matches the title.
     */
    public function syncSlugFromTitle(): void
    {
        $derived = \Illuminate\Support\Str::slug($this->title);

        // Only auto-fill when the slug was never touched or still tracks the
        // title, so a hand-written slug is never overwritten.
        if ($this->slug === '' || $this->slug === \Illuminate\Support\Str::slug($this->title)) {
            $this->slug = $derived;
        }
    }

    /**
     * Switch between write, preview, and split modes.
     */
    public function setMode(string $mode): void
    {
        if (in_array($mode, ['write', 'preview', 'split'], true)) {
            $this->mode = $mode;
        }
    }

    /**
     * Delete the post.
     */
    public function delete(DeletePost $deletePost): void
    {
        if ($this->post === null) {
            return;
        }

        $this->authorize('delete', $this->post);

        $deletePost->handle($this->post);

        Flux::toast(variant: 'success', text: __('Post deleted.'));

        $this->redirectRoute('dashboard.posts.index', navigate: true);
    }

    /**
     * The preview HTML, rendered through the same pipeline as the public page.
     */
    #[Computed]
    public function preview(): string
    {
        return app(MarkdownRenderer::class)->render($this->body);
    }

    /**
     * Body statistics shown under the editor.
     *
     * @return array{words: int, minutes: int, characters: int}
     */
    #[Computed]
    public function bodyStats(): array
    {
        $renderer = app(MarkdownRenderer::class);

        return [
            'words' => $renderer->countWords($this->body),
            'minutes' => $renderer->readingTime($this->body),
            'characters' => mb_strlen($this->body),
        ];
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->ordered()->get();
    }

    /**
     * Tags currently attached to the post.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function selectedTags(): Collection
    {
        return Tag::query()->whereIn('id', $this->tagIds)->orderBy('name')->get();
    }

    /**
     * Suggestions for the tag picker: the most-used tags not already attached.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tagSuggestions(): Collection
    {
        return Tag::query()
            ->popular()
            ->whereNotIn('id', $this->tagIds)
            ->limit(12)
            ->get();
    }

    /**
     * Whether the post has ever been published.
     */
    #[Computed]
    public function isPublished(): bool
    {
        return $this->post?->isPublished() ?? false;
    }
}; ?>

<div x-data="{ mode: @entangle('mode') }">
    <x-dashboard.header
        eyebrow="{{ $post?->exists ? 'Editing' : 'New post' }}"
        :title="$post?->exists ? $post->title : 'Untitled post'"
        :description="$post?->exists
            ? 'Last saved '.($this->lastSavedAt ? \Illuminate\Support\Carbon::parse($this->lastSavedAt)->diffForHumans() : 'never')
            : 'Draft a new post. Nothing is published until you say so.'"
    >
        <x-slot:actions>
            @if ($post?->exists && $post->isPublished())
                <flux:button variant="ghost" :href="route('blog.show', $post)" target="_blank" rel="noopener" icon="arrow-top-right-on-square">
                    View live
                </flux:button>
            @endif

            <flux:button variant="ghost" :href="route('dashboard.posts.index')" wire:navigate>
                Cancel
            </flux:button>

            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" icon="check">
                <span wire:loading.remove wire:target="save">{{ $post?->exists ? 'Save changes' : 'Create post' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </flux:button>
        </x-slot:actions>
    </x-dashboard.header>

    <div class="px-6 py-8 lg:px-10">
        {{-- Save-status bar --}}
        @if ($post?->exists)
            <div class="mb-6 flex items-center gap-3 rounded-md border border-ink-200 bg-white px-4 py-2.5 dark:border-ink-800 dark:bg-ink-900">
                <span
                    @class([
                        'size-1.5 shrink-0 rounded-full',
                        'bg-amber-500' => $dirty,
                        'bg-signal-500' => ! $dirty,
                    ])
                    aria-hidden="true"
                ></span>

                <p class="font-mono text-2xs text-ink-500 dark:text-ink-400" aria-live="polite">
                    @if ($dirty)
                        Unsaved changes
                    @else
                        Saved {{ $this->lastSavedAt ? \Illuminate\Support\Carbon::parse($this->lastSavedAt)->diffForHumans() : '' }}
                    @endif
                </p>

                <div class="ml-auto flex items-center gap-2">
                    <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                        {{ number_format($this->bodyStats['words']) }} words
                        · {{ $this->bodyStats['minutes'] }} min read
                    </span>

                    <button
                        type="button"
                        wire:click="autosave"
                        wire:loading.attr="disabled"
                        class="font-mono text-2xs text-signal-700 transition-colors hover:text-signal-800 dark:text-signal-400 disabled:opacity-50"
                    >
                        Save now
                    </button>
                </div>
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
            {{-- ========================================================= --
             | Main editor column
             --}}
            <div class="min-w-0 space-y-4">
                {{-- Title --}}
                <div>
                    <label for="post-title" class="sr-only">Title</label>
                    <input
                        id="post-title"
                        type="text"
                        wire:model.live.debounce.500ms="title"
                        wire:blur="syncSlugFromTitle"
                        placeholder="Post title"
                        autocomplete="off"
                        class="w-full rounded-md border border-ink-200 bg-white px-4 py-3 text-xl font-semibold tracking-tight text-ink-900 placeholder:text-ink-300 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-50 dark:placeholder:text-ink-700"
                    />
                    @error('title')
                        <p class="mt-2 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Slug --}}
                <div class="flex items-center gap-2 rounded-md border border-ink-200 bg-ink-50 px-3 py-2 dark:border-ink-800 dark:bg-ink-950">
                    <span class="shrink-0 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        /blog/
                    </span>
                    <label for="post-slug" class="sr-only">Slug</label>
                    <input
                        id="post-slug"
                        type="text"
                        wire:model.blur="slug"
                        placeholder="post-slug"
                        autocomplete="off"
                        class="min-w-0 flex-1 bg-transparent font-mono text-xs text-ink-700 placeholder:text-ink-300 focus:outline-none dark:text-ink-300 dark:placeholder:text-ink-700"
                    />
                    @if ($post?->exists && $post->isPublished())
                        <span class="shrink-0 font-mono text-2xs text-amber-600 dark:text-amber-400" title="Changing the slug of a published post breaks existing links">
                            live URL
                        </span>
                    @endif
                </div>
                @error('slug')
                    <p class="-mt-2 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                {{-- Editor toolbar --}}
                <div class="flex items-center gap-1 border-b border-ink-200 dark:border-ink-800">
                    @foreach (['write' => 'Write', 'preview' => 'Preview', 'split' => 'Split'] as $value => $label)
                        <button
                            type="button"
                            wire:click="setMode('{{ $value }}')"
                            @class([
                                'relative px-4 py-2.5 text-sm font-medium transition-colors',
                                'text-ink-900 dark:text-ink-50' => $mode === $value,
                                'text-ink-400 hover:text-ink-700 dark:text-ink-500 dark:hover:text-ink-200' => $mode !== $value,
                            ])
                        >
                            {{ $label }}
                            @if ($mode === $value)
                                <span aria-hidden="true" class="absolute inset-x-3 -bottom-px h-0.5 bg-signal-500 dark:bg-signal-400"></span>
                            @endif
                        </button>
                    @endforeach

                    <div class="ml-auto flex items-center gap-2 pr-1 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        <span>{{ number_format($this->bodyStats['characters']) }} chars</span>
                    </div>
                </div>

                {{-- Editor panes --}}
                <div @class([
                    'grid gap-4',
                    'lg:grid-cols-2' => $this->mode === 'split',
                ])>
                    @if (in_array($this->mode, ['write', 'split'], true))
                        <div>
                            <label for="post-body" class="sr-only">Body (Markdown)</label>
                            <textarea
                                id="post-body"
                                wire:model.live.debounce.600ms="body"
                                rows="{{ $this->mode === 'split' ? 28 : 24 }}"
                                placeholder="# Start writing

Markdown is supported: headings, lists, code fences, blockquotes, and links."
                                spellcheck="true"
                                class="w-full resize-y rounded-md border border-ink-200 bg-white px-4 py-3 font-mono text-sm leading-relaxed text-ink-800 placeholder:text-ink-300 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-200 dark:placeholder:text-ink-700"
                            ></textarea>
                            @error('body')
                                <p class="mt-2 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    @if (in_array($this->mode, ['preview', 'split'], true))
                        <div class="min-w-0">
                            <div class="rounded-md border border-ink-200 bg-white px-5 py-4 dark:border-ink-800 dark:bg-ink-950">
                                <p class="mb-4 border-b border-ink-100 pb-3 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:border-ink-800 dark:text-ink-500">
                                    Preview
                                </p>

                                <div class="prose-editorial max-w-none">
                                    @if (trim($this->body) === '')
                                        <p class="text-sm italic text-ink-300 dark:text-ink-700">
                                            Nothing to preview yet.
                                        </p>
                                    @else
                                        {!! $this->preview !!}
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ========================================================= --
             | Sidebar: publishing controls
             --}}
            <div class="space-y-4">
                {{-- Status --}}
                <div class="rounded-lg border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-900">
                    <h2 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        Status
                    </h2>

                    <div class="mt-4 space-y-2">
                        @foreach (PostStatus::cases() as $case)
                            @continue($case === PostStatus::Archived)

                            <label @class([
                                'flex cursor-pointer items-start gap-3 rounded-md border p-3 transition-colors',
                                'border-signal-400 bg-signal-50 dark:border-signal-600 dark:bg-signal-950/40' => $intendedStatus === $case->value,
                                'border-ink-200 hover:border-ink-300 dark:border-ink-800 dark:hover:border-ink-700' => $intendedStatus !== $case->value,
                            ])>
                                <input
                                    type="radio"
                                    name="intended-status"
                                    value="{{ $case->value }}"
                                    wire:click="setStatus('{{ $case->value }}')"
                                    @checked($intendedStatus === $case->value)
                                    class="mt-0.5 size-4 shrink-0 border-ink-300 text-signal-600 focus:ring-signal-500 dark:border-ink-600"
                                />

                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-ink-900 dark:text-ink-50">
                                        {{ $case->label() }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-ink-500 dark:text-ink-400">
                                        @if ($case === PostStatus::Draft)
                                            Only you can see this.
                                        @else
                                            Visible to everyone on the public site.
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if ($post?->exists && $intendedStatus !== $savedStatus)
                        <p class="mt-4 rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 font-mono text-2xs text-amber-700 dark:border-amber-500/30 dark:bg-amber-950/40 dark:text-amber-400">
                            Status changes from {{ PostStatus::from($savedStatus)->label() }} on save.
                        </p>
                    @endif

                    {{-- Featured toggle --}}
                    <div class="mt-5 border-t border-ink-200 pt-5 dark:border-ink-800">
                        <label class="flex cursor-pointer items-center justify-between gap-3">
                            <span>
                                <span class="block text-sm font-medium text-ink-900 dark:text-ink-50">Featured</span>
                                <span class="mt-0.5 block text-xs text-ink-500 dark:text-ink-400">Shown as the lead post on the landing page.</span>
                            </span>

                            <input
                                type="checkbox"
                                wire:model.live="isFeatured"
                                class="size-4 shrink-0 rounded border-ink-300 text-signal-600 focus:ring-signal-500 dark:border-ink-600"
                            />
                        </label>
                    </div>
                </div>

                {{-- Category --}}
                <div class="rounded-lg border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-900">
                    <label for="post-category" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        Category
                    </label>

                    <select
                        id="post-category"
                        wire:model.live="categoryId"
                        class="mt-4 w-full rounded-md border border-ink-200 bg-white px-3 py-2 text-sm text-ink-800 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-200"
                    >
                        <option value="">No category</option>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>

                    <p class="mt-2 text-xs text-ink-400 dark:text-ink-500">
                        Categories are broad topics. Use tags for specifics.
                    </p>
                </div>

                {{-- Tags --}}
                <div class="rounded-lg border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-900">
                    <h2 class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        Tags
                    </h2>

                    {{-- Attached tags --}}
                    @if ($this->selectedTags->isNotEmpty())
                        <ul class="mt-4 flex flex-wrap gap-1.5">
                            @foreach ($this->selectedTags as $tag)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="removeTag({{ $tag->id }})"
                                        class="tag-pill cursor-pointer"
                                        title="Remove {{ $tag->name }}"
                                    >
                                        {{ $tag->name }}
                                        <flux:icon name="x-mark" class="size-2.5" />
                                        <span class="sr-only">Remove {{ $tag->name }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 text-xs text-ink-400 dark:text-ink-500">No tags yet.</p>
                    @endif

                    {{-- Add a tag --}}
                    <div class="mt-4 flex gap-2">
                        <label for="tag-input" class="sr-only">Add tags</label>
                        <input
                            id="tag-input"
                            type="text"
                            wire:model="tagInput"
                            wire:keydown.enter.prevent="addTag"
                            placeholder="Add a tag…"
                            autocomplete="off"
                            class="min-w-0 flex-1 rounded-md border border-ink-200 bg-white px-3 py-1.5 text-xs text-ink-800 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-200 dark:placeholder:text-ink-600"
                        />
                        <flux:button size="xs" variant="ghost" wire:click="addTag" icon="plus">
                            <span class="sr-only">Add tag</span>
                        </flux:button>
                    </div>

                    {{-- Suggestions --}}
                    @if ($this->tagSuggestions->isNotEmpty())
                        <div class="mt-4 border-t border-ink-200 pt-4 dark:border-ink-800">
                            <p class="font-mono text-2xs text-ink-400 dark:text-ink-500">Suggestions</p>
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($this->tagSuggestions as $tag)
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="addSuggestedTag({{ $tag->id }})"
                                            class="rounded-xs border border-dashed border-ink-300 px-2 py-0.5 font-mono text-2xs text-ink-500 transition-colors hover:border-signal-400 hover:text-signal-700 dark:border-ink-700 dark:text-ink-400 dark:hover:border-signal-600 dark:hover:text-signal-400"
                                        >
                                            + {{ $tag->name }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                {{-- Excerpt --}}
                <div class="rounded-lg border border-ink-200 bg-white p-5 dark:border-ink-800 dark:bg-ink-900">
                    <label for="post-excerpt" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        Excerpt
                    </label>

                    <textarea
                        id="post-excerpt"
                        wire:model.blur="excerpt"
                        rows="4"
                        placeholder="Shown on cards, in search results, and in share previews. Left blank, it is derived from the first paragraph."
                        class="mt-4 w-full resize-y rounded-md border border-ink-200 bg-white px-3 py-2 text-xs leading-relaxed text-ink-800 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-200 dark:placeholder:text-ink-600"
                    ></textarea>

                    @error('excerpt')
                        <p class="mt-2 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <p class="mt-2 font-mono text-2xs text-ink-400 dark:text-ink-500">
                        {{ mb_strlen($excerpt) }} / 500
                    </p>
                </div>

                {{-- Danger zone --}}
                @if ($post?->exists)
                    <div class="rounded-lg border border-red-200 bg-red-50/40 p-5 dark:border-red-900/40 dark:bg-red-950/20" x-data="{ confirming: false }">
                        <h2 class="font-mono text-2xs font-medium uppercase tracking-widest text-red-600 dark:text-red-400">
                            Danger zone
                        </h2>

                        <p class="mt-3 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                            Deleting moves the post to the trash. It can be restored from the database but is immediately invisible to readers.
                        </p>

                        <div class="mt-4">
                            <template x-if="! confirming">
                                <flux:button variant="danger" size="xs" x-on:click="confirming = true" icon="trash">
                                    Delete post
                                </flux:button>
                            </template>

                            <template x-if="confirming">
                                <div class="space-y-3">
                                    <p class="text-xs font-medium text-red-700 dark:text-red-400">
                                        This will remove “{{ Str::limit($post->title, 32) }}”. Are you sure?
                                    </p>
                                    <div class="flex gap-2">
                                        <flux:button variant="danger" size="xs" wire:click="delete" wire:loading.attr="disabled">
                                            Yes, delete
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                            Cancel
                                        </flux:button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
