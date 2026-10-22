<?php

use App\Actions\Ideas\DeleteIdea;
use App\Enums\AccountPermission;
use App\Enums\IdeaStatus;
use App\Models\Idea;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ideas — the platform-wide console.
 *
 * The workspace at `/ideas/dashboard` shows one account's thinking. This shows all of
 * it, which is the only reason the screen exists: an operator needs to answer "what is
 * out there, and what should not be". Everything else about ideas is the author's
 * business, and that boundary is enforced rather than implied by the layout:
 *
 *   - `IdeaPolicy::update()` is authors only, so there is no edit action here at all.
 *     Reading across accounts is what moderating needs; rewriting somebody's plan for
 *     them is not.
 *   - `IdeaPolicy::delete()` does allow `admin:access`, so removal is offered, and each
 *     one re-authorises against the row it resolves rather than against the list that
 *     rendered the button.
 *
 * The `boot()` guard is not decoration. Mutations are POSTs to `/livewire/update`, which
 * never pass through the `permission:admin:access` on the route, so without it a
 * non-admin with a hand-built request would be issuing the delete.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Ideas — administration')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'tab', except: 'all')]
    public string $tab = 'all';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::AccessAdminPanel), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Ideas — administration',
            'description' => 'Review what every account has captured, and take down what should not be public.',
            'noindex' => true,
        ]);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    /**
     * Take an idea down on behalf of the platform.
     *
     * The row is resolved by id and then re-authorised, so a list rendered a minute ago
     * cannot be used to delete something that has since changed hands or been closed.
     */
    public function remove(int $ideaId, DeleteIdea $deleteIdea): void
    {
        $idea = Idea::query()->find($ideaId);

        if ($idea === null) {
            abort(404);
        }

        $this->authorize('delete', $idea);

        $deleteIdea->handle($idea);

        unset($this->ideas, $this->metrics);

        Flux::toast(variant: 'success', text: __('Idea removed.'));
    }

    /**
     * The stage tabs, in workflow order.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return collect(IdeaStatus::consoleCases())
            ->mapWithKeys(fn (IdeaStatus $status) => [$status->value => $status->label()])
            ->merge(['all' => __('Everything')])
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, Idea>
     */
    #[Computed]
    public function ideas(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['user:id,name,email', 'category:id,name', 'plan:id,idea_id,title,slug'])
            ->recentlyTouched()
            ->paginate(config('ideas.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * Counts for the header strip.
     *
     * Only two numbers here are worth a glance: how much of this is live on the public
     * site, and how much is still waiting for a decision. The rest of the metrics a
     * console usually shows would be counts of the same rows the tabs already filter.
     *
     * @return array{total: int, published: int, awaiting: int}
     */
    #[Computed]
    public function metrics(): array
    {
        return [
            'total' => Idea::query()->count(),
            'published' => Idea::query()->published()->count(),
            'awaiting' => Idea::query()->inStatus(IdeaStatus::UnderReview)->count(),
        ];
    }

    /**
     * @return Builder<Idea>
     */
    protected function filteredQuery(): Builder
    {
        $query = Idea::query()
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search));

        $status = IdeaStatus::tryFrom($this->tab);

        if ($status !== null) {
            $query->inStatus($status);
        }

        return $query;
    }
}; ?>

