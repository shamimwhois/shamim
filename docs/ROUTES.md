# Route Reference

Every route in the application, transcribed from `php artisan route:list`.
**135 routes** are registered — 25 of them package plumbing summarised in §13,
the rest listed individually below. The package side carries a build hash that
changes between releases, and `_dusk/*` appears only when Dusk is installed and
the app is `local`, so a total taken in production will be lower.

Where a guard is stated, it is the *enforced* guard — the route middleware, not
the intent. Where the two differ, the difference is called out.

---

## 1. Route files

| File | Contains |
| --- | --- |
| `routes/web.php` | Public pages, the role dashboards, the admin workspace, the platform modules, the editor AI endpoints, the composer's media door, backward-compatible redirects |
| `routes/settings.php` | Account settings, passkey endpoints |
| `routes/console.php` | No HTTP routes — two scheduled commands: `feed:publish-scheduled` every minute, `feed:prune-media` daily |
| Framework / Fortify / Livewire | Auth, 2FA, passkeys, Livewire transport, Flux assets |

There is **no `routes/api.php`** — see [`API.md`](API.md).

---

## 2. Public routes

No authentication. These are the pages a visitor or crawler sees.

| Method | URI | Name | Component / Controller |
| --- | --- | --- | --- |
| `GET` | `/` | `home` | `pages::home` |
| `GET` | `/about` | `about` | `pages::about` |
| `GET` | `/blog` | `blog.index` | `pages::blog.index` |
| `GET` | `/blog/{post:slug}` | `blog.show` | `pages::blog.show` |
| `GET` | `/work/{project}/{doc?}` | `work.show` | `pages::work.show` — the optional segment is matched against the project's own docs index |
| `GET` | `/feed` | `feed.index` | `pages::feed` |
| `GET` | `/feed/{slug}` | `feed.show` | `pages::feed.show` — a string parameter, not a bound model |
| `GET` | `/feed/attachments/{attachment}` | `feed.attachments.show` | `FeedAttachmentController` — signed, temporary |
| `GET` | `/communities` | `communities.index` | `pages::communities.index` |
| `GET` | `/errors/{code}` | `errors.show` | `ErrorPageController` — `whereIn('code', 400\|401\|403\|404\|500\|503)`; answers with the real status |
| `GET` | `/feed.xml` | `feed` | `FeedController` |
| `GET` | `/sitemap.xml` | `sitemap` | `SitemapController` |
| `GET` | `/robots.txt` | `RobotsController` (no name) | `RobotsController` |

Middleware: `web` only.

The error pages deserve a note: `/errors/{code}` is the preview surface for the
same views the exception handler renders on a real `abort()` — the framework's
`errors.{status}` discovery picks up `resources/views/errors/{code}.blade.php`,
which all delegate to the reusable `x-app.errors.page` component (copy sourced
from `App\Support\HttpErrorCatalog`). Uncaught (non-HTTP) exceptions are mapped
to the styled 500 by a renderable in `bootstrap/app.php`, but only outside debug
mode, where the trace matters more than the friendly page.

`/feed` wears the default public shell (`layouts::guest.home`): the shared
header, footer and auth dialogs are the site's own, and the page no longer opts
out with `#[Layout]`. Every feed filter — topic, sort, type, tag, search — is
URL-bound state on that component, so a filtered view is a shareable link and
survives a reload.

**Route-model binding keys matter here.** `feed.show` resolves its `{slug}` by
hand inside `mount()`, so the component can run the visibility rule on the row it
found rather than trusting the binding: `visible()` first (the corpus-wide floor)
and then `isVisibleTo($reader)` (the reader-shaped one), each answering `404`. A
post shared with its author alone and a post that does not exist give the same
answer, which is the point. `blog.show` additionally:

- publishes `draft` posts only to their author,
- returns `404` — not `403` — for a draft belonging to someone else, so the
  route does not confirm that an unpublished post exists.

**Livewire's page controller answers most of this file's routes, and
`php artisan route:list --except-vendor` therefore hides them.** The plain command
shows **135 routes**; the `--except-vendor` form shows 22, because every
`Route::livewire(...)` entry resolves to `Livewire\Mechanisms ›
LivewirePageController` and is filtered out with the vendor code. Anyone auditing
the route table from that 22 is looking at the exceptions and calling them the rule.

Those exceptions are the application's six real controllers:

