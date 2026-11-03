# Technical Requirements Document — DevFolio

| Field | Value |
| --- | --- |
| Document version | 1.1 |
| Status | Approved for build — §2.5, §4.1 and §5 revised for the role model |
| Audience | Senior engineers implementing or reviewing the system |
| Companion documents | [PRD](./PRD.md) · [Application Flow](./APP-FLOW.md) · [UI/UX Brief](./UI-UX-BRIEF.md) · [Schema](./SCHEMA.md) · [ERD](./ERD.md) · [Permissions](./PERMISSIONS.md) · [Architecture](./ARCHITECTURE.md) · [Components](./COMPONENTS.md) · [Routes](./ROUTES.md) · [API](./API.md) |

Sections marked **Revised** were corrected against the shipped code. Where an earlier draft described the system that was planned rather than the system that was built, the original claim is quoted and then corrected, so a reader who remembers the old wording can see exactly what changed and why.

---

## 1. Tech Stack

Versions below are the versions actually installed in this repository, verified from `composer.json`, `composer.lock`, and `package.json`. Do not assume a version — re-verify with `composer show --direct` and by reading `package.json` before relying on a specific API.

### 1.1 Backend

| Layer | Technology | Installed version | Rationale |
| --- | --- | --- | --- |
| Language | PHP | 8.5.0 | Constructor property promotion, readonly properties, enums, `#[Fillable]`/`#[Hidden]` attributes, first-class callable syntax. |
| Framework | Laravel | 13.35 | Eloquent, queues, migrations, validation, service container. Laravel 13 supplies attribute-based model configuration, which this codebase uses in preference to class properties. |
| Reactive UI | Livewire | 4.4.7 | Server-driven reactivity removes the need for a separate JSON API and a SPA. State lives in PHP, which is where the authorisation and validation logic already lives. |
| UI components | Flux UI | 2.20.1 | Free edition. Provides accessible primitives (modal, dropdown, field, toast, navbar) that would otherwise be reimplemented badly. |
| Auth | Laravel Fortify | 1.41.0 | Headless auth: registration, login, password reset, email verification, two-factor, passkey. Login, registration and the forgot-password request are modals on the home page; the four mid-flow screens are supplied by this application. |
| Passkeys | `laravel/fortify` passkey support | — | WebAuthn login and registration, already wired through `PasskeyAuthenticatable`. |
| AI | `laravel/ai` | 1.2+ | Official first-party AI SDK. Agents, structured output, provider abstraction, and a `fake()` harness for tests. See §1.5. |
| Admin scaffolding | `laravel/chisel` | 0.1+ | Project scaffolding helper; not used at runtime by product features. |
| Dev tooling | Pint, Larastan/PHPStan, Pest 5, Pail, Boost | see `composer.json` | Formatting, static analysis, testing, log tailing, framework-aware tooling. |

### 1.2 Frontend

| Layer | Technology | Installed version | Rationale |
| --- | --- | --- | --- |
| Build tool | Vite | 8.x via `vite-plus` | Config is wrapped in `defineConfig`/`lazyPlugins` from `vite-plus`; keep new plugins inside the existing `lazyPlugins` callback. |
| CSS | Tailwind CSS | 4.1+ | CSS-first configuration via `@theme`. There is **no** `tailwind.config.js` in this project and one must not be introduced. |
| Fonts | Bunny Fonts via `laravel-vite-plugin/fonts` | — | Privacy-friendly font delivery; configured in `vite.config.js`. |
| Client JS | Alpine.js | bundled with Livewire 4 | Do **not** install Alpine separately — Livewire 4 ships it. |
| Icons | Heroicons (via Flux) + Lucide (via `php artisan flux:icon`) | — | Search Heroicons for exact names; never invent icon names. |

### 1.3 Data

