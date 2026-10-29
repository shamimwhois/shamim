@php
    /**
     * One commit.
     *
     * The row is `[rail | content]` and the rail spans the row's full height,
     * so the graph runs uninterrupted: the segment above the node takes the
     * previous author's lane, the segment below takes this author's, and the
     * divider starts to the right of the rail rather than crossing it.
     *
     * A commit draws its **whole record**: the type it was filed under, the tags
     * it was given, the code it carries, the poll it asks, the reactions it
     * earned. All of that is already on the row — the post stores it — so a
     * commit that has a poll and renders as three lines of prose is the log
     * throwing away its own content.
     *
     * Long bodies fold. The fold is a `max-height`, not a truncation of the
     * markup: slicing rendered HTML at a character count breaks tags and mangles
     * code blocks, and the reader who wants the rest should get all of it.
     *
     * Deleting is a two-step, inline action: the trash icon reveals a soft red
     * strip, and confirming prints `rm {hash} → /dev/null`. The page keeps the
     * row for thirty seconds behind an Undo.
     */
@endphp

<article
    class="dn-commit {{ $commit['isOwn'] ? 'is-deletable' : '' }} {{ $commit['fresh'] ? 'is-fresh' : '' }}"
    data-commit="{{ $commit['id'] }}"
    data-permalink="{{ $commit['url'] }}"
    data-test="commit"
    style="--dn-lane: {{ $commit['lane'] }}"
    tabindex="-1"
    x-data="{ confirming: false }"
    x-bind:class="{ 'is-dimmed': $el.closest('.dn-feed')?.dataset.dimmed === 'true' }"
