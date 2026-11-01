<?php

use App\Enums\AccountPermission;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Models\IdeaPlan;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The signed-in account's plans.
 *
 * A plan is the heavier half of Module B, and this screen is where the weight shows:
 * an account comes here to ask what is agreed, what is being worked on, and what has
 * stalled. The default view is therefore live work, not everything, and a finished or
 * called-off plan leaves the list rather than hanging at the bottom of it. That is the
 * same decision the ideas workspace makes about archived ideas, for the same reason.
 *
 * Progress is a ratio of tasks, not a percentage of a plan's text. `docs/Idea.md` asks
 * for "progress tracking" without inventing a formula, and the only honest number here
 * is how many of the lines somebody wrote down are done. It is shown as counts on
 * purpose: "3 of 9" is a fact, while "33%" implies a precision nobody calculated.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Plans')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::UseIdeas),
            403,
            __('You may not open the planning workspace.')
        );
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Plans',
            'description' => 'The plans you have written from your ideas.',
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
     * The tabs, in workflow order, plus the two that group by outcome.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'open' => __('Open'),
            'ready' => PlanStatus::Ready->label(),
            'in_progress' => PlanStatus::InProgress->label(),
            'on_hold' => PlanStatus::OnHold->label(),
            'completed' => PlanStatus::Completed->label(),
            'cancelled' => PlanStatus::Cancelled->label(),
            'all' => __('All'),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, IdeaPlan>
     */
    #[Computed]
    public function plans(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['idea:id,title,slug'])
            ->withCount(['tasks as tasks_done_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done)])
            ->withCount('tasks')
            ->recentlyTouched()
            ->paginate(config('ideas.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * The tab the list is narrowed to, or null when the address bar names one this
     * screen no longer offers.
     *
     * An unrecognised `?tab=` already behaves as the widest view, so the sentence
     * under an empty list must not claim a stage nobody chose.
     */
    #[Computed]
    public function activeTabLabel(): ?string
    {
        return $this->tabs()[$this->tab] ?? null;
    }

    /**
     * @return Builder<IdeaPlan>
     */
    protected function filteredQuery(): Builder
    {
        $query = IdeaPlan::query()
            ->authoredBy(auth()->user())
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search));

        (match ($this->tab) {
            'open' => fn (Builder $query) => $query->open(),
            'ready' => fn (Builder $query) => $query->inStatus(PlanStatus::Ready),
            'in_progress' => fn (Builder $query) => $query->inStatus(PlanStatus::InProgress),
            'on_hold' => fn (Builder $query) => $query->inStatus(PlanStatus::OnHold),
            'completed' => fn (Builder $query) => $query->inStatus(PlanStatus::Completed),
            'cancelled' => fn (Builder $query) => $query->inStatus(PlanStatus::Cancelled),
            default => fn (Builder $query) => $query,
        })($query);

        return $query;
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Ideas"
        title="Plans"
        description="What an idea became when somebody wrote it down properly."
        :breadcrumbs="[
            ['label' => __('Ideas'), 'href' => route('ideas.dashboard')],
            ['label' => __('Plans')],
        ]"
    >
        <x-slot:actions>
            <flux:button :href="route('ideas.plan.create')" wire:navigate variant="primary" size="sm" icon="plus">
                {{ __('New plan') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.section :padded="false">
        <div class="border-b border-sui-hairline px-5 py-4">
            <div class="flex flex-wrap items-center gap-3">
                <label for="plans-search" class="sr-only">{{ __('Search plans') }}</label>
                <div class="relative min-w-0 flex-1 sm:max-w-sm">
                    <span aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>
                    <input
                        id="plans-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Search titles and problems') }}"
                        class="sui-control w-full pl-9"
                    />
                </div>

                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400" aria-live="polite">
                    {{ trans_choice(':count plan|:count plans', $this->plans->total(), ['count' => $this->plans->total()]) }}
                </p>
            </div>

            <div class="mt-4 flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter plans') }}">
                @foreach ($this->tabs() as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:key="plan-tab-{{ $key }}"
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

        @if ($this->plans->isEmpty())
            <div class="sui-empty">
                @if ($search !== '')
                    <p class="text-sm text-ink-500">
                        {{ __('No plan of yours matches :term.', ['term' => $search]) }}
                    </p>
                @elseif ($this->activeTabLabel !== null && $tab !== 'open' && $tab !== 'all')
                    <p class="text-sm text-ink-500">
                        {{ __('Nothing is :status right now.', ['status' => Str::lower($this->activeTabLabel)]) }}
                    </p>
                @else
                    <p class="text-sm text-ink-500">
                        {{ __('No plans yet. Approve an idea and turn it into one, or write a plan from scratch.') }}
                    </p>
                    <flux:button :href="route('ideas.plan.create')" wire:navigate variant="primary" size="sm" class="mt-4">
                        {{ __('New plan') }}
                    </flux:button>
                @endif
            </div>
        @else
            <ul class="divide-y divide-sui-hairline">
                @foreach ($this->plans as $plan)
                    <li wire:key="plan-{{ $plan->id }}" class="px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <a
                                    href="{{ route('ideas.plan', ['plan' => $plan->slug]) }}"
                                    wire:navigate
                                    class="text-sm font-semibold tracking-tight text-sui-ink transition-colors hover:text-sui-accent"
                                >
                                    {{ $plan->title }}
                                </a>

                                @if (filled($plan->excerpt()))
                                    <p class="mt-1 line-clamp-2 text-xs leading-normal text-ink-500">
                                        {{ $plan->excerpt() }}
                                    </p>
                                @endif

                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <x-app.ideas.status-badge :status="$plan->status" size="sm" />

                                    @if ($plan->priority !== null)
                                        <flux:badge :color="$plan->priority->badgeColor()" size="sm">
                                            {{ $plan->priority->label() }}
                                        </flux:badge>
                                    @endif

                                    @if ($plan->idea !== null)
                                        <a
                                            href="{{ route('ideas.edit', ['idea' => $plan->idea->slug]) }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1 text-2xs text-ink-500 transition-colors hover:text-sui-accent"
                                        >
                                            <flux:icon name="light-bulb" class="size-3.5" />
                                            {{ __('From: :title', ['title' => Str::limit($plan->idea->title, 40)]) }}
                                        </a>
                                    @endif

                                    @if ($plan->target_end_date !== null)
                                        <span @class([
                                            'text-2xs',
                                            'text-sui-danger' => $plan->status->isOpen() && $plan->target_end_date->isBefore(today()),
                                            'text-ink-500' => ! ($plan->status->isOpen() && $plan->target_end_date->isBefore(today())),
                                        ])>
                                            {{ __('Due :date', ['date' => $plan->target_end_date->translatedFormat('j M Y')]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="w-28 shrink-0 text-right">
                                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400">
                                    {{ __('Tasks') }}
                                </p>
                                <p class="mt-1 text-sm font-semibold text-sui-ink">
                                    {{ $plan->tasks_done_count }}/{{ $plan->tasks_count }}
                                </p>
                                <div
                                    class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-sui-hover"
                                    role="img"
                                    aria-label="{{ __(':done of :total tasks done', ['done' => $plan->tasks_done_count, 'total' => $plan->tasks_count]) }}"
                                >
                                    <div
                                        class="h-full rounded-full bg-sui-accent transition-all duration-300 ease-soft"
                                        style="width: {{ $plan->tasks_count > 0 ? round($plan->tasks_done_count / $plan->tasks_count * 100) : 0 }}%"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <x-app.ui.pagination :paginator="$this->plans" :label="__('Plans pagination')" class="px-5 pb-5" />
        @endif
    </x-app.ui.section>
</div>