| Concern | Choice | Rationale |
| --- | --- | --- |
| Database | SQLite (`DB_CONNECTION=sqlite`) | Zero-configuration local development. All queries stay portable to MySQL/PostgreSQL — no SQLite-specific SQL. |
| Migrations | Anonymous class migrations | Laravel 11+ default; matches the existing `database/migrations` files. |
| Full-text search | Indexed `LIKE` predicates | `LIKE` over `title`, `excerpt`, `body` with a leading-anchor-friendly index strategy. Sufficient at this content volume; see [§3.6](#36-search-strategy). |
| Queue | `database` driver | Autosave, view counting, and email notifications are queued so requests stay fast. |
| Session | `database` driver | Enables multi-instance deployment without sticky sessions. |
| Cache | `database` driver | Cached tag/category counts and related-post results. |

### 1.4 Explicitly rejected dependencies

| Package | Considered for | Why rejected |
| --- | --- | --- |
| `spatie/laravel-permission` | Author roles | **Revised.** Three account roles and 30 permissions now exist, but they are enum-backed behaviour, not rows. There is no `roles` table, no `permissions` table, and no pivot, because the mapping is code that deploys with the release rather than data an operator can edit at runtime. See §2.5 and [ERD §5.9](./ERD.md). Adding this package now would mean maintaining a second, competing source of truth for the same question. |
| `spatie/laravel-medialibrary` | Post cover images | No image uploads in v1 (non-goal). Adding it introduces storage config, conversions, and queue surface for zero requirement. |
| Meilisearch / Algolia | Search | Operationally heavy for a few hundred posts. Revisit at >5 000 posts. |
| `spatie/laravel-sluggable` | Slugs | Slug generation is ~20 lines in a model mutator and needs custom collision handling anyway. |
| Vue/React | Frontend | Livewire + Alpine already covers every interaction in the PRD. A second rendering model would double the surface for no user-visible gain. |

**Rule:** the dependency set is frozen. Any addition requires approval, as stated in `AGENTS.md`.

### 1.5 AI provider

The editor's AI features are built on **`laravel/ai`**, the official Laravel SDK,
rather than a hand-rolled wrapper over a vendor HTTP client. The SDK supplies
three things that would otherwise be reimplemented: a provider abstraction with
17 drivers, structured output enforced by a JSON schema, and a first-class test
harness (`Ai::fakeAgent()`, `Ai::fakeImages()`).

**Configuration lives in `config/ai.php` plus one `editor` block.** The block
adds `text_provider`, `text_model`, `image_provider`, `image_model`,
`images_enabled`, `max_tokens` and `timeout`, each read from an `AI_EDITOR_*`
environment variable. A model name of `null` means "the provider's own default",
which is deliberate: hardcoding `gpt-4o-mini` would make a DeepSeek or Ollama
deployment fail at request time with a confusing upstream error.

**No key is a supported state.** A fresh checkout has no provider key. That must
not be a 500, so `EditorAiService::isConfigured()` gates every operation,
`GET /editor/ai/capabilities` reports `configured: false`, and the editor hides
its AI rows rather than rendering buttons that fail. Ollama and
`openai-compatible` are treated as valid with only a `url` set — neither takes a
key — which makes a zero-cost local setup a two-line `.env` change.

**Prompt text is treated as code.** The instructions an agent receives are
asserted in tests, because a prompt is the only part of this feature that cannot
be type-checked. Two defects were caught that way: a `{MAX_SUGGESTIONS}`
placeholder that a heredoc rendered as literal text, and a category filter that
the instruction body then contradicted by listing every category anyway.

---

## 2. System Architecture

### 2.1 High-level shape

DevFolio is a **server-rendered monolith with islands of reactivity**. There is no separate API tier, no SPA, and no client-side router.

```
┌──────────────────────────────────────────────────────────────────────┐
│                            Browser                                   │
│  HTML (server-rendered Blade)  +  Livewire JS  +  Alpine  +  CSS     │
└───────────────┬──────────────────────────────────┬───────────────────┘
                │ full document request            │ Livewire XHR
                ▼                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│                         Laravel Application                          │
│                                                                      │
│  routes/web.php ──► Blade views (public)                             │
│                 └─► Route::livewire() ──► Livewire page components   │
│                                                                      │
│  Livewire component  ──►  Action / Service  ──►  Eloquent Model      │
│         │                        │                     │             │
│         │                        │                     ▼             │
│         │                        │              SQLite / MySQL       │
│         │                        ▼                                   │
│         │                   Queue (database)                         │
│         ▼                                                            │
│   Policies / Gates ──► 403 on unauthorised action                    │
└──────────────────────────────────────────────────────────────────────┘
```

### 2.2 Layering rules

The application follows the layering already present in this codebase. New code must match it.

| Layer | Location | May depend on | Must not |
| --- | --- | --- | --- |
| Routes | `routes/*.php` | controllers, Livewire components | contain business logic |
| HTTP | `app/Http/**` | Actions, Models, Requests | query Eloquent directly where an Action exists |
| Livewire | `resources/views/components/**`, `resources/views/pages/**` | Actions, Models, Policies, Support | contain multi-step business logic that belongs in an Action |
| Actions | `app/Actions/**` | Models, other Actions | know about HTTP or Livewire |
| Models | `app/Models/**` | Eloquent, other Models | perform side effects such as sending mail directly |
| Support | `app/Support/**` | Enums, Models | hold state; every class here is static or pure |
| Views | `resources/views/**` | view models, presenters | issue database queries |

`app/Support/**` is a real layer in this codebase, not a leftover: `MarkdownRenderer` (the single rendering entry point), `Seo` (meta/JSON-LD assembly), and `DashboardNavigation` (the declarative sidebar map, filtered by permission at render time). None of them belong in an Action — they compute rather than mutate — and none belong in a model.

**Existing conventions this project already established and that new code must follow:**

1. **Livewire component format is single-file (SFC)** in `resources/views/pages/`, with the ⚡ filename prefix. Class-based components are **not** used. An SFC opens with `<?php ... new class extends Component { ... }; ?>` followed by the Blade template. Reusable Blade components live in `resources/views/components/app/**` as anonymous components addressed with `<x-app.*>` tags. `config/livewire.php` declares the `pages::` and `layouts::` namespaces and adds `views/components` to `component_locations`, so a component placed at the top level of that directory resolves with no namespace prefix at all.
2. **Multi-step domain operations live in Action classes** under `app/Actions/**`, invoked through a public `handle()` method, with dependencies constructor-injected and resolved by the container. See `app/Actions/Posts/UpdatePost.php`.
3. **Authorization uses Gates/Policies**, checked with `Gate::authorize()` at the top of the action that mutates state. Ten policies exist, one per authorised resource; §2.5 lists them.
4. **Models declare configuration with PHP attributes**: `#[Fillable([...])]` and `#[Hidden([...])]`, with casts in a `casts()` method. Do not add `protected $fillable` properties.
5. **Shared behaviour is extracted into concerns** under `app/Concerns/**` (traits). Validation rule sets live there too.
6. **Enums live in `app/Enums/`** with TitleCase keys, and are cast on the model. Enums may carry behaviour (`label()`, `permissions()`, `dashboardRoute()`); they are not bare value lists.
7. **Value objects / DTOs live in `app/Data/`** as readonly classes: `AuthorProfile`, `PostMetrics`.
8. **Fortify response contracts are bound to app classes** in `app/Http/Responses/`, registered in `FortifyServiceProvider`. There is one response per flow: `LoginResponse`, `RegisterResponse`, `TwoFactorLoginResponse`, `PasskeyLoginResponse`, `VerifyEmailResponse`.
9. **Dashboard navigation is declared, not written into the view.** Every sidebar item names the permission it requires, and `DashboardNavigation::for($user)` drops any item the account cannot reach — so the visible menu and the route guards cannot disagree.

### 2.3 Request lifecycle

**Public page (full document):**

1. Route matched in `routes/web.php`.
2. Route middleware runs (`auth`, `verified`, and `EnsureAccountPermission` on the permission-guarded routes).
3. Controller or page component resolves data through a scoped query.
4. Blade renders the document, including `@vite`, `@fluxAppearance`, SEO partials.
5. Browser hydrates Livewire islands.

**Livewire interaction:**

1. Component method invoked from the browser (`wire:click`, `wire:submit`, `wire:model.live`).
2. Livewire rehydrates the component from a signed snapshot.
3. Method validates input and authorises **inside the method**, treating it like an HTTP request.
4. State mutates; the component re-renders; only changed DOM is patched back.
5. Side effects (mail, counters) are dispatched to the queue, not executed inline.

### 2.4 Authentication architecture

Fortify provides the headless flows; this application owns the views and the post-login redirects.

- **Views** are bound in `FortifyServiceProvider::configureViews()` to `resources/views/pages/auth/*`.
- **Redirects** are bound through `app/Http/Responses/*` to the Fortify response contracts. Each response calls `$user->dashboardRoute()`, so an account lands on the dashboard its **role** owns rather than on a fixed path. `config/fortify.php` deliberately carries **no `redirects` map** — only `home`, which no longer decides the destination.
- **Registration** runs through `App\Actions\Fortify\CreateNewUser`, which never sets `role`; the column default makes every new account a `user` (§2.5).
- **Password reset** runs through `App\Actions\Fortify\ResetUserPassword`.
- **Rate limiting** is configured per limiter in `FortifyServiceProvider::configureRateLimiting()`: `login` at 5/min keyed by transliterated email + IP, `two-factor` at 5/min keyed by `login.id`, `passkeys` at 10/min.
- **Two-factor** and **passkey** support already exist on the `User` model via `TwoFactorAuthenticatable` and `PasskeyAuthenticatable`.
- **Login layout** is a single split shell, `layouts/auth/split.blade.php`, delegating through `layouts/auth.blade.php`. It follows the application's theme tokens rather than forcing dark mode, and carries a skip link to the form.

**Post-login destination respects `intended`.** A reader prompted to log in before commenting returns to the post: the `auth` middleware stores the intended URL in the session and `LoginResponse` honours it before falling back to the role's dashboard. The three role dashboards are `dashboard.index` (`/dashboard`), `seller.dashboard` (`/seller/dashboard`), and `admin.dashboard` (`/admin/dashboard`).

### 2.5 Authorisation model

> **Revised.** An earlier draft of this section read: *"There is exactly one privileged role: the post author. Authorisation is expressed as a Policy, not scattered `if` statements."* The policy half is still true. The role half is not: the application now has three account roles.

**One axis, not two.** Authorisation is expressed through a single vocabulary:

| Axis | Question it answers | Backed by | Enforced at |
| --- | --- | --- | --- |
| **Account role** | What may this account do *anywhere* on the platform? | `users.role` → `AccountRole` + `AccountPermission` | Policies, gates, `DashboardNavigation`, route middleware |

An account holds exactly one account role. It is the only role axis in the application, and `AccountRole` + `AccountPermission` are the only authorisation vocabulary: there is no second, narrower scope to reconcile against.

**Account roles, ascending.**

| Role | Level | Plan | Reach |
| --- | --- | --- | --- |
| `user` | 1 | Free | Author and edit **own** posts (publishing is not among the six abilities this role holds); use the Ideas, Developer Lab and AI Hub workspaces. Comment moderation is not a user ability at any level |
| `seller` | 2 | Seller | Everything a user can do, plus publish without review, manage taxonomy, create and sell product listings, view own sales |
| `admin` | 3 | Platform | Every permission, including `admin:access`, `user:manage`, `role:manage`, `product:review`, `sale:view-any`, `refund:process`, `payment:configure`, `audit:view` |

`Seller` **inherits** the user permission set by spreading it, rather than restating it; `Admin` is `AccountPermission::cases()` outright. Inheritance is a deliberate choice — adding a baseline permission automatically reaches both higher roles, so the matrix cannot drift out of sync. [PERMISSIONS.md](./PERMISSIONS.md) publishes the full matrix, generated by executing the enums rather than transcribing them.

**The permission set, exactly.** `AccountPermission` carries **30** cases in five groups.

- **Content** — `post:create`, `post:update-own`, `post:update-any`, `post:delete-own`, `post:delete-any`, `post:publish`.
- **Moderation** — `comment:moderate`, `taxonomy:manage`.
- **Platform** — `dev-lab:use`, `ai-hub:use`, `ideas:use`.
- **Selling** — `marketplace:use`, `product:create`, `product:update-own`, `product:delete-own`, `product:submit`, `sale:view-own`, `support:manage-own`.
- **Administration** — `admin:access`, `user:manage`, `role:manage`, `product:review`, `product:manage-any`, `seller:manage`, `sale:view-any`, `refund:process`, `payment:configure`, `audit:view`, `about:manage`, `ai-hub:manage`.

`AccountRole::User` holds **6** of them (`post:create`, `post:update-own`, `post:delete-own`, `dev-lab:use`, `ai-hub:use`, `ideas:use`); `AccountRole::Seller` holds **15** (the user set plus `post:publish`, `taxonomy:manage`, `marketplace:use`, `product:create`, `product:update-own`, `product:delete-own`, `product:submit`, `sale:view-own`, `support:manage-own`); `AccountRole::Admin` holds all **30**.

**Policies remain the enforcement point.** Ten exist, one per authorised resource:

- `PostPolicy` — `viewAny`, `viewPublic`, `view` (published or owner), `create`, `update`, `delete`, `restore`, `forceDelete`, `publish`. There is no `unpublish` ability: unpublishing is `update` writing a status the model already accepts, which is why the transition needs no second gate. Ownership checks route through `User::canActOn($owner)`, the single rule expressing "the owner, or any admin". Stating it once is what stops the "admins can do anything" clause from being remembered in one policy and forgotten in another.
- `UserPolicy` — account management: `viewAny`, `view`, `update`, `updateRole`, `delete`. Contains **no role names at all**; every ability reduces to an account-level permission, so adding a fourth role later is a change to `AccountRole` and nothing in the policy. `delete` carries the escalation guard — an account may never delete itself through this path (self-deletion is a separate, confirmed flow), and `$account->accountRole()->isAtLeast($user->accountRole())` refuses any target at or above the actor's own level. That single line is why an admin cannot remove another admin.
- `FeedPostPolicy` and `AiArticlePolicy` — the platform-content shape. An AI article is authorised by permission alone: a holder of `ai-hub:manage` may act on any row, because the hub is a curated library and curation is the job. A feed post is ownership *and* the narrower ability (`post:update-own`), with an admin clause ahead of it, because an author's post is theirs until somebody with `admin:access` says otherwise.
- `IdeaPolicy`, `IdeaPlanPolicy`, `IdeaCategoryPolicy`, `LabWorkspacePolicy`, `LabSnippetPolicy` and `IdeaTaskPolicy` — ownership-first, and the opposite shape on purpose. `viewAny` and `create` are the module permission; `update` is ownership with **no admin clause**; `delete` adds `admin:access`, so an operator may take work down without rewriting it. `IdeaTaskPolicy` has no `user_id` to consult and resolves every method through the parent plan, which is what makes it structurally impossible for a task to belong to somebody other than its plan's author.

Per-target role rules are split for a reason worth naming: `updateRole` answers the blanket "may this account edit roles at all", while "may **this** account edit **that** account" depends on the target and lives in the component. Both checks run. Collapsing them into one ability would make the policy answer a question it cannot see the inputs for.

**Non-negotiables that survive from the original draft.**

- Every Livewire mutation calls `Gate::authorize()` before touching the database, mirroring the HTTP layer.
- Draft visibility is enforced in the **query**, not only in the policy, so a policy bypass cannot leak drafts into a listing.
- **Permission middleware composes with OR by default** (`permission:product:create,product:review` grants access if *either* holds). To require **all**, prefix the list with `all:` — `permission:all:product:create,seller:manage`. This is a real trap: a route that reads as "needs both" but omits `all:` silently admits anyone holding one.
- **Guests are redirected, not refused.** `EnsureAccountPermission` sends an unauthenticated request to the home page with `?signin` on it, where the login modal opens, rather than returning 403, because the account may well hold the permission once it authenticates. A 403 there would be a dead end. There is no `/login` page to send them to: login, registration and the forgot-password request are modal-first, and their GET routes redirect to that same flagged home page.
- **An unresolvable permission alias is a 500, not an allow.** A typo like `permission:prodcut:create` fails loudly at the route rather than silently granting access to everyone — the failure mode that matters.

**How an account becomes an admin.** Not through the UI. `AccountRole::assignableBy($actor)` excludes `Admin` from its result *even for an admin actor*, and no policy exposes a promote-to-admin path. Granting admin is a deliberate out-of-band act — currently a seeder or a manual database write. This is recorded as an open finding in [the Phase 0 audit §10](./audit/PHASE-0-AUDIT.md), because a deployment with no documented promotion path is one that cannot be operated. The intended resolution is a documented artisan command, not a UI affordance.

### 2.6 Rendering strategy for content

Markdown is rendered **server-side, once, at write time** for the published representation and cached with the post. Rationale:

- Rendering at read time on every request wastes CPU on content that rarely changes.
- Client-side rendering would ship a markdown parser plus a sanitiser to every reader for no benefit, and would hand attackers a sanitiser bypass surface in the browser.
- Caching the rendered HTML means a stored-XSS defect has exactly one place to be fixed.

The rendered HTML is produced from a sanitised pipeline and treated as trusted **only because** the sanitiser runs on write. Raw user input never reaches a `{!! !!}` sink.

### 2.7 Dashboard shell and role-based navigation

> **Revised.** The original draft had a single dashboard reached at `⚡dashboard.blade.php`. There are now three dashboards, one shell, and one navigation map, each role owning a top-level URL space.

**One shell, three homes.** `components/app/ui/dashboard-shell.blade.php` renders the chrome — sidenav, mobile drawer, top bar, flash region — and the three workspace layouts (`layouts::app.dashboard`, `layouts::seller.dashboard`, `layouts::admin.dashboard`) each compose it with their role's own chrome components. Each dashboard page opts in with `#[Layout('layouts::<audience>.dashboard')]`. That attribute is not optional: `config/livewire.php` sets `component_layout => 'layouts::guest.home'`, so **every SFC defaults to the public shell** and a dashboard page that forgets the attribute renders inside the blog chrome, with a public header and footer around it. This is the single most common mistake in this area of the codebase.

**Roles decide the home, permissions decide the menu.** Each role home is a top-level path whose first segment names the surface — no role dashboard is nested inside another's:

| Role | Path | Route name | Guard | Component |
| --- | --- | --- | --- | --- |
| `user` | `/dashboard` | `dashboard.index` | `auth`, `verified` | `pages/dashboard/⚡index` |
| `seller` | `/seller/dashboard` | `seller.dashboard` | `auth`, `verified`, `permission:product:create` | `pages/seller/⚡dashboard` |
| `admin` | `/admin/dashboard` | `admin.dashboard` | `auth`, `verified`, `permission:admin:access` | `pages/admin/⚡dashboard` |

The authoring screens — `admin.posts.index`, `admin.posts.create`, `admin.posts.edit`, `admin.comments`, `admin.taxonomy` — sit under `/admin` and are guarded by `permission:admin:access`, so a seller or a plain account no longer reaches them. `/dashboard` is a read-only overview, and the old `/dashboard/*` paths 301 to their new locations. Inside those screens the data is still author-scoped: a post list shows the signed-in admin's own posts, because the move changed the address, not the query. An admin-only `/admin/users` (`admin.users`) completes the surface. `AccountRole::dashboardRoute()` returns the route name for a role's home, and every Fortify response calls it; nothing hardcodes `/dashboard`.

**The sidebar is data.** `App\Support\DashboardNavigation::for($user)` returns sections of items, each already filtered to what the account may reach and carrying a resolved badge count. Four properties matter:

1. **Items declare a permission, not a role.** A permission check and a role check would drift; a permission check cannot. The item's `permissions` are evaluated with `hasAnyPermission(...)`.
2. **An empty section is dropped with its heading.** A plain user never sees an "Administration" heading with nothing beneath it.
3. **Badges are resolved in the support class, not the view.** The definition declares *which* badge it wants (`pending-comments`); `for()` fills in the number. The definition therefore carries no live data and can be reasoned about statically.
4. **The pending-comment badge is scoped to the viewer.** It counts comments on the account's own posts unless the account holds `comment:moderate`, so the badge means "work waiting for *you*", not "work waiting for somebody".

**Sections, in order.** `Home`, `Writing`, `Workspaces`, `Manage`, each holding one or
more labelled **groups**: `Overview`; `Content`; `Modules`; `Selling` and
`Administration`. Only the leaf item carries a permission, so filtering is per item
and then a group that emptied out is dropped and then a section that emptied out is
dropped. The **Modules** group sits between Writing and Manage and holds **Ideas**,
**Developer Lab**, **AI Hub**, and **Marketplace** — each a separate top-level URL
space gated by its own permission. A plain user sees Ideas, Developer Lab, and AI Hub
(they hold `dev-lab:use`, `ai-hub:use`, and `ideas:use`) and never Marketplace, which
requires `marketplace:use`; the group heading is dropped for anyone who would see none
of its items. The Writing group — Posts, Comments, Taxonomy — is `admin:access` only,
because those screens live under `/admin`. The Administration group is the same rule,
and it now includes the Ideas and Developer Lab consoles, which is why an ordinary
account's rail cannot contain them: the user and seller sidebars render the same
filtered map with no extra scope, so the permission on the leaf is the only thing
keeping an admin destination off a reader's screen. The role homes appear as "Seller
dashboard" in Selling and "Admin dashboard" in Administration.

**Plans are derived from roles, not stored alongside them.** `AccountRole::plan()` maps `user → Free`, `seller → Seller`, `admin → Platform`. `RolePlan` carries the commercial shape — tagline, feature bullets, `productListingLimit()`, `commissionPercent()`, `canSell()` — separately from the authority that `AccountRole` carries. Today the plan is a pure function of the role; when entitlements become per-account data they move onto the `users` table and the enum becomes the fallback default. The reason the split exists now rather than later is that plan limits should be read *through* the account (`$user->plan()->productListingLimit()`), never inlined as a conditional at a call site.

---

## 3. Architecture Decisions

Each decision is recorded as context → decision → consequence, so a future maintainer can tell whether the reasoning still holds.

### ADR-001 — Server-rendered monolith with Livewire rather than an SPA + API

**Context.** The product needs reactivity in three places: blog filtering, the post editor, and comment moderation. Everything else is a document.

**Decision.** Use Livewire 4 for reactive surfaces and plain Blade for documents. No JSON API, no client router.

**Consequences.**

- ✅ One language, one place for validation and authorisation (`app/`, not duplicated in JS).
- ✅ No API versioning, no auth token handling, no CORS surface.
- ✅ SEO works by default because every page is server-rendered HTML.
- ⚠️ Every interaction is a round-trip. Mitigated by debouncing inputs and keeping payloads small.
- ⚠️ Livewire's snapshot payload grows with public property size. Mitigated by never storing the full post body as a hydrated property where avoidable and by using computed properties for derived data.

### ADR-002 — Single-file Livewire components, matching the existing codebase

**Context.** Livewire 4 supports SFC, MFC, and class-based components. This repository consistently uses SFCs with the ⚡ prefix.

**Decision.** Follow the established convention. All new components are SFCs under `resources/views/components/` (reusable) or `resources/views/pages/` (full-page, registered with `Route::livewire()`).

**Consequences.**

- ✅ Template and behaviour are colocated, which keeps small components readable.
- ✅ Matches every existing component, so no mixed-format confusion.
- ⚠️ Large components embed a lot of PHP in a view file. Mitigated by extracting logic into Actions and Services — an SFC should orchestrate, not compute.
- ⚠️ The ⚡ prefix is not a valid identifier on all filesystems. It is the project default; keep it.

### ADR-003 — Domain operations as Action classes

**Context.** Creating a post, publishing a post, and approving a comment each involve multiple steps and need to be callable from Livewire and from tests.

**Decision.** Put each in a dedicated class under `app/Actions/**` exposing `handle()`, with dependencies injected through the constructor. This matches the existing `App\Actions\Posts\UpdatePost`.

**Consequences.**

- ✅ One call site per operation; testable without HTTP or Livewire.
- ✅ Authorisation and transaction boundaries live in one place.
- ⚠️ Indirection for trivial operations. A single `save()` on a model does not need an Action; do not create ceremony where there is no orchestration.

### ADR-004 — Markdown stored as source, rendered HTML cached

**Context.** Posts must be authored in markdown and displayed as rich HTML with code highlighting.

**Decision.** Store the markdown source in `posts.body`. Store the sanitised rendered HTML in `posts.body_html`, regenerated on save. Store a plain-text projection in `posts.body_text` for search and reading-time calculation.

**Consequences.**

- ✅ Read path is a single column read; no parsing on request.
- ✅ Reading time and search do not need to parse markdown.
- ⚠️ Three representations of one field must stay consistent. Mitigated by regenerating all three inside one Action, inside one transaction.
- ⚠️ `body_html` must never be written from a non-sanitising path.

### ADR-005 — Derived content attributes computed on save, not on read

**Context.** Reading time, word count, excerpt, and slug are all derivable from the body.

**Decision.** Compute them in the saving pipeline and persist them. Allow the author to override `excerpt` and `slug`; never allow overriding the computed word count.

**Consequences.**

- ✅ Listing pages stay cheap — no per-row computation across a paginated set.
- ✅ Computed values are queryable and sortable.
- ⚠️ Changing the algorithm requires a backfill. Acceptable; document any such change in a migration that recomputes existing rows.

### ADR-006 — Scoped public queries, not global scopes

**Context.** Drafts must never appear in public listings, but the author must see them in the dashboard.

**Decision.** Do **not** attach a global scope for published state. Instead expose named query scopes on the model (`published()`, `draft()`, `recent()`) and require every public listing to call `published()` explicitly.

**Consequences.**

- ✅ Dashboard queries are not silently filtered, so "all my posts" genuinely means all.
- ✅ Published-ness is visible at the call site during review.
- ⚠️ A developer could forget `published()`. Mitigated by feature tests asserting drafts are absent from every public surface, and by a `scopePublished` name that reads as a deliberate act.

### ADR-007 — Related posts by tag overlap, then category, with a bounded query

**Context.** Each post should suggest further reading without a recommendation service.

**Decision.** Rank candidates by count of shared tags, break ties by shared category, then by recency. Limit to 3. For SQLite compatibility, compute the ranking in PHP over a bounded candidate set (posts sharing at least one tag or the same category) rather than emitting window functions.

**Consequences.**

- ✅ Deterministic, explainable, testable.
- ✅ No extra infrastructure.
- ⚠️ Candidate set must be bounded, or the query degrades as the corpus grows. Bound it by selecting candidates sharing the category plus the top-N tags, then caps at a hard limit.

### ADR-008 — Autosave over explicit save-only

**Context.** The author writes in bursts and would lose work if a tab closed with unsaved changes.

**Decision.** Debounced autosave for drafts, with an explicit save still available. Publish and unpublish are always explicit actions.

**Consequences.**

- ✅ Draft loss becomes very unlikely.
- ⚠️ Write amplification. Mitigated by debouncing and by skipping the write when content is unchanged.
- ⚠️ An autosave racing a manual save could clobber. Mitigated by a single in-flight lock and a monotonic timestamp check before applying a save.
- **Draft only.** Autosave never publishes. Publishing is a deliberate, confirmed transition.

### ADR-009 — Design tokens in CSS, no component-level magic numbers

**Context.** A unique visual identity must be consistent across dozens of templates.

**Decision.** Every colour, font, radius, shadow, and duration used by product code is declared as a custom property in `resources/css/app.css` under `@theme`, and templates reference the token rather than a literal.

**Consequences.**

- ✅ Retheming is a single-file change.
- ✅ Dark mode is expressed by redefining tokens, not by sprinkling `dark:` variants on every element (though `dark:` is still used for one-off adjustments).
- ⚠️ Requires discipline in review: a raw hex value in a template is a defect.

### ADR-010 — Slug uniqueness enforced at the database level

**Context.** Slugs appear in public URLs and must be stable and unique.

**Decision.** `posts.slug` carries a unique index. The slug generator applies an incrementing suffix on collision. Existing post slugs are never regenerated when the title changes, unless the author explicitly requests it, because published URLs must not break.

**Consequences.**

- ✅ No duplicate URLs, no race-condition-induced collisions survive a write.
- ✅ Published links stay valid after a retitle.
- ⚠️ A misleading slug (title changed, slug stale) is possible by design. The editor exposes a manual override.

### ADR-011 — Comments are moderated by default

**Context.** A public comment form on a technically-focused blog attracts spam.

**Decision.** New comments default to `pending`. Only `approved` comments render publicly. Approval is a single-click author action from the dashboard.

**Consequences.**

- ✅ Spam never appears publicly without a deliberate act.
- ✅ The author's own replies can be auto-approved.
- ⚠️ A delay between submission and visibility. The UI communicates this state explicitly to the commenter so it does not read as a failure.

### ADR-012 — Soft deletes for posts, hard deletes for comments

**Context.** Deleting a published post destroys a URL that may be linked externally. Deleting a spam comment has no archival value.

**Decision.** Posts use `SoftDeletes`. Comments delete permanently.

**Consequences.**

- ✅ Accidental post deletion is recoverable, and published URLs can be restored.
- ⚠️ Every post query must consider trashed rows. The default Eloquent behaviour excludes them, which is correct for all product queries.

---

## 4. Implementation Approach

### 4.1 Directory layout

The tree below is the **actual** repository layout. `(existing)` marks what came from the Laravel/Livewire starter kit; everything else is this project's own.

```
app/
├── Actions/
│   ├── About/                         item and section writes
│   ├── AiHub/                         19 classes: article, topic, path and resource
│   │                                  writes, publication and shelf ordering
│   ├── Comments/                      ApproveComment, RejectComment
│   ├── Communities/                   membership and community writes
│   ├── DevLab/                        SaveLabWorkspace, DeleteLabWorkspace,
│   │                                  SaveLabSnippet, DeleteLabSnippet,
│   │                                  RecordLabExecution, SyncLabSnippetTopics,
│   │                                  CloneSnippetToWorkspace
│   ├── Feed/                          post and attachment writes
│   ├── Fortify/                       (existing) CreateNewUser, ResetUserPassword, …
│   ├── Ideas/                         SaveIdea, DeleteIdea, ToggleIdeaPin,
│   │                                  SyncIdeaVisibility, ConvertIdeaToPlan,
│   │                                  SaveIdeaCategory, DeleteIdeaCategory,
│   │                                  SaveIdeaPlan, DeleteIdeaPlan,
│   │                                  SaveIdeaTask, DeleteIdeaTask,
│   │                                  ReorderIdeaTasks, SyncIdeaTopics
│   ├── Marketplace/                   product, version and review writes
│   └── Posts/                         CreatePost, UpdatePost, DeletePost, ArchivePost,
│                                      PublishPost, UnpublishPost, SyncPostTags,
│                                      HydratePostContent, ResolveRelatedPosts,
│                                      SyncTaxonomyCounts
├── Concerns/                          HasAccountRole, HasReadingTime, HasSlug,
│                                      ResolvesUniqueSlug, PasswordValidationRules,
│                                      ProfileValidationRules
├── Console/                           (existing)
├── Data/                              AuthorProfile, PostMetrics, TextCorrection
├── Enums/                             thirty enum files. The authorisation
│                                      set (AccountPermission with 30 cases,
│                                      AccountRole, PermissionGroup, RolePlan) plus
│                                      the content set (PostStatus, CommentStatus);
│                                      the module set
│                                      (IdeaStatus, PlanStatus, TaskStatus, WorkPriority,
│                                      IdeaPotential, ContentVisibility, LabLanguage);
│                                      the feed, hub, marketplace, about and community
│                                      sets; and the editor's vocabulary
│                                      (ContentCategory, ContentTone, CorrectionType)
├── Ai/Agents/                         ContentWriterAgent, CopyEditorAgent,
│                                      ImagePromptAgent (the AI SDK's structured agents,
│                                      used by the editor and the hub, never by a route
│                                      that trusts a browser-supplied prompt)
├── Http/
│   ├── Controllers/                   (existing) Controller
│   │                                  FeedController, RobotsController, SitemapController,
│   │                                  ErrorPageController, FeedAttachmentController,
│   │                                  Editor/
│   ├── Middleware/                    (existing) EnsureAccountPermission
│   ├── Requests/                      Editor/ (CorrectionRequest, GenerationRequest,
│   │                                  ImageGenerationRequest, ImagePromptRequest,
│   │                                  PreviewRequest)
│   └── Responses/                     (existing) LoginResponse, RegisterResponse,
│                                      TwoFactorLoginResponse, PasskeyLoginResponse,
│                                      VerifyEmailResponse
├── Livewire/Actions/                  Logout
├── Observers/                         PostObserver (keeps derived post counts in step
│                                      with the rows, so a screen never computes them)
├── Models/                            (existing) User
│                                      Category, Comment, Post, PostTag, Tag,
│                                      FeedPost, FeedAttachment, Community,
│                                      CommunityMembership, AboutItem, AboutSection,
│                                      AiArticle, AiArticleLink, AiTopic, AiResource,
│                                      AiLearningPath, AiLearningPathItem,
│                                      MarketplaceCategory, MarketplaceProduct,
│                                      MarketplaceProductVersion, MarketplaceReview,
│                                      Idea, IdeaPlan, IdeaTask, IdeaCategory,
│                                      LabWorkspace, LabSnippet, LabExecution,
│                                      Topic (the platform taxonomy the two public
│                                      galleries facet on — flat, unlike AiTopic,
│                                      which is the hub's own hierarchical tree)
├── Policies/                          PostPolicy, UserPolicy, FeedPostPolicy,
│                                      AiArticlePolicy, IdeaPolicy, IdeaPlanPolicy,
│                                      IdeaTaskPolicy, IdeaCategoryPolicy,
│                                      LabWorkspacePolicy, LabSnippetPolicy
├── Providers/                         (existing)
├── Rules/                             SlugRule (the shared constraints), PostSlug,
│                                      IdeaSlug, PlanSlug, SnippetSlug
├── Services/                          AboutContent and AiHubContent (the copy the two
│                                      consoles read when their tables are empty),
│                                      EditorAiService and EditorAiException (the AI
│                                      endpoints' only path to an agent),
│                                      PublicTaxonomy (the count gate every public
│                                      facet, chip and `/topics` card reads, so a
│                                      screen can never advertise a subject whose
│                                      rows the published() scope would hide)
└── Support/                           DashboardNavigation, SettingsNavigation,
                                       MarkdownRenderer, Seo, HttpErrorCatalog,
                                       FeedCorpus, FeedPersonas

database/
├── factories/                         (existing) UserFactory
│                                      ★ CategoryFactory, CommentFactory, PostFactory, TagFactory
│                                      and the per-module factories for every model above,
│                                      including IdeaFactory and LabSnippetFactory, both of
│                                      which carry an `unpublishedPublic()` state — the flag
│                                      without the timestamp — because that combination is
│                                      the one a reader would otherwise never see,
│                                      and TopicFactory for the taxonomy parent — the
│                                      two families attach topics through their sync
│                                      action rather than a factory state, so a test
│                                      proves the same path the screen uses
├── migrations/                        (existing) 3 baseline + passkeys + 2FA
│                                      ★ categories, tags, posts, post_tag, comments,
│                                        add_role_to_users_table, and the per-module sets
│                                        through 2026_10_20_100009: seven tables for Ideas
│                                        and the Developer Lab (idea_categories, ideas,
│                                        idea_plans, idea_tasks, lab_workspaces,
│                                        lab_snippets, lab_executions) plus the taxonomy
│                                        three (topics, idea_topic, lab_snippet_topic —
│                                        the platform-owned parent and its two pivots).
│                                        41 in total.
└── seeders/                           ★ UserSeeder first, then CategorySeeder,
                                         TagSeeder, PostSeeder, CommentSeeder, and the
                                         module seeders. TopicsSeeder sits before
                                         IdeaCategorySeeder and LabSnippetSeeder so the
                                         two can file their rows by slug. IdeaCategorySeeder,
                                         TopicsSeeder and LabSnippetSeeder are idempotent —
                                         they resolve by slug and hand the existing model to
                                         the write action rather than inserting a second row
                                         — because a re-run of `db:seed` must not
                                         duplicate the starter content a fresh account is
                                         pointed at.

resources/
├── css/
│   └── app.css                        design tokens under @theme plus the public layer
├── js/
│   └── app.js                         (existing)
└── views/
    ├── components/
    │   └── app/                       every component, namespaced under app/
    │       ├── home/                  guest chrome — header, footer
    │       ├── ui/                    shared primitives: header, body, section,
    │       │                          stat, nav, modal/{actions,trigger},
    │       │                          auth/*, post/*, editor/rich-text,
    │       │                          dashboard-shell, sidenav-footer, topbar, …
    │       ├── icon/                  logo-mark, share
    │       ├── ideas/                 card, status-badge
    │       ├── dev-lab/               overview, capabilities, capability, status,
    │       │                          access, code-pane, snippet-card (the one row
    │       │                          shape, shared by the gallery, the landing's
    │       │                          featured strip and a topic page), showcase
    │       │                          (the landing's live sandbox pane, driven only
    │       │                          by the bundled example)
    │       ├── ai-hub/                article-card, path-card, topic-card,
    │       │                          resource-card, pill
    │       ├── marketplace/           product-card, rating, install-command,
    │       │                          product-status, review-card
    │       └── user|seller|admin/     each role's header, footer, sidebar
    ├── layouts/                       guest/home (the default), auth + auth/split,
    │                                  app/dashboard, seller/dashboard,
    │                                  admin/dashboard
    ├── pages/
    │   ├── auth/                      reset-password, confirm-password,
    │   │                              two-factor-challenge, verify-email — the four
    │   │                              mid-flow screens. Login, register and
    │   │                              forgot-password are modals on the home page,
    │   │                              and their GET routes redirect there
    │   ├── blog/                      ⚡index, ⚡show
    │   ├── dashboard/                 ⚡index
    │   ├── admin/                     ⚡dashboard, ⚡users, ⚡comments, ⚡taxonomy,
    │   │                              ⚡about, ⚡feed, ⚡ai-hub, ⚡marketplace,
    │   │                              ⚡ideas, ⚡dev-lab, posts/(⚡index, ⚡edit)
    │   ├── seller/                    ⚡dashboard
    │   ├── ideas/                     ⚡index, ⚡show, ⚡dashboard, ⚡edit,
    │   │                              ⚡categories, ⚡plans, ⚡plan, ⚡plan-edit
    │   ├── dev-lab/                   ⚡index, ⚡gallery, ⚡snippet-show, ⚡dashboard,
    │   │                              ⚡sandbox, ⚡snippets, ⚡snippet, ⚡history
    │   ├── ai-hub/                    ⚡index, ⚡articles, ⚡article, ⚡topics,
    │   │                              ⚡topic, ⚡paths, ⚡path, ⚡resources,
    │   │                              ⚡ideas and ⚡dev-lab (the hub's two shelves,
    │   │                              still placeholders: they want a curation model)
    │   ├── marketplace/               ⚡index, ⚡show
    │   ├── communities/               ⚡index
    │   ├── feed/                      ⚡show, ⚡composer-modal
    │   ├── settings/                  layout.blade.php, two-factor/,
    │   │                              ⚡profile, ⚡security, ⚡appearance,
    │   │                              ⚡delete-user-form, ⚡delete-user-modal,
    │   │                              ⚡two-factor-setup-modal
    │   ├── ⚡about.blade.php
    │   ├── ⚡feed.blade.php           the signed-in feed surface, at `/feed`
    │   ├── ⚡home.blade.php
    │   ├── ⚡topics.blade.php         the platform taxonomy, every count-gated subject
    │   │                              with both families' published counts on it
    │   └── ⚡topic.blade.php          one subject: its ideas, then its snippets
    └── partials/                      head, theme-boot

routes/
├── console.php                        (existing)
├── settings.php                       account settings + passkey endpoints
└── web.php                            public + role dashboards + modules + redirects

tests/
├── Browser/                           Dusk: ComposerPublishTest, FeedReducedMotionTest,
│                                      IdeasReadingTest, LabShowcaseTest, Pages/, plus
│                                      DuskTestCase at the top level
├── Feature/
│   ├── Admin/                         IdeasConsoleTest, DevLabConsoleTest
│   ├── AiHub/                         admin console, public render, seeding,
│   │                                  curated shelves, mock delegation
│   ├── Auth/                          layout, authentication, registration,
│   │                                  password reset and confirmation, verification,
│   │                                  two-factor challenge, the login modal
│   ├── Blog/                          IndexTest, ShowTest, CommentTest, SharingTest
│   ├── Dashboard/                     Composition, Navigation, Shell,
│   │                                  PostManagement, Moderation, Taxonomy
│   ├── DevLab/                        SandboxPage, SandboxShowcase, SnippetCard,
│   │                                  SnippetCrud, Workspace, GalleryFacets,
│   │                                  CloneSnippet, LabSnippetSeeder, DevLabMock
│   ├── Editor/                        EditorAiTest, TextCorrectionTest
│   ├── Enums/                         PermissionModelTest
│   ├── Errors/                        ErrorPagesTest
│   ├── Ideas/                         IdeaCrud, IdeaVisibility, StatusWorkflow,
│   │                                  IdeaConversion, PlanTask, Category,
│   │                                  IndexFacets, IdeaTopics, IdeaShowReading,
│   │                                  IdeasMock
│   ├── Marketplace/                   catalogue, listing page, filters, admin,
│   │                                  review submission, seeding, schema, mock
│   ├── Routing/                       RoutingStructureTest
│   ├── Settings/                      ProfileUpdateTest, SecurityTest
│   ├── Theme/                         ThemeSystemTest
│   ├── About*                         eight files, one per section of `/about`
│   ├── Feed*                          FeedTest, FeedShowTest, FeedModelTest,
│   │                                  FeedPublishingTest, FeedMediaTest
│   ├── AdminFeedTest.php              the moderation queue
│   ├── DashboardTest.php              the read-only user overview
│   ├── DevLabLandingTest.php          the public `/dev-lab` landing
│   ├── ExampleTest.php                the home page responds
│   ├── PublicTaxonomyTest.php         the count gate every public facet reads
│   ├── StructuredDataTest.php         the JSON-LD graph and its escaping
│   └── TopicsPagesTest.php            /topics and /topics/{subject}
└── Unit/                              IdeaTest, LabLanguageTest,
                                       FeedPostVisibilityTest, Marketplace/*
```

**Two notes a reader will otherwise trip over.** First, `app/Livewire/` holds one class and it is not a component: `app/Livewire/Actions/Logout.php`. Livewire components are SFCs under `resources/views`, and the framework locates them through `config/livewire.php`, not by convention. Second, `resources/views/pages/dashboard/` holds the user role's read-only overview (`⚡index`), while every screen under `/admin` lives in `pages/admin/` — `⚡dashboard`, `⚡comments`, `⚡taxonomy`, `⚡feed`, `⚡about`, `⚡ai-hub`, `⚡marketplace`, `⚡users`, the two module consoles `⚡ideas` and `⚡dev-lab`, and the post screens in `posts/` (`⚡index`, `⚡edit`) — matching their URL space; the seller home sits in `pages/seller/`. A third thing to trip over is naming: `pages/ai-hub/⚡ideas.blade.php` and `pages/ai-hub/⚡dev-lab.blade.php` are the hub's **curated shelves** at `/ai-hub/ideas` and `/ai-hub/dev-lab` (routes `ai-hub.ideas.index` and
`ai-hub.dev-lab.index`, both behind `auth`, `verified` and `ai-hub:manage`), not the
modules, which live in `pages/ideas/` and `pages/dev-lab/` under their own URL spaces. The shelves remain placeholders because a shelf needs a hub-side curation object that decides which Ideas and Lab rows the hub recommends, and that model is separate from either module's content.

### 4.2 Naming conventions

| Artifact | Convention | Example |
| --- | --- | --- |
| Livewire page component | `resources/views/pages/<area>/⚡<name>.blade.php`, registered via `Route::livewire('uri', 'pages::<area>.<name>')` | `pages/blog/⚡index.blade.php` → `'pages::blog.index'` |
| Livewire UI component | `resources/views/components/app/**/⚡<name>.blade.php` | none in this project — reusable UI is anonymous Blade, addressed `<x-app.ui.*>` |
| Blade component | kebab-case under `components/app/<audience>/`, referenced with `<x-app.<audience>.<name>>` | `components/app/ui/post/card.blade.php` → `<x-app.ui.post.card :post="$post" />` |
| Layout | `layouts/<audience>/<variant>.blade.php` | `layouts/app/dashboard.blade.php`, `layouts/auth/split.blade.php` |
| Action | verb + noun, `handle()` entry point | `App\Actions\Posts\PublishPost` |
| Enum | singular noun, TitleCase cases, behaviour on the enum | `App\Enums\PostStatus::Published` |
| Support class | noun describing the computation | `App\Support\MarkdownRenderer` |
| Route name | dot-namespaced | `blog.index`, `admin.posts.edit`, `admin.users` |
| Migration | anonymous class, snake_case filename | `2026_10_12_080204_create_posts_table.php` |
| Test | mirrors the namespace it covers | `tests/Feature/Enums/PermissionModelTest.php` |

**Testing SFCs by name.** An SFC is an anonymous class, so `::class` cannot address it. Reference the component by its **string name** in tests — `Livewire::test('pages::blog.show', ['post' => $post])` — never by class constant.

### 4.3 Data flow for the primary write path (saving a post)

```
Editor (Livewire SFC: pages/admin/posts/⚡edit, `pages::admin.posts.edit`)
  │  wire:model.live.debounce.800ms on body
  │  wire:submit / autosave tick
  ▼
Component method save()
  ├── $this->validate([...])                 ← validation rules as arrays
  ├── Gate::authorize('update', $post)
  └── app(UpdatePost::class)->handle($post, $data)
        │
        └── DB::transaction
              ├── derive slug (if title changed and no override)
              ├── derive excerpt (if blank)
              ├── MarkdownRenderer::render($body)   → sanitised HTML
              ├── derive word count + reading time from plain text
              ├── $post->fill(...)->save()
              └── $post->tags()->sync($tagIds)
  ▼
Component emits toast + refreshes save-state indicator
```

Key properties of this flow: validation runs before authorisation-independent side effects; authorisation runs before any write; all writes for one logical operation share a transaction; derivation happens in one place.

### 4.4 Data flow for the primary read path (rendering a post)

```
GET /blog/{post:slug}
  │
  ▼
Route model binding (Post, with category + tags + approved comments eager-loaded)
  │
  ├── PostPolicy@view  → abort(404) if draft and not owner
  │
  ▼
pages/blog/⚡show renders:
  ├── header      → title, category, tags, date, reading time
  ├── {!! body_html !!}       ← pre-sanitised, cached at write time
  ├── TOC         → parsed from body_html headings server-side
  ├── share-bar   → server-generated intent URLs
  ├── author card → from AuthorProfile data object
  ├── comments    → approved only, newest-first or threaded
  └── related     → bounded tag/category overlap query
  │
  ▼
partials/seo  → canonical, OG, Twitter Card, JSON-LD Article
```

Eager loading is mandatory on this path: `category`, `tags`, and `comments.author`. N+1 queries here are a defect, not a performance nicety.

### 4.5 Error handling

| Condition | Behaviour |
| --- | --- |
| Post not found, or draft requested by a non-owner | `404` — never `403`, so drafts are indistinguishable from non-existent URLs to anonymous users. |
| Authenticated non-owner attempts a mutation | `403` via `Gate::authorize()`. |
| Validation failure | Livewire renders field-level errors; the form retains all input. |
| Slug collision | Suffix applied transparently; no user-facing error. |
| Markdown sanitisation strips content | Rendered output reflects the sanitised result; the author sees the sanitised preview before publishing. |
| Queue job failure | Logged; the user-facing action that enqueued it has already succeeded. |

### 4.6 Performance approach

| Lever | Implementation |
| --- | --- |
| Eager loading | `with()` on every listing and detail query. Asserted by tests where N+1 is plausible. |
| Pagination | Cursor-free simple pagination is unnecessary; standard pagination at 9 rows keeps payloads small. |
| Rendered content | `body_html` cached at write time (ADR-004). |
| Counts | Category and tag counts cached, invalidated when a post transitions to or from published. |
| Autosave | Debounced, no-op writes skipped (ADR-008). |
| View counting | Incremented on the queue, not in the request. |
| Assets | Single Vite entry per bundle; CSS is one file; no blocking third-party scripts. |
| Fonts | Bunny Fonts, `@fonts` directive, `font-display: swap`. |
| Images | No uploaded imagery in v1; decorative graphics are inline SVG, which needs no network request. |

### 4.7 Security approach

| Threat | Mitigation |
| --- | --- |
| Stored XSS via markdown | Allow-list sanitiser on write; raw HTML never reaches a `{!! !!}` sink from user input. Test asserts `<script>` is stripped. |
| CSRF | Laravel's default token middleware; Livewire requests are signed. |
| Broken authorisation | `Gate::authorize()` in every mutating Livewire method and Action; drafts additionally excluded at query level. |
| Mass assignment | `#[Fillable]` attributes on every model; never `Model::create($request->all())`. |
| Enumeration of drafts | `404` not `403` for non-owners. |
| Brute force | Fortify rate limiters, already configured. |
| Comment spam | Moderation by default; honeypot field; authenticated-only commenting. |
| Secrets | `.env` only; `.env` is git-ignored. Never inline a secret in a template or a share URL. |
| Improper escaping in share URLs | Intent URLs are built server-side with `urlencode`; no client-side concatenation of user input. |

### 4.8 Testing approach

Framework: **Pest 5**, database-resetting feature tests by default (`tests/Pest.php` applies `RefreshDatabase` to `tests/Feature`).

| Layer | What is tested | Example |
| --- | --- | --- |
| Feature (HTTP) | Public pages render, drafts 404, SEO tags present | `tests/Feature/Blog/IndexTest.php`, `tests/Feature/Blog/ShowTest.php` |
| Feature (Livewire) | Filtering, search, pagination, autosave, authorisation | `tests/Feature/Blog/IndexTest.php`, `tests/Feature/Dashboard/PostManagementTest.php` |
| Feature (Livewire) | Post CRUD, status transitions, delete confirmation | `tests/Feature/Dashboard/PostManagementTest.php` |
| Feature (Livewire) | Comment submission, moderation gate, validation | `tests/Feature/Blog/CommentTest.php` |
| Unit | Reading time, slug generation, markdown sanitisation | Reading time is asserted in `tests/Feature/AiHub/AiHubSeedingTest.php`, slug derivation and collision handling in the Ideas, Marketplace and About suites. `app\Support\MarkdownRenderer` — the sanitiser that stands between authored markdown and `{!! !!}` — has **no test of its own**: it is exercised only as a side effect of the render suites. Recorded as a gap rather than claimed as coverage |

Rules applied: use factories (never hand-built models); assert observable behaviour rather than implementation detail; cover each authorisation branch; leave framework behaviour to the framework.

### 4.9 Quality gates

Run before considering any change complete:

```bash
vendor/bin/pint --dirty --format agent     # formatting
composer types:check                       # PHPStan / Larastan
php artisan test --compact                 # full suite
npm run build                              # frontend bundle
```

Per `AGENTS.md`, only PHP files that were modified are formatted (`--dirty`), and the complete suite is run by the user after the targeted tests pass.

---

## 5. Non-Functional Requirements

| ID | Requirement | Target |
| --- | --- | --- |
| NFR-1 | Public page response time (p95, cached content) | < 200 ms server-side |
| NFR-2 | Livewire round-trip (p95) | < 300 ms for filter and search interactions |
| NFR-3 | Time to interactive (mobile 4G) | < 3.5 s |
| NFR-4 | Accessibility | WCAG 2.2 AA; zero axe-core violations on public pages. Every modal is reachable and dismissible by keyboard; every icon-only control has an accessible name |
| NFR-5 | Browser support | Last two versions of Chrome, Firefox, Safari, Edge; Safari iOS 16+; Chrome Android |
| NFR-6 | Responsive range | 360 px → 2560 px, no horizontal overflow at any width. The dashboard sidebar collapses below `lg` |
| NFR-7 | Data portability | All queries run unchanged on SQLite, MySQL 8, and PostgreSQL 15 |
| NFR-8 | Backup safety | No destructive command runs outside local; `DB::prohibitDestructiveCommands` is active in production |
| NFR-9 | Deployability | Runs on Laravel Cloud without modification; `composer setup` produces a working app from a clean clone |
| NFR-10 | Log hygiene | No personally identifying data in logs beyond the standard authentication records |
| NFR-11 | Theme fidelity | No layout, page, or component may force a colour scheme. Light and dark are both first-class; a hardcoded `class="dark"` on a shell is a defect, asserted by test |
| NFR-12 | Authorisation correctness | An account must never see a navigation entry whose route would refuse it. Asserted by test over the navigation map rather than reviewed by eye |
| NFR-13 | Static analysis | PHPStan level 7 clean, including enums and policies. A `level()` or permission-set regression must fail analysis or a test, not reach review |

NFR-11 through NFR-13 were added after the fact, each because the corresponding invariant had already been violated once: a forced-dark auth layout (NFR-11), a hand-maintained sidebar array that could disagree with the route guards (NFR-12), and a permission matrix described in prose that had drifted from the enums (NFR-13). An invariant that has been broken once gets a test the second time.

---

## 6. Deployment

The application targets **Laravel Cloud**, which is the fastest path to a scaled deployment for this stack.

- Build: `composer setup` (install, key generate, migrate, npm install, npm run build).
- Queue worker: required, because autosave-adjacent jobs, view counting, and notifications are queued. Cloud provisions this as a resource.
- Database: SQLite is development-only (`.env.example` ships `DB_CONNECTION=sqlite`). Production uses a managed MySQL or PostgreSQL instance; **no application code may depend on SQLite behaviour** (see ADR-007 for the one place this rule constrained a design).
- Session, cache, and queue all default to the `database` driver in `.env.example`, which is what makes multi-instance deployment work without sticky sessions — and also what makes a fresh database mandatory before the app is usable.
- Assets: built by Vite at deploy time; `vite manifest not found` means `npm run build` was skipped.
- Environment: `APP_ENV=production` activates stricter password rules (min 12, mixed case, numbers, symbols, uncompromised) via `AppServiceProvider::configureDefaults()`.
- **Seeding is not part of `composer setup`.** The script runs `migrate --force` **without** `--seed`, so a deployed environment has tables and no rows — including no administrator. The first admin must therefore be created deliberately. This is the operational half of the gap described in §2.5, and it is the reason a documented bootstrap path is a release blocker rather than a nicety.

### 6.1 Pre-release checklist

```bash
bash /tmp/phpx.sh artisan test                        # full suite
bash /tmp/phpx.sh vendor/bin/pint --dirty --format agent
bash /tmp/phpx.sh -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
npm run build
```

`composer test` bundles four steps: `artisan config:clear`, then `lint:check` (Pint in **check** mode across the tree, not `--dirty`), then `types:check` (PHPStan), then `artisan test`. Running the suite directly skips the first three, so a green `artisan test` alone does not mean the change passes CI.

**PHPStan needs `-d memory_limit=1G` on this machine** or it aborts partway through. Configured at **level 7** (`phpstan.neon`) across `app/`, `bootstrap/app.php`, `config/`, `database/`, and `routes/`. Launch it through the PHP wrapper, since `php` is not on `PATH`:

```bash
bash /tmp/phpx.sh -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
```

A memory-starved run can report a partial analysis that looks clean, which is worse than a visible failure. Do not treat a truncated run as a pass.

---

## 7. Traceability Matrix

Every PRD requirement mapped to the code that realises it and the test that pins it.

| PRD requirement | Technical realisation | Test surface |
| --- | --- | --- |
| FR-1.x | `resources/views/pages/⚡home.blade.php` + `AuthorProfile` data object | `tests/Feature/ExampleTest.php` (the page responds), `tests/Feature/Theme/ThemeSystemTest.php` |
| FR-2.1–2.9 | `pages/blog/⚡index.blade.php`, `Post::scopePublished`, `scopeSearch` | `tests/Feature/Blog/IndexTest.php` |
| FR-3.1–3.10 | `MarkdownRenderer`, `pages/blog/⚡show.blade.php`, related-post query | `tests/Feature/Blog/ShowTest.php` |
| FR-4.x | Fortify + `pages/admin/posts/*`, `pages/dashboard/⚡index`, `Actions/Posts/*`, `PostPolicy` | `tests/Feature/Dashboard/*` |
| FR-4.1a / 4.1b | `AccountRole`, `AccountPermission`, `HasAccountRole`, `UserPolicy` | `tests/Feature/Enums/PermissionModelTest.php` |
| FR-4.2a–4.2c | `layout('layouts::{app,seller,admin}.dashboard')`, `DashboardNavigation`, `components/app/ui/dashboard-shell.blade.php`, role-scoped sidebars | `PermissionModelTest`, `ShellTest`, dashboard feature tests |
| FR-4.4a | `AccountRole::dashboardRoute()` consumed by every Fortify response | `tests/Feature/Auth/*` |
| FR-4.11a | `EnsureAccountPermission` route middleware + declared nav permissions | `PermissionModelTest` (composition, not size) |
| FR-5.x | `components/app/ui/post/share-bar.blade.php`, `Support\Seo` | `tests/Feature/Blog/SharingTest.php` |
| FR-6.x | `resources/css/app.css` tokens, `layouts/guest/home.blade.php` | Manual audit + Lighthouse |
| FR-7.1 | `pages/ideas/*` (eight screens), `Actions/Ideas/*` (thirteen), `IdeaPolicy` / `IdeaPlanPolicy` / `IdeaTaskPolicy` / `IdeaCategoryPolicy`, `IdeaSlug` / `PlanSlug` | `tests/Feature/Ideas/*` (ten files), `tests/Unit/IdeaTest.php`, `tests/Feature/Admin/IdeasConsoleTest.php` |
| FR-7.2 | `pages/dev-lab/*`, `Actions/DevLab/*` (seven), `LabLanguage::isBrowserRunnable()`, `LabWorkspacePolicy` / `LabSnippetPolicy`, `SnippetSlug` | `tests/Feature/DevLab/*` (nine files), `tests/Unit/LabLanguageTest.php`, `tests/Feature/Admin/DevLabConsoleTest.php`, `tests/Feature/DevLabLandingTest.php` |
| FR-7.1 + FR-7.2, the surface a stranger reads | `Models/Topic` and its two pivots, `Services/PublicTaxonomy` (the count gate), `pages/⚡topics` + `⚡topic`, `app/dev-lab/snippet-card`, `app/ui/post/share-bar`, `Support\Seo::$jsonLd`, `partials/head.blade.php` | `PublicTaxonomyTest`, `TopicsPagesTest`, `Ideas/IndexFacetsTest`, `DevLab/GalleryFacetsTest`, `Ideas/IdeaTopicsTest`, `Ideas/IdeaShowReadingTest`, `DevLab/SnippetCardTest`, `DevLab/SandboxShowcaseTest`, `DevLab/CloneSnippetTest`, `StructuredDataTest`, plus `Browser/IdeasReadingTest` and `Browser/LabShowcaseTest` for the listeners and the frame only a browser can reach |
| FR-7.3 | `pages/ai-hub/*` (ten screens), `Actions/AiHub/*` (nineteen), `AiArticlePolicy` | `tests/Feature/AiHub/*`, including `AiHubCuratedShelvesTest.php` for the two shelves that remain placeholders |
| FR-7.4 | `MarketplaceProduct` and its three companions, `pages/marketplace/*`, `Actions/Marketplace/*`, `pages/admin/⚡marketplace` | `tests/Feature/Marketplace/*` |
| NFR-4 | Semantic landmarks, focus management, contrast tokens | axe-core manual run |
| NFR-7 | Portable Eloquent; no raw SQL | Code review |
| NFR-11 | Auth layout token-driven, no forced scheme | `tests/Feature/Auth/AuthLayoutTest.php` |
| NFR-12 | Nav entries declare permissions; empty sections dropped | `PermissionModelTest` |
| NFR-13 | PHPStan level 7 clean | `composer types:check` |

### 7.1 Where the code and the plan disagree

A traceability matrix that only lists successes is a marketing document. These are the known gaps, all recorded in [the Phase 0 audit §10](./audit/PHASE-0-AUDIT.md):

| Gap | Effect | Status |
| --- | --- | --- |
| No permission covers comment **creation** (`comment:create` does not exist) | Commenting cannot be gated by permission; every authenticated account can comment | Open — needs an ability in `AccountPermission` |
| No permission covers deleting **own** comment (`comment:delete-own` does not exist) | A reader cannot withdraw their own comment | Open |
| No profile-level ability | Profile editing is authorised by ownership only | Accepted — ownership is the correct rule here |
| Nothing promotes an account to `admin` | A fresh deployment has no administrator and no documented path to one | **Release blocker**; resolvable by a documented artisan command |
| Five selling abilities have no surface (`sale:view-own`, `sale:view-any`, `refund:process`, `payment:configure`, `support:manage-own`) | `seller` and `admin` hold permissions with nothing behind them | Expected — the sales ledger is deferred (§7.2). The listings half of the enum is implemented: `product:*` abilities guard the marketplace, its seller screens and its moderation console |

### 7.2 Deferred by decision, not by omission

All four product modules named in the first draft of this document are now part of
the delivered system: the AI Knowledge Hub and the Developer Marketplace were built
ahead of this pass, and the Ideas workspace and the Developer Lab were built in it.
Each has real tables, real screens behind its `AccountPermission`, and its own
`DashboardNavigation` entry. What is deferred is no longer a module; it is a
capability *inside* a module, and each one is listed with the reason it was withheld
rather than half-built.

| Deferred | Module | Why it is a decision |
| --- | --- | --- |
| Server-side execution of any language | Developer Lab | The sandbox runs HTML/CSS/JS in the visitor's own browser with an opaque origin. A real PHP runner needs a container, a CPU and memory quota, and a queue — none of which this pass was authorised to add. `LabLanguage::isBrowserRunnable()` is the single method that encodes the boundary, and it is checked server-side as well as in the UI. |
| Challenges, submissions, grading | Developer Lab | Grading in the browser is advisory. Shipping it would promise a judge that does not exist. |
| Syntax highlighting and step-through debugging in the editor | Developer Lab | Highlighting a `<textarea>` needs an overlay, and colouring tokens in a rendered view needs a parser; either is a dependency, and none was approved. The application ships no highlighter at all, so the published snippet view is escaped monospace rather than colourised markup. Debugging is a debugger, not a phase. |
| Sharing, invitations, per-item comments, activity log, revision history | Ideas | Each needs new permissions and a second ACL — a design discussion, not a screen. `AccountPermission` was deliberately left at thirty cases. |
| Projects, milestones, the `projects` table | Ideas | A project is a delivery object with its own specification. `idea_plans` is a document, not a plan of record. |
| Idea tags and cover images | Ideas | Uploads need a disk, a signer and a prune command. Half-wiring them is worse than waiting. |
| Sales, refunds, payment configuration, support tickets | Marketplace | There is no `Sale` or `SupportTicket` model. The five abilities above are granted and never checked until it exists. |
| The hub's curated Ideas and Developer Lab shelves | AI Knowledge Hub | They need a hub-side curation model — an object that says which rows the hub chose and in what order. That is a different thing from the Ideas bank or the Lab's own gallery, so the two shelf pages stay honest placeholders. |

Declaring a route and permission before its module exists was a deliberate choice, and
the choice is now tested: claiming the top-level URL space early meant Ideas and the
Lab could be built without a routing change, without a permission migration, and
without touching `PermissionModelTest`, which pins the exact per-role sets.
`UseDeveloperLab`, `UseAiHub`, and `UseIdeas` group under `PermissionGroup::Platform`;
`UseMarketplace` groups under `PermissionGroup::Selling`.
