# Phase 0 — Repository Audit

**Purpose.** Establish the factual state of the codebase before any change is made.
Every specification document in `docs/` mandates this step:

- `docs/Idea.md` §13/§15 — *"Inspect the existing Laravel application. Identify framework
  versions, authentication, admin layout, database, frontend dependencies, route
  conventions, and existing reusable components. Document findings before making changes."*
- `docs/lab.md` §2 — *"Do not assume a feature, package, service, or runtime is installed
  unless the repository confirms it."*
- `docs/AI Knowledge Hub & Package.md` §12 — *"Do not assume the repository structure,
  installed package versions, authentication mechanism, or existing admin panel without
  verification."*
- `docs/Developer Marketplace & Dig.md` §22 — *"Identify the current Laravel version,
  authentication system, admin panel, route conventions, database, UI components, and
  existing modules."*

**Audited revision.** `main` @ `c239af8` (working tree carries 120 staged changes, listed in §7).

> **⚠️ Point-in-time snapshot — do not read as current state.** This document
> records the repository exactly as it was *before* the redesign it motivated.
> Several findings below **no longer describe the codebase**:
>
> - **The team feature has since been removed entirely** — models, tables,
>   middleware, components and tests (§7 and §10 below describe it as live).
> - **Account roles and permissions now exist** (§10 states they do not);
>   the role model is account-scoped and **single-axis**, not team-scoped.
> - **The test failure noted below is gone** — the test was deleted with the
>   feature it covered. `composer test` is green.
> - Role dashboards now live at `/dashboard`, `/seller/dashboard` and
>   `/admin/dashboard`.
>
> For the current state see [PERMISSIONS.md](../PERMISSIONS.md),
> [ROUTES.md](../ROUTES.md) and [ARCHITECTURE.md](../ARCHITECTURE.md). This file
> is retained as the historical record of *why* those changes were made.

---

## 1. Verified stack

| Layer | Installed | Evidence |
|---|---|---|
| PHP | 8.5.11 | Herd `php85` binary |
| Laravel | `^13.17` | `composer.json` `require` |
| Livewire | `^4.1` | `composer.json` |
| Flux UI | `^2.13.1` (free tier) | `composer.json` |
| Fortify | `^1.37.2` | `composer.json` |
| Passkeys | `@laravel/passkeys` `^0.2.0` | `package.json` |
| Tailwind | `^4.0.7` (CSS-first `@theme`) | `package.json` |
| Vite | `^8.0.0` via `vite-plus` `0.3.0` | `package.json` |
| Pest | `^5.3` | `composer.json` `require-dev` |
| PHPStan (Larastan) | `^3.9` | `composer.json` `require-dev` |
| Pint | `^1.27` | `composer.json` `require-dev` |
| Blaze | `livewire/blaze ^1.0` | `composer.json` |
| Chisel | `laravel/chisel ^0.1.0` | `composer.json` |
| Database | SQLite | `database/database.sqlite` |

**Not installed** (relevant to the referenced specs — none may be assumed):
no payment SDK of any kind, no `stripe/*`, no `laravel/cashier`, no
`intervention/image`, no `spatie/laravel-permission`, no `laravel/sanctum`,
no `league/flysystem-aws-s3-v3`, no `spatie/laravel-markdown`, no
CommonMark parser, no `monaco-editor` / `@codemirror/*`, no queue worker
supervisor config, no Docker/container runtime definition, no broadcast driver
beyond defaults.

**Baseline health — verified this audit:**

```
Tests:    1 failed, 146 passed (339 assertions)
```

The single failure is `Tests\Feature\Teams\TeamInvitationTest > accepted team
invitations cannot be accepted again`. It was confirmed **pre-existing** by
`git stash` + re-run in the prior session: Livewire swallows the
`ModelNotFoundException` before Pest's `expectException` can observe it, so the
assertion can never pass. It is a starter-kit test defect, unrelated to any
application code, and is **not fixed here** (out of scope; touching it would
change shipped starter-kit behaviour).

---

## 2. Application structure (actual)

