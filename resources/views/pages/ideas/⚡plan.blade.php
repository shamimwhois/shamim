<?php

use App\Actions\Ideas\DeleteIdeaTask;
use App\Actions\Ideas\ReorderIdeaTasks;
use App\Actions\Ideas\SaveIdeaTask;
use App\Enums\AccountPermission;
use App\Enums\TaskStatus;
use App\Models\IdeaPlan;
use App\Models\IdeaTask;
use App\Support\MarkdownRenderer;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One plan, and the checklist that tells you whether it is moving.
 *
 * The tasks are edited here rather than on a screen of their own. A task is a line,
 * and a line that needs its own URL, its own form, and its own navigation is a line
 * nobody will write down: the whole value of the checklist is that it sits under the
 * plan it belongs to and can be changed while the plan is being read.
 *
 * Every write below resolves the task through `$this->shownPlan->tasks()` first, so a
 * task that belongs to another account's plan is not found rather than refused, and no
 * id sent by a browser can reach somebody else's checklist. The plan itself is never
 * edited from this screen; that is `pages::ideas.plan-edit`, and the split is what
 * keeps the document and its work in one place without making either a form field of
 * the other.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Plan')] class extends Component
{
    /**
     * The plan being read.
     *
     * Not named `$plan`, because the route binds a parameter called `plan` and
     * Livewire would copy its slug onto a property of the same name.
     */
    public ?IdeaPlan $shownPlan = null;

    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    public string $newTask = '';

    public ?int $editingTaskId = null;

    public string $editingTaskTitle = '';

    public string $editingTaskStatus = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::UseIdeas), 403);
    }

    public function mount(IdeaPlan $plan): void
    {
        // A plan is never public, so an account that cannot see it gets a 404 and no
        // confirmation that the slug exists.
        abort_unless($plan->isVisibleTo(auth()->user()), 404);

        $this->shownPlan = $plan;
        $this->shownPlan->loadMissing(['user:id,name', 'idea:id,title,slug']);

        app(Seo::class)->set([
            'title' => $plan->title,
            'description' => $plan->excerpt(160),
            'noindex' => true,
        ]);
    }

    public function updatingTab(): void
    {
        $this->reset('editingTaskId', 'editingTaskTitle', 'editingTaskStatus');
    }

    /**
     * Add a task to the bottom of this plan's list.
     */
    public function addTask(SaveIdeaTask $saveTask): void
    {
        $this->validate([
            'newTask' => ['required', 'string', 'max:'.IdeaTask::TITLE_MAX],
        ]);

        try {
            $saveTask->handle(
                ['title' => $this->newTask],
                plan: $this->authorizePlan(),
            );
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reset('newTask');
        unset($this->tasks, $this->progress);
    }

    /**
     * Put a task into inline editing.
     */
    public function editTask(int $taskId): void
    {
        $task = $this->findTask($taskId);

        $this->editingTaskId = $task->id;
        $this->editingTaskTitle = $task->title;
        $this->editingTaskStatus = $task->status->value;
    }

    public function cancelEditTask(): void
    {
        $this->reset('editingTaskId', 'editingTaskTitle', 'editingTaskStatus');
        $this->resetValidation();
    }

    /**
     * Save the task that is being edited inline.
     */
    public function updateTask(SaveIdeaTask $saveTask): void
    {
        if ($this->editingTaskId === null) {
            return;
        }

        $task = $this->findTask($this->editingTaskId);

        $this->validate([
            'editingTaskTitle' => ['required', 'string', 'max:'.IdeaTask::TITLE_MAX],
            'editingTaskStatus' => ['required', 'in:'.implode(',', array_column(TaskStatus::cases(), 'value'))],
        ]);

        try {
            $saveTask->handle(
                [
                    'title' => $this->editingTaskTitle,
                    'status' => $this->editingTaskStatus,
                ],
                task: $task,
            );
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->cancelEditTask();
        unset($this->tasks, $this->progress);
    }

    /**
     * Tick or un-tick a task without opening the editor.
     */
    public function toggleTask(int $taskId, SaveIdeaTask $saveTask): void
    {
        $task = $this->findTask($taskId);

        $saveTask->handle(
            ['status' => $task->isDone() ? TaskStatus::Todo : TaskStatus::Done],
            task: $task,
        );

        unset($this->tasks, $this->progress);
    }

    /**
     * Move a task one place up or down inside this plan.
     */
    public function moveTask(int $taskId, string $direction, ReorderIdeaTasks $reorder): void
    {
        $task = $this->findTask($taskId);

        $reorder->handle($task, $direction);

        unset($this->tasks);
    }

    public function deleteTask(int $taskId, DeleteIdeaTask $deleteTask): void
    {
        $task = $this->findTask($taskId);

        $deleteTask->handle($task);

        if ($this->editingTaskId === $taskId) {
            $this->cancelEditTask();
        }

        unset($this->tasks, $this->progress);

        Flux::toast(variant: 'success', text: __('Task removed.'));
    }

    /**
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'open' => __('Open'),
            'blocked' => TaskStatus::Blocked->label(),
            'done' => __('Done'),
            'all' => __('All'),
        ];
    }

    /**
     * The tasks this screen is showing.
     *
     * @return Collection<int, IdeaTask>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $query = $this->shownPlan->tasks();

        if ($this->tab === 'open') {
            $query->open();
        } elseif (($status = TaskStatus::tryFrom($this->tab)) !== null) {
            $query->inStatus($status);
        }

        return $query->get();
    }

    /**
     * How much of the plan is finished, as counts rather than a percentage.
     *
     * @return array{done: int, total: int}
     */
    #[Computed]
    public function progress(): array
    {
        $total = $this->shownPlan->tasks()->count();
        $done = $this->shownPlan->tasks()->where('status', TaskStatus::Done)->count();

        return ['done' => $done, 'total' => $total];
    }

    /**
     * The Markdown body of the plan, rendered once per read.
     */
    #[Computed]
    public function notesHtml(): string
    {
        return filled($this->shownPlan->notes)
            ? app(MarkdownRenderer::class)->render((string) $this->shownPlan->notes)
            : '';
    }

    /**
     * The sections that have been filled in, in the order a reader wants them.
     *
     * @return array<int, array{label: string, body: string}>
     */
    #[Computed]
    public function sections(): array
    {
        $plan = $this->shownPlan;

        return array_values(array_filter([
            ['label' => __('Problem'), 'body' => (string) $plan->problem_statement],
            ['label' => __('Proposed solution'), 'body' => (string) $plan->proposed_solution],
            ['label' => __('In scope'), 'body' => (string) $plan->scope],
            ['label' => __('Out of scope'), 'body' => (string) $plan->out_of_scope],
            ['label' => __('Goals'), 'body' => (string) $plan->goals],
        ], fn (array $section): bool => trim($section['body']) !== ''));
    }

    /**
     * Resolve a task through this plan, so ownership cannot be talked around.
     */
    protected function findTask(int $taskId): IdeaTask
    {
        $task = $this->shownPlan->tasks()->whereKey($taskId)->firstOrFail();

        Gate::authorize('update', $task);

        return $task;
    }

    /**
     * Confirm the signed-in account may write to the plan, and return it.
     */
    protected function authorizePlan(): IdeaPlan
    {
        Gate::authorize('update', $this->shownPlan);

        return $this->shownPlan;
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Plan"
        :title="$this->shownPlan->title"
        :description="$this->shownPlan->summary"
        :breadcrumbs="[
            ['label' => __('Ideas'), 'href' => route('ideas.dashboard')],
            ['label' => __('Plans'), 'href' => route('ideas.plans')],
            ['label' => __('Tasks')],
        ]"
    >
        <x-slot:actions>
            @if ($this->shownPlan->isOwnedBy(auth()->user()))
                <flux:button
                    :href="route('ideas.plan.edit', ['plan' => $this->shownPlan->slug])"
                    wire:navigate
                    variant="ghost"
                    size="sm"
                    icon="pencil-square"
                >
                    {{ __('Edit plan') }}
                </flux:button>
            @endif
        </x-slot:actions>
    </x-app.ui.header>

    {{-- ============================================================= --
     | The state of it
     --}}
    <div class="px-5 pt-6 sm:px-6 lg:px-10">
        <div class="sui-card flex flex-wrap items-center gap-x-6 gap-y-4 px-5 py-4">
            <x-app.ideas.status-badge :status="$this->shownPlan->status" size="sm" />

            @if ($this->shownPlan->priority !== null)
                <flux:badge :color="$this->shownPlan->priority->badgeColor()" size="sm">
                    {{ $this->shownPlan->priority->label() }}
                </flux:badge>
            @endif

            @if (filled($this->shownPlan->estimated_effort))
                <p class="text-xs text-ink-500">
                    {{ __('Estimated: :effort', ['effort' => $this->shownPlan->estimated_effort]) }}
                </p>
            @endif

            @if ($this->shownPlan->target_start_date !== null || $this->shownPlan->target_end_date !== null)
                <p class="text-xs text-ink-500">
                    {{ $this->shownPlan->target_start_date?->translatedFormat('j M Y') ?? __('no start') }}
                    <span aria-hidden="true"> &rarr; </span>
                    {{ $this->shownPlan->target_end_date?->translatedFormat('j M Y') ?? __('no end') }}
                </p>
            @endif

            @if ($this->shownPlan->idea !== null)
                <a
                    href="{{ route('ideas.show', ['idea' => $this->shownPlan->idea->slug]) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1 text-xs text-sui-accent hover:underline"
                >
                    <flux:icon name="light-bulb" class="size-3.5" />
                    {{ __('From idea: :title', ['title' => Str::limit($this->shownPlan->idea->title, 40)]) }}
                </a>
            @endif

            <p class="ml-auto font-mono text-2xs uppercase tracking-widest text-ink-400">
                {{ trans_choice(':count task|:count tasks', $this->progress['total'], ['count' => $this->progress['total']]) }}
                ·
                {{ $this->progress['done'] }}/{{ $this->progress['total'] }} {{ __('done') }}
            </p>
        </div>
    </div>

    <div class="grid gap-6 px-5 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:px-10">
        {{-- ============================================================= --
         | The checklist
         --}}
        <div class="space-y-6">
            <x-app.ui.section :padded="false">
                <x-slot:title>
                    <span class="flex items-center gap-2">
                        {{ __('Tasks') }}
                        @if ($this->progress['total'] > 0)
                            <span class="font-mono text-2xs font-normal uppercase tracking-widest text-ink-400">
                                {{ $this->progress['done'] }}/{{ $this->progress['total'] }}
                            </span>
                        @endif
                    </span>
                </x-slot:title>

                <x-slot:actions>
                    <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter tasks') }}">
                        @foreach ($this->tabs() as $key => $label)
                            <button
                                type="button"
                                role="tab"
                                wire:key="task-tab-{{ $key }}"
                                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                wire:click="$set('tab', '{{ $key }}')"
                                @class([
                                    'sui-focus rounded-full border px-2.5 py-0.5 text-2xs font-medium transition duration-150 ease-soft',
                                    'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $tab === $key,
                                    'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $tab !== $key,
                                ])
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </x-slot:actions>

                @if ($this->shownPlan->isOwnedBy(auth()->user()))
                    <form wire:submit="addTask" class="border-b border-sui-hairline px-5 py-4">
                        <div class="flex items-center gap-2">
                            <label for="new-task" class="sr-only">{{ __('New task') }}</label>
                            <input
                                id="new-task"
                                type="text"
                                wire:model="newTask"
                                wire:keydown.enter.prevent="addTask"
                                placeholder="{{ __('One more line. Enter saves it.') }}"
                                autocomplete="off"
                                class="sui-control min-w-0 flex-1"
                            />
                            <flux:button type="submit" variant="primary" size="sm" icon="plus">
                                {{ __('Add') }}
                            </flux:button>
                        </div>
                        @error('newTask')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                        @error('editingTaskTitle')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </form>
                @endif

                @if ($this->tasks->isEmpty())
                    <div class="sui-empty">
                        <p class="text-sm text-ink-500">
                            @if ($tab === 'open' && $this->progress['total'] > 0)
                                {{ __('Everything on this plan is done. Choose Done to read the list.') }}
                            @elseif ($this->progress['total'] > 0)
                                {{ __('Nothing in this view. Choose another tab.') }}
                            @else
                                {{ __('No tasks yet. Write the first one above, one line at a time.') }}
                            @endif
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-sui-hairline">
                        @foreach ($this->tasks as $task)
                            <li wire:key="task-{{ $task->id }}" class="px-5 py-3">
                                @if ($editingTaskId === $task->id)
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label for="editing-task-title" class="sr-only">{{ __('Task') }}</label>
                                        <input
                                            id="editing-task-title"
                                            type="text"
                                            wire:model="editingTaskTitle"
                                            class="sui-control min-w-0 flex-1"
                                        />
                                        <select
                                            wire:model="editingTaskStatus"
                                            aria-label="{{ __('Status') }}"
                                            class="sui-control w-auto shrink-0"
                                        >
                                            @foreach (TaskStatus::options() as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                        <flux:button variant="primary" size="xs" wire:click="updateTask" icon="check">
                                            {{ __('Save') }}
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" wire:click="cancelEditTask">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                    </div>
                                @else
                                    <div class="flex items-start gap-3">
                                        <button
                                            type="button"
                                            wire:click="toggleTask({{ $task->id }})"
                                            aria-pressed="{{ $task->isDone() ? 'true' : 'false' }}"
                                            aria-label="{{ $task->isDone() ? __('Mark as not done') : __('Mark as done') }}"
                                            @class([
                                                'sui-focus mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border transition duration-150 ease-soft',
                                                'border-sui-accent bg-sui-accent text-white' => $task->isDone(),
                                                'border-sui-hairline text-transparent hover:border-sui-accent/50' => ! $task->isDone(),
                                            ])
                                        >
                                            <flux:icon name="check" class="size-3" />
                                        </button>

                                        <div class="min-w-0 flex-1">
                                            <p
                                                @class([
                                                    'text-sm',
                                                    'text-ink-400 line-through' => $task->isDone(),
                                                    'text-sui-ink' => ! $task->isDone(),
                                                ])
                                            >
                                                {{ $task->title }}
                                            </p>

                                            @if (filled($task->description))
                                                <p class="mt-1 text-xs leading-normal text-ink-500">
                                                    {{ $task->description }}
                                                </p>
                                            @endif

                                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                                <x-app.ideas.status-badge :status="$task->status" size="sm" />

                                                @if ($task->priority !== null)
                                                    <flux:badge :color="$task->priority->badgeColor()" size="sm">
                                                        {{ $task->priority->label() }}
                                                    </flux:badge>
                                                @endif

                                                @if ($task->due_date !== null)
                                                    <span @class([
                                                        'text-2xs',
                                                        'text-sui-danger' => $task->isOverdue(),
                                                        'text-ink-500' => ! $task->isOverdue(),
                                                    ])>
                                                        {{ $task->isOverdue() ? __('Late since') : __('Due') }}
                                                        {{ $task->due_date->translatedFormat('j M Y') }}
                                                    </span>
                                                @endif

                                                @if ($task->completed_at !== null)
                                                    <span class="text-2xs text-ink-400">
                                                        {{ __('Finished :date', ['date' => $task->completed_at->translatedFormat('j M Y')]) }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        @if ($this->shownPlan->isOwnedBy(auth()->user()))
                                            <div class="flex shrink-0 items-center gap-0.5" x-data="{ confirming: false }">
                                                <flux:button
                                                    variant="ghost"
                                                    size="xs"
                                                    icon="chevron-up"
                                                    wire:click="moveTask({{ $task->id }}, 'up')"
                                                >
                                                    <span class="sr-only">{{ __('Move up') }}</span>
                                                </flux:button>
                                                <flux:button
                                                    variant="ghost"
                                                    size="xs"
                                                    icon="chevron-down"
                                                    wire:click="moveTask({{ $task->id }}, 'down')"
                                                >
                                                    <span class="sr-only">{{ __('Move down') }}</span>
                                                </flux:button>
                                                <flux:button
                                                    variant="ghost"
                                                    size="xs"
                                                    icon="pencil-square"
                                                    wire:click="editTask({{ $task->id }})"
                                                >
                                                    <span class="sr-only">{{ __('Edit task') }}</span>
                                                </flux:button>

                                                <template x-if="! confirming">
                                                    <flux:button
                                                        variant="ghost"
                                                        size="xs"
                                                        icon="trash"
                                                        class="!text-sui-danger"
                                                        x-on:click="confirming = true"
                                                    >
                                                        <span class="sr-only">{{ __('Delete task') }}</span>
                                                    </flux:button>
                                                </template>

                                                <template x-if="confirming">
                                                    <div class="flex items-center gap-1">
                                                        <flux:button
                                                            variant="danger"
                                                            size="xs"
                                                            wire:click="deleteTask({{ $task->id }})"
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
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-app.ui.section>

            @if ($this->notesHtml !== '')
                <x-app.ui.section :title="__('Notes')">
                    <div class="prose-editorial text-sm leading-relaxed text-ink-700">{!! $this->notesHtml !!}</div>
                </x-app.ui.section>
            @endif
        </div>

        {{-- ============================================================= --
         | The document
         --}}
        <div class="space-y-6">
            @if ($this->sections !== [])
                <x-app.ui.section :title="__('The plan')">
                    <dl class="space-y-4">
                        @foreach ($this->sections as $section)
                            <div>
                                <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400">
                                    {{ $section['label'] }}
                                </dt>
                                <dd class="mt-1 text-sm leading-normal text-ink-700">
                                    {{ $section['body'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-app.ui.section>
            @endif

            <x-app.ui.section :title="__('Written by')">
                <p class="text-sm text-ink-700">{{ $this->shownPlan->user->name }}</p>
                <p class="mt-1 text-2xs text-ink-500">
                    {{ __('Created :created · updated :updated', [
                        'created' => $this->shownPlan->created_at?->translatedFormat('j F Y'),
                        'updated' => $this->shownPlan->updated_at?->translatedFormat('j F Y'),
                    ]) }}
                </p>
            </x-app.ui.section>
        </div>
    </div>
</div>
