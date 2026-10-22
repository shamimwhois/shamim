# API Design

> **Status: NOT YET IMPLEMENTED.**
> This document specifies the intended HTTP API surface. There is currently
> **no `routes/api.php`**, **no Laravel Sanctum**, and **no API controllers** in
> this repository. Nothing described below is callable today. It is published
> as a design so that the web application, the database schema and the future
> API can be reviewed against one contract before any of them are written in a
> way that is expensive to change.
>
> Anything an integrator reads here should be treated as a proposal, not
> documentation of a live service.

---

## 1. Why an API at all

The application today is server-rendered Livewire. Every mutation is a
round-trip that swaps HTML. That is the right architecture for a portfolio and
publishing tool, and it is the architecture the product will keep.

An API is not a replacement for that. It exists to serve three specific
consumers that HTML cannot:

| Consumer | Why HTML does not work |
| --- | --- |
| **Feed + syndication clients** | RSS is already served, but a JSON feed is what modern aggregators and newsletter tooling consume. |
| **Mobile / desktop clients** | A native client cannot drive a Livewire component tree. |
| **Third-party integrations** | Importing posts, syncing comments, pulling analytics — each needs authenticated, scoped, machine-readable access. |

If none of these ever materialise, the correct decision is to **not build this
API**. A public API is a permanent compatibility surface. It should be added
because something needs it, not because a REST layer is assumed to be good
practice.

---

## 2. Versioning and base URL

```
https://<host>/api/v1
```

- The version is **in the path**, not a header. It is visible in logs, obvious
  in a browser, and trivially routable.
- One version is served at a time. `/api/v2` is introduced only for a
  **breaking** change; additive changes ship inside `v1` without ceremony.
- Breaking changes, defined precisely: removing a field, renaming a field,
  changing a field's type, tightening validation, or changing a status code for
  an existing condition.
- Deprecation policy: a deprecated endpoint keeps working for **at least 6
  months**, emits `Sunset` and `Deprecation` response headers, and is listed in
  a changelog at `docs/API-CHANGELOG.md`.

---

## 3. Authentication

**Proposed mechanism: Laravel Sanctum personal access tokens.**

Sanctum is chosen over Passport because the product has no OAuth
authorization-server use case. Nobody needs to let a third-party app act on
behalf of a user through a consent screen. What is needed is: a client holds a
token, the token maps to one user, and the token carries explicit abilities.

### 3.1 Obtaining a token

```
POST /api/v1/auth/tokens
```

```json
{
  "email": "user@example.com",
  "password": "••••••••",
  "device_name": "iPhone 15",
  "abilities": ["posts:read"]
}
```

Response `201`:

```json
{
  "data": {
    "token": "1|abc123…",
    "device_name": "iPhone 15",
    "abilities": ["posts:read"],
    "expires_at": "2027-01-12T00:00:00+00:00"
  }
}
```

The plaintext token is returned **exactly once**. Only its hash is stored.

### 3.2 Sending a token

```
Authorization: Bearer 1|abc123…
```

### 3.3 Two-factor accounts

An account with two-factor enabled **cannot** receive a token from a password
alone. The response is `409 Conflict` with a `challenge_token` that must be
exchanged:

```
POST /api/v1/auth/two-factor-challenge
{ "challenge_token": "...", "code": "123456" }
```

This mirrors the web flow, where the second factor is a redirect to a
challenge screen rather than a failed login.

### 3.4 Revocation

```
DELETE /api/v1/auth/tokens/current     → revoke the calling token
DELETE /api/v1/auth/tokens             → revoke every token for the account
```

Revocation is **immediate** — tokens are checked against the database on every
request, not cached. Given the request volume this product will see, the
correctness win outweighs the query.

---

## 4. Account roles and token abilities

The web application defines three account roles with an additive permission
hierarchy (`app/Enums/AccountRole.php`):

| Role | Level | Permissions | Inherits |
| --- | --- | --- | --- |
| `user` | 1 | 6 | — |
| `seller` | 2 | 15 | everything `user` has |
| `admin` | 3 | 30 | everything, plus administration |