| Controller | Routes | Returns |
| --- | --- | --- |
| `FeedController` | `feed.xml` | RSS XML |
| `SitemapController` | `sitemap.xml` | sitemap XML |
| `RobotsController` | `robots.txt` | plain text |
| `FeedAttachmentController::show` | `feed/attachments/{attachment}` | bytes, signed (below) |
| `ErrorPageController` | `errors/{code}` | the catalogued status pages |
| `Editor\EditorAiController` | six `editor/ai/*` routes | JSON, called by `editor-ai.js` |

The first three are plain controllers because they return XML and plain text, not
HTML. The last three exist because a Livewire page is the wrong shape for them: an
attachment is a streamed file with a signature, an error page must render without
a component context to render inside, and the editor's AI calls are fetch requests
from Alpine that must answer with JSON and answer fast.

**The composer has no route of its own.** Writing a post is not a page visit: the
entry point is the `x-app.ui.modal.trigger` in `components/feed/composer.blade.php`
and the form is `pages::feed.composer-modal`, so a publish is a Livewire update
request, not a `POST /feed`. That is a deliberate omission rather than a gap —
there is no form action for an attacker to find, no CSRF token to get wrong, and
nothing to authorise before the component runs. What replaces the route is a
policy (`App\Policies\FeedPostPolicy`), a per-account rate limiter (`feed-publish`,
5 posts / 60 s, lifted for admins) and a 10-second duplicate guard, all of which
fire inside `App\Actions\Feed\ComposeFeedPost` however the call arrived.

