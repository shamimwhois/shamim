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
| `users`   | *(existing)* Authentication identity | 1–100 | No |
| `teams`, `team_members`, `team_invitations` | *(existing)* Starter-kit teams; not used for content scoping | small | Yes (teams) |
| `cache`, `jobs`, `sessions`, `password_reset_tokens`, `passkeys` | *(existing)* Framework tables | — | No |
| **`categories`** | ★ One-to-many grouping for posts | 5–20 | No |
| **`tags`** | ★ Many-to-many labelling for posts | 20–200 | No |
| **`posts`** | ★ The primary content entity | 50–5 000 | Yes |
| **`post_tag`** | ★ Post ↔ Tag pivot | 5× posts | No |
| **`comments`** | ★ Moderated reader discussion | 0–10× posts | No |

★ = introduced by this feature set.

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

**A note on the search index.** `body_text` is searched with a case-insensitive `LIKE '%term%'`, which cannot use a standard B-tree index because of the leading wildcard. At the expected corpus size (50–5 000 rows) a scan of `body_text` is acceptable and is bounded by pagination. Adding a full-text index is the documented escalation path when the corpus grows; see [TRD §3.6](./TRD.md#36-search-strategy).

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

### 4.5 Shared concerns

| Concern | Responsibility | Why shared |
| --- | --- | --- |
| `App\Concerns\HasSlug` | Derives a unique slug from a source field; applies an incrementing suffix on collision; respects a manual override | Posts, categories, and tags all need identical collision behaviour. A per-model copy would drift. |
| `App\Concerns\HasReadingTime` | Computes word count and reading minutes from plain text (200 wpm) | Used by the post pipeline and by tests that need the same algorithm. |

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

---

## 7. Migration Plan

Migrations are anonymous classes, created with `php artisan make:migration`, ordered so that foreign keys never reference a table that does not yet exist.

| # | Migration | Creates |
| --- | --- | --- |
| 1 | `2016_01_01_000010_create_categories_table` | `categories` |
| 2 | `2016_01_01_000011_create_tags_table` | `tags` |
| 3 | `2016_01_01_000012_create_posts_table` | `posts` (FKs → `users`, `categories`) |
| 4 | `2016_01_01_000013_create_post_tag_table` | `post_tag` (FKs → `posts`, `tags`) |
| 5 | `2016_01_01_000014_create_comments_table` | `comments` (FKs → `posts`, `users`, self) |

**Ordering note.** The timestamps place these after the framework's `0001_01_01_*` tables but before the existing `2024`/`2025`/`2026` starter-kit migrations. That is safe here because none of these tables are referenced by the starter-kit migrations, and `users` — the only dependency — is already present. On a fresh `php artisan migrate` the order is: framework tables → content tables → starter-kit additions.

**Rollback.** Each `down()` drops exactly what its `up()` created, in reverse dependency order, so `migrate:rollback` on the batch leaves the starter kit intact.

---

## 8. Seeding Strategy

Seeders produce a site that is immediately browsable, because a portfolio with three lorem-ipsum posts is not demonstrable.

| Seeder | Produces | Notes |
| --- | --- | --- |
| `CategorySeeder` | 6 categories: Architecture, Backend, Frontend, DevOps, Databases, Career | Real names — these read as a real engineer's taxonomy |
| `TagSeeder` | ~24 tags: laravel, php, livewire, react, typescript, postgres, redis, docker, testing, performance, security, caching, queues, observability, … | Slug-normalised, de-duplicated |
| `PostSeeder` | 12 posts: 9 published, 2 drafts, 1 featured | Real technical prose with prose bodies, code fences, and headings. Each post gets 1 category and 2–4 tags. `body_html`/`body_text`/`reading_time` produced by the same pipeline the editor uses |
| `CommentSeeder` | ~30 comments across published posts: 20 approved, 10 pending | Divided across several users so the thread looks real |
| `DatabaseSeeder` | Calls the above plus the existing user/team seeding | Keeps `composer setup` a one-command path to a working app |

**Why the seeder exercises the real pipeline.** `PostSeeder` calls the same `CreatePost` action the editor calls. Seeding through a shortened path would let a bug in the pipeline hide behind data that was written correctly by different code.

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
