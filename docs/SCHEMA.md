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
| `users`   | *(existing)* Authentication identity, **extended with `role`** | 1–100 | No |
| `cache`, `jobs`, `sessions`, `password_reset_tokens`, `passkeys` | *(existing)* Framework tables | — | No |
| **`categories`** | ★ One-to-many grouping for posts | 5–20 | No |
| **`tags`** | ★ Many-to-many labelling for posts | 20–200 | No |
| **`posts`** | ★ The primary content entity | 50–5 000 | Yes |
| **`post_tag`** | ★ Post ↔ Tag pivot | 5× posts | No |
| **`comments`** | ★ Moderated reader discussion | 0–10× posts | No |

★ = introduced by this feature set.

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

26 cases in 5 groups (`PermissionGroup`): Content 6, Moderation 2, Selling 7,
Platform 3, Administration 10.

| Group | Count | Example |
| --- | ---: | --- |
| `content` | 6 | `post:create`, `post:publish` |
| `moderation` | 2 | `comment:moderate`, `taxonomy:manage` |
| `selling` | 7 | `product:create`, `sale:view-own`, `marketplace:use` |
| `platform` | 3 | `dev-lab:use`, `ai-hub:use`, `ideas:use` |
| `administration` | 10 | `admin:access`, `role:manage` |Exposes `group(): PermissionGroup`, `groupLabel()`, and the static
`grouped()` / `options()`.

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

### 7.3 Framework and starter-kit migrations

These ship with Laravel and the starter kit and are unmodified.

| Migration | Creates |
| --- | --- |
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache` (`cache`, `cache_locks`) |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2024_01_01_000000_create_passkeys_table` | `passkeys` |
| `2025_08_14_170933_add_two_factor_columns_to_users_table` | 2FA columns on `users` |

**Ordering note.** The `2026_10_12_*` content migrations run after the
framework tables, so `users` exists before `posts` references it. On a fresh
`php artisan migrate`: framework tables → passkeys and 2FA → content →
`users.role`.

**Rollback.** Each `down()` drops exactly what its `up()` created. Rolling back
the content batch leaves the framework tables intact, and
`add_role_to_users_table::down()` drops the index before the column — dropping
the column first would fail on SQLite.

---

## 8. Seeding Strategy

Seeders produce a site that is immediately browsable, because a portfolio with three lorem-ipsum posts is not demonstrable.

`DatabaseSeeder` orders the calls deliberately: **users → taxonomies → posts →
comments**. Posts reference categories and tags by name, and `PostSeeder`
resolves its author from the first user, so `UserSeeder` must run first.

| Seeder | Produces | Notes |
| --- | --- | --- |
| `UserSeeder` | **3 accounts, one per role** | `test@example.com` (admin, listed **first** so it owns the seeded posts), `seller@example.com` (seller), `user@example.com` (user). Password `password`. |
| `CategorySeeder` | 6 categories: Architecture, Backend, Frontend, DevOps, Databases, Career | Real names — these read as a real engineer's taxonomy |
| `TagSeeder` | ~24 tags: laravel, php, livewire, react, typescript, postgres, redis, docker, testing, performance, security, caching, queues, observability, … | Slug-normalised, de-duplicated |
| `PostSeeder` | 12 posts: 9 published, 2 drafts, 1 featured | Real technical prose with code fences and headings. Each post gets 1 category and 2–4 tags. Created through the real `CreatePost` action — not raw inserts |
| `CommentSeeder` | ~30 comments across published posts | Mixed approved/pending so the moderation queue is non-empty |

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

---

## 9. Portability Notes

| Concern | Approach |
| --- | --- |
| Boolean storage | SQLite stores booleans as integers; MySQL as `tinyint(1)`. Eloquent's `boolean` cast normalises both. Never compare a boolean column to `true` in raw SQL. |
| Timestamps | `timestamp` columns, always accessed through Carbon. No string date comparison in SQL. |
| Case-insensitive matching | SQLite's `LIKE` is case-insensitive for ASCII by default; MySQL's depends on collation. Do **not** rely on it — use `whereRaw('LOWER(col) LIKE ?')` with a lower-cased term when case-insensitivity is a requirement. |
| `orderByRaw` | Used in exactly one place (§6.4) with a literal `CASE`. No engine-specific functions (`IFNULL` vs `COALESCE`, `strftime` vs `DATE_FORMAT`). |
| JSON columns | Not used. Avoids the JSON1-extension dependency in older SQLite builds. |
| Full-text search | Not used (see §2.1). |
| Auto-increment overflow | `unsignedBigInteger` on everything that grows unboundedly (`view_count`); `unsignedInteger` where 4 billion is genuinely unreachable (`word_count`). |

---

## 10. Change Log

| Version | Date | Change |
| --- | --- | --- |
| 1.0 | 2026-10-12 | Initial schema for DevFolio v1 |
