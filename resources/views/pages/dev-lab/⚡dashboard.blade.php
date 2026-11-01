<?php

use App\Actions\DevLab\RecordLabExecution;
use App\Enums\AccountPermission;
use App\Models\LabExecution;
use App\Models\LabSnippet;
use App\Models\LabWorkspace;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Developer Lab workspace home.
 *
 * Three facts and one door. What is saved, what is published, how much of today's
 * allowance has gone. The door is the sandbox, because a lab is used rather than
 * browsed, and a home page that is also an editor would be two mediocre screens.
 *
 * The usage figure is a count and not a chart. `docs/lab.md` asks for "execution
 * limits and usage tracking"; a sparkline of how often somebody pressed a button
 * last month answers a question nobody is asking, and the number that decides
 * whether the next Run will work is today's. It is read through
 * {@see RecordLabExecution}, the same class the button enforces through, so the
 * dashboard cannot show a remaining count the editor then contradicts.
 *
 * Nothing here writes. Saving belongs to the editor and to the snippet form, and a
 * screen that both lists and mutates is a screen where one stray click deletes
 * something somebody meant to keep.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Developer Lab')] class extends Component
{
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
            'title' => 'Developer Lab',
            'description' => "Your saved workspaces, your snippets, and the runs left today.",
            'noindex' => true,
        ]);
    }

    /**
     * The workspaces this account touched most recently.
     *
     * @return Collection<int, LabWorkspace>
     */
    #[Computed]
    public function workspaces(): Collection
    {
        return LabWorkspace::query()
            ->authoredBy(auth()->user())
            ->recentlyTouched()
            ->take((int) config('dev_lab.dashboard.recent'))
            ->get();
    }

    /**
     * The snippets this account saved, most recently touched first.
     *
     * @return Collection<int, LabSnippet>
     */
    #[Computed]
    public function snippets(): Collection
    {
        return LabSnippet::query()
            ->authoredBy(auth()->user())
            ->recentlyTouched()
            ->take((int) config('dev_lab.dashboard.recent'))
            ->get();
    }

    /**
     * The account's most recent runs, as a shelf rather than a table.
     *
     * @return Collection<int, LabExecution>
     */
    #[Computed]
    public function recentRuns(): Collection
    {
        return LabExecution::query()
            ->authoredBy(auth()->user())
            ->newestFirst()
            ->take(5)
            ->get();
    }

    /**
     * The three numbers in the band.
     *
     * The runs figure is the remaining count, not the used one, because the number
     * a person acts on is what they have left. An unlimited day says so in a word
     * rather than showing an enormous integer.
     *
     * @return array{workspaces: int, published: int, remaining: string}
     */
    #[Computed]
    public function metrics(): array
    {
        $user = auth()->user();
        $remaining = app(RecordLabExecution::class)->remainingToday($user);

        return [
            'workspaces' => LabWorkspace::query()->authoredBy($user)->count(),
            'published' => LabSnippet::query()->authoredBy($user)->published()->count(),
            'remaining' => $remaining === null
                ? __('Unlimited')
                : (string) $remaining,
        ];
    }
}; ?>

