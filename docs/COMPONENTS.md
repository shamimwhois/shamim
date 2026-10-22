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

Almost every component lives under `components/app/`. That prefix is not
decoration: it namespaces this application's components away from every
package's anonymous components, so `<x-app.ui.modal>` can never be shadowed by
a vendor package registering `<x-modal>` — which is exactly the collision the
old flat `components/modal.blade.php` was exposed to.

Eleven files sit outside it, and they are the honest exception rather than an
oversight:

| Directory | Tag | Count | Why it is not under `app/` |
| --- | --- | --- | --- |
| `components/auth/` | `<x-auth.login-modal>` and siblings | 4 | The three Fortify mid-flow modals plus the `modals` bundle that mounts them. They are the authentication surface, which predates the `app/` split and is namespaced by *concern*, not by role. |
| `components/feed/` | `<x-feed.card>` and siblings | 6 | The activity feed's own vocabulary. `<x-feed.avatar>` has eleven call sites across the public and authenticated surfaces, so it is shared, but it is feed-shaped, not primitive-shaped. |
| `components/communities/` | `<x-communities.card>` | 1 | One community directory card. |

The rule these three obey is the same rule `ideas/` and `dev-lab/` obey — **a
module owns its vocabulary** — and they simply got there before the `app/`
prefix was applied consistently. Bringing them under `app/` would mean rewriting
every tag in the table above for no change in behaviour, so the naming is
documented rather than fixed. Anything new goes under `app/`.

```
components/
├── auth/          the Fortify mid-flow modals and their bundle
├── feed/          the activity feed's card, composer, filters, inspector, rail, avatar
├── communities/   the community directory card
└── app/
    ├── home/          guest chrome — the public header and footer
    ├── ui/            shared primitives, role-agnostic by definition (41 files)
    │   ├── auth/      authentication screens
    │   ├── editor/    the Markdown editor and its AI affordances
    │   ├── modal/     the shared modal system
    │   └── post/      post cards, TOC, share bar
    ├── icon/          icon components — brand marks and the wordmark glyph
    ├── ideas/         the Ideas module's own components
    ├── dev-lab/       the Developer Lab's landing panels, its public snippet row, and its code pane
    ├── ai-hub/        the hub's article and topic cards
    ├── marketplace/   product cards and catalogue blocks
    ├── user/          the reader workspace's chrome
    ├── seller/        the selling workspace's chrome
    ├── admin/         the administration workspace's chrome
    └── errors/        the status pages
```

The rule that keeps it clean: **a component goes in `ui/` unless a role or a
module owns it.** `ui/` is the only directory that may not branch on role — the
moment a component needs to know whether it is rendering for a seller, it belongs in
that role's directory. `icon/` holds components whose entire body is a glyph.

The four module directories exist because a module's vocabulary is not a primitive.
`<x-app.ideas.card>` knows what an `IdeaStatus` badge looks like and
`<x-app.dev-lab.code-pane>` knows about the byte ceiling; putting either in `ui/`
would offer the whole application a component that is only meaningful inside one URL
space. The test for the placement is one question: would a screen in a *different*
module reach for this? If not, it stays in its module.

The layouts mirror the same audience-first shape:

