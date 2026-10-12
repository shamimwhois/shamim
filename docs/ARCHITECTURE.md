# Architecture

How this application is organised, and why it is organised that way. Every
claim here was verified against the code; where something is aspirational rather
than true, it says so.

---

## 1. The shape of the thing

A Laravel 13 application that renders almost everything through **Livewire
single-file components**. There is no JavaScript framework, no SPA, no API
layer. A page is a PHP class and a Blade template in one file, and an
interaction is a method call with a server round-trip.

That is a deliberate choice, not a limitation. The product is content and
forms. Livewire gives reactive forms, validation and state without a
client-side router, a state store or a serialization layer — roughly 3 kB of
JavaScript for the entire application.

### Stack

| Layer | Choice | Version |
| --- | --- | --- |
| Runtime | PHP | 8.5.11 |
| Framework | Laravel | 13.x |
| Components | Livewire SFCs | 4.1 |
| UI kit | Flux (free tier) | 2.13 |
| Auth | Fortify (headless) + Passkeys | 1.37 |
| Styles | Tailwind CSS 4, CSS-first `@theme` | 4.x |
| Bundler | Vite via `vite-plus` | 8.x / 0.3 |
| Database | SQLite | 3.x |
| Tests | Pest | 5.x |
| Types | Larastan (PHPStan) | level 7 |

---

## 2. Request lifecycle

Understanding one request explains most of the directory layout.

```
HTTP request
  │
  ├─ bootstrap/app.php ......... middleware aliases, route file registration
  │
  ├─ routes/web.php ............ Route::livewire('uri', 'pages::name')
  │
  ├─ middleware
  │    ├─ auth ................. Fortify session guard
  │    ├─ verified ............. email verification
  │    └─ permission:xxx ....... EnsureAccountPermission
  │
  ├─ Livewire mounts the SFC ... resources/views/pages/**/⚡*.blade.php
  │    ├─ mount() .............. authorization, initial state, SEO
  │    ├─ render() ............. implicit, returns the same file
  │    └─ actions .............. methods invoked via wire:click / wire:submit
  │
  └─ layouts::guest.home .... the default shell
       └─ components/app/home/{header,footer}
```

and the authenticated path:

```
  ├─ middleware
  │    ├─ auth ................. Fortify session guard
  │    ├─ verified ............. email verification
  │    └─ permission:xxx ....... EnsureAccountPermission
  │
  ├─ Livewire mounts the SFC ... resources/views/pages/**/⚡*.blade.php
  │    ├─ mount() .............. authorization, initial state, SEO
  │    ├─ render() ............. implicit, returns the same file
  │    └─ actions .............. methods invoked via wire:click / wire:submit
  │
  └─ layouts::app|seller|admin.dashboard ... one per audience
       └─ components/app/ui/dashboard-shell.blade.php
            └─ components/app/{user,seller,admin}/* ... the role's chrome
                 └─ Support/DashboardNavigation::for($user)
```

A workspace page names its layout with `#[Layout('layouts::app.dashboard')]`;
anything that names none lands in the guest shell, which is why the settings
pages — the one authenticated surface that wants the quiet public frame around
it — render there by default.

The one non-Livewire path is the syndication trio — `FeedController`,
`SitemapController`, `RobotsController` — which return raw responses and are
plain controller classes.

---

## 3. Why the directories exist

### `app/Actions/`

Every **write operation** that does more than a single `save()` lives here,
grouped by domain.

```
Actions/Posts/
  CreatePost.php       Persist a post, generate a unique slug, sync tags
  UpdatePost.php       Update fields, re-sync taxonomy
  PublishPost.php      draft → published, stamp published_at
  ArchivePost.php      published → archived
  DeletePost.php       Soft delete
  SyncPostTags.php     Replace a post's tag set
  SyncTaxonomyCounts.php  Denormalised post counts per category/tag
  ResolveRelatedPosts.php  Related-post query, extracted from the view
  HydratePostContent.php   Markdown → HTML
```

The point is not "thin controllers" as a slogan. It is that
**publishing a post is a domain operation with rules** — it must set the
timestamp, it must be idempotent, it must be reachable from a Livewire action
*and* from a seeder *and* from a test without duplicating the rules. An action
class is that operation with a name.

`CreatePost` is a good example: it takes a DTO-ish array, generates a slug
through the `HasSlug` concern, wraps the write in a transaction alongside tag
syncing, and fires no events. A caller cannot half-create a post.

### `app/Enums/`

Six enums carry the application's closed sets.

