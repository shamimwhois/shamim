<?php

use App\Actions\Ideas\ConvertIdeaToPlan;
use App\Actions\Ideas\DeleteIdea;
use App\Actions\Ideas\SaveIdea;
use App\Enums\AccountPermission;
use App\Enums\ContentVisibility;
use App\Enums\IdeaPotential;
use App\Enums\IdeaStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Models\IdeaPlan;
use App\Models\Topic;
use App\Rules\IdeaSlug;
use App\Services\PublicTaxonomy;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Create and edit one idea.
 *
 * One screen for both, the way `admin/posts.edit` works: the fields are
 * identical, only the starting values and the verbs differ, and two components
 * would be two places to keep the publish rule in sync.
 *
 * The write is a single call to {@see SaveIdea}. Nothing here decides a slug, a
 * timestamp or a status transition; those are the action's, so a request built
 * by hand meets the same rules as the button. The only validation this screen
 * adds beyond the action's is *shape* — a title that is long enough to read, a
 * slug that is legal in a URL and does not shadow another screen.
 *
 * The status picker offers only the transitions the workflow permits. That is
 * presentation, not security: the action refuses an illegal move even if the
 * option somehow reaches it, and the refusal is surfaced rather than swallowed.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Idea')] class extends Component
{
    /**
     * The row being edited, null while creating.
     *
     * Not named `$idea`: Livewire copies any route parameter that matches a
     * public property name onto that property, and the bound model in
     * `/ideas/{idea:slug}/edit` arrives as a slug string. Naming it after its own
     * URL segment would type a string into a `?Idea`.
     */
    public ?Idea $editingIdea = null;

    public string $title = '';

    public string $summary = '';

    public string $content = '';

    public string $slug = '';

    public string $status = '';

    public string $priority = '';

    public string $potential = '';

    public string $categoryId = '';

    /**
     * The public subjects this idea is filed under.
     *
     * Strings because a checkbox group hands values back as strings; the save action
     * casts them. Kept separate from `$categoryId`, which is the account's own
     * private folder and is not the same thing by any route.
     *
     * @var array<int, string>
     */
    public array $topicIds = [];

    public string $visibility = ContentVisibility::Private->value;

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::UseIdeas), 403);
    }

    public function mount(?Idea $idea = null): void
    {
        if ($idea?->exists) {
            // A 404 rather than a 403: an id that is somebody else's must not
            // confirm that the row exists.
            abort_unless($idea->isOwnedBy(auth()->user()), 404);

            $this->editingIdea = $idea;
            $this->title = $idea->title;
            $this->summary = (string) $idea->summary;
            $this->content = (string) $idea->content;
            $this->slug = $idea->slug;
            $this->status = $idea->status->value;
            $this->priority = $idea->priority?->value ?? '';
            $this->potential = $idea->potential?->value ?? '';
            $this->categoryId = (string) ($idea->idea_category_id ?? '');
            $this->topicIds = $idea->topics->pluck('id')->map(fn (int $id): string => (string) $id)->all();
            $this->visibility = $idea->visibility->value;
        } else {
            $this->status = IdeaStatus::Inbox->value;
        }

        app(Seo::class)->set([
            'title' => $this->editingIdea === null ? 'New idea' : $this->editingIdea->title,
            'description' => 'Write down an idea and decide what happens to it.',
            'noindex' => true,
        ]);
    }

    /**
     * Save the idea, creating it when there is nothing to update.
     */
    public function save(SaveIdea $saveIdea): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:'.Idea::TITLE_MAX],
            'summary' => ['nullable', 'string', 'max:'.Idea::SUMMARY_MAX],
            'content' => ['nullable', 'string', 'max:'.(Idea::CONTENT_MAX_KILOBYTES * 1024)],
            'slug' => ['nullable', 'string', 'max:120', new IdeaSlug($this->editingIdea?->id)],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(IdeaStatus::cases(), 'value'))],
            'priority' => ['nullable', 'string', 'in:'.implode(',', array_column(WorkPriority::cases(), 'value'))],
            'potential' => ['nullable', 'string', 'in:'.implode(',', array_column(IdeaPotential::cases(), 'value'))],
            'visibility' => ['required', 'in:'.implode(',', array_column(ContentVisibility::cases(), 'value'))],
            'topicIds' => ['array', 'max:'.Topic::MAX_PER_ROW],
            'topicIds.*' => ['integer'],
        ]);

        try {
            $idea = $saveIdea->handle(auth()->user(), [
                'title' => $this->title,
                'summary' => $this->summary ?: null,
                'content' => $this->content ?: null,
                'slug' => $this->slug ?: null,
                'status' => $this->status,
                'priority' => $this->priority ?: null,
                'potential' => $this->potential ?: null,
                'idea_category_id' => $this->categoryId ?: null,
                'topics' => $this->topicIds,
                'visibility' => $this->visibility,
            ], $this->editingIdea);
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $isNew = $this->editingIdea === null;

        if ($isNew) {
            $this->editingIdea = $idea;
            $this->slug = $idea->slug;
        }

        unset($this->availableStatuses, $this->ideaCategories, $this->availableTopics);

        Flux::toast(variant: 'success', text: $isNew ? __('Idea created.') : __('Saved.'));

        if ($isNew) {
            $this->redirectRoute('ideas.edit', ['idea' => $idea->slug], navigate: true);
        }
    }

    /**
     * Delete the idea this screen is editing.
     */
    public function delete(DeleteIdea $deleteIdea): void
    {
        if ($this->editingIdea === null) {
            return;
        }

        $this->authorize('delete', $this->editingIdea);

        $deleteIdea->handle($this->editingIdea);

        Flux::toast(variant: 'success', text: __('Idea deleted.'));

        $this->redirectRoute('ideas.dashboard', navigate: true);
    }

    /**
     * Turn this idea into a plan, then take the writer to the plan to fill it in.
     *
     * Conversion is the start of the document rather than the end of the idea, so the
     * redirect goes to the plan's form with its sections still blank. Everything the
     * idea already said has been copied across by the action.
     */
    public function convertToPlan(ConvertIdeaToPlan $convert): void
    {
        if ($this->editingIdea === null) {
            return;
        }

        $this->authorize('update', $this->editingIdea);

        try {
            $plan = $convert->handle($this->editingIdea);
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('ideas.plan.edit', ['plan' => $plan->slug], navigate: true);
    }

    /**
     * The plan this idea already became, if it has become one.
     */
    #[Computed]
    public function sourcePlan(): ?IdeaPlan
    {
        return $this->editingIdea?->plan;
    }

    /**
     * The stages this idea may move to, current one included.
     *
     * An account that wants to keep an idea where it is must still be able to
     * submit the form, so the present stage is always an option even though it is
     * not, strictly, a transition.
     *
     * @return Collection<int, IdeaStatus>
     */
    #[Computed]
    public function availableStatuses(): Collection
    {
        $current = $this->editingIdea?->status ?? IdeaStatus::Inbox;

        return collect([$current, ...$current->allowedTransitions()]);
    }

    /**
     * The signed-in account's own categories.
     *
     * @return Collection<int, IdeaCategory>
     */
    #[Computed]
    public function ideaCategories(): Collection
    {
        return IdeaCategory::query()
            ->authoredBy(auth()->user())
            ->get();
    }

    /**
     * The subjects an author may file under.
     *
     * From the shared gate rather than a plain `Topic::all()`, so this form and the
     * public landing ask the same question: the picker offers what is switched on,
     * plus a retired subject this row already carries so it can be un-checked.
     *
     * @return Collection<int, Topic>
     */
    #[Computed]
    public function availableTopics(): Collection
    {
        return app(PublicTaxonomy::class)->pickerTopics();
    }

    /**
     * Whether the publish switch may be offered.
     *
     * Ties the control to the rule in {@see \App\Actions\Ideas\SyncIdeaVisibility}
     * rather than to a preference, so the form cannot present a choice the action
     * is going to refuse.
     */
    #[Computed]
    public function mayPublish(): bool
    {
        return IdeaStatus::tryFrom($this->status)?->isPublishable() ?? false;
    }

    /**
     * The idea's current stage, for the provenance line.
     */
    #[Computed]
    public function currentStatus(): IdeaStatus
    {
        return IdeaStatus::tryFrom($this->status) ?? IdeaStatus::Inbox;
    }
}; ?>

