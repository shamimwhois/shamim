# Route Reference

Every route in the application, transcribed from `php artisan route:list`.
**77 routes** are registered; this document covers the application's own routes
and summarises the package routes rather than listing each one.

Where a guard is stated, it is the *enforced* guard — the route middleware, not
the intent. Where the two differ, the difference is called out.

---

## 1. Route files

| File | Contains |
| --- | --- |
| `routes/web.php` | Public pages, the three role dashboards, the four platform modules, backward-compatible redirects |
| `routes/settings.php` | Account settings, passkey endpoints |
| Framework / Fortify / Livewire | Auth, 2FA, passkeys, Livewire transport, Flux assets |

`routes/console.php` registers no routes. There is **no `routes/api.php`** — see
[`API.md`](API.md).

---

## 2. Public routes

No authentication. These are the pages a visitor or crawler sees.

| Method | URI | Name | Component / Controller |
| --- | --- | --- | --- |
| `GET` | `/` | `home` | `pages::home` |
| `GET` | `/about` | `about` | `pages::about` |
| `GET` | `/blog` | `blog.index` | `pages::blog.index` |
| `GET` | `/blog/{post:slug}` | `blog.show` | `pages::blog.show` |
| `GET` | `/feed.xml` | `feed` | `FeedController` |
| `GET` | `/sitemap.xml` | `sitemap` | `SitemapController` |
| `GET` | `/robots.txt` | `RobotsController` (no name) | `RobotsController` |

Middleware: `web` only.

**Route-model binding keys matter here.** `{post:slug}` binds by slug, not id,
so a post URL is stable and readable and does not leak row counts. `blog.show`
additionally:

- publishes `draft` posts only to their author,
- returns `404` — not `403` — for a draft belonging to someone else, so the
  route does not confirm that an unpublished post exists.

The three raw responses (`feed`, `sitemap`, `robots`) are the only non-Livewire
routes in the application. They are plain controllers because they return XML
and plain text, not HTML.

---

## 3. Authentication routes

Registered by **Fortify**, not by this application. Views are supplied through
`FortifyServiceProvider::configureViews()` and live in
`resources/views/pages/auth/`.

| Method | URI | Name | View |
| --- | --- | --- | --- |
| `GET` | `/login` | `login` | `pages::auth.login` |
| `POST` | `/login` | `login.store` | — |
| `POST` | `/logout` | `logout` | — |
| `GET` | `/register` | `register` | `pages::auth.register` |
| `POST` | `/register` | `register.store` | — |
| `GET` | `/forgot-password` | `password.request` | `pages::auth.forgot-password` |
| `POST` | `/forgot-password` | `password.email` | — |
| `GET` | `/reset-password/{token}` | `password.reset` | `pages::auth.reset-password` |
| `POST` | `/reset-password` | `password.update` | — |

### Where a successful login lands

This is the single most important behaviour in this file, and it was **wrong**
in the inherited codebase.

`config/fortify.php` defines only `'home' => '/dashboard'` and declares no
per-route `redirects` map, so Fortify delegates to the response classes in
`app/Http/Responses/`. Each one resolves its destination through
`AccountRole::dashboardRoute()`:

| Role | Route | URI |
| --- | --- | --- |
| `user` | `dashboard.index` | `/dashboard` |
| `seller` | `seller.dashboard` | `/seller/dashboard` |
| `admin` | `admin.dashboard` | `/admin/dashboard` |

Each role owns a **top-level** URL space: the role is the first path segment, so
the address bar names the surface. No role dashboard is nested inside another —
the previous `/{current_team}/dashboard` scoping is gone along with the team
feature, and no registered route carries a `current_team` or `team` parameter.

The response classes resolve the destination through
`AccountRole::dashboardRoute()` and guard with `app('router')->has($routeName)`,
falling back to `/dashboard`, so a role can be declared in the enum before its
dashboard route exists without producing a 500. That fallback is why
`'home' => '/dashboard'` still matters: it is what a role with no registered
dashboard route receives.

### Email verification

