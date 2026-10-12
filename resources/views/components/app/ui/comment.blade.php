@props([
    'comment',
    'isReply' => false,
])

@php
    /**
     * A single comment in the thread.
     *
     * Status badges are only shown to moderators — a reader has no business
     * seeing "Awaiting moderation" on someone else's comment.
     */
    $canModerate = auth()->check() && auth()->id() === $comment->post->user_id;
@endphp

<article {{ $attributes->merge(['class' => '']) }}>
    <div class="flex gap-4">
        {{-- Avatar: initials, monospace. No gravatar, no external request. --}}
        <div @class([
            'grid shrink-0 place-items-center rounded-md border font-mono font-semibold',
            'size-9 text-xs' => ! $isReply,
            'size-8 text-2xs' => $isReply,
            'border-ink-200 bg-ink-50 text-ink-500 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-400' => true,
        ])>
            {{ Str::of($comment->user->name)->substr(0, 1)->upper() }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <p class="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                    {{ $comment->user->name }}
                </p>

                <time datetime="{{ $comment->created_at->toIso8601String() }}" class="font-mono text-2xs text-ink-400 dark:text-ink-500">
                    {{ $comment->created_at->diffForHumans() }}
                </time>

                @if ($canModerate && ! $comment->isApproved())
                    <span class="rounded-xs border border-amber-300/60 bg-amber-50 px-1.5 py-0.5 font-mono text-2xs text-amber-700 dark:border-amber-500/30 dark:bg-amber-950/40 dark:text-amber-400">
                        {{ $comment->status->label() }}
                    </span>
                @endif

                @if ($isReply)
                    <span class="font-mono text-2xs text-ink-300 dark:text-ink-600">reply</span>
                @endif
            </div>

            <div class="mt-2 text-sm leading-relaxed text-ink-600 dark:text-ink-300">
                {!! nl2br(e($comment->body)) !!}
            </div>
        </div>
    </div>
</article>
