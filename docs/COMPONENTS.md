# Component Reference

Every reusable view in the application. Props are transcribed from the `@props`
block in each file — if this document and the source disagree, the source wins,
and the document is wrong.

Naming follows Laravel's convention throughout:

| Directory | Tag |
| --- | --- |
| `components/app/ui/foo.blade.php` | `<x-app.ui.foo />` |
| `components/app/icon/logo-mark.blade.php` | `<x-app.icon.logo-mark />` |
| `components/app/user/sidebar.blade.php` | `<x-app.user.sidebar />` |
| `layouts/auth/split.blade.php` | `<x-layouts::auth.split />` |
| `pages/blog/⚡index.blade.php` | `pages::blog.index` (route target) |

---

## 0. The directory map

Every component lives under `components/app/`. That prefix is not decoration:
it namespaces this application's components away from every package's anonymous
components, so `<x-app.ui.modal>` can never be shadowed by a vendor package
registering `<x-modal>` — which is exactly the collision the old flat
`components/modal.blade.php` was exposed to.

```
components/app/
├── home/          guest chrome — the public header and footer
├── ui/            shared primitives, role-agnostic by definition
│   ├── auth/      authentication screens
│   ├── editor/    the Markdown editor and its AI affordances
│   ├── modal/     the shared modal system
│   └── post/      post cards, TOC, share bar
├── icon/          icon components — brand marks and the wordmark glyph
├── user/          the author workspace's chrome
├── seller/        the selling workspace's chrome
└── admin/         the administration workspace's chrome
```

The rule that keeps it clean: **a component goes in `ui/` unless a role owns
it.** `ui/` is the only directory that may not branch on role — the moment a
component needs to know whether it is rendering for a seller, it belongs in
that role's directory. `icon/` holds components whose entire body is a glyph.

The layouts mirror the same audience-first shape:

```
layouts/
├── guest/         unauthenticated visitors — home, blog, about
│   └── home       the default layout (config/livewire.php component_layout)
├── auth/          every authenticated account, on the way in
│   └── split      the implementation behind layouts::auth
├── app/           the general authenticated workspace
│   └── dashboard  /dashboard and the four platform modules
├── seller/        the seller role
│   └── dashboard  /seller/dashboard
└── admin/         the admin role
    └── dashboard  /admin/dashboard, /admin/users
```

---

## 1. Shared components