| Enum | Cases | Purpose |
| --- | --- | --- |
| `AccountRole` | 3 | `user`, `seller`, `admin` — the account's role |
| `AccountPermission` | 28 | Every grantable ability, in 5 groups |
| `PermissionGroup` | 5 | `content`, `moderation`, `selling`, `platform`, `administration` |
| `RolePlan` | 3 | `free`, `seller`, `platform` — commercial entitlements |
| `PostStatus` | 3 | `draft`, `published`, `archived` |
| `CommentStatus` | 3 | `pending`, `approved`, `rejected` |
| `ContentCategory` | 8 | What the editor's generator can produce |
| `ContentTone` | 6 | The register it writes in |
| `CorrectionType` | 6 | The kinds of correction the copy editor suggests |

The last three are the editor's vocabulary. Each carries its own `label()`,
`icon()` and `toArray()`, and its own prompt fragment —
`ContentCategory::instruction()`, `CorrectionType::guidance()`. Keeping the
prompt copy on the enum is what stops the agent's instructions from listing a
category the caller filtered out: the list is built **from** the requested
types, so the two cannot disagree.

`CorrectionType` also carries `isMechanical()` and `weight()`. Those drive real
behaviour: mechanical corrections are applied by "fix all", judgement calls are
not, and the weight orders suggestions so the uncontroversial fixes appear
first. Bundling spelling and style behind one button would silently rewrite an
author's voice.

There is a single role axis. The team-scoped `TeamRole` and `TeamPermission`
enums were removed along with the team feature, so `AccountRole` +
`AccountPermission` are the entire authorisation vocabulary.

Enums over lookup tables because these sets are **closed and behavioural**. A
`post_statuses` table would let a deployment add a fourth status the code
cannot render a badge for. An enum makes that unrepresentable, and gives
PHPStan enough information to prove a `match` is exhaustive — which is how
`PermissionGroup` came to exist: adding `groupLabel()` produced a
`match.unhandled` error on a plain string, and the fix was to model the group
properly rather than add a default branch.

### `app/Concerns/`

Model traits, one responsibility each.

| Trait | Adds |
| --- | --- |
| `HasAccountRole` | Role, plan, permission checks, `dashboardRoute()` |
| `HasSlug` | Slug generation with uniqueness |
| `HasReadingTime` | Word-count → reading minutes |
| `PasswordValidationRules` | Shared password rules |
| `ProfileValidationRules` | Shared profile rules |

`HasAccountRole` is the most load-bearing. It is what makes
`$user->can('/^post:/')`-style checks unnecessary: the model answers its own
authorization questions, and every other layer delegates to it.

### `app/Data/`

Immutable read-only DTOs — `AuthorProfile`, `PostMetrics`, `TextCorrection`.
They exist to stop **arrays with implicit shapes** crossing layer boundaries.
When a Blade template does `$post->metrics->wordCount` instead of
`$metrics['word_count']`, a typo is a static-analysis error rather than a blank
space on a page.

`TextCorrection` does more than carry data: it is where the model's arithmetic
is checked. The copy editor agent returns `start`/`end` offsets alongside the
text it wants to change, and **those offsets are never trusted**.
`fromArray()` verifies the span against the source (`substr($source, $start,
$end - $start) === $original`) and recomputes it with `strpos` when they
disagree, dropping any suggestion it cannot place. Splicing at a wrong offset
corrupts the document, so the repair belongs here — once — rather than at every
call site.

### `app/Ai/`

The Laravel AI SDK agents. One class per task, each implementing
`Laravel\Ai\Contracts\Agent` and `use Laravel\Ai\Promptable;`:

| Agent | Returns |
| --- | --- |
| `CopyEditorAgent` | Correction suggestions as a structured list |
| `ContentWriterAgent` | `title`, `summary`, `body` for a category |
| `ImagePromptAgent` | A refined image prompt, alt text, summary |

They declare `HasStructuredOutput` rather than asking for JSON in prose, so the
SDK enforces the shape and a malformed reply is a caught exception rather than a
parse failure. Provider and model come from `config('ai.editor.*')`, never from
a hardcoded vendor, so the whole editor repoints with an env change.

**Do not pass a dynamic name to `<flux:icon>`.** A `<flux:icon ::name="…">`
binding makes Blaze re-enter the compiled icon template forever and exhausts
memory. Resolve the name server-side instead.

### `app/Services/`

`EditorAiService` is the editor's single door to the AI provider. Every AI
feature goes through it for two reasons:

