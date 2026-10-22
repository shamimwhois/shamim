<?php

use App\Actions\Ideas\DeleteIdea;
use App\Actions\Ideas\SaveIdea;
use App\Actions\Ideas\ToggleIdeaPin;
use App\Enums\AccountPermission;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Ideas workspace home.
 *
 * Capture, review, and the two bands an author actually returns to: what is
 * pinned and what was last touched. Everything else is behind the filter row,
 * because a home page that lists the entire inventory is an index with extra
 * steps.
 *
 * Ownership and permission are two different limits and the page applies both.
 * `ideas:use` decides who may open it, which the route group enforces on the GET
 * and the `boot()` below re-enforces on every subsequent POST: Livewire mutations
 * go to `/livewire/update`, not to `/ideas/dashboard`, so the route middleware
 * never runs for them. `authoredBy()` then decides which rows appear, and that is
 * a scoping rule, not a security one — an account here sees its own pile, never
 * anybody else's.
 *
 * Every write goes through an action class. The screen is a form; the invariants
 * (slug uniqueness, the status workflow, the publish conjunction) belong to the
 * model and its actions, so a hand-crafted request meets the same rules as the
 * button.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Ideas')] class extends Component
{
    use WithPagination;

    /**
     * Quick capture. A second, smaller copy of the create form.
     */
    #[Validate('required|string|max:'.Idea::TITLE_MAX)]
    public string $captureTitle = '';

    #[Validate('nullable|string|max:'.Idea::SUMMARY_MAX)]
    public string $captureSummary = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * `all` keeps archived rows in view; the default hides them, because an
     * archived idea is a decision that was already made.
     */
    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::UseIdeas), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Ideas',
            'description' => 'Capture and shape your ideas.',
            'noindex' => true,
        ]);
    }

    /**
     * Reset the list when a filter changes, so page 3 of a stale query never
     * follows a new one.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'tab'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Capture an idea without leaving the home page.
     *
     * The whole point of the module is that the thought is cheaper to write down
     * than to lose, so the capture path is two fields and one keystroke: enter
     * saves. The richer fields — content, category, priority, potential — are
     * deliberately absent here and available on the edit screen.
     */
    public function capture(SaveIdea $saveIdea): void
    {
        $this->validateOnly('captureTitle');
        $this->validateOnly('captureSummary');

        $idea = $saveIdea->handle(auth()->user(), [
            'title' => $this->captureTitle,
            'summary' => $this->captureSummary ?: null,
        ]);

        $this->reset('captureTitle', 'captureSummary');
        $this->resetValidation();

        unset($this->ideas, $this->pinned, $this->metrics);

        Flux::toast(variant: 'success', text: __('Idea captured.'));

        $this->redirectRoute('ideas.edit', ['idea' => $idea->slug], navigate: true);
    }

    /**
     * Pin or unpin an idea.
     */
    public function togglePin(int $ideaId, ToggleIdeaPin $togglePin): void
    {
        $idea = $this->findOwnedIdea($ideaId);

        $this->authorize('pin', $idea);

        $idea = $togglePin->handle($idea);

        unset($this->ideas, $this->pinned, $this->metrics);

        Flux::toast(
            variant: 'success',
            text: $idea->is_pinned ? __('Pinned.') : __('Unpinned.'),
        );
    }

    /**
     * Delete an idea. The UI makes this a two-step confirm, so there is no modal
     * to dismiss by accident.
     */
    public function delete(int $ideaId, DeleteIdea $deleteIdea): void
    {
        $idea = $this->findOwnedIdea($ideaId);

        $this->authorize('delete', $idea);

        $deleteIdea->handle($idea);

        unset($this->ideas, $this->pinned, $this->metrics);

        Flux::toast(variant: 'success', text: __('Idea deleted.'));
    }

    /**
     * Resolve an idea by id, scoped to the signed-in account.
     *
     * Scoping the lookup rather than leaning on the policy alone means a foreign
     * id 404s instead of 403ing, which does not confirm that the row exists.
     */
    protected function findOwnedIdea(int $ideaId): Idea
    {
        return Idea::query()
            ->authoredBy(auth()->user())
            ->findOrFail($ideaId);
    }

    /**
     * Counts for the metric tiles.
     *
     * Four separate counts rather than one aggregate query: the default
     * connection is SQLite, which does not speak the SQL Server cast syntax an
     * inline aggregate would need, and four indexed COUNTs on a small result set
     * are cheaper to read than one portable-but-clever expression.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function metrics(): array
    {
        $author = auth()->user();

        return [
            'total' => Idea::query()->authoredBy($author)->count(),
            'inbox' => Idea::query()->authoredBy($author)->inStatus(IdeaStatus::Inbox)->count(),
            'approved' => Idea::query()->authoredBy($author)->inStatus(IdeaStatus::Approved)->count(),
            'pinned' => Idea::query()->authoredBy($author)->where('is_pinned', true)->count(),
        ];
    }

    /**
     * The account's pinned ideas.
     *
     * @return Collection<int, Idea>
     */
    #[Computed]
    public function pinned(): Collection
    {
        return Idea::query()
            ->authoredBy(auth()->user())
            ->where('is_pinned', true)
            ->orderByDesc('created_at')
            ->limit((int) config('ideas.dashboard.pinned'))
            ->with('category')
            ->get();
    }

    /**
     * The filtered list of this account's ideas.
     *
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with('category')
            ->pinned()
            ->paginate((int) config('ideas.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * The list query with the active filter applied.
     *
     * @return Builder<Idea>
     */
    protected function filteredQuery(): Builder
    {
        $query = Idea::query()
            ->authoredBy(auth()->user())
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search));

        // The tab is a URL parameter, so it may say anything. An unrecognised
        // value is treated as `all` rather than as an error.
        (match ($this->tab) {
            'inbox' => fn (Builder $query) => $query->inStatus(IdeaStatus::Inbox),
            'review' => fn (Builder $query) => $query->inStatus(IdeaStatus::UnderReview),
            'approved' => fn (Builder $query) => $query->inStatus(IdeaStatus::Approved),
            'archived' => fn (Builder $query) => $query->inStatus(IdeaStatus::Archived),
            'open' => fn (Builder $query) => $query->open(),
            default => fn (Builder $query) => $query,
        })($query);

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'open' => __('Open'),
            'inbox' => __('Inbox'),
            'review' => __('In review'),
            'approved' => __('Approved'),
            'archived' => __('Archived'),
            'all' => __('Everything'),
        ];
    }
}; ?>

