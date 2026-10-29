@props([
    'user' => null,
])

@php
    /**
     * The account menu.
     *
     * The reference draws this as a React dropdown hung off the rail's user
     * chip. Here it is one Blade component rendered once, at the end of the
     * shell, and driven by an Alpine component in `log-shell.js` — because the
     * panel cannot live inside the nav: `.dn-nav` is `display: none` below
     * 1024px, and a `position: fixed` descendant of a hidden subtree is not
     * rendered either, so a phone would have a menu it could never open.
     *
     * Splitting the panel from its triggers also lets the phone's header avatar
     * open the same menu as the desktop chip. Both triggers dispatch
     * `open-user-menu` with their own element, and the panel anchors itself to
     * whichever one was clicked — a chip at the foot of the rail opens upward,
     * a header avatar at the top opens downward.
     *
     * The link set follows the reference (identity, account, site, session)
     * with this app's routes. Two deliberate departures: the reference's
     * "Operations" is the admin workspace here, and its "Terms"/"Privacy" only
     * appear when those routes exist — a menu of dead links is worse than a
     * shorter menu, and `Route::has()` means the day those pages ship they
     * appear without anyone editing this file.
     */
    use App\Enums\AccountPermission;
    use App\Enums\AccountRole;
    use App\Support\LogIdentity;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    $user ??= auth()->user();

    $handle = $user !== null ? LogIdentity::handleFor($user) : '';
    $lane = LogIdentity::lane($handle);

    $account = $user === null ? [] : array_values(array_filter([
        ['label' => __('Starred'), 'href' => route('starred.index'), 'icon' => 'star'],
        ['label' => __('Profile'), 'href' => route('profile.show', ['handle' => $handle]), 'icon' => 'profile'],
        ['label' => __('Settings'), 'href' => route('profile.edit'), 'icon' => 'settings'],

        /*
         * The admin workspace, not a separate "operations" product. It is the
         * only surface gated on `admin:access`, so it is the honest equivalent
         * of the reference's Operations row — and it is hidden rather than
         * disabled for everyone else, because a row that only ever 403s is
         * noise.
         */
        $user->hasAnyPermission(AccountPermission::AccessAdminPanel)
            ? ['label' => __('Dashboard'), 'href' => route('admin.dashboard'), 'icon' => 'grid']
            : null,
    ]));

    $site = [
        ['label' => __('About'), 'href' => route('about'), 'icon' => 'book'],
    ];

    foreach (['terms' => 'lock', 'privacy' => 'lock'] as $name => $icon) {
        if (Route::has($name)) {
            $site[] = ['label' => __(ucfirst($name)), 'href' => route($name), 'icon' => $icon];
        }
    }

    $github = config('portfolio.author.links.github.url');
@endphp

@if ($user !== null)
    <div
        class="dn-user-menu-scope"
        x-data="userMenu()"
        x-on:open-user-menu.window="toggle($event.detail?.el ?? null)"
        x-on:resize.window="reposition()"
        x-on:scroll.window.passive="reposition()"
        x-on:keydown.escape.window="close(true)"
    >
        <div
            x-ref="panel"
            x-show="open"
            x-cloak
            x-transition.opacity.duration.140ms
            x-on:click.outside="close()"
            :style="up
                ? `left:${left}px; bottom:${edge}px;`
                : `left:${left}px; top:${edge}px;`"
            class="dn-user-menu"
            role="menu"
            aria-orientation="vertical"
            aria-label="{{ __('Account') }}"
            data-test="user-menu"
        >
            {{-- Who you are, as the menu's own label — and the first thing you
                 would click, so it is a link to the profile rather than a
                 static caption. --}}
            <a
                href="{{ route('profile.show', ['handle' => $handle]) }}"
                wire:navigate
                class="dn-user-menu-id"
                role="menuitem"
            >
                <span class="dn-avatar" style="--dn-lane: {{ $lane }}; width: 34px; height: 34px; font-size: 12px;">
                    {{ LogIdentity::initials($user->name) }}
                </span>
                <span class="dn-user-menu-id-meta">
                    <span class="dn-user-menu-id-name">{{ $user->name }}</span>
                    <span class="dn-user-menu-id-foot">
                        <span class="dn-user-menu-id-handle">{{ '@'.LogIdentity::handle($handle) }}</span>
                        {{-- The role is only worth printing when it is not the
                             default one — "USER" beside your own name is noise.
                             It rides the handle's line rather than the name's,
                             so a long name keeps its room. --}}
                        @if ($user->role !== null && $user->role !== AccountRole::User)
                            <span class="dn-user-menu-role">{{ $user->role->label() }}</span>
                        @endif
                    </span>
                </span>
            </a>

            <hr class="dn-pop-rule" />

            @foreach ($account as $row)
                <a
                    href="{{ $row['href'] }}"
                    wire:navigate
                    class="dn-pop-item"
                    role="menuitem"
                    data-test="user-menu-{{ Str::slug($row['label']) }}"
                >
                    <x-feed.icon :name="$row['icon']" :size="15" />
                    {{ $row['label'] }}
                </a>
            @endforeach

            <hr class="dn-pop-rule" />

            @foreach ($site as $row)
                <a
                    href="{{ $row['href'] }}"
                    wire:navigate
                    class="dn-pop-item"
                    role="menuitem"
                    data-test="user-menu-{{ Str::slug($row['label']) }}"
                >
                    <x-feed.icon :name="$row['icon']" :size="15" />
                    {{ $row['label'] }}
                </a>
            @endforeach

            @if (is_string($github) && $github !== '')
                <a
                    href="{{ $github }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="dn-pop-item"
                    role="menuitem"
                    data-test="user-menu-github"
                >
                    <x-feed.icon name="github" :size="15" />
                    {{ __('GitHub') }}
                    <x-feed.icon name="external" :size="12" class="dn-pop-trail" />
                </a>
            @endif

            <hr class="dn-pop-rule" />

            {{-- A POST, because logging out is a state change: a GET would let
                 a prefetcher or an image tag end the session. --}}
            <form method="POST" action="{{ route('logout') }}" class="dn-user-menu-form">
                @csrf

                <button type="submit" class="dn-pop-item is-danger" role="menuitem" data-test="logout-button">
                    <x-feed.icon name="logout" :size="15" />
                    {{ __('Log out') }}
                </button>
            </form>
        </div>
    </div>
@endif