| Method | URI | Name |
| --- | --- | --- |
| `GET` | `/email/verify` | `verification.notice` |
| `GET` | `/email/verify/{id}/{hash}` | `verification.verify` |
| `POST` | `/email/verification-notification` | `verification.send` |

`{id}` and `{hash}` are both required, and `hash` is an HMAC of the user id
derived from `APP_KEY`. A verification link cannot be forged from an id alone.

### Two-factor challenge

| Method | URI | Name |
| --- | --- | --- |
| `GET` | `/two-factor-challenge` | `two-factor.login` |
| `POST` | `/two-factor-challenge` | `two-factor.login.store` |

Both are reachable **only** mid-login, when the session has passed the password
step but not the second factor. A guest requesting either is redirected.

---

## 4. Role dashboards

Three separate, **role-owned** URL spaces. The role is the first path segment,
so no role's home is nested inside another's. Each is stated with its exact
enforced middleware.

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/dashboard` | `dashboard.index` | `auth`, `verified` |
| `GET` | `/dashboard/posts` | `dashboard.posts.index` | `auth`, `verified` |
| `GET` | `/dashboard/posts/create` | `dashboard.posts.create` | `auth`, `verified` |
| `GET` | `/dashboard/posts/{post:id}/edit` | `dashboard.posts.edit` | `auth`, `verified` + policy |
| `GET` | `/dashboard/comments` | `dashboard.comments` | `auth`, `verified` |
| `GET` | `/dashboard/taxonomy` | `dashboard.taxonomy` | `auth`, `verified` |
| `GET` | `/seller/dashboard` | `seller.dashboard` | `auth`, `verified`, `permission:product:create` |
| `GET` | `/admin/dashboard` | `admin.dashboard` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/users` | `admin.users` | `auth`, `verified`, `permission:admin:access` |

`dashboard.*` is the **user** role's home and the shared author workspace: a
seller and an admin still write posts here. Only their *homes* are distinct, so
`/dashboard` stays reachable by every authenticated, verified account even
though it is the user role's landing page. The seller and admin homes are
guarded by a **permission**, not by a role name — a role check would drift from
the permission matrix, a permission check cannot.

The whole `dashboard.*` group is declared under `middleware(['auth',
'verified'])`, `prefix('dashboard')`, `name('dashboard.')`. The seller group
uses `prefix('seller')`, `name('seller.')`; the admin group uses
`prefix('admin')`, `name('admin.')`.

### Two design decisions worth stating

**`/dashboard` is deliberately unguarded by permission.** It is the landing page
for *every* role, and `pages::dashboard.index`'s `mount()` forwards a seller or
admin to the dashboard their role owns via `redirectRoute($routeName, navigate:
true)`. Guarding it with a permission would make it unreachable for the roles
that must land there first in order to be forwarded — the redirect is a real
HTTP redirect so the URL always names what is on screen.

**`posts/{post:id}` binds by id, not slug.** The dashboard edits the post you
own; a slug is a publication concern, not an authoring one. The *public* route
binds by slug. The different key is intentional and the routes are not
interchangeable.

