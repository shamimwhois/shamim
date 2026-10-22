<?php

use App\Actions\DevLab\DeleteLabSnippet;
use App\Enums\AccountPermission;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Support\Seo;
use Flux\Flux;
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
 * Developer Lab — the platform-wide console.
 *
 * Two questions get asked from staff side, and they are different enough that they
 * do not share a list. One is about objects: what has been published under somebody's
 * name, is it appropriate, and does it need to come down. The other is about use: how
 * often the Run button is actually being pressed, by whom, and whether the daily
 * allowance is doing anything. Snippets and executions answer those in turn.
 *
 * The boundary is the same one the Ideas console draws, and for the same reason:
 *
 *   - `LabSnippetPolicy::update()` is authors only, so there is no edit link here. An
 *     operator reads across accounts to moderate; rewriting somebody's code is a
 *     different act and is not offered.
 *   - `LabSnippetPolicy::delete()` does allow `admin:access`, so removal is offered,
 *     and each one re-authorises against the row it resolves rather than the list that
 *     rendered the button.
 *   - Executions are never deletable. A log that can be edited from the UI next to the
 *     thing it logs is not a log.
 *
 * The code itself is not printed here. A console that renders one account's markup
 * into another account's page is the mistake this module spends the rest of its design
 * avoiding; the excerpt is plain text, and the only frame in this file is the one an
 * operator opens on the public page, which runs under an opaque origin.
 *
 * The `boot()` guard is not decoration. Mutations are POSTs to `/livewire/update`,
 * which never pass through the `permission:admin:access` on the route, so without it a
 * non-admin with a hand-built request would be issuing the delete.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('Developer Lab — administration')] class extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'snippets')]
    public string $tab = 'snippets';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'lang', except: '')]
    public string $language = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::AccessAdminPanel), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Developer Lab — administration',
            'description' => 'Review every snippet the platform is holding and every run its accounts have made.',
            'noindex' => true,
        ]);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingLanguage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'language']);
    }

    /**
     * Take a snippet down on behalf of the platform.
     *
     * The row is resolved by id and then re-authorised, so a list rendered a minute ago
     * cannot be used to delete something that has since been unpublished or renamed.
     */
    public function remove(int $snippetId, DeleteLabSnippet $deleteLabSnippet): void
    {
        $snippet = LabSnippet::query()->find($snippetId);

        if ($snippet === null) {
            abort(404);
        }

        $this->authorize('delete', $snippet);

        $deleteLabSnippet->handle($snippet);

        unset($this->snippets, $this->metrics);

        Flux::toast(variant: 'success', text: __('Snippet removed.'));
    }

    /**
     * The tabs, in the order the questions get asked.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'snippets' => __('Snippets'),
            'executions' => __('Executions'),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, LabSnippet>
     */
    #[Computed]
    public function snippets(): LengthAwarePaginator
    {
        return $this->filteredSnippets()
            ->with('user:id,name,email')
            ->recentlyTouched()
            ->paginate(config('dev_lab.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, LabExecution>
     */
    #[Computed]
    public function executions(): LengthAwarePaginator
    {
        return LabExecution::query()
            ->with('user:id,name,email')
            ->when(
                $this->language !== '',
                fn (Builder $query) => LabLanguage::tryFrom($this->language) !== null
                    ? $query->inLanguage(LabLanguage::from($this->language))
                    : $query
            )
            ->newestFirst()
            ->paginate(config('dev_lab.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * Counts for the header strip.
     *
     * Three numbers, each a different kind of pressure: the size of the corpus, how
     * much of it is currently visible to a stranger, and how much of the lab got run
     * today. Anything else would be a count of rows the tabs already filter.
     *
     * @return array{total: int, published: int, runs_today: int}
     */
    #[Computed]
    public function metrics(): array
    {
        return [
            'total' => LabSnippet::query()->count(),
            'published' => LabSnippet::query()->published()->count(),
            'runs_today' => LabExecution::query()->onDay(Carbon::today())->count(),
        ];
    }

    /**
     * The languages available to the filter, every case, since an operator is looking
     * for the PHP rows just as much as the runnable ones.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function languages(): array
    {
        return LabLanguage::options();
    }

    /**
     * @return Builder<LabSnippet>
     */
    protected function filteredSnippets(): Builder
    {
        return LabSnippet::query()
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when(
                $this->language !== '',
                fn (Builder $query) => LabLanguage::tryFrom($this->language) !== null
                    ? $query->inLanguage(LabLanguage::from($this->language))
                    : $query
            );
    }
}; ?>

<div>
    <x-app.ui.header
        :eyebrow="__('Administration')"
        :title="__('Developer Lab')"
        :description="__('Every snippet the platform is holding, and every run its accounts have made. Reading is open to you; rewriting is not.')"
        :breadcrumbs="[
            ['label' => __('Administration'), 'href' => route('admin.dashboard')],
            ['label' => __('Developer Lab')],
        ]"
    />

    <div class="px-5 pt-6 sm:px-6 lg:px-10">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-app.ui.stat
                :label="__('Saved snippets')"
                :value="$this->metrics['total'] ?? 0"
                icon="code-bracket"
                tone="primary"
            />
            <x-app.ui.stat
                :label="__('Live on the site')"
                :value="$this->metrics['published'] ?? 0"
                icon="eye"
                tone="success"
                :hint="__('Public, with a publish timestamp behind the flag.')"
            />
            <x-app.ui.stat
                :label="__('Runs today')"
                :value="$this->metrics['runs_today'] ?? 0"
                icon="play"
                tone="attention"
                :hint="__('Presses of Run across every account, so far.')"
            />
        </div>
    </div>

    <x-app.ui.section :padded="false" class="mt-6">
        <div class="border-b border-sui-hairline px-5 py-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Switch what this console lists') }}">
                    @foreach ($this->tabs() as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            wire:key="dev-lab-tab-{{ $key }}"
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

                <p class="ml-auto font-mono text-2xs uppercase tracking-widest text-ink-400" aria-live="polite">
                    {{ $tab === 'snippets'
                        ? trans_choice(':count snippet|:count snippets', $this->snippets->total(), ['count' => $this->snippets->total()])
                        : trans_choice(':count run|:count runs', $this->executions->total(), ['count' => $this->executions->total()]) }}
                </p>
            </div>

            @if ($tab === 'snippets')
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <label for="dev-lab-search" class="sr-only">{{ __('Search snippets') }}</label>
                    <div class="relative min-w-0 flex-1 sm:max-w-sm">
                        <span aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400">
                            <flux:icon name="magnifying-glass" class="size-4" />
                        </span>
                        <input
                            id="dev-lab-search"
                            type="search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="{{ __('Search titles, notes and code') }}"
                            class="sui-control w-full pl-9"
                        />
                    </div>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    wire:key="dev-lab-lang-all"
                    wire:click="$set('language', '')"
                    aria-selected="{{ $language === '' ? 'true' : 'false' }}"
                    @class([
                        'sui-focus rounded-full border px-3 py-1 text-2xs font-medium transition-all duration-150 ease-soft',
                        'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $language === '',
                        'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $language !== '',
                    ])
                >
                    {{ __('Every language') }}
                </button>

                @foreach ($this->languages as $option)
                    <button
                        type="button"
                        wire:key="dev-lab-lang-{{ $option['value'] }}"
                        wire:click="$set('language', '{{ $option['value'] }}')"
                        aria-selected="{{ $language === $option['value'] ? 'true' : 'false' }}"
                        @class([
                            'sui-focus rounded-full border px-3 py-1 text-2xs font-medium transition-all duration-150 ease-soft',
                            'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $language === $option['value'],
                            'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $language !== $option['value'],
                        ])
                    >
                        {{ $option['label'] }}
                    </button>
                @endforeach

                @if ($search !== '' || $language !== '')
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="ml-1 text-2xs font-medium text-sui-accent hover:underline"
                    >
                        {{ __('Clear') }}
                    </button>
                @endif
            </div>
        </div>

        @if ($tab === 'snippets')
            @if ($this->snippets->isEmpty())
                <div class="sui-empty">
                    <p class="text-base font-semibold tracking-tight text-sui-ink">
                        {{ $search !== '' || $language !== '' ? __('Nothing matches those filters') : __('Nobody has saved a snippet yet') }}
                    </p>
                    <p class="mt-1.5 text-sm text-ink-500">
                        @if ($search !== '' || $language !== '')
                            {{ __('Try another language, or clear the search.') }}
                        @else
                            {{ __('The sandbox at /dev-lab/sandbox is where the first ones come from.') }}
                        @endif
                    </p>
                </div>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->snippets as $snippet)
                        <li wire:key="dev-lab-snippet-{{ $snippet->id }}" class="px-5 py-4">
                            <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold tracking-tight text-sui-ink">
                                            {{ $snippet->title }}
                                        </p>

                                        <flux:badge :color="$snippet->language->badgeColor()" size="sm">
                                            {{ $snippet->language->label() }}
                                        </flux:badge>

                                        @if ($snippet->isPublished())
                                            <flux:badge :color="$snippet->visibility->badgeColor()" size="sm">
                                                {{ __('Public') }}
                                            </flux:badge>
                                        @else
                                            <flux:badge :color="$snippet->visibility === ContentVisibility::Public ? 'amber' : 'gray'" size="sm">
                                                {{ $snippet->visibility === ContentVisibility::Public ? __('Public, not published') : __('Private') }}
                                            </flux:badge>
                                        @endif
                                    </div>

                                    <p class="mt-1 text-xs leading-normal text-ink-500">
                                        {{ $snippet->excerpt(160) }}
                                    </p>

                                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-ink-500">
                                        <span class="inline-flex items-center gap-1">
                                            <flux:icon name="user" class="size-3" />
                                            {{ $snippet->user?->name ?? __('Deleted account') }}
                                            <span class="text-ink-400">{{ $snippet->user?->email }}</span>
                                        </span>

                                        <span class="font-mono text-ink-400">{{ $snippet->slug }}</span>

                                        <span class="text-ink-400">
                                            {{ trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]) }}
                                        </span>

                                        <span class="text-ink-400">
                                            {{ trans_choice(':count view|:count views', $snippet->view_count, ['count' => $snippet->view_count]) }}
                                        </span>

                                        <time class="text-ink-400" datetime="{{ $snippet->updated_at?->toIso8601String() }}">
                                            {{ __('Touched :date', ['date' => $snippet->updated_at?->diffForHumans() ?? '']) }}
                                        </time>
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-2" x-data="{ confirming: false }">
                                    @if ($snippet->isPublished())
                                        <flux:button
                                            :href="route('dev-lab.snippets.show', ['snippet' => $snippet->slug])"
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
                                            <span class="sr-only">{{ __('Remove this snippet') }}</span>
                                        </flux:button>
                                    </template>

                                    <template x-if="confirming">
                                        <div class="flex items-center gap-1">
                                            <flux:button
                                                variant="danger"
                                                size="xs"
                                                wire:click="remove({{ $snippet->id }})"
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

                <x-app.ui.pagination :paginator="$this->snippets" :label="__('Snippets console pagination')" class="px-5 pb-5" />
            @endif
        @else
            @if ($this->executions->isEmpty())
                <div class="sui-empty">
                    <p class="text-base font-semibold tracking-tight text-sui-ink">
                        {{ $language !== '' ? __('No runs in that language yet') : __('Nobody has pressed Run yet') }}
                    </p>
                    <p class="mt-1.5 text-sm text-ink-500">
                        {{ __('Each row here is one preview someone asked for. The output itself stayed in their browser.') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->executions as $execution)
                        <li wire:key="dev-lab-execution-{{ $execution->id }}" class="px-5 py-3.5">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <flux:badge :color="$execution->language->badgeColor()" size="sm">
                                    {{ $execution->language->label() }}
                                </flux:badge>

                                <p class="min-w-0 flex-1 truncate text-sm text-sui-ink-secondary">
                                    {{ $execution->label ?? __('Untitled experiment') }}
                                </p>

                                <span class="inline-flex items-center gap-1 text-2xs text-ink-500">
                                    <flux:icon name="user" class="size-3" />
                                    {{ $execution->user?->name ?? __('Deleted account') }}
                                    <span class="text-ink-400">{{ $execution->user?->email }}</span>
                                </span>

                                <time class="font-mono text-2xs text-ink-400" datetime="{{ $execution->created_at?->toIso8601String() }}">
                                    {{ $execution->created_at?->format('j M Y · H:i') ?? '' }}
                                </time>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <x-app.ui.pagination :paginator="$this->executions" :label="__('Executions console pagination')" class="px-5 pb-5" />
            @endif
        @endif
    </x-app.ui.section>

    <section class="sui-card mt-6 space-y-3 p-5">
        <h2 class="text-sm font-semibold tracking-tight text-sui-ink">{{ __('What this console does not do') }}</h2>

        <p class="text-xs leading-normal text-ink-500">
            {{ __('It does not run anything. There is no server-side runner in this module, so an operator cannot execute a snippet from here, preview one on behalf of a visitor, or reach a frame another account left open. The code is stored as text and escaped on every surface, including this one, which is why the excerpt above is plain text rather than a rendered preview.') }}
        </p>

        <p class="text-xs leading-normal text-ink-500">
            {{ __('It does not edit. A snippet that should not be public is taken down rather than rewritten, because the author is the only account that should be changing their own work.') }}
        </p>
    </section>
</div>