**One place to be unavailable.** No provider key is configured on a fresh
checkout, and that must not be a 500. `isConfigured()` gates every operation,
the controller turns a `false` into a clear 503, and the editor hides its AI
affordances rather than rendering buttons that fail. It treats Ollama and
`openai-compatible` as valid with only a `url` set, because neither takes a key.

**One place to be wrong safely.** A malformed response, an upstream timeout, a
rate limit — all routine, and none should surface a stack trace to an author
mid-sentence. Failures are caught, logged with context, and rethrown as
`EditorAiException` carrying a message written for a person. Timeouts, 429s and
401s each get their own message; everything else gets a generic one.

### `app/Http/Responses/`

Fortify is headless — it knows *that* you authenticated, not *where* you should
land. There is one response class per flow:

```
LoginResponse            → role-aware dashboard
RegisterResponse         → onboarding path
VerifyEmailResponse      → dashboard with ?verified=1
TwoFactorLoginResponse   → dashboard after challenge
PasskeyLoginResponse     → dashboard after passkey
```

All five share `Responses/Concerns/RedirectsToRoleDashboard`, which resolves the
destination from `AccountRole::dashboardRoute()`. It touches no team URL
defaults — there is no team to seed a prefix from.

**This was the most consequential bug in the inherited codebase.** Every
successful login, registration and verification landed on the starter kit's
placeholder `/{team:slug}/dashboard` — a page that shows framework scaffolding,
not the application. The fix routes each role to its own dashboard —
`/dashboard`, `/seller/dashboard`, `/admin/dashboard` — and guards with
`app('router')->has($routeName)` so a role can be declared before its dashboard
exists.

### `app/Support/`

The three cross-cutting services.

**`DashboardNavigation`** — the single source of truth for the dashboard
sidebar. Entries declare `label`, `route`, `pattern`, `icon`, `permissions` and
an optional `badge`. `for(User $user)` filters by permission and resolves
badges. Sections render in a fixed order — Overview, Content, Moderation,
**Modules** (Ideas / Developer Lab / AI Hub / Marketplace), Selling,
Administration — and a section whose every item is filtered out is dropped
entirely, heading included.

Its importance is structural: the sidebar and the route guards read from the
same definitions, so a navigation item cannot be visible to an account whose
route would reject it. Two statements of one rule drift; one statement cannot.

**`MarkdownRenderer`** — CommonMark to safe HTML, configured once.

**`Seo`** — a scoped instance each page populates during `mount()`. Every field
has a fallback, so a page that declares nothing still emits a complete,
valid set of Open Graph and Twitter tags.

---

## 4. The view layer

### Two kinds of Blade file

| Kind | Location | Naming | How it is invoked |
| --- | --- | --- | --- |
| **Livewire SFC** | `resources/views/pages/**` | `⚡name.blade.php` | `Route::livewire('uri', 'pages::name')` |
| **Blade component** | `resources/views/components/**` | `name.blade.php` | `<x-name />` |
| **Layout** | `resources/views/layouts/**` | `name.blade.php` | `<x-layouts::name>` |

The `⚡` prefix is Livewire's convention for a single-file component: one file
containing `<?php new class extends Component { … } ?>` followed by the
template.

**This has a testing consequence that catches everyone once.** SFCs are
*anonymous* classes with no name, so `::class` cannot reference them. Tests use
the **name string**:

```php
Livewire::test('pages::blog.show', ['post' => $post]);   // correct
Livewire::test(BlogShow::class, ['post' => $post]);      // impossible
```

### Namespace routing

`Route::livewire('uri', 'pages::name')` resolves `pages::` to
`resources/views/pages` and `layouts::` to `resources/views/layouts`. A route
pointing at `pages::dashboard.⚡index` means
`resources/views/pages/dashboard/⚡index.blade.php`.

Because a missing view is a runtime error rather than a build error, route
names and file paths are asserted in tests — see `docs/ROUTES.md`.

### The component tree

Every Blade component lives under `resources/views/components/app/`, and the
second path segment names its audience:

```
components/app/
├── home/          the guest chrome — header, footer
├── ui/            shared primitives: header, body, section, stat, nav, modal,
│                  post/*, editor/*, auth/*, and the dashboard-shell itself
├── icon/          components whose whole body is a glyph
├── user/          the author workspace's chrome: header, footer, sidebar
├── seller/        the selling workspace's chrome
└── admin/         the administration workspace's chrome
```