`feed.attachments.show` is the exception, and the only route in the application
whose *only* purpose is to move bytes. The composer's images live on the
`feed_media` disk, which is configured `'serve' => false`, so no stock storage URL
reads them and this route is the single door. It is declared **before**
`feed/{slug}` so the two-segment detail route can never claim a three-segment
path. Two independent locks stand in front of it: `URL::hasValidSignature()`,
which answers `400` — a minted link is valid for ten minutes, and a forged
attachment id and an expired stamp both fail at the same place — then
`Gate::allows('viewAttachment', …)`, which re-runs the post's visibility rule
against *this* request and answers `404`. A forwarded, expired, or since-restricted
signed URL therefore fails closed, and it fails as not-found rather than forbidden
so a private post's existence is not confirmed.

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
| `GET` | `/seller/dashboard` | `seller.dashboard` | `auth`, `verified`, `permission:product:create` |
| `GET` | `/admin/dashboard` | `admin.dashboard` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/posts` | `admin.posts.index` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/posts/create` | `admin.posts.create` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/posts/{post:id}/edit` | `admin.posts.edit` | `auth`, `verified`, `permission:admin:access` + policy |
| `GET` | `/admin/comments` | `admin.comments` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/taxonomy` | `admin.taxonomy` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/feed` | `admin.feed` | `auth`, `verified`, `permission:admin:access`, `permission:comment:moderate` |
| `GET` | `/admin/users` | `admin.users` | `auth`, `verified`, `permission:admin:access`, `permission:user:manage` |
| `GET` | `/admin/marketplace` | `admin.marketplace` | `auth`, `verified`, `permission:admin:access`, `permission:product:review` |
| `GET` | `/admin/about` | `admin.about` | `auth`, `verified`, `permission:admin:access`, `permission:about:manage` |
| `GET` | `/admin/ai-hub` | `admin.ai-hub` | `auth`, `verified`, `permission:admin:access`, `permission:ai-hub:manage` |
| `GET` | `/admin/dev-lab` | `admin.dev-lab` | `auth`, `verified`, `permission:admin:access` |
| `GET` | `/admin/ideas` | `admin.ideas` | `auth`, `verified`, `permission:admin:access` |

`admin.dev-lab` and `admin.ideas` are platform-wide consoles over the two
modules' rows: every snippet and execution log entry for the lab, every idea and
plan for Ideas. They carry only the group's `admin:access` and no narrower
permission, because there is nothing to divide: the console reads across accounts
and, for snippets, removes them. Unlike the other module surfaces they *are*
offered as destinations — both appear in the admin rail, because unlike feed
moderation they now have work in them.

What a console may *not* do is the interesting half of the design. The Ideas
console lists and filters; it never edits. An idea and a plan are somebody's
thinking, and rewriting it is not what an operator key buys. The lab console
lists snippets and runs and may delete a snippet, but holds no editor at all:
the sandbox is the only place code is written, and it belongs to the account
writing it. `admin.about` and `admin.ai-hub` follow the marketplace pattern of
declaring a second, narrower permission in addition to the panel key; the two
module consoles deliberately do not, because `ideas:use` and `dev-lab:use`
describe authoring, not oversight, and reusing them as the moderation lock would
let every account that can write into a module see everyone else's rows.

`/dashboard` is the **user** role's home and a read-only overview of that
account's own content. The authoring screens — posts, the editor, the comment
queue, taxonomy — live under `/admin` and are gated by `permission:admin:access`,
so a plain user or a seller is refused them and sees no link to them anywhere.
Only the *homes* are distinct: `/dashboard` stays reachable by every
authenticated, verified account even though it is the user role's landing page.
The seller and admin homes are guarded by a **permission**, not by a role name —
a role check would drift from the permission matrix, a permission check cannot.

What limits the authoring screens is two different things, in that order.
`admin:access` decides who may open the page at all. Inside it, every query is
scoped to the signed-in account (`authoredBy(auth()->user())`), so an admin
editing from `/admin` still sees only their own posts. Note that a Livewire
mutation is a `POST /livewire/update`, which never passes through the route
group — each of those four components re-checks `admin:access` in its own
`boot()`, and that re-check is the actual defence.

The whole `dashboard.*` group is declared under `middleware(['auth',
'verified'])`, `prefix('dashboard')`, `name('dashboard.')`, and holds one route
today. The seller group uses `prefix('seller')`, `name('seller.')` and the admin
group `prefix('admin')`, `name('admin.')` — each declares its middleware, URL
prefix, and name prefix once, so a route line states only what is unique about
that route. A group with a single page is still a group, because the prefix and
the permission belong to the *space*, not to the page in it.

### Two design decisions worth stating

**`/dashboard` is deliberately unguarded by permission.** It is the landing page
for *every* role, and `pages::dashboard.index`'s `mount()` forwards a seller or
admin to the dashboard their role owns via `redirectRoute($routeName, navigate:
true)`. Guarding it with a permission would make it unreachable for the roles
that must land there first in order to be forwarded — the redirect is a real
HTTP redirect so the URL always names what is on screen.

**`admin/posts/{post:id}/edit` binds by id, not slug.** The editor changes the
post you own; a slug is a publication concern, not an authoring one — and it is
something the editor itself rewrites. The *public* route binds by slug. The
different key is intentional and the routes are not interchangeable.

**`admin.posts.create` and `admin.posts.edit` render the same
component** (`pages::admin.posts.edit`). One editor serves both create and
update; the presence of a bound `Post` is what distinguishes them. This is why
the route guard stops at `admin:access` — the authorisation question ("may this
account edit *this* post") cannot be answered until the model is bound, so it is
answered inside `mount()`, which 404s a post belonging to someone else rather
than admitting it exists.

---

## 5. Platform module routes

Four product modules, each with its own top-level URL space. Three are built —
Ideas, the Developer Lab and the Marketplace — and the AI Hub is built as a
reading surface whose two curated shelves still render placeholders (via
`resources/views/components/app/ui/module-placeholder.blade.php`).

All four follow the contract the marketplace stated first: **reading is public,
writing is gated**. A guest may browse published ideas, published snippets and
product listings without an account, because reading is not entering a workspace.
What an account may *do* inside a module is decided by `ideas:use`,
`dev-lab:use` and `product:create`, and those guard the workspace routes below.
Neither module permission gates a URL a reader needs, and no permission case was
added to build either module — see [`PERMISSIONS.md`](PERMISSIONS.md).

### Public surfaces

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/dev-lab` | `dev-lab.index` | — |
| `GET` | `/dev-lab/gallery` | `dev-lab.gallery` | — |
| `GET` | `/dev-lab/snippets/{snippet:slug}` | `dev-lab.snippets.show` | — |
| `GET` | `/ai-hub` | `ai-hub.index` | — |
| `GET` | `/ai-hub/articles` | `ai-hub.articles` | — |
| `GET` | `/ai-hub/articles/{article:slug}` | `ai-hub.article` | — |
| `GET` | `/ai-hub/topics` | `ai-hub.topics` | — |
| `GET` | `/ai-hub/topics/{topic:slug}` | `ai-hub.topic` | — |
| `GET` | `/ai-hub/paths` | `ai-hub.paths` | — |
| `GET` | `/ai-hub/paths/{path:slug}` | `ai-hub.path` | — |
| `GET` | `/ai-hub/resources` | `ai-hub.resources` | — |
| `GET` | `/marketplace` | `marketplace.index` | — |
| `GET` | `/marketplace/categories/{category:slug}` | `marketplace.category` | — |
| `GET` | `/marketplace/{product:slug}` | `marketplace.show` | — |
| `GET` | `/ideas` | `ideas.index` | — |
| `GET` | `/ideas/{idea:slug}` | `ideas.show` | — |
| `GET` | `/topics` | `topics.index` | — |
| `GET` | `/topics/{topic:slug}` | `topics.show` | — |

