<?php

use App\Actions\About\DeleteAboutItem;
use App\Actions\About\DeleteAboutSection;
use App\Actions\About\SaveAboutItem;
use App\Actions\About\SaveAboutSection;
use App\Enums\AccountPermission;
use App\Enums\AboutItemType;
use App\Enums\AboutSectionType;
use App\Models\AboutItem;
use App\Models\AboutSection;
use App\Services\AboutContent;
use App\Support\Seo;
use App\Support\WorkflowTaxonomy;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * About page content manager.
 *
 * Two panes over one job: the left owns the section skeleton (which bands the
 * page shows, in what order, under what wording), the right owns the rows that
 * live inside the selected section. Every write goes through the same actions
 * the rest of the app uses, so the public page and this console can never
 * disagree about what a valid row is.
 *
 * ## Authorisation
 *
 * The route requires `about:manage`. Every mutating method re-checks it rather
 * than trusting the page guard, because `wire:click` is client input — the
 * guard on the route proves the operator could open the console, not that they
 * may act on a particular row. (Same convention as the marketplace console.)
 *
 * ## Anchor stability
 *
 * A section's `key` is the scrollspy anchor on the public page. It is prefilled
 * on edit and only regenerated when explicitly changed, so rewording a label
 * can never break in-page links.
 */
