<?php

use App\Actions\DevLab\DeleteLabSnippet;
use App\Actions\DevLab\SaveLabSnippet;
use App\Enums\AccountPermission;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\Topic;
use App\Rules\SnippetSlug;
use App\Services\PublicTaxonomy;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Create and edit one snippet.
 *
 * One screen for both, the way the idea form works: the fields are identical, only the
 * starting values and the verbs differ, and two components would be two places to keep
 * the publish rule in sync.
 *
 * The write is a single call to {@see SaveLabSnippet}. Nothing here decides a slug, a
 * timestamp or the visibility pair; those are the action's, so a request built by hand
 * meets the same rules as the button. What this screen adds beyond the action is
 * *shape*: a title long enough to read, a code body under the byte ceiling, and a slug
 * that is legal in a URL and does not shadow one of the lab's own screens.
 *
 * The language picker offers PHP even though PHP has no Run button, because a snippet
 * is stored work rather than a runnable thing, and hiding the language would only make
 * people file their PHP as JavaScript.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Snippet')] class extends Component
{
    /**
     * The row being edited, null while creating.
     *
     * Not named `$snippet`: Livewire copies any route parameter that matches a public
     * property onto that property, and the bound model in `/dev-lab/snippets/{snippet:slug}/edit`
     * arrives as a slug string. Naming it after its own URL segment would type a string
     * into a `?LabSnippet`.
     */
    public ?LabSnippet $editingSnippet = null;

    #[Validate('required|string|max:' . LabSnippet::TITLE_MAX)]
    public string $title = '';

    public string $language = LabLanguage::Html->value;

    public string $code = '';

    public string $description = '';

    public string $slug = '';

    public string $visibility = ContentVisibility::Private->value;

    /**
     * The public subjects this snippet is filed under.
     *
     * Strings because a checkbox group hands values back as strings; the save action
     * casts them. The gallery's language chips stay a separate axis: a language says
     * what the code is written in, a subject says what it is about.
     *
     * @var array<int, string>
     */
    public array $topicIds = [];

    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::UseDeveloperLab),
            403,
            __('You may not open the Developer Lab.')
        );
    }

    public function mount(?LabSnippet $snippet = null): void
    {
        if ($snippet?->exists) {
            // A 404 rather than a 403: a slug that is somebody else's must not confirm
            // that the row exists.
            abort_unless($snippet->isOwnedBy(auth()->user()), 404);

            $this->editingSnippet = $snippet;
            $this->title = $snippet->title;
            $this->language = $snippet->language->value;
            $this->code = (string) $snippet->code;
            $this->description = (string) $snippet->description;
            $this->slug = $snippet->slug;
            $this->topicIds = $snippet->topics->pluck('id')->map(fn (int $id): string => (string) $id)->all();
            $this->visibility = $snippet->visibility->value;
        }

        app(Seo::class)->set([
            'title' => $this->editingSnippet === null ? 'New snippet' : $this->editingSnippet->title,
            'description' => 'Save a piece of code and decide whether anybody else may read it.',
            'noindex' => true,
        ]);
    }

    /**
     * Validate the shape of the form, leaving every decision to the action.
     *
     * The byte ceiling is checked here as well as inside {@see SaveLabSnippet} on
     * purpose: as a field rule it lands next to the textarea the reader was typing in,
     * and as an exception it lands in a toast they may not connect to the pane.
     */
    public function save(SaveLabSnippet $save): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:' . LabSnippet::TITLE_MAX],
            'language' => ['required', 'in:' . implode(',', array_column(LabLanguage::cases(), 'value'))],
            'code' => ['required', 'string', 'max:' . (int) config('dev_lab.max_code_bytes')],
            'description' => ['nullable', 'string', 'max:' . LabSnippet::DESCRIPTION_MAX],
            'slug' => ['nullable', 'string', 'max:120', new SnippetSlug($this->editingSnippet?->id)],
            'visibility' => ['required', 'in:' . implode(',', array_column(ContentVisibility::cases(), 'value'))],
            'topicIds' => ['array', 'max:' . Topic::MAX_PER_ROW],
            'topicIds.*' => ['integer'],
        ]);

        $language = LabLanguage::from($this->language);

        try {
            $snippet = $save->handle(auth()->user(), [
                'title' => $this->title,
                'language' => $language,
                'code' => $this->code,
                'description' => $this->description ?: null,
                'slug' => $this->slug ?: null,
                'topics' => $this->topicIds,
                'visibility' => $this->visibility,
            ], $this->editingSnippet);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $isNew = $this->editingSnippet === null;

        if ($isNew) {
            $this->editingSnippet = $snippet;
            $this->slug = $snippet->slug;
        }

        unset($this->currentLanguage, $this->availableTopics);

        Flux::toast(variant: 'success', text: $isNew ? __('Snippet created.') : __('Saved.'));

        if ($isNew) {
            $this->redirectRoute('dev-lab.snippets.edit', ['snippet' => $snippet->slug], navigate: true);
        }
    }

    /**
     * Delete the snippet this screen is editing.
     *
     * Runs recorded against it survive: an execution row carries its own label, so
     * history does not point at a row that has been thrown away.
     */
    public function delete(DeleteLabSnippet $delete): void
    {
        if ($this->editingSnippet === null) {
            return;
        }

        $this->authorize('delete', $this->editingSnippet);

        $delete->handle($this->editingSnippet);

        Flux::toast(variant: 'success', text: __('Snippet deleted.'));

        $this->redirectRoute('dev-lab.snippets', navigate: true);
    }

    /**
     * The language the picker is on, for the hints that depend on it.
     */
    #[Computed]
    public function currentLanguage(): LabLanguage
    {
        return LabLanguage::tryFrom($this->language) ?? LabLanguage::Html;
    }

    /**
     * The subjects an author may file under.
     *
     * From the same gate the gallery builds its chip row from, so the form only ever
     * offers a subject that has something published behind it — plus one a retired
     * subject this row already carries, which has to stay visible so it can be
     * un-checked.
     *
     * @return Collection<int, Topic>
     */
    #[Computed]
    public function availableTopics(): Collection
    {
        return app(PublicTaxonomy::class)->pickerTopics();
    }

    /**
     * Whether the public switch may be offered.
     *
     * Tied to the same rule the action enforces, so the form never presents a choice it
     * is going to refuse: an empty snippet has no business having a public page.
     */
    #[Computed]
    public function mayPublish(): bool
    {
        return trim($this->code) !== '';
    }
}; ?>

