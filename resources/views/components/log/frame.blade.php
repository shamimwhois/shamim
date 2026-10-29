@props([
    'active' => 'log',
])

@php
    /**
     * The log's application frame.
     *
     * One component for every surface that belongs to the product rather than
     * the portfolio: the feed, the inbox, remotes, a profile, the starred list,
     * settings, the marketplace. It draws the three columns the design asks for
     * — a sticky nav, the content column, a sticky aside — plus the phone's
     * header and bottom bar, the search palette and the shortcut sheet, because
     * those are global in the reference design, not page furniture.
     *
     * `active` is the nav key, not a route: a page says where it is, and the
     * frame decides which row that lights up.
     */
    use App\Models\FeedPost;
    use App\Models\LogNotification;
    use App\Models\RemoteMessage;
    use App\Support\LogIdentity;

    $reader = auth()->user();
    $handle = LogIdentity::handleFor($reader);

    $unread = 0;
    $unreadRemotes = 0;

    if ($reader !== null) {
        $unread = LogNotification::query()
            ->where('user_id', $reader->id)
            ->whereNull('read_at')
            ->deliverable()
            ->count();

        // A push is unread when it is newer than the reader's own pull marker
        // on that conversation.
        $unreadRemotes = RemoteMessage::query()
            ->join('remote_participants', function ($join) use ($reader): void {
                $join->on('remote_participants.remote_id', '=', 'remote_messages.remote_id')
                    ->where('remote_participants.user_id', '=', $reader->id);
            })
            ->where('remote_messages.user_id', '!=', $reader->id)
            // A muted conversation still keeps its unread dot in the list; it
            // just stops shouting from the nav. A mute carries its own end, so
            // the check is "still silenced", not "was ever silenced" — an
            // hour-long mute has to release itself without anyone clearing it.
            ->where(function ($query): void {
                $query->whereNull('remote_participants.muted_until_at')
                    ->orWhere('remote_participants.muted_until_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('remote_participants.last_read_at')
                    ->orWhereColumn('remote_messages.created_at', '>', 'remote_participants.last_read_at');
            })
            ->count();
    }

    $items = [
        ['key' => 'log', 'title' => __('Log'), 'href' => route('feed.index'), 'icon' => 'log'],
        ['key' => 'notifications', 'title' => __('Notifications'), 'href' => route('notifications.index'), 'icon' => 'bell', 'badge' => $unread],
        ['key' => 'remotes', 'title' => __('Remotes'), 'href' => route('remotes.index'), 'icon' => 'remotes', 'badge' => $unreadRemotes],
        ['key' => 'profile', 'title' => __('Profile'), 'href' => $reader !== null ? route('profile.show', ['handle' => $handle]) : route('feed.index'), 'icon' => 'profile'],
        ['key' => 'starred', 'title' => __('Starred'), 'href' => route('starred.index'), 'icon' => 'star'],
        ['key' => 'marketplace', 'title' => __('Marketplace'), 'href' => route('marketplace.index'), 'icon' => 'marketplace'],
    ];

    /*
     * The rest of the product, in one group.
     *
     * The first six are the log's own surfaces — the ones a reader uses daily.
     * These five are the reading surfaces: the directory, the writing, the
     * knowledge hub, the ideas board and the lab. They are grouped rather than
     * listed flat because they answer a different question from the log, and a
     * flat list of eleven rows would make the log look like one of many rather
     * than the thing you are in.
     */
    $modules = [
        ['key' => 'communities', 'title' => __('Communities'), 'href' => route('communities.index'), 'icon' => 'communities'],
        ['key' => 'blog', 'title' => __('Blog'), 'href' => route('blog.index'), 'icon' => 'blog'],
        ['key' => 'ai-hub', 'title' => __('AI Hub'), 'href' => route('ai-hub.index'), 'icon' => 'ai-hub'],
        ['key' => 'ideas', 'title' => __('Ideas'), 'href' => route('ideas.index'), 'icon' => 'ideas'],
        ['key' => 'dev-lab', 'title' => __('Dev Lab'), 'href' => route('dev-lab.index'), 'icon' => 'dev-lab'],
    ];

    $suggestions = FeedPost::query()
        ->visible()
        ->selectRaw('handle, author, avatar, count(*) as commits')
        ->groupBy('handle', 'author', 'avatar')
        ->orderByDesc('commits')
        ->limit(3)
        ->get()
        ->reject(fn (FeedPost $post): bool => $reader !== null
            && LogIdentity::handle($post->handle) === $handle)
        ->values();
@endphp

<div
    class="dn-shell"
    data-nav="expanded"
    x-data="logShell({
        searchUrl: @js(route('log.search')),
        profileUrlBase: @js(url('/')),
        destinations: @js([
            'l' => route('feed.index'),
            'n' => route('notifications.index'),
            'r' => route('remotes.index'),
            's' => route('starred.index'),
            'm' => route('marketplace.index'),
            'c' => route('communities.index'),
            'b' => route('blog.index'),
            'a' => route('ai-hub.index'),
            'i' => route('ideas.index'),
            'd' => route('dev-lab.index'),
            'f' => route('search.index'),
        ]),
        everythingUrl: @js(route('search.index')),
    })"
    x-on:keydown.window="onKey($event)"
    {{ $attributes }}
