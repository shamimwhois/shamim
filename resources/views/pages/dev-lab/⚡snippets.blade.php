<?php

use App\Actions\DevLab\DeleteLabSnippet;
use App\Actions\DevLab\SaveLabSnippet;
use App\Enums\AccountPermission;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
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
 * The signed-in account's snippets.
 *
 * A snippet is the thing out of the sandbox that is worth a name, a description and a
 * URL, so this screen is a library rather than a to-do list. The search runs over the
 * saved code for that reason: somebody hunting the debounce helper they wrote
 * remembers the function name, not the sentence they typed underneath it.
 *
 * Everything unpublished stays here and behind auth. The public half of the module is
 * the gallery, and the only route between the two is the publish pair, which this
 * screen changes through {@see SaveLabSnippet} like every other writer in the module.
 */
new #[Layout('layouts::app.dashboard')] #[Title('Snippets')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'lang', except: '')]
    public string $language = '';

    #[Url(as: 'visibility', except: 'all')]
    public string $visibility = 'all';

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
            'title' => 'Snippets',
            'description' => 'The code you have saved out of the sandbox.',
            'noindex' => true,
        ]);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'language', 'visibility'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Drop every filter in one click, which is what an empty result list asks for.
     */
    public function clearFilters(): void
    {
        $this->reset('search', 'language', 'visibility');
        $this->resetPage();
    }

    /**
     * Show the snippet to strangers.
     *
     * Refusing to publish a snippet with no code in it arrives as a toast rather than
     * a silently disabled button, because the reason is a rule about the row and the
     * reader is the only one who can fix it.
     */
    public function publish(int $snippetId, SaveLabSnippet $save): void
    {
        $snippet = $this->findOwnedSnippet($snippetId);

        try {
            $save->handle(auth()->user(), [
                'visibility' => ContentVisibility::Public,
            ], $snippet);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->snippets);

        Flux::toast(variant: 'success', text: __(':title is public.', ['title' => $snippet->title]));
    }

    /**
     * Take a public snippet back behind the sign-in.
     *
     * Also through {@see SaveLabSnippet}. That class is the module's only writer of
     * the visibility pair, and a list screen updating the column itself would be a
     * second writer with a weaker rule, which is how a conjunction stops being one.
     */
    public function unpublish(int $snippetId, SaveLabSnippet $save): void
    {
        $snippet = $this->findOwnedSnippet($snippetId);

        $save->handle(auth()->user(), [
            'visibility' => ContentVisibility::Private,
        ], $snippet);

        unset($this->snippets);

        Flux::toast(variant: 'success', text: __(':title is private again.', ['title' => $snippet->title]));
    }

    /**
     * Delete a snippet for good. Its run history survives, because an execution row
     * carries its own label rather than pointing back here.
     */
    public function destroy(int $snippetId, DeleteLabSnippet $delete): void
    {
        $snippet = $this->findOwnedSnippet($snippetId);

        $delete->handle($snippet);

        unset($this->snippets);

        Flux::toast(variant: 'success', text: __('Snippet deleted.'));
    }

    /**
     * @return LengthAwarePaginator<int, LabSnippet>
     */
    #[Computed]
    public function snippets(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->recentlyTouched()
            ->paginate((int) config('dev_lab.per_page.workspace'))
            ->withQueryString();
    }

    /**
     * @return array<string, string>
     */
    public function visibilities(): array
    {
        return [
            'all' => __('Everything'),
            'public' => __('Public'),
            'private' => __('Private'),
        ];
    }

    /**
     * @return Builder<LabSnippet>
     */
    protected function filteredQuery(): Builder
    {
        $language = LabLanguage::tryFrom($this->language);

        return LabSnippet::query()
            ->authoredBy(auth()->user())
            ->when($this->search !== '', fn (Builder $query) => $query->search($this->search))
            ->when($language !== null, fn (Builder $query) => $query->inLanguage($language))
            ->when(
                $this->visibility === 'public',
                fn (Builder $query) => $query->withVisibility(ContentVisibility::Public)
            )
            ->when(
                $this->visibility === 'private',
                fn (Builder $query) => $query->withVisibility(ContentVisibility::Private)
            );
    }

    /**
     * Resolve a snippet by id, scoped to the signed-in account.
     *
     * Scoping the lookup rather than leaning on the policy alone means a foreign id
     * 404s instead of 403ing, which does not confirm that the row exists.
     */
    protected function findOwnedSnippet(int $snippetId): LabSnippet
    {
        return LabSnippet::query()
            ->authoredBy(auth()->user())
            ->findOrFail($snippetId);
    }
}; ?>