```
layouts/
├── guest/         unauthenticated visitors — home, blog, about
│   └── home       the default layout (config/livewire.php component_layout)
├── auth/          every authenticated account, on the way in
│   └── split      the implementation behind layouts::auth
├── app/           the general authenticated workspace
│   └── dashboard  /dashboard, the Ideas and Developer Lab workspaces, the hub shelves
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

### `<x-app.ui.app-logo-icon>`

| Component | Prop | Type | Default |
| --- | --- | --- | --- |
| `<x-app.ui.app-logo-icon>` | — | — | no props |

The icon is a bare inline SVG (`viewBox="0 0 40 42"`, 5 `currentColor` paths) so it
inherits text colour and needs no asset request. The auth layout composes
`<flux:brand>` itself and renders this icon directly.

The `<x-app.ui.app-logo>` wrapper that used to sit beside it — a `sidebar` prop
switching between `flux:brand` and `flux:sidebar.brand` — had no call sites left and
has been deleted.

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

### `<x-app.ui.theme-toggle>`

No props. The single control for the application's two themes, and it appears on
three surfaces — the workspace navbar, the public site header, and the auth
layout — which is the entire reason it is a component rather than markup copied
three times.

The button owns no state. The click flips `$flux.dark`, and Flux's appearance
store applies the `.dark` class on `<html>` and persists to
`localStorage['flux.appearance']`, the same key `partials/head.blade.php` reads
before first paint. Exactly one writer, so the toggle cannot desynchronise from
the boot script. `window.DevFolioTheme` (defined inline by `partials/theme-boot`)
does the two things that are not Flux's job: the short colour cross-fade and the
`theme-color` meta that tints the mobile browser chrome.

It always shows the theme you would switch *to* — a sun while dark is applied —
and its accessible name says the same, so the icon and the label cannot disagree.

---

### `<x-app.ui.accent-picker>`

| Prop | Type | Default |
| --- | --- | --- |
| `panelId` | `?string` | `'accent-picker' . uniqid()` |

The companion to the toggle: where that flips dark and light, this re-tunes the
site's single accent hue across six swatches (emerald is the default, then
indigo, cyan, amber, rose, violet). One attribute carries the choice,
`data-accent` on `<html>`, and the per-hue variable scopes live at the end of
`resources/css/app.css`. `window.DevFolioTheme.accent` persists it to
`localStorage['devfolio.accent']` and stamps the attribute before first paint, so
there is no flash of the default hue on the next load.

Emerald is the *absence* of the attribute rather than a stored value — the same
trick the dark default uses to avoid fighting the OS preference, applied to
colour.

`panelId` is not decoration. The public header renders this component twice, once
in the desktop nav and once in the mobile panel, and both copies emit an
`aria-controls` pointing at a panel `id`. Two identical DOM ids is the bug, so
each instance is given a distinct one, and the `uniqid()` default only holds when
there is exactly one on the page.

---

### `<x-app.ui.welcome-card>`

| Prop | Type | Default |
| --- | --- | --- |
| `eyebrow` | `?string` | `null` |
| `title` | `string` | — |
| `description` | `?string` | `null` |
| `icon` | `string` | `'sparkles'` |
| `level` | `int` | `2` |

The one surface in the system allowed to carry a gradient, which is why the
gradient palette exists at all: white on the hero's darkest stop measures 14.9:1,
so nothing here compromises, and the secondary text at 70% white still clears AA
on both stops.

`level` exists because a page has exactly one `h1`. The card opens the four
dashboards that use it — `pages::dashboard.index`, `ideas.dashboard`,
`dev-lab.dashboard`, `seller.dashboard` — so each passes `1`, and a caller
greeting inside a page that already has its own heading leaves the default `2`.

The `actions` slot is where the caller composes its own `<flux:button>`s; the card
states where they sit and how they align, not what they look like.

---

### `<x-app.ui.chart-card>`

| Prop | Type | Default |
| --- | --- | --- |
| `title` | `string` | — |
| `description` | `?string` | `null` |
| `series` | `array<int, int>` | `[]` |
| `labels` | `array` | `[]` |
| `empty` | `?string` | `null` |

An area chart drawn as inline SVG. The dashboard needs one chart, and a charting
dependency that renders axes, tooltips and eleven legends is the wrong trade for
one area-plus-sparkline, so there is no chart library in `package.json` and none
is planned. The SVG scales from its `viewBox`, so it is crisp at any width, and
every colour in it is a token.

The series arrives as plain integers from the page — this component does no
querying. The caller owns what "activity" means; the card owns what it looks like.

Segments are straight with round joins, deliberately: a Catmull-Rom smoothing
would overshoot and invent peaks the data does not contain. The scale is
zero-based with the baseline padded, so a week with nothing published still sits
on the axis instead of at the edge of the `viewBox`. `empty` is the sentence
rendered when the series is all zeros — there is no skeleton state invented for a
request that returned nothing.

---

### `<x-app.ui.social-icon>`

| Prop | Type | Default |
| --- | --- | --- |
| `network` | `?string` | `null` |

Brand glyphs for the icon-only social row on `/about`. `network` takes the
`AuthorProfile` link keys; an unknown key renders nothing, because the caller's
`@if` already guards and a question-mark box where a GitHub mark should be is
worse than a gap.

These are hand-drawn paths rather than Flux icons for one reason: a brand glyph
is not in an icon library's remit, and `lucide`/Heroicons names for them are
either absent or trademark-shaded. They are `aria-hidden` and always inside a
link with visible or `sr-only` text, which is the accessibility requirement the
component depends on rather than re-asserts.

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

### The guest auth dialogs: `components/auth/`

Four files that live outside `components/app/` — see [§0](#0-the-directory-map) —
and are the guest half of the sign-in journey.

| Component | Props | Renders |
| --- | --- | --- |
| `<x-auth.modals>` | — | The bundle. One `@guest` block mounting the three dialogs below. |
| `<x-auth.login-modal>` | — | The login form, in a `<x-app.ui.modal name="login">`. |
| `<x-auth.register-modal>` | — | The registration form. |
| `<x-auth.forgot-password-modal>` | — | The reset *request*, which emails the link. |

`<x-auth.modals>` is included exactly once, at
`resources/views/layouts/guest/home.blade.php:34`, so every public page carries
the dialogs without every page declaring them. It renders nothing for a signed-in
account, and each dialog *also* guards itself, which is deliberate: the bundle is
a convenience for the layout, not the access control.

**The division between dialog and page is by entry point, not by importance.**
Login, registration and the forgot-password request are guest clicks, so they are
modals. Password reset (it arrives on a token-bearing link from an email),
two-factor challenge, confirm-password and verify-email are reached mid-session or
from outside the site, so they remain standalone screens under `pages/auth/` in
`layouts::auth.split`. An email link needs a real URL; a guest click does not.

Each dialog reads its own open state from the request rather than from a trigger,
so a redirected or validation-failed flow reopens it: `?forgot=required`,
`old('auth_flow') === 'forgot'`, or a flashed `status` with no `signin`/`signup`
flag alongside it. Closing strips the parameter with `history.replaceState`, which
is what keeps a reload from reopening a dialog the visitor just dismissed. The
forgot-password dialog also chains to login (`$openLogin`) rather than dead-ending,
because "I don't remember" and "I have an account" are one conversation.

`data-test` hooks exist on the forms and their fields
(`forgot-modal-form`, `forgot-modal-email`, `forgot-modal-submit`,
`forgot-modal-login-link`) because the flow is exercised by the suite, and a modal
whose open state is inferred from a query string is exactly the kind of thing that
silently stops opening.

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
account moving between the reader home, the storefront and platform
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

The shared shell for a product module that has a route but no feature yet.
Unbuilt modules render through this component unless they need more than the
four sentences it can say, so the placeholder pages cannot drift into different
designs. It wraps `<x-app.ui.header>` inside a `<x-app.ui.body max="prose">`, and
states three things honestly: what the module is, what is already built
underneath it, and that the module itself is not available yet.
| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `title` | `string` | — | Also rendered as the section heading |
| `description` | `string` | — | One-line summary of the module |
| `icon` | `string` | `'sparkles'` | Must be a real Flux icon |
| `features` | `array` | `[]` | Planned features, each rendered as a checked list item |
| `spec` | `?string` | `null` | Path to the module's specification document |
| `eyebrow` | `?string` | `__('Module')` | Passed through to `<x-app.ui.header>` |
| `breadcrumbs` | `?array` | `Workspaces / {title}` | Passed through to `<x-app.ui.header>`; a public page states `Home / {title}` instead |
| `alreadyWorks` | `?string` | the workspace-permission sentence | The "What already works" body. The default describes a guarded workspace page, so a public landing or an admin console overrides it rather than claiming a guard it does not have |

A `status` prop used to be declared here, defaulting to `'planned'`. Nothing in the
template read it and neither surviving call site passed it, so it has been deleted:
a documented knob that changes nothing is worse than no knob.

```blade
<x-app.ui.module-placeholder
    :title="__('Developer Lab shelf')"
    :description="__('A curated shelf inside the AI Knowledge Hub.')"
    icon="beaker"
    :spec="'docs/lab.md'"
    :features="[__('Hand-picked snippets'), __('Hub-side ordering')]"
    :already-works="__('The lab itself is built; this shelf is the hub\'s view of it, which needs a curation model that does not exist yet.')"
