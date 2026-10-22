<?php

use App\Actions\DevLab\RecordLabExecution;
use App\Enums\AccountPermission;
use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * This account's run history.
 *
 * The table is deliberately thin, and so is this screen. A `lab_executions` row records
 * that a sandbox was asked to run something in a given language at a given moment; it
 * holds no output, no exit code and no duration, because none of that is knowable on a
 * page where the browser, not the server, executed the code. A screen that implied
 * otherwise would be describing a containerised runner that does not exist.
 *
 * What it is good for is the two questions the module's spec actually asks: what have I
 * run recently, and how much of today's allowance is left. The label on each row is the
 * workspace or snippet name captured at the time, which is why deleting a workspace
 * does not silently rewrite history into "unknown".
 *
 * The allowance filter is a day rather than a tab: `while (true)` is a same-afternoon
 * problem, and a list of yesterday's runs does not help anybody debug it.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Run history')] class extends Component
{
    use WithPagination;

    #[Url(as: 'lang', except: '')]
    public string $language = '';

    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::UseDeveloperLab),
            403,
            __('You may not open the Developer Lab.')
        );
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Run history',
            'description' => 'Every time your sandbox was asked to run something.',
            'noindex' => true,
        ]);
    }

    public function updating(string $property): void
    {
        if ($property === 'language') {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, LabExecution>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->newestFirst()
            ->paginate((int) config('dev_lab.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * Runs recorded today, and the ceiling they are measured against.
     *
     * @return array{used: int, limit: int, remaining: int|null}
     */
    #[Computed]
    public function usage(): array
    {
        $used = LabExecution::query()
            ->authoredBy(auth()->user())
            ->onDay(Carbon::today())
            ->count();

        $remaining = app(RecordLabExecution::class)->remainingToday(auth()->user());

        return [
            'used' => $used,
            'limit' => (int) config('dev_lab.daily_run_limit'),
            'remaining' => $remaining,
        ];
    }

    /**
     * The languages this account has actually run, in the order the enum declares them.
     *
     * Offered from the rows rather than from the enum, because a filter for a language
     * that has never been run here is a control that only produces an empty page.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function filters(): array
    {
        // The column is cast, so a plucked language arrives back as the enum case rather
        // than as its stored string, and the comparison has to be made case to case.
        $runLanguages = LabExecution::query()
            ->authoredBy(auth()->user())
            ->distinct()
            ->pluck('language');

        // There is no leading "every language" entry here. PHP turns an array key of the
        // empty string into the integer zero when it stores it, so such an entry would
        // render a control asking for `?lang=0` — a value no language has, and one that
        // could never be compared back to the empty string the filter clears to. The
        // screen renders that control itself, above this list.
        return collect(LabLanguage::cases())
            ->filter(fn (LabLanguage $language): bool => $runLanguages->contains($language))
            ->mapWithKeys(fn (LabLanguage $language): array => [$language->value => $language->label()])
            ->all();
    }

    /**
     * @return Builder<LabExecution>
     */
    protected function filteredQuery(): Builder
    {
        $language = LabLanguage::tryFrom($this->language);

        return LabExecution::query()
            ->authoredBy(auth()->user())
            ->when($language !== null, fn (Builder $query) => $query->where('language', $language));
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Developer Lab"
        title="Run history"
        description="What the sandbox was asked to run, and when. Output stays in the browser, so there is nothing here but the asking."
        :breadcrumbs="[
            ['label' => __('Developer Lab'), 'href' => route('dev-lab.dashboard')],
            ['label' => __('Run history')],
        ]"
    >
        <x-slot:actions>
            <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="primary" size="sm" icon="play">
                {{ __('Open the sandbox') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.section :padded="false">
        <div class="flex flex-wrap items-center gap-3 border-b border-sui-hairline px-5 py-4">
            <p class="text-sm text-ink-600">
                {{ __('Today') }}
                <span class="font-semibold text-ink-900">{{ $this->usage['used'] }}</span>
                @if ($this->usage['remaining'] !== null)
                    <span class="text-ink-500">
                        {{ __('of :limit runs, :remaining left. The allowance resets at midnight.', [
                            'limit' => $this->usage['limit'],
                            'remaining' => $this->usage['remaining'],
                        ]) }}
                    </span>
                @else
                    <span class="text-ink-500">{{ __('runs. Today is not capped.')}}</span>
                @endif
            </p>

            <div class="ml-auto flex flex-wrap items-center gap-1.5" role="tablist" aria-label="{{ __('Filter by language') }}">
                <button
                    type="button"
                    role="tab"
                    wire:key="run-lang-every"
                    aria-selected="{{ $language === '' ? 'true' : 'false' }}"
                    wire:click="$set('language', '')"
                    @class([
                        'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition duration-150 ease-soft',
                        'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $language === '',
                        'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $language !== '',
                    ])
                >
                    {{ __('Every language') }}
                </button>

                @foreach ($this->filters as $value => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:key="run-lang-{{ $value }}"
                        aria-selected="{{ $language === $value ? 'true' : 'false' }}"
                        wire:click="$set('language', '{{ $value }}')"
                        @class([
                            'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition duration-150 ease-soft',
                            'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $language === $value,
                            'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $language !== $value,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($this->runs->isEmpty())
            <div class="sui-empty">
                @if ($language !== '')
                    <p class="text-sm text-ink-500">
                        {{ __('Nothing has been run in that language yet.') }}
                    </p>
                    <flux:button wire:click="$set('language', '')" size="sm" variant="ghost" class="mt-4">
                        {{ __('Show every language') }}
                    </flux:button>
                @else
                    <p class="text-sm text-ink-500">
                        {{ __('No runs recorded yet. Press Run in the sandbox and the moment appears here.') }}
                    </p>
                    <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="primary" size="sm" class="mt-4">
                        {{ __('Open the sandbox') }}
                    </flux:button>
                @endif
            </div>
        @else
            <ul class="divide-y divide-sui-hairline">
                @foreach ($this->runs as $run)
                    <li wire:key="run-{{ $run->id }}" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                        <flux:badge :color="$run->language->badgeColor()" size="sm">
                            {{ $run->language->label() }}
                        </flux:badge>

                        <p class="min-w-0 flex-1 truncate text-sm text-ink-800">
                            {{ $run->label ?? __('An unnamed workspace') }}
                        </p>

                        <p class="font-mono text-2xs uppercase tracking-widest text-ink-400">
                            {{ $run->created_at->format('j M Y · H:i') }}
                        </p>
                    </li>
                @endforeach
            </ul>

            <x-app.ui.pagination :paginator="$this->runs" :label="__('Run history pagination')" class="px-5 pb-5" />
        @endif
    </x-app.ui.section>

    <p class="px-5 pb-8 text-2xs leading-normal text-ink-400 sm:px-6 lg:px-10">
        {{ __('A run is recorded by the server, which is why it is a fact here. The output of that run lives in the frame and nowhere else, so it is gone when the tab closes. That is the shape of a sandbox with no runner behind it, and this page says so rather than showing a column nobody can fill.') }}
    </p>
</div>
