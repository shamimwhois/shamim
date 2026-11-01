@props([])

@php
    /**
     * The floating navbar.
     *
     * The Creative Tim Soft UI signature, and the reason the workspace no
     * longer has a conventional topbar: a white pill with 16px corners
     * floating inside the content column, with the canvas showing around it
     * on all four sides. Depth comes from `sui-nav` — one soft shadow — not
     * from a border and not from a full-width band.
     *
     * ## What it carries, left to right
     *
     *   1. the drawer trigger, below `lg` only — at `lg` and up the sidenav
     *      is beside this bar and the trigger would be a lie
     *   2. the breadcrumb (`$crumb`), supplied by the role's header
     *   3. search — a real GET against the posts table, not decoration
     *   4. the action group: view site, notifications, the profile menu
     *
     * ## The notifications are data
     *
     * The bell counts the same pending comments the sidebar badge counts,
     * and the panel lists the four most recent with a way through to the
     * queue. A bell that opens an empty "no notifications" panel every day
     * would be worse than no bell, which is why this reads the database.
     *
     * ## Search and the bell are author-only
     *
     * Both point at `/admin`, so both are hidden from an account that
     * cannot open the target. The bar keeps the drawer trigger,
     * breadcrumb, theme toggle, and profile menu for everyone; the
     * writing tools appear only when `admin:access` is held.
     *
     * ## Why the profile menu is hand-built
     *
     * `flux:profile` is a fine trigger but it owns its avatar, and the
     * avatar here is the same accent-soft initial the sidenav footer uses —
     * two places stating the same identity differently is the drift this
     * component layer exists to prevent. The menu itself is Flux's, so
     * keyboard navigation and outside-click come from the framework.
     */
    $user = auth()->user();

    /*
     * Search and the bell both link into `/admin`, so they only exist for an
     * account that can open it. Reading the queue for anyone else would be a
     * query whose result no link can display.
     */
    $canAuthor = $user->hasPermission(\App\Enums\AccountPermission::AccessAdminPanel);

    $pendingComments = $canAuthor
        ? \App\Models\Comment::query()
            ->pending()
            ->when(
                ! $user->hasPermission(\App\Enums\AccountPermission::ModerateComments),
                fn ($query) => $query->whereHas('post', fn ($postQuery) => $postQuery->where('user_id', $user->id))
            )
            ->with(['post', 'user'])
            ->latest()
            ->limit(4)
            ->get()
        : collect();

    $pendingCount = $pendingComments->count();
@endphp

<nav
    class="sui-nav mx-4 mt-4 mb-6 lg:mx-6 lg:mt-6"
    aria-label="{{ __('Workspace') }}"
