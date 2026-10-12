# Component Reference

Every reusable view in the application. Props are transcribed from the `@props`
block in each file — if this document and the source disagree, the source wins,
and the document is wrong.

Naming follows Laravel's convention throughout:

| Directory | Tag |
| --- | --- |
| `components/foo.blade.php` | `<x-foo />` |
| `components/foo/bar.blade.php` | `<x-foo.bar />` |
| `layouts/auth/split.blade.php` | `<x-layouts::auth.split />` |
| `pages/blog/⚡index.blade.php` | `pages::blog.index` (route target) |

---

## 1. Shared components

### `<x-section-marker>`

The numbered monospace marker — the site's signature element. `01 — Selected
work` renders as a two-character ordinal, a hairline rule and a tracked
uppercase label. Appears at the top of every major section so the page has a
visible ribcage.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `number` | `int\|string` | — | Zero-padded to two digits |
| `label` | `?string` | `null` | Omitted → marker renders without a label |
| `accent` | `bool` | `false` | Uses `signal-*` instead of `ink-*` |

```blade
<x-section-marker number="2" label="Recent writing" accent />
```

`accent` should mark at most one section per page. If everything is accented,
nothing is.

---

### `<x-empty-state>`

The one sanctioned way to render "there is nothing here". Central to the
project's stance that an empty state is a design opportunity, not an accident.

| Prop | Type | Default |
| --- | --- | --- |
| `title` | `string` | `'Nothing here yet'` |
| `description` | `?string` | `null` |
| `icon` | `string` | `'magnifying-glass'` |

The default `icon` must be a real Flux icon name. Several invalid names were
found in this codebase — see [§8](#8-known-issues).

---

### `<x-tag-pill>`

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `tag` | `Tag` | — | Model instance |
| `active` | `bool` | `false` | Selected state for filter chips |
| `count` | `?int` | `null` | Renders a trailing count when present |

---

### `<x-pagination>`

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `paginator` | `LengthAwarePaginator` | — | |
| `label` | `?string` | `null` | Accessible name for the nav landmark |

---

### `<x-app-logo>` / `<x-app-logo-icon>`

| Component | Prop | Type | Default |
| --- | --- | --- | --- |
| `<x-app-logo>` | `sidebar` | `bool` | `false` |

`sidebar` switches between `flux:brand` and `flux:sidebar.brand`. The icon is a
bare inline SVG (`viewBox="0 0 40 42"`, 5 `currentColor` paths) so it inherits
text colour and needs no asset request.

---

### `<x-placeholder-pattern>`

An 8px diagonal lattice as an SVG `<pattern>`. Used as a texture wash behind the
portfolio hero and the auth layout's editorial panel.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `id` | `string` | `uniqid()` | **Two instances on one page need distinct ids** |

That default is a genuine footgun: SVG `<pattern id>` is document-global, so two
default instances collide and the second renders blank. Pass an explicit `id`
when using more than one.

---

### `<x-modal>` / `<x-modal.actions>` / `<x-modal.trigger>`

The shared modal system. Full rationale in
[`ARCHITECTURE.md`](ARCHITECTURE.md#the-modal-system).

**`<x-modal>`**

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `name` | `string` | — | Must match its triggers |
| `title` | `string` | — | Rendered as `<flux:heading>` with `aria-labelledby` |
| `description` | `?string` | `null` | Rendered as `<flux:subheading>` |
| `icon` | `?string` | `null` | Real Flux icon; omitted → no icon block |
| `tone` | `'default'\|'danger'` | `'default'` | Header icon treatment only |
| `maxWidth` | `'sm'\|'md'\|'lg'\|'xl'\|'2xl'` | `'lg'` | Maps to a `max-w-*` class |
| `showOnError` | `bool` | `true` | Reopens when the error bag is non-empty |

`{{ $attributes }}` is **merged**, not replaced, so extra classes and Alpine
handlers pass through:

```blade
<x-modal
    name="two-factor-setup-modal"
    :title="$this->modalConfig['title']"
    :description="$this->modalConfig['description']"
    max-width="md"
    class="md:min-w-md"        {{-- merged alongside max-w-md --}}
    @close="closeModal"        {{-- pass-through --}}