/>
```

Its remaining call sites are **the two hub shelves**, `pages::ai-hub.dev-lab` and
`pages::ai-hub.ideas`. Both are deliberately still placeholders and neither is a lie
about the module it names: what is missing is a *hub-side curation model* — an
article-like row that says which snippets or ideas the hub has chosen and in what
order — which is a different object from the Ideas bank or the Lab's own gallery. The
Ideas and Developer Lab modules themselves outgrew this shell.

| Former call site | Now |
| --- | --- |
| `pages::ideas.index` | the public published index, with the category filter and the tab row |
| `pages::admin.ideas` | the platform-wide ideas console |
| `pages::admin.dev-lab` | the platform-wide snippets and executions console |
| `pages::dev-lab.index` | the assembled landing below |

A component that is only ever used by two screens is a component that is about to be
deleted, and this one is no exception: it survives because an honest placeholder beats
a public 500, and the two shelves are the last pages that need that argument made for
them.

---

### The Developer Lab components

`resources/views/components/app/dev-lab/` holds eight components: the five panels the
public landing is assembled from, the editor's code pane, one cluster inside a panel,
and the row every public surface draws a published snippet with. The landing declares
no markup beyond the header and the body: `sections()` returns an ordered list of
`{component, data}` pairs and the template renders each one through
`<x-dynamic-component>`, so the order of the page, the wording of every panel and
the audience rule all sit in one readable array while the styling lives in the
components.

| Component | Props | Renders |
| --- | --- | --- |
| `x-app.dev-lab.overview` | `data` — `title, description, icon, status, statusTone?` | The named panel with a chip that says which kind of lab this is |
| `x-app.dev-lab.capabilities` | `data` — `groups: list<{label, icon, items, done?}>` | One `<x-app.ui.section>` holding the groups |
| `x-app.dev-lab.capability` | `label, icon, items, done = true` | A single cluster: heading plus its list, ticked or dashed as a unit |
| `x-app.dev-lab.showcase` | `data` — `panes: {html, css, js}, title?, description?` | The landing's run-it-here panel: the sandbox's own factory over the repository's example, frame and console side by side, nothing executing until Run |
| `x-app.dev-lab.status` | `data` — `title?, icon?, tone?, description, spec, points?` | The honesty panel: what runs where, and what is still a runner-shaped gap |
| `x-app.dev-lab.access` | `data` — `heading, description, icon, tone, action?, secondary?` | The only audience-dependent panel; `action` and `secondary` are optional `<flux:button>`s |
| `x-app.dev-lab.snippet-card` | `snippet, href?, compact = false` | One published snippet as a public row: title, language badge, teaser, line count, author, published date |
| `x-app.dev-lab.code-pane` | `label, model, rows = 18, placeholder?, hint?, max?, id?` | One labelled monospace textarea with a byte counter and Tab-to-indent |

Five of the eight take one `data` array because that is what a dynamic component can
forward: `x-dynamic-component` passes attributes, so the page hands each panel a
single named bag and the panel destructures it. `capability`, `code-pane` and
`snippet-card` are reached by a static tag — from inside `capabilities`, from the
sandbox and the snippet form, and from the gallery, the landing's featured strip and
the topic page — so they keep ordinary named props.

`access` renders whatever the page hands it rather than deciding anything, which is
what keeps the audience rule in one place. The landing's `access()` has exactly three
branches — a guest, an account holding `dev-lab:use`, and an account that does not — and
the difference between them is three data arrays rather than three templates. The middle
one is offered the sandbox directly, because there is something to open.

`capability`'s `done` flag is per cluster, never per item, and that is the deliberate
constraint. A tick means the sentence is true today; a dash means it is a statement
about the roadmap. Mixing the two inside one list is how a page ends up with a check
beside a feature nobody can find, so a cluster is honest about itself or it is not
shown. `statusTone` on `overview` exists for the same reason in miniature: a lab that
runs code in the visitor's tab and a lab that is still a plan make different promises,
and a neutral chip between them costs a reader their time.

`showcase` is the only panel on a public page that mounts a frame and executes
something in it, and it earns that by executing only the repository's own code: the
three panes come from `config('dev_lab.showcase')`, not from a visitor and not from a
database row. It mounts the same `labSandbox` factory the sandbox screen mounts and
runs it through `runStored()` — the path the published snippet page already used —
because `run()` reads `$wire.html`, `$wire.css` and `$wire.js`, and the landing has no
editor to read them from. Nothing happens until the reader presses the button, and the
state that remembers this is the factory's own `dirty` flag, so pressing Stop returns
the reader to the offer rather than leaving an empty frame where the offer was. The
iframe carries `sandbox="allow-scripts"` and nothing else; the panel is not a second,
weaker copy of the boundary because the author of the code happens to be this
application. It states the boundary twice — a monospace chip reading `allow-scripts
only` beside the title, and the sentence that says `alert()` will not open — because a
modal that never arrives inside an opaque-origin frame reads as a bug rather than as a
rule.

`snippet-card` is the gallery's row lifted out of the page that drew it, so every
public surface answers "what is this code, what is it written in, and who kept it" the
same way: the gallery grid, the landing's featured strip — `compact`, smaller, not
different in meaning — and the code shelf on `/topics/{topic}`. The default target is
`route('dev-lab.snippets.show', $snippet->slug)`, so a caller that wants the published
page passes nothing, and a caller that wants somewhere else is saying so on purpose.
The workspace list and the admin console deliberately do not use it: those rows carry
the visibility state and the publish control, and reading them as cards would trade a
list you can act on for a grid you cannot. The component says that in its own docblock,
the way `x-app.ideas.card` does, because the restriction is the design and not an
oversight. Lifting the row out also retired the landing's `languageBadge()` computed,
which by then was a second definition of a badge the card was drawing; it is deleted
rather than left beside the thing it duplicated.

`code-pane` is the honest version of "a code editor" for a build that took a
dependency ban as a decision rather than an inconvenience. Highlighting a `<textarea>`
is not possible without an overlay library, so the pane gives a monospace field, a Tab
key that indents instead of escaping focus, and a live byte count against
`config('dev_lab.max_code_bytes')`. There is no syntax highlighting anywhere in the
module, published view included: the public snippet page prints escaped, single-colour
monospace code inside `<pre><code>`, which is the security requirement, not a
highlighter.

### The one client-side runtime the Lab has

`resources/js/lab-sandbox.js` is imported by `resources/js/app.js` for its side
effects and assigns `window.labSandbox`, so `⚡sandbox.blade.php`,
`⚡snippet-show.blade.php` and `components/app/dev-lab/showcase.blade.php` can name it
in an `x-data` attribute the same way the editor names `editor-ai.js`. Three surfaces
share it because the security boundary is one boundary: the author's own panes, a
stranger's published snippet, and the repository's own example on the landing must not
acquire different rules by accident.

Its shape is the reason a test cannot prove it works. `labSandbox()` holds `frame`,
`lines`, `nonce`, `dirty`, `wide`, `active` and `maxLines` — and deliberately **not** the
code: `run()` reads `html`, `css` and `js` off `$wire`, so the preview runs the same bytes
that a Save would write, including edits typed since the last debounce. `runWith()` takes
three strings somebody else already fixed, which is how a published snippet page offers
the same frame for a stranger's code without acquiring different rules, and
`runStored()` is the thin wrapper over the `panes` a page handed in at mount — which is
what the landing's showcase calls, because it has no database row and no editor either.
`stop()` replaces
the frame with an empty document, which is the non-reload way out of a loop. Nothing in
that path round-trips to the server, which is why Run shows output instantly and why the
only write the screen makes is the one that counts the run. The shim is a separate
top-level function serialised into the document with `toString()`, so the code running
inside the frame is the code in this file; the message it posts is authenticated by
`event.source === preview.contentWindow`, not by origin, because an opaque origin's
origin is the string `"null"`. Changing any of that is a security change, and
`docs/lab.md` is where it has to be written down as well.

### The Ideas components

`resources/views/components/app/ideas/` holds two, and they are the whole shared
vocabulary of the module: everything else on an Ideas screen is `<x-app.ui.*>`.

| Component | Props | Renders |
| --- | --- | --- |
| `x-app.ideas.card` | `idea, href?, showStatus = true, showCategory? = null` + an optional footer `slot` | One idea row: pin marker, title, three-line excerpt, stage badge, priority badge, a category chip the author alone sees, a "Not published" lock when the row is private |
| `x-app.ideas.status-badge` | `status, size = 'sm'` | A `<flux:badge>` carrying the enum's `label()` in the enum's `badgeColor()` |

`card` is used by four callers that must not diverge — the public index, the workspace
bands, the related shelf on the published idea page, and the ideas shelf on
`/topics/{topic}` — which is why the target is a prop: the card links to the published
idea by default and to the workspace edit screen when the caller says so. The module
console is not one of them: it renders its own rows, because a row an editor takes down
needs the visibility control beside it rather than underneath a link. The stage and the
priority sit *under* the excerpt because they qualify the idea rather than identify it.
The footer slot is where each caller's own actions would go, against the same row shape;
no caller passes one today, which is why the divider is conditional rather than a bare
strip of nothing under every card.

The category chip is the one piece of a card that is not a fact about the idea. An
`App\Models\IdeaCategory` name is one account's private filing system — the half of the
taxonomy `IdeaCategory` exists to keep private — so `showCategory` defaults to null,
meaning "author only", and the card resolves it against `auth()->id()` rather than
against the page it happens to sit on. A stranger reading the public index, the related
shelf, or a topic shelf therefore never sees how somebody else organises their notes,
and the three Ideas call sites needed no change to make that true. The subject chips are
the opposite case: those are platform-owned, which is why they appear in public and the
folder does not.

`status-badge` takes `IdeaStatus`, `PlanStatus` or `TaskStatus` rather than an idea,
and that is the point. Each enum owns its own `badgeColor()` mapping, so a re-tiered
workflow is one edit in the enum instead of a hunt through Blade, and the three
badges in one plan row cannot disagree about what "In progress" looks like. It renders
a word, never a coloured dot: a dot says *there is a status* and a word says what it
is.

---

### The AI Hub components

`resources/views/components/app/ai-hub/` holds five, and they are the hub's whole
shared row vocabulary. Four are reached by a static tag from the hub's screens;
`pill` is reached from inside the other four.

| Component | Props | Renders |
| --- | --- | --- |
| `x-app.ai-hub.article-card` | `article` | One article row: featured flag, primary topic, title, excerpt, reading time, status. |
| `x-app.ai-hub.path-card` | `path` | A learning path tile linking `ai-hub.path`. |
| `x-app.ai-hub.topic-card` | `topic` | A topic tile linking `ai-hub.topic`, listing its active children. |
| `x-app.ai-hub.resource-card` | `resource` | An outbound resource tile: `target="_blank"` with `rel="noopener noreferrer nofollow"`. |
| `x-app.ai-hub.pill` | `icon?, tone = 'neutral'` | The monospace uppercase chip the four cards and the hub headers share. |

`pill` exists because four different row types needed the same chip and one of
them renders its own icon; repeating that markup per card is how four shelves
drift apart in practice. **Two tones only, `neutral` and `signal`.** The hub has
one accent, and a difficulty ramp drawn in four colours would be read as a
quality score rather than as a label, which is the mistake the Ideas badges avoid
by carrying a word instead of a colour.

`resource-card` is the only tile in the application that leaves the site, and it
carries `nofollow` because the destination is curator-chosen, not
editorially-endorsed — the same reason the about page's social links do.

These five are *not* what the two hub shelves under `/ai-hub/ideas` and
`/ai-hub/dev-lab` render. Those are still
`<x-app.ui.module-placeholder>`s pending a hub-side curation model; see
[§ `module-placeholder`](#x-appuimodule-placeholder).

---

### The Feed components

`resources/views/components/feed/` holds six. They live outside `components/app/`
— see [§0](#0-the-directory-map) — and five of them are composed by exactly one
screen, `pages::feed.index`, which is why they read as a set rather than as
primitives.

| Component | Props | Renders |
| --- | --- | --- |
| `x-feed.card` | `post: FeedPost`, `fresh = false` | The timeline row: byline with the community it landed in, body, the type's own artefact, tags, then the meta bar. |
| `x-feed.avatar` | `initials`, `tone = 'neutral'\|'azure'\|'mint'`, `size = 'sm'\|'md'\|'lg'` | A round monospace initials disc. `aria-hidden`, because it always sits beside the name. |
| `x-feed.composer` | `initials = ''` | The collapsed entry line plus the six type chips. |
| `x-feed.filters` | `sort, type, category, tag, topics, tags = []` | Sort tabs left, topic/type/tag menus right. |
| `x-feed.nav-rail` | `sort, category, type = 'all', topics, typeCounts, communities = [], communityCount = 0` | The left rail: Navigation, Communities, Forum, Showcase. |
| `x-feed.inspector` | `posts, trendingTags, bounties, contributors, communities, featuredProducts, reader = null, readerInitials = '', tag = null, refusedSlug = null` | The right rail: the reader's own context, then the discovery widgets. |

**`x-feed.avatar` is the exception that proves the naming rule.** It has call
sites in three other feed components, in `pages::admin.feed`,
`pages/feed.composer-modal` and `pages/feed.show`, so it is genuinely shared —
but it is *feed-shaped*, not primitive-shaped: its tones are the feed's
azure/mint register, not the `sui-*` tokens a `ui/` component would draw on.
Moving it under `app/ui/` would advertise a palette it does not use.

`filters` and `nav-rail` hold no state of their own. Every control calls `$set`
against the parent's `#[Url]`-bound properties (`?sort=`, `?category=`, `?type=`,
`?tag=`), so a rail choice survives a reload and a feed view is a shareable URL.
The three menus inside `filters` are plain Alpine disclosures because a dropdown
that round-trips to the server on open is a slower dropdown, not a better one.
Search is deliberately absent: the app header owns it, next to the brand, so it
is reachable at every scroll position rather than only at the top of the rail.