The marketplace category route renders the same component as the index with the
filter pre-seeded, so there is one definition of "the catalogue" rather than two.
`marketplace.show` resolves `{product:slug}` by slug and returns 404 for any
listing that is not `Published` — unless the viewer is its creator or holds
`product:review`, which is the same rule the component applies in
`MarketplaceProduct::isVisibleTo()`.

`ideas.show` and `dev-lab.snippets.show` apply the identical rule through their
own models: `Idea::isVisibleTo()` and `LabSnippet::isVisibleTo()` admit the
author and an `admin:access` holder always, and a guest or a stranger only when
the row is both `Public` **and** carries a `published_at`. Publication is the
conjunction, never the flag alone, and it is why an idea somebody merely
flipped to `public` without a timestamp has no public URL. The pair is written in
one place each — `SyncIdeaVisibility` and `SaveLabSnippet` — so a row cannot
become readable through a code path nobody audited.

`/topics` is the one public surface that belongs to no module. It lists the
platform-owned subjects both modules file into, and `topics.show` opens one of
them with its published ideas and published snippets side by side. Its pair
sits at the same depth as the two module detail routes but under a different
first segment, so nothing about the ordering rules below applies to it and no
word needed reserving: an idea slugged `topics` lives at `/ideas/topics`, one
segment deeper, and neither route can swallow the other. `topics.show` refuses
with a 404 through `PublicTaxonomy::isBrowseable()` rather than rendering an
empty shelf, which covers two states at once — a subject switched off, and a
subject that exists only because it was seeded and has never carried
a published row.

The two landings, `ideas.index` and `dev-lab.gallery`, now take `?topic=`,
`?author=` and `?sort=` beside the `?q=` and `?page=` they already had, and each
keeps its own pre-existing facet — `?tab=` on the ideas index, `?lang=` on the
gallery. They are `#[Url]`-bound properties on the components with their default
hidden, so an unfiltered page keeps a clean canonical URL. A value that fails the
shared gate (an inactive subject, a handle with nothing published behind it, an
unknown sort word) is treated as no filter rather than as an error: these are
readers' URLs, typed and pasted, and a page that 404s on a stale filter is a page
that teaches somebody nothing. The parameters are documented in
[`API.md`](API.md).

The URL space is claimed on purpose: `/ideas`, `/dev-lab`, `/ai-hub` and
`/marketplace` are top-level names that must not be taken by anything else.
Where a surface *is* gated, `EnsureAccountPermission` redirects a guest to the
home page with the sign-in dialog open rather than refusing them, because they
may hold the permission after authenticating.

### Ideas workspace

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/ideas/dashboard` | `ideas.dashboard` | `auth`, `verified`, `permission:ideas:use` |
| `GET` | `/ideas/create` | `ideas.create` | same |
| `GET` | `/ideas/categories` | `ideas.categories` | same |
| `GET` | `/ideas/plans` | `ideas.plans` | same |
| `GET` | `/ideas/plans/create` | `ideas.plan.create` | same |
| `GET` | `/ideas/plans/{plan:slug}` | `ideas.plan` | same |
| `GET` | `/ideas/plans/{plan:slug}/edit` | `ideas.plan.edit` | same |
| `GET` | `/ideas/{idea:slug}/edit` | `ideas.edit` | same |
| `GET` | `/admin/ideas` | `admin.ideas` | `auth`, `verified`, `permission:admin:access` |

### Developer Lab workspace

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/dev-lab/dashboard` | `dev-lab.dashboard` | `auth`, `verified`, `permission:dev-lab:use` |
| `GET` | `/dev-lab/sandbox` | `dev-lab.sandbox` | same |
| `GET` | `/dev-lab/snippets` | `dev-lab.snippets` | same |
| `GET` | `/dev-lab/snippets/create` | `dev-lab.snippets.create` | same |
| `GET` | `/dev-lab/snippets/{snippet:slug}/edit` | `dev-lab.snippets.edit` | same |
| `GET` | `/dev-lab/history` | `dev-lab.history` | same |
| `GET` | `/admin/dev-lab` | `admin.dev-lab` | `auth`, `verified`, `permission:admin:access` |