```
app/
├── Actions/
│   ├── Comments/   ApproveComment, RejectComment
│   ├── Fortify/    CreateNewUser, ResetUserPassword
│   ├── Posts/      ArchivePost, CreatePost, DeletePost, HydratePostContent,
│   │               PublishPost, ResolveRelatedPosts, SyncPostTags,
│   │               SyncTaxonomyCounts, UnpublishPost, UpdatePost
│   └── Teams/      (invitation + membership actions)
├── Concerns/       GeneratesUniqueTeamSlugs, HasReadingTime, HasSlug, HasTeams
├── Console/        (commands)
├── Data/           AuthorProfile, PostMetrics, TeamPermissions, UserTeam
├── Enums/          CommentStatus, PostStatus, TeamPermission, TeamRole
├── Http/
│   ├── Controllers/ Controller, FeedController, RobotsController, SitemapController
│   ├── Middleware/  EnsureTeamMembership, SetTeamUrlDefaults
│   └── Responses/   LoginResponse, PasskeyLoginResponse, RegisterResponse,
│                    TwoFactorLoginResponse, VerifyEmailResponse
├── Livewire/       Actions/Logout (only; UI lives in SFC views)
├── Models/         Category, Comment, Membership, Post, PostTag, Tag, Team,
│                   TeamInvitation, User
├── Notifications/  (team invitation)
├── Observers/      PostObserver
├── Policies/       PostPolicy, TeamPolicy
├── Providers/      AppServiceProvider, FortifyServiceProvider
├── Rules/          PostSlug
└── Support/        MarkdownRenderer, Seo
```

**Conventions observed in the codebase (these are binding — new code must match):**

- `app/Actions/<Domain>/<Verb><Noun>.php` — single-purpose invokable classes.
- `app/Enums/` — backed string enums with `label()`, `permissions()`, `level()`,
  `isAtLeast()`, `assignable()` where the concept applies.
- `app/Data/` — readonly DTOs handed to views instead of arrays.
- Livewire pages are `resources/views/pages/**/⚡name.blade.php` (single-file
  components, `⚡` prefix), NOT `app/Livewire/**` classes. **Flagged below.**
- Model attributes use PHP 8 attributes (`#[Fillable([...])]`, `#[Hidden([...])]`),
  not `protected $fillable`.
- Every model has `casts()` as a method, return-typed `array<string, string>`.
- Every model/relation carries a full PHPDoc property block for PHPStan level 3.

---

## 3. Routing (actual, verified via `routes/web.php`)