### `<x-app.ui.section-marker>`

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
<x-app.ui.section-marker number="2" label="Recent writing" accent />
```

`accent` should mark at most one section per page. If everything is accented,
nothing is.

---

### `<x-app.ui.why-hire-me>`

A self-contained value-proposition section — "why hire me" — in the site's
hairline-grid visual language: a marker, a heading, a numbered grid of
claims and an optional closing CTA. No icons on purpose, so the grid reads as
a rank ordering rather than a feature wall (that look belongs to the
Expertise grid further down the About page).

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `number` | `int\|string` | — | Passed to the section marker |
| `accent` | `bool` | `false` | Accent the marker — the site's convention for a page-opening section |
| `label` | `string` | `'Why hire me'` | Marker label |
| `reasons` | `array<int, array{title: string, body: string}>` | `[]` | The value claims |
| `ctaHref` | `?string` | `null` | Present → mono CTA line renders a link |
| `ctaLabel` | `string` | `'Get in touch'` | |
| `headingId` | `string` | `'why-hire-me-heading'` | For `aria-labelledby`; override if two instances share a page |

The copy is a prop, not config: prose belongs next to the page that renders
it, same stance as the About page's principles. One call site today —
`pages::about` (section 02, right after the bio).

```blade
<x-app.ui.why-hire-me :number="2" :reasons="$this->reasons()" :ctaHref="$email['url']" />
```

---

### `<x-app.ui.expertise-marquee>`

A full-bleed, horizontally scrolling band of the author's expertise domains —one pill per item (icon + mono uppercase label), separated by signal dots,*seamlessly looping via a duplicated track and a CSS translate to -50%*. The
design copies mhabir.dev's capability strip that opens its page under the*hero; the content and tokens are this site's. Loop mechanics live in`resources/css/about-effects.css`; the component is pure markup.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `items` | `array<int, array{title: string, icon: string, ...}>` | — | Fed from `config('portfolio.author.expertise')` |
| `anchor` | `string` | `'expertise-heading'` | Every pill links# to this heading id |

Accessibility contract: the second copy of the sequence is `aria-hidden` andits pills carry `tabindex="-1"`, so keyboard users traverse the list exactlyonce; the animation pauses on hover and never runs under reduced motion.

```blade
<x-app.ui.expertise-marquee :items="$this->profile()->topExpertise()" />
```

---

### `<x-app.ui.cta-marquee>`

The floor band that closes the about page: an endless leftward drift of theoutlined display phrase *“Let's build something useful ·”*, stroked in the*accent at 35% opacity over a transparent fill — copied 1:1 from mhabir.dev's*footer marquee, including its 20s loop and 72px display size. Like the*expertise marquee, the loop is CSS-only: the track holds the phrase six*times, so a -50% translate lands exactly one phrase-set later and never*shows a seam. Loop and stroke live in `resources/css/about-effects.css`.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `text` | `string` | `"Let's build something useful"` | The repeated phrase |
| `repeat` | `int` | `6` | Copies in the track; keeps −50% loops seamless on ultrawide screens |

Accessibility contract: the whole band is `aria-hidden` and `select-none` —*pure decoration, no announcement, no tab stops — and the animation never*runs under reduced motion.

```blade
<x-app.ui.cta-marquee />
```

---

### `<x-app.ui.stat-band>`

Four centered figures over hairline separators — two columns on phones, fourfrom md up, with the reference band's exact rule topology (third cell swapsits left rule for a top rule when the grid folds). Values render final from*the server; when JS is on, `app.js` counts each numeric figure up once onfirst sight (`data-countup` family of attributes). Single-digit values displayzero-padded (`9` → `09`).

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `stats` | `array<int, array{label: string, value: string}>` | — | Fed from `config('portfolio.author.stats')` |

The component expects exactly the four configured figures — the grid isfixed at four columns, matching `md:grid-cols-4`, not derived from data.

```blade
<x-app.ui.stat-band :stats="$this->profile()->stats" />
```

---

### `<x-app.ui.empty-state>`

The one sanctioned way to render "there is nothing here". Central to the
project's stance that an empty state is a design opportunity, not an accident.

| Prop | Type | Default |
| --- | --- | --- |
| `title` | `string` | `'Nothing here yet'` |
| `description` | `?string` | `null` |
| `icon` | `string` | `'magnifying-glass'` |

The default `icon` must be a real Flux icon name. Several invalid names were
found in this codebase — see [§11](#11-known-issues).

---

### `<x-app.ui.tag-pill>`

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `tag` | `Tag` | — | Model instance |
| `active` | `bool` | `false` | Selected state for filter chips |
| `count` | `?int` | `null` | Renders a trailing count when present |

---

### `<x-app.ui.pagination>`

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `paginator` | `LengthAwarePaginator` | — | |
| `label` | `?string` | `null` | Accessible name for the nav landmark |

---

### `<x-app.ui.app-logo>` / `<x-app.ui.app-logo-icon>`

| Component | Prop | Type | Default |
| --- | --- | --- | --- |
| `<x-app.ui.app-logo>` | `sidebar` | `bool` | `false` |
| `<x-app.ui.app-logo-icon>` | — | — | no props |

`sidebar` switches between `flux:brand` and `flux:sidebar.brand`. The icon is a
bare inline SVG (`viewBox="0 0 40 42"`, 5 `currentColor` paths) so it inherits
text colour and needs no asset request.

The wrapper `<x-app.ui.app-logo>` is currently **unused** — the auth layout
composes `<flux:brand>` itself and renders the icon directly. See
[§11](#11-known-issues).

---

### `<x-app.ui.placeholder-pattern>`

An 8px diagonal lattice as an SVG `<pattern>`. Used as a texture wash behind the
auth layout's editorial panel; the public pages get their grid from the
`grid-field` utility in `app.css` instead.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `id` | `string` | `uniqid()` | **Two instances on one page need distinct ids** |

That default is a genuine footgun: SVG `<pattern id>` is document-global, so two
default instances collide and the second renders blank. Pass an explicit `id`
when using more than one. Today there is exactly one call site, so the footgun
is theoretical rather than live.

---

### `<x-app.ui.modal>` / `<x-app.ui.modal.actions>` / `<x-app.ui.modal.trigger>`

The shared modal system. Full rationale in
[`ARCHITECTURE.md`](ARCHITECTURE.md#the-modal-system).

**`<x-app.ui.modal>`**

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
<x-app.ui.modal
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

**`<x-app.ui.modal.actions>`**

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
<x-app.ui.modal.actions>
    <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
    <flux:button variant="danger" type="submit">Delete</flux:button>
</x-app.ui.modal.actions>
```

**`<x-app.ui.modal.trigger>`** — `name` only. A pass-through to
`<flux:modal.trigger>` that exists so a modal's name is written in exactly one
place. A renamed modal opened by a stale trigger still renders, still looks
right, and does nothing when clicked.

---

## 2. Authentication components

### `<x-app.ui.auth.header>`

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

### `<x-app.ui.auth.session-status>`

| Prop | Type | Default |
| --- | --- | --- |
| `status` | `?string` | — |

Wraps a `flux:callout` in a `role="status"` live region. Renders nothing when
`status` is null. The live region announces without stealing focus — the visitor
is usually mid-task when it appears, and this replaced bare green text that
carried no semantic weight.

---

### `<x-app.ui.auth.passkey-verify>`

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

### `<x-app.ui.auth.passkey-registration>`

No props. Registration counterpart, used in the security settings page.

---

## 3. Dashboard primitives