The prefix is a namespace, not decoration: `<x-app.ui.modal>` can never be
shadowed by a package's anonymous `<x-modal>`. The directory rule is the
important part — **a component goes in `ui/` unless a role owns it**, and `ui/`
may not branch on role.

The load-bearing consequence is the shell. There are three workspace layouts and
one implementation: `components/app/ui/dashboard-shell.blade.php` holds the
rail, the mobile drawer, the focus trap and the `data-sui` scope, and each role
layout composes it with its own three one-line chrome components. Triplicating
~270 lines of Alpine layout machinery would mean three places to fix the same
bug. Role differentiation comes from *composing* permission-filtered navigation
under a role-specific accessible name — `DashboardNavigation::for($user)`
already filters, so an admin's Administration group appears in the admin sidebar
and nowhere else without the admin sidebar knowing any other role exists.

Full inventory with every prop: [`COMPONENTS.md`](COMPONENTS.md).

### Role-owned URL spaces

Each role owns a **top-level** path; the role is the first segment of the URL,
so the address bar alone states which surface is loaded. No role's home is
nested inside another's.

| Role | Path | Route name | Guard |
| --- | --- | --- | --- |
| user | `/dashboard` | `dashboard.index` | `auth, verified` |
| seller | `/seller/dashboard` | `seller.dashboard` | `auth, verified, permission:product:create` |
| admin | `/admin/dashboard` | `admin.dashboard` | `auth, verified, permission:admin:access` |

The seller and admin guards name a **permission**, not a role. A role check
would drift from the permission matrix; a permission check cannot.

The shared author workspace stays on `/dashboard` for *every* authenticated
account, because a seller and an admin both still write posts:
`dashboard.posts.index`, `dashboard.posts.create`, `dashboard.posts.edit`,
`dashboard.comments`, `dashboard.taxonomy`. Admin-only `/admin/users` →
`admin.users` sits alongside the admin dashboard.

### Platform module routes

Four modules each claim a top-level URL space, guarded by their own permission.
They are not built yet, so each renders a placeholder page via the shared
`resources/views/components/app/ui/module-placeholder.blade.php` — the routes
are real and the navigation points at them, so each has a landing spot to be
built into rather than needing a routing change later.

| Module | Path | Route name | Permission |
| --- | --- | --- | --- |
| Developer Lab | `/dev-lab` | `dev-lab.index` | `dev-lab:use` |
| AI Knowledge Hub | `/ai-hub` | `ai-hub.index` | `ai-hub:use` |
| Marketplace | `/marketplace` | `marketplace.index` | `marketplace:use` |
| Ideas | `/ideas` | `ideas.index` | `ideas:use` |

The URL spaces are claimed now on purpose: these are top-level names that must
not be taken by anything else.

A small set of **301 redirects** keeps old bookmarks working after the move from
nested paths: `dashboard/seller` → `/seller/dashboard`,
`dashboard/admin` → `/admin/dashboard`, the preserved name `team.dashboard`
→ `/dashboard`, and `settings/teams` → `/settings/security`.

### The modal system

Eight modals were independent copies of the same pattern. They now share one
shell.

```
<x-app.ui.modal>               shell: focus, width, accessible name, reopen-on-error
<x-app.ui.modal.actions>       footer row; cancel defaults to a closing button
<x-app.ui.modal.trigger>       thin pass-through, keeps the modal name in one place
```

`tone="danger"` changes the header icon treatment only. The button that
performs the destructive action still declares `variant="danger"` itself,
because that control is frequently outside the modal shell in the DOM.

Migrating the eight modals surfaced **four non-existent Flux icon names**
(`storefront`, `book-open-text`, `folder-git-2`, `layout-grid`) that threw hard
exceptions when rendered. They had been latent in copied markup for as long as
the copies existed. That is the honest argument for consolidation: the bug was
not introduced by the duplication, it was *hidden* by it.

---

## 5. Authorization

**One axis.** A single, platform-wide permission set, derived from the account's
role. An earlier design had a second, team-scoped axis; the team feature was
removed in its entirety, so that axis no longer exists and nothing here should
be read as though it does.

### Account permissions (platform-wide)

```
User → role (enum) → permissions (derived)
```

`AccountRole::permissions()` derives the set; nothing is stored.

| Role | Level | Permissions |
| --- | --- | --- |
| `user` | 1 | 6 |
| `seller` | 2 | 15 (includes all of `user`) |
| `admin` | 3 | 26 (all cases) |