<div>
    <x-app.ui.body>
        {{-- ============================================================= --
         | Greeting
         --}}
        <x-app.ui.welcome-card
            eyebrow="Ideas"
            title="What are you thinking about?"
            description="Write it down before it goes. An idea starts as a line of text and becomes a plan when you decide it is worth one."
            icon="light-bulb"
        >
            <x-slot:actions>
                <flux:button :href="route('ideas.create')" wire:navigate variant="primary" icon="plus">
                    {{ __('New idea') }}
                </flux:button>
                <flux:button :href="route('ideas.plans')" wire:navigate variant="ghost" icon="queue-list">
                    {{ __('Plans') }}
                </flux:button>
                <flux:button :href="route('ideas.categories')" wire:navigate variant="ghost" icon="folder">
                    {{ __('Categories') }}
                </flux:button>
            </x-slot:actions>
        </x-app.ui.welcome-card>

        {{-- ============================================================= --
         | Counts
         --}}
        <section aria-labelledby="ideas-metrics-heading">
            <h2 id="ideas-metrics-heading" class="sr-only">{{ __('Idea counts') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-app.ui.stat
                    :label="__('Captured')"
                    :value="$this->metrics['total'] ?? 0"
                    icon="light-bulb"
                    tone="primary"
                />
                <x-app.ui.stat
                    :label="__('In the inbox')"
                    :value="$this->metrics['inbox'] ?? 0"
                    icon="inbox"
                    tone="info"
                    :hint="__('Nothing decided about them yet.')"
                />
                <x-app.ui.stat
                    :label="__('Approved')"
                    :value="$this->metrics['approved'] ?? 0"
                    icon="check-circle"
                    tone="success"
                    :hint="__('Ready to publish or turn into a plan.')"
                />
                <x-app.ui.stat
                    :label="__('Pinned')"
                    :value="$this->metrics['pinned'] ?? 0"
                    icon="hashtag"
                    tone="secondary"
                />
            </div>
        </section>

        {{-- ============================================================= --
         | Quick capture
         --}}
        <x-app.ui.section
            :title="__('Capture something')"
            :description="__('Two fields. Everything else can wait until the idea is worth more than a sentence.')"
            icon="pencil"
        >
            <form wire:submit="capture" class="space-y-4">
                <div>
                    <label for="capture-title" class="sui-control-label">{{ __('Title') }}</label>
                    <input
                        id="capture-title"
                        type="text"
                        wire:model="captureTitle"
                        wire:keydown.enter.prevent="capture"
                        placeholder="{{ __('A one-line summary of the thought') }}"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    @error('captureTitle')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="capture-summary" class="sui-control-label">{{ __('Why it came to mind') }}</label>
                    <textarea
                        id="capture-summary"
                        wire:model="captureSummary"
                        rows="2"
                        placeholder="{{ __('Optional. A sentence is enough to remember it by.') }}"
                        class="sui-control mt-2 w-full resize-y text-xs leading-normal"
                    ></textarea>
                    @error('captureSummary')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <flux:button type="submit" variant="primary" size="sm" icon="plus" wire:loading.attr="disabled">
                    {{ __('Capture') }}
                </flux:button>
            </form>
        </x-app.ui.section>

        {{-- ============================================================= --
         | Pinned
         --}}
        <x-app.ui.section
            :title="__('Pinned')"
            :description="__('The ideas you keep coming back to.')"
            icon="hashtag"
        >
            @if ($this->pinned->isEmpty())
                <p class="text-sm leading-normal text-ink-500">
                    {{ __('Nothing pinned yet. Pin an idea from the list below and it stays at the top of this page.') }}
                </p>
            @else
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->pinned as $idea)
                        <x-app.ideas.card
                            :idea="$idea"
                            :href="route('ideas.edit', ['idea' => $idea->slug])"
                            wire:key="pinned-{{ $idea->id }}"
                        />
                    @endforeach
                </div>
            @endif
        </x-app.ui.section>

        {{-- ============================================================= --
         | The inventory
         --}}
        <x-app.ui.section
            :padded="false"
            :title="__('All ideas')"
            :description="__('Filtered, searchable, and only ever your own.')"
        >
            <div class="border-b border-sui-hairline px-5 py-4">
                <div class="flex flex-wrap items-center gap-3">
                    <label for="ideas-search" class="sr-only">{{ __('Search ideas') }}</label>
                    <div class="relative min-w-0 flex-1 sm:max-w-sm">
                        <span aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400">
                            <flux:icon name="magnifying-glass" class="size-4" />
                        </span>
                        <input
                            id="ideas-search"
                            type="search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="{{ __('Search titles and text') }}"
                            class="sui-control w-full pl-9"
                        />
                    </div>

                    <p class="font-mono text-2xs uppercase tracking-widest text-ink-400" aria-live="polite">
                        {{ trans_choice(':count idea|:count ideas', $this->ideas->total(), ['count' => $this->ideas->total()]) }}
                    </p>
                </div>

                <div class="mt-4 flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter ideas') }}">
                    @foreach ($this->tabs() as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            wire:key="tab-{{ $key }}"
                            aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            wire:click="$set('tab', '{{ $key }}')"
                            @class([
                                'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition duration-150 ease-soft',
                                'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $tab === $key,
                                'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $tab !== $key,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($this->ideas->isEmpty())
                <div class="sui-empty">
                    @if ($search !== '')
                        <p class="text-sm text-ink-500">
                            {{ __('No idea of yours matches :term.', ['term' => $search]) }}
                        </p>
                    @else
                        <p class="text-sm text-ink-500">
                            {{ __('Nothing in this view. Capture an idea above, or choose a different tab.') }}
                        </p>
                    @endif
                </div>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->ideas as $idea)
                        <li wire:key="idea-{{ $idea->id }}" class="px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <a
                                        href="{{ route('ideas.edit', ['idea' => $idea->slug]) }}"
                                        wire:navigate
                                        class="text-sm font-semibold tracking-tight text-ink-900 transition-colors hover:text-sui-accent"
                                    >
                                        {{ $idea->title }}
                                    </a>

                                    @if (filled($idea->excerpt()))
                                        <p class="mt-1 line-clamp-2 text-xs leading-normal text-ink-500">
                                            {{ $idea->excerpt() }}
                                        </p>
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-app.ideas.status-badge :status="$idea->status" size="sm" />

                                        @if ($idea->priority !== null)
                                            <flux:badge :color="$idea->priority->badgeColor()" size="sm">
                                                {{ $idea->priority->label() }}
                                            </flux:badge>
                                        @endif

                                        @if ($idea->category !== null)
                                            <span class="text-2xs text-ink-500">{{ $idea->category->name }}</span>
                                        @endif

                                        @if ($idea->isPublished())
                                            <a
                                                href="{{ route('ideas.show', ['idea' => $idea->slug]) }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1 text-2xs text-sui-accent hover:underline"
                                            >
                                                <flux:icon name="globe-alt" class="size-3.5" />
                                                {{ __('Live') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-1" x-data="{ confirming: false }">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        wire:click="togglePin({{ $idea->id }})"
                                        icon="bookmark"
                                        @class(['!text-sui-accent' => $idea->is_pinned])
                                    >
                                        <span class="sr-only">
                                            {{ $idea->is_pinned ? __('Unpin') : __('Pin') }}
                                        </span>
                                    </flux:button>

                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        :href="route('ideas.edit', ['idea' => $idea->slug])"
                                        wire:navigate
                                        icon="pencil-square"
                                    >
                                        <span class="sr-only">{{ __('Edit') }}</span>
                                    </flux:button>

                                    <template x-if="! confirming">
                                        <flux:button
                                            variant="ghost"
                                            size="xs"
                                            x-on:click="confirming = true"
                                            icon="trash"
                                            class="!text-sui-danger"
                                        >
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </flux:button>
                                    </template>

                                    <template x-if="confirming">
                                        <div class="flex items-center gap-1">
                                            <flux:button
                                                variant="danger"
                                                size="xs"
                                                wire:click="delete({{ $idea->id }})"
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
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="px-5 pb-5">
                    <x-app.ui.pagination :paginator="$this->ideas" label="{{ __('Ideas pagination') }}" />
                </div>
            @endif
        </x-app.ui.section>
    </x-app.ui.body>
</div>
