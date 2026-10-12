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

### `<x-module-placeholder>`

The shared shell for a product module that has a route, a permission and a
sidebar entry but no feature yet. Every unbuilt module renders through this
component, so the four placeholder pages cannot drift into four different
designs. It wraps `<x-dashboard.header>` with `eyebrow="Module"` and states
three things honestly: what the module is, what is already built underneath it,
and that the module itself is not available yet.

| Prop | Type | Default | Notes |
| --- | --- | --- | --- |
| `title` | `string` | — | Also rendered as the card heading |
| `description` | `string` | — | One-line summary of the module |
| `icon` | `string` | `'sparkles'` | Must be a real Flux icon |
| `status` | `string` | `'planned'` | Declared for future use; the badge is currently a fixed "Not yet available" |
| `features` | `array` | `[]` | Planned features, each rendered as a checked list item |
| `spec` | `?string` | `null` | Path to the module's specification document |

```blade
<x-module-placeholder
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
| `showRole` | `bool` | `true` |

The dropdown in the dashboard sidebar footer. Carries
`data-test="sidebar-menu-button"`. `showRole` controls whether the account's
role label renders beneath its name in the collapsed-header area.

> **Legacy.** This component still uses `zinc-*` classes where the project token
> is `ink-*`. It predates the design token migration and is a candidate for
> conversion; it is listed here rather than silently fixed because it is outside
> the current redesign's scope.

---

## 6. Layouts

| Layout | Tag | Used by |
| --- | --- | --- |
| `layouts/public.blade.php` | `<x-layouts::public>` | Home, blog, about |
| `layouts/dashboard.blade.php` | `<x-layouts::dashboard>` | All dashboard and settings pages |
| `layouts/auth.blade.php` | `<x-layouts::auth>` | All seven Fortify screens |
| `layouts/auth/split.blade.php` | `<x-layouts::auth.split>` | The implementation behind the above |

The starter-kit `layouts/app*` layouts and `views/dashboard.blade.php` were
deleted along with the team feature. The public site (landing page, blog index,
post detail, about) renders inside `layouts::public`, which is the default;
dashboard and settings screens opt into `layouts::dashboard` explicitly — see
[How a page gets its layout](#how-a-page-gets-its-layout).

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
`component_locations` (which lists `resources/views/components` and
`resource_path('views/livewire')`) are both declared there too.
`component_locations` is what lets a plain component resolve by short name —
`components/module-placeholder.blade.php` is `<x-module-placeholder />`, and an
SFC placed under `views/livewire` is `<livewire:name />`, neither needing a
namespace prefix. Only the `pages::` and `layouts::` namespaces need one. So
`config/livewire.php` is the file to read first when a component cannot be
found.

---

## 8. Known issues

Concrete defects and hazards found while writing this reference. Recorded so they
are tracked rather than rediscovered.

| # | Issue | Impact |
| --- | --- | --- |
| 1 | `<x-placeholder-pattern>` defaults `id` to `uniqid()` | Two instances on a page collide; the second renders blank. Pass an explicit `id`. |
| 2 | `<x-desktop-user-menu>` uses `zinc-*` tokens | Off-palette against the `ink-*` system. Predates the token migration. |
| 3 | `<x-desktop-user-menu>` and other pre-migration components use `zinc-*` tokens | Off-palette against the `ink-*` system, but they still render. |
| 4 | Invalid Flux icon names recurred four times | `storefront`, `book-open-text`, `folder-git-2`, `layout-grid` all threw. All fixed; a repo-wide audit is now part of the verification gate. |

On issue 4: the audit that found them is

```bash
ls vendor/livewire/flux/stubs/resources/views/flux/icon/ | sed 's/\.blade\.php$//' | sort > /tmp/icons.txt
# then every icon="..." in resources/views is checked against that list
```

It is not wired into a test, because the icon set is a vendor artefact and a
failing test on a Flux upgrade would be noise. It is a pre-release checklist
item instead.