>
    <nav class="dn-nav" aria-label="{{ __('Main') }}">
        <div class="dn-nav-inner">
            <a href="{{ route('feed.index') }}" class="dn-brand" aria-label="{{ __('The Log') }}">
                <svg class="dn-brand-mark" viewBox="0 0 28 28" aria-hidden="true">
                    <path d="M14 0v9M14 19v9" stroke="currentColor" stroke-width="2" />
                    <circle cx="14" cy="14" r="5" fill="none" stroke="currentColor" stroke-width="2.5" />
                </svg>
                <span class="dn-brand-text">
                    <span class="dn-wordmark">~<span class="dn-wordmark-accent">/</span>feed</span>
                </span>
                <span class="dn-brand-tag">{{ __('the community log') }}</span>
            </a>

            <div class="dn-nav-list">
                @foreach ($items as $item)
                    <a
                        href="{{ $item['href'] }}"
                        wire:navigate
                        class="dn-nav-item {{ $active === $item['key'] ? 'is-active' : '' }}"
                        @if ($active === $item['key']) aria-current="page" @endif
                        data-nav-item="{{ $item['key'] }}"
                    >
                        @if ($active === $item['key'])
                            <span class="dn-nav-active" aria-hidden="true"></span>
                        @endif
                        <span class="dn-nav-icon">
                            <x-feed.icon :name="$item['icon']" />
                            @if (($item['badge'] ?? 0) > 0)
                                <span class="dn-nav-badge">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
                            @endif
                        </span>
                        <span class="dn-nav-label">{{ $item['title'] }}</span>
                    </a>
                @endforeach

                {{-- The reading surfaces: a group of their own, separated by a
                     hairline so the log's own rows stay a block. --}}
                <div class="dn-nav-group" role="group" aria-label="{{ __('Reading surfaces') }}">
                    <span class="dn-nav-group-label">{{ __('Explore') }}</span>

                    @foreach ($modules as $item)
                        <a
                            href="{{ $item['href'] }}"
                            wire:navigate
                            class="dn-nav-item {{ $active === $item['key'] ? 'is-active' : '' }}"
                            @if ($active === $item['key']) aria-current="page" @endif
                            data-nav-item="{{ $item['key'] }}"
                        >
                            @if ($active === $item['key'])
                                <span class="dn-nav-active" aria-hidden="true"></span>
                            @endif
                            <span class="dn-nav-icon">
                                <x-feed.icon :name="$item['icon']" />
                            </span>
                            <span class="dn-nav-label">{{ $item['title'] }}</span>
                        </a>
                    @endforeach
                </div>

                <a
                    href="{{ route('profile.edit') }}"
                    wire:navigate
                    class="dn-nav-item {{ $active === 'settings' ? 'is-active' : '' }}"
                    @if ($active === 'settings') aria-current="page" @endif
                    data-nav-item="settings"
                >
                    @if ($active === 'settings')
                        <span class="dn-nav-active" aria-hidden="true"></span>
                    @endif
                    <span class="dn-nav-icon">
                        <x-feed.icon name="settings" />
                    </span>
                    <span class="dn-nav-label">{{ __('Settings') }}</span>
                </a>

                <button
                    type="button"
                    class="dn-nav-item dn-nav-primary"
                    data-test="nav-commit"
                    @if ($reader !== null)
                        x-on:click="$el.closest('.dn-shell').querySelector('[data-composer-input]')?.focus()"
                    @else
                        x-on:click="$flux.modal('login').show(); $event.preventDefault()"
                    @endif
                >
                    <x-feed.icon name="pen" />
                    <span class="dn-nav-label">{{ __('Commit') }}</span>
                </button>

                <button
                    type="button"
                    class="dn-nav-item dn-nav-collapse"
                    data-test="nav-collapse"
                    x-on:click="$el.closest('.dn-shell').dataset.nav = $el.closest('.dn-shell').dataset.nav === 'expanded' ? 'collapsed' : 'expanded'"
                    aria-label="{{ __('Collapse navigation') }}"
                >
                    <x-feed.icon name="collapse" />
                    <span class="dn-nav-label">{{ __('Collapse') }}</span>
                </button>
            </div>

            @if ($reader !== null)
                {{--
                    The account chip. It stays a link to the profile — with
                    JavaScript off it is exactly the button it looks like — and
                    Alpine upgrades the click into the account menu.

                    No `wire:navigate` here on purpose: the navigator intercepts
                    the click before Alpine can prevent it, so the chip would
                    walk to the profile instead of opening the menu. `.stop` is
                    just as load-bearing — without it the click reaches the
                    document and the open menu's own `click.outside` closes it a
                    tick before this handler reopens it.
                --}}
                <a
                    href="{{ route('profile.show', ['handle' => $handle]) }}"
                    class="dn-user"
                    x-data="{ menuOpen: false }"
                    x-on:user-menu-state.window="menuOpen = $event.detail.open"
                    x-on:click.prevent.stop="$dispatch('open-user-menu', { el: $el })"
                    x-on:keydown.down.prevent="$dispatch('open-user-menu', { el: $el })"
                    :aria-expanded="menuOpen"
                    aria-haspopup="menu"
                    aria-label="{{ __('Account') }}"
                    data-test="user-menu-trigger"
                >
                    <span class="dn-avatar" style="--dn-lane: {{ LogIdentity::lane($handle) }}; width: 36px; height: 36px; font-size: 12px;">
                        {{ LogIdentity::initials($reader->name) }}
                    </span>
                    <span class="dn-user-meta">
                        <span class="dn-user-name">{{ $reader->name }}</span>
                        <span class="dn-user-handle">{{ '@'.LogIdentity::handle($handle) }}</span>
                    </span>
                    <span class="dn-user-more" aria-hidden="true">
                        <x-feed.icon name="chevron-down" :size="14" />
                    </span>
                </a>
            @else
                <a
                    href="{{ route('login') }}"
                    x-on:click="$flux.modal('login').show(); $event.preventDefault()"
                    data-test="sign-in-trigger"
                    class="dn-btn dn-nav-primary"
                    style="margin-top: auto; justify-content: center; gap: 8px;"
                >
                    <x-feed.icon name="github" :size="16" />
                    <span class="dn-nav-label">{{ __('Sign in') }}</span>
                </a>
            @endif
        </div>
    </nav>

    <main class="dn-main" id="main">
        <div class="dn-mobile-header">
            <a href="{{ route('feed.index') }}" aria-label="{{ __('The Log') }}">
                <span class="dn-wordmark">~/feed</span>
            </a>
            <div class="dn-mobile-actions">
                <button type="button" class="dn-icon-btn" x-on:click="openSearch()" aria-label="{{ __('Search developers') }}">
                    <x-feed.icon name="search" />
                </button>
                @if ($reader !== null)
                    {{-- The same menu as the rail's chip, opened downward
                         because this one sits at the top of the screen. --}}
                    <a
                        href="{{ route('profile.show', ['handle' => $handle]) }}"
                        class="dn-mobile-avatar"
                        x-data="{ menuOpen: false }"
                        x-on:user-menu-state.window="menuOpen = $event.detail.open"
                        x-on:click.prevent.stop="$dispatch('open-user-menu', { el: $el })"
                        :aria-expanded="menuOpen"
                        aria-haspopup="menu"
                        aria-label="{{ __('Account') }}"
                        data-test="user-menu-trigger-mobile"
                    >
                        <span class="dn-avatar" style="--dn-lane: {{ LogIdentity::lane($handle) }}; width: 32px; height: 32px; font-size: 11px;">
                            {{ LogIdentity::initials($reader->name) }}
                        </span>
                    </a>
                @endif
            </div>
        </div>

        {{ $header ?? '' }}

        {{ $slot }}
    </main>

    <aside class="dn-aside" aria-label="{{ __('About the log') }}">
        <div class="dn-aside-inner">
            <button type="button" class="dn-search-button" x-on:click="openSearch()" data-test="search-button">
                <x-feed.icon name="search" :size="16" />
                {{ __('Search developers') }}
                <kbd class="dn-kbd">⌘K</kbd>
            </button>

            @if ($reader !== null)
                <section class="dn-aside-section" aria-labelledby="aside-you">
                    <h2 id="aside-you" class="sr-only">{{ __('You') }}</h2>

                    <a href="{{ route('profile.show', ['handle' => $handle]) }}" class="dn-you" wire:navigate>
                        <span class="dn-avatar" style="--dn-lane: {{ LogIdentity::lane($handle) }};">
                            {{ LogIdentity::initials($reader->name) }}
                        </span>
                        <span style="min-width: 0;">
                            <span class="dn-you-name">{{ $reader->name }}</span>
                            <span class="dn-you-handle">
                                <span class="dn-lane-dot" style="--dn-lane: {{ LogIdentity::lane($handle) }}"></span>
                                {{ '@'.LogIdentity::handle($handle) }}
                            </span>
                        </span>
                    </a>

                    <dl class="dn-stats">
                        <div class="dn-stat">
                            <dt>{{ __('commits') }}</dt>
                            <dd>{{ $reader->feedPosts()->count() }}</dd>
                        </div>
                        <div class="dn-stat">
                            <dt>{{ __('stars') }}</dt>
                            <dd>{{ $reader->feedStars()->count() }}</dd>
                        </div>
                        <div class="dn-stat">
                            <dt>{{ __('remotes') }}</dt>
                            <dd>{{ $reader->remotes()->count() }}</dd>
                        </div>
                    </dl>
                </section>
            @else
                <section class="dn-card" aria-labelledby="aside-sign-in">
                    <h2 id="aside-sign-in" class="dn-aside-title">{{ __('New here?') }}</h2>
                    <p>{{ __('Sign in to commit, star what you like, and reply. Reading needs no account.') }}</p>
                    <a
                        href="{{ route('login') }}"
                        x-on:click="$flux.modal('login').show(); $event.preventDefault()"
                        data-test="sign-in-trigger"
                        class="dn-btn dn-btn-block"
                        style="margin-top: 16px;"
                    >
                        <x-feed.icon name="github" :size="16" />
                        {{ __('Sign in') }}
                    </a>
                </section>
            @endif

            @if ($suggestions->isNotEmpty())
                <section class="dn-aside-section" aria-labelledby="aside-who">
                    <h2 id="aside-who" class="dn-aside-title">{{ __('Active in the log') }}</h2>

                    <div class="dn-who">
                        @foreach ($suggestions as $author)
                            <a
                                class="dn-who-row"
                                href="{{ route('profile.show', ['handle' => LogIdentity::handle($author->handle)]) }}"
                                wire:navigate
                            >
                                <span class="dn-avatar" style="--dn-lane: {{ LogIdentity::lane($author->handle) }};">{{ $author->avatar }}</span>
                                <span class="dn-who-body">
                                    <span class="dn-who-name">{{ $author->author }}</span>
                                    <span class="dn-who-meta">
                                        {{ '@'.LogIdentity::handle($author->handle) }} · {{ trans_choice(':count commit|:count commits', (int) $author->commits, ['count' => (int) $author->commits]) }}
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="dn-aside-foot">
                <a href="{{ route('about') }}" wire:navigate>{{ __('About') }}</a>
                <a href="{{ route('blog.index') }}" wire:navigate>{{ __('Blog') }}</a>
                <a href="{{ route('communities.index') }}" wire:navigate>{{ __('Communities') }}</a>
                <a href="{{ route('marketplace.index') }}" wire:navigate>{{ __('Marketplace') }}</a>
                <a href="{{ route('topics.index') }}" wire:navigate>{{ __('Browse by subject') }}</a>
                <a href="{{ route('feed') }}">Atom</a>
            </div>

            <div class="dn-aside-tools">
                <x-app.ui.theme-toggle />

                <button type="button" class="dn-shortcuts-link" x-on:click="$dispatch('open-shortcuts')">
                    {{ __('Keyboard shortcuts') }}
                    <kbd class="dn-kbd">?</kbd>
                </button>
            </div>
        </div>
    </aside>

    <nav class="dn-mobile-bar" aria-label="{{ __('Main') }}">
        @foreach (array_slice($items, 0, 4) as $item)
            <a
                href="{{ $item['href'] }}"
                wire:navigate
                class="dn-mobile-item {{ $active === $item['key'] ? 'is-active' : '' }}"
                @if ($active === $item['key']) aria-current="page" @endif
            >
                <x-feed.icon :name="$item['icon']" />
                <span>{{ $item['title'] }}</span>
            </a>
        @endforeach
    </nav>

    {{-- The palette: a combobox over developers, not commits. --}}
    <div
        class="dn-overlay"
        x-show="searchOpen"
        x-cloak
        x-on:click.self="closeSearch()"
        x-transition.opacity
    >
        <div class="dn-dialog" role="dialog" aria-modal="true" aria-label="{{ __('Search developers') }}">
            <div class="dn-dialog-input">
                <x-feed.icon name="search" :size="18" />
                <input
                    type="text"
                    x-ref="paletteInput"
                    x-model="search"
                    x-on:input.debounce.250ms="runSearch()"
                    placeholder="{{ __('Search developers') }}"
                    autocomplete="off"
                    aria-label="{{ __('Search developers') }}"
                    data-test="search-input"
                />
                <kbd class="dn-kbd">esc</kbd>
            </div>

            <div class="dn-dialog-list">
                <template x-if="results.length === 0">
                    <p class="dn-dialog-empty">
                        <span x-show="search.trim() === ''">{{ __('Type a name or @handle.') }}</span>
                        <span x-show="search.trim() !== '' && ! searching">fatal: no developers match '<span x-text="search"></span>'</span>
                        <span x-show="searching">{{ __('Searching…') }}</span>
                    </p>
                </template>

                {{-- The escape hatch: the palette is a people-finder. Searching it
                     for prose is a different question, and the page that answers
                     it is one enter away. --}}
                <a
                    class="dn-result"
                    x-show="search.trim() !== '' && ! searching"
                    x-bind:href="searchEverythingUrl"
                    data-test="palette-search-everything"
                >
                    <span class="dn-avatar" style="--dn-lane: var(--dn-primary);" aria-hidden="true">
                        <x-feed.icon name="search" :size="14" />
                    </span>
                    <span style="min-width: 0;">
                        <span class="dn-result-name">{{ __('Search everything for') }} '<span x-text="search"></span>'</span>
                        <span class="dn-result-handle">{{ __('commits, posts, ideas, articles and snippets') }}</span>
                    </span>
                </a>

                <template x-for="(result, index) in results" :key="result.handle">
                    <a
                        class="dn-result"
                        x-bind:class="{ 'is-active': index === activeResult }"
                        x-bind:href="profileUrlBase + '/@' + result.handle"
                        x-on:mouseenter="activeResult = index"
                    >
                        <span class="dn-avatar" x-bind:style="`--dn-lane: ${result.lane}`" x-text="result.initials"></span>
                        <span style="min-width: 0;">
                            <span class="dn-result-name" x-text="result.name"></span>
                            <span class="dn-result-handle">
                                <span class="dn-lane-dot" x-bind:style="`--dn-lane: ${result.lane}`"></span>
                                <span x-text="'@' + result.handle"></span>
                            </span>
                        </span>
                        <span class="dn-result-bio" style="margin-left: auto;" x-text="result.commits + ' commits'"></span>
                    </a>
                </template>
            </div>

            <div class="dn-dialog-foot">
                <span class="dn-hint"><kbd class="dn-kbd">↑</kbd><kbd class="dn-kbd">↓</kbd> {{ __('to move') }}</span>
                <span class="dn-hint"><kbd class="dn-kbd">↵</kbd> {{ __('to open') }}</span>
                <span class="dn-hint"><kbd class="dn-kbd">esc</kbd> {{ __('to close') }}</span>
            </div>
        </div>
    </div>

    {{-- The shortcut sheet: what the frame listens for, and nothing else. --}}
    <div
        class="dn-overlay"
        x-data="{ open: false }"
        x-on:open-shortcuts.window="open = true"
        x-on:keydown.escape.window="open = false"
        x-show="open"
        x-cloak
        x-on:click.self="open = false"
        x-transition.opacity
    >
        <div class="dn-dialog" role="dialog" aria-modal="true" aria-label="{{ __('Keyboard shortcuts') }}">
            <div class="dn-dialog-input" style="justify-content: space-between;">
                <span style="font-size: 15px; font-weight: 600;">{{ __('Keyboard shortcuts') }}</span>
                <button type="button" class="dn-icon-btn" x-on:click="open = false" aria-label="{{ __('Close') }}">
                    <span aria-hidden="true">×</span>
                </button>
            </div>

            <div class="dn-dialog-list" style="padding: 16px;">
                <div class="dn-sheet-section">
                    <p class="dn-sheet-title">{{ __('Log') }}</p>
                    @foreach ([
                        [['j', 'k'], __('Move between commits')],
                        [['c'], __('Focus the composer')],
                        [['s'], __('Star the focused commit')],
                        [['r'], __('Reply to the focused commit')],
                        [['o'], __('Show or hide its replies')],
                    ] as [$keys, $label])
                        <div class="dn-sheet-row">
                            <span>{{ $label }}</span>
                            <span class="dn-sheet-keys">
                                @foreach ($keys as $key)
                                    <kbd class="dn-kbd">{{ $key }}</kbd>
                                @endforeach
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="dn-sheet-section">
                    <p class="dn-sheet-title">{{ __('Navigation') }}</p>
                    @foreach ([
                        [['g', 'l'], __('Go to the log')],
                        [['g', 'n'], __('Go to notifications')],
                        [['g', 'r'], __('Go to remotes')],
                        [['g', 's'], __('Go to starred')],
                        [['g', 'm'], __('Go to the marketplace')],
                        [['g', 'c'], __('Go to communities')],
                        [['g', 'b'], __('Go to the blog')],
                        [['g', 'a'], __('Go to the AI hub')],
                        [['g', 'i'], __('Go to ideas')],
                        [['g', 'd'], __('Go to the dev lab')],
                        [['g', 'f'], __('Search everything')],
                        [['⌘', 'K'], __('Search developers')],
                    ] as [$keys, $label])
                        <div class="dn-sheet-row">
                            <span>{{ $label }}</span>
                            <span class="dn-sheet-keys">
                                @foreach ($keys as $key)
                                    <kbd class="dn-kbd">{{ $key }}</kbd>
                                @endforeach
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- The account menu lives outside the nav on purpose: `.dn-nav` is
         `display: none` on a phone, and a fixed-position child of a hidden
         subtree is not painted either. --}}
    <x-log.user-menu :user="$reader" />
</div>
