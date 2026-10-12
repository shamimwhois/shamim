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
| `user` | 1 | 3 | — |
| `seller` | 2 | 11 | everything `user` has |
| `admin` | 3 | 24 | everything, plus administration |

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
| `POST` | `/posts/{id}/publish` | `post:publish-own` | Transitions `draft` → `published`. |

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

### 6.5 Teams

Teams are governed by a **separate** permission system — `TeamRole` /
`TeamPermission` — not by `AccountPermission`. Membership in a team carries a
role (`owner`, `admin`, `member`) and that role determines what the member may
do *within that team*.

This is a deliberate two-axis model: account-level permissions answer "what may
this person do on the platform", team-level permissions answer "what may this
person do in this team". Conflating them would make a team admin on one team an
admin on every team.

| Method | Path | Team role required |
| --- | --- | --- |
| `GET` | `/teams` | *(membership)* — teams the account belongs to |
| `GET` | `/teams/{slug}` | `member` |
| `POST` | `/teams` | *(any token)* — creator becomes `owner` |
| `GET` | `/teams/{slug}/members` | `member` |
| `POST` | `/teams/{slug}/invitations` | `invitation:create` |
| `DELETE` | `/teams/{slug}/invitations/{code}` | `invitation:cancel` |

Those two ability names are `TeamPermission` values, resolved **against the
target team's membership role** — not against the account. The full set is
`team:update`, `team:delete`, `member:add`, `member:update`, `member:remove`,
`invitation:create`, `invitation:cancel`.

Token abilities therefore cannot express team authorization on their own. An
account-level ability string like `team:invite` would be dangerous: it would
read as a platform-wide grant when it must be a per-team one. The correct shape
is a token bound to an account, with team authorization resolved per request
from the membership row.

### 6.6 Seller resources

Placeholder. A `seller`-role account can publish product listings in the web
application's design, but **no product model exists yet** — see
`docs/audit/PHASE-0-AUDIT.md`. These endpoints are named for completeness and
marked **unimplementable** until the model lands:

| Method | Path | Ability | Status |
| --- | --- | --- | --- |
| `GET` | `/products` | *(public)* | Blocked on the `Product` model |
| `POST` | `/products` | `product:create` | Blocked |
| `PATCH` | `/products/{id}` | `product:update-own` | Blocked |

Listing them is not padding. It records that the seller role is
**half-designed**: the plan limits exist (`RolePlan::productListingLimit()`)
but there is nothing for them to limit.

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
| 5 | Teams | Phase 4 |
| 6 | Seller products | **Blocked — no `Product` model** |

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
4. **Seller product model.** Until `Product` exists, the seller half of the
   role model cannot be exercised over the API at all.