**`dashboard.posts.create` and `dashboard.posts.edit` render the same
component** (`pages::dashboard.posts.edit`). One editor serves both create and
update; the presence of a bound `Post` is what distinguishes them. This is why
the route guard is only `auth` — the authorisation question ("may this account
edit *this* post") cannot be answered until the model is bound, so it is
answered by `PostPolicy` inside the component.

---

## 5. Platform module routes

Four product modules, each with its own top-level URL space and its own
permission. The modules are **not built yet** — each route renders a placeholder
page (via `resources/views/components/app/ui/module-placeholder.blade.php`) stating
what is planned. The routes and guards resolve today, and the sidebar points at
them, so each module has a landing spot to be built into rather than needing a
routing change later.

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/dev-lab` | `dev-lab.index` | `auth`, `verified`, `permission:dev-lab:use` |
| `GET` | `/ai-hub` | `ai-hub.index` | `auth`, `verified`, `permission:ai-hub:use` |
| `GET` | `/marketplace` | `marketplace.index` | `auth`, `verified`, `permission:marketplace:use` |
| `GET` | `/ideas` | `ideas.index` | `auth`, `verified`, `permission:ideas:use` |

The URL space is claimed now on purpose: `/ideas`, `/dev-lab`, `/ai-hub` and
`/marketplace` are top-level names that must not be taken by anything else. A
guest is redirected to login rather than refused, because they may hold the
permission after authenticating.

---

## 6. Editor AI routes

Six JSON endpoints backing the rich text editor's AI features. They live under
`/editor/ai` and are grouped by a single `auth`, `verified`,
`permission:post:create` middleware stack — anyone who can write a post can use
the editor's AI, and nobody else can.

| Method | URI | Name | Throttle |
| --- | --- | --- | --- |
| `GET` | `/editor/ai/capabilities` | `editor.ai.capabilities` | — |
| `POST` | `/editor/ai/preview` | `editor.ai.preview` | 120/min |
| `POST` | `/editor/ai/correct` | `editor.ai.correct` | 20/min |
| `POST` | `/editor/ai/generate` | `editor.ai.generate` | 10/min |
| `POST` | `/editor/ai/image-prompt` | `editor.ai.image-prompt` | 15/min |
| `POST` | `/editor/ai/image` | `editor.ai.image` | 5/min |

**Why JSON and not Livewire.** These actions fire from inside a text editor that
owns its own selection and cursor state. A Livewire round trip re-renders the
DOM and loses the textarea's caret position — the single most irritating thing a
writing tool can do. The editor keeps its own state client-side and calls these
endpoints with `fetch()`, syncing the finished value back through `$wire.set()`.

Throttles are per-minute and deliberately tight on the expensive endpoints:
image generation in particular is billed per call, and 5/min is generous for
authoring while useless for draining a budget.

**Error shape.** Every failure returns the same body, so the front end has one
code path:

```json
{ "message": "AI features are not configured…", "reason": "not_configured" }
```

`reason` maps to a status: `not_configured` → 503, `provider_failed` → 502,
`malformed_response` → 502, `storage_failed` → 500. See
`app/Services/EditorAiException.php`.

**Preview works with no provider configured.** It touches no model — it routes
Markdown through `App\Support\MarkdownRenderer`, the same renderer that serves
the published post. A client-side renderer would be a second sanitiser
implementation and the two would drift, at which point the preview stops
predicting the published output.

---

## 7. Backward-compatible redirects

The role dashboards moved from nested paths to role-owned top-level spaces.
These redirects keep existing bookmarks, external links and in-flight email
working. They are **301**, not 302: the move is permanent, so a browser or
search engine is correct to cache it.

| Method | URI | Name | Target | Middleware |
| --- | --- | --- | --- | --- |
| `GET` | `/dashboard/seller` | `dashboard.seller.redirect` | `/seller/dashboard` (301) | — |
| `GET` | `/dashboard/admin` | `dashboard.admin.redirect` | `/admin/dashboard` (301) | — |
| `GET` | `/teams/dashboard` | `team.dashboard` | `/dashboard` (301) | `auth`, `verified` |
| `GET` | `/settings/teams` | `teams.index.redirect` | `/settings/security` (301) | `auth`, `verified` |

The `team.dashboard` name is preserved as a redirect to `/dashboard`. It was the
starter kit's team-scoped dashboard; with the team feature removed there is no
team to scope by, but the name is still referenced in documentation and may be
bookmarked, so it resolves rather than 404s. `settings/teams` lands on the
security screen — the closest surviving screen, since no team surface remains.

---

## 8. Settings routes

Two groups with **different** guards. The split is deliberate. Account settings
are shared by every role — there is no team-scoped settings surface; the team
feature and its `/settings/teams` routes were removed.

| Method | URI | Name | Guard |
| --- | --- | --- | --- |
| `GET` | `/settings` | — | `auth` → redirects to `/settings/profile` |
| `GET` | `/settings/profile` | `profile.edit` | `auth` **only** |
| `GET` | `/settings/appearance` | `appearance.edit` | `auth`, `verified` |
| `GET` | `/settings/security` | `security.edit` | `auth`, `verified`, `password.confirm`\* |
| `GET` | `/settings/teams` | `teams.index.redirect` | `auth`, `verified` → 301 to `/settings/security` |

\* `password.confirm` is applied **conditionally**:

```php
->middleware(when(
    Features::canManageTwoFactorAuthentication()
        && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
    ['password.confirm'],
    [],
))
```

The security screen is where two-factor is enabled and disabled and where
passkeys are managed. Requiring a re-entered password makes a stolen session
insufficient to add an attacker's authenticator. If either feature is disabled
in config, the middleware is dropped rather than leaving a screen unreachable
behind a confirmation that guards nothing.

**Why `/settings/profile` requires only `auth`.** A user whose e-mail is
unverified must still be able to reach it, because **changing your e-mail
address is how you recover from being unable to receive the verification mail**.
Requiring `verified` here would make an unverifiable account permanently stuck —
unable to verify, and unable to change the address that verification is sent to.

`/.well-known/passkey-endpoints` is a JSON discovery document, returns
`security.edit` for both `enroll` and `manage`, and is intentionally
unauthenticated.

---

## 9. Passkey routes

Provided by `laravel/passkeys`. Two groups: unauthenticated (login) and
authenticated (registration and management).

| Method | URI | Name | Auth |
| --- | --- | --- | --- |
| `GET` | `/passkeys/login/options` | `passkey.login-options` | guest |
| `POST` | `/passkeys/login` | `passkey.login` | guest |
| `GET` | `/user/passkeys/options` | `passkey.registration-options` | auth |
| `POST` | `/user/passkeys` | `passkey.store` | auth |
| `DELETE` | `/user/passkeys/{passkey}` | `passkey.destroy` | auth |
| `GET` | `/passkeys/confirm/options` | `passkey.confirm-options` | auth |
| `POST` | `/passkeys/confirm` | `passkey.confirm` | auth |
| `GET` | `/.well-known/passkey-endpoints` | `well-known.passkeys` | public |

The `options` routes return WebAuthn challenge payloads. The challenge is
single-use and server-verified; a replayed assertion is rejected by the library,
not by application code.

---

## 10. Two-factor management routes

Fortify's management endpoints. All require `auth`; those that change state are
additionally behind password confirmation.

| Method | URI | Name |
| --- | --- | --- |
| `POST` | `/user/two-factor-authentication` | `two-factor.enable` |
| `DELETE` | `/user/two-factor-authentication` | `two-factor.disable` |
| `GET` | `/user/two-factor-qr-code` | `two-factor.qr-code` |
| `GET` | `/user/two-factor-secret-key` | `two-factor.secret-key` |
| `GET` | `/user/two-factor-recovery-codes` | `two-factor.recovery-codes` |
| `POST` | `/user/two-factor-recovery-codes` | `two-factor.regenerate-recovery-codes` |
| `POST` | `/user/confirmed-two-factor-authentication` | `two-factor.confirm` |

These are called by `pages::settings.security`, not navigated to. The setup
modal posts to `two-factor.enable` and then to `two-factor.confirm` — the two
steps being enable-then-verify, which is why a half-finished setup leaves the
account with 2FA enabled but unconfirmed, and `SecurityTest` covers the case
where that state persists between requests.

---

## 11. Password confirmation routes

| Method | URI | Name |
| --- | --- | --- |
| `GET` | `/user/confirm-password` | `password.confirm` |
| `POST` | `/user/confirm-password` | `password.confirm.store` |
| `GET` | `/user/confirmed-password-status` | `password.confirmation` |

Confirmation is **time-boxed by session**, not per-action. Once confirmed, the
session records the timestamp and sensitive routes accept it until it expires.
This is why `GET /user/confirm-password` redirects when the session has already
confirmed — the screen exists to *establish* the state, not to re-assert it.

---

## 12. The removed team routes

The team feature was deleted **entirely**, and with it every team-scoped route.
Nothing in the current route table carries a `current_team` or `team`
parameter, and no URL can reintroduce team scoping by accident.

Removed: the `Team`, `Membership` and `TeamInvitation` models; the `HasTeams`
and `GeneratesUniqueTeamSlugs` traits; `TeamPolicy`; the
`EnsureTeamMembership` and `SetTeamUrlDefaults` middleware; the
`TeamPermissions` and `UserTeam` DTOs; the `TeamName` and
`UniqueTeamInvitation` rules; `Actions/Teams/*` and `Notifications/Teams/*`;
the `RedirectsToCurrentTeam` response concern; the `TeamRole` and
`TeamPermission` enums; the whole `resources/views/pages/teams/` tree; the
`⚡team-switcher`, `⚡create-team-modal` and `team-invitation-alert` components;
the `layouts/app*` layouts; `views/dashboard.blade.php`; the `create_teams_table`
and `add_current_team_id_to_users_table` migrations; the `TeamFactory` and
`TeamInvitationFactory`; and the whole `tests/Feature/Teams/` directory.

The `teams`, `team_memberships` and `team_invitations` tables are gone, as is
`users.current_team_id`. The authorisation model is now **single-axis**:
`AccountRole` + `AccountPermission` are the only vocabulary. Any description of
a two-axis (account + team) model is stale.

Two routes survive as redirects so old links resolve — `/teams/dashboard` →
`/dashboard` (`team.dashboard`) and `/settings/teams` → `/settings/security`
(`teams.index.redirect`); see §7.

---

## 13. Package routes (not listed individually)

| Prefix | Source | Count |
| --- | --- | --- |
| `livewire-*/` | Livewire transport, assets, file upload, previews | 8 |
| `flux/` | Flux asset manager (JS, editor, flags) | 8 |
| `storage/{path}` | Local filesystem serving | 2 |
| `_boost/browser-logs`, `up` | Laravel Boost, health check | 2 |

None should be relied on by application code. `livewire-*` includes a build hash
that changes between releases.

---

## 14. Conventions

**Route names mirror URIs, with dots for nesting.**

```
/dashboard          → dashboard.index
/dashboard/posts    → dashboard.posts.index
/seller/dashboard   → seller.dashboard
/admin/users        → admin.users
```

Role homes are named `<role>.dashboard`, not `dashboard.<role>.*`, because the
role is the first path segment and the name follows the path. The two redirects
are the exceptions that prove the rule: `team.dashboard` and `teams.index.redirect`
keep names that no longer match their target, on purpose, so old references
still resolve.

**View routes use `Route::livewire`.** The second argument is always a
`pages::` or `layouts::` name. A typo is a runtime 500, not a build failure —
which is why route names and their components are exercised by tests rather
than trusted.

**Model binding keys are explicit.** `{post:slug}` on public routes,
`{post:id}` on dashboard routes. Never a bare `{post}`, because the default key
silently changes if the model's `getRouteKeyName()` changes. No route binds a
team, because no route is team-scoped.

**Guards are permission-based, not role-based.** Every role check is expressed
as `permission:<ability>` or a policy. There is no `->middleware('role:admin')`
anywhere in the codebase, and there should not be: a route that names a role
must be edited every time a role's permissions change.

---

## 15. Known issues

| # | Issue | Impact |
| --- | --- | --- |
| 1 | `dashboard.posts.create` and `.edit` differ only by a bound model | Intentional, but means the "create" guard cannot be expressed in middleware. §4. |
| 2 | `dashboard.index` is unguarded by permission | Intentional — it is the forwarding entry point for every role. §4. |
| 3 | Modules render placeholders, not features | Intentional. The routes and guards are real; the modules are unbuilt. §5. |
| 4 | `team.dashboard` and `teams.index.redirect` name routes that no longer match their behaviour | Historic names kept so old links and docs resolve. §7, §14. |

On issue 3: because the routes exist and the navigation points at them, each
module can be built into its landing spot without a routing change. The
placeholder page states plainly that it is not yet available, so it cannot be
mistaken for a working feature.