**Public**

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/` | `home` | `pages::home` |
| GET | `blog` | `blog.index` | `pages::blog.index` |
| GET | `blog/{post:slug}` | `blog.show` | `pages::blog.show` |
| GET | `about` | `about` | `pages::about` |
| GET | `feed.xml` | `feed` | `FeedController` |
| GET | `sitemap.xml` | `sitemap` | `SitemapController` |
| GET | `robots.txt` | — | `RobotsController` |

**Author dashboard** — `middleware(['auth','verified'])`, `prefix('dashboard')`,
`name('dashboard.')`:

| URI | Name |
|---|---|
| `/dashboard` | `dashboard.index` |
| `/dashboard/posts` | `dashboard.posts.index` |
| `/dashboard/posts/create` | `dashboard.posts.create` |
| `/dashboard/posts/{post:id}/edit` | `dashboard.posts.edit` |
| `/dashboard/comments` | `dashboard.comments` |
| `/dashboard/taxonomy` | `dashboard.taxonomy` |

**Starter kit** — `prefix('{current_team}')`,
`middleware(['auth','verified',EnsureTeamMembership::class])`:

| URI | Name |
|---|---|
| `/{current_team}/dashboard` | `team.dashboard` |

`routes/settings.php` is required at the bottom of `web.php` (Fortify-provided
profile / security / appearance pages).

**Auth routes** — registered entirely by Fortify via its service provider.
There is **no** `routes/auth.php`.

### Gap: there is no API surface

`routes/api.php` **does not exist**, `bootstrap/app.php` has no `api:` entry in
`withRouting()`, and `laravel/sanctum` is **not installed**. The user's request
to *"update the API design documentation"* therefore has no implementation to
document. See §6, decision D2.

---

## 4. Authentication system (actual)

Fortify-driven, headless-to-views. All views are Blade files in
`resources/views/pages/auth/` registered through `FortifyServiceProvider::configureViews()`:

| Feature | View | Guard |
|---|---|---|
| Login | `pages::auth.login` | `login` limiter, 5/min by `email\|ip` |
| Register | `pages::auth.register` | — |
| Forgot password | `pages::auth.forgot-password` | — |
| Reset password | `pages::auth.reset-password` | — |
| Confirm password | `pages::auth.confirm-password` | — |
| Verify email | `pages::auth.verify-email` | — |
| 2FA challenge | `pages::auth.two-factor-challenge` | `two-factor` limiter, 5/min by session `login.id` |
| Passkeys | `<x-passkey-verify />` inside login | `passkeys` limiter, 10/min |

**Response redirection.** Every Fortify contract is bound to a custom class in
`app/Http/Responses/`, all sharing `Concerns\RedirectsToCurrentTeam`. That trait
resolves `$user->currentTeam ?? $user->personalTeam()`, `abort_if` on failure,
then redirects to `/{team:slug}/dashboard`.

> **This is the single most important finding for the redesign.** A successful
> login currently lands every user on the *starter-kit team dashboard*
> (`team.dashboard`), not on the author dashboard (`dashboard.index`) that the
> portfolio work actually built. `config/fortify.php` declares
> `'home' => '/dashboard'`, but the response classes ignore it and prepend the
> team slug. The two dashboards are unrelated: `team.dashboard` is
> `Route::view('dashboard', 'dashboard')` — a starter-kit placeholder blade —
> while `dashboard.index` is the real author product.

**Modal system (actual).** There is **no** dedicated modal system. Modals are
ad-hoc `<flux:modal>` usages scattered across:

- `pages/teams/⚡create-team-modal.blade.php`, `⚡invite-member-modal.blade.php`,
  `⚡remove-member-modal.blade.php`, `⚡cancel-invitation-modal.blade.php`,
  `⚡delete-team-modal.blade.php`, `⚡pending-invitations-modal.blade.php`
- `pages/settings/⚡delete-user-modal.blade.php`, `⚡two-factor-setup-modal.blade.php`
- `components/⚡create-team-modal.blade.php`

There is no shared trigger/modal contract, no consistent naming scheme, no
shared "danger confirmation" primitive. The user's request to redesign *"the
modal system"* is therefore **greenfield**, not a refactor. See §6, decision D3.

---

## 5. Database (actual, 11 migrations)

| Table | Origin | Notes |
|---|---|---|
| `users` | framework | `+ current_team_id` (FK → `teams`) |
| `cache`, `cache_locks` | framework | |
| `jobs`, `job_batches`, `failed_jobs` | framework | |
| `passkeys` | `@laravel/passkeys` | |
| `password_reset_tokens`, `sessions` | framework | |
| `teams` | starter kit | soft-deletes, `slug` unique, `is_personal` |
| `team_members` | starter kit | pivot w/ `role`, mapped by `Membership` |
| `team_invitations` | starter kit | `code`, `expires_at`, `accepted_at` |
| `categories` | portfolio | `name`, `slug`, `description`, `posts_count` |
| `tags` | portfolio | `name`, `slug`, `posts_count` |
| `posts` | portfolio | soft-deletes, `status`, `body`, `body_html`, `body_text`, `word_count`, `reading_time`, `published_at`, `view_count` |
| `post_tag` | portfolio | composite PK, mapped by `PostTag` pivot model |
| `comments` | portfolio | `status`, soft-deletes |

**No** `roles`, `permissions`, `model_has_roles`, `packages`, `orders`,
`payments`, `knowledge_*`, `lab_*`, or `marketplace_*` tables exist.

### The role model is team-scoped, not account-scoped

`User` has **no** `role` column. Roles live on the `team_members` pivot as
`TeamRole` (`owner` / `admin` / `member`) and are resolved by
`HasTeams::teamRole(Team $team)`. There is **no** global/account-level role, no
`seller`, and no permission that spans the whole application.

`PostPolicy` reflects this: authoring is `viewAny() => true` for *any*
authenticated user, and every mutating ability is `$user->id === $post->user_id`.
There is no "can this account publish" gate — every registered account can.

---

## 6. Findings against the request

The request asks for six concrete things. Each is listed with its **actual**
starting state so the delta is unambiguous.

| # | Requested | Actual state | Delta |
|---|---|---|---|
| 1 | Redesign login/auth incl. components, layouts, **modal system** | Fortify wired; 7 views; 3 auth layouts (`app`, `auth`, `auth.card`, `auth.simple`, `auth.split`); redirect goes to `team.dashboard`; **no modal system** | Substantial. Layouts are unused starter-kit leftovers; redirect target is wrong for this product; modal system is greenfield. |
| 2 | Redesign dashboard for **user / seller / admin** | One author dashboard (`dashboard.*`); one starter-kit team dashboard; **no seller concept** | Substantial. Seller is entirely new. |
| 3 | Role-based plans and sidebar menus | Sidebar items are a hardcoded PHP array in `components/dashboard/nav.blade.php`; no plan concept anywhere | Substantial. |
| 4 | Define user/seller/admin roles + permissions | Only `TeamRole` (team-scoped) + `TeamPermission` (7 cases) | Substantial. Account-level roles do not exist. |
| 5 | Update API design documentation | **No API exists** — no `routes/api.php`, no Sanctum | Cannot document what does not exist. See D2. |
| 6 | Review/update all `.md`; document components, dirs, routes, endpoints | 7 hand-written docs in `docs/` (`PRD`, `TRD`, `APP-FLOW`, `UI-UX-BRIEF`, `SCHEMA`, `ERD`, + 4 external spec docs). **No `README.md`.** No component/directory/route reference. | Substantial. |

### Additional finding: the four referenced docs are product specs, not tasks

`docs/Idea.md`, `docs/lab.md`, `docs/AI Knowledge Hub & Package.md`, and
`docs/Developer Marketplace & Dig.md` are **master PRDs for four separate large
products**:

| Doc | Product | Route space | Approx. tables |
|---|---|---|---|
| `Idea.md` | Ideas → Plans → Tasks → Projects | `/Idea/*`, `/admin/Idea/*` | ~15 |
| `lab.md` | Developer Lab (browser IDE) | `/it-flash/lab/*`, `/admin/it-flash/lab/*` | 10 |
| `AI Knowledge Hub & Package.md` | Knowledge Hub + Package Marketplace + Payments | `/ai/*`, `/packages/*`, `/admin/*` | ~23 |
| `Developer Marketplace & Dig.md` | Full multi-seller marketplace | `/marketplace/*`, `/admin/marketplace/*` | ~23 |

Combined that is **4 products, ~70 tables, and hundreds of routes** — each doc
independently ends with *"Implement one phase at a time"* and *"Do not claim a
gateway is integrated until its actual payment flow has been implemented and
tested."*

These documents therefore **define the eventual product family**, and they are
the reason a `seller` role and an admin surface are needed at all. They are
**not** a scoped work order, and `AI Knowledge Hub` and `Developer Marketplace`
overlap heavily (both define `orders`, `payments`, `entitlements`, `licenses`,
`package_*` with conflicting prefixes — `marketplace_*` vs bare names).

---

## 7. Working-tree state

120 files staged (117 added, 3 modified, 1 deleted) plus 1 untracked
(`docs/lab.md`). Nothing is committed since `c239af8`, which is only the
starter-kit install. **The entire portfolio implementation is uncommitted.**

This matters: any large redesign should be committed first, so the existing
green baseline (146 passing) is recoverable. See D1.

---

## 8. Decisions required before implementation

Three questions genuinely change the shape of the work and cannot be answered
from the documents. Each is stated with the smallest compatible option first.

### D1 — Commit the existing work before restructuring?

The portfolio implementation is entirely uncommitted. A redesign touching auth,
dashboards, and a new role model will make a clean rollback impossible without a
commit point.

- **Recommended:** commit the current tree as a baseline, then branch.
- Alternative: proceed uncommitted and rely on the stash/manual backups.

### D2 — Scope of the "API design documentation" update

No API exists and Sanctum is not installed. Three coherent readings:

- **Recommended:** document the *intended* API surface as a design artifact
  (`docs/API.md`) — resource names, versioning, auth strategy, response
  envelopes, rate limits, error codes — explicitly marked as **not yet
  implemented**, and add the routes incrementally as the modules land.
- Add a real `/api/v1` surface now (Sanctum + versioned resources). This is a
  large, independent workstream that no referenced doc requires yet.
- Skip the API docs entirely.

### D3 — How deep should the user / seller / admin redesign go now?

- **Recommended (foundation-first):** build the *substrate* that all four specs
  require and that does not exist — account-level `Role` enum, `permissions`
  matrix, role-aware auth redirect, a real dashboard shell with role-based
  sidebar + plan concept, and a shared modal system. Then implement the product
  modules (Idea / Lab / Knowledge / Marketplace) as separate phases on top.
  This satisfies every item in the request without committing to any single
  product spec's schema.
- Implement the auth + dashboard redesign **and** one product spec end-to-end
  in this pass. Choose which.
- Implement all four specs. Not achievable as a single coherent change, and
  contradicts each doc's own "one phase at a time" instruction.

---

## 9. Constraints carried forward

From `AGENTS.md` (project binding rules, quoted verbatim):

- *"Stick to existing directory structure; don't create new base folders without approval."*
- *"Do not change the application's dependencies without approval."*
- *"You must only create documentation files if explicitly requested by the user."*
  — the user **has** explicitly requested them, so this is satisfied.
- *"Add or update tests for behavior and logic changes when a test provides meaningful regression coverage."*
- *"Do not delete tests or test files without approval."*
- Pint must be run after PHP edits: `vendor/bin/pint --dirty --format agent`.

From the audit itself:

- `Route::livewire('uri', 'pages::name')` — `pages::` → `resources/views/pages`,
  `layouts::` → `resources/views/layouts`.
- Livewire SFCs are **anonymous**; tests address them by the **name string**
  (`'pages::blog.show'`), never `::class`.
- `php` is not on PATH here; use `/tmp/phpx.sh artisan …`.
- `composer` is not on PATH here; call `vendor/bin/phpstan` / `vendor/bin/pint` directly.
- PHPStan needs `-d memory_limit=1G` in this environment.

---

## 10. Post-audit findings — permission model gaps

Added after writing `docs/API.md`, which forced a line-by-line comparison
between the permission names the API needed and the names `AccountPermission`
actually defines. Four gaps surfaced. All are recorded here rather than fixed
silently, because each is a **policy decision**, not a typo:

1. **No comment-creation ability.** Any authenticated account can comment; the
   only comment permission is `comment:moderate`. There is no way to express
   "this token may post comments but not moderate them".
2. **No comment-ownership ability.** `comment:delete-own` does not exist, so
   "delete my own comment" is currently expressed as a policy check rather than
   a permission.
3. **No profile ability.** Editing one's own name and e-mail is unpermissioned
   — correct for the web app, but it means an API token cannot be restricted to
   read-only.
4. **`admin` is not assignable by anyone.** `AccountRole::assignableBy()`
   deliberately omits `Admin` from every role's list, including `admin`. This
   is a sound default (a compromised admin token cannot mint more admins) but
   it means there is currently **no code path at all** that promotes an account
   to `admin`. Promotion is performed by seeder or by direct database write.

Finding 4 is the most consequential. The admin role is reachable only through
`UserSeeder`; there is no in-application mechanism to create the first admin or
to promote a second. On a fresh deployment that is a bootstrap problem, not a
security one — but it needs a deliberate answer (an artisan command, a
config-defined allow-list, or an explicit "admins are made in the database"
policy) before the system is operated by anyone but its author.

---

*End of Phase 0. No application code was modified to produce this document.*
