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
| `routes/web.php` | Public pages, dashboard, team-scoped starter routes |
| `routes/settings.php` | Account settings, teams, passkey endpoints |
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
| `seller` | `dashboard.seller.index` | `/dashboard/seller` |
| `admin` | `dashboard.admin.index` | `/dashboard/admin` |

Previously all four flows landed on `team.dashboard` —
`/{current_team}/dashboard` — which renders the starter kit's placeholder page,
not the application. `RedirectsToCurrentTeam::dashboardPathFor()` now guards
with `app('router')->has($routeName)` and falls back to `/dashboard`, so a role
can be declared in the enum before its dashboard route exists without producing
a 500.

The fallback is why `'home' => '/dashboard'` still matters: it is what a role
with no registered dashboard route receives.

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

## 4. Dashboard routes

```
middleware: ['auth', 'verified']
prefix:     dashboard
name:       dashboard.
```

Every authenticated, verified account can reach the base group. The seller and
admin sub-groups are additionally guarded by a **permission**, not by a role.

| Method | URI | Name | Guard |
| --- | --- | --- | --- |
| `GET` | `/dashboard` | `dashboard.index` | any authenticated account |
| `GET` | `/dashboard/posts` | `dashboard.posts.index` | authenticated |
| `GET` | `/dashboard/posts/create` | `dashboard.posts.create` | authenticated |
| `GET` | `/dashboard/posts/{post:id}/edit` | `dashboard.posts.edit` | authenticated + policy |
| `GET` | `/dashboard/comments` | `dashboard.comments` | authenticated |
| `GET` | `/dashboard/taxonomy` | `dashboard.taxonomy` | authenticated |
| `GET` | `/dashboard/seller` | `dashboard.seller.index` | `permission:product:create` |
| `GET` | `/dashboard/admin` | `dashboard.admin.index` | `permission:admin:access` |
| `GET` | `/dashboard/admin/users` | `dashboard.admin.users` | `permission:admin:access` |

### Two design decisions worth stating

**`/dashboard` is deliberately unguarded.** It is the landing page for *every*
role, and `pages::dashboard.⚡index`'s `mount()` forwards a seller or admin to
their own dashboard via `redirectRoute($routeName, navigate: true)`. Guarding it
with a permission would make it unreachable for the roles that must land there
first in order to be forwarded.

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

## 5. Settings routes

Two groups with **different** guards. The split is deliberate.

| Method | URI | Name | Guard |
| --- | --- | --- | --- |
| `GET` | `/settings` | — | `auth` → redirects to `/settings/profile` |
| `GET` | `/settings/profile` | `profile.edit` | `auth` **only** |
| `GET` | `/settings/appearance` | `appearance.edit` | `auth`, `verified` |
| `GET` | `/settings/security` | `security.edit` | `auth`, `verified`, `password.confirm`\* |
| `GET` | `/settings/teams` | `teams.index` | `auth`, `verified` |
| `GET` | `/settings/teams/{team}` | `teams.edit` | `auth`, `verified`, `EnsureTeamMembership` |

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

`/settings/teams/{team}` binds `{team}`, not `{team:slug}` — the model's
`getRouteKeyName()` supplies the slug, and `EnsureTeamMembership` on the route
refuses a non-member before the component boots.

`/.well-known/passkey-endpoints` is a JSON discovery document, returns
`security.edit` for both `enroll` and `manage`, and is intentionally
unauthenticated.

---

## 6. Passkey routes

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

## 7. Two-factor management routes

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

## 8. Password confirmation routes

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

## 9. Starter-kit route — retained

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/{current_team}/dashboard` | `team.dashboard` | `auth`, `verified`, `EnsureTeamMembership` |

This is the only starter-kit route left registered.

**Why it still exists.** `routes/web.php` documents the reason: the team
invitation and team-management flows link to it, and removing it would break
them until they are repointed at `/dashboard`. It renders
`resources/views/dashboard.blade.php`, which is also starter-kit scaffolding.

**It is not the application's dashboard.** Nothing a user is directed to by
login, registration or verification reaches it — all of those now resolve
through the role-aware redirect. It is reachable only by typing the URL or
following a team flow, and it is the last piece of the starter kit with a live
route. Removing it is a contained follow-up: repoint the team links at
`/dashboard`, delete the route, delete `views/dashboard.blade.php`, delete
`layouts/app/*`.

---

## 10. Package routes (not listed individually)

| Prefix | Source | Count |
| --- | --- | --- |
| `livewire-*/` | Livewire transport, assets, file upload, previews | 8 |
| `flux/` | Flux asset manager (JS, editor, flags) | 8 |
| `storage/{path}` | Local filesystem serving | 2 |
| `_boost/browser-logs`, `up` | Laravel Boost, health check | 2 |

None should be relied on by application code. `livewire-*` includes a build hash
that changes between releases.

---

## 11. Conventions

**Route names mirror URIs, with dots for nesting.**

```
/dashboard          → dashboard.index
/dashboard/posts    → dashboard.posts.index
/settings/teams     → teams.index        ← deliberate exception
```

`teams.*` rather than `settings.teams.*` because the team routes were named
before they moved under `/settings`, and the name is referenced by redirects and
tests. Renaming would be tidier and is not worth the churn.

**View routes use `Route::livewire`.** The second argument is always a
`pages::` or `layouts::` name. A typo is a runtime 500, not a build failure —
which is why route names and their components are exercised by tests rather
than trusted.

**Model binding keys are explicit.** `{post:slug}` on public routes,
`{post:id}` on dashboard routes, `{team:slug}` on team routes. Never a bare
`{post}`, because the default key silently changes if the model's
`getRouteKeyName()` changes.

**Guards are permission-based, not role-based.** Every role check is expressed
as `permission:<ability>` or a policy. There is no `->middleware('role:admin')`
anywhere in the codebase, and there should not be: a route that names a role
must be edited every time a role's permissions change.

---

## 12. Known issues

| # | Issue | Impact |
| --- | --- | --- |
| 1 | `team.dashboard` and `views/dashboard.blade.php` are live starter-kit scaffolding | Confusing: two things called "dashboard". §9. |
| 2 | `settings/teams` is named `teams.*`, not `settings.teams.*` | Inconsistent with its URI. §11. |
| 3 | `dashboard.posts.create` and `.edit` differ only by a bound model | Intentional, but means the "create" guard cannot be expressed in middleware. §4. |
| 4 | `dashboard.index` is unguarded by permission | Intentional — it is the forwarding entry point for every role. §4. |
| 5 | `settings/teams/{team}` binds `{team}`, not `{team:slug}` | Relies on `Team::getRouteKeyName()`. Changing that method would silently change the URI shape. |

On issue 5: the binding key is implicit where the dashboard's post routes are
explicit (`{post:id}`). Making the team route explicit too would remove the
dependency on a model method for a URL's shape.
