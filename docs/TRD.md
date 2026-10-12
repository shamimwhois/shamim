# Technical Requirements Document — DevFolio

| Field | Value |
| --- | --- |
| Document version | 1.0 |
| Status | Approved for build |
| Audience | Senior engineers implementing or reviewing the system |
| Companion documents | [PRD](./PRD.md) · [Application Flow](./APP-FLOW.md) · [UI/UX Brief](./UI-UX-BRIEF.md) · [Schema](./SCHEMA.md) · [ERD](./ERD.md) |

---

## 1. Tech Stack

Versions below are the versions actually installed in this repository, verified from `composer.json`, `composer.lock`, and `package.json`. Do not assume a version — re-verify with `composer show --direct` and by reading `package.json` before relying on a specific API.

### 1.1 Backend

| Layer | Technology | Installed version | Rationale |
| --- | --- | --- | --- |
| Language | PHP | 8.5.11 | Constructor property promotion, readonly properties, enums, `#[Fillable]`/`#[Hidden]` attributes, first-class callable syntax. |
| Framework | Laravel | 13.35 | Eloquent, queues, migrations, validation, service container. Laravel 13 supplies attribute-based model configuration, which this codebase uses in preference to class properties. |
| Reactive UI | Livewire | 4.x | Server-driven reactivity removes the need for a separate JSON API and a SPA. State lives in PHP, which is where the authorisation and validation logic already lives. |
| UI components | Flux UI | 2.13+ | Free edition. Provides accessible primitives (modal, dropdown, field, toast, navbar) that would otherwise be reimplemented badly. |
| Auth | Laravel Fortify | 1.37+ | Headless auth: registration, login, password reset, email verification, two-factor, passkey. Views are supplied by this application, not the package. |
| Passkeys | `laravel/fortify` passkey support | — | WebAuthn login and registration, already wired through `PasskeyAuthenticatable`. |
| Admin scaffolding | `laravel/chisel` | 0.1+ | Project scaffolding helper; not used at runtime by product features. |
| Dev tooling | Pint, Larastan/PHPStan, Pest 5, Pail, Boost | see `composer.json` | Formatting, static analysis, testing, log tailing, framework-aware tooling. |

### 1.2 Frontend

| Layer | Technology | Installed version | Rationale |
| --- | --- | --- | --- |
| Build tool | Vite | 8.x via `vite-plus` | Config is wrapped in `defineConfig`/`lazyPlugins` from `vite-plus`; keep new plugins inside the existing `lazyPlugins` callback. |
| CSS | Tailwind CSS | 4.1+ | CSS-first configuration via `@theme`. There is **no** `tailwind.config.js` in this project and one must not be introduced. |
| Fonts | Bunny Fonts via `laravel-vite-plugin/fonts` | — | Privacy-friendly font delivery; configured in `vite.config.js`. |
| Client JS | Alpine.js | bundled with Livewire 4 | Do **not** install Alpine separately — Livewire 4 ships it. |
| Icons | Heroicons (via Flux) + Lucide (via `php artisan flux:icon`) | — | Search Heroicons for exact names; never invent icon names. |

### 1.3 Data