>
    <div class="dn-rail" aria-hidden="true">
        @if ($commit['previousLane'] !== null)
            <span class="dn-rail-line is-up" style="background: {{ $commit['previousLane'] }}"></span>
        @endif

        <span class="dn-rail-line is-down" style="background: {{ $commit['lane'] }}"></span>

        <a class="dn-rail-node" href="{{ $commit['url'] }}" tabindex="-1">
            <span class="dn-rail-avatar">
                <span class="dn-avatar">{{ $commit['initials'] }}</span>
            </span>
        </a>
    </div>

    <div class="dn-commit-content">
        <div class="dn-commit-head">
            {{-- The identity cluster is a hover target: resting on it previews
                 the author (the numbers the page can back up), following it
                 opens the profile. Focus opens the card too, because a keyboard
                 reader has the same question a pointer does. --}}
            <span
                class="dn-id"
                x-data="{ open: false }"
                x-on:mouseenter="open = true"
                x-on:mouseleave="open = false"
                x-on:focusin="open = true"
                x-on:focusout="open = false"
                x-on:keydown.escape.stop="open = false"
                data-test="author-hover"
            >
                <a
                    href="{{ route('profile.show', ['handle' => $commit['handle']]) }}"
                    class="dn-name"
                    wire:navigate
                >{{ $commit['name'] }}</a>

                <a
                    href="{{ route('profile.show', ['handle' => $commit['handle']]) }}"
                    class="dn-handle"
                    wire:navigate
                >{{ '@'.$commit['handle'] }}</a>

                <div
                    class="dn-hovercard"
                    x-show="open"
                    x-cloak
                    x-transition:enter="dn-hovercard-in"
                    x-transition:enter-start="is-entering"
                    x-transition:enter-end="is-entered"
                    role="group"
                    aria-label="{{ __('Author profile preview') }}"
                    data-test="author-card"
                >
                    <div class="dn-hovercard__id">
                        <span class="dn-avatar" style="--dn-lane: {{ $commit['lane'] }}">{{ $commit['initials'] }}</span>
                        <div class="dn-hovercard__who">
                            <p class="dn-hovercard__name">{{ $commit['name'] }}</p>
                            <p class="dn-hovercard__meta">{{ '@'.$commit['handle'] }}@if ($commit['role']) · {{ $commit['role'] }}@endif</p>
                        </div>
                    </div>

                    @if ($commit['authorCard'] !== null)
                        <dl class="dn-hovercard__stats">
                            <div class="dn-hovercard__stat">
                                <dt>{{ __('commits') }}</dt>
                                <dd>{{ $commit['authorCard']['commits'] }}</dd>
                            </div>
                            <div class="dn-hovercard__stat">
                                <dt>{{ __('stars') }}</dt>
                                <dd>{{ $commit['authorCard']['stars'] }}</dd>
                            </div>
                            <div class="dn-hovercard__stat">
                                <dt>{{ __('replies') }}</dt>
                                <dd>{{ $commit['authorCard']['replies'] }}</dd>
                            </div>
                        </dl>
                    @endif

                    <p class="dn-hovercard__go">
                        <a
                            href="{{ route('profile.show', ['handle' => $commit['handle']]) }}"
                            wire:navigate
                            x-on:click="open = false"
                        >{{ __('Open profile') }}
                            <x-feed.icon name="chevron-right" :size="12" />
                        </a>
                    </p>
                </div>
            </span>

            <span class="dn-dot" aria-hidden="true">·</span>

            {{-- The full date lives in `title`, because the row is a log line and
                 a log line prints relative time; the exact moment is one hover
                 away rather than a second column. --}}
            <a
                href="{{ $commit['url'] }}"
                class="dn-time"
                title="{{ $commit['fullDate'] }}"
                data-test="commit-permalink"
            >
                <time datetime="{{ $commit['iso'] }}">{{ $commit['time'] }}</time>
            </a>

            <a
                href="{{ route('feed.index', ['type' => $commit['type']]) }}"
                wire:navigate
                class="dn-type"
                title="{{ __('Show every :type', ['type' => $commit['type']]) }}"
                data-test="commit-type"
            >{{ $commit['type'] }}</a>

            @if ($commit['pinned'])
                <span class="dn-badge is-pin" title="{{ __('Pinned commit') }}" data-test="commit-pinned">
                    <x-feed.icon name="pin" :size="11" />
                    {{ __('pinned') }}
                </span>
            @endif

            @if ($commit['featured'])
                <span class="dn-badge is-featured" title="{{ __('Featured commit') }}" data-test="commit-featured">
                    <x-feed.icon name="spark" :size="11" />
                    {{ __('featured') }}
                </span>
            @endif

            @if ($commit['answered'])
                <span class="dn-badge is-answer" data-test="commit-answered">
                    <x-feed.icon name="check" :size="11" />
                    {{ __('answered') }}
                </span>
            @endif

            <span class="dn-hash-wrap">
                <a
                    href="{{ $commit['url'] }}"
                    class="dn-hash"
                    tabindex="-1"
                    title="{{ __('Commit :hash', ['hash' => $commit['hash']]) }}"
                >{{ $commit['hash'] }}</a>

                @if ($commit['isOwn'])
                    <button
                        type="button"
                        class="dn-delete"
                        aria-label="{{ __('Revert commit') }}"
                        title="{{ __('Revert commit') }}"
                        x-on:click="confirming = true"
                        data-test="delete-commit"
                    >
                        <x-feed.icon name="trash" :size="16" />
                    </button>
                @endif
            </span>
        </div>

        {{-- A commit's subject, the way git prints it above the body. Corpus
             posts carry a headline; the log keeps it, bold, on its own line. --}}
        @if (filled($commit['title']))
            <p class="dn-commit-subject">{{ $commit['title'] }}</p>
        @endif

        <div class="dn-body-wrap" x-data="{ open: false }">
            <div class="dn-body {{ $commit['long'] ? 'is-folded' : '' }}" x-bind:class="{ 'is-open': open }">{!! $commit['body'] !!}</div>

            @if ($commit['long'])
                <button
                    type="button"
                    class="dn-fold"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open"
                    data-test="commit-fold"
                >
                    <span x-show="! open">{{ __('Show more') }}</span>
                    <span x-show="open" x-cloak>{{ __('Show less') }}</span>
                </button>
            @endif
        </div>

        @if ($commit['attachments'] !== [])
            <div class="dn-media" data-test="commit-media">
                @foreach ($commit['attachments'] as $attachment)
                    <a
                        class="dn-media-item"
                        href="{{ $attachment['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <img
                            src="{{ $attachment['url'] }}"
                            alt="{{ $attachment['alt'] }}"
                            @if ($attachment['width'] && $attachment['height'])
                                width="{{ $attachment['width'] }}"
                                height="{{ $attachment['height'] }}"
                            @endif
                            loading="lazy"
                            decoding="async"
                        >
                    </a>
                @endforeach
            </div>
        @endif

        @if ($commit['code'] !== null)
            <figure class="dn-code" data-test="commit-code">
                @if ($commit['code']['file'] !== null)
                    <figcaption class="dn-code-file">
                        <x-feed.icon name="snippet" :size="12" />
                        {{ $commit['code']['file'] }}
                    </figcaption>
                @endif
                <pre class="dn-code-body"><code>{{ $commit['code']['body'] }}</code></pre>
            </figure>
        @endif

        @if ($commit['poll'] !== null)
            {{-- A poll is the one commit a reader answers rather than reacts to.
                 The tally is public, so it is shown before the vote too; the
                 radio and the button are how the reader joins it. --}}
            <div
                class="dn-poll"
                data-test="commit-poll"
                x-data="{ choice: @js($commit['poll']['voted'] ?? '') }"
            >
                @foreach ($commit['poll']['options'] as $option)
                    <label class="dn-poll-row {{ $option['mine'] ? 'is-mine' : '' }}">
                        <input
                            type="radio"
                            name="poll-{{ $commit['id'] }}"
                            value="{{ $option['label'] }}"
                            x-model="choice"
                            @disabled($commit['poll']['voted'] !== null)
                            class="dn-poll-radio"
                            data-test="poll-option"
                        >
                        <span class="dn-poll-bar" style="width: {{ $option['share'] }}%" aria-hidden="true"></span>
                        <span class="dn-poll-label">{{ $option['label'] }}</span>
                        <span class="dn-poll-share">{{ $option['share'] }}%</span>
                    </label>
                @endforeach

                <div class="dn-poll-foot">
                    <span>
                        {{ trans_choice(':count vote|:count votes', $commit['poll']['votes'], ['count' => $commit['poll']['votes']]) }}
                    </span>

                    @if ($commit['poll']['closes'] !== null)
                        <span class="dn-dot" aria-hidden="true">·</span>
                        <span>{{ $commit['poll']['closes'] }}</span>
                    @endif

                    @if ($commit['poll']['voted'] !== null)
                        <span class="dn-poll-mine" data-test="poll-voted">
                            {{ __('You voted: :option', ['option' => $commit['poll']['voted']]) }}
                        </span>
                    @elseif ($commit['canVote'])
                        <button
                            type="button"
                            class="dn-btn dn-btn-sm dn-poll-vote"
                            x-bind:disabled="choice === ''"
                            x-on:click="$wire.votePoll({{ $commit['id'] }}, choice)"
                            data-test="poll-vote"
                        >
                            {{ __('Vote') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif

        @if ($commit['answer'] !== null)
            {{-- The accepted answer to a question. It is the whole point of a
                 solved question, so it is printed on the row rather than hidden
                 behind a click. --}}
            <div class="dn-answer" data-test="commit-answer">
                <div class="dn-answer-head">
                    <span class="dn-avatar" style="width: 20px; height: 20px; font-size: 9px;">{{ $commit['answer']['initials'] }}</span>
                    <span class="dn-name">{{ $commit['answer']['author'] }}</span>
                    <span class="dn-badge is-answer">
                        <x-feed.icon name="check" :size="10" />
                        {{ __('accepted') }}
                    </span>
                </div>
                <div class="dn-answer-body">{!! $commit['answer']['body'] !!}</div>
            </div>
        @endif

        @if ($commit['activity'] !== null)
            {{-- The "this is still moving" line. It points at the discussion
                 surface, which is where the thread and the diff live. --}}
            <a
                class="dn-activity"
                href="{{ $commit['url'] }}"
                wire:navigate
                data-test="commit-activity"
            >
                <x-feed.icon name="activity" :size="12" />
                <span class="dn-activity-label">{{ $commit['activity']['label'] }}</span>
                @if ($commit['activity']['detail'] !== '')
                    <span class="dn-dot" aria-hidden="true">·</span>
                    <span class="dn-activity-detail">{{ $commit['activity']['detail'] }}</span>
                @endif
                <x-feed.icon name="chevron-right" :size="12" class="dn-activity-go" />
            </a>
        @endif

        @if ($commit['tags'] !== [])
            <div class="dn-tags" data-test="commit-tags">
                @foreach ($commit['tags'] as $tag)
                    <a class="dn-tag" href="{{ route('feed.index', ['tag' => $tag]) }}" wire:navigate>#{{ $tag }}</a>
                @endforeach
            </div>
        @endif

        <div class="dn-actions">
            <div class="dn-actions-left">
                <button
                    type="button"
                    class="dn-action {{ $commit['starred'] ? 'is-on' : '' }}"
                    wire:click="toggleStar({{ $commit['id'] }})"
                    aria-pressed="{{ $commit['starred'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Star this commit') }}"
                    data-test="star"
                >
                    <x-feed.icon name="star" />
                    @if ($commit['stars'] > 0)
                        <span class="dn-action-count">{{ $commit['stars'] }}</span>
                    @endif
                </button>

                <button
                    type="button"
                    class="dn-action"
                    wire:click="openReply({{ $commit['id'] }})"
                    data-test="reply"
                >
                    <x-feed.icon name="reply" />
                    {{ __('Reply') }}
                </button>

                @if ($commit['replies'] > 0)
                    <button
                        type="button"
                        class="dn-action dn-replies-link"
                        wire:click="toggleThread({{ $commit['id'] }})"
                        aria-expanded="{{ $commit['threadOpen'] ? 'true' : 'false' }}"
                        data-test="replies-toggle"
                    >
                        {{ trans_choice(':count reply|:count replies', $commit['replies'], ['count' => $commit['replies']]) }}
                    </button>
                @endif

                @if ($commit['reactions'] !== null)
                    <span class="dn-tallies" data-test="commit-reactions">
                        @foreach (['like', 'love', 'insight'] as $reaction)
                            @if ($commit['reactions'][$reaction] > 0)
                                <span class="dn-tally" title="{{ trans_choice(':count :reaction|:count :reaction', $commit['reactions'][$reaction], ['count' => $commit['reactions'][$reaction], 'reaction' => $reaction]) }}">
                                    {{ $commit['reactions'][$reaction] }} {{ $reaction }}
                                </span>
                            @endif
                        @endforeach
                    </span>
                @endif

                @if ($commit['readMinutes'] !== null && $commit['readMinutes'] > 0)
                    <span class="dn-readtime">{{ __(':count min read', ['count' => $commit['readMinutes']]) }}</span>
                @endif
            </div>

            <button
                type="button"
                class="dn-action"
                aria-label="{{ __('Copy link to commit') }}"
                x-data="{ copied: false }"
                x-on:click="
                    navigator.clipboard?.writeText(@js($commit['url'])).then(() => {
                        copied = true;
                        setTimeout(() => (copied = false), 2000);
                    });
                "
            >
                <x-feed.icon name="link" x-show="! copied" />
                <x-feed.icon name="check" x-show="copied" x-cloak style="color: var(--dn-primary)" />
            </button>

            {{-- Reporting asks why. "Reported" on its own is not a triage, and a
                 report with no reason is one nobody can act on. A reader reports
                 a commit once; after that the row says so instead of offering
                 the button again. --}}
            @if ($commit['canReport'] || $commit['reported'])
                <div class="dn-report" @if ($commit['canReport']) x-data="{ open: false }" @endif>
                    @if ($commit['reported'])
                        <span
                            class="dn-action is-reported"
                            title="{{ __('You reported this commit') }}"
                            data-test="commit-reported"
                        >
                            <x-feed.icon name="flag" />
                        </span>
                    @else
                        <button
                            type="button"
                            class="dn-action"
                            aria-label="{{ __('Report commit') }}"
                            x-on:click="open = ! open"
                            x-bind:aria-expanded="open"
                            data-test="report-commit"
                        >
                            <x-feed.icon name="flag" />
                        </button>

                        <div
                            class="dn-pop dn-report-pop"
                            x-show="open"
                            x-cloak
                            x-on:click.outside="open = false"
                            data-test="report-reasons"
                        >
                            <p class="dn-pop-head">{{ __('Why are you reporting this commit?') }}</p>
                            @foreach ($commit['reportReasons'] as $key => $label)
                                <button
                                    type="button"
                                    class="dn-pop-item"
                                    wire:click="reportCommit({{ $commit['id'] }}, '{{ $key }}')"
                                    data-test="report-reason-{{ $key }}"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>

        @if ($commit['threadOpen'])
            <div class="dn-thread" data-test="thread">
                @foreach ($commit['repliesList'] as $reply)
                    <div class="dn-reply">
                        <span
                            class="dn-avatar"
                            style="--dn-lane: {{ $reply['lane'] }}; width: 28px; height: 28px; font-size: 10px; flex: none;"
                        >{{ $reply['initials'] }}</span>
                        <div class="dn-reply-body">
                            <div class="dn-reply-head">
                                <span class="dn-name">{{ $reply['name'] }}</span>
                                <span class="dn-handle">{{ '@'.$reply['handle'] }}</span>
                                <span class="dn-dot" aria-hidden="true">·</span>
                                <span class="dn-time">{{ $reply['time'] }}</span>
                            </div>
                            <p class="dn-reply-text">{{ $reply['body'] }}</p>
                        </div>
                    </div>
                @endforeach

                @if ($commit['replyOpen'])
                    <form
                        class="dn-reply-composer"
                        wire:submit="postReply({{ $commit['id'] }})"
                        data-test="reply-composer"
                    >
                        <label for="reply-{{ $commit['id'] }}" class="sr-only">{{ __('Reply') }}</label>
                        <textarea
                            id="reply-{{ $commit['id'] }}"
                            wire:model="replyDraft"
                            maxlength="{{ $maxReply }}"
                            rows="1"
                            placeholder="{{ __('Reply to @:handle', ['handle' => $commit['handle']]) }}"
                            x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                            x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                            x-on:keydown.escape.prevent="$wire.cancelReply()"
                        ></textarea>

                        @error('replyDraft')
                            <p style="font-size: 13px; color: var(--dn-destructive);">{{ $message }}</p>
                        @enderror

                        <div class="dn-reply-actions">
                            <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" wire:click="cancelReply">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit" class="dn-btn dn-btn-sm" wire:loading.attr="disabled">
                                {{ __('Reply') }}
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        @endif

        {{-- Deleting is inline: a soft red strip, then the row collapses. --}}
        <template x-if="confirming">
            <div class="dn-confirm" role="group" aria-label="{{ __('Confirm delete') }}">
                <span style="margin-right: auto;">{{ __("Delete this commit? You can undo for 30 seconds.") }}</span>
                <button type="button" class="dn-btn dn-btn-ghost dn-btn-sm" x-on:click="confirming = false">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    class="dn-btn dn-btn-destructive"
                    wire:click="deleteCommit({{ $commit['id'] }})"
                    data-test="confirm-delete"
                >
                    {{ __('Delete') }}
                </button>
            </div>
        </template>
    </div>
</article>
