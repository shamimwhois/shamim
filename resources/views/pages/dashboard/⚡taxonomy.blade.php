<?php

use App\Models\Category;
use App\Models\Tag;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Taxonomy management: categories and tags.
 *
 * Both taxonomies are managed here rather than as separate pages: they are the
 * same concept at different resolutions, and the author switches between them
 * while filing a post, not on a schedule.
 *
 * Deletion rules differ and are enforced honestly:
 *   - A category in use cannot be deleted; its posts are reassigned first.
 *   - A tag in use can be deleted; the pivot rows cascade and posts survive.
 */
new #[Layout('layouts::dashboard')] #[Title('Taxonomy')] class extends Component
{
    /**
     * Category form (create or edit).
     */
    public ?int $editingCategoryId = null;

    #[Validate('required|string|max:80')]
    public string $categoryName = '';

    #[Validate('nullable|string|max:500')]
    public string $categoryDescription = '';

    #[Validate('nullable|string|max:20')]
    public string $categoryColor = '';

    /**
     * Tag form (bulk create).
     */
    #[Validate('required|string|max:500')]
    public string $tagNames = '';

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Taxonomy',
            'description' => 'Manage categories and tags.',
            'noindex' => true,
        ]);
    }

    /**
     * Load a category into the form for editing.
     */
    public function editCategory(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->categoryName = $category->name;
        $this->categoryDescription = $category->description ?? '';
        $this->categoryColor = $category->color ?? '';

        unset($this->categories);
    }

    /**
     * Clear the category form.
     */
    public function resetCategoryForm(): void
    {
        $this->reset('editingCategoryId', 'categoryName', 'categoryDescription', 'categoryColor');
        $this->resetValidation();
    }

    /**
     * Create or update a category.
     */
    public function saveCategory(): void
    {
        $this->validateOnly('categoryName');
        $this->validateOnly('categoryDescription');
        $this->validateOnly('categoryColor');

        $name = trim($this->categoryName);

        // Names are unique; edits may keep their own name.
        $duplicate = Category::query()
            ->where('name', $name)
            ->when($this->editingCategoryId, fn ($query) => $query->whereKeyNot($this->editingCategoryId))
            ->exists();

        if ($duplicate) {
            $this->addError('categoryName', __('A category with that name already exists.'));

            return;
        }

        if ($this->editingCategoryId !== null) {
            $category = Category::query()->findOrFail($this->editingCategoryId);

            $category->update([
                'name' => $name,
                // A renamed category keeps its slug: published URLs must not
                // break because a label was reworded.
                'description' => $this->categoryDescription ?: null,
                'color' => $this->categoryColor ?: null,
            ]);

            Flux::toast(variant: 'success', text: __('Category updated.'));
        } else {
            Category::query()->create([
                'name' => $name,
                'slug' => Category::generateUniqueSlug($name),
                'description' => $this->categoryDescription ?: null,
                'color' => $this->categoryColor ?: null,
            ]);

            Flux::toast(variant: 'success', text: __('Category created.'));
        }

        $this->resetCategoryForm();

        unset($this->categories);
    }

    /**
     * Delete a category.
     *
     * Refuses when posts still reference it, rather than silently reassigning
     * them to nothing. The author decides where those posts belong.
     */
    public function deleteCategory(int $categoryId): void
    {
        $category = Category::query()->withCount('posts')->findOrFail($categoryId);

        if ($category->posts_count > 0) {
            Flux::toast(
                variant: 'danger',
                text: trans_choice(
                    'This category still has :count post. Move it first.|This category still has :count posts. Move them first.',
                    $category->posts_count,
                    ['count' => $category->posts_count],
                ),
            );

            return;
        }

        $category->delete();

        if ($this->editingCategoryId === $categoryId) {
            $this->resetCategoryForm();
        }

        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category deleted.'));
    }

    /**
     * Create tags from a comma- or newline-separated list.
     */
    public function createTags(): void
    {
        $this->validateOnly('tagNames');

        $names = collect(preg_split('/[\r\n,]+/', $this->tagNames) ?: [])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($names as $name) {
            $slug = str($name)->slug()->value();

            if ($slug === '') {
                $skipped++;

                continue;
            }

            $tag = Tag::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );

            $tag->wasRecentlyCreated ? $created++ : $skipped++;
        }

        $this->reset('tagNames');

        unset($this->tags);

        Flux::toast(
            variant: 'success',
            text: __(':created created, :skipped already existed.', ['created' => $created, 'skipped' => $skipped]),
        );
    }

    /**
     * Delete a tag.
     *
     * Safe to delete even when in use: the pivot cascades and the posts remain.
     */
    public function deleteTag(int $tagId): void
    {
        $tag = Tag::query()->withCount('posts')->findOrFail($tagId);

        $tag->delete();

        unset($this->tags);

        Flux::toast(variant: 'success', text: __('Tag deleted.'));
    }

    /**
     * All categories with post counts.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->ordered()
            ->withCount('posts')
            ->get();
    }

    /**
     * All tags with post counts, most used first.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->withCount('posts')
            ->orderByDesc('posts_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether the category form is editing an existing row.
     */
    #[Computed]
    public function isEditingCategory(): bool
    {
        return $this->editingCategoryId !== null;
    }

    /**
     * A small palette for category accents.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function palette(): array
    {
        return [
            ['value' => '', 'label' => 'None'],
            ['value' => 'teal', 'label' => 'Teal'],
            ['value' => 'cyan', 'label' => 'Cyan'],
            ['value' => 'violet', 'label' => 'Violet'],
            ['value' => 'rose', 'label' => 'Rose'],
            ['value' => 'amber', 'label' => 'Amber'],
            ['value' => 'lime', 'label' => 'Lime'],
        ];
    }
}; ?>

<div>
    <x-dashboard.header
        eyebrow="Content"
        title="Taxonomy"
        description="Categories are broad topics a reader browses; tags are specifics they search. A post has one category and any number of tags."
    />

    <div class="grid gap-6 px-6 py-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:px-10">
        {{-- ============================================================= --
         | Categories
         --}}
        <section aria-labelledby="categories-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
            <div class="flex items-center justify-between gap-4 border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                <h2 id="categories-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                    Categories
                </h2>
                <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                    {{ $this->categories->count() }} total
                </span>
            </div>

            {{-- Form --}}
            <div class="border-b border-ink-200 bg-ink-50/50 p-5 dark:border-ink-800 dark:bg-ink-950/40">
                <div class="space-y-3">
                    <div>
                        <label for="category-name" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            {{ $this->isEditingCategory ? 'Rename category' : 'New category' }}
                        </label>
                        <input
                            id="category-name"
                            type="text"
                            wire:model="categoryName"
                            wire:keydown.enter.prevent="saveCategory"
                            placeholder="Architecture"
                            autocomplete="off"
                            class="mt-2 w-full rounded-md border border-ink-200 bg-white px-3 py-2 text-sm text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-100 dark:placeholder:text-ink-600"
                        />
                        @error('categoryName')
                            <p class="mt-1.5 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="category-description" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            Description
                        </label>
                        <textarea
                            id="category-description"
                            wire:model="categoryDescription"
                            rows="2"
                            placeholder="Shown on the blog index when this category is filtered."
                            class="mt-2 w-full resize-y rounded-md border border-ink-200 bg-white px-3 py-2 text-xs leading-relaxed text-ink-800 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-200 dark:placeholder:text-ink-600"
                        ></textarea>
                        @error('categoryDescription')
                            <p class="mt-1.5 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <span class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                            Accent
                        </span>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($this->palette() as $colour)
                                <button
                                    type="button"
                                    wire:click="$set('categoryColor', '{{ $colour['value'] }}')"
                                    @class([
                                        'rounded-xs border px-2.5 py-1 font-mono text-2xs transition-colors',
                                        'border-signal-400 bg-signal-50 text-signal-700 dark:border-signal-600 dark:bg-signal-950 dark:text-signal-300' => $categoryColor === $colour['value'],
                                        'border-ink-200 text-ink-500 hover:border-ink-300 dark:border-ink-700 dark:text-ink-400' => $categoryColor !== $colour['value'],
                                    ])
                                >
                                    {{ $colour['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <flux:button variant="primary" size="sm" wire:click="saveCategory" wire:loading.attr="disabled" icon="check">
                            {{ $this->isEditingCategory ? 'Save changes' : 'Create category' }}
                        </flux:button>

                        @if ($this->isEditingCategory)
                            <flux:button variant="ghost" size="sm" wire:click="resetCategoryForm">
                                Cancel
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>

            {{-- List --}}
            @if ($this->categories->isEmpty())
                <div class="px-5 py-12 text-center">
                    <p class="text-sm text-ink-500 dark:text-ink-400">No categories yet.</p>
                </div>
            @else
                <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                    @foreach ($this->categories as $category)
                        <li
                            wire:key="category-{{ $category->id }}"
                            @class([
                                'group flex items-start gap-4 px-5 py-4 transition-colors',
                                'bg-signal-50/40 dark:bg-signal-950/20' => $editingCategoryId === $category->id,
                            ])
                        >
                            {{-- Colour swatch --}}
                            <span
                                @class([
                                    'mt-1 size-2.5 shrink-0 rounded-full',
                                    'bg-teal-500' => $category->color === 'teal',
                                    'bg-cyan-500' => $category->color === 'cyan',
                                    'bg-violet-500' => $category->color === 'violet',
                                    'bg-rose-500' => $category->color === 'rose',
                                    'bg-amber-500' => $category->color === 'amber',
                                    'bg-lime-500' => $category->color === 'lime',
                                    'bg-ink-300 dark:bg-ink-600' => blank($category->color),
                                ])
                                aria-hidden="true"
                            ></span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink-900 dark:text-ink-50">
                                    {{ $category->name }}
                                </p>
                                <p class="mt-0.5 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                    /{{ $category->slug }} · {{ $category->posts_count }} {{ Str::plural('post', $category->posts_count) }}
                                </p>

                                @if ($category->description)
                                    <p class="mt-1.5 line-clamp-2 text-xs leading-relaxed text-ink-500 dark:text-ink-400">
                                        {{ $category->description }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-1" x-data="{ confirming: false }">
                                <flux:button
                                    variant="ghost"
                                    size="xs"
                                    wire:click="editCategory({{ $category->id }})"
                                    icon="pencil-square"
                                >
                                    <span class="sr-only">Edit {{ $category->name }}</span>
                                </flux:button>

                                <template x-if="! confirming">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        x-on:click="confirming = true"
                                        icon="trash"
                                        class="!text-red-600 dark:!text-red-400"
                                    >
                                        <span class="sr-only">Delete {{ $category->name }}</span>
                                    </flux:button>
                                </template>

                                <template x-if="confirming">
                                    <div class="flex items-center gap-1">
                                        <flux:button
                                            variant="danger"
                                            size="xs"
                                            wire:click="deleteCategory({{ $category->id }})"
                                            wire:loading.attr="disabled"
                                        >
                                            Confirm
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                            No
                                        </flux:button>
                                    </div>
                                </template>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ============================================================= --
         | Tags
         --}}
        <section aria-labelledby="tags-heading" class="rounded-lg border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
            <div class="flex items-center justify-between gap-4 border-b border-ink-200 px-5 py-4 dark:border-ink-800">
                <h2 id="tags-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                    Tags
                </h2>
                <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                    {{ $this->tags->count() }} total
                </span>
            </div>

            {{-- Bulk create --}}
            <div class="border-b border-ink-200 bg-ink-50/50 p-5 dark:border-ink-800 dark:bg-ink-950/40">
                <label for="tag-names" class="font-mono text-2xs font-medium uppercase tracking-widest text-ink-400 dark:text-ink-500">
                    Add tags
                </label>

                <textarea
                    id="tag-names"
                    wire:model="tagNames"
                    rows="2"
                    placeholder="One per line, or comma separated: caching, queues, redis"
                    class="mt-2 w-full resize-y rounded-md border border-ink-200 bg-white px-3 py-2 text-sm text-ink-900 placeholder:text-ink-400 focus:border-signal-500 focus:outline-none focus:ring-2 focus:ring-signal-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-ink-100 dark:placeholder:text-ink-600"
                ></textarea>

                @error('tagNames')
                    <p class="mt-1.5 font-mono text-2xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="mt-3 flex items-center gap-3">
                    <flux:button variant="primary" size="sm" wire:click="createTags" wire:loading.attr="disabled" icon="plus">
                        Add tags
                    </flux:button>
                    <span class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                        Existing tags are skipped, not duplicated.
                    </span>
                </div>
            </div>

            {{-- List --}}
            @if ($this->tags->isEmpty())
                <div class="px-5 py-12 text-center">
                    <p class="text-sm text-ink-500 dark:text-ink-400">No tags yet.</p>
                </div>
            @else
                <ul class="divide-y divide-ink-200 dark:divide-ink-800">
                    @foreach ($this->tags as $tag)
                        <li wire:key="tag-{{ $tag->id }}" class="flex items-center gap-4 px-5 py-3">
                            <a
                                href="{{ route('blog.index', ['tag' => $tag->slug]) }}"
                                target="_blank"
                                rel="noopener"
                                class="tag-pill"
                            >
                                {{ $tag->name }}
                            </a>

                            {{-- Usage bar: visual weight proportional to use --}}
                            <div class="hidden min-w-0 flex-1 items-center gap-2 sm:flex">
                                <div class="h-1 flex-1 overflow-hidden rounded-full bg-ink-100 dark:bg-ink-800">
                                    <div
                                        class="h-full rounded-full bg-signal-400 dark:bg-signal-600"
                                        style="width: {{ $this->tags->max('posts_count') > 0 ? max(4, ($tag->posts_count / $this->tags->max('posts_count')) * 100) : 4 }}%"
                                    ></div>
                                </div>
                            </div>

                            <span class="shrink-0 font-mono text-2xs tabular-nums text-ink-400 dark:text-ink-500">
                                {{ $tag->posts_count }}
                            </span>

                            <div class="shrink-0" x-data="{ confirming: false }">
                                <template x-if="! confirming">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        x-on:click="confirming = true"
                                        icon="trash"
                                        class="!text-ink-400 hover:!text-red-600 dark:hover:!text-red-400"
                                    >
                                        <span class="sr-only">Delete {{ $tag->name }}</span>
                                    </flux:button>
                                </template>

                                <template x-if="confirming">
                                    <div class="flex items-center gap-1">
                                        <span class="font-mono text-2xs text-red-600 dark:text-red-400">Sure?</span>
                                        <flux:button
                                            variant="danger"
                                            size="xs"
                                            wire:click="deleteTag({{ $tag->id }})"
                                            wire:loading.attr="disabled"
                                        >
                                            Delete
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                            No
                                        </flux:button>
                                    </div>
                                </template>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
