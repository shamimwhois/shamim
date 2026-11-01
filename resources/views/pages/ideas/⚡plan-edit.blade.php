<?php

use App\Actions\Ideas\DeleteIdeaPlan;
use App\Actions\Ideas\SaveIdeaPlan;
use App\Enums\AccountPermission;
use App\Enums\PlanStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\IdeaPlan;
use App\Rules\PlanSlug;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Create and edit one plan.
 *
 * One screen for both, for the reason `pages::ideas.edit` gives: the fields are the
 * same, only the starting values differ, and two components would be two places to
 * keep the status workflow in sync.
 *
 * The plan's sections are written in the order `docs/Idea.md` asks them to be filled
 * in, and every one of them is optional. That is the whole design: a plan that
 * refuses to exist until somebody has declared a scope and a stack is a plan nobody
 * writes. What the screen does insist on is a title, because the title is what the
 * list, the URL and the tasks hang off.
 *
 * Nothing here decides a slug, a status transition, or the link back to an idea.
 * Those belong to {@see SaveIdeaPlan} and {@see \App\Actions\Ideas\ConvertIdeaToPlan},
 * so a request built by hand meets the same rules as the button.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Plan')] class extends Component
{
    /**
     * The row being edited, null while creating.
     *
     * Not named `$plan`: the bound model in `/ideas/plans/{plan:slug}/edit` arrives
     * as a route parameter called `plan`, and Livewire would copy the slug string
     * onto a property of that name.
     */
    public ?IdeaPlan $editingPlan = null;

    public string $title = '';

    public string $summary = '';

    public string $problem = '';

    public string $solution = '';

    public string $scope = '';

    public string $outOfScope = '';

    public string $goals = '';

    public string $notes = '';

    public string $slug = '';

    public string $status = '';

    public string $priority = '';

    public string $effort = '';

    public string $startDate = '';

    public string $endDate = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::UseIdeas), 403);
    }

    public function mount(?IdeaPlan $plan = null): void
    {
        if ($plan?->exists) {
            abort_unless($plan->isOwnedBy(auth()->user()), 404);

            $this->editingPlan = $plan;
            $this->title = $plan->title;
            $this->summary = (string) $plan->summary;
            $this->problem = (string) $plan->problem_statement;
            $this->solution = (string) $plan->proposed_solution;
            $this->scope = (string) $plan->scope;
            $this->outOfScope = (string) $plan->out_of_scope;
            $this->goals = (string) $plan->goals;
            $this->notes = (string) $plan->notes;
            $this->slug = $plan->slug;
            $this->status = $plan->status->value;
            $this->priority = $plan->priority?->value ?? '';
            $this->effort = (string) $plan->estimated_effort;
            $this->startDate = (string) $plan->target_start_date?->format('Y-m-d');
            $this->endDate = (string) $plan->target_end_date?->format('Y-m-d');
        } else {
            $this->status = PlanStatus::Draft->value;
        }

        app(Seo::class)->set([
            'title' => $this->editingPlan === null ? 'New plan' : $this->editingPlan->title,
            'description' => 'Write the plan an idea became.',
            'noindex' => true,
        ]);
    }

    /**
     * Save the plan, creating it when there is nothing to update.
     */
    public function save(SaveIdeaPlan $savePlan): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:'.IdeaPlan::TITLE_MAX],
            'summary' => ['nullable', 'string', 'max:'.IdeaPlan::SUMMARY_MAX],
            'problem' => ['nullable', 'string', 'max:'.IdeaPlan::SECTION_MAX],
            'solution' => ['nullable', 'string', 'max:'.IdeaPlan::SECTION_MAX],
            'scope' => ['nullable', 'string', 'max:'.IdeaPlan::SECTION_MAX],
            'outOfScope' => ['nullable', 'string', 'max:'.IdeaPlan::SECTION_MAX],
            'goals' => ['nullable', 'string', 'max:'.IdeaPlan::SECTION_MAX],
            'notes' => ['nullable', 'string', 'max:'.(IdeaPlan::NOTES_MAX_KILOBYTES * 1024)],
            'slug' => ['nullable', 'string', 'max:120', new PlanSlug($this->editingPlan?->id)],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(PlanStatus::cases(), 'value'))],
            'priority' => ['nullable', 'string', 'in:'.implode(',', array_column(WorkPriority::cases(), 'value'))],
            'effort' => ['nullable', 'string', 'max:'.IdeaPlan::EFFORT_MAX],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
        ]);

        try {
            $plan = $savePlan->handle(auth()->user(), [
                'title' => $this->title,
                'summary' => $this->summary ?: null,
                'problem_statement' => $this->problem ?: null,
                'proposed_solution' => $this->solution ?: null,
                'scope' => $this->scope ?: null,
                'out_of_scope' => $this->outOfScope ?: null,
                'goals' => $this->goals ?: null,
                'notes' => $this->notes ?: null,
                'slug' => $this->slug ?: null,
                'status' => $this->status,
                'priority' => $this->priority ?: null,
                'estimated_effort' => $this->effort ?: null,
                'target_start_date' => $this->startDate ?: null,
                'target_end_date' => $this->endDate ?: null,
            ], $this->editingPlan);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $isNew = $this->editingPlan === null;

        if ($isNew) {
            $this->editingPlan = $plan;
            $this->slug = $plan->slug;
        }

        unset($this->availableStatuses);

        Flux::toast(variant: 'success', text: $isNew ? __('Plan created.') : __('Saved.'));

        if ($isNew) {
            $this->redirectRoute('ideas.plan.edit', ['plan' => $plan->slug], navigate: true);
        }
    }

    /**
     * Delete the plan this screen is editing, and the tasks under it.
     */
    public function delete(DeleteIdeaPlan $deletePlan): void
    {
        if ($this->editingPlan === null) {
            return;
        }

        $this->authorize('delete', $this->editingPlan);

        $deletePlan->handle($this->editingPlan);

        Flux::toast(variant: 'success', text: __('Plan deleted.'));

        $this->redirectRoute('ideas.plans', navigate: true);
    }

    /**
     * The stages this plan may move to, current one included.
     *
     * @return Collection<int, PlanStatus>
     */
    #[Computed]
    public function availableStatuses(): Collection
    {
        $current = PlanStatus::tryFrom($this->status) ?? PlanStatus::Draft;

        return collect([$current, ...$current->allowedTransitions()]);
    }

    /**
     * The idea this plan came from, when there is one.
     */
    #[Computed]
    public function sourceIdea(): ?Idea
    {
        return $this->editingPlan?->idea;
    }
}; ?>