<div>
    <x-app.ui.header
        :eyebrow="$this->editingIdea === null ? __('New idea') : __('Edit')"
        :title="$this->editingIdea === null ? __('Capture an idea') : $this->editingIdea->title"
        description="Write it while it is cheap to lose. Structure can come later."
        :breadcrumbs="[['label' => __('Ideas'), 'href' => route('ideas.dashboard')], ['label' => $this->editingIdea === null ? __('New idea') : __('Edit')]]"
    >
        <x-slot:actions>
            @if ($this->editingIdea !== null && $this->editingIdea->isPublished())
                <flux:button :href="route('ideas.show', ['idea' => $this->editingIdea->slug])" wire:navigate variant="ghost" icon="arrow-top-right-on-square">
                    {{ __('View live') }}
                </flux:button>
            @endif
        </x-slot:actions>
    </x-app.ui.header>

    <div class="grid gap-6 px-5 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] lg:px-10">
        <form wire:submit="save" class="space-y-6">
            {{-- ============================================================= --
             | The thought
             --}}
            <section aria-labelledby="idea-writing-heading" class="sui-card space-y-5 p-5">
                <h2 id="idea-writing-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                    {{ __('The idea') }}
                </h2>

                <div>
                    <label for="idea-title" class="sui-control-label">{{ __('Title') }}</label>
                    <input
                        id="idea-title"
                        type="text"
                        wire:model="title"
                        placeholder="{{ __('What is the thought, in one line?') }}"
                        autocomplete="off"
                        class="sui-control mt-2 w-full"
                    />
                    @error('title')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="idea-summary" class="sui-control-label">
                        {{ __('Summary') }}
                        <span class="font-normal text-ink-400">{{ __('(shown on cards and in search results)') }}</span>
                    </label>
                    <textarea
                        id="idea-summary"
                        wire:model="summary"
                        rows="2"
                        placeholder="{{ __('One or two sentences. What would this be worth if it happened?') }}"
                        class="sui-control mt-2 w-full resize-y text-sm leading-normal"
                    ></textarea>
                    @error('summary')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="idea-content" class="sui-control-label">
                        {{ __('Notes') }}
                        <span class="font-normal text-ink-400">{{ __('(Markdown)') }}</span>
                    </label>
                    <textarea
                        id="idea-content"
                        wire:model="content"
                        rows="14"
                        placeholder="{{ __('Everything else: where the thought came from, what it would take, who it is for.') }}"
                        class="sui-control mt-2 w-full resize-y font-mono text-xs leading-relaxed"
                    ></textarea>
                    @error('content')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">
                    {{ $this->editingIdea === null ? __('Create idea') : __('Save changes') }}
                </flux:button>

                <flux:button :href="route('ideas.dashboard')" wire:navigate variant="ghost">
                    {{ __('Back to ideas') }}
                </flux:button>

                @if ($this->editingIdea !== null)
                    <span class="ml-auto text-2xs text-ink-400">
                        {{ __('Created :date · updated :updated', [
                            'date' => $this->editingIdea->created_at?->format('j M Y'),
                            'updated' => $this->editingIdea->updated_at?->diffForHumans(),
                        ]) }}
                    </span>
                @endif
            </div>
        </form>

        {{-- ============================================================= --
         | Filing
         --}}
        <aside aria-labelledby="idea-filing-heading" class="space-y-6">
            <section class="sui-card space-y-5 p-5" aria-labelledby="idea-stage-heading">
                <h2 id="idea-stage-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                    {{ __('Stage') }}
                </h2>

                <div>
                    <label for="idea-status" class="sui-control-label">{{ __('Status') }}</label>
                    <select id="idea-status" wire:model.live="status" class="sui-control mt-2 w-full">
                        @foreach ($this->availableStatuses as $available)
                            <option value="{{ $available->value }}">{{ $available->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-2xs leading-normal text-ink-400">
                        {{ $this->currentStatus->isPublishable()
                            ? __('Approved ideas can be published.')
                            : __('Only an approved idea can be published.') }}
                    </p>
                    @error('status')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="idea-priority" class="sui-control-label">{{ __('Priority') }}</label>
                    <select id="idea-priority" wire:model="priority" class="sui-control mt-2 w-full">
                        <option value="">{{ __('Not set') }}</option>
                        @foreach (WorkPriority::options() as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="idea-potential" class="sui-control-label">{{ __('Potential') }}</label>
                    <select id="idea-potential" wire:model="potential" class="sui-control mt-2 w-full">
                        <option value="">{{ __('Not rated') }}</option>
                        @foreach (IdeaPotential::options() as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="idea-category" class="sui-control-label">{{ __('Category') }}</label>
                    <select id="idea-category" wire:model="categoryId" class="sui-control mt-2 w-full">
                        <option value="">{{ __('Uncategorised') }}</option>
                        @foreach ($this->ideaCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @if ($this->ideaCategories->isEmpty())
                        <p class="mt-1.5 text-2xs leading-normal text-ink-400">
                            {{ __('You have no categories yet.') }}
                            <a href="{{ route('ideas.categories') }}" wire:navigate class="text-sui-accent hover:underline">{{ __('Create one') }}</a>.
                        </p>
                    @endif
                </div>

                {{-- Public subjects. Deliberately beside the category rather than
                     replacing it: the folder above is the account's own filing and
                     stays private, this list is what a stranger browses by. --}}
                <div>
                    <span class="sui-control-label">{{ __('Subjects') }}</span>
                    @php $subjects = $this->availableTopics; @endphp
                    @if ($subjects->isEmpty())
                        <p class="mt-2 text-xs leading-normal text-ink-400">
                            {{ __('No subjects have been published on this site yet, so nothing can be filed here.') }}
                        </p>
                    @else
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($subjects as $subject)
                                <label class="inline-flex items-center gap-1.5 rounded-lg border border-sui-hairline bg-sui-well px-2.5 py-1.5 text-xs">
                                    <input
                                        type="checkbox"
                                        wire:model.live="topicIds"
                                        value="{{ $subject->id }}"
                                        class="sui-focus size-4 shrink-0 accent-sui-accent"
                                    />
                                    <span class="text-ink-600">{{ $subject->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-2 text-2xs leading-normal text-ink-400">
                            {{ __('Only published ideas appear under a subject, and at most :max fit on one idea.', ['max' => Topic::MAX_PER_ROW]) }}
                        </p>
                    @endif
                    @error('topicIds') <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p> @enderror
                </div>
            </section>

            {{-- ========================================================= --
             | Visibility
             --}}
            <section class="sui-card space-y-4 p-5" aria-labelledby="idea-visibility-heading">
                <h2 id="idea-visibility-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                    {{ __('Who can read it') }}
                </h2>

                <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="{{ __('Visibility') }}">
                    @foreach ([ContentVisibility::Private, ContentVisibility::Public] as $option)
                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $visibility === $option->value ? 'true' : 'false' }}"
                            wire:click="$set('visibility', '{{ $option->value }}')"
                            wire:loading.attr="disabled"
                            @disabled($option->isPublic() && ! $this->mayPublish && $visibility !== ContentVisibility::Public->value)
                            @class([
                                'sui-focus flex-1 rounded-lg border px-3 py-2 text-left transition duration-150 ease-soft',
                                'border-sui-accent/30 bg-sui-accent-soft' => $visibility === $option->value,
                                'border-sui-hairline hover:bg-sui-hover' => $visibility !== $option->value,
                                'cursor-not-allowed opacity-50' => $option->isPublic() && ! $this->mayPublish && $visibility !== ContentVisibility::Public->value,
                            ])
                        >
                            <span @class([
                                'block text-sm font-medium',
                                'text-sui-accent-ink' => $visibility === $option->value,
                                'text-ink-700' => $visibility !== $option->value,
                            ])>
                                {{ $option->label() }}
                            </span>
                            <span class="mt-0.5 block text-2xs leading-normal text-ink-500">
                                {{ $option->isPublic() ? __('Lists on /ideas and reads as a page.') : __('Only you can open it.') }}
                            </span>
                        </button>
                    @endforeach
                </div>

                @if (! $this->mayPublish && $visibility !== ContentVisibility::Public->value)
                    <p class="text-2xs leading-normal text-ink-400">
                        {{ __('Publishing unlocks once the idea reaches Approved.') }}
                    </p>
                @endif

                @if ($this->editingIdea?->published_at !== null)
                    <p class="text-2xs leading-normal text-ink-400">
                        {{ __('First published :date.', ['date' => $this->editingIdea->published_at->format('j M Y')]) }}
                    </p>
                @endif

                <div>
                    <label for="idea-slug" class="sui-control-label">{{ __('URL') }}</label>
                    <div class="mt-2 flex items-center gap-1.5">
                        <span class="shrink-0 text-xs text-ink-400">/ideas/</span>
                        <input
                            id="idea-slug"
                            type="text"
                            wire:model="slug"
                            placeholder="{{ __('generated from the title') }}"
                            autocomplete="off"
                            spellcheck="false"
                            class="sui-control w-full font-mono text-xs"
                        />
                    </div>
                    @error('slug')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                    <p class="mt-1.5 text-2xs leading-normal text-ink-400">
                        {{ __('Leave it blank to generate one. A saved idea keeps its URL even when renamed.') }}
                    </p>
                </div>
            </section>

            @if ($this->editingIdea !== null)
                {{-- ===================================================== --
                 | What it became
                 --}}
                <section class="sui-card space-y-3 p-5" aria-labelledby="idea-plan-heading">
                    <h2 id="idea-plan-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                        {{ __('Plan') }}
                    </h2>

                    @if ($this->sourcePlan !== null)
                        <p class="text-xs leading-normal text-ink-600">
                            {{ __('This idea became a plan. The idea stays as it was, and the work is written on the other side.') }}
                        </p>

                        <a
                            href="{{ route('ideas.plan', ['plan' => $this->sourcePlan->slug]) }}"
                            wire:navigate
                            class="flex items-center gap-2 rounded-lg border border-sui-hairline px-3 py-2 transition duration-150 ease-soft hover:bg-sui-hover"
                        >
                            <flux:icon name="queue-list" class="size-4 text-sui-accent" />
                            <span class="min-w-0 flex-1 truncate text-sm text-sui-ink">{{ $this->sourcePlan->title }}</span>
                            <x-app.ideas.status-badge :status="$this->sourcePlan->status" size="sm" />
                        </a>
                    @elseif ($this->currentStatus->isPublishable())
                        <p class="text-xs leading-normal text-ink-600">
                            {{ __('Approved, so this is worth writing down properly. Your summary and notes come with it, and the idea is not moved or copied.') }}
                        </p>

                        <flux:button
                            wire:click="convertToPlan"
                            wire:loading.attr="disabled"
                            variant="primary"
                            size="sm"
                            icon="queue-list"
                        >
                            {{ __('Convert to plan') }}
                        </flux:button>
                    @else
                        <p class="text-2xs leading-normal text-ink-400">
                            {{ __('Conversion opens once the idea reaches Approved, so that a plan is only ever written for something already decided.') }}
                        </p>
                    @endif
                </section>
            @endif

            @if ($this->editingIdea !== null)
                {{-- ===================================================== --
                 | Danger
                 --}}
                <section class="rounded-xl border border-sui-danger/25 bg-sui-danger-soft p-5" aria-labelledby="idea-danger-heading" x-data="{ confirming: false }">
                    <h2 id="idea-danger-heading" class="text-sm font-semibold tracking-tight text-sui-danger">
                        {{ __('Delete this idea') }}
                    </h2>

                    <p class="mt-1.5 text-xs leading-normal text-ink-600">
                        {{ __('Deletion is final. Anything already published stops being readable the moment this is done.') }}
                    </p>

                    <div class="mt-4">
                        <template x-if="! confirming">
                            <flux:button variant="ghost" size="xs" x-on:click="confirming = true" icon="trash" class="!text-sui-danger">
                                {{ __('Delete idea') }}
                            </flux:button>
                        </template>

                        <template x-if="confirming">
                            <div class="flex items-center gap-2">
                                <flux:button variant="danger" size="xs" wire:click="delete" wire:loading.attr="disabled">
                                    {{ __('Yes, delete it') }}
                                </flux:button>
                                <flux:button variant="ghost" size="xs" x-on:click="confirming = false">
                                    {{ __('Keep it') }}
                                </flux:button>
                            </div>
                        </template>
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>