`nav-rail` used to accept a `reader` prop that the feed page passed and the rail
never read. The prop and the passing are both gone. Leaving it would have been the
more dangerous choice: an undeclared attribute on a Blade component is not ignored,
it is merged onto the root element, so deleting only the `@props` entry would have
turned `:reader="$this->reader"` into an object-to-string conversion in the view.

`composer` is an entry point, not an editor. Collapsed it costs one input row,
and every one of its controls opens `feed-composer-modal`, which is where the
writing surface, media, audience and publish verb actually live. Its six modes
mirror the feed's own content types — question, discussion, showcase, snippet,
article, poll — so the composer cannot drift from what the module filters and
renders, and the chosen chip travels as the `feed-composer-open` detail so a click
is not lost on the way in. **A guest never reaches the modal:** the same controls
open the login dialog instead, because the honest answer to "I want to post" is
"sign in", not a form that will fail on submit.

`card` renders the type's artefact rather than a generic body: a post carrying
`code` gets the code block, one carrying `poll` gets the options, and one with a
`hasDetail()` thread gets the always-dark terminal panel — with the thread body
suppressed on a question that is unsolved, so an open question does not present a
partial discussion as a complete one. The topic tile is derived from the post's
own `topic` string, its first two characters upper-cased, so no per-post image,
colour, or glyph field exists to maintain.
`fresh` is the entry animation class, applied when the row just landed, and it is
a prop rather than a sniffed timestamp so the caller decides which rows are new.