>
    <div class="flex items-center gap-1.5 px-3 py-2.5 sm:gap-2.5 sm:px-4">
        {{-- ========================================================= --
         | Drawer trigger — below lg only
         --}}
        <button
            type="button"
            @click="drawer = true"
            class="sui-focus grid size-9 shrink-0 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-ink lg:hidden"
            aria-label="{{ __('Open navigation') }}"
            aria-controls="dashboard-drawer"
            data-test="drawer-open"
        >
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>

        {{-- Breadcrumb --}}
        <div class="min-w-0 flex-1">
            {{ $crumb ?? null }}
        </div>

        {{-- ========================================================= --
         | Search — a real query against the posts table
         --}}
        @if ($canAuthor)
            <form
                method="GET"
                action="{{ route('admin.posts.index') }}"
                class="relative hidden md:block"
                role="search"
            >
                <label for="navbar-search" class="sr-only">{{ __('Search posts') }}</label>

                <svg class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>

                <input
                    id="navbar-search"
                    type="search"
                    name="q"
                    placeholder="{{ __('Search posts…') }}"
                    class="sui-focus w-44 rounded-md border border-sui-hairline bg-sui-well py-1.5 pl-8 pr-3 text-sm text-sui-ink shadow-soft-well transition duration-150 ease-soft placeholder:text-ink-400 focus:border-sui-border-strong lg:w-64"
                />
            </form>
        @endif

        {{-- ========================================================= --
         | Actions
         --}}
        <div class="flex shrink-0 items-center gap-0.5">
            {{-- View site --}}
            <a
                href="{{ route('home') }}"
                class="sui-focus hidden size-9 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-accent-ink sm:grid"
                title="{{ __('View site') }}"
            >
                <span class="sr-only">{{ __('View site') }}</span>
                <flux:icon name="arrow-top-right-on-square" class="size-5" />
            </a>

            {{-- Theme toggle — one control, every dashboard. --}}
            <x-app.ui.theme-toggle />

            {{-- Notifications --}}
            @if ($canAuthor)
              <flux:dropdown position="bottom" align="end">
                  <button
                      type="button"
                      class="sui-focus relative grid size-9 place-items-center rounded-md text-ink-500 transition duration-150 ease-soft hover:bg-sui-hover hover:text-sui-accent-ink"
                      aria-label="{{ __('Notifications') }}"
                  >
                      <flux:icon name="bell" class="size-5" />

                      @if ($pendingCount > 0)
                          <span class="absolute right-1 top-1 grid min-w-4 place-items-center rounded-full bg-sui-attention-badge px-1 text-2xs font-semibold tabular-nums text-sui-attention-badge-ink">
                              {{ $pendingCount }}
                          </span>
                      @endif
                  </button>

                  <flux:menu class="w-72 rounded-xl shadow-soft-2">
                      <flux:menu.heading class="px-3 py-2 text-2xs font-semibold uppercase tracking-wide text-ink-400">
                          {{ __('Notifications') }}
                      </flux:menu.heading>

                      @forelse ($pendingComments as $comment)
                          @php
                              /*
                               * Plain anchors rather than `flux:menu.item`: the item
                               * component reserves a column for its own icon, and these
                               * rows carry a two-line body that has no room for a
                               * placeholder column. The keyboard and outside-click
                               * behaviour lives on `flux:menu`, not on the items.
                               */
                              $tone = ['primary', 'info', 'success', 'warning', 'danger', 'secondary'][$loop->index % 6];
                          @endphp

                          <a
                              href="{{ route('admin.comments', ['post' => $comment->post_id]) }}"
                              wire:navigate
                              class="sui-focus flex items-start gap-2.5 rounded-md px-3 py-2 transition duration-150 ease-soft hover:bg-sui-hover"
                          >
                              <span class="grid size-8 shrink-0 place-items-center rounded-md bg-sui-pastel-{{ $tone }}-wash text-sui-pastel-{{ $tone }}-ink shadow-soft-1">
                                  <flux:icon name="chat-bubble-left-right" class="size-3.5" />
                              </span>

                              <span class="min-w-0 flex-1">
                                  <span class="block truncate text-sm font-medium text-sui-ink">
                                      {{ Str::limit($comment->post?->title ?? __('(deleted post)'), 28) }}
                                  </span>
                                  <span class="mt-0.5 block truncate text-xs text-ink-500">
                                      {{ $comment->user?->name ?? __('Former member') }} · {{ $comment->created_at->diffForHumans() }}
                                  </span>
                              </span>
                          </a>
                      @empty
                          <p class="px-3 py-6 text-center text-sm text-ink-500">
                              {{ __('Nothing waiting.') }}
                          </p>
                      @endforelse

                      <flux:menu.separator class="my-1" />

                      <flux:menu.item :href="route('admin.comments')" wire:navigate class="rounded-md">
                          {{ __('Review the queue') }}
                      </flux:menu.item>
                  </flux:menu>
              </flux:dropdown>
            @endif

            {{-- Profile --}}
            <flux:dropdown position="bottom" align="end">
                <button
                    type="button"
                    class="sui-focus flex items-center gap-2 rounded-md py-1.5 pl-1.5 pr-2 transition duration-150 ease-soft hover:bg-sui-hover"
                >
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-sui-accent-soft text-xs font-semibold text-sui-accent-ink">
                        {{ Str::of($user->name)->substr(0, 1)->upper() }}
                    </span>
                    <span class="hidden text-sm font-medium text-ink-700 sm:inline">
                        {{ Str::of($user->name)->explode(' ')->first() }}
                    </span>
                    <flux:icon name="chevron-down" class="size-3.5 text-ink-400" />
                </button>

                <flux:menu class="w-64 rounded-xl shadow-soft-2">
                    <div class="px-3 py-2.5">
                        <p class="truncate text-sm font-medium text-sui-ink">{{ $user->name }}</p>
                        <p class="mt-0.5 truncate text-xs text-ink-500">{{ $user->email }}</p>
                        <div class="mt-2">
                            <x-app.ui.role-badge size="md" />
                        </div>
                    </div>

                    <flux:menu.separator class="my-1" />

                    <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate class="rounded-md">
                        {{ __('Settings') }}
                    </flux:menu.item>

                    <flux:menu.item :href="route('home')" icon="arrow-top-right-on-square" wire:navigate class="rounded-md">
                        {{ __('View site') }}
                    </flux:menu.item>

                    <flux:menu.separator class="my-1" />

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:menu.item type="submit" variant="danger" icon="arrow-right-start-on-rectangle" class="rounded-md">
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>
</nav>
