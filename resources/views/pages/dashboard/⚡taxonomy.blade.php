<?php

use App\Concerns\HasSlug;
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
new #[Layout('layouts::app.dashboard')] #[Title('Taxonomy')] class extends Component
{
    use HasSlug;

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
                'slug' => $this->generateUniqueSlug($name),
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
    <x-app.ui.header
        eyebrow="Content"
        title="Taxonomy"
        description="Categories are broad topics a reader browses; tags are specifics they search. A post has one category and any number of tags."
        :breadcrumbs="[['label' => __('Content')], ['label' => __('Taxonomy')]]"
    />

    <div class="grid gap-6 px-5 py-8 sm:px-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:px-10">
        {{-- ============================================================= --
         | Categories
         --}}
        <section aria-labelledby="categories-heading" class="sui-card overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                <h2 id="categories-heading" class="text-sm font-semibold tracking-tight text-ink-900">
                    Categories
                </h2>
                <span class="text-2xs text-ink-500">
                    {{ $this->categories->count() }} total
                </span>
            </div>

            {{-- Form --}}
            <div class="space-y-3 border-b border-sui-hairline bg-sui-accent-softer p-5">
                <div>
                    <label for="category-name" class="sui-control-label">
                        {{ $this->isEditingCategory ? 'Rename category' : 'New category' }}
                    </label>
                    <input
                        id="category-name"
                        type="text"
                        wire:model="categoryName"
                        wire:keydown.enter.prevent="saveCategory"
                        placeholder="Architecture"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    @error('categoryName')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="category-description" class="sui-control-label">
                        Description
                    </label>
                    <textarea
                        id="category-description"
                        wire:model="categoryDescription"
                        rows="2"
                        placeholder="Shown on the blog index when this category is filtered."
                        class="sui-control mt-2 w-full resize-y text-xs leading-normal"
                    ></textarea>
                    @error('categoryDescription')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <span class="sui-control-label">
                        Accent
                    </span>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($this->palette() as $colour)
                            <button
                                type="button"
                                wire:click="$set('categoryColor', '{{ $colour['value'] }}')"
                                @class([
                                    'sui-focus rounded-full border px-2.5 py-1 text-xs font-medium transition duration-150 ease-soft',
                                    'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $categoryColor === $colour['value'],
                                    'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-sui-ink' => $categoryColor !== $colour['value'],
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

            {{-- List --}}
            @if ($this->categories->isEmpty())
                <div class="sui-empty">
                    <p class="text-sm text-ink-500">No categories yet.</p>
                </div>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->categories as $category)
                        <li
                            wire:key="category-{{ $category->id }}"
                            @class([
                                'group flex items-start gap-4 px-5 py-4 transition duration-150 ease-soft',
                                'bg-sui-accent-softer' => $editingCategoryId === $category->id,
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
                                    /* No colour chosen — the swatch reads as an
                                       empty slot, so it takes the neutral border. */
                                    'bg-sui-border' => blank($category->color),
                                ])
                                aria-hidden="true"
                            ></span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink-900">
                                    {{ $category->name }}
                                </p>
                                <p class="mt-0.5 text-2xs text-ink-500">
                                    /{{ $category->slug }} · {{ $category->posts_count }} {{ Str::plural('post', $category->posts_count) }}
                                </p>

                                @if ($category->description)
                                    <p class="mt-1.5 line-clamp-2 text-xs leading-normal text-ink-500">
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
                                        class="!text-sui-danger"
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
        <section aria-labelledby="tags-heading" class="sui-card overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                <h2 id="tags-heading" class="text-sm font-semibold tracking-tight text-ink-900">
                    Tags
                </h2>
                <span class="text-2xs text-ink-500">
                    {{ $this->tags->count() }} total
                </span>
            </div>

            {{-- Bulk create --}}
            <div class="border-b border-sui-hairline bg-sui-accent-softer p-5">
                <label for="tag-names" class="sui-control-label">
                    Add tags
                </label>

                <textarea
                    id="tag-names"
                    wire:model="tagNames"
                    rows="2"
                    placeholder="One per line, or comma separated: caching, queues, redis"
                    class="sui-control mt-2 w-full resize-y"
                ></textarea>

                @error('tagNames')
                    <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                @enderror

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <flux:button variant="primary" size="sm" wire:click="createTags" wire:loading.attr="disabled" icon="plus">
                        Add tags
                    </flux:button>
                    <span class="text-2xs text-ink-500">
                        Existing tags are skipped, not duplicated.
                    </span>
                </div>
            </div>

            {{-- List --}}
            @if ($this->tags->isEmpty())
                <div class="sui-empty">
                    <p class="text-sm text-ink-500">No tags yet.</p>
                </div>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->tags as $tag)
                        <li wire:key="tag-{{ $tag->id }}" class="sui-row flex items-center gap-4">
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
                                <div class="h-1 flex-1 overflow-hidden rounded-full bg-sui-hover">
                                    <div
                                        class="h-full rounded-full bg-sui-accent"
                                        style="width: {{ $this->tags->max('posts_count') > 0 ? max(4, ($tag->posts_count / $this->tags->max('posts_count')) * 100) : 4 }}%"
                                    ></div>
                                </div>
                            </div>

                            <span class="shrink-0 text-2xs tabular-nums text-ink-500">
                                {{ $tag->posts_count }}
                            </span>

                            <div class="shrink-0" x-data="{ confirming: false }">
                                <template x-if="! confirming">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        x-on:click="confirming = true"
                                        icon="trash"
                                        class="!text-ink-400 hover:!text-sui-danger"
                                    >
                                        <span class="sr-only">Delete {{ $tag->name }}</span>
                                    </flux:button>
                                </template>

                                <template x-if="confirming">
                                    <div class="flex items-center gap-1">
                                        <span class="text-2xs text-sui-danger">Sure?</span>
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