Seller inherits by **spread** — `...self::User->permissions()` — so adding a
user permission automatically reaches sellers. Admin returns
`AccountPermission::cases()`, so a new permission is granted to admins the
moment it is declared. Both are intended: a seller *is* a user with more, and
an admin is defined as "everything".

Enforced by `EnsureAccountPermission` middleware, aliased as `permission:`.

```php
Route::middleware('permission:post:create')

// Multiple permissions are OR'd by default — any one grants access:
Route::middleware('permission:user:manage,role:manage')

// Prefix with `all:` to require every one instead:
Route::middleware('permission:all:user:manage,role:manage')
```

**The default is OR, and that is a security-relevant default worth being
explicit about.** A route guarded with two permissions grants access to anyone
holding *either*. That matches how a navigation entry visible to two different
roles should behave, but it means the safe reading of
`permission:a,b` is "a or b" — never assume it tightens.

Two failure modes are handled loudly rather than silently:

- A route declaring **no valid permission** aborts with `500`, not `403`. An
  `abort_if($required === [], 500, …)` catches the typo'd alias, because a
  guard that silently allows everything is the worst possible outcome.
- A **guest** is redirected to login rather than shown a 403. They may hold the
  permission once authenticated; a 403 would be a dead end. `auth` normally
  runs first, so this branch is defensive.

### Policies

`PostPolicy` and `UserPolicy` handle resource-scoped questions the middleware
cannot answer — "may this account edit **this** post".

`UserPolicy` contains the escalation guard. Role assignment is checked twice:
the policy authorizes the action, and `AccountRole::assignableBy()` filters
which roles an actor may grant. Belt and braces, because privilege escalation
is the one bug class worth redundant defence.

Full matrix: [`PERMISSIONS.md`](PERMISSIONS.md).

---

## 6. Data layer

Six application migrations plus the framework defaults.

```
users
  │
  ├─ posts ───── post_tag ──── tags
  │   │
  │   └─ comments
  │
  └─ role (AccountRole)
```

Conventions, applied without exception:

- **PHP 8 attributes over properties** — `#[Fillable([...])]`, `#[Hidden([...])]`.
- **`casts()` as a method**, not a property.
- **Full PHPDoc property blocks** on every model, so PHPStan level 7 can
  resolve attribute types.
- **Enums are cast** — `'role' => AccountRole::class`, not `'role' => 'string'`.

`PostObserver` keeps the denormalised `posts_count` columns on `categories` and
`tags` in step. Denormalisation is deliberate: a category listing shows counts,
and counting per row is N+1.

The reconciliation strategy matters more than the denormalisation.
`SyncTaxonomyCounts` **recounts** rather than incrementing or decrementing:

```php
'posts_count' => $category->publishedPosts()->count(),
```

An increment/decrement scheme drifts the first time a write path forgets to
touch the counter — and it will, because publishing, archiving, deleting and
re-tagging are four separate paths. A recount is more expensive and *self-heals*
on the next write, so a missed path costs a stale number until the next save
rather than a permanently wrong one. `recountAll()` exists to reconcile drift
in bulk.

Schema detail: [`SCHEMA.md`](SCHEMA.md). Relationships: [`ERD.md`](ERD.md).

---

## 7. Front-end architecture

Tailwind CSS 4 with **CSS-first configuration** — design tokens are declared in
`resources/css/app.css` under `@theme`, not in `tailwind.config.js` (which does
not exist).

Two token families carry the identity:

```css
--color-ink-*      /* warm-shifted neutrals, 50–950 */
--color-signal-*   /* terminal green-teal accent, 50–950 */
```

`ink` is a deliberately warm grey — a neutral that is not clinically blue-grey.
`signal` is a green-teal used sparingly, as an accent rather than a theme.

The editorial type scale is a 1.200 minor third from `--text-2xs` (11px) to
`--text-5xl` (60px), with tracking that tightens as size grows.

**Theme handling.** Light and dark are both first-class; the visitor chooses and
`@fluxAppearance` applies it before first paint. No layout hardcodes a theme —
this was a real bug in the inherited auth layouts, which forced `class="dark"`
and overrode the choice.

**Custom utilities.** Anything repeated three times is promoted to a `@utility`
so it cannot drift between call sites. Tailwind tree-shakes an unused `@utility`
out of the build entirely, so an unreferenced one costs nothing and a
*grep-missing* one in `public/build` means "unused", not "broken".