| Concern | Choice | Rationale |
| --- | --- | --- |
| Database | SQLite (`DB_CONNECTION=sqlite`) | Zero-configuration local development. All queries stay portable to MySQL/PostgreSQL — no SQLite-specific SQL. |
| Migrations | Anonymous class migrations | Laravel 11+ default; matches the existing `database/migrations` files. |
| Full-text search | Indexed `LIKE` predicates | `LIKE` over `title`, `excerpt`, `body` with a leading-anchor-friendly index strategy. Sufficient at this content volume; see [§3.6](#36-search-strategy). |
| Queue | `database` driver | Autosave, view counting, and email notifications are queued so requests stay fast. |
| Session | `database` driver | Enables multi-instance deployment without sticky sessions. |
| Cache | `database` driver | Cached tag/category counts and related-post results. |

### 1.4 Explicitly rejected dependencies

| Package | Considered for | Why rejected |
| --- | --- | --- |
| `spatie/laravel-permission` | Author roles | One author. A `user_id` column plus a policy is the entire permission model. |
| `spatie/laravel-medialibrary` | Post cover images | No image uploads in v1 (non-goal). Adding it introduces storage config, conversions, and queue surface for zero requirement. |
| Meilisearch / Algolia | Search | Operationally heavy for a few hundred posts. Revisit at >5 000 posts. |
| `spatie/laravel-sluggable` | Slugs | Slug generation is ~20 lines in a model mutator and needs custom collision handling anyway. |
| Vue/React | Frontend | Livewire + Alpine already covers every interaction in the PRD. A second rendering model would double the surface for no user-visible gain. |

**Rule:** the dependency set is frozen. Any addition requires approval, as stated in `AGENTS.md`.

---

## 2. System Architecture

### 2.1 High-level shape

DevFolio is a **server-rendered monolith with islands of reactivity**. There is no separate API tier, no SPA, and no client-side router.

```
┌──────────────────────────────────────────────────────────────────────┐
│                            Browser                                   │
│  HTML (server-rendered Blade)  +  Livewire JS  +  Alpine  +  CSS     │
└───────────────┬──────────────────────────────────┬───────────────────┘
                │ full document request            │ Livewire XHR
                ▼                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│                         Laravel Application                          │
│                                                                      │
│  routes/web.php ──► Blade views (public)                             │
│                 └─► Route::livewire() ──► Livewire page components   │
│                                                                      │
│  Livewire component  ──►  Action / Service  ──►  Eloquent Model      │
│         │                        │                     │             │
│         │                        │                     ▼             │
│         │                        │              SQLite / MySQL       │
│         │                        ▼                                   │
│         │                   Queue (database)                         │
│         ▼                                                            │
│   Policies / Gates ──► 403 on unauthorised action                    │
└──────────────────────────────────────────────────────────────────────┘
```

### 2.2 Layering rules

The application follows the layering already present in this codebase. New code must match it.

| Layer | Location | May depend on | Must not |
| --- | --- | --- | --- |
| Routes | `routes/*.php` | controllers, Livewire components | contain business logic |
| HTTP | `app/Http/**` | Actions, Models, Requests | query Eloquent directly where an Action exists |
| Livewire | `resources/views/components/**`, `resources/views/pages/**` | Actions, Models, Policies | contain multi-step business logic that belongs in an Action |
| Actions | `app/Actions/**` | Models, other Actions | know about HTTP or Livewire |
| Models | `app/Models/**` | Eloquent, other Models | perform side effects such as sending mail directly |
| Views | `resources/views/**` | view models, presenters | issue database queries |

**Existing conventions this project already established and that new code must follow:**

1. **Livewire component format is single-file (SFC)** in `resources/views/components/` and `resources/views/pages/`, with the ⚡ filename prefix. Class-based components are **not** used. An SFC opens with `<?php ... new class extends Component { ... }; ?>` followed by the Blade template. Verify with `config/livewire.php` if it ever appears; today the default applies.
2. **Multi-step domain operations live in Action classes** under `app/Actions/**`, invoked through a public `handle()` method, with dependencies constructor-injected and resolved by the container. See `app/Actions/Teams/CreateTeam.php`.
3. **Authorization uses Gates/Policies**, checked with `Gate::authorize()` at the top of the action that mutates state. See `app/Livewire/...` usages of `Gate::authorize('leave', $team)`.
4. **Models declare configuration with PHP attributes**: `#[Fillable([...])]` and `#[Hidden([...])]`, with casts in a `casts()` method. Do not add `protected $fillable` properties.
5. **Shared behaviour is extracted into concerns** under `app/Concerns/**` (traits). Validation rule sets live there too.
6. **Enums live in `app/Enums/`** with TitleCase keys, and are cast on the model.
7. **Value objects / DTOs live in `app/Data/`** as readonly classes.
8. **Fortify response contracts are bound to app classes** in `app/Http/Responses/`, registered in `FortifyServiceProvider`.

### 2.3 Request lifecycle

**Public page (full document):**

1. Route matched in `routes/web.php`.
2. Route middleware runs (`auth`, `verified`, `EnsureTeamMembership` where applicable).
3. Controller or page component resolves data through a scoped query.
4. Blade renders the document, including `@vite`, `@fluxAppearance`, SEO partials.
5. Browser hydrates Livewire islands.

**Livewire interaction:**

1. Component method invoked from the browser (`wire:click`, `wire:submit`, `wire:model.live`).
2. Livewire rehydrates the component from a signed snapshot.
3. Method validates input and authorises **inside the method**, treating it like an HTTP request.
4. State mutates; the component re-renders; only changed DOM is patched back.
5. Side effects (mail, counters) are dispatched to the queue, not executed inline.

### 2.4 Authentication architecture

Fortify provides the headless flows; this application owns the views and the post-login redirects.

- **Views** are bound in `FortifyServiceProvider::configureViews()` to `resources/views/pages/auth/*`.
- **Redirects** are bound through `app/Http/Responses/*` to the Fortify response contracts.
- **Registration** runs through `App\Actions\Fortify\CreateNewUser`.
- **Password reset** runs through `App\Actions\Fortify\ResetUserPassword`.
- **Rate limiting** is configured per limiter in `FortifyServiceProvider::configureRateLimiting()`: `login` at 5/min keyed by transliterated email + IP, `two-factor` at 5/min keyed by `login.id`, `passkeys` at 10/min.
- **Two-factor** and **passkey** support already exist on the `User` model via `TwoFactorAuthenticatable` and `PasskeyAuthenticatable`.

**New requirement:** post-login destination must respect an `intended` URL so that a reader who is prompted to log in before commenting returns to the post. This is already the framework default via the `intended()` redirect in `LoginResponse`; the comment prompt must therefore link to `route('login')` with the current URL passed as `intended`, or rely on the session-stored intended URL set by the `auth` middleware.

### 2.5 Authorisation model

There is exactly one privileged role: **the post author**. Authorisation is expressed as a Policy, not scattered `if` statements.

- `PostPolicy` governs `view` (published or owner), `create`, `update`, `delete`, `publish`, and `unpublish`.
- The `view` ability is the only one reachable by guests and is published-state aware.
- Every Livewire mutation calls `Gate::authorize()` before touching the database, mirroring the HTTP layer.
- Draft visibility is enforced in the **query**, not only in the policy, so an accidental policy bypass cannot leak drafts into a listing.

### 2.6 Rendering strategy for content

Markdown is rendered **server-side, once, at write time** for the published representation and cached with the post. Rationale:

- Rendering at read time on every request wastes CPU on content that rarely changes.
- Client-side rendering would ship a markdown parser plus a sanitiser to every reader for no benefit, and would hand attackers a sanitiser bypass surface in the browser.
- Caching the rendered HTML means a stored-XSS defect has exactly one place to be fixed.

The rendered HTML is produced from a sanitised pipeline and treated as trusted **only because** the sanitiser runs on write. Raw user input never reaches a `{!! !!}` sink.

---

## 3. Architecture Decisions

Each decision is recorded as context → decision → consequence, so a future maintainer can tell whether the reasoning still holds.

### ADR-001 — Server-rendered monolith with Livewire rather than an SPA + API

**Context.** The product needs reactivity in three places: blog filtering, the post editor, and comment moderation. Everything else is a document.

**Decision.** Use Livewire 4 for reactive surfaces and plain Blade for documents. No JSON API, no client router.

**Consequences.**

- ✅ One language, one place for validation and authorisation (`app/`, not duplicated in JS).
- ✅ No API versioning, no auth token handling, no CORS surface.
- ✅ SEO works by default because every page is server-rendered HTML.
- ⚠️ Every interaction is a round-trip. Mitigated by debouncing inputs and keeping payloads small.
- ⚠️ Livewire's snapshot payload grows with public property size. Mitigated by never storing the full post body as a hydrated property where avoidable and by using computed properties for derived data.

### ADR-002 — Single-file Livewire components, matching the existing codebase

**Context.** Livewire 4 supports SFC, MFC, and class-based components. This repository consistently uses SFCs with the ⚡ prefix.

**Decision.** Follow the established convention. All new components are SFCs under `resources/views/components/` (reusable) or `resources/views/pages/` (full-page, registered with `Route::livewire()`).

**Consequences.**

- ✅ Template and behaviour are colocated, which keeps small components readable.
- ✅ Matches every existing component, so no mixed-format confusion.
- ⚠️ Large components embed a lot of PHP in a view file. Mitigated by extracting logic into Actions and Services — an SFC should orchestrate, not compute.
- ⚠️ The ⚡ prefix is not a valid identifier on all filesystems. It is the project default; keep it.

### ADR-003 — Domain operations as Action classes

**Context.** Creating a post, publishing a post, and approving a comment each involve multiple steps and need to be callable from Livewire and from tests.

**Decision.** Put each in a dedicated class under `app/Actions/**` exposing `handle()`, with dependencies injected through the constructor. This matches the existing `App\Actions\Teams\CreateTeam`.

**Consequences.**

- ✅ One call site per operation; testable without HTTP or Livewire.
- ✅ Authorisation and transaction boundaries live in one place.
- ⚠️ Indirection for trivial operations. A single `save()` on a model does not need an Action; do not create ceremony where there is no orchestration.

### ADR-004 — Markdown stored as source, rendered HTML cached

**Context.** Posts must be authored in markdown and displayed as rich HTML with code highlighting.

**Decision.** Store the markdown source in `posts.body`. Store the sanitised rendered HTML in `posts.body_html`, regenerated on save. Store a plain-text projection in `posts.body_text` for search and reading-time calculation.

**Consequences.**

- ✅ Read path is a single column read; no parsing on request.
- ✅ Reading time and search do not need to parse markdown.
- ⚠️ Three representations of one field must stay consistent. Mitigated by regenerating all three inside one Action, inside one transaction.
- ⚠️ `body_html` must never be written from a non-sanitising path.

### ADR-005 — Derived content attributes computed on save, not on read

**Context.** Reading time, word count, excerpt, and slug are all derivable from the body.

**Decision.** Compute them in the saving pipeline and persist them. Allow the author to override `excerpt` and `slug`; never allow overriding the computed word count.

**Consequences.**

- ✅ Listing pages stay cheap — no per-row computation across a paginated set.
- ✅ Computed values are queryable and sortable.
- ⚠️ Changing the algorithm requires a backfill. Acceptable; document any such change in a migration that recomputes existing rows.

### ADR-006 — Scoped public queries, not global scopes

**Context.** Drafts must never appear in public listings, but the author must see them in the dashboard.

**Decision.** Do **not** attach a global scope for published state. Instead expose named query scopes on the model (`published()`, `draft()`, `recent()`) and require every public listing to call `published()` explicitly.

**Consequences.**

- ✅ Dashboard queries are not silently filtered, so "all my posts" genuinely means all.
- ✅ Published-ness is visible at the call site during review.
- ⚠️ A developer could forget `published()`. Mitigated by feature tests asserting drafts are absent from every public surface, and by a `scopePublished` name that reads as a deliberate act.

### ADR-007 — Related posts by tag overlap, then category, with a bounded query

**Context.** Each post should suggest further reading without a recommendation service.

**Decision.** Rank candidates by count of shared tags, break ties by shared category, then by recency. Limit to 3. For SQLite compatibility, compute the ranking in PHP over a bounded candidate set (posts sharing at least one tag or the same category) rather than emitting window functions.

**Consequences.**

- ✅ Deterministic, explainable, testable.
- ✅ No extra infrastructure.
- ⚠️ Candidate set must be bounded, or the query degrades as the corpus grows. Bound it by selecting candidates sharing the category plus the top-N tags, then caps at a hard limit.

### ADR-008 — Autosave over explicit save-only

**Context.** The author writes in bursts and would lose work if a tab closed with unsaved changes.

**Decision.** Debounced autosave for drafts, with an explicit save still available. Publish and unpublish are always explicit actions.

**Consequences.**

- ✅ Draft loss becomes very unlikely.
- ⚠️ Write amplification. Mitigated by debouncing and by skipping the write when content is unchanged.
- ⚠️ An autosave racing a manual save could clobber. Mitigated by a single in-flight lock and a monotonic timestamp check before applying a save.
- **Draft only.** Autosave never publishes. Publishing is a deliberate, confirmed transition.

### ADR-009 — Design tokens in CSS, no component-level magic numbers

**Context.** A unique visual identity must be consistent across dozens of templates.

**Decision.** Every colour, font, radius, shadow, and duration used by product code is declared as a custom property in `resources/css/app.css` under `@theme`, and templates reference the token rather than a literal.

**Consequences.**

- ✅ Retheming is a single-file change.
- ✅ Dark mode is expressed by redefining tokens, not by sprinkling `dark:` variants on every element (though `dark:` is still used for one-off adjustments).
- ⚠️ Requires discipline in review: a raw hex value in a template is a defect.

### ADR-010 — Slug uniqueness enforced at the database level

**Context.** Slugs appear in public URLs and must be stable and unique.

**Decision.** `posts.slug` carries a unique index. The slug generator applies an incrementing suffix on collision. Existing post slugs are never regenerated when the title changes, unless the author explicitly requests it, because published URLs must not break.

**Consequences.**

- ✅ No duplicate URLs, no race-condition-induced collisions survive a write.
- ✅ Published links stay valid after a retitle.
- ⚠️ A misleading slug (title changed, slug stale) is possible by design. The editor exposes a manual override.

### ADR-011 — Comments are moderated by default

**Context.** A public comment form on a technically-focused blog attracts spam.

**Decision.** New comments default to `pending`. Only `approved` comments render publicly. Approval is a single-click author action from the dashboard.

**Consequences.**

- ✅ Spam never appears publicly without a deliberate act.
- ✅ The author's own replies can be auto-approved.
- ⚠️ A delay between submission and visibility. The UI communicates this state explicitly to the commenter so it does not read as a failure.

### ADR-012 — Soft deletes for posts, hard deletes for comments

**Context.** Deleting a published post destroys a URL that may be linked externally. Deleting a spam comment has no archival value.

**Decision.** Posts use `SoftDeletes`. Comments delete permanently.

**Consequences.**

- ✅ Accidental post deletion is recoverable, and published URLs can be restored.
- ⚠️ Every post query must consider trashed rows. The default Eloquent behaviour excludes them, which is correct for all product queries.

---

## 4. Implementation Approach

### 4.1 Directory layout

The layout extends the existing structure. Bold entries are new.

```
app/
├── Actions/
│   ├── Fortify/                       (existing)
│   ├── Teams/                         (existing)
│   ├── Posts/                         ★ CreatePost, UpdatePost, PublishPost,
│   │                                    UnpublishPost, DeletePost, SyncPostTags
│   └── Comments/                      ★ ApproveComment, RejectComment
├── Concerns/                          (existing) ★ HasSlug, HasReadingTime
├── Console/                           (existing)
├── Data/                              (existing) ★ PostMetrics, AuthorProfile
├── Enums/                             (existing) ★ PostStatus, CommentStatus
├── Http/
│   ├── Controllers/                   (existing) ★ RssController, SitemapController
│   ├── Middleware/                    (existing)
│   ├── Requests/                      ★ StoreCommentRequest (if a non-Livewire path appears)
│   └── Responses/                     (existing)
├── Livewire/Actions/                  (existing)
├── Models/                            (existing) ★ Post, Category, Tag, Comment
├── Policies/                          (existing) ★ PostPolicy
├── Providers/                         (existing)
├── Rules/                             (existing)
└── Support/                           ★ MarkdownRenderer  (single rendering entry point)

database/
├── factories/                         ★ PostFactory, CategoryFactory, TagFactory, CommentFactory
├── migrations/                        ★ posts, categories, tags, post_tag, comments
└── seeders/                           ★ PostSeeder, CategorySeeder, TagSeeder

resources/
├── css/
│   └── app.css                        ★ extended with design tokens
├── js/
│   └── app.js                         ★ reading progress, share, TOC observers
└── views/
    ├── components/                    ★ post-card, tag-pill, share-bar, prose, ...
    ├── layouts/                        ★ public.blade.php,  public/nav, public/footer
    ├── pages/
    │   ├── auth/                      (existing)
    │   ├── settings/                  (existing)
    │   ├── teams/                     (existing)
    │   └── blog/                      ★ ⚡index, ⚡show, ⚡about
    ├── partials/                      ★ seo.blade.php
    └── ⚡home.blade.php / ⚡dashboard.blade.php

routes/
├── console.php                        (existing)
├── settings.php                       (existing)
└── web.php                            ★ extended with public + dashboard routes
```

### 4.2 Naming conventions

| Artifact | Convention | Example |
| --- | --- | --- |
| Livewire page component | `resources/views/pages/<area>/⚡<name>.blade.php`, registered via `Route::livewire()` | `pages/blog/⚡index.blade.php` |
| Livewire UI component | `resources/views/components/⚡<name>.blade.php` | `components/⚡post-card.blade.php` |
| Blade partial / component | kebab-case, referenced with `<x-...>` | `<x-post-card :post="$post" />` |
| Action | verb + noun, `handle()` entry point | `App\Actions\Posts\PublishPost` |
| Enum | singular noun, TitleCase cases | `App\Enums\PostStatus::Published` |
| Route name | dot-namespaced | `blog.index`, `blog.show`, `dashboard.posts.edit` |
| Migration | anonymous class, snake_case filename | `2026_10_12_000010_create_posts_table.php` |

### 4.3 Data flow for the primary write path (saving a post)

```
Editor (Livewire SFC: pages/dashboard/⚡post-editor)
  │  wire:model.live.debounce.800ms on body
  │  wire:submit / autosave tick
  ▼
Component method save()
  ├── $this->validate([...])                 ← validation rules as arrays
  ├── Gate::authorize('update', $post)
  └── app(UpdatePost::class)->handle($post, $data)
        │
        └── DB::transaction
              ├── derive slug (if title changed and no override)
              ├── derive excerpt (if blank)
              ├── MarkdownRenderer::render($body)   → sanitised HTML
              ├── derive word count + reading time from plain text
              ├── $post->fill(...)->save()
              └── $post->tags()->sync($tagIds)
  ▼
Component emits toast + refreshes save-state indicator
```

Key properties of this flow: validation runs before authorisation-independent side effects; authorisation runs before any write; all writes for one logical operation share a transaction; derivation happens in one place.

### 4.4 Data flow for the primary read path (rendering a post)

```
GET /blog/{post:slug}
  │
  ▼
Route model binding (Post, with category + tags + approved comments eager-loaded)
  │
  ├── PostPolicy@view  → abort(404) if draft and not owner
  │
  ▼
pages/blog/⚡show renders:
  ├── header      → title, category, tags, date, reading time
  ├── {!! body_html !!}       ← pre-sanitised, cached at write time
  ├── TOC         → parsed from body_html headings server-side
  ├── share-bar   → server-generated intent URLs
  ├── author card → from AuthorProfile data object
  ├── comments    → approved only, newest-first or threaded
  └── related     → bounded tag/category overlap query
  │
  ▼
partials/seo  → canonical, OG, Twitter Card, JSON-LD Article
```

Eager loading is mandatory on this path: `category`, `tags`, and `comments.author`. N+1 queries here are a defect, not a performance nicety.

### 4.5 Error handling

| Condition | Behaviour |
| --- | --- |
| Post not found, or draft requested by a non-owner | `404` — never `403`, so drafts are indistinguishable from non-existent URLs to anonymous users. |
| Authenticated non-owner attempts a mutation | `403` via `Gate::authorize()`. |
| Validation failure | Livewire renders field-level errors; the form retains all input. |
| Slug collision | Suffix applied transparently; no user-facing error. |
| Markdown sanitisation strips content | Rendered output reflects the sanitised result; the author sees the sanitised preview before publishing. |
| Queue job failure | Logged; the user-facing action that enqueued it has already succeeded. |

### 4.6 Performance approach

| Lever | Implementation |
| --- | --- |
| Eager loading | `with()` on every listing and detail query. Asserted by tests where N+1 is plausible. |
| Pagination | Cursor-free simple pagination is unnecessary; standard pagination at 9 rows keeps payloads small. |
| Rendered content | `body_html` cached at write time (ADR-004). |
| Counts | Category and tag counts cached, invalidated when a post transitions to or from published. |
| Autosave | Debounced, no-op writes skipped (ADR-008). |
| View counting | Incremented on the queue, not in the request. |
| Assets | Single Vite entry per bundle; CSS is one file; no blocking third-party scripts. |
| Fonts | Bunny Fonts, `@fonts` directive, `font-display: swap`. |
| Images | No uploaded imagery in v1; decorative graphics are inline SVG, which needs no network request. |

### 4.7 Security approach

| Threat | Mitigation |
| --- | --- |
| Stored XSS via markdown | Allow-list sanitiser on write; raw HTML never reaches a `{!! !!}` sink from user input. Test asserts `<script>` is stripped. |
| CSRF | Laravel's default token middleware; Livewire requests are signed. |
| Broken authorisation | `Gate::authorize()` in every mutating Livewire method and Action; drafts additionally excluded at query level. |
| Mass assignment | `#[Fillable]` attributes on every model; never `Model::create($request->all())`. |
| Enumeration of drafts | `404` not `403` for non-owners. |
| Brute force | Fortify rate limiters, already configured. |
| Comment spam | Moderation by default; honeypot field; authenticated-only commenting. |
| Secrets | `.env` only; `.env` is git-ignored. Never inline a secret in a template or a share URL. |
| Improper escaping in share URLs | Intent URLs are built server-side with `urlencode`; no client-side concatenation of user input. |

### 4.8 Testing approach

Framework: **Pest 5**, database-resetting feature tests by default (`tests/Pest.php` applies `RefreshDatabase` to `tests/Feature`).

| Layer | What is tested | Example |
| --- | --- | --- |
| Feature (HTTP) | Public pages render, drafts 404, SEO tags present | `tests/Feature/Blog/BlogIndexTest.php` |
| Feature (Livewire) | Filtering, search, pagination, autosave, authorisation | `tests/Feature/Blog/BlogFilterTest.php` |
| Feature (Livewire) | Post CRUD, status transitions, delete confirmation | `tests/Feature/Dashboard/PostManagementTest.php` |
| Feature (Livewire) | Comment submission, moderation gate, validation | `tests/Feature/Blog/CommentTest.php` |
| Unit | Reading time, slug generation, markdown sanitisation | `tests/Unit/Support/MarkdownRendererTest.php` |

Rules applied: use factories (never hand-built models); assert observable behaviour rather than implementation detail; cover each authorisation branch; leave framework behaviour to the framework.

### 4.9 Quality gates

Run before considering any change complete:

```bash
vendor/bin/pint --dirty --format agent     # formatting
composer types:check                       # PHPStan / Larastan
php artisan test --compact                 # full suite
npm run build                              # frontend bundle
```

Per `AGENTS.md`, only PHP files that were modified are formatted (`--dirty`), and the complete suite is run by the user after the targeted tests pass.

---

## 5. Non-Functional Requirements

| ID | Requirement | Target |
| --- | --- | --- |
| NFR-1 | Public page response time (p95, cached content) | < 200 ms server-side |
| NFR-2 | Livewire round-trip (p95) | < 300 ms for filter and search interactions |
| NFR-3 | Time to interactive (mobile 4G) | < 3.5 s |
| NFR-4 | Accessibility | WCAG 2.2 AA; zero axe-core violations on public pages |
| NFR-5 | Browser support | Last two versions of Chrome, Firefox, Safari, Edge; Safari iOS 16+; Chrome Android |
| NFR-6 | Responsive range | 360 px → 2560 px, no horizontal overflow at any width |
| NFR-7 | Data portability | All queries run unchanged on SQLite, MySQL 8, and PostgreSQL 15 |
| NFR-8 | Backup safety | No destructive command runs outside local; `DB::prohibitDestructiveCommands` is active in production |
| NFR-9 | Deployability | Runs on Laravel Cloud without modification; `composer setup` produces a working app from a clean clone |
| NFR-10 | Log hygiene | No personally identifying data in logs beyond the standard authentication records |

---

## 6. Deployment

The application targets **Laravel Cloud**, which is the fastest path to a scaled deployment for this stack.

- Build: `composer setup` (install, key generate, migrate, npm install, npm run build).
- Queue worker: required, because autosave-adjacent jobs, view counting, and notifications are queued. Cloud provisions this as a resource.
- Database: SQLite is development-only. Production uses a managed MySQL or PostgreSQL instance; **no application code may depend on SQLite behaviour** (see ADR-007 for the one place this rule constrained a design).
- Assets: built by Vite at deploy time; `vite manifest not found` means `npm run build` was skipped.
- Environment: `APP_ENV=production` activates stricter password rules (min 12, mixed case, numbers, symbols, uncompromised) via `AppServiceProvider::configureDefaults()`.

---

## 7. Traceability Matrix

| PRD requirement | Technical realisation | Test surface |
| --- | --- | --- |
| FR-1.x | `resources/views/⚡home.blade.php` + `AuthorProfile` data object | `tests/Feature/HomeTest.php` |
| FR-2.1–2.9 | `pages/blog/⚡index.blade.php`, `Post::scopePublished`, `scopeSearch` | `tests/Feature/Blog/BlogIndexTest.php`, `BlogFilterTest.php` |
| FR-3.1–3.10 | `MarkdownRenderer`, `pages/blog/⚡show.blade.php`, related-post query | `tests/Feature/Blog/PostDetailTest.php` |
| FR-4.x | Fortify + `pages/dashboard/*`, `Actions/Posts/*`, `PostPolicy` | `tests/Feature/Dashboard/*` |
| FR-5.x | `components/share-bar.blade.php`, `partials/seo.blade.php` | `tests/Feature/Blog/SharingTest.php` |
| FR-6.x | `resources/css/app.css` tokens, `layouts/public.blade.php` | Manual audit + Lighthouse |
| NFR-4 | Semantic landmarks, focus management, contrast tokens | axe-core manual run |
| NFR-7 | Portable Eloquent; no raw SQL | Code review |