**Token abilities must never exceed the account's role.** A token is a
*restriction* on top of a role, never a grant. A `user`-role account that
requests `["admin:access"]` gets a `422` at token-creation time — not a token
that silently fails later.

The ability names in a token map one-to-one onto the `AccountPermission` enum
values (`post:create`, `post:update-own`, `admin:access`, …). This is
deliberate: two naming systems for the same concept is how authorization bugs
are born.

Requested abilities are intersected with the role at creation and the effective
set is returned in the response, so a client can see what it actually received.

---

## 5. Response envelope

Every response — success or failure — uses the same outer shape.

**Single resource:**

```json
{
  "data": {
    "id": 42,
    "type": "post",
    "attributes": { "title": "…", "slug": "…" },
    "relationships": { "author": { "type": "user", "id": 7 } },
    "links": { "self": "/api/v1/posts/42" }
  }
}
```

**Collection:**

```json
{
  "data": [ … ],
  "meta": {
    "pagination": {
      "total": 137,
      "per_page": 25,
      "current_page": 1,
      "last_page": 6,
      "from": 1,
      "to": 25
    }
  },
  "links": {
    "first": "/api/v1/posts?page=1",
    "prev": null,
    "next": "/api/v1/posts?page=2",
    "last": "/api/v1/posts?page=6"
  }
}
```