<div>
    <x-app.ui.header
        :eyebrow="__('Administration')"
        :title="__('Ideas')"
        :description="__('Every idea on the platform, as its author left it. Reading is open to you; rewriting is not.')"
        :breadcrumbs="[
            ['label' => __('Administration'), 'href' => route('admin.dashboard')],
            ['label' => __('Ideas')],
        ]"
    />

    <div class="px-5 pt-6 sm:px-6 lg:px-10">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-app.ui.stat
                :label="__('Captured')"
                :value="$this->metrics['total'] ?? 0"
                icon="light-bulb"
                tone="primary"
            />
            <x-app.ui.stat
                :label="__('Live on the site')"
                :value="$this->metrics['published'] ?? 0"
                icon="eye"
                tone="success"
                :hint="__('Approved and published by their authors.')"
            />
            <x-app.ui.stat
                :label="__('Waiting for a decision')"
                :value="$this->metrics['awaiting'] ?? 0"
                icon="clock"
                tone="attention"
                :hint="__('Ideas their authors have put under review.')"
            />
        </div>
    </div>

    <x-app.ui.section :padded="false" class="mt-6">
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
                        placeholder="{{ __('Search titles, summaries and notes') }}"
                        class="sui-control w-full pl-9"
                    />
                </div>

                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400" aria-live="polite">
                    {{ trans_choice(':count idea|:count ideas', $this->ideas->total(), ['count' => $this->ideas->total()]) }}
                </p>
            </div>

            <div class="mt-4 flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter by stage') }}">
                @foreach ($this->tabs() as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:key="idea-tab-{{ $key }}"
                        aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        wire:click="$set('tab', '{{ $key }}')"
                        @class([
                            'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition-all duration-150 ease-soft',
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
                <p class="text-base font-semibold tracking-tight text-ink-900">
                    {{ $search !== '' || $tab !== 'all' ? __('Nothing matches those filters') : __('Nobody has captured an idea yet') }}
                </p>
                <p class="mt-1.5 text-sm text-ink-500">
                    @if ($search !== '' || $tab !== 'all')
                        {{ __('Try another stage, or clear the search.') }}
                    @else
                        {{ __('The workspace at /ideas/dashboard is where the first ones come from.') }}
                    @endif
                </p>
            </div>
        @else
            <ul class="divide-y divide-sui-hairline">
                @foreach ($this->ideas as $idea)
                    <li wire:key="idea-{{ $idea->id }}" class="px-5 py-4">
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold tracking-tight text-ink-900">
                                        {{ $idea->title }}
                                    </p>

                                    <x-app.ideas.status-badge :status="$idea->status" size="sm" />

                                    @if ($idea->priority !== null)
                                        <flux:badge :color="$idea->priority->badgeColor()" size="sm">
                                            {{ $idea->priority->label() }}
                                        </flux:badge>
                                    @endif

                                    @if ($idea->isPublished())
                                        <flux:badge :color="$idea->visibility->badgeColor()" size="sm">
                                            {{ __('Public') }}
                                        </flux:badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-xs leading-normal text-ink-500">
                                    {{ $idea->excerpt(160) }}
                                </p>

                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-ink-500">
                                    <span class="inline-flex items-center gap-1">
                                        <flux:icon name="user" class="size-3" />
                                        {{ $idea->user?->name ?? __('Deleted account') }}
                                        <span class="text-ink-400">{{ $idea->user?->email }}</span>
                                    </span>

                                    @if ($idea->category !== null)
                                        <span class="inline-flex items-center gap-1">
                                            <flux:icon name="folder" class="size-3" />
                                            {{ $idea->category->name }}
                                        </span>
                                    @endif

                                    @if ($idea->plan !== null)
                                        <a
                                            href="{{ route('ideas.plan', ['plan' => $idea->plan->slug]) }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1 text-sui-accent hover:underline"
                                        >
                                            <flux:icon name="queue-list" class="size-3" />
                                            {{ __('Has a plan') }}
                                        </a>
                                    @endif

                                    <time class="text-ink-400" datetime="{{ $idea->created_at?->toIso8601String() }}">
                                        {{ __('Captured :date', ['date' => $idea->created_at?->diffForHumans() ?? '']) }}
                                    </time>

                                    <span class="text-ink-400">
                                        {{ trans_choice(':count view|:count views', $idea->view_count, ['count' => $idea->view_count]) }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2" x-data="{ confirming: false }">
                                @if ($idea->isPublished())
                                    <flux:button
                                        :href="route('ideas.show', ['idea' => $idea->slug])"
                                        target="_blank"
                                        rel="noopener"
                                        variant="ghost"
                                        size="xs"
                                        icon="arrow-top-right-on-square"
                                    >
                                        <span class="sr-only">{{ __('Open the public page') }}</span>
                                    </flux:button>
                                @endif

                                <template x-if="! confirming">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        icon="trash"
                                        class="!text-sui-danger"
                                        x-on:click="confirming = true"
                                    >
                                        <span class="sr-only">{{ __('Remove this idea') }}</span>
                                    </flux:button>
                                </template>

                                <template x-if="confirming">
                                    <div class="flex items-center gap-1">
                                        <flux:button
                                            variant="danger"
                                            size="xs"
                                            wire:click="remove({{ $idea->id }})"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ __('Remove') }}
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                            {{ __('Keep it') }}
                                        </flux:button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <x-app.ui.pagination :paginator="$this->ideas" :label="__('Ideas console pagination')" class="px-5 pb-5" />
        @endif
    </x-app.ui.section>
</div>