<div>
    <x-app.ui.header
        :eyebrow="$this->editingPlan === null ? null : 'Plan'"
        :title="$this->editingPlan === null ? __('New plan') : $this->editingPlan->title"
        :description="$this->editingPlan === null
            ? __('Start from an idea, or write the document first.')
            : __('Edit the plan. The tasks live on the plan page, not here.')"
        :breadcrumbs="[
            ['label' => __('Ideas'), 'href' => route('ideas.dashboard')],
            ['label' => __('Plans'), 'href' => route('ideas.plans')],
            ['label' => $this->editingPlan === null ? __('New') : __('Edit')],
        ]"
    >
        <x-slot:actions>
            @if ($this->editingPlan !== null)
                <flux:button
                    :href="route('ideas.plan', ['plan' => $this->editingPlan->slug])"
                    wire:navigate
                    variant="ghost"
                    size="sm"
                    icon="list-bullet"
                >
                    {{ __('Open tasks') }}
                </flux:button>
            @endif
        </x-slot:actions>
    </x-app.ui.header>

    <div class="grid gap-6 px-5 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] lg:px-10">
        {{-- ============================================================= --
         | The document
         --}}
        <form wire:submit="save" class="space-y-6">
            <x-app.ui.section
                :title="__('What is the plan for?')"
                :description="__('The title is the only thing a plan has to have.')"
            >
                <div class="space-y-4">
                    <div>
                        <label for="plan-title" class="sui-control-label">{{ __('Title') }}</label>
                        <input
                            id="plan-title"
                            type="text"
                            wire:model="title"
                            placeholder="{{ __('Ship the sandbox first') }}"
                            autocomplete="off"
                            class="sui-control mt-2 w-full text-base font-semibold tracking-tight"
                        />
                        @error('title')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="plan-summary" class="sui-control-label">{{ __('Summary') }}</label>
                        <textarea
                            id="plan-summary"
                            wire:model="summary"
                            rows="2"
                            placeholder="{{ __('One paragraph a stranger could read and understand.') }}"
                            class="sui-control mt-2 w-full resize-y text-sm leading-normal"
                        ></textarea>
                        @error('summary')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-app.ui.section>

            <x-app.ui.section
                :title="__('The case')"
                :description="__('Written in the order you think about it. Leave anything blank and it stays blank.')"
            >
                <div class="space-y-4">
                    @foreach ([
                        'problem' => ['label' => __('Problem'), 'placeholder' => __('What hurts today, and to whom?')],
                        'solution' => ['label' => __('Proposed solution'), 'placeholder' => __('What you intend to build, in plain words.')],
                        'scope' => ['label' => __('In scope'), 'placeholder' => __('The work this plan covers.')],
                        'outOfScope' => ['label' => __('Out of scope'), 'placeholder' => __('What a reader might assume is included, and is not.')],
                        'goals' => ['label' => __('Goals'), 'placeholder' => __('The outcomes, not the features.')],
                    ] as $field => $meta)
                        <div>
                            <label for="plan-{{ $field }}" class="sui-control-label">{{ $meta['label'] }}</label>
                            <textarea
                                id="plan-{{ $field }}"
                                wire:model="{{ $field }}"
                                rows="3"
                                placeholder="{{ $meta['placeholder'] }}"
                                class="sui-control mt-2 w-full resize-y text-sm leading-normal"
                            ></textarea>
                            @error($field)
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div>
                        <label for="plan-notes" class="sui-control-label">{{ __('Notes') }}</label>
                        <p class="mt-1 text-2xs text-ink-500">
                            {{ __('Requirements, technical approach, stack. Markdown, whenever you want it.') }}
                        </p>
                        <textarea
                            id="plan-notes"
                            wire:model="notes"
                            rows="12"
                            placeholder="{{ __('Requirements, approach, stack. One place for the detail.') }}"
                            class="sui-control mt-2 w-full resize-y font-mono text-xs leading-relaxed"
                        ></textarea>
                        @error('notes')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-2">
                    <flux:button type="submit" variant="primary" size="sm" icon="check" wire:loading.attr="disabled">
                        {{ __('Save plan') }}
                    </flux:button>
                    <flux:button
                        :href="route('ideas.plans')"
                        wire:navigate
                        variant="ghost"
                        size="sm"
                    >
                        {{ __('Back to plans') }}
                    </flux:button>
                </div>
            </x-app.ui.section>
        </form>

        {{-- ============================================================= --
         | The shape of it
         --}}
        <div class="space-y-6">
            <x-app.ui.section :title="__('Stage')">
                <div class="space-y-4">
                    <div>
                        <label for="plan-status" class="sui-control-label">{{ __('Status') }}</label>
                        <select id="plan-status" wire:model.live="status" class="sui-control mt-2 w-full">
                            @foreach ($this->availableStatuses as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        @error('status')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="plan-priority" class="sui-control-label">{{ __('Priority') }}</label>
                        <select id="plan-priority" wire:model.live="priority" class="sui-control mt-2 w-full">
                            <option value="">{{ __('Unranked') }}</option>
                            @foreach (WorkPriority::options() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </x-app.ui.section>

            <x-app.ui.section :title="__('When, and how much')">
                <div class="space-y-4">
                    <div>
                        <label for="plan-effort" class="sui-control-label">{{ __('Estimated effort') }}</label>
                        <input
                            id="plan-effort"
                            type="text"
                            wire:model="effort"
                            placeholder="{{ __('a sprint') }}"
                            autocomplete="off"
                            class="sui-control mt-2 w-full"
                        />
                        @error('effort')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="plan-start" class="sui-control-label">{{ __('Target start') }}</label>
                            <input id="plan-start" type="date" wire:model="startDate" class="sui-control mt-2 w-full" />
                            @error('startDate')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="plan-end" class="sui-control-label">{{ __('Target end') }}</label>
                            <input id="plan-end" type="date" wire:model="endDate" class="sui-control mt-2 w-full" />
                            @error('endDate')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </x-app.ui.section>

            @if ($this->sourceIdea !== null)
                <x-app.ui.section :title="__('Where it came from')">
                    <a
                        href="{{ route('ideas.edit', ['idea' => $this->sourceIdea->slug]) }}"
                        wire:navigate
                        class="flex items-start gap-3 text-sm text-ink-700 transition-colors hover:text-sui-accent"
                    >
                        <flux:icon name="light-bulb" class="mt-0.5 size-4 text-ink-400" />
                        <span>
                            {{ $this->sourceIdea->title }}
                            <span class="mt-1 block text-2xs text-ink-500">
                                {{ __('The idea is kept as it was. Deleting it will not take this plan with it.') }}
                            </span>
                        </span>
                    </a>
                </x-app.ui.section>
            @endif

            <x-app.ui.section :title="__('Address')">
                <div>
                    <label for="plan-slug" class="sui-control-label">{{ __('Slug') }}</label>
                    <input
                        id="plan-slug"
                        type="text"
                        wire:model="slug"
                        placeholder="{{ __('derived-from-the-title') }}"
                        autocomplete="off"
                        class="sui-control mt-2 w-full font-mono text-xs"
                    />
                    @error('slug')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-2xs text-ink-500">
                        {{ __('Leave it empty and the title decides it. Once set, saving does not change it.') }}
                    </p>
                </div>
            </x-app.ui.section>

            @if ($this->editingPlan !== null)
                <x-app.ui.section tone="attention">
                    <div x-data="{ confirming: false }">
                        <p class="text-sm font-medium text-sui-ink">{{ __('Delete this plan') }}</p>
                        <p class="mt-1 text-xs leading-normal text-ink-500">
                            {{ __('The tasks under it go too. The idea it came from stays where it is.') }}
                        </p>

                        <template x-if="! confirming">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="mt-3 !text-sui-danger"
                                x-on:click="confirming = true"
                            >
                                {{ __('Delete plan') }}
                            </flux:button>
                        </template>

                        <template x-if="confirming">
                            <div class="mt-3 flex items-center gap-2">
                                <flux:button variant="danger" size="sm" wire:click="delete" wire:loading.attr="disabled">
                                    {{ __('Yes, delete it') }}
                                </flux:button>
                                <flux:button variant="ghost" size="sm" x-on:click="confirming = false">
                                    {{ __('Keep it') }}
                                </flux:button>
                            </div>
                        </template>
                    </div>
                </x-app.ui.section>
            @endif
        </div>
    </div>
</div>