---

### `x-communities.card`

| Prop | Type | Default |
| --- | --- | --- |
| `community` | `Community` | — |
| `member` | `bool` | `false` |

One community directory row, and the only component in
`resources/views/components/communities/`. Its name links to
`route('feed.index', ['category' => $community->topic])`, **not to a detail page,
because there is no community detail route**: the module is a directory plus the
feed, and a community *is* the topic filter it opens. That is the same view the
feed rail's community rows select, so the two surfaces cannot offer different
destinations for the same community.

`member` renders the membership marker. The count itself is never stored on the
row: it comes from `memberships_count`, the `withCount` value, so a counter column
cannot drift from the rows it summarises.

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

### `<x-app.ui.navbar>`

| Prop | Type | Default |
| --- | --- | --- |
| `crumb` slot | `Renderable` | — |

The floating navbar, and the only top-level chrome strip every authenticated
surface shares. It is a white pill with 16px corners inside the content column,
canvas visible on all four sides; depth comes from `sui-nav`, one soft shadow,
not from a border and not from a full-width band.

Four regions, left to right:

1. the drawer trigger, `lg:hidden` — at `lg` and up the sidenav is beside the
   bar and a button that opens nothing would be a lie;
2. the `$crumb` slot, which is the role's `nav-crumb`, in a `min-w-0 flex-1`
   wrapper so a long workspace name truncates instead of pushing the actions out;
3. search, `hidden md:block` — a GET form whose action is
   `route('admin.posts.index')`, so it is a real query against the posts table
   rather than a decoration;
4. the action group: view site, `<x-app.ui.theme-toggle>`, notifications, the
   profile menu.

**Search and the bell are `admin:access`-gated, and they are gated because they
are author tools, not because the bar is role-aware.** Both link into `/admin` —
the form to `admin.posts.index`, every bell row to `admin.comments` — so
`components/app/ui/navbar.blade.php:50` reads
`hasPermission(AccountPermission::AccessAdminPanel)` and skips rendering the
query at all when it is absent. An account that cannot open the target would
otherwise be shown a bell that counts a queue it can never display. The theme
toggle and the profile menu stay for everyone. The bar keeps no `role` prop and
branches on nothing else, which is what makes one file serve three layouts.

The bell is data, not a badge: it counts the pending comments the sidebar badge
counts and lists the four most recent, scoped to the author's own posts unless
`comment:moderate` is held (`:55-63`). The empty panel says "Nothing waiting."
rather than pretending there is a notification system behind it.

The profile menu is hand-built because `flux:profile` owns its avatar, and the
avatar here is the same accent-soft initial `<x-app.ui.sidenav-footer>` uses. The
menu itself is Flux's, so keyboard navigation and outside-click come from the
framework.

---

### `<x-app.ui.nav-crumb>`

| Prop | Type | Default |
| --- | --- | --- |
| `label` | `?string` | `__('Dashboard')` |

The breadcrumb that fills the navbar's `crumb` slot. Two levels, no more:

- **Root** — the workspace name from `$label`, linking to
  `route(auth()->user()->dashboardRoute())` and carrying
  `<x-app.icon.logo-mark>`. On the smallest screens this is also the only place
  the product identifies itself, because the sidenav is behind the drawer there.
- **Leaf** — the label of the navigation section holding the current route,
  walked out of `DashboardNavigation::for()` and reading the same
  permission-filtered map the sidebar renders (`:25-33`). It therefore cannot
  name a section the account is not allowed to see. When nothing is active —
  the dashboard root, a settings page — it falls back to `__('Overview')`,
  which is always true of a role's home.

`<nav aria-label="Breadcrumb">` with `aria-current="page"` on the leaf; the "/"
separator is `aria-hidden`.

---

### The role chrome: `user`, `seller`, `admin`

Each role directory holds exactly three components, and they are deliberately
thin.

| Component | Props | Body |
| --- | --- | --- |
| `<x-app.user.sidebar>` | `variant: 'default'\|'icons'` | `<x-app.ui.nav :label="__('Author workspace')" />`, or `<x-app.ui.nav-icons>` when `variant="icons"` |
| `<x-app.user.footer>` | — | `<x-app.ui.sidenav-footer :label="__('Author account')" />` |
| `<x-app.user.header>` | — | `<x-app.ui.nav-crumb :label="__('Author workspace')" />` |
| `<x-app.seller.sidebar>` | `variant` | same, labelled `__('Selling workspace')` |
| `<x-app.seller.footer>` | — | `__('Seller account')` |
| `<x-app.seller.header>` | — | `__('Selling workspace')` |
| `<x-app.admin.sidebar>` | `variant` | same, labelled `__('Administration')`. It also forwards `scope="admin"` to `x-app.ui.nav` and `nav-icons` — that is a prop of the shared nav, not of this component |
| `<x-app.admin.footer>` | — | `__('Administrator account')` |
| `<x-app.admin.header>` | — | `__('Administration')` |

None of the six footers or headers takes a `label` prop: each hard-codes its own
string, because the string is what the file exists to contribute and a caller
that could pass a different one would defeat the point. Only the sidebars take
anything, and only `variant`.

**The admin rail is the one role chrome with real behaviour in it.** It forwards
`scope="admin"` to `DashboardNavigation::for()`, which narrows the rail to the
platform-administration route prefix. The author and seller rails pass no scope
and get the permission-filtered whole. That is a navigation concern, not an
access-control decision, and it lives in the data source rather than in the view
— `components/app/ui/nav.blade.php:14-17` states that it stays
presentation-only.

**Beyond that, the one genuine per-role difference is the nav's accessible name.**
The items are not any role component's business otherwise: `DashboardNavigation`
already filters them by permission, and an admin holds every one of them, so the
Administration group appears in the admin sidebar and nowhere else *without the
admin sidebar knowing any other role exists*. Role differentiation comes from
composing permission-filtered data under a role-specific name, never from
duplicated navigation logic.