new #[Layout('layouts::admin.dashboard')] #[Title('About content')] class extends Component
{
    /** The section whose rows the right-hand pane shows. */
    #[Url(as: 'section', except: '')]
    public string $sectionKey = '';

    /**
     * Section form (create or edit).
     */
    public ?int $editingSectionId = null;

    #[Validate('nullable|string|max:64')]
    public string $sectionKeyInput = '';

    #[Validate('required|string')]
    public string $sectionType = '';

    #[Validate('nullable|string|max:120')]
    public string $sectionLabel = '';

    #[Validate('nullable|string|max:200')]
    public string $sectionHeading = '';

    #[Validate('nullable|string|max:500')]
    public string $sectionLead = '';

    #[Validate('nullable|string|max:2000')]
    public string $sectionBody = '';

    #[Validate('nullable|string|max:64')]
    public string $sectionIcon = '';

    #[Validate('required|integer|min:0|max:999')]
    public int $sectionOrdinal = 0;

    #[Validate('required|integer|min:0|max:999')]
    public int $sectionSortOrder = 0;

    public bool $sectionVisible = true;

    /**
     * Item form (create or edit, inside the selected section).
     */
    public ?int $editingItemId = null;

    #[Validate('required|string')]
    public string $itemType = AboutItemType::Entry->value;

    #[Validate('required|string|max:200')]
    public string $itemTitle = '';

    #[Validate('nullable|string|max:200')]
    public string $itemSubtitle = '';

    #[Validate('nullable|string|max:2000')]
    public string $itemBody = '';

    #[Validate('nullable|string|max:120')]
    public string $itemValue = '';

    #[Validate('nullable|string|max:500')]
    public string $itemTags = '';

    #[Validate('nullable|string|max:64')]
    public string $itemIcon = '';

    #[Validate('nullable|string|max:500')]
    public string $itemUrl = '';

    #[Validate('nullable|string|max:120')]
    public string $itemUrlLabel = '';

    #[Validate('nullable|string|max:120')]
    public string $itemPeriod = '';

    #[Validate('nullable|integer')]
    public ?int $itemParentId = null;

    #[Validate('required|integer|min:0|max:999')]
    public int $itemSortOrder = 0;

    public bool $itemVisible = true;

    /**
     * Workflow console state.
     *
     * The generic item form above speaks in "value / subtitle / period", which
     * is meaningless for a board card. These fields name the concepts the
     * workflow rows actually carry — stage, priority, agent, status, band, tone
     * — and {@see saveWorkflowItem()} maps them back onto the generic columns.
     * The schema stays generic; the console stops pretending it is.
     *
     * The vocabulary itself is not duplicated here: every select is fed from
     * {@see WorkflowTaxonomy}, the same registry the public board and graph read.
     */
    public string $wfKind = AboutItemType::WorkflowTask->value;

    public string $wfTitle = '';

    public string $wfIdentifier = '';

    public string $wfBody = '';

    public string $wfStage = WorkflowTaxonomy::FALLBACK_STAGE;

    public string $wfPriority = 'Feature';

    public string $wfAgent = '';

    public string $wfTarget = '';

    public string $wfTags = '';

    public string $wfStatus = 'active';

    public string $wfBand = 'header';

    public string $wfTone = 'cyan';

    public string $wfValue = '';

    public int $wfOrder = 0;

    public bool $wfVisible = true;

    public ?int $editingWorkflowId = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ManageAboutContent), 403);
    }

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'About content',
            'description' => 'Manage the sections and rows of the About page.',
            'noindex' => true,
        ]);
    }

    /**
     * Show a section's rows in the right-hand pane.
     */
    public function selectSection(string $key): void
    {
        $this->sectionKey = $key;
        $this->resetItemForm();
        $this->resetWorkflowForm();
    }

    /**
     * Load a section into the form for editing.
     */
    public function editSection(int $sectionId): void
    {
        $section = AboutSection::query()->findOrFail($sectionId);

        $this->editingSectionId = $section->id;
        $this->sectionKeyInput = $section->key;
        $this->sectionType = $section->type->value;
        $this->sectionLabel = $section->label ?? '';
        $this->sectionHeading = $section->heading ?? '';
        $this->sectionLead = $section->lead ?? '';
        $this->sectionBody = $section->body ?? '';
        $this->sectionIcon = $section->icon ?? '';
        $this->sectionOrdinal = $section->ordinal;
        $this->sectionSortOrder = $section->sort_order;
        $this->sectionVisible = $section->is_visible;

        $this->sectionKey = $section->key;
    }

    /**
     * Clear the section form back to "create".
     */
    public function resetSectionForm(): void
    {
        $this->reset(
            'editingSectionId', 'sectionKeyInput', 'sectionType', 'sectionLabel',
            'sectionHeading', 'sectionLead', 'sectionBody', 'sectionIcon',
            'sectionOrdinal', 'sectionSortOrder', 'sectionVisible',
        );
        $this->resetValidation();
    }

    /**
     * Create or update the section.
     */
    public function saveSection(): void
    {
        $this->authorizeManage();

        foreach ([
            'sectionKeyInput', 'sectionType', 'sectionLabel', 'sectionHeading',
            'sectionLead', 'sectionBody', 'sectionIcon', 'sectionOrdinal', 'sectionSortOrder',
        ] as $field) {
            $this->validateOnly($field);
        }

        $type = AboutSectionType::tryFrom($this->sectionType);

        if ($type === null) {
            $this->addError('sectionType', __('Choose a valid section type.'));

            return;
        }

        if (trim($this->sectionLabel) === '' && trim($this->sectionHeading) === '') {
            $this->addError('sectionLabel', __('Provide a label or a heading so the section can be named.'));

            return;
        }

        $section = app(SaveAboutSection::class)->handle(
            [
                'key' => $this->sectionKeyInput,
                'type' => $type,
                'ordinal' => $this->sectionOrdinal,
                'label' => trim($this->sectionLabel) !== '' ? trim($this->sectionLabel) : null,
                'heading' => trim($this->sectionHeading) !== '' ? trim($this->sectionHeading) : null,
                'lead' => trim($this->sectionLead) !== '' ? trim($this->sectionLead) : null,
                'body' => trim($this->sectionBody) !== '' ? trim($this->sectionBody) : null,
                'icon' => trim($this->sectionIcon) !== '' ? trim($this->sectionIcon) : null,
                'sort_order' => $this->sectionSortOrder,
                'is_visible' => $this->sectionVisible,
            ],
            $this->editingSectionId !== null
                ? AboutSection::query()->findOrFail($this->editingSectionId)
                : null,
        );

        $this->sectionKey = $section->key;
        $this->resetSectionForm();

        unset($this->sections, $this->selectedSection, $this->parentOptions);

        Flux::toast(variant: 'success', text: __('Section saved.'));
    }

    /**
     * Delete a section and, by database cascade, every row inside it.
     */
    public function deleteSection(int $sectionId): void
    {
        $this->authorizeManage();

        $section = AboutSection::query()->findOrFail($sectionId);

        app(DeleteAboutSection::class)->handle($section);

        if ($this->editingSectionId === $sectionId) {
            $this->resetSectionForm();
        }

        if ($this->sectionKey === $section->key) {
            $this->sectionKey = '';
        }

        unset($this->sections, $this->selectedSection, $this->parentOptions);

        Flux::toast(variant: 'success', text: __('Section deleted.'));
    }

    /**
     * Hide or reveal a section on the public page without deleting its rows.
     */
    public function toggleSectionVisibility(int $sectionId): void
    {
        $this->authorizeManage();

        $section = AboutSection::query()->findOrFail($sectionId);

        $section->update(['is_visible' => ! $section->is_visible]);

        unset($this->sections, $this->selectedSection);

        Flux::toast(
            variant: 'success',
            text: $section->is_visible ? __('Section is now visible.') : __('Section is now hidden.'),
        );
    }

    /**
     * Load a row into the item form for editing.
     */
    public function editItem(int $itemId): void
    {
        $item = AboutItem::query()->with('section')->findOrFail($itemId);

        $this->sectionKey = $item->section->key;
        $this->editingItemId = $item->id;
        $this->itemType = $item->type->value;
        $this->itemTitle = $item->title;
        $this->itemSubtitle = $item->subtitle ?? '';
        $this->itemBody = $item->body ?? '';
        $this->itemValue = $item->value ?? '';
        $this->itemTags = $item->tags ?? '';
        $this->itemIcon = $item->icon ?? '';
        $this->itemUrl = $item->url ?? '';
        $this->itemUrlLabel = $item->url_label ?? '';
        $this->itemPeriod = $item->period ?? '';
        $this->itemParentId = $item->parent_id;
        $this->itemSortOrder = $item->sort_order;
        $this->itemVisible = $item->is_visible;
    }

    /**
     * Clear the item form back to "create".
     */
    public function resetItemForm(): void
    {
        $this->reset(
            'editingItemId', 'itemType', 'itemTitle', 'itemSubtitle', 'itemBody',
            'itemValue', 'itemTags', 'itemIcon', 'itemUrl', 'itemUrlLabel',
            'itemPeriod', 'itemParentId', 'itemSortOrder', 'itemVisible',
        );
        $this->resetValidation();
    }

    /**
     * Create or update a row inside the selected section.
     */
    public function saveItem(): void
    {
        $this->authorizeManage();

        $section = $this->selectedSection;

        if ($section === null) {
            Flux::toast(variant: 'danger', text: __('Select a section first.'));

            return;
        }

        foreach ([
            'itemType', 'itemTitle', 'itemSubtitle', 'itemBody', 'itemValue', 'itemTags',
            'itemIcon', 'itemUrl', 'itemUrlLabel', 'itemPeriod', 'itemParentId', 'itemSortOrder',
        ] as $field) {
            $this->validateOnly($field);
        }

        $type = AboutItemType::tryFrom($this->itemType);

        if ($type === null) {
            $this->addError('itemType', __('Choose a valid row type.'));

            return;
        }

        // Flat sections have no tree to hang a row on.
        if (! $section->type->isHierarchical()) {
            $this->itemParentId = null;
        }

        if ($this->itemParentId !== null && $this->parentOptions->doesntContain('id', $this->itemParentId)) {
            $this->addError('itemParentId', __('That parent row does not belong to this section.'));

            return;
        }

        try {
            app(SaveAboutItem::class)->handle(
                $section,
                [
                    'type' => $type,
                    'title' => trim($this->itemTitle),
                    'subtitle' => trim($this->itemSubtitle) !== '' ? trim($this->itemSubtitle) : null,
                    'body' => trim($this->itemBody) !== '' ? trim($this->itemBody) : null,
                    'value' => trim($this->itemValue) !== '' ? trim($this->itemValue) : null,
                    'tags' => trim($this->itemTags) !== '' ? trim($this->itemTags) : null,
                    'icon' => trim($this->itemIcon) !== '' ? trim($this->itemIcon) : null,
                    'url' => trim($this->itemUrl) !== '' ? trim($this->itemUrl) : null,
                    'url_label' => trim($this->itemUrlLabel) !== '' ? trim($this->itemUrlLabel) : null,
                    'period' => trim($this->itemPeriod) !== '' ? trim($this->itemPeriod) : null,
                    'parent_id' => $this->itemParentId,
                    'sort_order' => $this->itemSortOrder,
                    'is_visible' => $this->itemVisible,
                ],
                $this->editingItemId !== null
                    ? AboutItem::query()->findOrFail($this->editingItemId)
                    : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('itemParentId', __($exception->getMessage()));

            return;
        }

        $this->resetItemForm();

        unset($this->sections, $this->selectedSection, $this->parentOptions);

        Flux::toast(variant: 'success', text: __('Content row saved.'));
    }

    /**
     * Delete a row and its whole subtree.
     */
    public function deleteItem(int $itemId): void
    {
        $this->authorizeManage();

        $item = AboutItem::query()->findOrFail($itemId);

        app(DeleteAboutItem::class)->handle($item);

        if ($this->editingItemId === $itemId) {
            $this->resetItemForm();
        }

        if ($this->editingWorkflowId === $itemId) {
            $this->resetWorkflowForm();
        }

        unset($this->sections, $this->selectedSection, $this->parentOptions);

        Flux::toast(variant: 'success', text: __('Content row deleted.'));
    }

    /**
     * Hide or reveal a row without deleting it.
     */
    public function toggleItemVisibility(int $itemId): void
    {
        $this->authorizeManage();

        $item = AboutItem::query()->findOrFail($itemId);

        $item->update(['is_visible' => ! $item->is_visible]);

        unset($this->sections, $this->selectedSection);

        Flux::toast(
            variant: 'success',
            text: $item->is_visible ? __('Row is now visible.') : __('Row is now hidden.'),
        );
    }

    /**
     * Switch the workflow editor between task / agent / metric.
     */
    public function setWorkflowKind(string $kind): void
    {
        if (! in_array($kind, $this->workflowKinds(), true)) {
            return;
        }

        $this->resetWorkflowForm();
        $this->wfKind = $kind;
    }

    /**
     * Load a workflow row into the kind-aware form.
     */
    public function editWorkflowItem(int $itemId): void
    {
        $item = AboutItem::query()->with('section')->findOrFail($itemId);

        if ($item->section->type !== AboutSectionType::AiWorkflow) {
            return;
        }

        $this->sectionKey = $item->section->key;
        $this->editingItemId = $item->id;
        $this->editingWorkflowId = $item->id;
        $this->wfKind = $item->type->value;
        $this->wfOrder = $item->sort_order;
        $this->wfVisible = $item->is_visible;

        if ($item->type === AboutItemType::WorkflowTask) {
            $this->wfTitle = $item->title;
            $this->wfIdentifier = (string) ($item->subtitle ?? '');
            $this->wfBody = (string) ($item->body ?? '');
            $this->wfStage = WorkflowTaxonomy::normaliseStage($item->value);
            $this->wfPriority = (string) ($item->url_label ?: 'Feature');
            $this->wfAgent = (string) ($item->period ?? '');
            $this->wfTarget = (string) ($item->url ?? '');
            $this->wfTags = (string) ($item->tags ?? '');
        }

        if ($item->type === AboutItemType::WorkflowAgent) {
            $this->wfTitle = $item->title;
            $this->wfBody = (string) ($item->body ?? '');
            $this->wfStatus = WorkflowTaxonomy::normaliseStatus($item->value);
        }

        if ($item->type === AboutItemType::WorkflowMetric) {
            $this->wfTitle = $item->title;
            $this->wfValue = (string) ($item->value ?? '');
            $this->wfBand = WorkflowTaxonomy::normaliseBand($item->subtitle);
            $this->wfTone = filled($item->url_label)
                ? WorkflowTaxonomy::normaliseTone($item->url_label)
                : WorkflowTaxonomy::legacyTone($item->sort_order);
        }
    }

    /**
     * Clear the workflow form back to "create a task".
     */
    public function resetWorkflowForm(): void
    {
        $this->reset(
            'editingWorkflowId', 'wfTitle', 'wfIdentifier', 'wfBody', 'wfStage',
            'wfPriority', 'wfAgent', 'wfTarget', 'wfTags', 'wfStatus', 'wfBand',
            'wfTone', 'wfValue', 'wfOrder', 'wfVisible',
        );

        $this->wfKind = AboutItemType::WorkflowTask->value;
        $this->editingItemId = null;
        $this->resetValidation();
    }

    /**
     * Create or update a workflow row inside the selected section.
     *
     * One method for all three kinds because they share the same table and the
     * same save path; the kind decides which columns carry meaning and which
     * rules apply, so a task can never be stored without a stage and a metric
     * can never be stored without a value.
     */
    public function saveWorkflowItem(): void
    {
        $this->authorizeManage();

        $section = $this->selectedSection;

        if ($section === null || $section->type !== AboutSectionType::AiWorkflow) {
            Flux::toast(variant: 'danger', text: __('Select the AI Workflow section first.'));

            return;
        }

        $kind = AboutItemType::tryFrom($this->wfKind);

        if ($kind === null || ! in_array($kind->value, $this->workflowKinds(), true)) {
            $this->addError('wfKind', __('Choose a valid workflow row type.'));

            return;
        }

        $this->validate($this->workflowRules($kind));

        if ($kind === AboutItemType::WorkflowMetric && trim($this->wfValue) === '') {
            $this->addError('wfValue', __('A metric needs a value to render.'));

            return;
        }

        $payload = match ($kind) {
            AboutItemType::WorkflowTask => [
                'title' => trim($this->wfTitle),
                'subtitle' => trim($this->wfIdentifier) !== '' ? trim($this->wfIdentifier) : null,
                'body' => trim($this->wfBody) !== '' ? trim($this->wfBody) : null,
                'value' => $this->wfStage,
                'period' => trim($this->wfAgent) !== '' ? trim($this->wfAgent) : null,
                'url' => trim($this->wfTarget) !== '' ? trim($this->wfTarget) : null,
                'url_label' => trim($this->wfPriority) !== '' ? trim($this->wfPriority) : 'Feature',
                'tags' => trim($this->wfTags) !== '' ? trim($this->wfTags) : null,
            ],
            AboutItemType::WorkflowAgent => [
                'title' => trim($this->wfTitle),
                'subtitle' => null,
                'body' => trim($this->wfBody) !== '' ? trim($this->wfBody) : null,
                'value' => $this->wfStatus,
                'period' => null,
                'url' => null,
                'url_label' => null,
                'tags' => null,
            ],
            AboutItemType::WorkflowMetric => [
                'title' => trim($this->wfTitle),
                'subtitle' => $this->wfBand,
                'body' => null,
                'value' => trim($this->wfValue),
                'period' => null,
                'url' => null,
                'url_label' => $this->wfTone,
                'tags' => null,
            ],
            default => [],
        };

        if ($payload === []) {
            return;
        }

        app(SaveAboutItem::class)->handle(
            $section,
            $payload + [
                'type' => $kind,
                'parent_id' => null,
                'sort_order' => $this->wfOrder,
                'is_visible' => $this->wfVisible,
            ],
            $this->editingWorkflowId !== null
                ? AboutItem::query()->findOrFail($this->editingWorkflowId)
                : null,
        );

        $this->resetWorkflowForm();

        unset($this->sections, $this->selectedSection, $this->parentOptions);

        Flux::toast(variant: 'success', text: __('Workflow row saved.'));
    }

    /**
     * Move a workflow row one position within its own kind.
     *
     * Card order on the board is `sort_order`, so without this an operator can
     * add a card but never place it. The whole sibling set is renumbered rather
     * than swapping two values, which also repairs any ties left behind by
     * hand-edited data.
     */
    public function moveWorkflowItem(int $itemId, int $direction): void
    {
        $this->authorizeManage();

        $item = AboutItem::query()->findOrFail($itemId);

        $siblings = AboutItem::query()
            ->where('about_section_id', $item->about_section_id)
            ->where('type', $item->type->value)
            ->ordered()
            ->get()
            ->values();

        $index = $siblings->search(fn (AboutItem $sibling): bool => $sibling->id === $item->id);

        if (! is_int($index)) {
            return;
        }

        $target = $index + ($direction <=> 0);

        if ($target === $index || $target < 0 || $target >= $siblings->count()) {
            return;
        }

        $reordered = $siblings->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $sibling) {
            $sibling->update(['sort_order' => $position]);
        }

        unset($this->sections, $this->selectedSection);
    }

    /**
     * Fill an empty board with the built-in demo.
     *
     * Refuses a board that already has rows: this is a starting point, not a
     * reset button, and silently appending a second demo would be worse than
     * doing nothing.
     */
    public function seedWorkflowDefaults(): void
    {
        $this->authorizeManage();

        $section = $this->selectedSection;

        if ($section === null || $section->type !== AboutSectionType::AiWorkflow) {
            return;
        }

        if ($section->items()->exists()) {
            Flux::toast(
                variant: 'danger',
                text: __('This board already has rows. Delete them first if you want the demo back.'),
            );

            return;
        }

        $save = app(SaveAboutItem::class);

        foreach (app(AboutContent::class)->workflowDefaultRows() as $row) {
            $save->handle($section, $row + ['parent_id' => null, 'is_visible' => true]);
        }

        unset($this->sections, $this->selectedSection);

        Flux::toast(variant: 'success', text: __('Default board seeded.'));
    }

    /**
     * The workflow row kinds, in the order the console offers them.
     *
     * @return array<int, string>
     */
    private function workflowKinds(): array
    {
        return [
            AboutItemType::WorkflowTask->value,
            AboutItemType::WorkflowAgent->value,
            AboutItemType::WorkflowMetric->value,
        ];
    }

    /**
     * Rules for the current kind, plus the fields every kind shares.
     *
     * @return array<string, array<int, mixed>>
     */
    private function workflowRules(AboutItemType $kind): array
    {
        $shared = ['wfOrder' => ['required', 'integer', 'min:0', 'max:999']];

        return match ($kind) {
            AboutItemType::WorkflowTask => [
                'wfTitle' => ['required', 'string', 'max:200'],
                'wfIdentifier' => ['nullable', 'string', 'max:64'],
                'wfBody' => ['nullable', 'string', 'max:2000'],
                'wfStage' => ['required', Rule::in(WorkflowTaxonomy::stageIds())],
                'wfPriority' => ['required', 'string', 'max:60'],
                'wfAgent' => ['nullable', 'string', 'max:120'],
                'wfTarget' => ['nullable', 'string', 'max:500'],
                'wfTags' => ['nullable', 'string', 'max:500'],
            ] + $shared,
            AboutItemType::WorkflowAgent => [
                'wfTitle' => ['required', 'string', 'max:200'],
                'wfBody' => ['nullable', 'string', 'max:2000'],
                'wfStatus' => ['required', Rule::in(array_keys(WorkflowTaxonomy::STATUSES))],
            ] + $shared,
            AboutItemType::WorkflowMetric => [
                'wfTitle' => ['required', 'string', 'max:200'],
                'wfValue' => ['nullable', 'string', 'max:120'],
                'wfBand' => ['required', Rule::in(array_keys(WorkflowTaxonomy::BANDS))],
                'wfTone' => ['required', Rule::in(array_keys(WorkflowTaxonomy::TONES))],
            ] + $shared,
            default => $shared,
        };
    }

    /**
     * Every section in page order, with all of its rows — hidden ones included,
     * because the console must show what the public page will not.
     *
     * @return Collection<int, AboutSection>
     */
    #[Computed]
    public function sections(): Collection
    {
        return AboutSection::query()
            ->ordered()
            ->with(['items' => fn ($query) => $query->ordered()])
            ->get();
    }

    /**
     * The section the right-hand pane is editing, defaulting to the first.
     */
    #[Computed]
    public function selectedSection(): ?AboutSection
    {
        if ($this->sectionKey === '') {
            return $this->sections->first();
        }

        return $this->sections->firstWhere('key', $this->sectionKey);
    }

    /**
     * Rows that may act as a parent in the selected section's tree.
     *
     * @return Collection<int, AboutItem>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $section = $this->selectedSection;

        if ($section === null || ! $section->type->isHierarchical()) {
            return collect();
        }

        return $section->items
            ->whereIn('type', [AboutItemType::Employer->value, AboutItemType::Role->value])
            ->reject(fn (AboutItem $item) => $item->id === $this->editingItemId)
            ->values();
    }

    /**
     * Whether the section form is editing an existing row.
     */
    #[Computed]
    public function isEditingSection(): bool
    {
        return $this->editingSectionId !== null;
    }

    /**
     * Whether the item form is editing an existing row.
     */
    #[Computed]
    public function isEditingItem(): bool
    {
        return $this->editingItemId !== null;
    }

    /**
     * Board cards in stored order.
     *
     * @return Collection<int, AboutItem>
     */
    #[Computed]
    public function workflowTasks(): Collection
    {
        return $this->workflowRows(AboutItemType::WorkflowTask);
    }

    /**
     * Agent nodes in graph-lane order.
     *
     * @return Collection<int, AboutItem>
     */
    #[Computed]
    public function workflowAgents(): Collection
    {
        return $this->workflowRows(AboutItemType::WorkflowAgent);
    }

    /**
     * Metric counters, both bands.
     *
     * @return Collection<int, AboutItem>
     */
    #[Computed]
    public function workflowMetrics(): Collection
    {
        return $this->workflowRows(AboutItemType::WorkflowMetric);
    }

    /**
     * Task count per pipeline stage.
     *
     * The console's copy of the board's counters: an operator editing a card
     * needs to see the column it will land in, not a total that hides it.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function workflowStageCounts(): array
    {
        $counts = [];

        foreach (WorkflowTaxonomy::stageIds() as $stage) {
            $counts[$stage] = 0;
        }

        foreach ($this->workflowTasks as $task) {
            $stage = WorkflowTaxonomy::normaliseStage($task->value);
            $counts[$stage] = ($counts[$stage] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The selected section's rows of one workflow kind.
     *
     * @return Collection<int, AboutItem>
     */
    private function workflowRows(AboutItemType $type): Collection
    {
        $section = $this->selectedSection;

        if ($section === null || $section->type !== AboutSectionType::AiWorkflow) {
            return new Collection;
        }

        return $section->items->where('type', $type)->values();
    }

    /**
     * The public page anchor this section type renders under. The scrollspy
     * ids are fixed design constants on the About page; this maps a managed
     * section back to the band it takes over.
     */
    public function previewAnchor(AboutSectionType $type): string
    {
        return match ($type) {
            AboutSectionType::Hero => 'about-intro',
            AboutSectionType::Reasons => 'about-why',
            AboutSectionType::Principles => 'about-principles',
            AboutSectionType::Expertise, AboutSectionType::Stats, AboutSectionType::Focus => 'about-capabilities',
            AboutSectionType::Experience => 'about-experience',
            AboutSectionType::Work => 'about-work',
            AboutSectionType::AiWorkflow => 'ai-workflow',
            AboutSectionType::Contact => 'about-contact',
        };
    }

    /**
     * Mutating methods re-check the permission; `wire:click` is client input.
     */
    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermission(AccountPermission::ManageAboutContent), 403);
    }
}; ?>

