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
    wire:key="commit-{{ $commit['id'] }}"
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
                @if ($commit['profileUrl'] !== null)
                    <a
                        href="{{ $commit['profileUrl'] }}"
                        class="dn-name"
                        wire:navigate
                    >{{ $commit['name'] }}</a>

                    <a
                        href="{{ $commit['profileUrl'] }}"
                        class="dn-handle"
                        wire:navigate
                    >{{ '@'.$commit['handle'] }}</a>
                @else
                    {{-- No account owns this handle, so there is nothing to open.
                         Rendered as text rather than a link to a 404. --}}
                    <span class="dn-name">{{ $commit['name'] }}</span>
                    <span class="dn-handle">{{ '@'.$commit['handle'] }}</span>
                @endif

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
                                <dt>{{ __('posts') }}</dt>
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

                    @if ($commit['profileUrl'] !== null)
                        <p class="dn-hovercard__go">
                            <a
                                href="{{ $commit['profileUrl'] }}"
                                wire:navigate
                                x-on:click="open = false"
                            >{{ __('Open profile') }}
                                <x-feed.icon name="chevron-right" :size="12" />
                            </a>
                        </p>
                    @endif
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
                <span class="dn-badge is-pin" title="{{ __('Pinned post') }}" data-test="commit-pinned">
                    <x-feed.icon name="pin" :size="11" />
                    {{ __('pinned') }}
                </span>
            @endif

            @if ($commit['featured'])
                <span class="dn-badge is-featured" title="{{ __('Featured post') }}" data-test="commit-featured">
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
                    title="{{ __('Post :hash', ['hash' => $commit['hash']]) }}"
                >{{ $commit['hash'] }}</a>

                @if ($commit['isOwn'])
                    <span class="dn-row-verbs">
                        <button
                            type="button"
                            class="dn-delete"
                            aria-label="{{ __('Edit post') }}"
                            title="{{ __('Edit post') }}"
                            wire:click="openEdit({{ $commit['id'] }})"
                            data-test="edit-commit"
                        >
                            <x-feed.icon name="pencil" :size="16" />
                        </button>

                        <button
                            type="button"
                            class="dn-delete"
                            aria-label="{{ __('Delete post') }}"
                            title="{{ __('Delete post') }}"
                            x-on:click="confirming = true"
                            data-test="delete-commit"
                        >
                            <x-feed.icon name="trash" :size="16" />
                        </button>
                    </span>
                @endif
            </span>
        </div>

        {{-- A commit's subject, the way git prints it above the body. Corpus
             posts carry a headline; the log keeps it, bold, on its own line. --}}
        @if (filled($commit['title']))
            <p class="dn-commit-subject">{{ $commit['title'] }}</p>
        @endif

        {{-- The body, when there is one. A one-line post is entirely subject:
             the headline was derived from the opening line and that line taken
             out of the body, so nothing is left. Rendering the wrapper anyway
             would print an empty paragraph-sized gap under every short post. --}}
        @if (filled($commit['plain']) || $commit['editing'])
            <div class="dn-body-wrap" x-data="{ open: false }">
                <div class="dn-body {{ $commit['long'] ? 'is-folded' : '' }}" x-bind:class="{ 'is-open': open }" @if ($commit['editing']) hidden @endif>{!! $commit['body'] !!}</div>

                @if ($commit['long'] && ! $commit['editing'])
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
        @endif

        {{-- The inline editor. It replaces the body rather than sitting beside
             it, because two copies of the same paragraph on one row is a row
             nobody can read — and the form has to open holding the current
             text, so the row carries it (`editDraft`) instead of fetching it. --}}
        @if ($commit['editing'])
            <form
                class="dn-edit"
                wire:submit="saveEdit"
                data-test="edit-form"
            >
                <label for="edit-{{ $commit['id'] }}" class="sr-only">{{ __('Post body') }}</label>

                <textarea
                    id="edit-{{ $commit['id'] }}"
                    wire:model="editDraft"
                    rows="3"
                    maxlength="{{ $maxEdit }}"
                    class="dn-edit-input"
                    aria-label="{{ __('Post body') }}"
                    x-on:keydown.escape.prevent="$wire.cancelEdit()"
                    x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                    x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                    data-test="edit-input"
                ></textarea>

                @error('editDraft')
                    <p class="dn-composer-error" data-test="edit-error">{{ $message }}</p>
                @enderror

                {{-- The images on this row, and the way to change them.
                     An edit is text-first, but a post whose picture is wrong is
                     a post that is wrong, so the editor carries the same media
                     controls the composer does. Removing writes immediately
                     (the bytes are on the disk and the row knows them); adding
                     borrows the composer's one file field rather than opening a
                     second, which is why the button dispatches an event instead
                     of holding an input. --}}
                <ul class="dn-edit-media" data-test="edit-media">
                    @foreach ($this->editingMedia as $attachment)
                        <li class="dn-edit-media-item">
                            <img
                                src="{{ $attachment->url() }}"
                                alt="{{ $attachment->alt() }}"
                                loading="lazy"
                                decoding="async"
                            >
                            <button
                                type="button"
                                class="dn-composer-media-remove dn-edit-media-remove"
                                wire:click="removeEditMedia({{ $attachment->id }})"
                                wire:loading.attr="disabled"
                                wire:target="removeEditMedia"
                                aria-label="{{ __('Remove this image') }}"
                                data-test="edit-remove-media"
                            >
                                <x-feed.icon name="close" :size="12" />
                            </button>
                        </li>
                    @endforeach

                    <li class="dn-edit-media-item is-add">
                        <button
                            type="button"
                            class="dn-edit-media-add"
                            x-on:click="$dispatch('composer-attach-requested')"
                            aria-label="{{ __('Add an image to this post') }}"
                            title="{{ __('Add an image') }}"
                            data-test="edit-add-media"
                        >
                            <x-feed.icon name="image" :size="14" />
                            <span>{{ __('Add image') }}</span>
                        </button>
                    </li>
                </ul>

                {{-- The upload refusal, in the same slot the composer uses. A
                     per-file rule fails as `uploads.0`, so the bare key would
                     render nothing and the file would vanish unexplained. --}}
                @if ($message = $errors->first('uploads.*') ?: $errors->first('uploads'))
                    <p class="dn-composer-error" data-test="edit-media-error">{{ $message }}</p>
                @endif

                <div class="dn-edit-actions">
                    <span class="dn-edit-hint">{{ __('Editing :hash', ['hash' => $commit['hash']]) }}</span>
                    <button
                        type="button"
                        class="dn-btn dn-btn-ghost dn-btn-sm"
                        wire:click="cancelEdit"
                        data-test="edit-cancel"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="dn-btn dn-btn-sm"
                        wire:loading.attr="disabled"
                        wire:target="saveEdit"
                        data-test="edit-save"
                    >
                        <span wire:loading.remove wire:target="saveEdit">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveEdit">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        @endif

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
                    aria-label="{{ __('Star this post') }}"
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

            {{-- Share. One control, two routes: the OS sheet where the platform
                 has one, the clipboard everywhere else. Both record the share —
                 a share is a row in `feed_shares`, so the count printed here is
                 a row count and cannot drift from what actually happened.

                 The count is a ledger read, not a column on the post: sharing
                 twice is the same row, so the number means "how many accounts
                 passed this on", not "how many times a button was pressed". --}}
            <div class="dn-share" x-data="{ copied: false }">
                <button
                    type="button"
                    class="dn-action {{ $commit['shared'] ? 'is-on' : '' }}"
                    aria-label="{{ __('Share this post') }}"
                    title="{{ __('Share this post') }}"
                    data-test="share"
                    x-on:click="
                        const url = @js($commit['url']);
                        const title = @js($commit['title'] ?: $commit['plain']);

                        if (navigator.share) {
                            navigator.share({ title, url }).then(() => {
                                $wire.shareCommit({{ $commit['id'] }}, 'sheet');
                            }).catch(() => {});
                        } else {
                            navigator.clipboard?.writeText(url).then(() => {
                                copied = true;
                                setTimeout(() => (copied = false), 2000);
                            });
                            $wire.shareCommit({{ $commit['id'] }}, 'link');
                        }
                    "
                >
                    <x-feed.icon name="share" x-show="! copied" />
                    <x-feed.icon name="check" x-show="copied" x-cloak style="color: var(--dn-primary)" />

                    @if ($commit['shares'] > 0)
                        <span class="dn-action-count">{{ $commit['shares'] }}</span>
                    @endif
                </button>
            </div>

            {{-- Reporting asks why. "Reported" on its own is not a triage, and a
                 report with no reason is one nobody can act on. A reader reports
                 a commit once; after that the row says so instead of offering
                 the button again. --}}
            @if ($commit['canReport'] || $commit['reported'])
                <div class="dn-report" @if ($commit['canReport']) x-data="{ open: false }" @endif>
                    @if ($commit['reported'])
                        <span
                            class="dn-action is-reported"
                            title="{{ __('You reported this post') }}"
                            data-test="commit-reported"
                        >
                            <x-feed.icon name="flag" />
                        </span>
                    @else
                        <button
                            type="button"
                            class="dn-action"
                            aria-label="{{ __('Report post') }}"
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
                            <p class="dn-pop-head">{{ __('Why are you reporting this post?') }}</p>
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
                <span style="margin-right: auto;">{{ __("Delete this post? You can undo for 30 seconds.") }}</span>
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
