<?php

use App\Actions\Ideas\DeleteIdeaCategory;
use App\Actions\Ideas\SaveIdeaCategory;
use App\Enums\AccountPermission;
use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The signed-in account's idea categories.
 *
 * Categories are per account, not global. The blog's `Category` is navigation a
 * stranger browses; this is a private filing system, and letting one account's
 * "Q3 experiments" appear in another's picker would leak what somebody is
 * working on through a dropdown. The consequence is stated where it is felt: a
 * category is private, so the public index has no category filter, only search.
 *
 * Deleting a folder does not delete what is in it. `idea_categories` is joined to
 * ideas with `nullOnDelete`, so the ideas survive the move back to uncategorised,
 * and the count on each row is the warning before you do it.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Idea categories')] class extends Component
{
    public ?int $editingCategoryId = null;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public string $description = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::UseIdeas), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Idea categories',
            'description' => 'File ideas into your own folders.',
            'noindex' => true,
        ]);
    }

    /**
     * Load a category into the form.
     */
    public function edit(int $categoryId): void
    {
        $category = $this->findOwnedCategory($categoryId);

        $this->editingCategoryId = $category->id;
        $this->name = $category->name;
        $this->description = (string) $category->description;

        unset($this->categories);
    }

    public function resetForm(): void
    {
        $this->reset('editingCategoryId', 'name', 'description');
        $this->resetValidation();
    }

    /**
     * Create or rename a category.
     */
    public function save(SaveIdeaCategory $saveCategory): void
    {
        $this->validateOnly('name');
        $this->validateOnly('description');

        try {
            $saveCategory->handle(auth()->user(), [
                'name' => $this->name,
                'description' => $this->description ?: null,
            ], $this->editingCategoryId === null ? null : $this->findOwnedCategory($this->editingCategoryId));
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->resetForm();

        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    /**
     * Delete a category, leaving its ideas in place.
     */
    public function delete(int $categoryId, DeleteIdeaCategory $deleteCategory): void
    {
        $category = $this->findOwnedCategory($categoryId);

        $this->authorize('delete', $category);

        $deleteCategory->handle($category);

        if ($this->editingCategoryId === $categoryId) {
            $this->resetForm();
        }

        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category deleted. Its ideas are still there, uncategorised.'));
    }

    /**
     * Resolve a category by id, scoped to the signed-in account.
     */
    protected function findOwnedCategory(int $categoryId): IdeaCategory
    {
        return IdeaCategory::query()
            ->authoredBy(auth()->user())
            ->findOrFail($categoryId);
    }

    /**
     * @return Collection<int, IdeaCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return IdeaCategory::query()
            ->authoredBy(auth()->user())
            ->withCount('ideas')
            ->get();
    }

    /**
     * How many of the account's ideas are not filed anywhere.
     */
    #[Computed]
    public function uncategorised(): int
    {
        return Idea::query()
            ->authoredBy(auth()->user())
            ->whereNull('idea_category_id')
            ->count();
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Ideas"
        title="Categories"
        description="Your own filing system. Nobody else sees these names, and a category never moves an idea."
        :breadcrumbs="[['label' => __('Ideas'), 'href' => route('ideas.dashboard')], ['label' => __('Categories')]]"
    />

    <div class="grid gap-6 px-5 py-8 sm:px-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:px-10">
        {{-- ============================================================= --
         | Form
         --}}
        <section aria-labelledby="category-form-heading" class="sui-card h-fit overflow-hidden">
            <div class="border-b border-sui-hairline px-5 py-4">
                <h2 id="category-form-heading" class="text-sm font-semibold tracking-tight text-ink-900">
                    {{ $this->editingCategoryId === null ? __('New category') : __('Rename category') }}
                </h2>
            </div>

            <form wire:submit="save" class="space-y-4 p-5">
                <div>
                    <label for="category-name" class="sui-control-label">{{ __('Name') }}</label>
                    <input
                        id="category-name"
                        type="text"
                        wire:model="name"
                        placeholder="{{ __('Platform experiments') }}"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    @error('name')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="category-description" class="sui-control-label">{{ __('Description') }}</label>
                    <textarea
                        id="category-description"
                        wire:model="description"
                        rows="3"
                        placeholder="{{ __('Optional. What belongs in here?') }}"
                        class="sui-control mt-2 w-full resize-y text-xs leading-normal"
                    ></textarea>
                    @error('description')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-2">
                    <flux:button type="submit" variant="primary" size="sm" icon="check" wire:loading.attr="disabled">
                        {{ $this->editingCategoryId === null ? __('Create') : __('Save changes') }}
                    </flux:button>

                    @if ($this->editingCategoryId !== null)
                        <flux:button type="button" variant="ghost" size="sm" wire:click="resetForm">
                            {{ __('Cancel') }}
                        </flux:button>
                    @endif
                </div>
            </form>
        </section>

        {{-- ============================================================= --
         | List
         --}}
        <section aria-labelledby="category-list-heading" class="sui-card overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                <h2 id="category-list-heading" class="text-sm font-semibold tracking-tight text-ink-900">
                    {{ __('Your categories') }}
                </h2>
                <span class="text-2xs text-ink-500">
                    {{ $this->categories->count() }} total · {{ $this->uncategorised }} {{ __('uncategorised') }}
                </span>
            </div>

            @if ($this->categories->isEmpty())
                <div class="sui-empty">
                    <p class="text-sm text-ink-500">
                        {{ __('No categories yet. Ideas work perfectly well without them; a category only earns its place once the pile is hard to scan.') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->categories as $category)
                        <li
                            wire:key="category-{{ $category->id }}"
                            @class([
                                'flex items-start gap-4 px-5 py-4 transition duration-150 ease-soft',
                                'bg-sui-accent-softer' => $editingCategoryId === $category->id,
                            ])
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink-900">{{ $category->name }}</p>
                                <p class="mt-0.5 text-2xs text-ink-500">
                                    /{{ $category->slug }} ·
                                    {{ trans_choice(':count idea|:count ideas', $category->ideas_count, ['count' => $category->ideas_count]) }}
                                </p>

                                @if (filled($category->description))
                                    <p class="mt-1.5 line-clamp-2 text-xs leading-normal text-ink-500">
                                        {{ $category->description }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-1" x-data="{ confirming: false }">
                                <flux:button
                                    variant="ghost"
                                    size="xs"
                                    wire:click="edit({{ $category->id }})"
                                    icon="pencil-square"
                                >
                                    <span class="sr-only">{{ __('Edit :name', ['name' => $category->name]) }}</span>
                                </flux:button>

                                <template x-if="! confirming">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        x-on:click="confirming = true"
                                        icon="trash"
                                        class="!text-sui-danger"
                                    >
                                        <span class="sr-only">{{ __('Delete :name', ['name' => $category->name]) }}</span>
                                    </flux:button>
                                </template>

                                <template x-if="confirming">
                                    <div class="flex items-center gap-1">
                                        <flux:button
                                            variant="danger"
                                            size="xs"
                                            wire:click="delete({{ $category->id }})"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ __('Delete') }}
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                            {{ __('Keep') }}
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