Each role file carries a docblock naming what that role is most likely to need
that is not a navigation item: the seller's listings, the admin's health
indicator, failed-job count and deploy stamp, the author's drafts queue.
Those belong in the role's directory when they arrive — which is why the
directories exist before their contents do. The seller's payouts link was the
promised example, and it never arrived with anything renderable behind it; see
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

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `post` | `?Post` | `null` | The blog shape: passing a post resolves the URL and the title from it |
| `url` | `?string` | `null` | An absolute page URL for a caller that is not a post |
| `title` | `?string` | `null` | The heading the intent links pre-fill |

Renders share targets from `config/portfolio.php`. See
[`<x-app.icon.share>`](#x-appiconshare).

It used to take a `Post` and nothing else, which is all a component needs while it has
one module calling it and a problem the moment it has two. Two lines of it were ever
blog-specific — the ones resolving `$url` and `$title` from `$post` — and everything
under them already worked on a string, so `post` became the default branch rather than
the only input. The two blog call sites pass `:post` exactly as before and render
identically; `pages::ideas.show` passes `:url` and `:title` twice, above and below the
prose. The snippet detail page renders no share bar: what it offers instead is a copy of
the code.

The three tiers are unchanged — the Web Share sheet where `navigator.share` exists, the
`config('portfolio.sharing')` primary and secondary intent lists, and copy-link as the
fallback that always works. The channel list is data rather than markup, so adding a
network is a config edit and not another edit of this file.

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
is an accent-filled chip in the dashboard shell and in the navbar's breadcrumb,
and a bordered monogram on the public About page:

```blade
<x-app.icon.logo-mark size="md" class="bg-sui-accent text-sui-ink-on-accent" />          {{-- sidenav + drawer --}}
<x-app.icon.logo-mark size="sm" class="bg-sui-accent text-sui-ink-on-accent" />          {{-- nav-crumb root link --}}
<x-app.icon.logo-mark size="lg" class="border … bg-ink-900 font-mono text-signal-400 …" /> {{-- public header --}}
```

On a dashboard page it renders three times: the sidenav, the drawer that reuses
the same brand slot, and the breadcrumb root in `<x-app.ui.nav-crumb>`.

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

### `partials/head.blade.php`

Not a component — the include all five layouts put their `<head>` through, recorded
here because the one thing in it that a page must not get wrong is not a component
either. It prints **exactly one** `<script type="application/ld+json">` block, guarded
by `$seo->jsonLd !== [] && ! $seo->noindex`, encoded with
`JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES`.

The flag set is the reason that line is safe rather than bold. It is the only unescaped
print of database text in the head: `JSON_HEX_TAG` turns `<` and `>` into `\u003C` and
`\u003E`, so an idea whose title contains `</script>` cannot close the element it is
being written inside, and the other three flags do the same job for the quote and
ampersand forms a stored string is full of. `JSON_UNESCAPED_SLASHES` is there so the
URLs inside the document stay readable. Nothing on the line is interpolated from
markup; the value arrives as a PHP array from the page component and is encoded here.

The value is `App\Support\Seo::$jsonLd`, a **list** rather than a single object, because
a page legitimately has several things to say about itself. Four pages say anything: the
published idea page emits an `Article` and a `BreadcrumbList`, the published snippet
page a `SoftwareSourceCode` and the same trail, and the two topic pages a
`CollectionPage` plus the trail back to the front door. One script holds the whole list,
because two top-level objects in a single `application/ld+json` block are not valid JSON
and would leave the parser with neither claim.

A `noindex` page emits nothing. Structured data is a claim addressed to a search engine
about a document the same head has just told it not to list, and the workspace screens
are exactly that.

### `<x-app.errors.page>`

One prop: `code`. Renders the HTTP error page for every supported status —
400, 401, 403, 404, 500, 503 — from the copy in `App\Support\HttpErrorCatalog`,
which is also the source for the dynamic route's `whereIn` pattern, so a status
can never exist in one place and not the others. An unsupported code refuses to
render (404) rather than showing an empty frame.

The page renders inside `layouts::guest.home`, so an error keeps the site's
header, footer and theme plumbing, and carries `noindex` SEO plus a real
`data-modal-open`-style escape hatch: one primary button back to the homepage.
The status code is drawn twice on purpose — a giant decorative numeral (hidden
from assistive tech) and the meaningful title as the `h1` — so a screen reader
hears "Page not found" once instead of "four zero four" spelled out.

Two render paths share the component: the framework's own `errors.{status}`
view discovery (real `abort()`s anywhere in the app) and the dynamic
`/errors/{code}` preview route. Because exception rendering runs outside the
`web` middleware group, the component shares an empty `$errors` bag for that
path — the guest shell's auth dialogs would otherwise die on an undefined
variable. Uncaught non-HTTP exceptions are mapped to the styled 500 by a
renderable in `bootstrap/app.php`, but only outside debug mode, where the
developer needs the trace more than the friendly page.

---

## 9. Layouts

Five layouts, one per audience. The audience is always the first path segment,
so a layout's name states who it is for before what it contains.

| Layout | Audience | Used by |
| --- | --- | --- |
| `layouts::guest.home` | unauthenticated visitors | Home, blog index, post detail, about — **and the default** |
| `layouts::auth` / `layouts::auth.split` | every authenticated account, mid-flow | The four mid-flow Fortify screens (verify email, two-factor challenge, confirm password, password reset); login, registration and forgot-password are modal-first now and render `x-auth.modals` in the guest shell instead |
| `layouts::app.dashboard` | authenticated accounts generally | `/dashboard`, the eleven Ideas and Developer Lab workspace screens, and the two AI Hub shelves |
| `layouts::seller.dashboard` | the seller role | `/seller/dashboard` |
| `layouts::admin.dashboard` | the admin role | `/admin/dashboard`, `/admin/users`, and every other screen under `/admin` including the two module consoles |

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
| `pages::dashboard.index` | `layouts::app.dashboard` |
| `pages::admin.posts.index`, `admin.posts.edit`, `admin.comments`, `admin.taxonomy` | `layouts::admin.dashboard` — they render the admin rail, so the shared shell is not enough |
| `pages::ideas.index`, `pages::ideas.show`, `pages::dev-lab.index`, `pages::dev-lab.gallery`, `pages::dev-lab.snippet-show`, `pages::topics`, `pages::topic`, `pages::ai-hub.index`, `pages::marketplace.index` | *(none — the guest shell, deliberately)* |
| `pages::ideas.dashboard`, `ideas.edit`, `ideas.categories`, `ideas.plans`, `ideas.plan`, `ideas.plan-edit`, `pages::dev-lab.dashboard`, `dev-lab.sandbox`, `dev-lab.snippets`, `dev-lab.snippet`, `dev-lab.history` | `layouts::app.dashboard` — a workspace is the account's own surface, so it wears the general shell rather than a role's |
| `pages::seller.dashboard` | `layouts::seller.dashboard` |
| `pages::admin.dashboard`, `pages::admin.users`, `pages::admin.ideas`, `pages::admin.dev-lab` | `layouts::admin.dashboard` |
| `pages::settings.*` | *(none — the guest shell, deliberately)* |

Those last rows are a wrinkle worth knowing about. Pages with no `#[Layout]`
attribute render in the guest shell. For settings that is a choice against
workspace chrome on an authenticated surface; for the public module surfaces — the two
landings, the gallery, the published idea and snippet pages, the shared topic pair, the
hub and the marketplace — it is the point: a guest has no workspace to be drawn inside,
and a sidebar that resolves to nothing for an account-less visitor is worse than no
sidebar. It does mean those pages are written in `ink-*`/`signal-*` utilities rather
than `sui-*` — a `tests/Feature/Dashboard/ShellTest.php` assertion pins the behaviour
so it cannot regress silently — and it is the reason `pages::ideas.show` and
`pages::dev-lab.snippet-show` carry a comment saying so, because "a public detail page
looks like the site it belongs to" is a decision somebody has to remember, not an
accident of an omitted attribute.

The split also means the same idea renders two different ways depending on who is
looking: the published page is editorial and the workspace card is Soft UI. That is
consistent with the rest of the app, where `/blog/{slug}` and `/admin/posts` have never
looked alike.

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
| `pages::seller.dashboard` | `pages/seller/⚡dashboard.blade.php` | `/seller/dashboard` |
| `pages::admin.dashboard` | `pages/admin/⚡dashboard.blade.php` | `/admin/dashboard` |
| `pages::admin.posts.index` | `pages/admin/posts/⚡index.blade.php` | `/admin/posts` |
| `pages::admin.posts.edit` | `pages/admin/posts/⚡edit.blade.php` | `/admin/posts/{post:id}/edit` |
| `pages::admin.comments` | `pages/admin/⚡comments.blade.php` | `/admin/comments` |
| `pages::admin.taxonomy` | `pages/admin/⚡taxonomy.blade.php` | `/admin/taxonomy` |
| `pages::admin.users` | `pages/admin/⚡users.blade.php` | `/admin/users` |
| `pages::admin.marketplace` | `pages/admin/⚡marketplace.blade.php` | `/admin/marketplace` |
| `pages::admin.dev-lab` | `pages/admin/⚡dev-lab.blade.php` | `/admin/dev-lab` |
| `pages::admin.ideas` | `pages/admin/⚡ideas.blade.php` | `/admin/ideas` |
| `pages::dev-lab.index` | `pages/dev-lab/⚡index.blade.php` | `/dev-lab` |
| `pages::dev-lab.gallery` | `pages/dev-lab/⚡gallery.blade.php` | `/dev-lab/gallery` |
| `pages::dev-lab.dashboard` | `pages/dev-lab/⚡dashboard.blade.php` | `/dev-lab/dashboard` |
| `pages::dev-lab.sandbox` | `pages/dev-lab/⚡sandbox.blade.php` | `/dev-lab/sandbox` (takes no route parameter) |
| `pages::dev-lab.snippets` | `pages/dev-lab/⚡snippets.blade.php` | `/dev-lab/snippets` |
| `pages::dev-lab.snippet` | `pages/dev-lab/⚡snippet.blade.php` | `/dev-lab/snippets/create` **and** `/dev-lab/snippets/{snippet:slug}/edit` |
| `pages::dev-lab.snippet-show` | `pages/dev-lab/⚡snippet-show.blade.php` | `/dev-lab/snippets/{snippet:slug}` |
| `pages::dev-lab.history` | `pages/dev-lab/⚡history.blade.php` | `/dev-lab/history` |
| `pages::ai-hub.index` | `pages/ai-hub/⚡index.blade.php` | `/ai-hub` |
| `pages::marketplace.index` | `pages/marketplace/⚡index.blade.php` | `/marketplace`, `/marketplace/categories/{category:slug}` |
| `pages::marketplace.show` | `pages/marketplace/⚡show.blade.php` | `/marketplace/{product:slug}` |
| `pages::ideas.index` | `pages/ideas/⚡index.blade.php` | `/ideas` |
| `pages::ideas.show` | `pages/ideas/⚡show.blade.php` | `/ideas/{idea:slug}` |
| `pages::ideas.dashboard` | `pages/ideas/⚡dashboard.blade.php` | `/ideas/dashboard` |
| `pages::ideas.edit` | `pages/ideas/⚡edit.blade.php` | `/ideas/create` **and** `/ideas/{idea:slug}/edit` |
| `pages::ideas.categories` | `pages/ideas/⚡categories.blade.php` | `/ideas/categories` |
| `pages::ideas.plans` | `pages/ideas/⚡plans.blade.php` | `/ideas/plans` |
| `pages::ideas.plan` | `pages/ideas/⚡plan.blade.php` | `/ideas/plans/{plan:slug}` |
| `pages::ideas.plan-edit` | `pages/ideas/⚡plan-edit.blade.php` | `/ideas/plans/create` **and** `/ideas/plans/{plan:slug}/edit` |
| `pages::topics` | `pages/⚡topics.blade.php` | `/topics` |
| `pages::topic` | `pages/⚡topic.blade.php` | `/topics/{topic:slug}` |

Three of those pairs — `ideas.edit`, `ideas.plan-edit`, `dev-lab.snippet` — are one
component serving create and edit. The screen reads its bound model, and when there is
none it is a form for a new row; the field list, the validation and the reserved-slug
rule are then written exactly once. The alternative, two components per object, is two
places to forget the slug rule.
| `pages::settings.profile` | `pages/settings/⚡profile.blade.php` | `/settings/profile` |
| `pages::settings.security` | `pages/settings/⚡security.blade.php` | `/settings/security` |
| `pages::settings.appearance` | `pages/settings/⚡appearance.blade.php` | `/settings/appearance` |
| `pages::settings.delete-user-modal` | `pages/settings/⚡delete-user-modal.blade.php` | (component) |
| `pages::settings.two-factor-setup-modal` | `pages/settings/⚡two-factor-setup-modal.blade.php` | (component) |
| `pages::settings.two-factor.recovery-codes` | `pages/settings/two-factor/⚡recovery-codes.blade.php` | (component) |

`pages/settings/layout.blade.php` is a **plain Blade component**, not an SFC —
the settings shell, invoked as `<x-pages::settings.layout>`.

### The public topic pair

`resources/views/pages/⚡topics.blade.php` (`topics.index`, `/topics`) and
`resources/views/pages/⚡topic.blade.php` (`topics.show`, `/topics/{topic:slug}`) are the
taxonomy's own shelf — one place that answers "what is this site about" without asking
the reader to choose a module first. They render in the public shell with no `#[Layout]`
attribute, the way the hub's identically shaped `ai-hub/topics` pair does, and the two
pairs stay at different URLs on purpose: `App\Models\Topic` is the flat, platform-wide
subject vocabulary that files both ideas and snippets, while `AiTopic` is the hub's tree
of parents and subtopics. Two shapes of "topic" in one page would be a site-wide
ambiguity, so the two live apart and say so in their own copy.

Both read `App\Services\PublicTaxonomy` rather than querying `Topic` directly, and that
is the reason they are a pair instead of two pages that happen to look alike. The service
owns the gate once: `is_active` is necessary and not sufficient, and a topic whose only
rows are drafts is absent rather than listed at zero, because a chip that opens an empty
list is a dead end wearing navigation. Its counts run through the `published()` scope on
the pivot relation, so the ideas landing, the gallery and `/topics` all print the same
numbers. `⚡topic` aborts 404 through `PublicTaxonomy::isBrowseable()`, which refuses both
the topic an operator switched off and the one that was seeded and never used: printing
either name above two empty shelves would contradict the operator and confirm to anybody
probing slugs that the row exists.

Each shelf on `⚡topic` shows one page of rows and links into the module's own list
carrying `?topic=`, instead of rebuilding a worse version of that list's search, sorting
and pagination here. The heading states the full count, so nothing is cut without saying
so, and the facet and the topic page cannot disagree because they read one scope.

### Marketplace components

`resources/views/components/app/marketplace/` holds the storefront's reusable
pieces. They are **public-site only**: the moderation console renders its own
table rows, because the public site and the dashboard resolve the `ink-*` scale
in opposite directions in dark mode (see §3) and one class list cannot be
correct in both.

| Component | Renders |
| --- | --- |
| `<x-app.marketplace.product-card>` | The catalogue card: category, name, tagline, rating, downloads, price. The whole card is one link. Rendered in the Vibe96 skin (`.v96-card`): uppercase purple category eyebrow, bold title that turns purple on hover, hairline-closed price row, and a 4px lift with a purple-tinted shadow on hover. |
| `<x-app.marketplace.rating>` | Stars plus the numeric average, derived from approved reviews. Says "No reviews yet" when there are none. |
| `<x-app.marketplace.install-command>` | A copyable shell command with its own Alpine scope. |
| `<x-app.marketplace.product-status>` | A `ProductStatus` badge; label and colour both come from the enum. |
| `<x-app.marketplace.review-card>` | One published review, public side. |

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
| 2 | ~~`<x-app.ui.desktop-user-menu>` and `<x-app.ui.app-logo>` are unused~~ **resolved** | Both deleted. `sidenav-footer` superseded the first; the auth layout composes `flux:brand` itself and only needs `<x-app.ui.app-logo-icon>`, which is kept. Nothing referenced either file, including tests. |
| 3 | ~~`<x-app.ui.desktop-user-menu>` uses `zinc-*` tokens~~ **resolved** | Off-palette against the `ink-*` system, and it predated the token migration. The fix and the deletion were the same change. |
| 4 | Invalid Flux icon names recurred four times | `storefront`, `book-open-text`, `folder-git-2`, `layout-grid` all threw. All fixed; a repo-wide audit is now part of the verification gate. |
| 5 | A dynamic `<flux:icon ::name="…">` recurses forever under Blaze | Memory exhaustion, not a blank icon. Never bind the icon name to an Alpine expression — see §6. |
| 6 | ~~`components/app/seller/payouts/index.blade.php` is orphaned **and unrenderable**~~ **resolved** | The file called `<x-app.ui.seller.payouts>` and `<x-app.ui.payouts-table>`, neither of which existed, and posted to `seller.payouts.request`, a route that did not either. It was the one thing that made `php artisan view:cache` fail — Blade compiles every view in the tree, so an orphan breaks a production build step even though no page ever reaches it. Deleted, and `view:cache` is green. Payouts stay out of scope until a payout provider or a controlled manual process exists (see [`docs/Developer Marketplace & Dig.md`](./Developer%20Marketplace%20%26%20Dig.md)). |
| 7 | **Blade does not compile directives inside a component tag's attribute list** | `@js($panes)` written on `<x-app.ui.section x-data="labSandbox({ … })">` reached the browser as the literal text `@js($panes)`, Alpine could not parse it, and `showcase` rendered a panel with no working button and no error anywhere. The same directive on a plain HTML element compiles normally, so the bug is invisible from the source. Pass `Illuminate\Support\Js::from($value)` through the `@php` block and interpolate it with `{{ }}` instead — identical escaping, on a path the component tag leaves alone. Pinned by `tests/Browser/LabShowcaseTest.php`; a `php artisan view:cache` pass does not catch it, because the text is valid Blade. |
| 8 | **A paginated list drops its filters unless the paginator is told to keep them** | `vendor/pagination/devfolio.blade.php` emits real `<a href>` links carrying `wire:navigate`, so moving to the next list is a navigation that rebuilds the component from the address bar rather than a Livewire round-trip that can still read the active filter off the component. A paginator built with `paginate()` writes those links from the path plus `?page=`, so `?topic=`, `?q=`, `?author=`, `?sort=`, `?lang=`, `?tab=` and `?visibility=` all disappear on the second list, under a heading that still claims the list is filtered. Every `paginate()` chain under `pages/` therefore ends with `->withQueryString()`. Pinned per-screen over HTTP by `tests/Feature/FilteredPaginationTest.php` and repo-wide by `tests/Feature/FilteredPaginationGuardTest.php`, which reads the source precisely so a new listing cannot be added without it. |
| 9 | **PHP turns an array key of the empty string into the integer `0`** | `['' => __('Every language')] + $languages` stored the clear-all entry as key `0`, so the chip it rendered asked for `?lang=0` and could never compare equal to the empty string the filter clears to. Render a clear-all control as its own element instead of as an entry in the option map. Found in the Developer Lab run history; pinned by `tests/Feature/DevLab/HistoryFiltersTest.php`. |
| 10 | **A plucked cast column gives back enum cases, not stored strings** | `Collection::contains('css')` over a plucked `lab_executions.language` is false for every case, because the model cast means the collection holds `LabLanguage` instances. The history screen shipped with a language filter row that was permanently empty for exactly this reason, and no test noticed because no test had ever asserted the row. Compare case to case (`$run->contains($language)`). |
| 11 | **An empty-state sentence built from a URL-bound label crashes on a value the screen does not offer** | `/ideas/plans` looked its tab up with `$this->tabs()[$tab]` inside a guard that excluded only two known keys, so a stale bookmark naming a retired stage raised *Undefined array key*. Any label lookup keyed by a `#[Url]` property must be nullable (`activeTabLabel()`), because the address bar accepts what the control never emits. Pinned by `tests/Feature/Ideas/PlansTabsTest.php`. |

On issue 4: the audit that found them is

```bash
ls vendor/livewire/flux/stubs/resources/views/flux/icon/ | sed 's/\.blade\.php$//' | sort > /tmp/icons.txt
# then every icon="..." in resources/views is checked against that list
```

It is not wired into a test, because the icon set is a vendor artefact and a
failing test on a Flux upgrade would be noise. It is a pre-release checklist
item instead.