<div>
    <x-app.ui.body>
        {{-- ============================================================= --
         | Greeting
         --}}
        <x-app.ui.welcome-card
            eyebrow="Developer Lab"
            title="Try it before you ship it"
            description="HTML, CSS and JavaScript run in a sandboxed frame in your own browser. PHP is stored, listed and copied, and there is no Run button for it because there is no server-side runner."
            icon="beaker"
        >
            <x-slot:actions>
                <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="primary" icon="play">
                    {{ __('Open sandbox') }}
                </flux:button>
                <flux:button :href="route('dev-lab.snippets')" wire:navigate variant="ghost" icon="code-bracket">
                    {{ __('Snippets') }}
                </flux:button>
                <flux:button :href="route('dev-lab.history')" wire:navigate variant="ghost" icon="clock">
                    {{ __('Run history') }}
                </flux:button>
            </x-slot:actions>
        </x-app.ui.welcome-card>

        {{-- ============================================================= --
         | Counts
         --}}
        <section aria-labelledby="dev-lab-metrics-heading">
            <h2 id="dev-lab-metrics-heading" class="sr-only">{{ __('Lab counts') }}</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-app.ui.stat
                    :label="__('Workspaces')"
                    :value="$this->metrics['workspaces']"
                    icon="window"
                    tone="primary"
                    :hint="__('Saved sets of panes, one per experiment.')"
                />
                <x-app.ui.stat
                    :label="__('Snippets published')"
                    :value="$this->metrics['published']"
                    icon="code-bracket"
                    tone="success"
                    :hint="__('Readable by anybody. The rest are yours alone.')"
                />
                <x-app.ui.stat
                    :label="__('Runs left today')"
                    :value="$this->metrics['remaining']"
                    icon="play"
                    tone="info"
                    :hint="__('The allowance resets at midnight.')"
                />
            </div>
        </section>

        {{-- ============================================================= --
         | Workspaces
         --}}
        <x-app.ui.section
            :title="__('Recent workspaces')"
            :description="__('What the editor was holding when somebody pressed Save.')"
            icon="window"
        >
            @if ($this->workspaces->isEmpty())
                <x-app.ui.empty-state
                    icon="window"
                    :title="__('Nothing saved yet')"
                    :description="__('Open the sandbox and press Save. The three panes become a workspace you can come back to by name.')"
                >
                    <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="primary" icon="play">
                        {{ __('Open sandbox') }}
                    </flux:button>
                </x-app.ui.empty-state>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->workspaces as $workspace)
                        @php
                            $filled = collect([$workspace->html, $workspace->css, $workspace->js])->filter(filled(...))->count();
                        @endphp

                        <li wire:key="workspace-{{ $workspace->id }}" class="flex items-center justify-between gap-4 px-5 py-3.5">
                            <div class="min-w-0">
                                <a
                                    href="{{ route('dev-lab.sandbox', ['workspace' => $workspace->id]) }}"
                                    wire:navigate
                                    class="text-sm font-semibold tracking-tight text-sui-ink transition-colors hover:text-sui-accent"
                                >
                                    {{ $workspace->name }}
                                </a>
                                <p class="mt-0.5 text-2xs text-ink-500">
                                    {{ __('Updated :time', ['time' => $workspace->updated_at->diffForHumans()]) }}
                                    @if ($workspace->last_run_at !== null)
                                        &middot; {{ __('run :time', ['time' => $workspace->last_run_at->diffForHumans()]) }}
                                    @else
                                        &middot; {{ __('never run') }}
                                    @endif
                                </p>
                            </div>

                            <flux:badge size="sm" color="zinc">
                                {{ trans_choice(':count pane|:count panes', $filled, ['count' => $filled]) }}
                            </flux:badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-app.ui.section>

        {{-- ============================================================= --
         | Snippets
         --}}
        <x-app.ui.section
            :title="__('Your snippets')"
            :description="__('Code worth naming. Publishing one puts it in the gallery.')"
            icon="code-bracket"
        >
            <x-slot:actions>
                <flux:button :href="route('dev-lab.snippets.create')" wire:navigate variant="ghost" size="xs" icon="plus">
                    {{ __('New snippet') }}
                </flux:button>
            </x-slot:actions>

            @if ($this->snippets->isEmpty())
                <x-app.ui.empty-state
                    icon="code-bracket"
                    :title="__('No snippets saved')"
                    :description="__('A snippet is a piece of code you would send to somebody without explaining it first.')"
                >
                    <flux:button :href="route('dev-lab.snippets.create')" wire:navigate variant="primary" icon="plus">
                        {{ __('New snippet') }}
                    </flux:button>
                </x-app.ui.empty-state>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->snippets as $snippet)
                        <li wire:key="dashboard-snippet-{{ $snippet->id }}" class="flex items-center justify-between gap-4 px-5 py-3.5">
                            <div class="min-w-0">
                                <a
                                    href="{{ route('dev-lab.snippets.edit', ['snippet' => $snippet->slug]) }}"
                                    wire:navigate
                                    class="text-sm font-semibold tracking-tight text-sui-ink transition-colors hover:text-sui-accent"
                                >
                                    {{ $snippet->title }}
                                </a>
                                <p class="mt-0.5 font-mono text-2xs text-ink-400">
                                    {{ $snippet->lineCount() }} {{ __('lines') }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:badge :color="$snippet->language->badgeColor()" size="sm">
                                    {{ $snippet->language->label() }}
                                </flux:badge>

                                @if ($snippet->isPublished())
                                    <flux:badge :color="$snippet->visibility->badgeColor()" size="sm">{{ __('Public') }}</flux:badge>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-sui-hairline px-5 py-3">
                    <a
                        href="{{ route('dev-lab.snippets') }}"
                        wire:navigate
                        class="text-2xs font-medium text-sui-accent-ink transition-colors hover:text-sui-accent"
                    >
                        {{ __('See every snippet') }}
                    </a>
                </div>
            @endif
        </x-app.ui.section>

        {{-- ============================================================= --
         | Recent runs
         --}}
        <x-app.ui.section
            :title="__('Last runs')"
            :description="__('Pressing Run in the browser. The output belongs to the console, only the fact is kept.')"
            icon="clock"
        >
            @if ($this->recentRuns->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-500">
                    {{ __('Nothing has been run yet. The history starts the first time the sandbox executes a document.') }}
                </p>
            @else
                <ul class="divide-y divide-sui-hairline">
                    @foreach ($this->recentRuns as $run)
                        <li wire:key="run-{{ $run->id }}" class="flex items-center justify-between gap-4 px-5 py-3">
                            <p class="min-w-0 truncate text-sm text-ink-700">
                                {{ $run->label ?: $run->language->label() }}
                            </p>
                            <p class="shrink-0 font-mono text-2xs uppercase tracking-widest text-ink-400">
                                {{ $run->created_at->diffForHumans() }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-app.ui.section>
    </x-app.ui.body>
</div>