**Error:**

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "errors": {
      "title": ["The title field is required."]
    }
  }
}
```

### 5.1 Why an envelope

A top-level `data` key costs a few bytes and buys three things: room for `meta`
and `links` beside a resource without collisions, an unambiguous way to
distinguish "empty collection" from "error", and a response shape that does not
change if a resource ever grows a field named `error`.

---

## 6. Resource catalogue

Resources follow the existing domain model. `id` is the primary key **unless
noted**; public-facing resources are keyed by `slug` where they have one,
because a slug is what a client can hold across a re-import.

### 6.1 Posts

| Method | Path | Ability | Notes |
| --- | --- | --- | --- |
| `GET` | `/posts` | *(public)* | Published posts only. Filters below. |
| `GET` | `/posts/{slug}` | *(public)* | Published only, unless the caller authored it. |
| `POST` | `/posts` | `post:create` | Creates a draft. |
| `PATCH` | `/posts/{id}` | `post:update-own` | Author only. `post:update-any` extends to others. |
| `DELETE` | `/posts/{id}` | `post:delete-own` | Soft delete. |
| `POST` | `/posts/{id}/publish` | `post:publish` | Transitions `draft` → `published`. |

Collection filters:

| Parameter | Type | Behaviour |
| --- | --- | --- |
| `status` | enum `draft\|published\|archived` | Requester's own posts only. Ignored for public reads. |
| `category` | slug | Exact category match. |
| `tag` | slug, repeatable | AND across repeated values. |
| `author` | id | Filter by author. |
| `q` | string | Full-text over title and body. |
| `sort` | `published_at\|-published_at\|title` | Default `-published_at`. |
| `per_page` | int, 1–100 | Default 25. |

Fields:

```json
{
  "id": 42,
  "slug": "shipping-a-design-system",
  "title": "Shipping a design system",
  "excerpt": "…",
  "body": "…",
  "status": "published",
  "published_at": "2026-09-14T09:00:00+00:00",
  "reading_time_minutes": 8,
  "author": { "id": 7, "name": "…" },
  "category": { "slug": "engineering", "name": "Engineering" },
  "tags": [{ "slug": "laravel", "name": "Laravel" }],
  "created_at": "…",
  "updated_at": "…"
}
```

`body` is omitted from collection responses and present on single-resource
reads. A 25-item list should not carry 25 full articles.

### 6.2 Comments

| Method | Path | Ability | Notes |
| --- | --- | --- | --- |
| `GET` | `/posts/{slug}/comments` | *(public)* | Approved comments only. |
| `POST` | `/posts/{slug}/comments` | *see note* | Creates `pending`. |
| `PATCH` | `/comments/{id}` | `comment:moderate` | Sets `approved` / `rejected`. |
| `DELETE` | `/comments/{id}` | *see note* | Author or moderator. |

**Note — a real gap.** `AccountPermission` has no create- or delete-comment
ability. Commenting is currently governed by authentication plus the
`comment:moderate` ability for moderation only. The web application lets any
authenticated account comment; an API needs an explicit ability for it. Two
abilities — `comment:create` and `comment:delete-own` — should be **added to
the enum** before this resource is implemented, rather than the API inventing
names that the rest of the application does not recognise.

Moderation is `pending` → `approved` | `rejected`, matching
`CommentStatus`. A comment **never** goes straight to `approved` through the
API, matching the web behaviour.

### 6.3 Categories and tags

| Method | Path | Ability |
| --- | --- | --- |
| `GET` | `/categories` | *(public)* |
| `GET` | `/tags` | *(public)* |
| `POST` | `/tags` | `taxonomy:manage` |

Read-mostly. Creating categories is an administrative action with no
meaningful API consumer today; only tags are creatable so an editor tool can
introduce one inline.

### 6.4 Accounts

| Method | Path | Ability | Notes |
| --- | --- | --- | --- |
| `GET` | `/me` | *(any token)* | The token's own account. |
| `PATCH` | `/me` | *(any token)* | The caller's own name and e-mail. |
| `GET` | `/users` | `user:manage` | Paginated. |
| `GET` | `/users/{id}` | `user:manage` | |
| `PATCH` | `/users/{id}/role` | `role:manage` | Cannot exceed the caller's own level. |

Note that `PATCH /me` needs no ability: a token always authorises its own
holder to edit their own profile, and requiring an ability for it would mean
every account needs a permission granted purely to change their own name. The
existing permissions are coarse — `user:manage` and `role:manage` rather than a
per-verb split — and the API mirrors that granularity rather than pretending to
a finer one.

The role-escalation rule from the web application applies verbatim. Its exact
behaviour (`AccountRole::assignableBy()`), verified against the current code:

| Caller role | May assign |
| --- | --- |
| `user` | `user` |
| `seller` | `user`, `seller` |
| `admin` | `user`, `seller` |

A caller may never assign a role at or above their own, and `admin` is **not
assignable through this path by anyone** — promoting to admin requires a
stronger mechanism than an authenticated API call, because otherwise a single
compromised admin token becomes a way to mint more admins. Attempting to assign
a disallowed role is a `403`, not a silently ignored no-op.

### 6.5 Role dashboards and platform modules

Authorisation is **single-axis**. There is exactly one role enum
(`AccountRole`) and one permission enum (`AccountPermission`); the team-scoped
role and permission axes were removed along with the team feature, and no
`TeamRole` / `TeamPermission` exists. Every ability an account holds is an
`AccountPermission`, resolved from its single account role.

The role-owned surfaces — the route-permission equivalent of the web
dashboards — map onto the same abilities:

| Surface | Path | Ability | Notes |
| --- | --- | --- | --- |
| Reader workspace | `/dashboard` | *(auth + verified only)* | The user role's home. It carries **no** ability guard: the route middleware is `auth` and `verified`, and the sidebar inside it is what filters by permission. A token that can read this surface is a signed-in account, nothing more. Authoring lives in the admin space. |
| Seller workspace | `/seller/dashboard` | `product:create` | The selling home. |
| Admin dashboard | `/admin/dashboard` | `admin:access` | Platform administration. |
| Admin user list | `/admin/users` | `user:manage` | Paginated; mirrors `GET /users`. |
| Admin authoring screens | `/admin/posts`, `/admin/comments`, `/admin/taxonomy` | `admin:access` | The post list, editor, moderation queue and taxonomy editor. Scoped to the signed-in account's own posts. |

The four product modules are each a permission-guarded top-level space, and all
four are built. `ideas:use`, `dev-lab:use`, `ai-hub:use` and `marketplace:use`
describe the *workspace* half of each space; the *public* half — the landing and
detail pages — carries no guard at all, because what is published is published to
strangers. Every module surface therefore splits into three layers, and an API
resource must state which layer it belongs to:

| Layer | Example | Guard |
| --- | --- | --- |
| Public read | `GET /ideas/{slug}` | none; the row is served only when its publication pair says it may be |
| Own workspace | `POST /ideas`, `PATCH /ideas/{id}` | the module permission, plus ownership inside the policy |
| Platform oversight | `/admin/ideas`, `/admin/dev-lab` | `admin:access` alone, and it may read and remove but never rewrite |

| Module | Path | Ability | What it fronts |
| --- | --- | --- | --- |
| Ideas, Plans & Tasks | `/ideas` | `ideas:use` | A capture workspace: ideas with a status workflow, plans converted from them, tasks owned through their plan, per-account categories, and a publishable public surface. `ideas:use` guards the eight workspace routes and every `boot()` in `pages::ideas.*`. |
| Developer Lab | `/dev-lab` | `dev-lab:use` | An editor workspace: HTML/CSS/JS run in a client-side sandbox, snippets stored per language with a public gallery, execution history counted per day. `dev-lab:use` guards the six workspace routes and every `boot()` in `pages::dev-lab.*`. |
| AI Knowledge Hub | `/ai-hub` | `ai-hub:use` | A curated library of generated articles, topics and resources. Note that the hub's reading pages carry **no** route guard — they are public — so `ai-hub:use` is the sidebar entry and nothing more, while `ai-hub:manage` guards the admin console and the hub's two curated shelves. |
| Developer Marketplace | `/marketplace` | `marketplace:use` | A storefront: anyone reads the catalogue without signing in, the platform console gates on `product:review`, and the seller home gates on `product:create` — the only ability in the selling group with a route behind it, and it guards a dashboard, not a listing screen. `marketplace:use` again lights the nav entry rather than a route. |

The asymmetry is worth stating, because the four modules were built in two
different shapes. A **workspace** module has content that belongs to an account, so
its permission guards the screens where that content is written. A **storefront** or
**library** module has content that belongs to the platform and is meant to be read
by strangers, so its permission guards only the door, and the real authorisation
lives in the narrower `product:*` and `ai-hub:manage` abilities. An API resource
inherits whichever shape its module has.

Ability strings are exactly the `AccountPermission` enum values. Because there
is only one axis, a token ability can always be expressed as a plain
account-level string — there is no per-resource scope that would need to be
resolved from a membership row, and consequently no ability name that reads as
a narrower grant than it is.

What the enum does **not** contain is a narrower module permission, and an API
designer should notice, because the instinct is to add `ideas:manage` for the
console. `IdeaPolicy` already encodes the difference: `update` is ownership-only
even for an admin, and `delete` admits `admin:access`. A token that could edit
somebody else's idea would be a token for an ability the web application refuses.

### 6.6 Seller resources

The **catalogue** half of the seller role is real. `MarketplaceProduct`,
`MarketplaceCategory`, `MarketplaceProductVersion` and `MarketplaceReview` all
exist and are seeded; the public storefront (`pages::marketplace.*`) reads them
without an account; and `/admin/marketplace` moderates them through
`app/Actions/Marketplace/*` (approve, reject, feature, review moderation).
`MarketplaceProduct` carries a `user_id`, a `published()` scope and an
`isVisibleTo()` predicate, so the ownership column the API would need is already
in the schema.

The **authoring** half is not. `/seller/dashboard` is the only route in the
`permission:product:create` group, and it states that listings are not available
yet; there is no create or edit screen anywhere, and no `MarketplaceProductPolicy`
for one to resolve through. Nothing in `resources/views` creates a
`MarketplaceProduct`. The shell was built for a seller-facing product pipeline
that has not been written.

The **transaction** half is not either. There is no `Sale` model and no
`SupportTicket` model, so the four abilities that would front them —
`sale:view-own`, `sale:view-any`, `refund:process`, `payment:configure` — and
`support:manage-own` have no surface anywhere in the application. They are
granted, grouped and rendered in the permission matrix, and never checked.

| Method | Path | Ability | Status |
| --- | --- | --- | --- |
| `GET` | `/products` | *(public)* | Backed by `MarketplaceProduct`; published listings only |
| `GET` | `/products/{slug}` | *(public)* | Same `isVisibleTo()` rule as the storefront |
| `POST` | `/products` | `product:create` | **Blocked — no authoring screen and no policy** |
| `PATCH` | `/products/{id}` | `product:update-own` | **Blocked — same**. Admin-side changes go through the console |
| `POST` | `/products/{id}/submit` | `product:submit` | **Blocked**. `ProductStatus` already models the submitted state |
| `GET` | `/sales` | `sale:view-own` | **Blocked — no `Sale` model** |
| `POST` | `/sales/{id}/refund` | `refund:process` | **Blocked** |

Listing the blocked rows is not padding. It records that the seller role is
**half-built in a specific direction**: the inventory exists and is displayed,
while the two verbs a seller would actually need — write a listing, sell a
listing — do not. `RolePlan::productListingLimit()` has something to limit but
nothing to limit it against, and `RolePlan`'s other selling claims describe a
revenue ledger that has not been written.

### 6.7 Ideas resources

The Ideas module is four tables (`ideas`, `idea_plans`, `idea_tasks`,
`idea_categories`); this table lists the two a client reads first, and the three
verbs that are not CRUD: publish, pin and convert. A published idea also carries
subjects from the platform's `topics` table through the `idea_topic` pivot, which is
shared with the Developer Lab and is not a fifth module table.

| Method | Path | Ability | Notes |
| --- | --- | --- | --- |
| `GET` | `/ideas` | *(public)* | Published rows only — `visibility = public` **and** `published_at` set. Filters below. |
| `GET` | `/ideas/{slug}` | *(public)* | Served when `Idea::isVisibleTo()` passes: published to anyone, otherwise the author or an operator. A private row is `404`, not `403`. |
| `POST` | `/ideas` | `ideas:use` | Creates in `inbox`. Slug derives from the title unless supplied. |
| `PATCH` | `/ideas/{id}` | `ideas:use` | **Author only.** `IdeaPolicy::update` has no admin clause, and the API must not be the loophole. |
| `DELETE` | `/ideas/{id}` | `ideas:use` | Author, or `admin:access`. Permanent — the module has no soft deletes. |
| `POST` | `/ideas/{id}/status` | `ideas:use` | Author only, and only along `IdeaStatus::allowedTransitions()`; an illegal jump is `422`, not a silent write. |
| `POST` | `/ideas/{id}/publish` | `ideas:use` | Sets the pair. Refuses unless the idea is `Approved` — approving is a stage, publishing is a decision. |
| `POST` | `/ideas/{id}/pin` | `ideas:use` | Toggles a reading order. Does not change status or visibility. |
| `POST` | `/ideas/{id}/convert` | `ideas:use` | Writes an `IdeaPlan`, links it by `idea_id`, leaves the idea row otherwise untouched. A second call is `409`: one idea converts once. |
| `GET` | `/ideas/categories` | `ideas:use` | The caller's own folders. Slug uniqueness is **per account**, which is why two accounts may both have a folder named "backend". |

Plans and tasks follow the same shape under `/ideas/plans` and
`/ideas/plans/{id}/tasks`, mirroring the web URL space one for one. A
`PATCH /ideas/tasks/{id}` is authorised through its parent plan, because an
`idea_tasks` row carries no `user_id` — there is no column for ownership to drift
from — so the API cannot accept a task id alone as proof of anything.

Collection filters for `GET /ideas`: `status`, `category`, `pinned`, `topic`,
`author`, `q` (title, summary and body), `sort` (`-published_at` default for public
reads, pinned-then-newest inside a workspace), `per_page`. `category` is one
account's private folder and therefore belongs to the workspace half only; the public
half is narrowed by `topic` and `author`, which is the split the live page already
makes in its own URL (§7.1).

Fields:

```json
{
  "id": 12,
  "slug": "offline-first-sync-notes",
  "title": "Offline-first sync notes",
  "summary": "…",
  "content": "…markdown…",
  "status": "under_review",
  "priority": "high",
  "potential": "medium",
  "visibility": "private",
  "published_at": null,
  "is_pinned": true,
  "view_count": 0,
  "author": { "id": 7, "name": "…" },
  "plan": null
}
```

`visibility` and `published_at` are both exposed and both read-only: a client sets
publication with `POST /ideas/{id}/publish`, never by patching either column, because
the pair is an invariant owned by `SyncIdeaVisibility`.

### 6.8 Developer Lab resources

| Method | Path | Ability | Notes |
| --- | --- | --- | --- |
| `GET` | `/snippets` | *(public)* | The gallery: published rows, filterable by `language`, `topic`, `author` and `sort` — the facets the live gallery page already puts in its URL (§7.1). |
| `GET` | `/snippets/{slug}` | *(public)* | `404` unless `LabSnippet::isVisibleTo()` passes. `code` is data in a JSON response — the escaping question the HTML view has to answer never reaches an API client. |
| `POST` | `/snippets` | `dev-lab:use` | Requires a title and a language the lab knows (`html`, `css`, `javascript`, `php`). |
| `PATCH` | `/snippets/{id}` | `dev-lab:use` | Author only. |
| `DELETE` | `/snippets/{id}` | `dev-lab:use` | Author, or `admin:access`. Deleting a snippet leaves its execution rows standing — `lab_executions` denormalises the label for exactly that reason. |
| `POST` | `/snippets/{id}/publish` | `dev-lab:use` | Refuses a snippet whose code is whitespace; there is nothing for a stranger to read. |
| `GET` | `/workspaces` | `dev-lab:use` | The caller's buffers. Bound by `id` — a workspace has no slug because it is never public. |
| `PUT` | `/workspaces/{id}` | `dev-lab:use` | Upsert of the HTML/CSS/JS trio. A blank pane stores `null`, not `""`. |
| `GET` | `/executions` | `dev-lab:use` | This account's run history, newest first. Read only — see below. |
| `GET` | `/executions/today` | `dev-lab:use` | The count that remains of `config('dev_lab.daily_run_limit')`. |

There is deliberately **no** `POST /executions`. `RecordLabExecution` would do
nothing except *record* a run: the sandbox is a browser, so an HTTP client has no
frame, no `console` to capture and no rendered document to return. There is no
server-side runner in this build and `LabLanguage::isBrowserRunnable()` is false for
`php`, so even a web request that asks to run PHP is refused before its row is
written. The write belongs to the client that actually executed something, which is
the only party that can report a result honestly, so the API exposes history and not
a run.

Collection filters for `GET /snippets`: `language`, `topic`, `author`, `q`, `sort`,
`per_page`. `language` and `topic` are separate axes over the same result set — what
the code is written in, and what it is for.

Fields:

```json
{
  "id": 3,
  "slug": "css-container-query-card",
  "title": "Container query card",
  "language": "css",
  "code": "…",
  "description": "…",
  "visibility": "public",
  "published_at": "2026-10-20T09:14:00+00:00",
  "view_count": 41,
  "runnable": false
}
```

`runnable` is the server's answer to `isBrowserRunnable()`, exposed so a client does
not draw a Run button that the next request will refuse.

---

## 7. Pagination, filtering, sorting

- **Pagination:** page-number based, `page` and `per_page`. The product's data
  volumes do not justify cursor pagination, and page numbers are substantially
  easier for a client to reason about.
- `per_page` defaults to 25, is clamped to `1..100`, and a larger request is
  **clamped, not rejected**.
- **Filtering:** flat query parameters. No filter DSL; the set is small enough
  that a bespoke grammar would be more surface area than it saves.
- **Sorting:** `sort` with a leading `-` for descending. The allowed fields are
  whitelisted per resource; an unknown field is a `422`, never a silent
  fall-through to a default.
- **Sparse fields:** `fields[posts]=id,title,slug`. Omitted fields are dropped
  from the response. Useful on mobile.

### 7.1 The parameters the public landing pages already take

Two Livewire pages already keep this state in the URL: `GET /ideas` and
`GET /dev-lab/gallery`. They are screens and not JSON endpoints, and they are
recorded here because the filter sets in §6.7 and §6.8 are meant to mirror them, and
because [ROUTES](./ROUTES.md) resolves every one of them to this section instead of
restating the table. Each is a `#[Url]`-bound property on the component with its
default suppressed, so an unfiltered page keeps a clean canonical URL.

| Parameter | Type | Behaviour |
| --- | --- | --- |
| `topic` | slug | Filter to one platform-owned subject. Absent or empty is no filter. |
| `author` | handle | Filter to one author by their `users.handle`. Absent or empty is no filter. |
| `sort` | enum | `recent`, `oldest`, `views`, `title`, plus `tab` on `/ideas`. See below. |
| `q` | string | Substring match — title, summary and body on the ideas index; title, description and the saved code on the gallery. Two-character floor. |
| `tab` | enum | Ideas index only. Its existing stage band (`promoted` default, plus the tab's own order feeding `sort=tab`). |
| `lang` | enum | Gallery only. `LabLanguage` case; the chip row counts published snippets per language and hides a language with none. |
| `page` | int | Page number. Both pages still paginate, at `config('ideas.per_page.public')` and `config('dev_lab.per_page.public')`. |

Neither facet is validated by looking the value up on its own. `topic` is checked
through `App\Services\PublicTaxonomy::topic()`, which is built from the same
`published()` count that built the chips, and `author` against the author chips from
the same class — only accounts with at least one published row in that family and a
non-empty handle are on the list. A hand-typed value that fails the gate is ignored
rather than answered with a 404: an inactive subject, a subject with no published row
behind it, or a handle nobody published under behaves exactly as if nothing had been
chosen. These are readers' URLs, typed and pasted, and a page that refuses a stale
filter is a page that teaches somebody nothing.

Sorting is one whitelist of words per page, translated into an `ORDER BY` by
`applySort()` in `resources/views/pages/ideas/⚡index.blade.php` and its twin in
`pages/dev-lab/⚡gallery.blade.php`. `/ideas` defaults to `tab`, its own "leave the
tab's order alone" value, which is therefore the word excluded from the URL; the
gallery defaults to `recent`. `recent` means `published_at` descending with an `id`
tie-break on both pages. An unknown sort word falls through to the default rather
than failing — a browser extension or a stale bookmark is not a request to reject.

A search term shorter than two characters does not filter at all: it is ignored and
the page says so in a status line. This is a screen rule. `Idea::search()` and
`LabSnippet::search()` were not touched, because a model scope that silently drops
one-letter terms would be lying about its own contract for the sake of one page's
noise problem.

---

## 8. Rate limiting

| Scope | Limit | Applies to |
| --- | --- | --- |
| Unauthenticated | 60 / minute / IP | Public reads |
| Authenticated | 120 / minute / token | Default |
| Token creation | 5 / minute / IP | Brute-force surface |
| Comment creation | 10 / hour / account | Spam surface |

Headers on every response:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 117
Retry-After: 34          (only when 429)
```

A `429` uses the standard error envelope with `code: "rate_limited"`.

---

## 9. Status codes

| Code | Meaning in this API |
| --- | --- |
| `200` | Success |
| `201` | Resource created — includes a `Location` header |
| `204` | Success, no body (deletes) |
| `401` | Missing, malformed, expired or revoked token |
| `403` | Valid token, insufficient **ability** |
| `404` | Not found, **or** found but not visible to the caller |
| `409` | State conflict — most notably a two-factor challenge |
| `422` | Validation failed, or an unknown filter/sort field |
| `429` | Rate limited |
| `500` | Unhandled server error; `code: "server_error"`, no internals leaked |

**`404` over `403` for invisible resources.** A `403` on a resource that exists
confirms its existence to someone not permitted to see it. For a draft post
belonging to another author, the API answers `404` — the same answer as for a
post that was never written.

---

## 10. Error codes

Stable, machine-readable, additive.

| `code` | HTTP | Meaning |
| --- | --- | --- |
| `unauthenticated` | 401 | No or invalid token |
| `token_expired` | 401 | Well-formed but past `expires_at` |
| `forbidden` | 403 | Ability missing |
| `not_found` | 404 | No such resource, or not visible |
| `validation_failed` | 422 | `errors` object populated |
| `unknown_field` | 422 | Unrecognised `fields` / `sort` value |
| `ability_not_grantable` | 422 | Requested ability exceeds the account role |
| `two_factor_required` | 409 | Challenge token in `meta.challenge_token` |
| `rate_limited` | 429 | `Retry-After` set |
| `server_error` | 500 | Unhandled |

Clients **must** branch on `code`, not on `message`; messages are prose and may
be reworded or translated at any time.

---

## 11. CORS

Only the API routes are CORS-enabled — the web routes deliberately are not,
because they are same-origin and exposing them would be pure attack surface.
Allowed origins are an explicit allow-list from `config('cors.allowed_origins')`
and default to **empty**, so a freshly deployed instance is not open until
someone opts in.

---

## 12. Implementation plan

Recorded so the design is falsifiable rather than aspirational.

| Phase | Work | Depends on |
| --- | --- | --- |
| 0 | Add Sanctum; create `routes/api.php`; register the `api` middleware group | — |
| 1 | Token issuance + `/me`; ability intersection against `AccountRole` | Phase 0 |
| 2 | Read-only public resources: posts, categories, tags | Phase 1 |
| 3 | Write resources: post create/update, comment create | Phase 2 |
| 4 | Administrative resources: users, role changes | Phase 3 |
| 5 | Ideas + Developer Lab resources (§6.7, §6.8) — the models, policies and screens already exist, so these rows are transcription, not design | Phase 4 |
| 6 | Marketplace reads from the existing models; listing writes need a `MarketplaceProductPolicy` and an authoring screen first | Sales **blocked — no `Sale` model**, listing writes **blocked — no policy** (see §6.6) |

Each phase ships with Laravel Resource classes, Form Requests, a policy per
resource, and feature tests asserting both the happy path and the denial path.
An endpoint without a denial test is not finished — the interesting failures in
an API with this role model are all `403`s and `404`s.

---

## 13. Open questions

Deliberately unresolved. Each needs a decision before the phase that depends on
it.

1. **Token lifetime.** Should tokens expire by default? Indefinite tokens are
   convenient and a standing risk. A 12-month default with refresh was assumed
   in §3.1 and is not settled.
2. **Webhook delivery.** Integrations that import posts usually want to be
   notified of changes rather than poll. No design exists for this.
3. **Slug vs. id as the canonical key.** §6.1 exposes both and sets no rule for
   which a client should store. This needs an answer before v1 freezes.
4. **The revenue ledger.** `MarketplaceProduct` exists and is displayed, so the
   catalogue is not an open question. Two halves are: a seller still cannot write
   a listing (§6.6), and there is no `Sale` model, no refunds, and nothing that
   explains what `payment:configure` is meant to configure. Until those exist,
   `RolePlan`'s selling limits are countable but not enforceable against anything
   a seller can do.
5. **Execution reporting.** §6.8 refuses to expose `POST /executions` because an
   HTTP client cannot honestly report a browser run. If a server-side runner is
   ever built, that endpoint becomes implementable and the question of what it
   returns — output, exit status, both truncated — becomes real.