**Registration order is the property that keeps the two audiences apart, and it
is not incidental.** In each module the workspace group is declared before the
public pair, and inside the group the literals come before the parametric routes:
`/ideas/plans` before `/ideas/{idea:slug}`, `snippets/create` and
`snippets/{snippet:slug}/edit` before `/dev-lab/snippets/{snippet:slug}`. Laravel
matches in registration order, so an idea whose slug happens to be `plans` can
never swallow the plans screen — and `App\Rules\IdeaSlug`, `PlanSlug` and
`SnippetSlug` refuse that name from the other direction, at the write, so a row
is never *born* claiming a URL that already opens a screen. Both defences are
required: the ordering protects the screens from the data, the rule protects the
data from itself.

`ideas.create` and `ideas.edit` render one component, as do
`ideas.plan.create` / `ideas.plan.edit` and `dev-lab.snippets.create` /
`dev-lab.snippets.edit` — the presence of a bound model is what distinguishes
creating from updating, exactly as in the post editor. Authoring routes bind by
slug too, which is a deliberate difference from `/admin/posts/{post:id}/edit`: a
module row's slug is cheap to type, stable once reserved, and the components 404
rather than 403 a row belonging to somebody else. `LabWorkspace` is the exception
that keeps an `id` key: a workspace is never public, so it needs no canonical
name, and the sandbox takes no route parameter at all — it loads the account's
own current workspace.

The hub's two curated shelves share the `/ai-hub` URL space without sharing its
visibility — they are staff pages:

| Method | URI | Name | Middleware |
| --- | --- | --- | --- |
| `GET` | `/ai-hub/dev-lab` | `ai-hub.dev-lab.index` | `auth`, `verified`, `permission:ai-hub:manage` |
| `GET` | `/ai-hub/ideas` | `ai-hub.ideas.index` | `auth`, `verified`, `permission:ai-hub:manage` |

They are different pages from the public `/dev-lab` and `/ideas` landings, which
is why the names stay namespaced under `ai-hub.` rather than reusing
`dev-lab.index` / `ideas.index`. Building the Ideas workspace and the sandbox did
not build these two: a shelf is hub-side curation over module rows, which is a
different object from the module's own console at `/admin/ideas` and
`/admin/dev-lab`. They stay placeholders until that curation model exists, and
`RoutingStructureTest` asserts that a placeholder still reads as one.

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

The role dashboards moved from nested paths to role-owned top-level spaces, and
the authoring screens later moved from `/dashboard` into `/admin`. These
redirects keep existing bookmarks, external links and in-flight email working.
They are **301**, not 302: the move is permanent, so a browser or search engine
is correct to cache it.

| Method | URI | Name | Target | Middleware |
| --- | --- | --- | --- | --- |
| `GET` | `/dashboard/seller` | `dashboard.seller.redirect` | `/seller/dashboard` (301) | — |
| `GET` | `/dashboard/admin` | `dashboard.admin.redirect` | `/admin/dashboard` (301) | — |
| `GET` | `/dashboard/posts` | `dashboard.posts.index` | `/admin/posts` (301, query string kept) | — |
| `GET` | `/dashboard/posts/create` | `dashboard.posts.create` | `/admin/posts/create` (301) | — |
| `GET` | `/dashboard/posts/{id}/edit` | `dashboard.posts.edit` | `/admin/posts/{id}/edit` (301) | — |
| `GET` | `/dashboard/comments` | `dashboard.comments` | `/admin/comments` (301, query string kept) | — |
| `GET` | `/dashboard/taxonomy` | `dashboard.taxonomy` | `/admin/taxonomy` (301) | — |
| `GET` | `/teams/dashboard` | `team.dashboard` | `/dashboard` (301) | `auth`, `verified` |
| `GET` | `/settings/teams` | `teams.index.redirect` | `/settings/security` (301) | `auth`, `verified` |

Three things about the author redirects are deliberate:

* **They keep the old route *names* as well as the old paths.** A `route()` call
  that outlives the move, or an `url.intended` written by the permission
  middleware, should resolve rather than throw.
* **`/dashboard/posts` and `/dashboard/comments` are closures, not
  `Route::redirect()`.** Those pages keep their filters in the query string —
  `?status=draft`, `?q=caching`, `?post=7` *are* the page's state, and a static
  redirect would silently drop them.
* **`{id}` is a scalar with `->whereNumber('id')`, not a bound model.** These
  paths sit outside `auth`, so a route that resolved a post would answer 404 for
  a missing one and 301 for an existing one, telling a guest which post IDs
  exist. A non-numeric segment simply does not match.

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
| `livewire-*/` | Livewire transport, assets, file upload, upload/preview endpoints | 9 |
| `flux/` | Flux asset manager (JS, editor, flags) | 9 |
| `storage/{path}` | Local filesystem serving | 2 |
| `_boost/browser-logs`, `up` | Laravel Boost, health check | 2 |
| `_dusk/*` | Laravel Dusk page/asset capture, `local` only | 3 |

None should be relied on by application code. `livewire-*` includes a build hash
that changes between releases.

---

## 14. Conventions

**Route names mirror URIs, with dots for nesting.**

```
/dashboard          → dashboard.index
/admin/posts        → admin.posts.index
/seller/dashboard   → seller.dashboard
/admin/users        → admin.users
```

Role homes are named `<role>.dashboard`, not `dashboard.<role>.*`, because the
role is the first path segment and the name follows the path. The redirects are
the exceptions that prove the rule: `team.dashboard`, `teams.index.redirect` and
the five `dashboard.posts.*` / `dashboard.comments` / `dashboard.taxonomy` names
keep labels that no longer match their target, on purpose, so old references
still resolve.

**View routes use `Route::livewire`.** The second argument is always a
`pages::` or `layouts::` name. A typo is a runtime 500, not a build failure —
which is why route names and their components are exercised by tests rather
than trusted.

**Model binding keys are explicit.** `{post:slug}` on public routes,
`{post:id}` on the `/admin` authoring routes. Never a bare `{post}`, because the
default key
silently changes if the model's `getRouteKeyName()` changes. No route binds a
team, because no route is team-scoped.

The one bare parameter is `{attachment}`. It binds by id, and that is the right
key here rather than a lapse: a feed image has no slug, its stored filename is a
UUID, and the id is not the secret — the signature is. The route it appears in is
also the only one where the bound model is not the resource a reader is looking
at, just the row that names the bytes.

**Guards are permission-based, not role-based.** Every role check is expressed
as `permission:<ability>` or a policy. There is no `->middleware('role:admin')`
anywhere in the codebase, and there should not be: a route that names a role
must be edited every time a role's permissions change.

---

## 15. Known issues

| # | Issue | Impact |
| --- | --- | --- |
| 1 | `admin.posts.create` and `.edit` differ only by a bound model | Intentional, but means the "create" guard cannot be expressed in middleware. §4. |
| 2 | `dashboard.index` is unguarded by permission | Intentional — it is the forwarding entry point for every role. §4. |
| 3 | The AI Hub's two curated shelves render placeholders, not features | Intentional. `/ai-hub/dev-lab` and `/ai-hub/ideas` need a hub-side curation model; the modules they are named after are built and have their own surfaces. §5. |
| 4 | `team.dashboard` and `teams.index.redirect` name routes that no longer match their behaviour | Historic names kept so old links and docs resolve. §7, §14. |
| 5 | The feed has no HTTP write surface — no `POST /feed`, no route to a draft, no per-post edit URL | Intentional. The composer modal owns every verb through `App\Actions\Feed\*`, so nothing here needs a URL yet. §2. |
| 6 | `feed.attachments.show` is unreachable by path and its signed links do not survive ten minutes | Intentional. Copying an image URL into a document or a mail template will break; the reader must be entitled at request time. §2. |

On issue 3: a shelf is curation owned by the hub, not another view of the
module's rows, so it is deliberately not the same page as `/ideas` or
`/dev-lab` — and not the same page as `/admin/ideas` or `/admin/dev-lab`, which
are oversight rather than selection. Each module keeps one placeholder check in
`RoutingStructureTest` so that a page promising a feature it does not have is
caught by the suite rather than by a visitor.