Everything in this section lives in `components/app/ui/` and is shared by all
three role layouts. None of them branches on role — role enters only through the
chrome in [§4](#4-the-dashboard-shell-and-role-chrome).

### `<x-app.ui.header>`

| Prop | Type | Default |
| --- | --- | --- |
| `title` | `string` | — |
| `description` | `?string` | `null` |
| `eyebrow` | `?string` | `null` |
| `breadcrumbs` | `array<int, array{label: string, href?: string}>` | `[]` |

Page header for dashboard screens. `eyebrow` takes the permission-group label so
a page states which area of the product it belongs to.

When `breadcrumbs` is present it renders a `<nav aria-label="Breadcrumb">` with an
`<ol>`: a leading **Home** link to `route(auth()->user()->dashboardRoute())` so
the trail starts at the role's own home rather than a fixed path, then each crumb,
then the page title as plain text carrying `aria-current="page"`. The final crumb
is never a link — a breadcrumb that links to where you already are is noise.

The header body is wrapped in `mx-auto w-full max-w-7xl px-5 sm:px-6 lg:px-10` so
it lines up with `<x-app.ui.body>` underneath it. Change one and you must
change the other.

```blade
<x-app.ui.header
    :title="__('Posts')"
    :description="__('Drafts, published and archived.')"
    :breadcrumbs="[['label' => __('Content')], ['label' => __('Posts')]]"
/>
```

---

### `<x-app.ui.body>`

The page-body container. Every dashboard page wraps its content in exactly one.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `max` | `'full'\|'wide'\|'prose'` | `'wide'` | `wide` → `max-w-7xl`, `prose` → `max-w-3xl`, `full` → `max-w-none` |

Renders `mx-auto w-full {width} space-y-8 px-5 py-8 sm:px-6 lg:px-10`. Because the
spacing lives here, sibling `<x-app.ui.section>` blocks are spaced without any
`space-y-*` on each call site — which is what made the old pages drift.

---

### `<x-app.ui.section>`

A titled region within a page — the unit of visual grouping below the page header.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `title` | `?string` | `null` | Header row is omitted entirely when null |
| `description` | `?string` | `null` | |
| `icon` | `?string` | `null` | Must be a real Flux icon |
| `tone` | `'default'\|'attention'` | `'default'` | `attention` is a soft amber wash |
| `padded` | `bool` | `true` | `false` when the body is a table that owns its own padding |

Depth comes from the `sui-card` utility (soft shadow plus a hairline border).
`tone="attention"` is reserved for a region that genuinely needs action — a
surface that shouts everywhere shouts nowhere.

```blade
<x-app.ui.section :title="__('Recent posts')" icon="document-text">
    {{-- … --}}
</x-app.ui.section>

<x-app.ui.section :padded="false">
    {{-- a table with its own cell padding --}}
</x-app.ui.section>
```

---

### `<x-app.ui.nav>`

| Prop | Type | Default |
| --- | --- | --- |
| `label` | `?string` | `__('Dashboard')` |

Renders the sidebar by delegating to
`App\Support\DashboardNavigation::for(auth()->user())`.

This component contains **no permission logic of its own**, and that is its
entire value. The nav items and the route guards read the same definitions, so
a menu entry cannot be visible to an account whose route would refuse it.

The navigation data is three levels deep — **section → group → item** — and the
view renders two:

* a **section** is a labelled band (`Home`, `Writing`, `Workspaces`, `Manage`).
  It is a non-interactive heading and is dropped entirely, heading included, if
  nothing under it survives permission filtering;
* a **group** is a labelled cluster with an icon in the *definition*. It draws
  no heading and no disclosure control of its own: every item renders as a
  direct neighbour under its section's heading, because a chevron that hides a
  link behind a click costs a navigation step to reach the thing the sidebar
  exists to surface;
* an **item** is a leaf link.

A group that filtered down to a single destination is marked `static`, and
`active` records whether a group contains the current route — both are metadata
on the tree that `tests/Feature/Dashboard/NavigationTest.php` pins down, not
behaviour the view branches on.

`$label` is the nav's accessible name. Each role's sidebar passes its own, so an
account moving between the author workspace, the storefront and platform
administration hears a landmark that says where it is rather than the same
generic "Dashboard" three times over.

Active *item* state resolves against each item's `pattern` via
`request()->routeIs()`, so the highlight cannot drift from the route table, and
there is no per-group collapse state left to persist — `df.sidenav-collapsed`
(the rail width) is the sidebar's only stored preference.

The `$badge` resolved per item is always an `int` — never a sentinel string.
An earlier version used `'pending-comments'` as a placeholder and leaked it into
the markup; `DashboardNavigation::for()` now resolves it to a count, which also
satisfied PHPStan's return-type check.

---

### `<x-app.ui.nav-icons>`

| Prop | Type | Default |
| --- | --- | --- |
| `label` | `?string` | `__('Dashboard (compact)')` |

No props of its own beyond the accessible name. The icon-only variant of the
sidebar, rendered when the desktop sidenav is collapsed.

It is built from **the same `DashboardNavigation::for()` data**, flattened — not
from a second hand-maintained list. A parallel menu is exactly the drift the
navigation layer exists to prevent. Sections and group headers are dropped
because neither survives being reduced to an icon. Because the label text is
absent, every link carries both a `title` and an `aria-label`.

---

### `<x-app.ui.utility-link>`

| Prop | Type | Default |
| --- | --- | --- |
| `href` | `string` | — |
| `icon` | `string` | — |
| `label` | `string` | — |

A link in the sidebar's pinned footer ("View site", "Settings"). The label is
hidden with `x-show="! collapsed"` when the sidenav collapses; an `sr-only` copy of the
label remains so the link is never nameless to a screen reader.

---

### `<x-app.ui.role-badge>`

| Prop | Type | Default |
| --- | --- | --- |
| `user` | `?User` | `null` → `auth()->user()` |
| `size` | `'sm'\|'md'` | `'sm'` |

Reads `accountRole()` and `plan()` off the account, so the badge cannot disagree
with the permission set the page was rendered under. Used in the top bar's
profile menu and in the seller dashboard's plan panel.

---

### `<x-app.ui.stat>`

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

### `<x-app.ui.module-placeholder>`

The shared shell for a product module that has a route, a permission and a
sidebar entry but no feature yet. Every unbuilt module renders through this
component, so the four placeholder pages cannot drift into four different
designs. It wraps `<x-app.ui.header>` — with breadcrumbs
`Workspaces / {title}` — inside a `<x-app.ui.body max="prose">`, and states
three things honestly: what the module is, what is already built underneath it,
and that the module itself is not available yet.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `title` | `string` | — | Also rendered as the section heading |
| `description` | `string` | — | One-line summary of the module |
| `icon` | `string` | `'sparkles'` | Must be a real Flux icon |
| `status` | `string` | `'planned'` | Declared for future use; the badge is currently a fixed "Not yet available" |
| `features` | `array` | `[]` | Planned features, each rendered as a checked list item |
| `spec` | `?string` | `null` | Path to the module's specification document |

```blade
<x-app.ui.module-placeholder
    :title="__('Developer Lab')"
    :description="__('A browser-based environment to write, run, inspect and debug code.')"
    icon="beaker"
    :spec="'docs/lab.md'"
    :features="[__('Write and run code in the browser'), __('Work through guided challenges')]"
/>
```

Used by `pages::dev-lab.index`, `pages::ai-hub.index`,
`pages::marketplace.index` and `pages::ideas.index`.

---

### The settings shell

`resources/views/pages/settings/layout.blade.php` is the settings sibling of the
main navigation: a labelled list of destinations beside the active screen.
Above `md` the list is a `md:w-56` column; below it, it becomes a horizontally
scrollable tab strip, because a two-column layout with one item per row spends
the whole first screen on navigation.

| Prop | Type | Default |
| --- | --- | --- |
| `heading` | `?string` | `null` |
| `subheading` | `?string` | `null` |

The shell renders `heading` as the page `<h1>` and `subheading` as its
supporting line, then constrains the slot to `max-w-2xl`. **Pages must not
render their own page-level heading** — that duplication is why
`partials/settings-heading.blade.php` was deleted. They may (and should) render
*section* headings below it, e.g. Security's "Update password" block above the
form.

Destinations come from `App\Support\SettingsNavigation::items()` — the same
declaration shape as `DashboardNavigation`, minus permission filtering, because
every account manages its own profile, security and appearance. There is no
`for(User)` parameter; a test asserts the method takes none, since adding one
would imply role-dependent settings.

| Key | Route | Pattern |
| --- | --- | --- |
| Profile | `profile.edit` | `profile.*` |
| Security | `security.edit` | `security.*` |
| Appearance | `appearance.edit` | `appearance.*` |

The settings pages carry **no** `#[Layout]` attribute, so they render in the
guest shell — see [§9](#9-layouts).

---

## 4. The dashboard shell and role chrome

This is the part of the tree the role directories exist for. The rule:

> **`ui/` implements the workspace; the role directories name it.**

Every authenticated surface is the same workspace — a collapsible sidenav, a mobile
drawer, a sticky bar, a `<main>`. Three roles' worth of that machinery is one
implementation in `components/app/ui/dashboard-shell.blade.php`, and each role's
directory holds three one-line components that fill the shell's regions and
supply the accessible names that tell an account where it is.

### `<x-app.ui.dashboard-shell>`

No props. Every region is a slot.

| Slot | Region | Filled by |
| --- | --- | --- |
| `brand` | the wordmark, in the sidenav and again in the drawer | *(default: role-aware home link)* |
| `sidebar` | the sidenav's scrolling navigation | `<x-app.{role}.sidebar />` |
| `sidebarIcons` | the sidenav's collapsed, icon-only navigation | `<x-app.{role}.sidebar variant="icons" />` |
| `footer` | the sidenav's pinned account block | `<x-app.{role}.footer />` |
| `header` | the sticky bar shown below `lg` | `<x-app.{role}.header />` |
| default slot | `<main id="dashboard-main">` — the page itself | the Livewire page |

The shell owns the skip link, the `data-sui` scope's host markup, two pieces of
Alpine layout state, the `x-teleport` that keeps the drawer out of a clipped
ancestor, the focus trap that makes it modal in fact as well as in
`aria-modal`, and the escape handler that closes it.

**Why a component and not a layout.** There are three role layouts
(`layouts::app.dashboard`, `layouts::seller.dashboard`,
`layouts::admin.dashboard`) and they share this shell rather than each carrying
a copy. The sidenav and the drawer are ~270 lines of interdependent machinery;
duplicating that three times would mean three places to fix the same bug, and
the three would drift. The layouts *compose*; only this file *implements*.

**Two regions reuse the same slot content on purpose.** The brand appears in
both the sidenav and the drawer, and the navigation appears in the sidenav, the
sidenav's collapsed form, and the drawer: rendering `{{ $brand }}` twice costs
nothing because a slot is already a rendered string
(`ComponentSlot::toHtml()` returns it), and it guarantees the drawer cannot
disagree with the sidenav about where "home" is.

**State.** Two independent pieces, both namespaced by user id so a shared
machine does not leak one account's layout into another's:

| State | Persisted to | Behaviour |
| --- | --- | --- |
| `sidenav` | `df.sidenav-collapsed.{userId}` in `localStorage` | Desktop sidebar collapses to a 68 px icon-only strip and back to 264 px |
| `drawer` | not persisted (transient) | Below `lg` the sidebar becomes an off-canvas drawer |

The collapse toggle is the icon button in the sidenav's **brand row** —
top-right of the wordmark when the rail is expanded, centred alone when it is
narrow (the brand link yields the row at 76 px). It carries
`data-test="sidenav-toggle"`; the mobile header trigger carries
`data-test="drawer-open"`, so the responsive behaviour is testable without
asserting on CSS.

The mobile drawer is `x-teleport`ed to `<body>` and declared
`role="dialog" aria-modal="true"`. It implements, by hand, the three things a
modal must do:

1. **scroll lock** — `document.documentElement.classList.add('overflow-hidden')`;
2. **focus trap** — an `@keydown.tab` handler cycling between the first and last
   *visible* focusable descendants (`el.offsetParent !== null` filters out
   anything hidden from layout);
3. **focus restore** — the element that had focus before opening is refocused on
   close.

> **Why hand-rolled.** `x-collapse` ships with Flux, but `x-trap` **does not** —
> grep `flux.min.js` for `trap` and you get zero hits. Writing `x-trap` would
> fail *silently*: the dialog would look modal while Tab walked straight out into
> the page behind it. `tests/Feature/Dashboard/ShellTest.php` asserts that this
> is the only view file in the application containing a focus trap.

---

### `<x-app.ui.sidenav-footer>`

| Prop | Type | Default |
| --- | --- | --- |
| `label` | `?string` | `__('Account')` |
| `links` | *(slot)* | — |

The sidenav's pinned footer: who you are, and how to leave.

Two blocks, top to bottom, each one the smallest thing that answers its
question: the account identity (name, email); and the utility links — view site,
settings, the optional `links` slot, log out.

> **Moved:** the collapse toggle used to be a third block here. It now lives in
> the shell's brand row (`<x-app.ui.dashboard-shell>`), because it is shell
> machinery rather than account chrome and must stay reachable at every rail
> width — including the icon-only one this footer sits under.

> **Removed:** a fourth block, the plan card (role + plan badge and tagline, as
> a link home), used to sit between the identity and the utility links. It is
> gone from every workspace rail: the wordmark already links home, the profile
> menu in the top bar already shows the role and plan, and the seller's own
> dashboard is where the plan belongs. `tests/Feature/Dashboard/ShellTest.php`
> asserts the tagline no longer appears inside the `<aside>`.

It is **pinned rather than scrolling** with the nav so the log-out control can
never be scrolled out of reach, which matters most on exactly the small screens
where the sidenav is at its narrowest.

`$label` names the account block for assistive technology. Each role's footer
passes its own so the region is not announced as a bare "Account" three
different ways in three different parts of the product.

The `links` slot is each role's seam: a role's own utility links, rendered
between the shared ones and the logout control. Empty today for every role,
which is the point — the shape exists before the need does, and adding the
seller's payouts link later is one line in
`components/app/seller/footer.blade.php` rather than a fork of this file.

---

### `<x-app.ui.topbar>`

| Prop | Type | Default |
| --- | --- | --- |
| `label` | `?string` | `__('Dashboard home')` |

The bar that replaces the sidenav below `lg`. Three things, in the order a thumb
reaches them: the button that opens the drawer, the wordmark that goes home,
and the role badge that says which workspace you are standing in. Nothing else
— at this width the bar is 56px tall and every additional control is a control
you miss.

The brand link carries `aria-label` because its visible text is the author's
short name, which says who owns the site and not where the link goes. `$label`
supplies the destination — "Selling dashboard", "Admin dashboard" — drawn from
the role's own route by the role's header component.

---

### The role chrome: `user`, `seller`, `admin`

Each role directory holds exactly three components, and they are deliberately
thin.

| Component | Props | Body |
| --- | --- | --- |
| `<x-app.user.sidebar>` | `variant: 'default'\|'icons'` | `<x-app.ui.nav :label="__('Author workspace')" />`, or `<x-app.ui.nav-icons>` when `variant="icons"` |
| `<x-app.user.footer>` | `label: ?string` | `<x-app.ui.sidenav-footer :label="__('Author account')" />` |
| `<x-app.user.header>` | `label: ?string` | `<x-app.ui.topbar :label="__('Author dashboard')" />` |
| `<x-app.seller.sidebar>` | `variant` | same, labelled `__('Selling workspace')` |
| `<x-app.seller.footer>` | `label` | `__('Seller account')` |
| `<x-app.seller.header>` | `label` | `__('Selling dashboard')` |
| `<x-app.admin.sidebar>` | `variant` | same, labelled `__('Administration')` |
| `<x-app.admin.footer>` | `label` | `__('Administrator account')` |
| `<x-app.admin.header>` | `label` | `__('Admin dashboard')` |

**The one genuine per-role difference is the nav's accessible name.** The items
are not any role component's business: `DashboardNavigation` already filters
them by permission, and an admin holds every one of them, so the Administration
group appears in the admin sidebar and nowhere else *without the admin sidebar
knowing any other role exists*. That is the whole value of the split — role
differentiation comes from composing permission-filtered data under a
role-specific name, never from duplicated navigation logic.

Each role file carries a docblock naming what that role is most likely to need
that is not a navigation item: the seller's payouts and listings, the admin's
health indicator, failed-job count and deploy stamp, the author's drafts queue.
Those belong in the role's directory when they arrive — which is why the
directories exist before their contents do.

---

### `<x-app.ui.desktop-user-menu>`

| Prop | Type | Default |
| --- | --- | --- |
| `showRole` | `bool` | `true` |

**Currently unused.** The account block it used to render is now
`<x-app.ui.sidenav-footer>`, which replaces it outright: same dropdown-style
identity, plus the sidenav toggle and the utility links the old shell rendered
separately. Its `data-test="sidebar-menu-button"` hook died with it — nothing
ever asserted on it. The file is retained only as the historical record of the
pre-sidenav chrome, and it is the first file to delete — see
[§11](#11-known-issues).

---

## 5. Post components

### `<x-app.ui.post.card>`

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

### `<x-app.ui.post.toc>`

| Prop | Type | Default |
| --- | --- | --- |
| `items` | `array` | `[]` |
| `title` | `string` | `'On this page'` |
| `id` | `string` | `'toc'` |

Table of contents from extracted headings. Renders nothing when `items` is
empty — an "On this page" heading above nothing is worse than no heading.

---

### `<x-app.ui.post.reading-progress>`

| Prop | Type | Default |
| --- | --- | --- |
| `target` | `string` | `'[data-reading-target]'` |

A thin scroll-progress bar. The selector is a prop so the bar can be pointed at
a specific element rather than always the document.

---

### `<x-app.ui.post.share-bar>`

| Prop | Type | Notes |
| --- | --- | --- |
| `post` | `Post` | Needs an absolute canonical URL |

Renders share targets from `config/portfolio.php`. See
[`<x-app.icon.share>`](#x-appiconshare).

---

### `<x-app.ui.comment>`

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `comment` | `Comment` | — | |
| `isReply` | `bool` | `false` | Indents and adjusts the heading level |

---

## 6. Editor components

### `<x-app.ui.editor.rich-text>`

The Markdown editor with inline AI assistance.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `model` | `string` | `'body'` | The Livewire property the editor syncs to |
| `value` | `string` | `''` | Initial Markdown |
| `label` | `?string` | `null` | |
| `placeholder` | `?string` | `null` | |
| `minHeight` | `string` | `'28rem'` | |
| `imagesEnabled` | `?bool` | `null` | `null` falls back to `ai.editor.images_enabled` |

**Why Markdown and not a contenteditable surface.** The post body is stored as
Markdown and rendered through `App\Support\MarkdownRenderer`. A WYSIWYG surface
would have to emit HTML, which means a sanitiser, a new column, and a rewrite of
the public render path — for a feature the data model does not ask for. Editing
Markdown directly also keeps the preview honest: the author sees exactly what the
renderer will produce, not an approximation that drifts from it.

**Why Alpine rather than Livewire state.** Every AI action must leave the cursor
where it was. A Livewire round trip re-renders the DOM, which loses the
textarea's selection and scroll position. The component owns its state
client-side and talks to the server over plain JSON (`resources/js/editor-ai.js`),
syncing the finished value back up with `$wire.set()` only when an action
completes. `apply({ text, start, end })` is the single mutation point, so
selection restoration and syncing exist once rather than at every call site.

**Batching corrections.** `acceptAllMechanical()` applies corrections in
**descending offset order**. Applying from the start would shift every later
offset and invalidate the rest of the batch.

**One gotcha.** Do not pass a dynamic name to `<flux:icon>` — a
`<flux:icon ::name="…">` binding makes Blaze re-enter the compiled icon template
forever and exhausts memory. The category grid is rendered server-side from
`ContentCategory` with a static icon per case for exactly this reason.

The three enums behind it — `ContentCategory`, `ContentTone`, `CorrectionType` —
each carry `label()`, `icon()`, `toArray()` and their own prompt fragments, so
adding a category is a one-file change with no view edit.

---

## 7. Icon components

Components whose entire body is a glyph. They exist so a mark is drawn from one
definition: before `<x-app.icon.logo-mark>` was extracted, the `>_` wordmark
appeared in four files under three different corner radii.

### `<x-app.icon.logo-mark>`

| Prop | Type | Default |
| --- | --- | --- |
| `size` | `'sm'\|'md'\|'lg'` | `'md'` |

The `>_` terminal glyph in a rounded box. The component owns the glyph and the
box only — **the palette arrives through `$attributes`**, because the same mark
is an accent-filled chip on the sidenav and in the topbar, and a bordered
monogram on the public About page:

```blade
<x-app.icon.logo-mark size="md" class="bg-sui-accent text-sui-ink-on-accent" />          {{-- sidenav + drawer --}}
<x-app.icon.logo-mark size="sm" class="bg-sui-accent text-sui-ink-on-accent" />  {{-- mobile topbar --}}
<x-app.icon.logo-mark size="lg" class="border … bg-ink-900 font-mono text-signal-400 …" />
```

`tests/Feature/Dashboard/ShellTest.php` asserts the glyph is defined in exactly
one file and rendered exactly three times in a dashboard page — once per
surface, never duplicated as markup.

### `<x-app.icon.share>`

| Prop | Type | Notes |
| --- | --- | --- |
| `name` | `string` | A key from the share-target config |

Brand marks are inlined as SVG paths because the free Flux tier has no brand
icons. Sized to a 3.5 `size-*` utility (14px) and drawn with `currentColor` so
they inherit the button's hover state.

---

## 8. Site chrome

### `<x-app.home.header>` / `<x-app.home.footer>`

No props. Used by `layouts::guest.home` only. Dashboard screens use the shell in
[§4](#4-the-dashboard-shell-and-role-chrome) and never render these.

Both were brought onto the shared focus treatment: every interactive element
carries `focus-ring`, which draws a 2 px signal-coloured ring with a 2 px offset
via `box-shadow` rather than `outline`, so it can follow a non-square
`border-radius` cleanly. The offset colour flips to `ink-950` in dark mode so the
ring reads as a ring and not a smear.

The header nav marks the current section with both `aria-current="page"` and a
signal-coloured underline, so the state is not carried by colour alone. Below
`md` the nav collapses into a disclosure panel; its `x-transition` classes are
neutralised by the `prefers-reduced-motion` block in `app.css`, because a blanket
`transition-duration` clamp does not stop Alpine from applying its classes.

The footer's external links (GitHub, LinkedIn, X) carry
`rel="noopener noreferrer me"` — the `me` value is what makes IndieWeb-style
identity consumers treat them as belonging to the same person — plus an
`sr-only` " (opens in a new tab)" announcement, since `target="_blank"` is
invisible to a screen reader.

---

## 9. Layouts

Five layouts, one per audience. The audience is always the first path segment,
so a layout's name states who it is for before what it contains.

| Layout | Audience | Used by |
| --- | --- | --- |
| `layouts::guest.home` | unauthenticated visitors | Home, blog index, post detail, about — **and the default** |
| `layouts::auth` / `layouts::auth.split` | every authenticated account, mid-flow | All seven Fortify screens |
| `layouts::app.dashboard` | authenticated accounts generally | `/dashboard` and the four platform modules |
| `layouts::seller.dashboard` | the seller role | `/seller/dashboard` |
| `layouts::admin.dashboard` | the admin role | `/admin/dashboard`, `/admin/users` |

`layouts::auth` is the entry point and `layouts::auth.split` is the
implementation behind it; the pair exists so the seven Fortify screens all
reference one name and switching the authentication design is a one-line change
to `auth.blade.php` — a second design would be added beside `split` and selected
there, and no caller would change. `layouts::auth.split` is a public surface
with no `data-sui` scope — it is written in the editorial `ink-*`/`signal-*`
palette so the login screen looks like the site it guards.

### The three workspace layouts are one composition

`layouts::app.dashboard`, `layouts::seller.dashboard` and
`layouts::admin.dashboard` are **byte-identical in shape**. Each one owns the
document, the `data-sui` scope, the persisted toast group and `@fluxScripts` —
the parts that are about being a page — and fills the shell's regions from its
own role chrome:

```blade
<body class="min-h-screen antialiased" data-sui>
    <x-app.ui.dashboard-shell>
        <x-slot:sidebar><x-app.user.sidebar /></x-slot:sidebar>
        <x-slot:sidebarIcons><x-app.user.sidebar variant="icons" /></x-slot:sidebarIcons>
        <x-slot:header><x-app.user.header /></x-slot:header>
        <x-slot:footer><x-app.user.footer /></x-slot:footer>

        {{ $slot }}
    </x-app.ui.dashboard-shell>

    @persist('toast')
        <flux:toast.group><flux:toast /></flux:toast.group>
    @endpersist

    @fluxScripts
</body>
```

`brand` is deliberately not passed by any of the three: the shell's default
wordmark points at `auth()->user()->dashboardRoute()`, which is already
role-aware, so every role gets the correct home link without restating it.

`data-sui` sits on `<body>` rather than an inner wrapper because the mobile
drawer and the toast group are teleported straight onto the body — anything
outside the scope would silently fall back to the public palette. That is the
mechanism documented in [`SOFT-UI.md`](SOFT-UI.md#12-the-mechanism).

### How a page gets its layout

`config/livewire.php` sets `component_layout => 'layouts::guest.home'`. That
means **every** SFC rendered through `Route::livewire()` is wrapped in the public
shell by default — including dashboard pages, unless they say otherwise.

Workspace pages therefore opt in explicitly:

```blade
new #[Layout('layouts::app.dashboard')] class extends Component { … }
```

| Page | Layout |
| --- | --- |
| `pages::dashboard.index`, `dashboard.comments`, `dashboard.taxonomy`, `dashboard.posts.*` | `layouts::app.dashboard` |
| `pages::dev-lab.index`, `ai-hub.index`, `marketplace.index`, `ideas.index` | `layouts::app.dashboard` |
| `pages::seller.dashboard` | `layouts::seller.dashboard` |
| `pages::admin.dashboard`, `pages::admin.users` | `layouts::admin.dashboard` |
| `pages::settings.*` | *(none — the guest shell, deliberately)* |

That last row is a wrinkle worth knowing about. The settings pages carry no
`#[Layout]` attribute and therefore render in the guest shell: settings is the
one authenticated surface that does not want the workspace chrome around it, so
it keeps the quiet public frame. It does mean those pages are written in
`ink-*`/`signal-*` utilities rather than `sui-*` — a `tests/Feature/Dashboard/ShellTest.php`
assertion pins the behaviour so it cannot regress silently.

`component_namespaces` (which gives `pages::` and `layouts::`) and
`component_locations` (which lists `resources/views/components` and
`resource_path('views/livewire')`) are both declared there too.
`component_locations` is what lets a plain component resolve by short name, and
an SFC placed under `views/livewire` is `<livewire:name />`, neither needing a
namespace prefix. Only the `pages::` and `layouts::` namespaces need one. So
`config/livewire.php` is the file to read first when a component cannot be
found.

---

## 10. Livewire single-file components

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
| `pages::seller.dashboard` | `pages/seller/⚡dashboard.blade.php` | `/seller/dashboard` |
| `pages::admin.dashboard` | `pages/admin/⚡dashboard.blade.php` | `/admin/dashboard` |
| `pages::admin.users` | `pages/admin/⚡users.blade.php` | `/admin/users` |
| `pages::dev-lab.index` | `pages/dev-lab/⚡index.blade.php` | `/dev-lab` |
| `pages::ai-hub.index` | `pages/ai-hub/⚡index.blade.php` | `/ai-hub` |
| `pages::marketplace.index` | `pages/marketplace/⚡index.blade.php` | `/marketplace` |
| `pages::ideas.index` | `pages/ideas/⚡index.blade.php` | `/ideas` |
| `pages::settings.profile` | `pages/settings/⚡profile.blade.php` | `/settings/profile` |
| `pages::settings.security` | `pages/settings/⚡security.blade.php` | `/settings/security` |
| `pages::settings.appearance` | `pages/settings/⚡appearance.blade.php` | `/settings/appearance` |
| `pages::settings.delete-user-modal` | `pages/settings/⚡delete-user-modal.blade.php` | (component) |
| `pages::settings.two-factor-setup-modal` | `pages/settings/⚡two-factor-setup-modal.blade.php` | (component) |
| `pages::settings.two-factor.recovery-codes` | `pages/settings/two-factor/⚡recovery-codes.blade.php` | (component) |

`pages/settings/layout.blade.php` is a **plain Blade component**, not an SFC —
the settings shell, invoked as `<x-pages::settings.layout>`.

Modal SFCs receive their target through props rather than reading the route,
which is what allows one modal component to serve every row in a list:

```blade
<livewire:pages::settings.delete-user-modal
    :user-id="$user->id"
    :modal-name="'delete-user-'.$user->id"
    :key="'delete-user-modal-'.$user->id"
/>
```

The `:key` is required. Without it Livewire reuses one component instance across
the loop and every row's modal operates on the same record.

---

## 11. Known issues

Concrete defects and hazards found while writing this reference. Recorded so they
are tracked rather than rediscovered.

| # | Issue | Impact |
| --- | --- | --- |
| 1 | `<x-app.ui.placeholder-pattern>` defaults `id` to `uniqid()` | Two instances on a page collide; the second renders blank. Pass an explicit `id`. Currently one call site, so the footgun is theoretical. |
| 2 | `<x-app.ui.desktop-user-menu>` and `<x-app.ui.app-logo>` are unused | Dead components left by the reorganisation. `sidenav-footer` superseded the first; the auth layout composes `flux:brand` itself and only needs the icon. Delete both when convenient — nothing references them, including tests. |
| 3 | `<x-app.ui.desktop-user-menu>` uses `zinc-*` tokens | Off-palette against the `ink-*` system. It predates the token migration and, being unused, renders nowhere — so the fix and the deletion are the same change. |
| 4 | Invalid Flux icon names recurred four times | `storefront`, `book-open-text`, `folder-git-2`, `layout-grid` all threw. All fixed; a repo-wide audit is now part of the verification gate. |
| 5 | A dynamic `<flux:icon ::name="…">` recurses forever under Blaze | Memory exhaustion, not a blank icon. Never bind the icon name to an Alpine expression — see §6. |

On issue 4: the audit that found them is

```bash
ls vendor/livewire/flux/stubs/resources/views/flux/icon/ | sed 's/\.blade\.php$//' | sort > /tmp/icons.txt
# then every icon="..." in resources/views is checked against that list
```

It is not wired into a test, because the icon set is a vendor artefact and a
failing test on a Flux upgrade would be noise. It is a pre-release checklist
item instead.