| Utility | Purpose |
| --- | --- |
| `eyebrow` / `eyebrow-accent` | Numbered monospace section marker |
| `hairline` | Full-bleed 1 px rule, theme-aware |
| `shell` / `shell-narrow` | The one horizontal container (public) |
| `link-underline` | Accent underline that draws in on hover |
| `grid-field` | Hairline grid fading out behind hero content |
| `tag-pill` | Category / tag chip |
| `card-surface` | Bordered card surface |
| `surface-raise` | Bordered card + shadow so faint it only reads as separation |
| `focus-ring` | Opt-in ring for elements that suppress the default outline |

`focus-ring` exists because the base layer's `:focus-visible` outline does not
follow a non-square `border-radius`. It draws its ring with `box-shadow` instead,
and flips its offset colour to `ink-950` in dark mode.

**Reduced motion.** The base layer clamps animation and transition durations
under `prefers-reduced-motion`. That clamp does **not** stop Alpine from applying
its `x-transition` classes, so those elements are additionally neutralised in the
same block. Verified against a real drawer, not assumed.

Full design language: [`UI-UX-BRIEF.md`](UI-UX-BRIEF.md).

---

## 8. Testing strategy

Pest 5, 255 passing tests, 903 assertions.

| Suite | Covers |
| --- | --- |
| `Feature/Auth/*` | Login, register, reset, verification, 2FA, passkeys, layout contract |
| `Feature/Blog/*` | Index, show, sharing, drafts, previews |
| `Feature/Dashboard/*` | Post management, moderation, taxonomy, shell structure |
| `Feature/DashboardTest` | Role-scoped dashboards, nav filtering, role badges |
| `Feature/Settings/*` | Profile, security, 2FA lifecycle |

Two conventions worth stating:

**Test the denial path, always.** For every "user can view their own draft"
there is a "stranger cannot". Authorization bugs live on the negative path.

**Test the contract, not the markup.** `AuthLayoutTest` asserts that the layout
does *not* hardcode a theme, not that a particular `div` has a particular class.
Class-level assertions break on every redesign and catch nothing.

---

## 9. Deliberate non-goals

Things this architecture intentionally does not do, so that their absence reads
as a decision rather than an oversight.

| Not done | Why |
| --- | --- |
| No API layer | Nothing needs one yet. Specified in `API.md`, unimplemented by design. |
| No queue workers in dev | `queue:listen` runs in `composer dev`; no job is critical-path. |
| No client-side state store | Livewire holds state server-side. Alpine covers local UI. |
| No `Product` model | The seller role is defined; the commerce module is not. Documented, not hidden. |
| No repository interfaces | Eloquent *is* the data layer. Wrapping it adds indirection without a second implementation to justify it. |
| No service container gymnastics | Bindings are in `AppServiceProvider`; anything more is speculation. |

---

## 10. Extension points

Where a new feature plugs in, in the order you would touch them.

**Adding a page**

1. `resources/views/pages/<area>/⚡<name>.blade.php` — the SFC
2. `routes/web.php` — `Route::livewire(...)`, add `permission:` if guarded
3. `app/Support/DashboardNavigation.php` — only if it belongs in the sidebar
4. `tests/Feature/<Area>/<Name>Test.php`

**Adding a permission**

1. `app/Enums/AccountPermission.php` — add the case
2. `group()` — assign a `PermissionGroup`; an unhandled case fails PHPStan
3. `AccountRole::Seller` — add to the array **only** if sellers should have it
4. Nothing else — admin picks it up automatically from `cases()`

**Adding an account role**

1. `app/Enums/AccountRole.php` — add the case and its permissions
2. `dashboardRoute()` — return the route name
3. `RolePlan` — associate a plan, or return `null`
4. `resources/views/pages/<role>/⚡dashboard.blade.php` — the dashboard, with
   `#[Layout('layouts::<role>.dashboard')]`
5. `resources/views/layouts/<role>/dashboard.blade.php` — the role's layout,
   composing `<x-app.ui.dashboard-shell>` with the role's chrome
6. `components/app/<role>/{header,footer,sidebar}.blade.php` — three one-line
   components labelling the shell's regions for this role
7. `routes/web.php` — a middleware-guarded sub-group
8. `DashboardNavigation` — section entries
9. `UserSeeder`, `UserFactory` — a state and a demo account

Steps 1–2 are enough for the role to *work*: `RedirectsToRoleDashboard` uses
`router->has()` so the login redirect falls back rather than 500s even before
the dashboard route exists. A page whose `#[Layout]` names a view that does not
exist is a hard runtime error rather than a silent fallback — the graceful
degradation is the *other* direction, where a page declaring no layout at all
lands in the guest shell, which is exactly how the settings pages are built.