<div data-test="admin-about">
    <x-app.ui.header
        eyebrow="Administration"
        title="About"
        description="Everything the About page says, as data. Sections define the bands and their wording; rows fill each band with content. The public page reads exactly what is stored here."
        :breadcrumbs="[['label' => __('Administration')]]"
    >
        <x-slot:actions>
            <flux:button :href="route('about')" icon="arrow-top-right-on-square" wire:navigate>
                {{ __('View the About page') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.body>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
            {{-- ============================================================= --
             | Left pane: sections — the page skeleton.
             --}}
            <section aria-labelledby="about-sections-heading" class="sui-card h-fit overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                    <h2 id="about-sections-heading" class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                        {{ __('Sections') }}
                    </h2>
                    <span class="text-2xs text-ink-500">
                        {{ $this->sections->count() }} total
                    </span>
                </div>

                {{-- Section form --}}
                <div class="space-y-3 border-b border-sui-hairline bg-sui-accent-softer p-5">
                    <div class="flex items-center justify-between">
                        <span class="sui-control-label">
                            {{ $this->isEditingSection ? __('Edit section') : __('New section') }}
                        </span>
                        @if ($this->isEditingSection)
                            <button
                                type="button"
                                wire:click="resetSectionForm"
                                class="sui-focus text-2xs font-medium text-ink-500 underline-offset-2 hover:underline"
                            >
                                {{ __('Start over') }}
                            </button>
                        @endif
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="about-section-type" class="sui-control-label">{{ __('Type') }}</label>
                            <select id="about-section-type" wire:model="sectionType" class="sui-control mt-2 w-full text-xs">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach (AboutSectionType::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('sectionType')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="about-section-key" class="sui-control-label">{{ __('Anchor key') }}</label>
                            <input
                                id="about-section-key"
                                type="text"
                                wire:model="sectionKeyInput"
                                placeholder="{{ __('auto from label') }}"
                                autocomplete="off"
                                class="sui-control mt-2 w-full font-mono text-xs"
                            />
                            @error('sectionKeyInput')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="about-section-label" class="sui-control-label">{{ __('Sidebar label') }}</label>
                            <input id="about-section-label" type="text" wire:model="sectionLabel" placeholder="{{ __('Why hire me') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                            @error('sectionLabel')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="about-section-heading" class="sui-control-label">{{ __('Heading') }}</label>
                            <input id="about-section-heading" type="text" wire:model="sectionHeading" placeholder="{{ __('The things I keep coming back to') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                            @error('sectionHeading')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="about-section-lead" class="sui-control-label">{{ __('Lead paragraph') }}</label>
                        <textarea id="about-section-lead" wire:model="sectionLead" rows="2" placeholder="{{ __('One sentence under the heading.') }}" class="sui-control mt-2 w-full resize-y text-xs leading-normal"></textarea>
                        @error('sectionLead')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="about-section-body" class="sui-control-label">{{ __('Body copy') }}</label>
                        <textarea id="about-section-body" wire:model="sectionBody" rows="2" placeholder="{{ __('Longer prose for the hero and intro bands.') }}" class="sui-control mt-2 w-full resize-y text-xs leading-normal"></textarea>
                        @error('sectionBody')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="about-section-icon" class="sui-control-label">{{ __('Icon') }}</label>
                            <input id="about-section-icon" type="text" wire:model="sectionIcon" placeholder="{{ __('book-open') }}" autocomplete="off" class="sui-control mt-2 w-full font-mono text-xs" />
                            @error('sectionIcon')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="about-section-ordinal" class="sui-control-label">{{ __('Page order') }}</label>
                            <input id="about-section-ordinal" type="number" min="0" max="999" wire:model="sectionOrdinal" class="sui-control mt-2 w-full text-xs" />
                            @error('sectionOrdinal')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="about-section-sort" class="sui-control-label">{{ __('Tie-break') }}</label>
                            <input id="about-section-sort" type="number" min="0" max="999" wire:model="sectionSortOrder" class="sui-control mt-2 w-full text-xs" />
                            @error('sectionSortOrder')
                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink-700 dark:text-ink-300">
                        <input type="checkbox" wire:model.live="sectionVisible" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                        {{ __('Visible on the page') }}
                    </label>

                    <div class="flex items-center gap-2 pt-1">
                        <flux:button variant="primary" size="sm" wire:click="saveSection" wire:loading.attr="disabled" icon="check">
                            {{ $this->isEditingSection ? __('Save section') : __('Create section') }}
                        </flux:button>

                        @if ($this->isEditingSection)
                            <flux:button variant="ghost" size="sm" wire:click="resetSectionForm">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                {{-- Section list --}}
                @if ($this->sections->isEmpty())
                    <div class="sui-empty">
                        <p class="text-sm text-ink-500">{{ __('No sections yet — the About page is running on its built-in defaults. Create one to take over a band.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-sui-hairline">
                        @foreach ($this->sections as $section)
                            <li
                                wire:key="about-section-{{ $section->id }}"
                                @class([
                                    'group transition duration-150 ease-soft',
                                    'bg-sui-accent-softer' => $this->selectedSection?->id === $section->id,
                                    'bg-sui-raised' => $editingSectionId === $section->id,
                                ])
                            >
                                <button
                                    type="button"
                                    wire:click="selectSection('{{ $section->key }}')"
                                    class="sui-focus flex w-full items-start gap-3 px-5 py-4 text-left"
                                >
                                    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-sui-accent-soft font-mono text-2xs font-semibold text-sui-accent-ink">
                                        {{ $section->ordinal }}
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-ink-900 dark:text-ink-50">
                                            {{ $section->label ?? $section->heading ?? $section->type->label() }}
                                        </span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-ink-500">
                                            <span class="font-mono">#{{ $section->key }}</span>
                                            <span aria-hidden="true">·</span>
                                            <span>{{ $section->type->label() }}</span>
                                            <span aria-hidden="true">·</span>
                                            <span>{{ trans_choice(':count row|:count rows', $section->items->count(), ['count' => $section->items->count()]) }}</span>
                                        </span>
                                    </span>

                                    @unless ($section->is_visible)
                                        <span class="shrink-0 rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">
                                            {{ __('Hidden') }}
                                        </span>
                                    @endunless
                                </button>

                                <div class="flex items-center gap-2 px-5 pb-3" wire:key="about-section-actions-{{ $section->id }}">
                                    <button type="button" wire:click="editSection({{ $section->id }})" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 transition hover:bg-sui-hover dark:text-ink-300">
                                        {{ __('Edit') }}
                                    </button>

                                    <button type="button" wire:click="toggleSectionVisibility({{ $section->id }})" wire:loading.attr="disabled" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 transition hover:bg-sui-hover dark:text-ink-300">
                                        {{ $section->is_visible ? __('Hide') : __('Show') }}
                                    </button>

                                    <div x-data="{ confirming: false }" class="ml-auto">
                                        <button
                                            type="button"
                                            x-show="! confirming"
                                            x-on:click="confirming = true"
                                            class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-sui-danger transition hover:bg-sui-danger/10"
                                        >
                                            {{ __('Delete') }}
                                        </button>

                                        <div x-show="confirming" x-cloak class="flex items-center gap-1.5">
                                            <span class="text-2xs text-ink-500">{{ __('Remove section and its rows?') }}</span>
                                            <button type="button" wire:click="deleteSection({{ $section->id }})" wire:loading.attr="disabled" class="sui-focus rounded-full bg-sui-danger px-2.5 py-1 text-2xs font-semibold text-white">
                                                {{ __('Confirm') }}
                                            </button>
                                            <button type="button" x-on:click="confirming = false" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 dark:text-ink-300">
                                                {{ __('Cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ============================================================= --
             | Right pane: rows inside the selected section.
             --}}
            @php $section = $this->selectedSection; @endphp

            @if ($section === null)
                <section class="sui-card flex items-center justify-center p-10">
                    <p class="max-w-sm text-center text-sm text-ink-500">
                        {{ __('Pick a section on the left to manage its content, or create one to replace a default band.') }}
                    </p>
                </section>
            @else
                <section aria-labelledby="about-items-heading" class="space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="about-items-heading" class="text-base font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ $section->label ?? $section->heading ?? $section->type->label() }}
                            </h2>
                            <p class="mt-0.5 text-xs text-ink-500">
                                {{ $section->type->label() }}
                                @if ($section->type->isHierarchical())
                                    · {{ __('hierarchical — employers contain roles, roles contain highlights') }}
                                @endif
                                @if ($section->type === AboutSectionType::AiWorkflow)
                                    · {{ __('board, graph and telemetry — one row set, three readings') }}
                                @endif
                            </p>
                        </div>

                        <a
                            href="{{ route('about').'#'.$this->previewAnchor($section->type) }}"
                            wire:navigate
                            class="sui-focus inline-flex items-center gap-1.5 rounded-full border border-sui-hairline px-3 py-1.5 text-2xs font-semibold text-ink-600 transition hover:bg-sui-hover dark:text-ink-300"
                        >
                            {{ __('Preview on page') }}
                            <svg aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H18M18 6v4.5M18 6l-7.5 7.5M6 14.25v3.375c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125v-9c0-.621-.504-1.125-1.125-1.125H13.5" /></svg>
                        </a>
                    </div>

                    @if ($section->type === AboutSectionType::AiWorkflow)
                        {{-- ================================================= --
                         | AI Workflow console.
                         |
                         | The board has its own vocabulary — stage, priority,
                         | agent, status, band, tone — and the generic "value /
                         | subtitle / period" form would leave an operator
                         | guessing which column means what. Three readings of
                         | the stored rows sit above the editor, so the result
                         | being published is visible while it is edited.
                         --}}
                        @php
                            $statusDots = [
                                'active' => 'bg-sui-accent',
                                'idle' => 'bg-ink-300 dark:bg-ink-600',
                                'blocked' => 'bg-sui-warning',
                            ];
                            $toneDots = [
                                'cyan' => 'bg-sky-500',
                                'green' => 'bg-emerald-500',
                                'neutral' => 'bg-ink-400',
                                'amber' => 'bg-sui-warning',
                            ];
                            $peak = max(1, max(array_values($this->workflowStageCounts)));
                            $kinds = [
                                AboutItemType::WorkflowTask->value => __('Task card'),
                                AboutItemType::WorkflowAgent->value => __('Agent node'),
                                AboutItemType::WorkflowMetric->value => __('Metric'),
                            ];
                            $isEmptyBoard = $this->workflowTasks->isEmpty()
                                && $this->workflowAgents->isEmpty()
                                && $this->workflowMetrics->isEmpty();
                        @endphp

                        <div class="sui-card overflow-hidden">
                            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-sui-hairline px-5 py-4">
                                <div>
                                    <h3 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                        {{ __('Workflow state') }}
                                    </h3>
                                    <p class="mt-0.5 text-2xs text-ink-500">
                                        {{ __('Read from the stored rows — this is what the About page renders.') }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-3">
                                    <p class="font-mono text-2xs tabular-nums text-ink-500">
                                        {{ $this->workflowTasks->count() }} {{ __('cards') }}
                                        <span aria-hidden="true">·</span>
                                        {{ $this->workflowAgents->count() }} {{ __('agents') }}
                                        <span aria-hidden="true">·</span>
                                        {{ $this->workflowMetrics->count() }} {{ __('metrics') }}
                                    </p>

                                    @if ($isEmptyBoard)
                                        <flux:button size="sm" icon="sparkles" wire:click="seedWorkflowDefaults" wire:loading.attr="disabled">
                                            {{ __('Seed the demo board') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>

                            <div class="grid divide-y divide-sui-hairline lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                                {{-- Pipeline --}}
                                <div class="p-5">
                                    <p class="sui-control-label">{{ __('Pipeline') }}</p>
                                    <ul class="mt-3 space-y-2">
                                        @foreach (WorkflowTaxonomy::stages() as $index => $stage)
                                            @php $count = $this->workflowStageCounts[$stage['id']] ?? 0; @endphp
                                            <li class="flex items-center gap-2.5">
                                                <span class="w-4 shrink-0 font-mono text-2xs tabular-nums text-ink-400">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                                <span class="min-w-0 flex-1 truncate text-xs text-ink-700 dark:text-ink-300">{{ $stage['name'] }}</span>
                                                <span class="h-1 w-9 shrink-0 overflow-hidden rounded-full bg-sui-hover">
                                                    <span class="block h-full rounded-full bg-sui-accent" style="width: {{ (int) round($count / $peak * 100) }}%"></span>
                                                </span>
                                                <span class="w-5 shrink-0 text-right font-mono text-xs font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ $count }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>

                                {{-- Agents --}}
                                <div class="p-5">
                                    <p class="sui-control-label">{{ __('Agents') }}</p>
                                    <ul class="mt-3 space-y-2">
                                        @forelse ($this->workflowAgents as $agent)
                                            @php $agentStatus = WorkflowTaxonomy::normaliseStatus($agent->value); @endphp
                                            <li class="flex items-center gap-2">
                                                <span class="size-1.5 shrink-0 rounded-full {{ $statusDots[$agentStatus] ?? 'bg-ink-400' }}"></span>
                                                <span class="min-w-0 flex-1 truncate text-xs text-ink-700 dark:text-ink-300">{{ $agent->title }}</span>
                                                <span class="shrink-0 text-2xs text-ink-500">{{ WorkflowTaxonomy::statusLabel($agentStatus) }}</span>
                                            </li>
                                        @empty
                                            <li class="text-xs leading-relaxed text-ink-500">{{ __('No agents yet — the graph keeps its demo roster.') }}</li>
                                        @endforelse
                                    </ul>

                                    @if ($this->workflowAgents->count() > 5)
                                        <p class="mt-3 text-2xs text-sui-warning-ink">
                                            {{ __('The graph draws the first five, in this order.') }}
                                        </p>
                                    @endif
                                </div>

                                {{-- Metrics --}}
                                <div class="p-5">
                                    <p class="sui-control-label">{{ __('Metrics') }}</p>
                                    @foreach (WorkflowTaxonomy::bandOptions() as $bandKey => $bandLabel)
                                        <div class="mt-3">
                                            <p class="text-2xs font-medium text-ink-500">{{ $bandLabel }}</p>
                                            <ul class="mt-1.5 space-y-1.5">
                                                @forelse ($this->workflowMetrics->filter(fn (AboutItem $metric): bool => WorkflowTaxonomy::normaliseBand($metric->subtitle) === $bandKey) as $metric)
                                                    <li class="flex items-baseline justify-between gap-2">
                                                        <span class="truncate text-xs text-ink-600 dark:text-ink-400">{{ $metric->title }}</span>
                                                        <span class="shrink-0 font-mono text-xs font-semibold tabular-nums text-ink-900 dark:text-ink-50">{{ $metric->value }}</span>
                                                    </li>
                                                @empty
                                                    <li class="text-2xs text-ink-400">{{ __('Falls back to the demo band') }}</li>
                                                @endforelse
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Editor --}}
                        <div class="sui-card overflow-hidden">
                            <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                                <h3 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                    {{ $this->editingWorkflowId !== null ? __('Edit workflow row') : __('New workflow row') }}
                                </h3>
                                @if ($this->editingWorkflowId !== null)
                                    <button type="button" wire:click="resetWorkflowForm" class="sui-focus text-2xs font-medium text-ink-500 underline-offset-2 hover:underline">
                                        {{ __('Start over') }}
                                    </button>
                                @endif
                            </div>

                            {{-- Kind switch — one table, three shapes. --}}
                            <div class="flex flex-wrap items-center gap-2 border-b border-sui-hairline bg-sui-accent-softer px-5 py-3">
                                @foreach ($kinds as $kindValue => $kindLabel)
                                    <button
                                        type="button"
                                        wire:click="setWorkflowKind('{{ $kindValue }}')"
                                        wire:loading.attr="disabled"
                                        @class([
                                            'sui-focus rounded-full border px-3 py-1.5 text-2xs font-semibold transition',
                                            // Tint + ink rather than a filled accent: `--color-sui-accent`
                                            // lightens in dark mode, where white-on-accent drops under AA.
                                            'border-sui-accent bg-sui-accent-soft text-sui-accent-ink' => $wfKind === $kindValue,
                                            'border-sui-hairline text-ink-600 hover:bg-sui-hover dark:text-ink-300' => $wfKind !== $kindValue,
                                        ])
                                    >
                                        {{ $kindLabel }}
                                    </button>
                                @endforeach

                                @error('wfKind')
                                    <span class="text-2xs text-sui-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="space-y-3 p-5">
                                @if ($wfKind === AboutItemType::WorkflowTask->value)
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="wf-title" class="sui-control-label">{{ __('Intent') }} <span class="text-sui-danger">*</span></label>
                                            <input id="wf-title" type="text" wire:model="wfTitle" placeholder="{{ __('Redesign navbar profile') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                            @error('wfTitle')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-identifier" class="sui-control-label">{{ __('Identifier') }}</label>
                                            <input id="wf-identifier" type="text" wire:model="wfIdentifier" placeholder="#SH-1240" autocomplete="off" class="sui-control mt-2 w-full font-mono text-xs" />
                                            @error('wfIdentifier')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <label for="wf-stage" class="sui-control-label">{{ __('Stage') }}</label>
                                            <select id="wf-stage" wire:model="wfStage" class="sui-control mt-2 w-full text-xs">
                                                @foreach (WorkflowTaxonomy::stageOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('wfStage')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-priority" class="sui-control-label">{{ __('Priority') }}</label>
                                            <select id="wf-priority" wire:model="wfPriority" class="sui-control mt-2 w-full text-xs">
                                                @foreach (WorkflowTaxonomy::PRIORITIES as $priority)
                                                    <option value="{{ $priority }}">{{ $priority }}</option>
                                                @endforeach
                                                @if (! in_array($wfPriority, WorkflowTaxonomy::PRIORITIES, true))
                                                    <option value="{{ $wfPriority }}">{{ $wfPriority }}</option>
                                                @endif
                                            </select>
                                            <p class="mt-1 text-2xs text-ink-500">{{ __('Sets the card accent: amber for bugs and blocks, green for releases.') }}</p>
                                            @error('wfPriority')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-agent" class="sui-control-label">{{ __('Handling agent') }}</label>
                                            <input id="wf-agent" type="text" wire:model="wfAgent" list="wf-agent-options" placeholder="{{ __('Coder agent') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                            <datalist id="wf-agent-options">
                                                @foreach ($this->workflowAgents as $agent)
                                                    <option value="{{ $agent->title }}"></option>
                                                @endforeach
                                            </datalist>
                                            @error('wfAgent')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div>
                                        <label for="wf-body" class="sui-control-label">{{ __('Description') }}</label>
                                        <textarea id="wf-body" wire:model="wfBody" rows="2" placeholder="{{ __('What the card promises, in one sentence.') }}" class="sui-control mt-2 w-full resize-y text-xs leading-normal"></textarea>
                                        @error('wfBody')
                                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="wf-target" class="sui-control-label">{{ __('Target') }}</label>
                                            <input id="wf-target" type="text" wire:model="wfTarget" placeholder="acme/platform#412" autocomplete="off" class="sui-control mt-2 w-full font-mono text-xs" />
                                            @error('wfTarget')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-tags" class="sui-control-label">{{ __('Tags') }}</label>
                                            <input id="wf-tags" type="text" wire:model="wfTags" placeholder="{{ __('Backend, Security') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                            @error('wfTags')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>
                                @endif

                                @if ($wfKind === AboutItemType::WorkflowAgent->value)
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="wf-title" class="sui-control-label">{{ __('Agent name') }} <span class="text-sui-danger">*</span></label>
                                            <input id="wf-title" type="text" wire:model="wfTitle" placeholder="{{ __('Coder Agent') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                            @error('wfTitle')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-status" class="sui-control-label">{{ __('Status') }}</label>
                                            <select id="wf-status" wire:model="wfStatus" class="sui-control mt-2 w-full text-xs">
                                                @foreach (WorkflowTaxonomy::statusOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 text-2xs text-ink-500">
                                                {{ WorkflowTaxonomy::STATUSES[$wfStatus]['description'] ?? '' }}
                                            </p>
                                            @error('wfStatus')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div>
                                        <label for="wf-body" class="sui-control-label">{{ __('Role, one line') }}</label>
                                        <input id="wf-body" type="text" wire:model="wfBody" placeholder="{{ __('Writes & refactors code') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                        @error('wfBody')
                                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif

                                @if ($wfKind === AboutItemType::WorkflowMetric->value)
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="wf-title" class="sui-control-label">{{ __('Label') }} <span class="text-sui-danger">*</span></label>
                                            <input id="wf-title" type="text" wire:model="wfTitle" placeholder="{{ __('Active agents') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                            @error('wfTitle')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-value" class="sui-control-label">{{ __('Value') }} <span class="text-sui-danger">*</span></label>
                                            <input id="wf-value" type="text" wire:model="wfValue" placeholder="08" autocomplete="off" class="sui-control mt-2 w-full font-mono text-xs" />
                                            @error('wfValue')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="wf-band" class="sui-control-label">{{ __('Band') }}</label>
                                            <select id="wf-band" wire:model="wfBand" class="sui-control mt-2 w-full text-xs">
                                                @foreach (WorkflowTaxonomy::bandOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 text-2xs text-ink-500">{{ __('Header sits beside the heading; telemetry sits under the graph.') }}</p>
                                            @error('wfBand')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="wf-tone" class="sui-control-label">{{ __('Tone') }}</label>
                                            <select id="wf-tone" wire:model="wfTone" class="sui-control mt-2 w-full text-xs">
                                                @foreach (WorkflowTaxonomy::toneOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 flex items-center gap-1.5 text-2xs text-ink-500">
                                                <span class="size-1.5 rounded-full {{ $toneDots[$wfTone] ?? 'bg-ink-400' }}"></span>
                                                {{ __('Amber is reserved for a figure that needs a human.') }}
                                            </p>
                                            @error('wfTone')
                                                <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>
                                @endif

                                <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                                    <div class="flex flex-wrap items-center gap-4">
                                        <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink-700 dark:text-ink-300">
                                            <input type="checkbox" wire:model.live="wfVisible" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                                            {{ __('Visible on the page') }}
                                        </label>

                                        <div class="flex items-center gap-2">
                                            <label for="wf-order" class="sui-control-label">{{ __('Order') }}</label>
                                            <input id="wf-order" type="number" min="0" max="999" wire:model="wfOrder" class="sui-control w-20 text-xs" />
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <flux:button variant="primary" size="sm" wire:click="saveWorkflowItem" wire:loading.attr="disabled" icon="check">
                                            {{ $this->editingWorkflowId !== null ? __('Save row') : __('Create row') }}
                                        </flux:button>

                                        @if ($this->editingWorkflowId !== null)
                                            <flux:button variant="ghost" size="sm" wire:click="resetWorkflowForm">
                                                {{ __('Cancel') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Row lists, grouped by kind so the board reads as a
                             board rather than one undifferentiated table. --}}
                        @foreach ([
                            'task' => [__('Task cards'), $this->workflowTasks],
                            'agent' => [__('Agent nodes'), $this->workflowAgents],
                            'metric' => [__('Metrics'), $this->workflowMetrics],
                        ] as $groupKey => [$groupLabel, $groupRows])
                            <div class="sui-card overflow-hidden" wire:key="wf-group-{{ $groupKey }}">
                                <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                                    <h3 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">{{ $groupLabel }}</h3>
                                    <span class="text-2xs text-ink-500">{{ $groupRows->count() }} {{ __('total') }}</span>
                                </div>

                                @if ($groupRows->isEmpty())
                                    <div class="sui-empty">
                                        <p class="text-sm text-ink-500">{{ __('Nothing stored — this part of the board renders its built-in default.') }}</p>
                                    </div>
                                @else
                                    <ul class="divide-y divide-sui-hairline">
                                        @foreach ($groupRows as $row)
                                            <li
                                                wire:key="wf-row-{{ $row->id }}"
                                                @class([
                                                    'group flex items-start gap-3 px-5 py-3 transition duration-150 ease-soft',
                                                    'bg-sui-accent-softer' => $editingWorkflowId === $row->id,
                                                ])
                                            >
                                                <div class="flex shrink-0 flex-col gap-1 pt-0.5">
                                                    <button type="button" wire:click="moveWorkflowItem({{ $row->id }}, -1)" wire:loading.attr="disabled" aria-label="{{ __('Move up') }}" class="sui-focus rounded border border-sui-hairline px-1 text-2xs leading-4 text-ink-500 transition hover:bg-sui-hover">↑</button>
                                                    <button type="button" wire:click="moveWorkflowItem({{ $row->id }}, 1)" wire:loading.attr="disabled" aria-label="{{ __('Move down') }}" class="sui-focus rounded border border-sui-hairline px-1 text-2xs leading-4 text-ink-500 transition hover:bg-sui-hover">↓</button>
                                                </div>

                                                <div class="min-w-0 flex-1">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $row->title }}</span>

                                                        @if ($row->type === AboutItemType::WorkflowTask)
                                                            <span class="rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">
                                                                {{ WorkflowTaxonomy::stageName(WorkflowTaxonomy::normaliseStage($row->value)) }}
                                                            </span>
                                                            <span class="rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">{{ $row->url_label }}</span>
                                                        @endif

                                                        @if ($row->type === AboutItemType::WorkflowAgent)
                                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">
                                                                <span class="size-1.5 rounded-full {{ $statusDots[WorkflowTaxonomy::normaliseStatus($row->value)] ?? 'bg-ink-400' }}"></span>
                                                                {{ WorkflowTaxonomy::statusLabel((string) $row->value) }}
                                                            </span>
                                                        @endif

                                                        @if ($row->type === AboutItemType::WorkflowMetric)
                                                            <span class="rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">
                                                                {{ WorkflowTaxonomy::BANDS[WorkflowTaxonomy::normaliseBand($row->subtitle)] ?? '' }}
                                                            </span>
                                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sui-hover px-2 py-0.5 font-mono text-2xs font-medium text-ink-500">
                                                                <span class="size-1.5 rounded-full {{ $toneDots[WorkflowTaxonomy::normaliseTone($row->url_label)] ?? 'bg-ink-400' }}"></span>
                                                                {{ $row->value }}
                                                            </span>
                                                        @endif

                                                        @unless ($row->is_visible)
                                                            <span class="rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">{{ __('Hidden') }}</span>
                                                        @endunless
                                                    </div>

                                                    @if ($row->type === AboutItemType::WorkflowTask && $row->subtitle)
                                                        <p class="mt-0.5 truncate font-mono text-2xs text-ink-400">
                                                            {{ $row->subtitle }}@if ($row->period) · {{ $row->period }}@endif
                                                        </p>
                                                    @endif

                                                    @if ($row->type === AboutItemType::WorkflowAgent && $row->body)
                                                        <p class="mt-0.5 truncate text-2xs text-ink-400">{{ $row->body }}</p>
                                                    @endif
                                                </div>

                                                <div class="flex shrink-0 items-center gap-2">
                                                    <button type="button" wire:click="editWorkflowItem({{ $row->id }})" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 transition hover:bg-sui-hover dark:text-ink-300">
                                                        {{ __('Edit') }}
                                                    </button>

                                                    <button type="button" wire:click="toggleItemVisibility({{ $row->id }})" wire:loading.attr="disabled" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 transition hover:bg-sui-hover dark:text-ink-300">
                                                        {{ $row->is_visible ? __('Hide') : __('Show') }}
                                                    </button>

                                                    <div x-data="{ confirming: false }">
                                                        <button type="button" x-show="! confirming" x-on:click="confirming = true" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-sui-danger transition hover:bg-sui-danger/10">
                                                            {{ __('Delete') }}
                                                        </button>

                                                        <div x-show="confirming" x-cloak class="flex items-center gap-1.5">
                                                            <button type="button" wire:click="deleteItem({{ $row->id }})" wire:loading.attr="disabled" class="sui-focus rounded-full bg-sui-danger px-2.5 py-1 text-2xs font-semibold text-white">
                                                                {{ __('Confirm') }}
                                                            </button>
                                                            <button type="button" x-on:click="confirming = false" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 dark:text-ink-300">
                                                                {{ __('Cancel') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    @else
                    {{-- Item form --}}
                    <div class="sui-card overflow-hidden">
                        <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                            <h3 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ $this->isEditingItem ? __('Edit row') : __('New row') }}
                            </h3>
                            @if ($this->isEditingItem)
                                <button
                                    type="button"
                                    wire:click="resetItemForm"
                                    class="sui-focus text-2xs font-medium text-ink-500 underline-offset-2 hover:underline"
                                >
                                    {{ __('Start over') }}
                                </button>
                            @endif
                        </div>

                        <div class="space-y-3 p-5">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label for="about-item-type" class="sui-control-label">{{ __('Type') }}</label>
                                    <select id="about-item-type" wire:model="itemType" class="sui-control mt-2 w-full text-xs">
                                        @foreach (AboutItemType::options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('itemType')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                @if ($section->type->isHierarchical())
                                    <div>
                                        <label for="about-item-parent" class="sui-control-label">{{ __('Parent row') }}</label>
                                        <select id="about-item-parent" wire:model="itemParentId" class="sui-control mt-2 w-full text-xs">
                                            <option value="">{{ __('— top level —') }}</option>
                                            @foreach ($this->parentOptions as $parent)
                                                <option value="{{ $parent->id }}">{{ $parent->title }} ({{ $parent->type->label() }})</option>
                                            @endforeach
                                        </select>
                                        @error('itemParentId')
                                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif

                                <div>
                                    <label for="about-item-sort" class="sui-control-label">{{ __('Order') }}</label>
                                    <input id="about-item-sort" type="number" min="0" max="999" wire:model="itemSortOrder" class="sui-control mt-2 w-full text-xs" />
                                    @error('itemSortOrder')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="about-item-title" class="sui-control-label">{{ __('Title') }} <span class="text-sui-danger">*</span></label>
                                    <input id="about-item-title" type="text" wire:model="itemTitle" placeholder="{{ __('Laravel, Livewire, and PHP') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                    @error('itemTitle')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="about-item-subtitle" class="sui-control-label">{{ __('Subtitle') }}</label>
                                    <input id="about-item-subtitle" type="text" wire:model="itemSubtitle" placeholder="{{ __('Full Stack Developer') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                    @error('itemSubtitle')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label for="about-item-value" class="sui-control-label">{{ __('Value / stat') }}</label>
                                    <input id="about-item-value" type="text" wire:model="itemValue" placeholder="{{ __('8+ years') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                    @error('itemValue')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="about-item-period" class="sui-control-label">{{ __('Period') }}</label>
                                    <input id="about-item-period" type="text" wire:model="itemPeriod" placeholder="{{ __('2019 — present') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                    @error('itemPeriod')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="about-item-icon" class="sui-control-label">{{ __('Icon') }}</label>
                                    <input id="about-item-icon" type="text" wire:model="itemIcon" placeholder="{{ __('cube') }}" autocomplete="off" class="sui-control mt-2 w-full font-mono text-xs" />
                                    @error('itemIcon')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div>
                                <label for="about-item-body" class="sui-control-label">{{ __('Body') }}</label>
                                <textarea id="about-item-body" wire:model="itemBody" rows="2" placeholder="{{ __('The outcome-first sentence shown under the title.') }}" class="sui-control mt-2 w-full resize-y text-xs leading-normal"></textarea>
                                @error('itemBody')
                                    <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="about-item-tags" class="sui-control-label">{{ __('Tags') }}</label>
                                <input id="about-item-tags" type="text" wire:model="itemTags" placeholder="{{ __('Laravel, MySQL, Redis') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                <p class="mt-1 text-2xs text-ink-500">{{ __('Comma-separated. Rendered as chips on the public page.') }}</p>
                                @error('itemTags')
                                    <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="about-item-url" class="sui-control-label">{{ __('URL') }}</label>
                                    <input id="about-item-url" type="text" wire:model="itemUrl" placeholder="https://…" autocomplete="off" class="sui-control mt-2 w-full font-mono text-xs" />
                                    @error('itemUrl')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="about-item-url-label" class="sui-control-label">{{ __('URL label') }}</label>
                                    <input id="about-item-url-label" type="text" wire:model="itemUrlLabel" placeholder="{{ __('Visit') }}" autocomplete="off" class="sui-control mt-2 w-full text-xs" />
                                    @error('itemUrlLabel')
                                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                                <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink-700 dark:text-ink-300">
                                    <input type="checkbox" wire:model.live="itemVisible" class="sui-focus size-4 shrink-0 accent-sui-accent" />
                                    {{ __('Visible on the page') }}
                                </label>

                                <div class="flex items-center gap-2">
                                    <flux:button variant="primary" size="sm" wire:click="saveItem" wire:loading.attr="disabled" icon="check">
                                        {{ $this->isEditingItem ? __('Save row') : __('Create row') }}
                                    </flux:button>

                                    @if ($this->isEditingItem)
                                        <flux:button variant="ghost" size="sm" wire:click="resetItemForm">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Item list --}}
                    <div class="sui-card overflow-hidden">
                        <div class="flex items-center justify-between gap-4 border-b border-sui-hairline px-5 py-4">
                            <h3 class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                {{ __('Rows in this section') }}
                            </h3>
                            <span class="text-2xs text-ink-500">
                                {{ $section->items->count() }} total
                            </span>
                        </div>

                        @if ($section->items->isEmpty())
                            <div class="sui-empty">
                                <p class="text-sm text-ink-500">{{ __('Nothing here yet — this band still renders its built-in default. Add rows to replace it.') }}</p>
                            </div>
                        @else
                            <ul class="divide-y divide-sui-hairline">
                                @foreach ($section->items as $item)
                                    <li
                                        wire:key="about-item-{{ $item->id }}"
                                        @class([
                                            'group flex items-start gap-4 px-5 py-4 transition duration-150 ease-soft',
                                            'bg-sui-accent-softer' => $editingItemId === $item->id,
                                            'bg-sui-raised/50 pl-12' => $item->parent_id !== null,
                                        ])
                                    >
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-semibold text-ink-900 dark:text-ink-50">
                                                    {{ $item->parent_id !== null ? '↳ '.$item->title : $item->title }}
                                                </span>
                                                <span class="rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">
                                                    {{ $item->type->label() }}
                                                </span>
                                                @unless ($item->is_visible)
                                                    <span class="rounded-full bg-sui-hover px-2 py-0.5 text-2xs font-medium text-ink-500">{{ __('Hidden') }}</span>
                                                @endunless
                                            </div>

                                            @if ($item->subtitle || $item->value || $item->period)
                                                <p class="mt-1 truncate text-xs text-ink-500">
                                                    {{ collect([$item->subtitle, $item->value, $item->period])->filter()->implode(' · ') }}
                                                </p>
                                            @endif

                                            @if ($item->tags !== null && $item->tags !== '')
                                                <p class="mt-1 truncate font-mono text-2xs text-ink-400">{{ $item->tags }}</p>
                                            @endif
                                        </div>

                                        <div class="flex shrink-0 items-center gap-2">
                                            <button type="button" wire:click="editItem({{ $item->id }})" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 transition hover:bg-sui-hover dark:text-ink-300">
                                                {{ __('Edit') }}
                                            </button>

                                            <button type="button" wire:click="toggleItemVisibility({{ $item->id }})" wire:loading.attr="disabled" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 transition hover:bg-sui-hover dark:text-ink-300">
                                                {{ $item->is_visible ? __('Hide') : __('Show') }}
                                            </button>

                                            <div x-data="{ confirming: false }">
                                                <button
                                                    type="button"
                                                    x-show="! confirming"
                                                    x-on:click="confirming = true"
                                                    class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-sui-danger transition hover:bg-sui-danger/10"
                                                >
                                                    {{ __('Delete') }}
                                                </button>

                                                <div x-show="confirming" x-cloak class="flex items-center gap-1.5">
                                                    <button type="button" wire:click="deleteItem({{ $item->id }})" wire:loading.attr="disabled" class="sui-focus rounded-full bg-sui-danger px-2.5 py-1 text-2xs font-semibold text-white">
                                                        {{ __('Confirm') }}
                                                    </button>
                                                    <button type="button" x-on:click="confirming = false" class="sui-focus rounded-full border border-sui-hairline px-2.5 py-1 text-2xs font-medium text-ink-600 dark:text-ink-300">
                                                        {{ __('Cancel') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @endif
                </section>
            @endif
        </div>
    </x-app.ui.body>
</div>