<div>
    <x-app.ui.header
        :eyebrow="$this->editingSnippet === null ? __('New snippet') : __('Edit')"
        :title="$this->editingSnippet === null ? __('Save a piece of code') : $this->editingSnippet->title"
        description="A snippet is a thing worth naming. The sandbox is a thing worth running."
        :breadcrumbs="[
            ['label' => __('Developer Lab'), 'href' => route('dev-lab.dashboard')],
            ['label' => __('Snippets'), 'href' => route('dev-lab.snippets')],
            ['label' => $this->editingSnippet === null ? __('New snippet') : __('Edit')],
        ]"
    >
        <x-slot:actions>
            @if ($this->editingSnippet !== null && $this->editingSnippet->isPublished())
                <flux:button :href="route('dev-lab.snippets.show', ['snippet' => $this->editingSnippet->slug])" wire:navigate variant="ghost" size="sm" icon="arrow-top-right-on-square">
                    {{ __('View live') }}
                </flux:button>
            @endif
        </x-slot:actions>
    </x-app.ui.header>

    <div class="grid gap-6 px-5 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] lg:px-10">
        <form wire:submit="save" class="space-y-6">
            {{-- ============================================================= --
             | The code
             --}}
            <section aria-labelledby="snippet-writing-heading" class="sui-card space-y-5 p-5">
                <h2 id="snippet-writing-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                    {{ __('The snippet') }}
                </h2>

                <div class="grid gap-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,12rem)]">
                    <div>
                        <label for="snippet-title" class="sui-control-label">{{ __('Title') }}</label>
                        <input
                            id="snippet-title"
                            type="text"
                            wire:model="title"
                            placeholder="{{ __('What does this do?') }}"
                            autocomplete="off"
                            class="sui-control mt-2 w-full"
                        />
                        @error('title')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="snippet-language" class="sui-control-label">{{ __('Language') }}</label>
                        <select id="snippet-language" wire:model.live="language" class="sui-control mt-2 w-full">
                            @foreach (LabLanguage::options() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @error('language')
                            <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="snippet-description" class="sui-control-label">
                        {{ __('Note') }}
                        <span class="font-normal text-ink-400">{{ __('(optional; used as the teaser)') }}</span>
                    </label>
                    <textarea
                        id="snippet-description"
                        wire:model="description"
                        rows="2"
                        placeholder="{{ __('When you would reach for this, and what it saves you.') }}"
                        class="sui-control mt-2 w-full resize-y text-sm leading-normal"
                    ></textarea>
                    @error('description')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Public subjects. A second axis beside the language rather than a
                     replacement for it: the chip row on the gallery filters by what the
                     code is written in, these say what it is about. --}}
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
                            {{ __('Only published snippets appear under a subject, and at most :max fit on one snippet.', ['max' => Topic::MAX_PER_ROW]) }}
                        </p>
                    @endif
                    @error('topicIds')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                <x-app.dev-lab.code-pane
                    :label="__('Code')"
                    model="code"
                    :max="(int) config('dev_lab.max_code_bytes')"
                    :hint="$this->currentLanguage->runExplanation()"
                    :placeholder="$this->currentLanguage->isBrowserRunnable() ? __('Paste the part worth keeping') : __('Paste the PHP. It will be stored and shown, never run.')"
                    :rows="18"
                    id="snippet-code"
                />
                @error('code')
                    <p class="-mt-3 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                @enderror
            </section>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">
                    {{ $this->editingSnippet === null ? __('Create snippet') : __('Save changes') }}
                </flux:button>

                <flux:button :href="route('dev-lab.snippets')" wire:navigate variant="ghost">
                    {{ __('Back to snippets') }}
                </flux:button>

                @if ($this->editingSnippet !== null)
                    <span class="ml-auto text-2xs text-ink-400">
                        {{ __('Created :date · updated :updated', [
                            'date' => $this->editingSnippet->created_at?->format('j M Y'),
                            'updated' => $this->editingSnippet->updated_at?->diffForHumans(),
                        ]) }}
                    </span>
                @endif
            </div>
        </form>

        {{-- ============================================================= --
         | Where it lives
         --}}
        <aside aria-labelledby="snippet-filing-heading" class="space-y-6">
            <section class="sui-card space-y-5 p-5">
                <h2 id="snippet-filing-heading" class="text-sm font-semibold tracking-tight text-sui-ink">
                    {{ __('Who may read it') }}
                </h2>

                <div>
                    <label for="snippet-visibility" class="sui-control-label">{{ __('Visibility') }}</label>
                    <select id="snippet-visibility" wire:model="visibility" class="sui-control mt-2 w-full">
                        @foreach (ContentVisibility::options() as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-2xs leading-normal text-ink-400">
                        {{ $this->mayPublish()
                            ? __('Public snippets appear in the gallery and get their own page.')
                            : __('A public snippet needs code in it first. Nothing else is different.') }}
                    </p>
                    @error('visibility')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                </div>

                @if ($this->editingSnippet !== null)
                    <p class="text-2xs leading-normal text-ink-400">
                        {{ $this->editingSnippet->isPublished()
                            ? __('Published :date, viewed :count times.', [
                                'date' => $this->editingSnippet->published_at?->translatedFormat('j M Y'),
                                'count' => $this->editingSnippet->view_count,
                            ])
                            : __('Only you can read this one right now.') }}
                    </p>
                @endif
            </section>

            <section class="sui-card space-y-4 p-5">
                <h2 class="text-sm font-semibold tracking-tight text-sui-ink">{{ __('Address') }}</h2>

                <div>
                    <label for="snippet-slug" class="sui-control-label">{{ __('Slug') }}</label>
                    <input
                        id="snippet-slug"
                        type="text"
                        wire:model="slug"
                        placeholder="{{ __('left-blank-uses-the-title') }}"
                        autocomplete="off"
                        class="sui-control mt-2 w-full font-mono text-xs"
                    />
                    @error('slug')
                        <p class="mt-1.5 text-xs leading-normal text-sui-danger">{{ $message }}</p>
                    @enderror
                    <p class="mt-1.5 text-2xs leading-normal text-ink-400">
                        {{ __('Lowercase letters, numbers and single hyphens. Leave it blank and the title becomes the address.') }}
                    </p>
                </div>

                @if ($this->editingSnippet !== null)
                    <p class="font-mono text-2xs text-ink-500">
                        /dev-lab/snippets/{{ $this->editingSnippet->slug }}
                    </p>
                @endif
            </section>

            <section class="sui-card space-y-4 p-5">
                <h2 class="text-sm font-semibold tracking-tight text-sui-ink">{{ __('Running it') }}</h2>

                <p class="text-xs leading-normal text-ink-500">
                    {{ $this->currentLanguage->isBrowserRunnable()
                        ? __('The gallery page for this snippet offers a preview in the same sandboxed frame your own experiments run in. Somebody else’s code, in a frame that cannot reach this site.')
                        : __('PHP has no Run button and no preview. It is stored, listed and copyable, and the server-side runner that would change that is not part of the lab.') }}
                </p>

                <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="ghost" size="sm" icon="play">
                    {{ __('Open the sandbox') }}
                </flux:button>
            </section>

            @if ($this->editingSnippet !== null)
                <div x-data="{ confirming: false }" class="sui-card space-y-3 p-5">
                    <button
                        type="button"
                        x-show="! confirming"
                        x-on:click="confirming = true"
                        class="sui-focus font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:text-sui-danger"
                    >
                        {{ __('Delete this snippet') }}
                    </button>

                    <div x-show="confirming" x-cloak class="space-y-3">
                        <p class="text-xs leading-normal text-sui-danger">
                            {{ __('A deleted snippet has no public page any more, and the link stops working. This cannot be undone from here.') }}
                        </p>
                        <div class="flex items-center gap-2">
                            <flux:button wire:click="delete" variant="danger" size="sm">
                                {{ __('Yes, delete') }}
                            </flux:button>
                            <button
                                type="button"
                                x-on:click="confirming = false"
                                class="sui-focus rounded-full px-3 py-1 font-mono text-2xs uppercase tracking-widest text-ink-500 transition-colors hover:bg-sui-hover"
                            >
                                {{ __('Keep it') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </aside>
    </div>
</div>
