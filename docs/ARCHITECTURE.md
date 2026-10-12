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
  │    ├─ permission:xxx ....... EnsureAccountPermission
  │    └─ team ................. EnsureTeamMembership
  │
  ├─ Livewire mounts the SFC ... resources/views/pages/**/⚡*.blade.php
  │    ├─ mount() .............. authorization, initial state, SEO
  │    ├─ render() ............. implicit, returns the same file
  │    └─ actions .............. methods invoked via wire:click / wire:submit
  │
  └─ layouts/dashboard.blade.php ... or layouts/public.blade.php
       └─ components/dashboard/nav.blade.php
            └─ Support/DashboardNavigation::for($user)
```

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

Eight enums carry the application's closed sets.

| Enum | Cases | Purpose |
| --- | --- | --- |
| `AccountRole` | 3 | `user`, `seller`, `admin` — the account's role |
| `AccountPermission` | 24 | Every grantable ability, in 4 groups |
| `PermissionGroup` | 4 | `content`, `moderation`, `selling`, `administration` |
| `RolePlan` | 3 | `free`, `seller`, `platform` — commercial entitlements |
| `PostStatus` | 3 | `draft`, `published`, `archived` |
| `CommentStatus` | 3 | `pending`, `approved`, `rejected` |
| `TeamRole` | 3 | `owner`, `admin`, `member` |
| `TeamPermission` | 7 | What a team role may do within a team |

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
| `HasTeams` | Team relationships, membership, personal team |
| `HasSlug` | Slug generation with uniqueness |
| `HasReadingTime` | Word-count → reading minutes |
| `PasswordValidationRules` | Shared password rules |
| `ProfileValidationRules` | Shared profile rules |
| `GeneratesUniqueTeamSlugs` | Team-specific slug uniqueness |

`HasAccountRole` is the most load-bearing. It is what makes
`$user->can('/^post:/')`-style checks unnecessary: the model answers its own
authorization questions, and every other layer delegates to it.

### `app/Data/`

Immutable read-only DTOs — `AuthorProfile`, `PostMetrics`, `TeamPermissions`,
`UserTeam`. They exist to stop **arrays with implicit shapes** crossing layer
boundaries. When a Blade template does `$post->metrics->wordCount` instead of
`$metrics['word_count']`, a typo is a static-analysis error rather than a blank
space on a page.

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

All five share `Responses/Concerns/RedirectsToCurrentTeam`, which resolves the
destination from `AccountRole::dashboardRoute()`.

**This was the most consequential bug in the inherited codebase.** Every
successful login, registration and verification landed on the starter kit's
placeholder `/{team:slug}/dashboard` — a page that shows framework scaffolding,
not the application. The fix routes each role to its own dashboard and guards
with `app('router')->has($routeName)` so a role can be declared before its
dashboard exists.

### `app/Support/`

The three cross-cutting services.

**`DashboardNavigation`** — the single source of truth for the dashboard
sidebar. Entries declare `label`, `route`, `pattern`, `icon`, `permissions` and
an optional `badge`. `for(User $user)` filters by permission and resolves
badges.

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

### The modal system

Eight modals were independent copies of the same pattern. They now share one
shell.

```
<x-modal>               shell: focus, width, accessible name, reopen-on-error
<x-modal.actions>       footer row; cancel defaults to a closing button
<x-modal.trigger>       thin pass-through, keeps the modal name in one place
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

Two independent axes. Conflating them is the mistake this design avoids.

### Axis 1 — account permissions (platform-wide)

```
User → role (enum) → permissions (derived)
```

`AccountRole::permissions()` derives the set; nothing is stored.

| Role | Level | Permissions |
| --- | --- | --- |
| `user` | 1 | 3 |
| `seller` | 2 | 11 (includes all of `user`) |
| `admin` | 3 | 24 (all cases) |

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

### Axis 2 — team roles (per team)

`TeamRole` / `TeamPermission` answer "what may this person do *in this team*".
A team `admin` on one team is a `member` on another. This is why team
authorization cannot be expressed as a token ability or an account permission —
it depends on which team is being addressed.

Enforced by `EnsureTeamMembership` plus `Membership` model checks.

### Policies

`PostPolicy`, `TeamPolicy`, `UserPolicy` handle resource-scoped questions the
middleware cannot answer — "may this account edit **this** post".

`UserPolicy` contains the escalation guard. Role assignment is checked twice:
the policy authorizes the action, and `AccountRole::assignableBy()` filters
which roles an actor may grant. Belt and braces, because privilege escalation
is the one bug class worth redundant defence.

Full matrix: [`PERMISSIONS.md`](PERMISSIONS.md).

---

## 6. Data layer

Eleven application migrations plus the framework defaults.

```
users ──────────┐
  │             │
  ├─ posts ─────┼── post_tag ──── tags
  │   │         │
  │   └─ comments
  │             │
  └─ memberships ┴── teams ──── team_invitations
        │
        └─ current_team_id (users)
```

Conventions, applied without exception:

- **PHP 8 attributes over properties** — `#[Fillable([...])]`, `#[Hidden([...])]`.
- **`casts()` as a method**, not a property.
- **Full PHPDoc property blocks** on every model, so PHPStan level 7 can
  resolve attribute types.
- **Enums are cast** — `'role' => AccountRole::class`, not `'role' => 'string'`.

`current_team_id` on `users` is a denormalised pointer to the active team. It
is what lets a team-scoped URL be generated without a session lookup on every
request.

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

Full design language: [`UI-UX-BRIEF.md`](UI-UX-BRIEF.md).

---

## 8. Testing strategy

Pest 5, 169 passing tests, 403 assertions.

| Suite | Covers |
| --- | --- |
| `Feature/Auth/*` | Login, register, reset, verification, 2FA, passkeys, layout contract |
| `Feature/Blog/*` | Index, show, sharing, drafts, previews |
| `Feature/Dashboard/*` | Post management, moderation, taxonomy |
| `Feature/DashboardTest` | Role-scoped dashboards, nav filtering, role badges |
| `Feature/Teams/*` | Membership, invitations, ownership |
| `Feature/Settings/*` | Profile, security, 2FA lifecycle |

Two conventions worth stating:

**Test the denial path, always.** For every "user can view their own draft"
there is a "stranger cannot". Authorization bugs live on the negative path.

**Test the contract, not the markup.** `AuthLayoutTest` asserts that the layout
does *not* hardcode a theme, not that a particular `div` has a particular class.
Class-level assertions break on every redesign and catch nothing.

### The one known failure

```
Tests\Feature\Teams\TeamInvitationTest > accepted team invitations cannot be accepted again
```

Pest's `expectException(ModelNotFoundException::class)` never fires, because
Livewire catches the exception inside its own request handling before it can
propagate. The application behaviour is correct — an already-accepted invitation
cannot be re-accepted — but the assertion cannot observe it through a Livewire
test.

It is left failing rather than deleted: `AGENTS.md` forbids removing tests
without approval, and a suppressed failure is worse than a visible one. The
correct fix is to assert on the Livewire component's error state instead of
expecting a thrown exception.

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
4. `resources/views/pages/dashboard/<role>/⚡index.blade.php` — the dashboard
5. `routes/web.php` — a middleware-guarded sub-group
6. `DashboardNavigation` — section entries
7. `UserSeeder`, `UserFactory` — a state and a demo account

Step 2 is enough for the role to *work*: `RedirectsToCurrentTeam` uses
`router->has()` so a role can be declared before its dashboard exists, and the
login redirect will fall back rather than 500.
