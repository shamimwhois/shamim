@props([
    'idea',
    'href' => null,
    'showStatus' => true,
    'showCategory' => null,
])

@php
    /**
     * Idea card.
     *
     * One card for every list: the public index, the workspace bands, and the
     * admin console. It is the same object in each place, so it should read the
     * same way, and only the target differs — the caller passes `href` when the
     * card should lead somewhere other than the published idea itself.
     *
     * A card answers the three questions a reader actually weighs: what is the
     * thought, how far has it been taken, and is it worth opening. The stage and
     * the priority are the second question, which is why they sit under the
     * excerpt rather than above the title — they qualify the idea, they are not
     * what the idea is.
     *
     * Like `x-app.ui.empty-state`, this component is shared between the public
     * shell and the dashboard, so it carries explicit `dark:` variants on the ink
     * tokens; the `sui-*` surface tokens already resolve per theme.
     *
     * The folder is not a fact about the published idea — it is one account's private
     * filing system, the half of the taxonomy that {@see \App\Models\IdeaCategory}
     * exists to keep private. So it renders for the author reading their own card and
     * for nobody else, whatever page the card happens to sit on. A caller who wants
     * the question answered outright passes `showCategory`.
     */
    $href ??= route('ideas.show', ['idea' => $idea->slug]);
    $showCategory ??= auth()->id() !== null && auth()->id() === (int) $idea->user_id;
    $priority = $idea->priority;
    $published = $idea->isPublished();
@endphp

<article {{ $attributes->merge(['class' => 'group relative flex h-full flex-col overflow-hidden rounded-xl border border-ink-200 bg-white transition duration-200 ease-soft hover:border-sui-accent/40 hover:shadow-soft-2 dark:border-ink-800 dark:bg-ink-950']) }}>
    @if ($idea->is_pinned)
        <span class="absolute right-3 top-3 text-sui-accent" title="{{ __('Pinned') }}">
            <flux:icon name="bookmark" class="size-4" />
        </span>
    @endif

    <a href="{{ $href }}" wire:navigate class="focus-ring flex flex-1 flex-col p-5">
        <h3 class="max-w-[85%] text-base font-semibold leading-snug tracking-tight text-app-ink">
            {{ $idea->title }}
        </h3>

        <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
            {{ $idea->excerpt() }}
        </p>

        <div class="mt-auto flex flex-wrap items-center gap-2 pt-5">
            @if ($showStatus)
                <x-app.ideas.status-badge :status="$idea->status" />
            @endif

            @if ($priority !== null)
                <flux:badge :color="$priority->badgeColor()" size="sm">
                    {{ $priority->label() }}
                </flux:badge>
            @endif

            @if ($showCategory && $idea->category !== null)
                <span class="inline-flex items-center gap-1.5 text-2xs text-ink-500 dark:text-ink-400">
                    <flux:icon name="folder" class="size-3.5" />
                    {{ $idea->category->name }}
                </span>
            @endif

            @unless ($published)
                <span class="inline-flex items-center gap-1.5 text-2xs text-ink-400 dark:text-ink-500">
                    <flux:icon name="lock-closed" class="size-3.5" />
                    {{ __('Not published') }}
                </span>
            @endunless
        </div>
    </a>

    @if ($slot->isNotEmpty())
        <div class="flex items-center gap-1 border-t border-ink-200 px-4 py-2 dark:border-ink-800">
            {{ $slot }}
        </div>
    @endif
</article>