<div>
    <x-app.ui.header
        eyebrow="Developer Lab"
        title="Snippets"
        description="The pieces of code you pulled out of the sandbox and gave a name."
        :breadcrumbs="[
            ['label' => __('Developer Lab'), 'href' => route('dev-lab.dashboard')],
            ['label' => __('Snippets')],
        ]"
    >
        <x-slot:actions>
            <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="ghost" size="sm" icon="code-bracket">
                {{ __('Sandbox') }}
            </flux:button>
            <flux:button :href="route('dev-lab.snippets.create')" wire:navigate variant="primary" size="sm" icon="plus">
                {{ __('New snippet') }}
            </flux:button>
        </x-slot:actions>
    </x-app.ui.header>

    <x-app.ui.section :padded="false">
        <div class="border-b border-sui-hairline px-5 py-4">
            <div class="flex flex-wrap items-center gap-3">
                <label for="snippets-search" class="sr-only">{{ __('Search snippets') }}</label>
                <div class="relative min-w-0 flex-1 sm:max-w-sm">
                    <span aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400">
                        <flux:icon name="magnifying-glass" class="size-4" />
                    </span>
                    <input
                        id="snippets-search"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('Search titles, notes and the code itself') }}"
                        class="sui-control w-full pl-9"
                    />
                </div>

                <label for="snippets-language" class="sr-only">{{ __('Filter by language') }}</label>
                <select id="snippets-language" wire:model.live="language" class="sui-control w-auto">
                    {{-- The "everything" choice is an empty string rather than a fake
                         enum case, so the filter never has to invent a language. --}}
                    <option value="">{{ __('All languages') }}</option>
                    @foreach (LabLanguage::options() as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>

                <p class="font-mono text-2xs uppercase tracking-widest text-ink-400" aria-live="polite">
                    {{ trans_choice(':count snippet|:count snippets', $this->snippets->total(), ['count' => $this->snippets->total()]) }}
                </p>
            </div>

            <div class="mt-4 flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter by visibility') }}">
                @foreach ($this->visibilities() as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:key="visibility-tab-{{ $key }}"
                        aria-selected="{{ $visibility === $key ? 'true' : 'false' }}"
                        wire:click="$set('visibility', '{{ $key }}')"
                        @class([
                            'sui-focus rounded-full border px-3 py-1 text-xs font-medium transition duration-150 ease-soft',
                            'border-sui-accent/30 bg-sui-accent-soft text-sui-accent-ink' => $visibility === $key,
                            'border-sui-hairline text-ink-500 hover:bg-sui-hover hover:text-ink-700' => $visibility !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($this->snippets->isEmpty())
            <div class="sui-empty">
                @if ($search !== '' || $language !== '' || $visibility !== 'all')
                    <p class="text-sm text-ink-500">
                        {{ __('Nothing of yours matches those filters. The search runs over the saved code, so an odd phrase is worth trying in pieces.') }}
                    </p>
                    <flux:button wire:click="clearFilters" size="sm" variant="ghost" class="mt-4">
                        {{ __('Clear the filters') }}
                    </flux:button>
                @else
                    <p class="text-sm text-ink-500">
                        {{ __('No snippets yet. Write something in the sandbox, then save the pane you like as a snippet.') }}
                    </p>
                    <flux:button :href="route('dev-lab.sandbox')" wire:navigate variant="primary" size="sm" class="mt-4">
                        {{ __('Open the sandbox') }}
                    </flux:button>
                @endif
            </div>
        @else
            <ul class="divide-y divide-sui-hairline">
                @foreach ($this->snippets as $snippet)
                    <li wire:key="snippet-{{ $snippet->id }}" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a
                                        href="{{ route('dev-lab.snippets.show', ['snippet' => $snippet->slug]) }}"
                                        wire:navigate
                                        class="text-sm font-semibold tracking-tight text-ink-900 transition-colors hover:text-sui-accent"
                                    >
                                        {{ $snippet->title }}
                                    </a>

                                    <flux:badge :color="$snippet->language->badgeColor()" size="sm">
                                        {{ $snippet->language->label() }}
                                    </flux:badge>

                                    <flux:badge :color="$snippet->visibility->badgeColor()" size="sm">
                                        {{ $snippet->visibility->label() }}
                                    </flux:badge>
                                </div>

                                <p class="mt-1 line-clamp-2 font-mono text-2xs leading-relaxed text-ink-500">
                                    {{ $snippet->excerpt() }}
                                </p>

                                <p class="mt-2 font-mono text-2xs uppercase tracking-widest text-ink-400">
                                    {{ trans_choice(':count line|:count lines', $snippet->lineCount(), ['count' => $snippet->lineCount()]) }}
                                    @if ($snippet->isPublished())
                                        &middot; {{ trans_choice(':count view|:count views', $snippet->view_count, ['count' => $snippet->view_count]) }}
                                    @endif
                                    &middot; {{ __('updated :ago', ['ago' => $snippet->updated_at->diffForHumans()]) }}
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                @if ($snippet->isPublished())
                                    <flux:button wire:click="unpublish('{{ $snippet->id }}')" size="xs" variant="ghost" icon="eye-slash">
                                        {{ __('Unpublish') }}
                                    </flux:button>
                                @else
                                    <flux:button wire:click="publish('{{ $snippet->id }}')" size="xs" variant="ghost" icon="globe-alt">
                                        {{ __('Publish') }}
                                    </flux:button>
                                @endif

                                <flux:button
                                    :href="route('dev-lab.snippets.edit', ['snippet' => $snippet->slug])"
                                    wire:navigate
                                    size="xs"
                                    variant="ghost"
                                    icon="pencil-square"
                                >
                                    {{ __('Edit') }}
                                </flux:button>

                                <div x-data="{ confirming: false }" class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        x-show="! confirming"
                                        x-on:click="confirming = true"
                                        class="sui-focus rounded-full px-2 py-1 font-mono text-2xs uppercase tracking-widest text-ink-400 transition-colors hover:text-sui-danger"
                                    >
                                        {{ __('Delete') }}
                                    </button>

                                    <template x-if="confirming">
                                        <div class="flex items-center gap-1.5">
                                            <button
                                                type="button"
                                                wire:click="destroy('{{ $snippet->id }}')"
                                                class="sui-focus rounded-full border border-sui-danger/30 px-2 py-1 font-mono text-2xs uppercase tracking-widest text-sui-danger transition-colors hover:bg-sui-danger/10"
                                            >
                                                {{ __('Confirm') }}
                                            </button>
                                            <button
                                                type="button"
                                                x-on:click="confirming = false"
                                                class="sui-focus rounded-full px-2 py-1 font-mono text-2xs uppercase tracking-widest text-ink-400 transition-colors hover:bg-sui-hover"
                                            >
                                                {{ __('Keep') }}
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <x-app.ui.pagination :paginator="$this->snippets" :label="__('Snippets pagination')" class="px-5 pb-5" />
        @endif
    </x-app.ui.section>
</div>
