# Database & Backend Schema — DevFolio

Complete data model for the portfolio and technical blog platform.

Companion documents: [PRD](./PRD.md) · [TRD](./TRD.md) · [Application Flow](./APP-FLOW.md) · [ERD](./ERD.md)

---

## 1. Schema Overview

### 1.1 Design principles

| # | Principle | Consequence |
| --- | --- | --- |
| S1 | **Content is authored, readers are incidental** | Post-related tables are optimised for the author's write path and the public read path. Reader-facing tables (comments) are deliberately minimal. |
| S2 | **Derived data is stored, not recomputed** | Reading time, word count, slug, and rendered HTML are persisted at write time (see [TRD ADR-004/ADR-005](./TRD.md#adr-004--markdown-stored-as-source-rendered-html-cached)). Read paths never parse markdown. |
| S3 | **Portable SQL** | No SQLite-specific syntax, no MySQL-only functions, no PostgreSQL extensions. Every query must run unchanged on all three engines. |
| S4 | **Referential integrity at the database level** | Foreign keys with explicit `onDelete` behaviour. The application never relies on orphan cleanup. |
| S5 | **Uniqueness enforced by the database** | Slugs, category names, and tag names carry unique indexes. Application-level checks are a UX improvement, not the guarantee. |
| S6 | **Soft deletion only where a URL is at stake** | Posts are soft-deleted; comments are hard-deleted. No blanket soft-delete policy. |
| S7 | **Enum-like columns are strings, not database enums** | Portable across engines, extendable without an `ALTER TYPE`, and backed by a PHP enum with casts. |

### 1.2 Table inventory

| Table | Purpose | Rows expected | Soft delete |
| --- | --- | --- | --- |
| `users`   | *(existing)* Authentication identity, **extended with `role` and `handle`** | 1–100 | No |
| `cache`, `jobs`, `sessions`, `password_reset_tokens`, `passkeys` | *(existing)* Framework tables | — | No |
| **`categories`** | ★ One-to-many grouping for posts | 5–20 | No |
| **`tags`** | ★ Many-to-many labelling for posts | 20–200 | No |
| **`posts`** | ★ The primary content entity | 50–5 000 | Yes |
| **`post_tag`** | ★ Post ↔ Tag pivot | 5× posts | No |
| **`comments`** | ★ Moderated reader discussion | 0–10× posts | No |
| `communities`, `community_memberships` | ★ *(existing)* Audience scope a feed post can be limited to | 4–50 / 10–500 | No |
| **`feed_posts`** | *(existing)* The community feed, **extended with publishing state** — §2.6 | 10–2 000 | No — `hidden`/`deleted` flags |
| **`feed_attachments`** | ★ Composer images, stored on a private disk | 0–8 per post | No |
| `idea_categories` | ◆ Per-account filing for ideas (§10) | 3–30 | No |
| `ideas` | ◆ The Ideas module's primary entity | 20–2 000 | No |
| `idea_plans` | ◆ A plan converted from, or written beside, an idea | 5–500 | No |
| `idea_tasks` | ◆ Checklist rows inside one plan | 10× plans | No |
| `lab_workspaces` | ◆ The sandbox's saved HTML/CSS/JS trio | 1–50 per account | No |
| `lab_snippets` | ◆ A saved piece of code, optionally published | 5–500 | No |
| `lab_executions` | ◆ Append-only record of a sandbox run | 10–10 000 | No |
| `topics` | ◇ The platform's public subject list, shared by Ideas and the Lab (§10.10) | 10–40 | No |
| `idea_topic` | ◆ Idea ↔ Topic pivot, carrying the author's order | 1–8 per idea | No |
| `lab_snippet_topic` | ◆ Snippet ↔ Topic pivot, same shape | 1–8 per snippet | No |

★ = introduced by this feature set. ◆ = introduced by the Ideas and Developer
Lab modules (§10). ◇ = shared by both modules and owned by neither — see §10.10.
Marketplace and AI Hub tables are documented in their own module sections rather
than here.

---

### 1.3 The `role` column on `users`

Added by `2026_10_12_094528_add_role_to_users_table.php`.

| Column | Type | Null | Default | Index |
| --- | --- | --- | --- | --- |
| `role` | `string(20)` | no | `'user'` | indexed |

Placed `after('password')`. The index exists because the admin account list
filters by role and the seller/admin dashboards count by it.

**Why a string and not an enum column.** The column stores the backing value of
`App\Enums\AccountRole` (`'user'`, `'seller'`, `'admin'`) and is cast on the
model via `'role' => AccountRole::class`. A database `ENUM` would need a
migration to add a role; a `string(20)` needs only a PHP enum case. Given that
adding a role is an expected product change, the string is the lower-friction
choice, and the cast is what keeps it type-safe.

**Why no `permissions` table.** Permissions are **derived**, never stored —
`AccountRole::permissions()` computes them. There is no pivot, no cache and no
config to invalidate, so changing what a role may do is a one-method edit. See
[`PERMISSIONS.md`](PERMISSIONS.md).

`role` is the **only** authorisation axis on `users`. The starter-kit's
`current_team_id` column and the team tables (`teams`, `memberships`,
`team_invitations`) were removed along with the team feature, so there is no
second, narrower scope to reconcile against.

---

### 1.4 The `handle` column on `users`

Added by `2026_10_19_015729_add_handle_to_users_table.php`.

| Column | Type | Null | Default | Index |
| --- | --- | --- | --- | --- |
| `handle` | `string` | yes | `null` | **unique** |

Stored **bare, without the `@`** (`aisha`, not `@aisha`). The decoration belongs
to the surface that renders it — the card prints `@` because every card does, and
a column that carried it would have to be trimmed by anything that wanted the
identity rather than the label.

**Why nullable.** The three starter accounts and every self-registered reader
arrive without a handle; only the seeded personas claim one (§8). A `NOT NULL`
handle would make registration a username claim, which is a different feature
with a different set of rules and is not built.

**Why unique.** The handle is how an account is named in public. Two accounts
reachable as `aisha` would make that name ambiguous, and uniqueness is the only
place the guarantee can live — `UserFactory` deliberately does **not** generate a
handle, because a unique column in a factory turns every test that creates users
into a collision risk.

**Bare here, decorated on the card — and that split is deliberate.**
`feed_posts.handle` keeps the corpus's own spelling (`@kenji`), because a card
prints the column verbatim and the ten curated rows are byte-identical to what
they always showed. `users.handle` holds `kenji`. The bridge is one function:
`App\Support\FeedPersonas::displayedHandle()` prepends the `@` when the composer
snapshots a byline, and `findByHandle()` strips it when a corpus handle is looked
up, so either spelling resolves to the same persona and neither column has to
change shape for the other's sake.

**What the card's handle link is.** When `user_id` is set, the handle links to
`route('feed.index', ['q' => $post->author])` — the feed asked for this name — and
when it is null the handle stays plain text, because a corpus row has no account
behind it. There is **no public profile route** in this application and none was
invented to make the link look more complete; the deferred item is the profile
page, not the link.

---

## 2. Entity Definitions

### 2.1 `posts`

The central entity. Stores the markdown source, the rendered HTML, a searchable plain-text projection, and all derived metadata.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | auto | PK |
| `user_id` | `bigint unsigned` | no | — | FK → `users.id`, `cascadeOnDelete`, indexed |
| `category_id` | `bigint unsigned` | **yes** | `null` | FK → `categories.id`, `nullOnDelete`. Null = uncategorised (see [ADR: category deletion](#52-category-deletion)). |
| `title` | `string(255)` | no | — | Display title |
| `slug` | `string(255)` | no | — | **Unique.** URL identifier, immutable once published unless explicitly changed |
| `excerpt` | `text` | yes | `null` | Author-overridable summary; auto-derived from body when blank |
| `body` | `longText` | no | — | **Markdown source.** The single source of truth for content |
| `body_html` | `longText` | yes | `null` | Sanitised rendered HTML. Written only by the rendering pipeline |
| `body_text` | `longText` | yes | `null` | Plain-text projection. Search target + reading-time input |
| `status` | `string(20)` | no | `'draft'` | Cast to `PostStatus`. Indexed |
| `is_featured` | `boolean` | no | `false` | Promotes to hero/featured surfaces. Indexed with `status` |
| `reading_time` | `unsignedSmallInteger` | no | `1` | Whole minutes, computed on save |
| `word_count` | `unsignedInteger` | no | `0` | Computed on save; never editable |
| `view_count` | `unsignedBigInteger` | no | `0` | Incremented on the queue, not inline |
| `published_at` | `timestamp` | yes | `null` | Set on publish, cleared on unpublish. Indexed |
| `created_at` | `timestamp` | yes | — | |
| `updated_at` | `timestamp` | yes | — | Also drives "recently edited" ordering |
| `deleted_at` | `timestamp` | yes | `null` | Soft delete |

**Indexes.**

| Index | Columns | Purpose |
| --- | --- | --- |
| `posts_slug_unique` | `slug` | Unique constraint + route binding lookup |
| `posts_user_id_status_index` | `user_id`, `status` | Author dashboard filtered lists |
| `posts_status_published_at_index` | `status`, `published_at` | Public listing ordered by publish date — the hottest read path |
| `posts_status_is_featured_index` | `status`, `is_featured` | Featured-post selection |
| `posts_category_id_index` | `category_id` | Category filtering |

**A note on the search index.** `body_text` is searched with a case-insensitive `LIKE '%term%'`, which cannot use a standard B-tree index because of the leading wildcard. At the expected corpus size (50–5 000 rows) a scan of `body_text` is acceptable and is bounded by pagination. Adding a full-text index is the documented escalation path when the corpus grows; the trade-off and its trigger threshold are recorded in [TRD §1.3](./TRD.md#13-data) and [TRD §1.4](./TRD.md#14-explicitly-rejected-dependencies).

**Why `body`, `body_html`, and `body_text` coexist.**

| Representation | Read by | Why not derive on read |
| --- | --- | --- |
| `body` | The editor | The author must get back exactly what they typed. A round-trip through HTML is lossy. |
| `body_html` | The public post page | Parsing + sanitising markdown on every request wastes CPU on content that changes rarely, and puts the sanitiser on the hot path where a bypass becomes a live vulnerability. |
| `body_text` | Search, reading time | Stripping markdown at query time would make search unusably slow across a paginated set. |

All three are regenerated in one transaction by the same Action. They cannot drift because no other code path writes them.

### 2.2 `categories`

Hierarchical grouping is deliberately **not** modelled. Posts need one primary topic; a tree would add a parent pointer, recursive queries, and a breadcrumb UI for no reader benefit.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | auto | PK |
| `name` | `string(255)` | no | — | **Unique** (case-insensitive at application level) |
| `slug` | `string(255)` | no | — | **Unique.** URL identifier |
| `description` | `text` | yes | `null` | Shown on the category landing view |
| `color` | `string(20)` | yes | `null` | Optional chip tint token name |
| `posts_count` | `unsignedInteger` | no | `0` | Denormalised published count; cache-invalidated on status change |
| `created_at` / `updated_at` | `timestamp` | yes | — | |

**Indexes:** `categories_slug_unique`, `categories_name_unique`.

**Why `posts_count` is denormalised.** The category filter bar renders a count per category on every blog index request. Computing six `COUNT(*)` subqueries per render is wasteful for a value that changes only on publish/unpublish. It is maintained inside the publish/unpublish transaction and is therefore never stale for longer than that transaction.

### 2.3 `tags`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | auto | PK |
| `name` | `string(255)` | no | — | Display name; **unique** |
| `slug` | `string(255)` | no | — | **Unique.** URL identifier; unique index |
| `posts_count` | `unsignedInteger` | no | `0` | Denormalised published count |
| `created_at` / `updated_at` | `timestamp` | yes | — | |

**Indexes:** `tags_slug_unique`, `tags_name_unique`.

**Why `tags.slug` is separately unique from `tags.name`.** Two tags named "C#" and "C Sharp" should be distinct rows, but a slug collision between "Next.js" and "Next.js " must not be possible. Enforcing uniqueness on both columns independently means a slug conflict is caught even when the display names differ only by normalisation.

### 2.4 `post_tag`

Standard Eloquent pivot. No `id` column — the composite primary key is the natural one and prevents duplicate pairs at the database level.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `post_id` | `bigint unsigned` | no | FK → `posts.id`, `cascadeOnDelete` |
| `tag_id` | `bigint unsigned` | no | FK → `tags.id`, `cascadeOnDelete` |

**Primary key:** composite `(post_id, tag_id)`.
**Additional index:** `tag_id` (for the reverse lookup used by tag filtering and related posts).

**Why `cascadeOnDelete` and not `nullOnDelete`.** Unlinking a post from its tags is meaningless — the row exists only to express "this post has this tag". If either side disappears, the link must disappear. Compare with `posts.category_id`, where `nullOnDelete` is correct because the *post* survives the category and legitimately becomes uncategorised.

### 2.5 `comments`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | auto | PK |
| `post_id` | `bigint unsigned` | no | — | FK → `posts.id`, `cascadeOnDelete`, indexed |
| `user_id` | `bigint unsigned` | no | — | FK → `users.id`, `cascadeOnDelete`, indexed. Commenting requires authentication. |
| `parent_id` | `bigint unsigned` | yes | `null` | FK → `comments.id`, `cascadeOnDelete`. Reserved for threading; v1 renders a flat list ordered by `created_at`. |
| `body` | `text` | no | — | Plain text, 3–2000 characters. Never rendered as HTML. |
| `status` | `string(20)` | no | `'pending'` | Cast to `CommentStatus`. Indexed with `post_id`. |
| `created_at` / `updated_at` | `timestamp` | yes | — | |

**Indexes.**

| Index | Columns | Purpose |
| --- | --- | --- |
| `comments_post_id_status_index` | `post_id`, `status` | Render approved comments for a post — the read path |
| `comments_status_created_at_index` | `status`, `created_at` | Moderation queue ordered by submission time |
| `comments_user_id_index` | `user_id` | "My comments"; cascade support |
| `comments_parent_id_index` | `parent_id` | Thread traversal when threading ships |

**Why only approved comments are queried with an explicit `status` predicate rather than a global scope.** The moderation queue and the public thread are the same table read with different filters. A global scope would silently hide pending comments from the queue and produce a confusing empty moderation screen.

**Why `user_id` is not nullable.** The PRD requires authentication to comment ([FR-3.8](./PRD.md)). A nullable `user_id` would imply an anonymous-comment feature that does not exist and would force every render path to handle a nameless author.

### 2.6 The publishing columns on `feed_posts`

Added by `2026_10_19_015730_add_publishing_columns_to_feed_posts_table.php` to the
table the community feed already reads. Every column is nullable or defaulted to
the value the curated corpus already behaves like, so the migration turns ten
read-only rows into ten published, public, owned posts without touching their
content.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `user_id` | `bigint unsigned` | yes | `null` | FK → `users.id`, `nullOnDelete`, placed after `id`. **Ownership and authorisation only** — no card reads it to print a name. |
| `status` | `string` | no | `'published'` | Cast to `FeedPostStatus` (`draft` \| `scheduled` \| `published`). Indexed with `posted_at`. |
| `scheduled_at` | `timestamp` | yes | `null` | The publish gate's other half. Cast to `datetime`. |
| `audience` | `string` | no | `'public'` | Cast to `FeedPostAudience` (`public` \| `self` \| `community`). |
| `community_id` | `bigint unsigned` | yes | `null` | FK → `communities.id`, `nullOnDelete`. Set only for a `community` audience. |

**Indexes.**

| Index | Columns | Purpose |
| --- | --- | --- |
| `feed_posts_status_posted_at_index` | `status`, `posted_at` | The feed's read: published rows, newest first |

**Denormalised identity is kept, not replaced.** `author`, `handle`, `avatar` and
`role` remain the rendered truth and a new post snapshots them from the
authenticated account at publish time. This is what preserves the one-query
invariant the feed is built on: a card that eager-loaded `user` to print a name
would pay a join on every render of every surface that shows the stream.
`user_id` answers a different question — *who is allowed to do this* — and that
question is asked on the write path, not in the feed.

**Why `nullOnDelete` on both foreign keys.** Deleting an account must not delete
the discussion it took part in, and a `Community` that disappears must not take
its posts with it. Either way the row survives and simply loses the ownership
that let it be edited — which is correct, because nobody is left who may.

**Two visibility predicates, and why they are not one.**

| | `visible()` (scope) | `isVisibleTo($user)` (method) |
| --- | --- | --- |
| Answers | may the **public** render this row | may **this reader** render this row |
| Status + schedule gate | in SQL | in PHP |
| `hidden` / `deleted` | in SQL | in PHP |
| Audience | not filtered | `public` → anyone; `self` → the author; `community` → the author or a member |
| Used by | `pages::feed`'s corpus query and its new-post poll, `pages::feed.show`, `Community::posts()` | the feed's in-memory filter, `feed.show`'s 404 gate, `FeedPostPolicy::view` / `viewAttachment` |

The **admin moderation console reads without `visible()`**, deliberately. Its job
is to list what is hidden, what is soft-deleted, and what a user drafted and
abandoned; a scope whose whole purpose is to hide those rows cannot serve the
surface whose purpose is to review them.

Audience is deliberately absent from SQL. The feed page reads one curated
collection and projects six widgets from it — the same rows are filtered by
topic, type, tag, search *and* audience in memory, because a second query would
pay twice for the same ten rows. The `≤ 4 queries per feed render` pin in
`tests/Feature/FeedTest.php` is what makes that a rule rather than a preference.

**Deleting a post sets `deleted`, it does not delete the row.** `deleted` is
already moderation's single definition of "removed from the feed" and the admin
console reads it; destroying the row would give the same verb two meanings
depending on who invoked it.

### 2.7 `feed_attachments`

Created by `2026_10_19_015731_create_feed_attachments_table.php`. One row per
composer image.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | no | auto | PK — also the signed route's binding key |
| `feed_post_id` | `bigint unsigned` | yes | `null` | FK → `feed_posts.id`, `cascadeOnDelete`. **Null while the upload belongs to no post yet.** |
| `user_id` | `bigint unsigned` | no | — | FK → `users.id`, `cascadeOnDelete`. The **uploader**, not the author — ownership of an unattached file is the only claim on it. |
| `disk` | `string` | no | `'feed_media'` | Which private disk holds the bytes. |
| `path` | `string` | no | — | `posts/{slug}/{uuid}.{ext}`, server-generated. The client's filename never reaches this column. |
| `mime` | `string` | no | — | Read back from the disk after the move (`Storage::mimeType()`), never taken from the request. |
| `size` | `bigint unsigned` | no | — | Bytes, from `Storage::size()`. |
| `width` / `height` | `int unsigned` | yes | `null` | `getimagesizefromstring()` on the stored contents; `null` for anything the decoder declines. |
| `alt_text` | `text` | yes | `null` | The `alt` the card renders. |
| `sort_order` | `int unsigned` | no | `0` | Composer order; the relationship is `orderBy('sort_order')`. |
| `temp_key` | `string` | yes | `null` | **Indexed.** Livewire's temporary-upload key, kept so `feed:prune-media` can find staged files that never became a post. |
| `created_at` / `updated_at` | `timestamp` | yes | — | `created_at` is the staging cutoff the prune command compares against. |

**Indexes.**

| Index | Columns | Purpose |
| --- | --- | --- |
| `feed_attachments_feed_post_id_sort_order_index` | `feed_post_id`, `sort_order` | The card's media strip, in order, in one eager load |
| `feed_attachments_temp_key_index` | `temp_key` | Orphan cleanup |

**Why its own table and not a JSON column.** The file lives on the `feed_media`
disk, which is configured `'serve' => false`, so it is reachable only through a
short-lived signed route. That route has to answer, from stored server truth, what
the bytes are (`mime`, `size`, `width`), who may see them (`user_id`, then the
parent post's visibility rule) and whether anyone still owns them (`temp_key`). A
JSON blob on `feed_posts` carries none of that addressably, and the orphan
sweep would have to parse every post to find one abandoned upload.

**Why `cascadeOnDelete` here and `nullOnDelete` on `feed_posts.user_id`.** An
attachment exists to be part of a post; when the post goes, the file goes with it
— that is the pair the prune command also relies on to keep the disk tidy. An
account deletion is not a statement about a discussion's right to exist.

---

## 3. PHP Enum Definitions

Enum-like columns are backed by PHP enums cast on the model, giving exhaustive `match` support and eliminating magic strings.

### 3.1 `App\Enums\PostStatus`

| Case | Value | Label | Publicly visible | UI treatment |
| --- | --- | --- | --- | --- |
| `Draft` | `draft` | Draft | No | Warning chip |
| `Published` | `published` | Published | **Yes** | Success chip |
| `Archived` | `archived` | Archived | No | Muted chip |

**Contract.** A post is publicly visible **iff** `status === Published && published_at !== null && deleted_at === null`. All three conditions are enforced together in the `published()` query scope; no caller reimplements the check.

The enum exposes `label(): string` (translatable) and `isPublic(): bool`.

### 3.3 `App\Enums\AccountRole`

Backs `users.role`.

| Case | Value | Level | Plan |
| --- | --- | --- | --- |
| `User` | `user` | 1 | `free` |
| `Seller` | `seller` | 2 | `seller` |
| `Admin` | `admin` | 3 | `platform` |

Exposes `label()`, `description()`, `badgeColor()`, `plan()`, `permissions()`,
`hasPermission()`, `hasAllPermissions()`, `hasAnyPermission()`, `level()`,
`isAtLeast()`, `canAccessAdminPanel()`, `dashboardRoute()`, and the static
`assignableBy()`.

`Seller::permissions()` **spreads** `User::permissions()`; `Admin::permissions()`
returns `AccountPermission::cases()`. Both make inheritance structural — see
[`PERMISSIONS.md`](PERMISSIONS.md) §2.

### 3.4 `App\Enums\AccountPermission`

30 cases in 5 groups (`PermissionGroup`): Content 6, Moderation 2, Selling 7,
Platform 3, Administration 12.

| Group | Count | Example |
| --- | ---: | --- |
| `content` | 6 | `post:create`, `post:publish` |
| `moderation` | 2 | `comment:moderate`, `taxonomy:manage` |
| `selling` | 7 | `product:create`, `sale:view-own`, `marketplace:use` |
| `platform` | 3 | `dev-lab:use`, `ai-hub:use`, `ideas:use` |
| `administration` | 12 | `admin:access`, `role:manage` |

Exposes `group(): PermissionGroup`, `groupLabel()`, and the static
`grouped()` / `options()`.

**The two platform cases that gate the newest modules did not grow.** Ideas and
the Developer Lab are gated by `ideas:use` and `dev-lab:use`, and the module
consoles by `admin:access`. There is deliberately no `ideas:manage` or
`dev-lab:manage`: adding a case would change the exact per-role sets pinned by
`tests/Feature/Enums/PermissionModelTest.php` and need a matrix migration.

**No storage.** This enum is the entire permission model — no table, no pivot,
no cache. `group()` is an exhaustive `match`, so adding a case without a group
is a PHPStan error rather than a runtime surprise.

### 3.5 `App\Enums\RolePlan`

Commercial entitlements, deliberately separate from authorization.

| Case | Value | Listing limit | Commission | Can sell |
| --- | --- | ---: | ---: | --- |
| `Free` | `free` | 0 | — | No |
| `Seller` | `seller` | 25 | 15.0 % | Yes |
| `Platform` | `platform` | `null` (unlimited) | 0.0 % | Yes |

`productListingLimit()` distinguishes `null` (unlimited) from `0` (not
permitted); the two are not collapsed.

### 3.2 `App\Enums\CommentStatus`

| Case | Value | Publicly visible | UI treatment |
| --- | --- | --- | --- |
| `Pending` | `pending` | No | Warning chip, "Awaiting moderation" |
| `Approved` | `approved` | **Yes** | Success chip |
| `Rejected` | `rejected` | No | Muted chip, struck through in the moderation view |

The enum exposes `label(): string` and `isPublic(): bool`.

**Why `Rejected` is retained rather than deleting the row.** Keeping the row is what allows the moderation queue to show the author what was already dismissed, and it makes a "reject twice" mistake impossible to repeat.

### 3.6 `App\Enums\FeedPostStatus`

Backs `feed_posts.status` (§2.6).

| Case | Value | Renders on `/feed` | UI treatment |
| --- | --- | --- | --- |
| `Draft` | `draft` | No | Composer draft list row, resumable |
| `Scheduled` | `scheduled` | No | Info chip with the due time, until `feed:publish-scheduled` promotes it |
| `Published` | `published` | **Yes** | The normal card |

**Contract.** A row is a candidate for any public surface **iff** `status === Published && (scheduled_at === null || scheduled_at <= now())`. Both halves are inside the `visible()` scope and mirrored by `isPublishedNow()`; no caller reimplements the check.

The enum exposes `label(): string`, `isPublished(): bool`, and `options()`. `Scheduled` is *not* `isPublic()`-style implied by the clock — the promotion is the command's job, so a reader never has to guess whether a row's status column or the current time decides what it sees.

**Why the default is `Published` and not `Draft`.** The migration adds the column to ten existing curated rows. Defaulting to `draft` would silently empty the public feed on the deploy that introduces it; defaulting to `published` states what those rows already were. A composer-created draft sets the value explicitly.

### 3.7 `App\Enums\FeedPostAudience`

Backs `feed_posts.audience` (§2.6).

| Case | Value | Who may read it | Requires |
| --- | --- | --- | --- |
| `Public` | `public` | Anyone, including signed-out visitors | nothing |
| `Self` | `self` | The author only | `user_id` |
| `Community` | `community` | The author and members of one community | `community_id` **and** the author's membership |

Exposes `label()`, `hint()` — the one-line explanation rendered under each option
in the composer, so the author reads the rule instead of inferring it from a
label — and `options()`.

**There is no `Followers` or `Custom` case.** The platform has no follow graph and
no per-user allow-list, and an option that silently meant "public" would be a
privacy lie rather than a placeholder. Adding an audience case means adding a
branch to `isVisibleTo()`'s exhaustive `match`, which is a PHPStan error until it
is written — the enum makes an unhandled audience a build failure, not a leak.

---

## 4. Model Layer

### 4.1 `App\Models\Post`

| Member | Kind | Detail |
| --- | --- | --- |
| `user()` | `belongsTo(User::class)` | Author |
| `category()` | `belongsTo(Category::class)` | Primary topic; nullable |
| `tags()` | `belongsToMany(Tag::class)` | Labelling |
| `comments()` | `hasMany(Comment::class)` | All comments |
| `approvedComments()` | `hasMany(Comment::class)` with `status = approved` | Public thread |
| `scopePublished($q)` | scope | `status = published` AND `published_at not null` |
| `scopeDraft($q)` | scope | `status = draft` |
| `scopeFeatured($q)` | scope | `is_featured = true` + published |
| `scopeSearch($q, string $term)` | scope | Escaped `LIKE` across `title`, `excerpt`, `body_text` |
| `scopeInCategory($q, string $slug)` | scope | Filter by category slug |
| `scopeWithTag($q, string $slug)` | scope | Filter by tag slug |
| `scopeRecent($q)` | scope | `orderByDesc('published_at')` |
| `getRouteKeyName()` | method | `'slug'` — powers route model binding |
| `readingTimeLabel()` | method | `"6 min read"` |
| `isPublished()`, `isDraft()` | method | Status predicates used by views and policies |

**Casts.** `status` → `PostStatus`, `published_at` → `datetime`, `is_featured` → `boolean`.

**Traits.** `HasFactory`, `SoftDeletes`, plus `App\Concerns\HasSlug` and `App\Concerns\HasReadingTime` (see §4.4).

**Attribute configuration uses PHP attributes**, matching this codebase's convention:

```php
#[Fillable(['user_id', 'category_id', 'title', 'slug', 'excerpt', 'body',
            'status', 'is_featured', 'published_at'])]
```

Note that `body_html`, `body_text`, `reading_time`, `word_count`, and `view_count` are **not fillable**. They are derived, and making them fillable would allow a caller to write a `body_html` that never passed through the sanitiser — a stored-XSS vector closed by omission from the fillable list.

### 4.2 `App\Models\Category`

`posts(): hasMany(Post::class)` · `publishedPosts(): hasMany(...)->published()` · `getRouteKeyName(): 'slug'` · `#[Fillable(['name', 'slug', 'description', 'color'])]`

### 4.3 `App\Models\Tag`

`posts(): belongsToMany(Post::class)` · `getRouteKeyName(): 'slug'` · `#[Fillable(['name', 'slug'])]`

### 4.4 `App\Models\Comment`

`post(): belongsTo(Post::class)` · `user(): belongsTo(User::class)` · `parent(): belongsTo(Comment::class)` · `replies(): hasMany(Comment::class, 'parent_id')` · `scopeApproved($q)` · `scopePending($q)`

Cast: `status` → `CommentStatus`. `#[Fillable(['post_id', 'user_id', 'parent_id', 'body', 'status'])]`.

### 4.5 `App\Models\User` and the account-role concern

`User` is the starter-kit model extended with the platform role. It declares the role through the `#[Fillable]` / `#[Hidden]` attributes, casts `role` to `AccountRole`, and defaults it in `$attributes` — so a model instantiated in memory and never saved already reports `user`, matching the migration default (§1.3).

```php
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use HasAccountRole, HasFactory, Notifiable,
        PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = ['role' => AccountRole::User->value];
}
```

The model body itself holds only the properties and `initials()`. Role behaviour
lives in `App\Concerns\HasAccountRole` (§4.6), so `User` stays close to the
skeleton shape while that behaviour remains independently testable.

**Role API provided by `HasAccountRole`.** Reach for these; do not compare `$user->role` to a string, and do not branch on role in a view when a permission check expresses the same intent.

| Member | Returns | Notes |
| --- | --- | --- |
| `accountRole()` | `AccountRole` | The cast accessor, for null-safe reads |
| `plan()` | `RolePlan` | The plan the role confers; see §3.5 |
| `hasRole(AccountRole $role)` | `bool` | Exact match |
| `isAtLeastRole(AccountRole $role)` | `bool` | Privilege ordering, not equality |
| `isUser()` / `isSeller()` / `isAdmin()` | `bool` | Thin wrappers over `hasRole()` — the only role-identity checks views should use |
| `hasPermission(AccountPermission $p)` | `bool` | Single permission |
| `hasAllPermissions(AccountPermission ...$p)` | `bool` | Conjunction |
| `hasAnyPermission(AccountPermission ...$p)` | `bool` | Disjunction |
| `canAccessAdminPanel()` | `bool` | Gates the admin surface as a whole |
| `canActOn(mixed $owner)` | `bool` | **The** ownership rule: the owner acting on their own record, or any admin. Guards the null-owner case so an ownerless record cannot be self-authorised |
| `dashboardRoute()` | `string` | Route name of this role's dashboard home |

### 4.6 Shared concerns

| Concern | Responsibility | Why shared |
| --- | --- | --- |
| `App\Concerns\HasSlug` | Derives a unique slug from a source field; applies an incrementing suffix on collision; respects a manual override | Posts, categories, and tags all need identical collision behaviour. A per-model copy would drift. |
| `App\Concerns\HasReadingTime` | Computes word count and reading minutes from plain text (200 wpm) | Used by the post pipeline and by tests that need the same algorithm. |
| `App\Concerns\HasAccountRole` | `role` cast, the three role predicates, and the account permission check | Keeps platform-role behaviour out of the model body and testable on its own (§3.3). |
| `App\Concerns\PasswordValidationRules` | The single password rule set used by registration, reset, and profile updates | Three entry points must not enforce three different policies. |
| `App\Concerns\ProfileValidationRules` | Name/email rules shared by registration and profile update | Same reason. |

### 4.7 `App\Models\FeedPost` and `App\Models\FeedAttachment`

The community feed's two models (§2.6, §2.7). `FeedPost` is pre-existing; `FeedAttachment` and the publishing half of `FeedPost` are this phase.

**`App\Models\FeedPost`**

| Member | Kind | Detail |
| --- | --- | --- |
| `user()` | `belongsTo(User::class)` | Owner — nullable for curated corpus rows, and **never** the rendered name |
| `community()` | `belongsTo(Community::class)` | The community a `community`-audience post is shared into |
| `attachments()` | `hasMany(FeedAttachment::class)` + `orderBy('sort_order')` | The card's media strip, in composer order, in one eager load |
| `scopeVisible($q)` | scope | `hidden = false` AND `deleted = false` AND published AND past the schedule — the SQL floor (§2.6) |
| `scopeUnanswered($q)` | scope | `type = question` AND `answered = false` |
| `scopeWithDetail($q)` | scope | `detail` **and** `detail->diff` are set — the SQL mirror of `hasDetail()` |
| `scopeCurated($q)` | scope | `pinned ↓, featured ↓, posted_at ↓, id ↓` |
| `scopeDraftsOf($q, int $userId)` | scope | One author's non-deleted drafts, newest first — the only query that may return a draft |
| `isPublishedNow()` | method | Status + schedule gate, no moderation, no audience |
| `isVisibleTo(?User, ?array $joinedCommunityIds)` | method | **The** one visibility rule, audience included |
| `reactionSum()`, `formattedScore()`, `isQuestion()`, `isSolved()`, `hasDetail()` | method | Presentation predicates used by cards and the detail page |
| `getRouteKeyName()` | method | `'slug'` |

**Casts.** `status` → `FeedPostStatus`, `audience` → `FeedPostAudience`, `posted_at`/`scheduled_at` → `datetime`, the boolean moderation flags → `boolean`, `tags`/`reactions`/`poll`/`answer`/`activity`/`detail` → `array`.

**In-memory defaults.** `$attributes` carries `status = published` and `audience = public` as well as the migration column defaults, so a post built in memory and never saved reports the same visibility as a row freshly read. Without it a `make()`-ed post has a `null` enum and both predicates have to survive that — a class of bug the defaults simply delete.

**The `$hidden` name collision, and why the code spells it out.** `Illuminate\Database\Eloquent\Model` declares a **protected `$hidden` array** for attributes withheld from serialization, and `feed_posts` has a boolean `hidden` column. From outside the class `$post->hidden` reads the column, because a protected property is inaccessible and the read falls through to `__get()`. **Inside** the class the same expression reads the array — empty, therefore falsy — and never the column. `isVisibleTo()` therefore uses `$this->getAttribute('hidden')`, and the comment at that line exists so nobody "simplifies" it back. The feed's gate silently accepted a hidden post until a unit test caught exactly this.

**`App\Models\FeedAttachment`**

| Member | Kind | Detail |
| --- | --- | --- |
| `const URL_TTL_MINUTES = 10` | constant | How long a minted signed URL stays valid — a stream permission, not a saved link |
| `post()` | `belongsTo(FeedPost::class, 'feed_post_id')` | Null while the upload is still a draft |
| `uploader()` | `belongsTo(User::class, 'user_id')` | The uploader, not the author — ownership of an unattached file is the only claim on it |
| `scopeAttached($q)` | scope | Rows a post has claimed |
| `scopeUnattached($q)` | scope | `feed_post_id` null AND `temp_key` set — what `feed:prune-media` reclaims |
| `url()` | method | `URL::temporarySignedRoute('feed.attachments.show', …, ['attachment' => $this->id])` |
| `alt()` | method | `alt_text`, or a non-empty fallback so a screen reader is never handed `alt=""` for a real image |
| `humanSize()` | method | The composer's byte formatting, `B`/`KB`/`MB`/`GB` |

`#[Fillable]` lists every storage column and nothing derived; `mime`, `size`, `width`, and `height` are written only by `AttachFeedMedia` after reading them back from the disk, so a client-claimed content type can never become the truth a browser is served.

**No `getRouteKeyName()`.** `feed.attachments.show` binds by `id`, and that is intentional — a slug would put a guessable name in a URL whose only defence is the signature plus the visibility check (§2.7, and [`ROUTES.md`](ROUTES.md) §14).

### 4.8 The seven module models

`Idea`, `IdeaPlan`, `IdeaTask`, `IdeaCategory`, `LabWorkspace`, `LabSnippet`,
`LabExecution` are documented with their tables in [§10](#10-ideas-and-developer-lab-tables)
rather than here, because for these objects the schema and the model are the same
conversation. `Topic` is deliberately absent from that list: it belongs to neither
module, and lives with the taxonomy tables at §10.10. The four conventions that
bind across all seven:

| Convention | Where | Why it is a convention and not a preference |
| --- | --- | --- |
| `#[Fillable([...])]` listing storage columns only | every model | a derived column (`slug`, `published_at`, `completed_at`) written by `fill()` would let a request set the truth |
| `casts()` **method**, not `$casts` property | every model | the house pattern; it is the only form that can return an enum class string without a stale annotation |
| `#[Scope] protected function …()` with `@param Builder<$this>` | `Idea` (`published`, `authoredBy`, `inStatus`, `pinned`, `prioritised`, `recentlyTouched`, `search`), `IdeaPlan`, `LabSnippet`, `Community` | Livewire screens call `Idea::query()->published()->prioritised()`; the attribute is what makes the protected method callable on the builder |
| `getRouteKeyName() => 'slug'` | all except `LabWorkspace` | a workspace is never public, so it has no slug to leak and binds by `id` |

`Idea::isVisibleTo()` and `LabSnippet::isVisibleTo()` are the conjunction §10
describes, and both are model methods rather than policy methods for one reason:
the same test decides whether a public route aborts 404 and whether a listing
includes the row, and a policy is the wrong home for a question the model can
answer about itself.

`IdeaTask` carries **no `user_id`** — see §10.9. Ownership resolves through
`$task->plan->user_id`, so a task cannot drift away from its plan's author, and
every mutating action re-asserts the parent's owner rather than trusting a column
that could have been written wrong.

---

## 5. Data Integrity Rules

### 5.1 Status ↔ timestamp invariant

| `status` | `published_at` | Valid? |
| --- | --- | --- |
| `draft` | `null` | ✅ |
| `draft` | set | ⚠️ **Invalid** — a draft must not carry a publish timestamp |
| `published` | set | ✅ |
| `published` | `null` | ⚠️ **Invalid** — a published post must be orderable by publish date |
| `archived` | either | ✅ — archived posts retain their historical publish date |

Enforced inside `PublishPost` and `UnpublishPost`, which are the only writers of `published_at`. A test asserts that unpublishing clears the timestamp, so the invariant cannot regress.

**Why not a database `CHECK` constraint.** SQLite's support requires a table rebuild, and the constraint's error surfaces as an opaque driver exception rather than a validation message. Enforcing it in the single writer Action gives a clear failure and keeps the schema portable (principle S3).

### 5.2 Category deletion

Deleting a category with posts uses `nullOnDelete`, so its posts become uncategorised rather than being destroyed. **Posts are never deleted as a side effect of taxonomy cleanup** — an author pruning a category must not silently lose articles. The delete confirmation modal states this explicitly.

### 5.3 Tag deletion

`post_tag` rows cascade. The tag disappears from posts; the posts are untouched.

### 5.4 Post deletion

Soft delete. `deleted_at` is set; the row and its relationships remain. Public queries exclude trashed rows by Eloquent's default. The public URL returns 404 (never 410, because restoration is supported and a 410 would be cached by intermediaries).

**Why posts cascade-delete comments but soft-delete themselves.** Hard-deleting a comment is correct — it has no archival URL. If a post were hard-deleted, its comments would legitimately be orphaned, so the cascade is declared for correctness even though the soft delete means it rarely fires. It fires on a genuine force-delete or a database-level cleanup, and in that case the cascade is what we want.

### 5.5 User deletion

`posts.user_id` cascades on user delete. Deliberate: a deleted author's posts have no owner and would otherwise be unmoderatable. If content preservation is ever required, the correct change is a `nullOnDelete` plus a nullable `user_id`, and that change must be made explicitly — it is not the default.

### 5.6 Slug uniqueness

Guaranteed by a unique index. The generator's read-then-write is inherently racy; the unique index is what actually prevents a duplicate, and the Action retries once with an incremented suffix on a constraint violation.

---

## 6. Query Patterns

The queries that matter, with the reasoning that constrains them.

### 6.1 Public blog index (paginated, filtered)

```php
Post::query()
    ->published()
    ->with(['category', 'tags'])
    ->when($search,   fn ($q) => $q->search($search))
    ->when($category, fn ($q) => $q->inCategory($category))
    ->when($tag,      fn ($q) => $q->withTag($tag))
    ->recent()
    ->paginate(9);
```

**Constraints.**

- `with(['category', 'tags'])` is mandatory. Without it, a 9-row page issues 19 queries instead of 3. This is the single most important performance property of the index page.
- The `search` scope escapes `%` and `_` in the term before interpolating it, so a `%` in the query is a literal, not a wildcard.
- Filter predicates are applied in a stable order so the generated SQL is identical for identical filter sets, keeping the query cache warm.

### 6.2 Public post detail

```php
Post::query()
    ->published()
    ->with(['category', 'tags', 'approvedComments.user'])
    ->where('slug', $slug)
    ->firstOrFail();
```

Route model binding resolves by `slug` via `getRouteKeyName()`. The controller then re-checks ownership for the draft-preview case, because binding alone cannot express "published **or** mine".

### 6.3 Related posts

Compute in PHP over a bounded candidate set, because the ranking is a weighted tuple comparison that is clearer in PHP than in portable SQL:

1. Candidate pool = posts sharing at least one tag with the subject (limit the tag list to the subject's 8 most-used tags to bound the pool), plus posts in the same category.
2. Exclude the subject and any unpublished post.
3. Order by: shared tag count ↓, same category ↓, `published_at` ↓.
4. Take 3.

The two-step shape (bounded candidate query, then in-PHP ranking) is what keeps this from degrading as the corpus grows, per [TRD ADR-007](./TRD.md#adr-007--related-posts-by-tag-overlap-then-category-with-a-bounded-query).

### 6.4 Moderation queue

```php
Comment::query()
    ->with(['user', 'post'])
    ->whereIn('status', [CommentStatus::Pending, CommentStatus::Approved])
    ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
    ->orderByDesc('created_at')
    ->paginate(20);
```

Pending items sort first so the actionable work is always at the top. The `orderByRaw` uses a literal `CASE` expression with a hardcoded status value from the enum, not user input.

### 6.5 Author dashboard metrics

| Metric | Query shape |
| --- | --- |
| Total posts | `Post::count()` |
| Published | `Post::published()->count()` |
| Drafts | `Post::draft()->count()` |
| Total views | `Post::sum('view_count')` |
| Comments | `Comment::count()` |
| Pending moderation | `Comment::pending()->count()` |

Six cheap aggregates. If this ever became hot it would move behind a cached `PostMetrics` data object; it is not hot today, so it is not cached today.

### 6.6 Role-scoped dashboard metrics

Each role's dashboard reads a different slice, and the queries reflect that.

**Seller dashboard** (`pages::seller.dashboard`) reads the account's plan
rather than the database: `RolePlan::productListingLimit()` and
`commissionPercent()`. No query — there is no `Product` model to count. The
screen states entitlements against a feature that does not yet exist, which is
why it is honest about being empty rather than showing a zero.

**Admin dashboard** (`pages::admin.dashboard`) adds platform-wide counts
that the author dashboard deliberately does not scope:

| Metric | Query shape |
| --- | --- |
| Total accounts | `User::count()` |
| Accounts by role | `User::selectRaw('role, count(*)')->groupBy('role')` |
| Published posts | `Post::published()->count()` |
| Awaiting moderation | `Comment::pending()->count()` |
| Sellers | `User::where('role', 'seller')->count()` |

**Why `users.role` is indexed.** The group-by above and the admin user list's
role filter both scan on it. Without the index, a filtered list degrades to a
full scan as accounts grow — the one table in this application that can
realistically reach six figures.

---

## 7. Migration Plan

Migrations are anonymous classes, created with `php artisan make:migration`,
ordered so that foreign keys never reference a table that does not yet exist.

### 7.1 Content migrations

| # | Migration | Creates |
| --- | --- | --- |
| 1 | `2026_10_12_080202_create_categories_table` | `categories` |
| 2 | `2026_10_12_080203_create_tags_table` | `tags` |
| 3 | `2026_10_12_080204_create_posts_table` | `posts` (FKs → `users`, `categories`) |
| 4 | `2026_10_12_080205_create_post_tag_table` | `post_tag` (FKs → `posts`, `tags`) |
| 5 | `2026_10_12_080207_create_comments_table` | `comments` (FKs → `posts`, `users`, self) |

`080206` is absent from the sequence — a gap from the authoring process, not a
missing migration. Laravel orders by filename, so a gap is harmless.

### 7.2 Access migration

| # | Migration | Changes |
| --- | --- | --- |
| 6 | `2026_10_12_094528_add_role_to_users_table` | Adds `users.role` + index |

### 7.3 Community-feed migrations

The first three are pre-existing — the read-only feed and community directory that
Phase 1 builds on. The last three are Phase 1, and every one of them is
**additive**: no table is recreated, no existing column changes meaning.

| # | Migration | Changes |
| --- | --- | --- |
| 7 | `2026_10_17_000000_create_feed_posts_table` | `feed_posts` — the corpus table, JSON columns included |
| 8 | `2026_10_17_100000_create_communities_table` | `communities` |
| 9 | `2026_10_17_100100_create_community_memberships_table` | `community_memberships` (FKs → `communities`, `users`) |
| 10 | `2026_10_19_015729_add_handle_to_users_table` | Adds nullable unique `users.handle` after `name` (§1.4) |
| 11 | `2026_10_19_015730_add_publishing_columns_to_feed_posts_table` | Adds `user_id`, `status`, `scheduled_at`, `audience`, `community_id` + `status`/`posted_at` index (§2.6) |
| 12 | `2026_10_19_015731_create_feed_attachments_table` | `feed_attachments` + both indexes (§2.7) |

**What the timestamps actually encode.** The hard requirement is only that all
three follow 7, 8, and 9: `add_publishing_columns_to_feed_posts_table` puts
foreign keys on `feed_posts` → `users` and `feed_posts` → `communities`, and
`create_feed_attachments_table` puts foreign keys on `feed_attachments` →
`feed_posts` and → `users`. Nothing in 10, 11, or 12 references a column the next
one adds, so those three would run in any order among themselves. The
015729 → 015730 → 015731 sequence states the reading order instead — identity,
then the post's publishing state, then the files attached to it — which is also
the order the persona registry and the seeders resolve dependencies in.

### 7.5 Catalogue, About and AI Hub migrations

`2026_10_17_10000x` and `2026_10_18_x` are the two module batches that predate the
Ideas and Developer Lab work. They share the `100000` prefix with the community
tables because they were authored in the same wave, and Laravel's filename sort is
what orders them, not the intent behind the numbers.

| # | Migration | Creates |
| --- | --- | --- |
| 13 | `2026_10_17_100000_create_marketplace_categories_table` | `marketplace_categories` |
| 14 | `2026_10_17_100001_create_marketplace_products_table` | `marketplace_products` (FKs → `users`, `marketplace_categories`) |
| 15 | `2026_10_17_100002_create_marketplace_product_versions_table` | `marketplace_product_versions` |
| 16 | `2026_10_17_100003_create_marketplace_reviews_table` | `marketplace_reviews` |
| 17 | `2026_10_18_000000_create_about_sections_table` | `about_sections` |
| 18 | `2026_10_18_000001_create_about_items_table` | `about_items` |
| 19 | `2026_10_18_100000_create_ai_topics_table` | `ai_topics` (self-referencing parent) |
| 20 | `2026_10_18_100001_create_ai_articles_table` | `ai_articles` |
| 21 | `2026_10_18_100002_create_ai_article_topics_table` | `ai_article_topic` pivot |
| 22 | `2026_10_18_100003_create_ai_article_links_table` | `ai_article_links` |
| 23 | `2026_10_18_100004_create_ai_article_related_table` | `ai_article_related` pivot |
| 24 | `2026_10_18_100005_create_ai_learning_paths_table` | `ai_learning_paths` |
| 25 | `2026_10_18_100006_create_ai_learning_path_items_table` | `ai_learning_path_items` |
| 26 | `2026_10_18_100007_create_ai_resources_table` | `ai_resources` |

### 7.6 Ideas, Developer Lab and taxonomy migrations

The ten that §10 documents, in `2026_10_20_10000x` order. Each is additive and
each references only a table above it, so they are the last batch and can be
sliced apart without disturbing anything.

| # | Migration | Creates |
| --- | --- | --- |
| 27 | `2026_10_20_100000_create_idea_categories_table` | `idea_categories` |
| 28 | `2026_10_20_100001_create_ideas_table` | `ideas` (FKs → `users` cascade, `idea_categories` nullOnDelete) |
| 29 | `2026_10_20_100002_create_idea_plans_table` | `idea_plans` (FK → `ideas`, **unique**, nullOnDelete) |
| 30 | `2026_10_20_100003_create_idea_tasks_table` | `idea_tasks` (FK → `idea_plans`, not null, cascadeOnDelete) |
| 31 | `2026_10_20_100004_create_lab_workspaces_table` | `lab_workspaces` |
| 32 | `2026_10_20_100005_create_lab_snippets_table` | `lab_snippets` |
| 33 | `2026_10_20_100006_create_lab_executions_table` | `lab_executions` |
| 34 | `2026_10_20_100007_create_topics_table` | `topics` |
| 35 | `2026_10_20_100008_create_idea_topic_table` | `idea_topic` pivot (FKs → `ideas`, `topics`, both cascade) |
| 36 | `2026_10_20_100009_create_lab_snippet_topic_table` | `lab_snippet_topic` pivot (FKs → `lab_snippets`, `topics`, both cascade) |

**The `idea_plans.idea_id` unique index is the schema stating a product rule.**
One idea converts to one plan, so a second conversion is an integrity violation
rather than a bug a service class might forget to catch. It is nullable and
`nullOnDelete`, so deleting the source idea leaves the plan standing with a null
provenance instead of taking the plan with it.

### 7.7 Framework and starter-kit migrations

These ship with Laravel and the starter kit and are unmodified.

| Migration | Creates |
| --- | --- |
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache` (`cache`, `cache_locks`) |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2024_01_01_000000_create_passkeys_table` | `passkeys` |
| `2025_08_14_170933_add_two_factor_columns_to_users_table` | 2FA columns on `users` |

That is **41 migration files** in `database/migrations/`: 5 framework and starter
kit, 36 authored by this application.

**Ordering note.** The `2026_10_12_*` content migrations run after the
framework tables, so `users` exists before `posts` references it. On a fresh
`php artisan migrate`: framework tables → passkeys and 2FA → content →
`users.role` → marketplace, community, About and AI Hub tables → `users.handle` →
`feed_posts` publishing columns → `feed_attachments` → the seven Ideas and
Developer Lab tables.

**Rollback.** Each `down()` drops exactly what its `up()` created. Rolling back
the content batch leaves the framework tables intact, and
`add_role_to_users_table::down()` drops the index before the column — dropping
the column first would fail on SQLite. `add_handle_to_users_table::down()` drops
`handle` alone, letting the unique index go with it, and
`add_publishing_columns_to_feed_posts_table::down()` drops
`feed_posts_status_posted_at_index` **before** the columns it covers — and each
constrained foreign key with `dropConstrainedForeignId()`, because dropping an
indexed or constrained column in the wrong order fails on SQLite.

---

## 8. Seeding Strategy

Seeders produce a site that is immediately browsable, because a portfolio with three lorem-ipsum posts is not demonstrable.

`DatabaseSeeder` orders the calls deliberately: **users → taxonomies → posts →
comments → feed corpus → marketplace → about → AI hub → topics → idea categories
→ lab snippets**. Posts reference
categories and tags by name, and `PostSeeder` resolves its author from the first
user, so `UserSeeder` must run first. The last three are in that order because
`LabSnippetSeeder` resolves the topic slugs on its examples through
`Topic::query()->active()`, so the taxonomy must exist before the snippets that
cite it.

| Seeder | Produces | Notes |
| --- | --- | --- |
| `UserSeeder` | **3 accounts, one per role** | `shamimstack@gmail.com` (admin, listed **first** so it owns the seeded posts), `seller@example.com` (seller), `user@example.com` (user). Password `password`. |
| `CategorySeeder` | 6 categories: Architecture, Backend, Frontend, DevOps, Databases, Career | Real names — these read as a real engineer's taxonomy |
| `TagSeeder` | ~24 tags: laravel, php, livewire, react, typescript, postgres, redis, docker, testing, performance, security, caching, queues, observability, … | Slug-normalised, de-duplicated |
| `PostSeeder` | 12 posts: 9 published, 2 drafts, 1 featured | Real technical prose with code fences and headings. Each post gets 1 category and 2–4 tags. Created through the real `CreatePost` action — not raw inserts |
| `CommentSeeder` | ~30 comments across published posts | Mixed approved/pending so the moderation queue is non-empty |
| `FeedPostSeeder` | The `App\Support\FeedCorpus` rows, upserted **by slug** | Sets `user_id` from `App\Support\FeedPersonas` and writes `author`/`handle`/`avatar`/`role` byte-for-byte as the corpus states them. It does **not** write `status` or `audience` — the column defaults say what a curated row is: published and public |
| `CommunitySeeder` | 6 communities (2 invite-only) and their memberships | **Not called by `DatabaseSeeder`**; it runs via `php artisan db:seed --class=CommunitySeeder` and through `$this->seed()` in the feed's tests. A plain `db:seed` therefore leaves the directory and every membership empty, which is why a `community`-audience post is unrenderable until it is seeded |
| `TopicsSeeder` | 12 platform subjects: Interfaces, Forms, Accessibility, CSS, JavaScript, PHP, Security, Performance, Testing, Tooling, Data, Layout | Idempotent by slug through `updateOrCreate`, and it writes content columns only — never `is_active`, because once a row exists the on/off switch belongs to the operator and a re-seed that switched a retired subject back on would put it in front of the public. The list is a **candidate** list: `PublicTaxonomy` still hides any subject no published row carries, so a fresh install shows the one or two chips that actually open something. The shipped names live in a static `defaults()` array so the idempotency test can assert against the same list the seeder writes |
| `IdeaCategorySeeder` | The same short filing list for **each seeded account** | Idempotent by `(user_id, slug)` using `updateOrCreate`, the pattern `MarketplaceCategorySeeder` uses. A category is per-account, so a single global list would show one account another account's folders; seeding the shape is what makes the Ideas workspace non-empty on a fresh install without inventing anybody's ideas. It seeds **no ideas** — an inbox is the account's own |
| `LabSnippetSeeder` | Four example snippets, one per `LabLanguage` case | Also idempotent by slug. These are the only rows the lab ships pre-seeded, because a workspace is a scratch buffer and an idea is somebody's thinking, whereas a snippet is a demonstrable artefact. The `php` example is seeded deliberately: it is the row that proves a PHP snippet lists, escapes and copies without a Run button. Each example also carries two or three topic slugs from `TopicsSeeder` through the same action, which is what makes the gallery's subject row non-empty on a fresh install — a snippet with no topic would ship the facet as an empty strip |

**Why `PostSeeder` uses the action rather than `Post::create()`.** Every derived
column (`body_html`, `body_text`, `word_count`, `reading_time`, `excerpt`) is
produced by the same pipeline production uses. Seeding through a shortened path
would let a bug in the pipeline hide behind data written correctly by different
code — and would produce posts whose `body_html` was empty, so the first page
anyone loaded would render blank.

**Not for production.** These accounts exist so every dashboard in the access
model is reachable immediately after setup. `composer setup` runs
`migrate --force` **without** `--seed`; seeding is always a separate, explicit
step.

**`App\Support\FeedPersonas` is the single source for feed identities.** Ten
authors — `handle`, `name`, `email`, `avatar`, `role` — as a plain value list that
touches no database, read by `UserSeeder` (which gives `shamimstack@gmail.com` its
`shamimstack` handle), `CommunitySeeder` (whose member roster is handles into the
registry), `FeedPostSeeder` (which resolves `user_id` from a corpus byline, and is
order-independent because `authorFor()` creates the account if this install has
none), and `feed:simulate` (`App\Console\Commands\SimulateFeedPost`).

Before the registry existed, that one identity was repeated in four places and had
already drifted: the corpus said `Kenji Sato · @kenji · KS` while the community
seeder's persona list said `Kenji Watanabe`, and the corpus's `@danielo` was
seeded as `@daniel`. **The corpus values won**, because they are the text every
visitor reads on a published card. The visible consequence, stated rather than
buried: in the community directory's member lists, `Kenji Watanabe` became
`Kenji Sato` and `@daniel` became `@danielo`. Handles are stored **bare** in
`users.handle` (without the `@` — §1.4) while `feed_posts.handle` keeps the
corpus's decorated spelling, and `findByHandle()` accepts either spelling so a
corpus byline reading `@kenji` resolves to the same persona.

---

## 9. Portability Notes

| Concern | Approach |
| --- | --- |
| Boolean storage | SQLite stores booleans as integers; MySQL as `tinyint(1)`. Eloquent's `boolean` cast normalises both. Never compare a boolean column to `true` in raw SQL. |
| Timestamps | `timestamp` columns, always accessed through Carbon. No string date comparison in SQL. |
| Case-insensitive matching | SQLite's `LIKE` is case-insensitive for ASCII by default; MySQL's depends on collation. Do **not** rely on it — use `whereRaw('LOWER(col) LIKE ?')` with a lower-cased term when case-insensitivity is a requirement. |
| `orderByRaw` | Used in exactly one place (§6.4) with a literal `CASE`. No engine-specific functions (`IFNULL` vs `COALESCE`, `strftime` vs `DATE_FORMAT`). |
| JSON columns | **In use**, contrary to this row's original claim. `feed_posts` holds `tags`, `reactions`, `poll`, `answer`, `activity`, `detail`; `marketplace_products` holds `tags`; `passkeys` holds `credential`. SQLite needs the JSON1 extension for this and PHP's bundled `sqlite3` has it — the phase's test run is the proof, because `FeedPost::withDetail()` is asserted against a real SQLite database. The rule this imposes: JSON is written through a model cast and read back through that cast, so no surface hand-decodes it. |
| JSON path operators | `FeedPost::withDetail()` filters on `detail->diff`. `->` is Laravel's portable JSON-path operator — it emits `json_extract()` on SQLite and `->` on MySQL — so the scope is not engine-specific. It is also the only place JSON is touched in SQL at all. |
| Full-text search | Not used (see §2.1). |
| Auto-increment overflow | `unsignedBigInteger` on everything that grows unboundedly (`view_count`); `unsignedInteger` where 4 billion is genuinely unreachable (`word_count`). |

---

## 10. Ideas and Developer Lab tables

Seven module tables, one platform taxonomy of three, two modules, one shared
publication rule. They are documented
here rather than in a module-specific file because the invariants that matter —
what a foreign key does on delete, which columns are unique, what an enum case
costs the reader — are schema facts wherever the screen that writes them lives.

Migrations `2026_10_20_100000` through `100006`, in the dependency order the
foreign keys require: categories before ideas, ideas before plans, plans before
tasks; the three lab tables are independent of the Ideas four. The taxonomy's
three (`100007`–`100009`) come last because each of them points at a table above,
not the other way round: §10.10 documents them.

### 10.1 `idea_categories`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `user_id` | `fk → users` | `cascadeOnDelete` — an account's folders die with it |
| `name` | `string` | |
| `slug` | `string` | unique **inside the account**: `unique(user_id, slug)` |
| `description` | `text nullable` | |
| `created_at`, `updated_at` | `timestamp` | |

The composite unique is the deliberate difference from every other slug in this
schema. A category has no public URL, so nothing outside its author ever resolves
it; two accounts both filing a folder called "Platform experiments" is the
expected case, not a collision. A global unique here would turn an ordinary
second account's ordinary first folder into a validation failure.

Indexed `(user_id, name)` for the alphabetical list the workspace renders.

### 10.2 `ideas`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `user_id` | `fk → users` | `cascadeOnDelete` |
| `idea_category_id` | `fk → idea_categories nullable` | **`nullOnDelete`** — deleting a folder never deletes what was in it |
| `title` | `string` | |
| `slug` | `string` | `unique` — this one is global, because `/ideas/{idea:slug}` is a public URL |
| `summary` | `text nullable` | |
| `content` | `longText nullable` | Markdown, stored as source. Not rendered into a cached HTML column, because there is no read path that would benefit enough to justify the second copy |
| `status` | `string(32)` default `inbox` | `App\Enums\IdeaStatus` |
| `priority` | `string(16) nullable` | `App\Enums\WorkPriority`; null means "not triaged", which is a real state and therefore not a case |
| `potential` | `string(16) nullable` | `App\Enums\IdeaPotential` |
| `visibility` | `string(16)` default `private` | `App\Enums\ContentVisibility` |
| `is_pinned` | `boolean` default `false` | |
| `view_count` | `unsignedBigInteger` default `0` | |
| `published_at` | `timestamp nullable` | |
| `created_at`, `updated_at` | `timestamp` | |

Indexes: `(user_id, status)` for the workspace bands, `(user_id, is_pinned,
created_at)` for pinned-first ordering, `(visibility, published_at)` for the
public index, and a single-column index on `idea_category_id` for the filter.

**Search is `LIKE` over `title`, `summary` and `content`.** SQLite has no
full-text index, so anything claiming a search index would be claiming a MySQL
feature on the default connection. The consequence for a reader: ideas search is
substring matching, correct but not tolerant of a misspelling, and it is the same
trade `posts` makes.

### 10.3 `idea_plans`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `user_id` | `fk → users` | `cascadeOnDelete` |
| `idea_id` | `fk → ideas nullable` | **`unique`** and `nullOnDelete` |
| `title` / `slug` | `string` | slug `unique` |
| `summary`, `problem_statement`, `proposed_solution`, `scope`, `out_of_scope`, `goals` | `text nullable` | the spec's structured sections |
| `notes` | `longText nullable` | Markdown |
| `status` | `string(32)` default `draft` | `App\Enums\PlanStatus` |
| `priority` | `string(16) nullable` | `WorkPriority` |
| `estimated_effort` | `string(60) nullable` | free text — "3–4 days", not a fake number column |
| `target_start_date`, `target_end_date` | `date nullable` | |
| timestamps | | indexed `(user_id, status)` and `(user_id, updated_at)` |

Two schema facts that are not any code's promise:

- **`idea_id` is unique.** "One idea, one plan" is enforced by the database, not
  by `ConvertIdeaToPlan`. A second conversion of the same idea fails at the
  constraint rather than at a check someone might forget to run.
- **`idea_id` is nullable and nulls on delete.** The idea a plan came from is a
  link, not a lineage. An account that cleans out its inbox must not be able to
  damage the plan that inbox entry produced, which is what `docs/Idea.md` asks
  for in the other direction too: conversion preserves the original.

Requirements, technical approach and stack live in `notes` rather than in six
more nullable columns. The spec asks for sections "filled in gradually", which is
a form concern; a schema that pre-splits prose makes every later change to the
form a migration.

Plans have **no visibility column**. Decision was that ideas and snippets are the
publishable objects; a plan is working notation.

### 10.4 `idea_tasks`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `plan_id` | `fk → idea_plans` **not null** | `cascadeOnDelete` |
| `title` | `string` | |
| `description` | `text nullable` | |
| `status` | `string(32)` default `todo` | `App\Enums\TaskStatus` |
| `priority` | `string(16) nullable` | `WorkPriority` |
| `due_date` | `date nullable` | |
| `sort_order` | `unsignedInteger` default `0` | |
| `completed_at` | `timestamp nullable` | |
| timestamps | | indexed `(plan_id, status)` and `(plan_id, sort_order)` |

There is deliberately **no `user_id`**. Ownership derives from the plan's author,
so a task can never drift away from the plan it belongs to, and every mutating
action re-asserts the parent's owner instead of trusting a column that could have
been written by a different hand. `plan_id` is not nullable for the same reason:
a task with no plan is a fact about nothing, so its cascade is a delete rather
than a null.

### 10.5 `lab_workspaces`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | the route key — a workspace is never public and needs no slug |
| `user_id` | `fk → users` | `cascadeOnDelete` |
| `name` | `string` | |
| `html`, `css`, `js` | `longText nullable` | one column per pane |
| `last_run_at` | `timestamp nullable` | set by Run, not by Save |
| timestamps | | indexed `(user_id, updated_at)` |

Column-per-file rather than a JSON blob because one row is exactly the trio a
live preview needs, nothing queries inside a pane, and a cast maintained for
nobody is a cast maintained wrong. `last_run_at` stays separate from
`updated_at` so "saved an hour ago, run five minutes ago" remains two readable
facts.

### 10.6 `lab_snippets`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `user_id` | `fk → users` | `cascadeOnDelete` |
| `language` | `string(32)` | `App\Enums\LabLanguage` |
| `title` / `slug` | `string` | slug `unique` |
| `code` | `longText` | the payload |
| `description` | `text nullable` | |
| `visibility` | `string(16)` default `private` | `ContentVisibility` |
| `view_count` | `unsignedBigInteger` default `0` | |
| `published_at` | `timestamp nullable` | |
| timestamps | | indexed `(user_id, language)` and `(visibility, published_at)` |

`code` is **never markup on any surface**. Every view escapes it into
`<pre><code>`, so a snippet containing a closing script tag is a string on the
page rather than an instruction to the browser reading it. Where the code *is*
executed, it is executed inside the sandboxed frame described in
[`lab.md`](./lab.md) — an opaque origin, `allow-scripts` and nothing else — not
on the page that lists it.

### 10.7 `lab_executions`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `user_id` | `fk → users` | `cascadeOnDelete` |
| `language` | `string(32)` | recorded, not derived |
| `label` | `string(160) nullable` | the workspace name or snippet title at the moment of the run |
| `created_at`, `updated_at` | `timestamp` | indexed `(user_id, created_at)` |

Append-only, and the smallest table in the application by intent. `language` and
`label` are denormalised for the same reason: a history row that answers "what
did I run" by looking its source up again goes blank when the source is renamed
or deleted, and a history whose shape depends on somebody else's housekeeping is
not a history. The table exists to serve two lines of the original spec —
execution history, and execution limits and usage tracking — and nothing else.
Adding an output column, an error column or a duration would make it a log
retention problem, which is a decision deferred with the server-side runner.

### 10.8 Module enums

| Enum | Cases | Stored in | |
| --- | --- | --- | --- |
| `IdeaStatus` | `inbox`, `draft`, `under_review`, `approved`, `archived` | `ideas.status` | carries `allowedTransitions()` |
| `PlanStatus` | `draft`, `planning`, `ready`, `in_progress`, `on_hold`, `completed`, `cancelled` | `idea_plans.status` | carries `allowedTransitions()`; `completed` is terminal |
| `TaskStatus` | `todo`, `in_progress`, `blocked`, `done` | `idea_tasks.status` | |
| `WorkPriority` | `low`, `medium`, `high`, `urgent` | ideas, plans **and** tasks | shared deliberately; "unset" is a null column, not a fifth case |
| `IdeaPotential` | `low`, `medium`, `high` | `ideas.potential` | three honest steps instead of a score nobody can explain |
| `ContentVisibility` | `private`, `public` | `ideas.visibility`, `lab_snippets.visibility` | **no `published` case** — see below |
| `LabLanguage` | `html`, `css`, `javascript`, `php` | `lab_snippets.language`, `lab_executions.language` | `isBrowserRunnable()` is the execution policy |

**Publication is a conjunction, not a flag.** `ContentVisibility` has two cases
and neither is `published`, because a row is readable by a stranger only when
`visibility === public` **and** `published_at` is non-null. Both halves are
written together in exactly one place per module — `SyncIdeaVisibility` and
`SaveLabSnippet` — so no code path can set one and forget the other. Each has one
additional precondition of its own, and the difference between them is the
difference between the two objects: an **idea** must be `Approved` before it can
go public, because an inbox capture is not a finished thought; a **snippet** must
contain non-whitespace code, because a public page with an empty block on it is
not something anybody should be sent to.
The timestamp is stamped once and **not cleared when a row goes back to private**:
unpublishing hides work, it does not erase when the work was first worth reading.
That also means `published_at` alone is not a public flag and must never be
tested on its own, which is why every read path goes through `isVisibleTo()`.
The AI Hub
reaches the same conclusion through a different pair (`AiPublicationStatus::Published`
plus a `published_at`, conjoined in `AiArticle::isVisibleTo()`), and the shape is
what matters: neither a flag nor a timestamp alone is ever the test for whether a
stranger may read a row.

`LabLanguage` is the whole difference between a snippet that runs and one that
does not, in one method: `isBrowserRunnable()` is true for HTML, CSS and
JavaScript and false for PHP. PHP is a fourth case because refusing to store
somebody's PHP would be a worse answer than storing it and being honest that no
runner exists — and the flag is re-checked server-side in `RecordLabExecution`, so
the UI hiding the Run button is a courtesy, not the control.

`App\Enums\RolePlan` already owned the word "plan" for dashboards, which is why
the models here are `IdeaPlan`/`idea_plans` and never `Plan`/`plans`.

### 10.9 Integrity rules the module tables add to §5

| Rule | Where enforced | Why there |
| --- | --- | --- |
| One plan per idea | `idea_plans.idea_id` unique index | the database, not the action |
| Deleting a category keeps its ideas | `nullOnDelete` | a folder is metadata |
| Deleting an idea keeps its plan | `idea_id` nullable + `nullOnDelete` | conversion must not be destructive in either direction |
| Deleting a plan deletes its tasks | `plan_id` not null + `cascadeOnDelete` | a task with no plan is nothing |
| Ownership of a task | `plan.user_id`, re-checked in every action | there is no `user_id` to forge |
| A slug may not name a screen | `IdeaSlug`, `PlanSlug`, `SnippetSlug` **and** registration order | two independent defences, both required |
| A run must be a runnable language, under today's limit | `RecordLabExecution` | the Run button is a UI, not a permission |
| A subject may be attached to a row at most 8 times | `Topic::MAX_PER_ROW`, applied inside `Topic::syncRows()` | there is no database constraint for a count, and eight is a UI limit (a chip row of twelve is a wall), so it is enforced at the only writer |
| A hand-crafted topic list cannot attach an inactive or nonexistent id | the same `syncRows()` — ids are int-cast, de-duplicated, capped, then filtered against `Topic::active()`; what fails is dropped silently | the picker is a convenience; the sync is the contract |
| Switching a subject off leaves every pivot intact | `is_active` is a display flag, not a link | the row keeps its subject so it reappears when the operator switches it back on; deleting the links would silently re-file the author's work |

### 10.10 The platform taxonomy: `topics`, `idea_topic`, `lab_snippet_topic`

Three tables that belong to neither module. They are documented after the seven
because nothing in them is module-specific: they are the shared subject list the
public facet, `/topics` and both detail pages read.

#### `topics`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint pk` | |
| `name` | `string` | not unique — `slug` is the key everything resolves by, and `updateOrCreate` on slug is what the seeder uses |
| `slug` | `string` | `unique`, globally. It indexes a public URL (`/topics/{topic:slug}`), so unlike `idea_categories.slug` it cannot be scoped to an account |
| `description` | `text nullable` | shown on the `/topics` card; a subject with nothing to say still lists |
| `icon` | `string(64) nullable` | a Flux icon name, rendered as `<flux:icon :name="$topic->icon ?? 'tag'">`. A column rather than a constant because the honest default is "pick one that fits", and a wrong one is cosmetic, not broken |
| `sort_order` | `unsignedSmallInteger` default `0` | the tie-break: the facet orders by published count first, then this, so a re-ordering is a one-column edit rather than a code change |
| `is_active` | `boolean` default `true` | Retires a subject without touching a single pivot row. There is **no admin console for `topics`** — the hub's console manages `ai_topics` and nothing else — so this flag and `sort_order` are exercised through the database or a re-seed today, and `TopicsSeeder` never writes this column so a re-seed cannot switch a retired subject back on. |
| `created_at`, `updated_at` | `timestamp` | |

Indexed `(is_active, sort_order)`, which is the shape every public read asks for.

**There is no `color` column and no `user_id` column.** Colour would be a second
accent competing with the one the site has; ownership would be the lie — a subject
is what a row is *about*, not who filed it, so it cannot belong to an account.

**`topics` is not `ai_topics`, and not `idea_categories`.** The adjacency is
confusing on purpose of history, so the three are stated plainly:

| Table | Owner | Shape | Public |
| --- | --- | --- | --- |
| `idea_categories` | one account | flat, one parent per idea | never — it is private filing |
| `ai_topics` | the AI Hub | **hierarchical** (`parent_id`, `rootLevel()`, `hasChildren()`) | yes, within the hub |
| `topics` | the platform | flat, many-to-many, shared by two modules | only through the count gate |

A subject means the same thing to an idea and to a snippet, which is why one
table serves both rather than the `idea_topics`/`lab_topics` pair the plan first
named: two parents would be two names for one concept, two seeders, and two slug
spaces that could disagree.

#### `idea_topic` and `lab_snippet_topic`

| Column | Type | Notes |
| --- | --- | --- |
| `idea_id` / `lab_snippet_id` | `fk` | `cascadeOnDelete` |
| `topic_id` | `fk → topics` | `cascadeOnDelete` |
| `sort_order` | `unsignedSmallInteger` default `0` | the author's own order, written by the sync action |
| *(primary)* | `idea_id, topic_id` | no `id`, no timestamps — the row exists only to express the link |

Indexed `(topic_id, sort_order)` in both, because the public read walks the
subject and asks for its rows in the author's order. The pivot `sort_order` is not
cosmetic: the first link is the primary subject, and that is what a card badge and
the structured-data keywords name when a row carries more than one.

**Nothing in the schema says "published".** No column on `topics` and no column on
either pivot carries visibility, because whether a subject may be advertised is not
a stored fact — it is derived every request from whether a published row carries
it. That rule lives in `App\Services\PublicTaxonomy` and nowhere else, so the two
facet rows, `/topics`, `/topics/{slug}` and the structured-data keywords cannot
drift apart. See `PublicTaxonomyTest`.

---

## 11. Change Log

| Version | Date | Change |
| --- | --- | --- |
| 1.0 | 2026-10-12 | Initial schema for DevFolio v1 |
| 1.1 | 2026-10-19 | Feed Composer & Publishing (PRD Phase 1): §1.4 `users.handle`, §2.6 the publishing columns on `feed_posts`, §2.7 `feed_attachments`, §3.6 `FeedPostStatus`, §3.7 `FeedPostAudience`, §4.7 the two feed models, §7.3 the community-feed migration batch, §8 the persona registry and the `CommunitySeeder` invocation caveat, §9 corrected for the JSON columns this schema has always had. |
| 1.2 | 2026-10-20 | Ideas and Developer Lab: §1.2 gains the seven ◆ rows, §10 documents `idea_categories`, `ideas`, `idea_plans`, `idea_tasks`, `lab_workspaces`, `lab_snippets`, `lab_executions`, the seven module enums and the integrity rules they add. The Change Log was §10 before this section was inserted. |
| 1.3 | 2026-10-20 | Audit pass against the code: §3.4 corrected to **30** permission cases with Administration at 12, and states that adding a case was deliberately declined; §4.8 added, the four conventions that bind across the seven module models; §7 gains the catalogue/About/AI Hub batch (13–26) and the module batch (27–33), and now totals the **38** migration files that exist; §8 gains `IdeaCategorySeeder` and `LabSnippetSeeder` and the corrected call order. |
| 1.4 | 2026-10-21 | The platform taxonomy: §1.2 gains the three ◇/◆ rows, §7.6 gains migrations 34–36 and the file count is corrected to **41**, §8 gains `TopicsSeeder` and the reason the taxonomy seeds before the snippets, §10.9 gains the three integrity rules the sync enforces, and §10.10 documents `topics`, `idea_topic` and `lab_snippet_topic` — including why one flat shared table replaced the per-module pair, how `topics` differs from `ai_topics` and `idea_categories`, and that no column anywhere stores "published" because visibility is derived by `PublicTaxonomy`. |