>
```

`tone="danger"` does **not** make the confirming button destructive. That button
declares `variant="danger"` itself, because the destructive control is often
outside the shell.

**`<x-modal.actions>`**

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `cancel` | `?string` | `null` | Falls back to `__('Cancel')` |
| `submit` | `?string` | `null` | Label for the primary action |
| `variant` | `string` | `'primary'` | |
| `cancelVariant` | `string` | `'filled'` | |
| `danger` | `bool` | `false` | Forces the primary variant to `danger` |

With no slot content it renders a `flux:modal.close` cancel button — the common
case. Supply slot content to replace it:

```blade
<x-modal.actions>
    <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
    <flux:button variant="danger" type="submit">Delete</flux:button>
</x-modal.actions>
```

**`<x-modal.trigger>`** — `name` only. A pass-through to
`<flux:modal.trigger>` that exists so a modal's name is written in exactly one
place. A renamed modal opened by a stale trigger still renders, still looks
right, and does nothing when clicked.

---

## 2. Authentication components

### `<x-auth-header>`

The heading block inside the auth form column — *not* the editorial panel, which
has its own headline.

| Prop | Type | Default |
| --- | --- | --- |
| `title` | `string` | — |
| `description` | `?string` | `null` |
| `eyebrow` | `?string` | `null` |

`eyebrow` renders the monospace ordinal used across the site, so the move from
marketing pages into authenticated screens reads as one design.

---

### `<x-auth-session-status>`

| Prop | Type | Default |
| --- | --- | --- |
| `status` | `?string` | — |

Wraps a `flux:callout` in a `role="status"` live region. Renders nothing when
`status` is null. The live region announces without stealing focus — the visitor
is usually mid-task when it appears, and this replaced bare green text that
carried no semantic weight.

---

### `<x-passkey-verify>`

| Prop | Type | Default |
| --- | --- | --- |
| `optionsRoute` | `string` | `'passkey.login-options'` |
| `submitRoute` | `string` | `'passkey.login'` |
| `label` | `string` | `__('Sign in with a passkey')` |
| `loadingLabel` | `string` | `__('Authenticating...')` |
| `separator` | `string` | `__('Or continue with email')` |

Route names are props rather than hardcoded so the component can be reused
outside the login screen without editing it.

---

### `<x-passkey-registration>`

No props. Registration counterpart, used in the security settings page.

---

### `<x-team-invitation-alert>`

| Prop | Type | Notes |
| --- | --- | --- |
| `invitation` | `array` | Needs `teamName` and `code` keys |
| `action` | `string` | Verb for the sentence — `'Log in'` or `'Register'` |

Renders an informational callout when a visitor arrives via an invitation link.
Carries `data-test="team-invitation-alert"`.

---

## 3. Dashboard components

### `<x-dashboard.header>`

| Prop | Type | Default |
| --- | --- | --- |
| `title` | `string` | — |
| `description` | `?string` | `null` |
| `eyebrow` | `?string` | `null` |

Page header for dashboard screens. `eyebrow` takes the permission-group label so
a page states which area of the product it belongs to.

---

### `<x-dashboard.nav>`

No props. Renders the sidebar by delegating to
`App\Support\DashboardNavigation::for(auth()->user())`.

This component contains **no permission logic of its own**, and that is its
entire value. The nav items and the route guards read the same definitions, so
a menu entry cannot be visible to an account whose route would refuse it.

The `$badge` resolved per item is always an `int` — never a sentinel string.
An earlier version used `'pending-comments'` as a placeholder and leaked it into
the markup; `DashboardNavigation::for()` now resolves it to a count, which also
satisfied PHPStan's return-type check.

---

### `<x-dashboard.role-badge>`

| Prop | Type | Default |
| --- | --- | --- |
| `user` | `?User` | `null` → `auth()->user()` |
| `size` | `'sm'\|'md'` | `'sm'` |

Reads `accountRole()` and `plan()` off the account, so the badge cannot disagree
with the sidebar. Used in the dashboard plan card and the mobile header.

---

### `<x-dashboard.stat>`

A single metric tile.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `label` | `string` | — | |
| `value` | `int\|string` | — | |
| `icon` | `string` | `'squares-2x2'` | Must be a real Flux icon |
| `hint` | `?string` | `null` | Secondary line |
| `href` | `?string` | `null` | Present → tile becomes a link |
| `tone` | `string` | `'default'` | |

---

### `⚡team-switcher` — `<livewire:team-switcher />`

A Livewire SFC (note the `⚡`) rather than a plain component, because it holds
state and dispatches events.

No props. Renders a dropdown of the account's teams plus a "New team" action
that opens the `create-team-switcher` modal.

**Lives in `components/`, not `pages/`**, and is invoked **without a namespace
prefix**. Both are intentional:

- It renders inside a sidebar, not at a route, so it does not belong under
  `pages/`.
- `config/livewire.php` lists `resource_path('views/components')` in
  `component_locations`, so a bare `<livewire:team-switcher />` resolves there.
  Only `component_namespaces` (`pages::`, `layouts::`) need a prefix.

Note that **the current dashboard layout does not use this component at all** —
the only `livewire:team-switcher` references are in the legacy
`layouts/app/*` files (see [§6](#6-layouts)).

---

### `⚡create-team-modal`

Livewire SFC. The modal body for creating a team. Closes by dispatching
`close-modal` with the name `create-team-switcher`; paired with the
`create-team` and `create-team-switcher` triggers elsewhere.

---

## 4. Post components

### `<x-post.card>`

The post summary used on the index and in related-post lists.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `post` | `Post` | — | |
| `featured` | `bool` | `false` | Larger treatment for the lead post |
| `headingLevel` | `string` | `'h3'` | **Must be passed correctly** — see below |
| `showExcerpt` | `bool` | `true` | |

`headingLevel` exists because the same card appears under an `h1` on the index
and under an `h2` on a post detail page. Hardcoding `h3` produced a document
outline that skipped a level — a real accessibility defect, not a style
preference.

---

### `<x-post.toc>`

| Prop | Type | Default |
| --- | --- | --- |
| `items` | `array` | `[]` |
| `title` | `string` | `'On this page'` |
| `id` | `string` | `'toc'` |

Table of contents from extracted headings. Renders nothing when `items` is
empty — an "On this page" heading above nothing is worse than no heading.

---

### `<x-post.reading-progress>`

| Prop | Type | Default |
| --- | --- | --- |
| `target` | `string` | `'[data-reading-target]'` |

A thin scroll-progress bar. The selector is a prop so the bar can be pointed at
a specific element rather than always the document.

---

### `<x-post.share-bar>`

| Prop | Type | Notes |
| --- | --- | --- |
| `post` | `Post` | Needs an absolute canonical URL |

Renders share targets from `config/portfolio.php`. See `<x-share-icon>`.

---

### `<x-share-icon>`

| Prop | Type | Notes |
| --- | --- | --- |
| `name` | `string` | A key from the share-target config |

Brand marks are inlined as SVG paths because the free Flux tier has no brand
icons.

---

### `<x-comment>`

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `comment` | `Comment` | — | |
| `isReply` | `bool` | `false` | Indents and adjusts the heading level |

---

## 5. Site chrome

### `<x-site.header>` / `<x-site.footer>`

No props. Used by `layouts/public.blade.php` only. Dashboard screens use
`layouts/dashboard.blade.php` and never render these.

---

### `<x-desktop-user-menu>`

| Prop | Type | Default |
| --- | --- | --- |
| `showTeam` | `bool` | `true` |

The dropdown in the dashboard sidebar footer. Carries
`data-test="sidebar-menu-button"`.

> **Legacy.** This component still uses `zinc-*` classes where the project token
> is `ink-*`. It predates the design token migration and is a candidate for
> conversion; it is listed here rather than silently fixed because it is outside
> the current redesign's scope.

---

## 6. Layouts

| Layout | Tag | Used by |
| --- | --- | --- |
| `layouts/public.blade.php` | `<x-layouts::public>` | Home, blog, about |
| `layouts/dashboard.blade.php` | `<x-layouts::dashboard>` | All dashboard pages |
| `layouts/auth.blade.php` | `<x-layouts::auth>` | All seven Fortify screens |
| `layouts/auth/split.blade.php` | `<x-layouts::auth.split>` | The implementation behind the above |
| `layouts/app.blade.php` | `<x-layouts::app>` | Legacy alias → `app.sidebar` |
| `layouts/app/sidebar.blade.php` | `<x-layouts::app.sidebar>` | Starter-kit, unreferenced by app pages |

`layouts/app/*` are **starter-kit leftovers**. Only
`resources/views/dashboard.blade.php` (also legacy) references them; no
application page does. They are retained rather than deleted because their tests
still exercise them, and they are marked here so nobody mistakes them for the
real dashboard layout.

---

## 7. Livewire single-file components

SFCs live under `resources/views/pages/**` and carry the `⚡` prefix. They are
anonymous classes addressed by **name string**, never `::class`.

| Name string | File | Route |
| --- | --- | --- |
| `pages::home` | `pages/⚡home.blade.php` | `/` |
| `pages::about` | `pages/⚡about.blade.php` | `/about` |
| `pages::blog.index` | `pages/blog/⚡index.blade.php` | `/blog` |
| `pages::blog.show` | `pages/blog/⚡show.blade.php` | `/blog/{post:slug}` |
| `pages::dashboard.index` | `pages/dashboard/⚡index.blade.php` | `/dashboard` |
| `pages::dashboard.comments` | `pages/dashboard/⚡comments.blade.php` | `/dashboard/comments` |
| `pages::dashboard.taxonomy` | `pages/dashboard/⚡taxonomy.blade.php` | `/dashboard/taxonomy` |
| `pages::dashboard.posts.index` | `pages/dashboard/posts/⚡index.blade.php` | `/dashboard/posts` |
| `pages::dashboard.posts.edit` | `pages/dashboard/posts/⚡edit.blade.php` | `/dashboard/posts/{post:id}/edit` |
| `pages::dashboard.seller.index` | `pages/dashboard/seller/⚡index.blade.php` | `/dashboard/seller` |
| `pages::dashboard.admin.index` | `pages/dashboard/admin/⚡index.blade.php` | `/dashboard/admin` |
| `pages::dashboard.admin.users` | `pages/dashboard/admin/⚡users.blade.php` | `/dashboard/admin/users` |
| `pages::settings.profile` | `pages/settings/⚡profile.blade.php` | `/settings/profile` |
| `pages::settings.security` | `pages/settings/⚡security.blade.php` | `/settings/security` |
| `pages::settings.appearance` | `pages/settings/⚡appearance.blade.php` | `/settings/appearance` |
| `pages::settings.delete-user-modal` | `pages/settings/⚡delete-user-modal.blade.php` | (component) |
| `pages::settings.two-factor-setup-modal` | `pages/settings/⚡two-factor-setup-modal.blade.php` | (component) |
| `pages::settings.two-factor.recovery-codes` | `pages/settings/two-factor/⚡recovery-codes.blade.php` | (component) |
| `pages::teams.index` | `pages/teams/⚡index.blade.php` | `/settings/teams` |
| `pages::teams.edit` | `pages/teams/⚡edit.blade.php` | `/settings/teams/{team:slug}/edit` |

`pages/settings/layout.blade.php` is a **plain Blade component**, not an SFC —
the settings shell, invoked as `<x-pages::settings.layout>`.

Modal SFCs receive their target through props rather than reading the route,
which is what allows one `remove-member-modal` to serve every member in a list:

```blade
<livewire:pages::teams.remove-member-modal
    :member-id="$member['id']"
    :modal-name="'remove-member-'.$member['id']"
    :key="'remove-member-modal-'.$member['id']"
/>
```

The `:key` is required. Without it Livewire reuses one component instance across
the loop and every row's modal operates on the same member.

---

### How a page gets its layout

`config/livewire.php` sets `component_layout => 'layouts::public'`. That means
**every** SFC rendered through `Route::livewire()` is wrapped in the public
marketing shell by default — including dashboard pages, unless they say
otherwise.

Dashboard and settings screens therefore opt in explicitly:

```blade
new #[Layout('layouts::dashboard')] class extends Component { … }
```

`component_namespaces` (which gives `pages::` and `layouts::`) and
`component_locations` (which lets `<livewire:team-switcher />` resolve without a
prefix) are both declared there too, so `config/livewire.php` is the file to
read first when a component cannot be found.

---

## 8. Known issues

Concrete defects and hazards found while writing this reference. Recorded so they
are tracked rather than rediscovered.

| # | Issue | Impact |
| --- | --- | --- |
| 1 | `<x-placeholder-pattern>` defaults `id` to `uniqid()` | Two instances on a page collide; the second renders blank. Pass an explicit `id`. |
| 2 | `<x-desktop-user-menu>` uses `zinc-*` tokens | Off-palette against the `ink-*` system. Predates the token migration. |
| 3 | `layouts/app/*` and `views/dashboard.blade.php` are starter-kit leftovers | Unreferenced by app pages but still tested. Confusing to newcomers. |
| 4 | Invalid Flux icon names recurred four times | `storefront`, `book-open-text`, `folder-git-2`, `layout-grid` all threw. All fixed; a repo-wide audit is now part of the verification gate. |

On issue 4: the audit that found them is

```bash
ls vendor/livewire/flux/stubs/resources/views/flux/icon/ | sed 's/\.blade\.php$//' | sort > /tmp/icons.txt
# then every icon="..." in resources/views is checked against that list
```

It is not wired into a test, because the icon set is a vendor artefact and a
failing test on a Flux upgrade would be noise. It is a pre-release checklist
item instead.
