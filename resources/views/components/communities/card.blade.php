@props([
    'community',
    'member' => false,
])

@php
    use App\Enums\CommunityKind;

    /** @var \App\Models\Community $community */
@endphp

<article
    class="group relative flex flex-col rounded-lg border border-ink-200 bg-white p-5 transition-colors hover:border-ink-300 dark:border-ink-800 dark:bg-ink-900 dark:hover:border-ink-700"
    data-test="community-card"
>
    <div class="flex items-start justify-between gap-3">
        {{-- There is no community detail route (the module is a directory plus
             the feed), so the name opens the community's topic in the feed —
             the same view the feed rail's community rows select. --}}
        <a href="{{ route('feed.index', ['category' => $community->topic]) }}" wire:navigate class="focus-ring flex min-w-0 items-center gap-3 rounded-sm">
            <span class="grid size-11 shrink-0 place-items-center rounded-md bg-ink-900 font-mono text-xs font-semibold text-signal-400 dark:bg-ink-800 dark:text-signal-400">
                {{ $community->glyph }}
            </span>
            <div class="min-w-0">
                <h2 class="truncate text-sm font-semibold text-ink-900 dark:text-ink-50">{{ $community->name }}</h2>
                <p class="font-mono text-[11px] text-ink-500 dark:text-ink-400">
                    {{ $community->memberships_count }} {{ __('members') }} · {{ $community->topic }}
                </p>
            </div>
        </a>

        @if ($community->kind === CommunityKind::InviteOnly)
            <span class="shrink-0 rounded-full border border-ink-200 px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider text-ink-500 dark:border-ink-700 dark:text-ink-400">
                {{ __('Invite only') }}
            </span>
        @endif
    </div>

    <p class="mt-3 line-clamp-2 flex-1 text-[13px] leading-relaxed text-ink-600 dark:text-ink-300">
        {{ $community->description }}
    </p>

    <div class="mt-4">
        @auth
            @if ($member)
                <flux:button variant="outline" size="sm" wire:click="toggleMembership('{{ $community->slug }}')" wire:loading.attr="disabled">
                    {{ __('Leave') }}
                </flux:button>
            @elseif ($community->kind === CommunityKind::Open)
                <flux:button variant="primary" size="sm" wire:click="toggleMembership('{{ $community->slug }}')" wire:loading.attr="disabled">
                    {{ __('Join') }}
                </flux:button>
            @else
                <flux:button variant="ghost" size="sm" disabled>
                    {{ __('By invitation') }}
                </flux:button>
            @endif
        @else
            <flux:button variant="primary" size="sm" :href="route('login')" x-on:click="$flux.modal('login').show(); $event.preventDefault()">
                {{ __('Sign in to join') }}
            </flux:button>
        @endauth
        <p class="mt-2 font-mono text-[10px] uppercase tracking-wider text-ink-500 dark:text-ink-400">{{ $community->kind->hint() }}</p>
    </div>
</article>
