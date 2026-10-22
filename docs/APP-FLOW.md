# Application Flow — DevFolio

User journeys and key interactions for every actor in the system.

> **Scoped by role.** This document was originally written for a single-author  
> product with no role model. The shipped application has three account roles  
> (`user`, `seller`, `admin`), each with its own dashboard. §1 and §2 were  
> revised to match; the journey narratives in §3 onward remain accurate for the  
> authoring flows they describe.
>
> Authoritative references: [`ROUTES.md`](ROUTES.md) for the full route map,  
> [`PERMISSIONS.md`](PERMISSIONS.md) for the role model.

---

## 1. Actors and Surfaces

Roles are defined in `app/Enums/AccountRole.php`. Permissions are **derived**  
from the role, never stored — see [`PERMISSIONS.md`](PERMISSIONS.md).

| Actor      | Role     | Surfaces                                                                                     | Land                |
| ---------- | -------- | -------------------------------------------------------------------------------------------- | ------------------- |
| **Guest**  | —        | Home, blog index, blog detail, about, auth pages, published ideas, the lab gallery            | —                   |
| **Reader** | `user`   | All guest surfaces + comment submission + a read-overview home + the Ideas and Lab workspaces | `/dashboard`        |
| **Seller** | `seller` | Everything a reader has, plus selling and publishing rights                                   | `/seller/dashboard` |
| **Admin**  | `admin`  | Everything, plus the authoring screens, moderation, accounts and the two module consoles      | `/admin/dashboard`  |

The guest row gained two surfaces when the modules shipped. Nothing about a
published idea or a published snippet requires an account, and the lab's landing page
and gallery were always public — the split is by *row*, not by *screen*: an
unpublished idea has no public URL at all.

### Role capabilities

| Capability                          | `user` | `seller` | `admin` |
| ----------------------------------- | :----: | :------: | :-----: |
| Write and edit own posts            |    ✓   |     ✓    |    ✓    |
| **Publish** a post                  |        |     ✓    |    ✓    |
| Manage taxonomy                     |        |     ✓    |    ✓    |
| **Moderate comments**               |        |          |    ✓    |
| Manage accounts and roles           |        |          |    ✓    |
| Capture and organise ideas           |    ✓   |     ✓    |    ✓    |
| Run HTML/CSS/JS in the lab sandbox   |    ✓   |     ✓    |    ✓    |
| Publish an idea or a snippet         |    ✓   |     ✓    |    ✓    |
| Read another account's unpublished ideas |        |          |    ✓    |
| Edit another account's idea or snippet |        |          |         |

Three restrictions are deliberate and worth noting when reading the journeys:

- **A plain reader cannot publish a post.** They may draft and edit their own
  writing, but making it public requires at least a seller role. This is the
  boundary that keeps arbitrary content off the public site.
- **Comment moderation is admin-only.** Sellers do not moderate, even though
  they can manage taxonomy.
- **The authoring screens are admin-only.** The table above lists *policy*
  permissions, which the matrix still grants widely; the post list, editor,
  queue and taxonomy editor live under `/admin` behind `admin:access`. A plain
  reader therefore holds `post:create` without a screen that exercises it.

The modules invert the first restriction on purpose, and the inversion is the most
interesting thing about the authorisation shape here. A plain account **can** publish
its own idea and its own snippet, because publication there is not a permission but a
property of the row: `visibility = public` **and** a `published_at` stamp, settable
only by the author through an action that refuses an idea the workflow has not
approved and a snippet with no code in it. The blog's rule exists because a post is
platform content; an idea is a person's thinking that they chose to show. The
consequence is that `/ideas` and `/dev-lab/gallery` can list an ordinary account's
work without anybody having granted it, so the safety of those surfaces rests on
`isVisibleTo()` and the publish action's refusals rather than on the role matrix.

The last row of the table above is the one to read carefully: an admin can see
everybody's unpublished ideas and take one down, and **nobody** — including an admin —
can edit one. That asymmetry is enforced in `IdeaPolicy`, in the console's lack of an
edit affordance, and in the fact that the console offers a stage filter, an author
column and a remove button and nothing else. An operator key buys oversight, never
authorship.

---

## 2. Route Map

The complete, verified route map lives in [`ROUTES.md`](ROUTES.md) — 135 routes  
with their enforced middleware, nineteen of them in the two module URL spaces. The  
table below is the subset that matters for these journeys.

| Route                                 | Name                                                                          | Guard                                  | Component                       |
| ------------------------------------- | ----------------------------------------------------------------------------- | -------------------------------------- | ------------------------------- |
| `GET /`                               | `home`                                                                        | —                                      | `pages::home`                   |
| `GET /blog`                           | `blog.index`                                                                  | —                                      | `pages::blog.index`             |
| `GET /blog/{post:slug}`               | `blog.show`                                                                   | —                                      | `pages::blog.show`              |
| `GET /about`                          | `about`                                                                       | —                                      | `pages::about`                  |
| `GET /feed.xml`                       | `feed`                                                                        | —                                      | `FeedController`                |
| `GET /sitemap.xml`                    | `sitemap`                                                                     | —                                      | `SitemapController`             |
| `GET /robots.txt`                     | —                                                                             | —                                      | `RobotsController`              |
| Auth routes                           | `login`, `register`, `logout`, `password.*`, `verification.*`, `two-factor.*` | —                                      | Fortify + `pages::auth.*`       |
| `GET /settings/profile`               | `profile.edit`                                                                | `auth`                                 | `pages::settings.profile`       |
| `GET /settings/security`              | `security.edit`                                                               | `auth`, `verified`, `password.confirm` | `pages::settings.security`      |
| `GET /settings/teams`                 | `teams.index`                                                                 | `auth`, `verified`                     | `pages::teams.index`            |
| `GET /dashboard`                      | `dashboard.index`                                                             | `auth`, `verified`                     | `pages::dashboard.index`        |
| `GET /admin/posts`                    | `admin.posts.index`                                                           | `auth`, `verified`, `permission:admin:access` | `pages::admin.posts.index`  |
| `GET /admin/posts/create`             | `admin.posts.create`                                                          | `auth`, `verified`, `permission:admin:access` | `pages::admin.posts.edit`   |
| `GET /admin/posts/{post:id}/edit`     | `admin.posts.edit`                                                            | `auth`, `verified`, `permission:admin:access` | `pages::admin.posts.edit`   |
| `GET /admin/comments`                 | `admin.comments`                                                              | `auth`, `verified`, `permission:admin:access` | `pages::admin.comments`     |
| `GET /admin/taxonomy`                 | `admin.taxonomy`                                                              | `auth`, `verified`, `permission:admin:access` | `pages::admin.taxonomy`     |
| `GET /seller/dashboard`               | `seller.dashboard`                                                            | `auth`, `verified`, `permission:product:create` | `pages::seller.dashboard`   |
| `GET /admin/dashboard`                | `admin.dashboard`                                                             | `auth`, `verified`, `permission:admin:access`    | `pages::admin.dashboard`    |
| `GET /admin/users`                    | `admin.users`                                                                 | `auth`, `verified`, `permission:admin:access`    | `pages::admin.users`        |
| `GET /ideas`                          | `ideas.index`                                                                 | —                                              | `pages::ideas.index`        |
| `GET /ideas/{idea:slug}`              | `ideas.show`                                                                  | —                                              | `pages::ideas.show` (404 unless `isVisibleTo()`) |
| `GET /ideas/dashboard`                | `ideas.dashboard`                                                             | `auth`, `verified`, `permission:ideas:use`     | `pages::ideas.dashboard`    |
| `GET /ideas/create`, `/ideas/{slug}/edit` | `ideas.create`, `ideas.edit`                                              | `auth`, `verified`, `permission:ideas:use`     | `pages::ideas.edit` (one component for both) |
| `GET /ideas/categories`               | `ideas.categories`                                                            | `auth`, `verified`, `permission:ideas:use`     | `pages::ideas.categories`   |
| `GET /ideas/plans`, `/plans/create`, `/plans/{plan:slug}`, `/plans/{plan:slug}/edit` | `ideas.plans`, `ideas.plan.create`, `ideas.plan`, `ideas.plan.edit` | `auth`, `verified`, `permission:ideas:use` | `pages::ideas.plans`, `pages::ideas.plan`, `pages::ideas.plan-edit` |
| `GET /dev-lab`, `/dev-lab/gallery`, `/dev-lab/snippets/{slug}` | `dev-lab.index`, `dev-lab.gallery`, `dev-lab.snippets.show` | —                          | `pages::dev-lab.index`, `pages::dev-lab.gallery`, `pages::dev-lab.snippet-show` |
| `GET /dev-lab/dashboard`              | `dev-lab.dashboard`                                                           | `auth`, `verified`, `permission:dev-lab:use`   | `pages::dev-lab.dashboard`  |
| `GET /dev-lab/sandbox`                | `dev-lab.sandbox`                                                             | `auth`, `verified`, `permission:dev-lab:use`   | `pages::dev-lab.sandbox`    |
| `GET /dev-lab/snippets`, `/snippets/create`, `/snippets/{slug}/edit`, `/history` | `dev-lab.snippets`, `dev-lab.snippets.create`, `dev-lab.snippets.edit`, `dev-lab.history` | `auth`, `verified`, `permission:dev-lab:use` | `pages::dev-lab.snippets`, `pages::dev-lab.snippet`, `pages::dev-lab.history` |
| `GET /admin/ideas`, `GET /admin/dev-lab` | `admin.ideas`, `admin.dev-lab`                                             | `auth`, `verified`, `permission:admin:access`  | `pages::admin.ideas`, `pages::admin.dev-lab` |
| `GET /dashboard/posts?…` and friends  | `dashboard.posts.*`, `dashboard.comments`, `dashboard.taxonomy`               | —                                            | 301 redirects to the `/admin` screens, query strings kept |

> **On the module route order.** Within each module URL space the workspace routes
> are registered first and the public pair last, so `GET /ideas/plans` reaches the
> plans screen even when some account has an idea slugged `plans`, and
> `/ideas/{idea:slug}/edit` — one segment deeper than the public detail route —
> never collides with it. `App\Rules\IdeaSlug`, `PlanSlug` and `SnippetSlug` close
> the same gap from the other direction by refusing to name a row after a screen.
> The ordering is a defence and the reserved-slug rule is a defence; one without the
> other leaves a hole.

> **On the `{current_team}` prefix.** The starter kit scopes `dashboard` under a  
> team slug at `/{current_team}/dashboard` (route name `team.dashboard`). It is  
> still registered, so the team flows continue to work, but it renders  
> starter-kit scaffolding rather than the application. **Login, registration and  
> e-mail verification no longer send anyone there** — each resolves the role's  
> own dashboard through `AccountRole::dashboardRoute()`. See  
> [`ROUTES.md`](ROUTES.md) §9 for why the route remains.

---

## 3. Guest Reader Journeys

### 3.1 Journey A — First impression (arrival from a social or professional link)

```
Entry: external link → https://<site>/
  │
  ├─► Hero renders: name, role, specialisms, years of experience
  │     └─ above-the-fold CTA: [Read the blog]  [Get in touch]
  │
  ├─► Scroll → Expertise grid (4–6 competencies)
  ├─► Scroll → Selected work (≤3 projects with stack chips)
  ├─► Scroll → Featured posts (≤3, with category + reading time)
  ├─► Scroll → Latest posts (6, newest first)
  └─► Footer: nav, social links, copyright
        │
        └─ Exit paths:
              ├─ [Read the blog]     → blog.index
              ├─ [Get in touch]      → mailto / contact block
              ├─ click a post card   → blog.show
              └─ click résumé        → résumé download
```

**Design intent.** A recruiter must be able to answer "who is this and are they senior?" without scrolling. The hero carries the entire first impression; everything below is confirmation.

**Key interactions.**

| Trigger               | Response                                                                  |
| --------------------- | ------------------------------------------------------------------------- |
| Page load             | CSS-driven entrance animation, suppressed under `prefers-reduced-motion`. |
| Hover a post card     | Card lifts slightly; title underlines; category chip brightens.           |
| Keyboard tab          | Focus follows DOM order: skip-link → nav → hero CTAs → content.           |
| Click "Read the blog" | Client-side navigation preserves scroll position policy (top).            |

### 3.2 Journey B — Browsing the blog

```
Entry: nav [Blog] or home CTA
  │
  ├─► blog.index renders:
  │     ├─ header: title, description, live post count
  │     ├─ featured post banner (only when no filter active)
  │     └─ grid: 9 published posts, newest first
  │
  ├─► Reader types in search box
  │     └─ wire:model.live.debounce.300ms
  │           ├─ matched on title | excerpt | body_text (case-insensitive)
  │           ├─ resets pagination to page 1
  │           └─ result count updates
  │
  ├─► Reader clicks a category chip
  │     └─ category filter set; featured banner hidden; URL query updated
  │
  ├─► Reader clicks a tag pill
  │     └─ tag filter toggled; combines with category (AND)
  │
  ├─► Reader clicks [Clear filters]
  │     └─ all filters, search term, and page reset
  │
  ├─► ⚠ No results
  │     └─ empty state: icon + "No posts match those filters" + [Clear all]
  │
  └─► Reader clicks a card → blog.show
```

**State model (all held in the Livewire component, reflected in the URL).**

| Property   | Type             | Default | URL key    |
| ---------- | ---------------- | ------- | ---------- |
| `search`   | `string`         | `''`    | `q`        |
| `category` | `?string` (slug) | `null`  | `category` |
| `tag`      | `?string` (slug) | `null`  | `tag`      |
| `page`     | `int`            | `1`     | `page`     |

**Filter semantics.** All three predicates are combined with logical AND. Changing `search`, `category`, or `tag` resets `page` to 1 — otherwise a reader narrowing results would land on an empty page 3.

**Interaction timing.**

| Interaction    | Debounce | Loading affordance                             |
| -------------- | -------- | ---------------------------------------------- |
| Search typing  | 300 ms   | Results area dims; spinner on the search field |
| Category click | none     | Immediate; chips show `data-loading`           |
| Tag toggle     | none     | Immediate                                      |
| Pagination     | none     | Scroll to top of grid                          |

### 3.3 Journey C — Reading a post

```
Entry: blog.index card, home card, related post, or external share link
  │
  ├─► Route model binding resolves Post by slug (eager: category, tags)
  │
  ├─► PostPolicy@view
  │     ├─ published  → continue
  │     ├─ draft + owner → continue with "Draft preview" banner
  │     └─ draft + other  → 404
  │
  ├─► Render:
  │     ├─ reading progress bar (top, sticky)
  │     ├─ breadcrumb: Blog / Category / Title
  │     ├─ header: title, author, published date, reading time, category, tags
  │     ├─ share bar (top)
  │     ├─ sticky TOC (desktop, lg+; collapsible drawer on mobile)
  │     ├─ body: sanitised HTML with highlighted code blocks
  │     ├─ share bar (bottom) + author card
  │     ├─ related posts (≤3)
  │     └─ comments section
  │
  └─► Continuous while reading:
        ├─ progress bar tracks scroll
        ├─ TOC highlights the current h2/h3 via IntersectionObserver
        └─ asynchronously: view counter incremented on the queue
```

**Progressive enhancement.** Progress bar and TOC highlighting are pure JS enhancements. Without JS the page is fully readable, the TOC still links to anchors, and the share bar still opens intent URLs. Nothing is behind a JS-only gate.

**Code block behaviour.**

| Condition       | Behaviour                                                                   |
| --------------- | --------------------------------------------------------------------------- |
| Long line       | Horizontal scroll within the block; the page itself never scrolls sideways. |
| Copy button     | Present on every `pre > code`; copies raw source.                           |
| Narrow viewport | Reduced padding and font size; no wrapping of code lines.                   |
| Reduced motion  | No animation on the copy confirmation.                                      |

### 3.4 Journey D — Sharing a post

```
Reader wants to share
  │
  ├─► Native share available? (navigator.share on mobile)
  │     └─ YES → [Share] opens the OS share sheet with title, text, url
  │
  ├─► Desktop / unsupported
  │     └─ share bar exposes explicit destinations:
  │           X/Twitter · LinkedIn · Facebook · Reddit · Hacker News · Email
  │           ＋ [Copy link]
  │
  ├─► Click a destination
  │     └─ Opens a popup window with the pre-built intent URL
  │         (title, url, and summary encoded server-side)
  │
  └─► Click [Copy link]
        ├─ Clipboard API available → write → inline "Copied!" confirmation (2 s)
        └─ Unavailable → select the URL text and prompt manual copy
```

**Metadata contract.** Every share destination and every unfurl relies on the same server-generated values:

| Value                    | Source                                    | Constraint                  |
| ------------------------ | ----------------------------------------- | --------------------------- |
| `og:title`               | `post.title`                              | ≤ 95 chars for full display |
| `og:description`         | `post.excerpt`                            | ≤ 200 chars                 |
| `og:url`                 | `route('blog.show', $post)`               | Absolute, canonical         |
| `og:type`                | `article` for posts, `website` elsewhere  | —                           |
| `og:image`               | site OG image (no per-post uploads in v1) | 1200×630                    |
| `article:published_time` | `post.published_at`                       | ISO 8601                    |
| `article:tag`            | repeated per tag                          | —                           |
| `twitter:card`           | `summary_large_image`                     | —                           |


### 3.5 Journey E — Commenting

```
Reader finishes a post and wants to respond
  │
  ├─► Guest?
  │     ├─ YES → comment form replaced by a prompt:
  │     │         "Log in to join the discussion" → route('login')
  │     │         (auth middleware stores the post URL as intended)
  │     └─ NO  → continue
  │
  ├─► Comment form: textarea (3–2000 chars) + hidden honeypot + [Post comment]
  │
  ├─► Submit (wire:submit)
  │     ├─ honeypot filled → silently discarded, generic success shown
  │     ├─ validation fails → inline field error, text preserved
  │     └─ valid → Comment created with status = pending
  │
  ├─► Confirmation: "Thanks — your comment is awaiting moderation."
  │
  └─► Comment appears publicly only after the author approves it
```

**Why the honeypot shows a success message.** Telling a bot it failed teaches it to retry differently. A uniform success response to every submission removes the feedback signal.

### 3.6 Journey F — Account creation (reader)

```
Entry: [Register] from a comment prompt or a direct auth link
  │
  ├─► /register
  │     ├─ fields: name, email, password, password confirmation
  │     ├─ password rules come from AppServiceProvider::configureDefaults()
  │     │   (production: min 12, mixed case, letters, numbers, symbols, uncompromised)
  │     └─ submit → App\Actions\Fortify\CreateNewUser
  │
  ├─► On success → RegisterResponse redirect
  │     └─ email verification required before dashboard access
  │
  └─► Verification email (log driver in local, real transport in production)
        └─ click link → VerifyEmailResponse → intended destination or dashboard
```

**Decision.** Readers and the author share one `users` table. The author is distinguished by owning posts, not by a role column. This keeps the permission model to a single policy and avoids inventing a role system for a one-person product.

---

## 4. Author Journeys

> **Reading note on this section.** Journeys G–K describe the **intended** author experience end to end. Some steps are shipped and some are design intent for surfaces that do not exist yet. Rather than silently presenting both as fact, here is the split:
>
> | Shipped                                                   | Design intent (not built)                           |
> | --------------------------------------------------------- | --------------------------------------------------- |
> | Sign-in, including 2FA and passkey paths (`pages/auth/*`) | The block editor's live preview pane                |
> | Role-aware post-login redirect                            | Debounced autosave with a save-state indicator      |
> | Dashboard shell, permission-driven sidebar, role homes    | The `beforeunload` guard for unsaved changes        |
> | Post list at `admin.posts.index`                          | Live filtering (All / Draft / Published / Archived) |
> | Post editor at `admin.posts.edit` (create + edit)          | Live slug derivation as the title is typed          |
> | Comment moderation queue at `admin.comments`              | The two-pane 7/12 + 5/12 editor layout              |
> | Taxonomy management at `admin.taxonomy`                   | —                                                   |
>
> The dashboard **routes** for every step below exist and are guarded; what is incomplete in places is the depth of the interaction, not its addressability. Read the journeys as the contract the editor is being built toward.

### 4.1 Journey G — Signing in

```
Entry: /login (layouts/auth/split.blade.php)
  │
  ├─► [Optional] Passkey login offered via <x-app.ui.auth.passkey-verify />
  │
  ├─► Email + password submit → POST /login (Fortify)
  │     ├─ rate limited: 5/min per transliterated email + IP
  │     ├─ 2FA enabled → redirect to /two-factor-challenge (5/min limiter)
  │     ├─ failure → generic error, no field-level disclosure of which was wrong
  │     └─ success → LoginResponse
  │
  └─► Redirect target resolution:
        ├─ session intended URL present → redirect there
        └─ otherwise                    → $user->dashboardRoute()
                                            user   → dashboard.index
                                            seller → seller.dashboard
                                            admin  → admin.dashboard
```

**Two notes that matter here.** The layout is a split shell driven by the application's theme tokens — it does **not** force dark mode, and a test asserts that no `class="dark"` appears in the rendered auth pages. The redirect is resolved from the account's **role**, not from a fixed path: `config/fortify.php` has no `redirects` map, only `home`, and nothing in the codebase hardcodes `/dashboard` as a post-login destination.

**Security posture.** The error message never distinguishes "unknown email" from "wrong password". Timing is not equalised, which is an accepted trade-off documented here rather than silently ignored.

### 4.1a Journey G′ — A seller or admin signing in

Identical to Journey G through authentication. The divergence is entirely in step 5 and in what the shell renders afterwards:

```
seller.dashboard  → Selling section visible in the sidebar
                    (requires product:create)
admin.dashboard   → Writing, Selling AND Administration sections visible
                    (each item requires its own permission; the writing
                    items require admin:access)
```

A `user` account sees none of those sections, and the navigation drops the heading along with its items — so a plain user never meets an "Administration" or "Writing" heading with nothing under it. This is why the sidebar is generated from `DashboardNavigation` rather than written into the Blade template: the menu and the route guards read from the same source and cannot disagree.

The sidebar data has three levels — **section → group → item** — and renders
two. A *group* is a labelled cluster inside a section (`Content`, `Modules`,
`Administration`); the view draws no group heading and no disclosure control, so
every item sits as a direct neighbour under its section's heading. Filtering is
applied per *item*, then a group that emptied out is dropped, then a section that
emptied out is dropped.

**Active state resolves against each item's `pattern`** via
`request()->routeIs()`, so a nested route (`ideas.index` under `ideas.*`) lights
its link without anyone maintaining a second list.

The desktop sidenav width preference is per-account presentation state,
persisted to `localStorage` under `df.sidenav-collapsed.{userId}` — never in the
database, because the server has no opinion about which menu a person left open.
There is no per-group collapse state to keep: with no disclosure there is
nothing to open or close.

### 4.2 Journey H — Authoring a post

```
Entry: /admin/posts → [+ New post]
  │
  ├─► post-editor mounts (create mode)
  │     ├─ left pane:   title · slug (auto, editable) · excerpt (auto, editable)
  │     │               body (markdown textarea, autosizing)
  │     │               category select · tag multiselect · featured toggle
  │     └─ right pane:  live preview (rendered server-side) + outline
  │
  ├─► Author types a title
  │     └─ slug derives live (debounced), unless the author has touched the slug field
  │
  ├─► Author types the body
  │     ├─ preview re-renders, debounced 800 ms
  │     ├─ word count + reading time update
  │     └─ autosave fires, debounced 2 000 ms after typing stops
  │
  ├─► Autosave cycle
  │     ├─ status: "Unsaved changes"  →  "Saving…"  →  "Saved 14:32"
  │     ├─ skipped when content is byte-identical to the last save
  │     └─ suppressed while a manual save is in flight
  │
  ├─► Author navigates away with unsaved changes
  │     └─ beforeunload guard prompts for confirmation
  │
  └─► Author publishes
        ├─ validation runs (title, body, category required)
        ├─ PostPolicy@update authorises
        ├─ UpdatePost action persists all representations
        ├─ confirmation modal: "Publish this post?"
        └─ PublishPost sets published_at, status = published, toast + redirect
```

**Editor layout.**

| Breakpoint | Layout                                                                                                                                                                               |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `< lg`     | Single column: form, then a preview toggle button. Preview replaces the form rather than stacking below it, so the author is never scrolling 4 000 px to see the top of the preview. |
| `≥ lg`     | Two columns: form (7/12) · preview (5/12), both independently scrollable.                                                                                                            |

### 4.3 Journey I — Managing posts

```
Entry: /admin/dashboard → Writing → Posts
  │
  ├─► List: title · status chip · category · views · comments · updated date
  ├─► Filters: All · Draft · Published · Archived  (live, no reload)
  ├─► Search within the author's own posts
  │
  ├─► Row actions:
  │     ├─ [Edit]      → post-editor (edit mode)
  │     ├─ [Publish]   → confirmation modal → PublishPost
  │     ├─ [Unpublish] → confirmation modal → UnpublishPost
  │     ├─ [View]      → public post (available once published)
  │     └─ [Delete]    → destructive modal requiring title confirmation
  │
  └─► Delete flow (destructive confirmation)
        ├─ modal: "This cannot be undone from the UI."
        │          "Type <post title> to confirm."
        ├─ mismatch → inline error, delete blocked
        └─ match    → soft delete, toast with the post title
```

**Why title confirmation instead of a second "Are you sure?".** A repeated yes/no trains the author to click through. Typing an exact title cannot be done reflexively — it is the same pattern the starter kit already uses for team deletion, so it is consistent with the codebase.

### 4.4 Journey J — Moderating comments

```
Entry: /admin/dashboard → Comments (badge shows pending count)
  │
  ├─► Queue: pending first, then approved
  │     each row: commenter · post title · excerpt · submitted date
  │
  ├─► [Approve] → ApproveComment → status = approved
  │                            → toast → row moves to the approved group
  │
  ├─► [Reject]  → confirmation modal → RejectComment → status = rejected
  │
  └─► [View post] → opens the public post in a new context
```

**Bulk behaviour.** Approve and reject are single-row per the PRD (FR-4.11). Bulk actions are deliberately out of scope: at this content volume, a bulk control would be used once and then add a confirmation-dialog maintenance burden.

### 4.5 Journey K — Reviewing content performance

```
Entry: /dashboard (a plain account's home; every other role is forwarded to its own)
  │
  ├─► Metric tiles (computed from persisted data, none of them links):
  │     Published · Drafts · Awaiting moderation · Total views · Comments · Archived
  │
  ├─► Publishing activity: posts per week over the last twelve weeks
  │
  └─► Most read: the top posts by view count, each linking out to its public page
```

Nothing here is actionable. The moderation widget, the recent-posts table and the quick-action buttons were removed when the authoring screens moved to `/admin`: a plain account renders this page and cannot open any of them, so they would have been links to a 403.

### 4.6 Journey L — Capturing an idea and turning it into a plan

Journeys L and M belong to *any* account holding the module permission, which is
every account including the plainest `user`. They sit in the author section because
they are writing journeys; the only difference from Journey H is that the writer owns
the row and nobody has to approve them being able to write it.

```
Entry: /dashboard → sidebar [Ideas] → /ideas/dashboard
  │
  ├─► Workspace renders, in this order
  │     ├─ four counts: Captured · In the inbox · Approved · Pinned
  │     ├─ the capture form, first, because it is the reason the page exists
  │     ├─ Pinned — the reading order the account chose, newest first
  │     └─ a filtered list with a tab row: Open · Inbox · In review · Approved ·
  │        Archived · Everything, plus a search field, ordered pinned-then-newest
  │   Ownership is a scope on every query, `Idea::authoredBy($user)`, and an
  │   unrecognised tab value falls back to Everything rather than to an error.
  │
  ├─► Capture: one inline form at the top of the page — title, optional summary
  │     ├─ [Capture] → CreateIdea writes status=inbox, visibility=private,
  │     │              slug derived from the title
  │     ├─ empty title → validation error on the field, nothing written
  │     └─ the new card appears in Inbox without a page change
  │
  ├─► Open it: /ideas/{slug}/edit (pages::ideas.edit, create and edit in one component)
  │     ├─ markdown body, category, priority, potential
  │     ├─ status picker lists only IdeaStatus::allowedTransitions() from the
  │     │  current stage — not all five cases
  │     └─ slug stays auto-derived until the field is touched
  │
  ├─► Move it forward: Inbox → Draft → Under review
  │     └─ Under review → Approved is the author's own call, and an illegal jump
  │        is refused by the action rather than rendered as a choice
  │
  ├─► Convert: [Convert to plan] — offered only once the idea is Approved
  │     ├─ ConvertIdeaToPlan writes an IdeaPlan, copies the title, puts the
  │     │  summary in problem_statement and the body in notes, links idea_id
  │     ├─ the idea row is otherwise unchanged: conversion never deletes the thought
  │     ├─ the plan slug derives as `{idea-slug}-plan`, collision-suffixed
  │     └─ redirects to /ideas/plans/{slug}; a second conversion of the same idea
  │        is refused, because a plan with two parents is a bug report
  │
  ├─► Plan screen: sections, provenance line back to the idea, task board inline
  │     ├─ add a task → appended at the bottom of the sort order
  │     ├─ tick → completed_at is stamped; untick → it is cleared
  │     ├─ move ← / → → ReorderIdeaTasks swaps with the neighbour, in that plan only
  │     ├─ edit inline → the row becomes a form, nothing navigates away
  │     └─ tab filter Open / Done / All, in the URL, so a filtered board is linkable
  │
  └─► Publish (optional): visibility=public, refused unless status is Approved
        └─ the idea then appears on /ideas and /ideas/{slug} for a stranger
```

The two rules a reader should take from this journey are that **a stage transition is
validated against the enum's own `allowedTransitions()`** and that **conversion is
additive**. The first is why no screen offers "Inbox → Approved"; the second is why
the ideas table keeps its row after a plan exists, with `idea_plans.idea_id` pointing
back at it — `docs/Idea.md` asks for it, and `IdeaConversionTest` pins it.

### 4.7 Journey M — Running code and saving it as a snippet

```
Entry: /dashboard → sidebar [Developer Lab] → /dev-lab/dashboard
  │
  ├─► Dashboard: recent workspaces, own snippets, "n of 100 runs used today",
  │   one primary action [Open sandbox]
  │
  ├─► /dev-lab/sandbox — three panes (HTML, CSS, JS) and a preview
  │     ├─ desktop: editor left, preview + console right
  │     ├─ mobile:  a segmented [Editor | Preview | Console] control, one visible
  │     ├─ each pane is a monospace textarea with a byte counter; the ceiling is
  │     │  config('dev_lab.max_code_bytes') and it is enforced by the save action,
  │     │  so the browser never truncates silently and the server never stores more
  │     │  than a tab can display
  │     └─ typing changes nothing on the right. Nothing runs on keystroke.
  │
  ├─► [Run]
  │     ├─ RecordLabExecution checks the day's count, then writes one lab_executions
  │     │  row carrying the language and a denormalised label
  │     ├─ the parent composes a document from the three panes and assigns it to
  │     │  the iframe's srcdoc
  │     ├─ the frame is sandbox="allow-scripts" and nothing else: no
  │     │  allow-same-origin, so the document gets an opaque origin
  │     │     ├─ cannot read this site's cookies, localStorage, sessionStorage or indexedDB
  │     │     ├─ cannot reach parent.document
  │     │     ├─ cannot post a Livewire mutation with the visitor's session attached
  │     │     └─ alert() / confirm() are blocked (no allow-modals), and the console
  │     │        shim says so out loud rather than leaving a silence that reads as a bug
  │     ├─ the shim inside the frame postMessage()s log/info/warn/error, window.onerror
  │     │  and unhandledrejection to the parent
  │     └─ the parent accepts a message only when event.source is the preview frame's
  │        own contentWindow and the payload is shaped { __lab, level, text }.
  │        event.origin is "null" for an opaque origin and is therefore not compared
  │        against anything — the source identity is the authentication here.
  │        Received text is re-capped and written with textContent, never innerHTML.
  │
  ├─► [Save] — upserts the current LabWorkspace (html/css/js by id, never public)
  │     └─ saving twice updates the same buffer instead of forking a second one
  │
  ├─► [Save as snippet] — one pane's content, titled, given a language
  │     ├─ a blank snippet is refused; a snippet does not need an approval stage,
  │     │  it needs something to read
  │     └─ slug derives from the title, reserved names refused
  │
  ├─► PHP: the pane is read-and-save only. There is no Run button for PHP, and
  │   RecordLabExecution re-checks isBrowserRunnable() server-side, so a
  │   hand-crafted request cannot mint a run entry for a language nothing runs.
  │
  └─► Publish a snippet → it appears on /dev-lab/gallery and /dev-lab/snippets/{slug}
        └─ the code is stored, never executed on the way out: the public page prints
           it escaped inside <pre><code> and offers an opt-in sandbox preview
```

The residual risk is stated in the UI and in `docs/lab.md` rather than papered over: a
`while (true)` in the JS pane hangs that visitor's own tab. There is no CPU or memory
quota, because decision-making authority for this build was that execution stays
client-side with no new dependencies. The mitigations are behavioural: the preview
rebuilds only on an explicit Run, reassigning `srcdoc` destroys the looping document,
and `daily_run_limit` bounds how often the button may be pressed at all.

### 4.8 Journey N — An operator overseeing the modules

```
Entry: /admin/dashboard → admin rail [Ideas] / [Developer Lab]
  │
  ├─► /admin/ideas — every account's ideas, newest first
  │     ├─ author column: whose row this is, always visible
  │     ├─ stage tabs over IdeaStatus, in the URL
  │     ├─ search reaches title, summary and body
  │     ├─ the row underneath an idea is the plan it was converted into, if any
  │     └─ actions: none that write, except [Take down] → IdeaPolicy::delete
  │        There is no edit button, because there is no edit permission to hang one on.
  │
  ├─► /admin/dev-lab — two tabs
  │     ├─ Snippets: every snippet, with language, author, visibility and views,
  │     │  and a remove action that 404s nothing and never edits
  │     └─ Executions: the run log, filterable by language — usage, not content
  │
  └─► Exit: back to the admin dashboard, whose module tiles count real rows
```

Both consoles carry `admin:access` and nothing narrower, and the reason is recorded in
`docs/PERMISSIONS.md`: there is no thirteenth permission to gate them with, and adding
one would invent a distinction the screens do not make. Both render in
`layouts::admin.dashboard` like every other admin screen, so the operator keeps one
rail rather than being bounced between two chrome designs mid-task, and both declare
`noindex` — an oversight page should not be crawlable.

---

## 5. State Transitions

### 5.1 Post status

```
                  ┌──────────────────────────────────────────┐
                  │                                          │
        [create]  ▼                                          │
       ────────► Draft ──[publish]──► Published ──[archive]──► Archived
                    ▲                     │                      │
                    │                     │                      │
                    └──────[unpublish]────┘                      │
                    │                                            │
                    └───────────────[restore]──────────────────────┘
```

| Transition        | Trigger         | Side effects                                                 | Reversible       |
| ----------------- | --------------- | ------------------------------------------------------------ | ---------------- |
| ∅ → Draft         | Create post     | Row inserted; slug assigned                                  | n/a              |
| Draft → Published | `PublishPost`   | `published_at = now()`, public listings now include the post | Yes              |
| Published → Draft | `UnpublishPost` | `published_at = null`, removed from public surfaces          | Yes              |
| Any → Archived    | Archive action  | Hidden from the author's default list, still in the DB       | Yes              |
| Any → Trashed     | Delete          | Soft delete; public URL 404s                                 | Yes, from the DB |


**Invariant.** A post appears on a public surface **if and only if** `status = published` **and** `published_at` is not null **and** `deleted_at` is null. Every public query enforces all three conditions.

### 5.2 Comment status

```
[submit] ──► Pending ──[approve]──► Approved   (publicly visible)
                │
                └──[reject]───► Rejected        (never publicly visible)
```

### 5.3 Idea stage

Transcribed from `IdeaStatus::allowedTransitions()`. A stage may always stay where it
is; `canTransitionTo()` admits the same value before it consults the list.

```
             ┌──────────────► Draft ──┐
             │              ▲    │     │
             │              │    ▼     ▼
  [capture]  │   Under review ◄──── Approved ──[convert]──► (an IdeaPlan exists)
     │       │        │    ▲   ▲        │
     ▼       └────────┘    │   └────────┤
   Inbox ──────────────────┘            │
     ▲                                  ▼
     └────────────────────────── Archived
```

| Transition              | Trigger                        | Side effects                                                        |
| ----------------------- | ------------------------------ | ------------------------------------------------------------------- |
| ∅ → Inbox               | `CreateIdea`                   | Row inserted; slug derived; `visibility = private`                  |
| Inbox → Draft           | status change                  | nothing else                                                        |
| Inbox / Draft → Under review | status change             | nothing else                                                        |
| Draft / Under review → Approved | status change             | the idea becomes publishable and convertible; neither is automatic  |
| Approved → Under review | status change                  | an approved idea can be pulled back for another look                |
| Any open stage → Archived | status change                | leaves the default (Open) filter; still owned, still listable       |
| Archived → Inbox        | status change                  | the only way out of a closed idea, and it reopens at the beginning  |

| Refusal                 | Where                                          | Response to a hand-crafted request        |
| ----------------------- | ---------------------------------------------- | ----------------------------------------- |
| Inbox → Approved        | `SaveIdea`, `IdeaStatus::canTransitionTo()`      | validation error; the row keeps its stage |
| Archived → Approved     | same                                             | validation error                            |
| publishing a non-Approved idea | `SyncIdeaVisibility::assertReadyToPublish` | `InvalidArgumentException`, no write        |
| converting a non-Approved idea | `ConvertIdeaToPlan`                     | `InvalidArgumentException`, no plan row      |
| converting an idea that already has a plan | `ConvertIdeaToPlan` | refused; `idea_plans.idea_id` is `UNIQUE`   |

### 5.4 Plan stage and task state

`PlanStatus::allowedTransitions()` is a delivery line rather than a review line, and
the difference shows in the two dead ends: `Completed` leads nowhere, and
`Cancelled` leads back to `Draft` and nothing else.

```
Draft ──► Planning ──► Ready ──► In progress ──► Completed   (terminal)
  ▲          │           │   ▲        │
  │          ▼           ▼   └────────┤
  └────── Cancelled ◄────┴─── On hold ─┘
```

Tasks have four states (`Todo`, `In progress`, `Blocked`, `Done`) and no declared
workflow: a task is a line item, and a task that could not be moved from `Blocked` to
`Done` without a ceremony would be a second workflow nobody asked for. What *is*
encoded is the timestamp — `completed_at` is stamped when a task becomes `Done` and
cleared when it stops being `Done`, by the action rather than the screen, which is why
"ticking and un-ticking" round-trips cleanly.

### 5.5 Publication (ideas and snippets)

Neither module reuses `PostStatus`. Publication is a conjunction of two columns
written by exactly one action, and the read side is exactly one method.

```
private ──[publish]──► public + published_at set ──► visible to a stranger
   ▲                          │
   └────[unpublish]───────────┘   (published_at is NOT cleared)
```

| Fact                       | Detail                                                                                       |
| -------------------------- | -------------------------------------------------------------------------------------------- |
| The predicate              | `visibility === Public && published_at !== null`, expressed once as `isVisibleTo()`           |
| The writers                | `SyncIdeaVisibility` (ideas), `SaveLabSnippet` (snippets). Nothing else sets either column.    |
| Flag without a timestamp    | Not published. `isVisibleTo()` returns false for a stranger, and a test pins it.              |
| Timestamp without the flag  | Cannot be manufactured: `published_at` is only written when the target visibility is public.  |
| Unpublishing               | Clears the flag and keeps the stamp, so republishing does not re-date the work.               |
| Empty content              | A snippet with whitespace-only code is refused publication; an idea is refused unless Approved. |
| A null user                | `isVisibleTo(null)` is the guest case: only the published branch can return true.             |
| `admin:access`              | Sees an unpublished row *and* is treated as visible, which is what lets the console link to it. |

The two refusals differ on purpose. An idea is a thought and needs a stage before it
goes public; a snippet needs something to read, and that is the whole difference
between the two modules.

### 5.6 Authentication state

```
Guest ──[register]──► Unverified ──[verify email]──► Verified
  │                                                     │
  └──[login]──► 2FA challenge ──[confirm]──────────────►│
  │                                                     │
  └──[passkey]─────────────────────────────────────────►│
                                                        │
Verified ──[logout]──► Guest ◄──[session expiry]─────────┘
```

---

## 6. Edge Cases and Failure Modes

| Scenario                                          | Expected behaviour                                                                                                     | Rationale                                                                                                   |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Draft URL requested by a guest                    | `404`                                                                                                                  | A `403` confirms the resource exists; drafts are private, so they should be indistinguishable from nothing. |
| Post unpublished while a reader has it open       | Reader's tab still shows the cached HTML; a refresh yields `404`                                                       | No websocket channel exists to push the change; forcing a refresh would be worse.                           |
| Search term with regex metacharacters             | Treated as a literal; `%` and `_` escaped before reaching `LIKE`                                                       | Prevents wildcard injection and full-table scans.                                                           |
| Simultaneous autosave and manual save             | The later-issued write wins; the earlier is discarded by timestamp check                                               | Prevents a stale autosave clobbering a deliberate save.                                                     |
| Slug collision on publish                         | Suffix appended (`my-post-2`)                                                                                          | Never blocks publishing on a cosmetic conflict.                                                             |
| Comment submitted while logged out in another tab | Session is gone; submission redirects to login                                                                         | Standard session behaviour; no special handling.                                                            |
| JS disabled                                       | All public pages render and navigate; share intents work as links; filtering falls back to a full-page form submission | Progressive enhancement is a hard requirement for the read path.                                            |
| Clipboard API blocked by permissions policy       | Fallback selects the URL and shows "Press Ctrl+C to copy"                                                              | Silent failure would look like a broken button.                                                             |
| Web Share API cancelled by the user               | Nothing happens; no error state                                                                                        | Cancellation is a normal user action, not a failure.                                                        |
| Very long post body (10 000+ words)               | Editor and preview remain usable; TOC paginates visually with max-height and scroll                                    | The TOC must never push the article off-screen.                                                             |
| Empty blog (no published posts)                   | Index shows a friendly empty state, not a broken grid                                                                  | First-run experience matters.                                                                               |
| Private idea requested by URL by a stranger       | `404`                                                                                                                  | Same rule as a draft: the row's existence is not public information, so 403 would leak it.                  |
| `/ideas/plans` when an idea is slugged `plans`    | The plans screen renders; the idea does not shadow it                                                                  | Workspace routes are registered before the public detail route, and `IdeaSlug` refuses the name anyway. Two defences, both needed. |
| An idea reaches `Approved` and is then archived   | It loses its public visibility only when the author unpublishes it; archiving is a stage, not a withdrawal             | Stage and visibility are two columns and the module keeps them separate on purpose — *approving is not the same as publishing*. |
| A snippet published with whitespace-only code     | Refused by `SaveLabSnippet`, and the screen reports why                                                                | A public page with an empty block on it is worse than no page.                                              |
| A run requested for a `php` snippet               | Refused server-side before any row is written; the pane is read-and-save with no button in the first place             | The UI hiding a control is not a security boundary.                                                         |
| `while (true)` in the JS pane                     | That visitor's tab hangs until [Run] or a reload replaces the frame                                                     | No CPU or memory quota exists in a client-side sandbox, and the build says so rather than implying otherwise. |
| `alert()` inside the preview                      | Nothing happens; the console panel prints that modals are blocked                                                     | A silence that looks like a bug is worse than an honest message.                                            |
| Code past `max_code_bytes`                        | Save refused with the size and the limit in the message; never truncated quietly                                       | The column would store more than a tab can display, and the failure would arrive as a frozen page.          |
| Two accounts both create a category called "backend" | Both rows exist                                                                     | Category slug uniqueness is per account, which is the difference between a folder and a URL.                |
| The day's run allowance is spent                  | `[Run]` refuses with a danger toast and records nothing                                                                 | The limit is enforced where the write happens, not where the button is drawn.                                |

### 6.1 Authorisation failure modes

Added with the role model. These are the cases where the answer is not obvious and a wrong choice leaks information or strands a user.

| Scenario                                                             | Expected behaviour                                       | Rationale                                                                                                                                                                   |
| -------------------------------------------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Guest requests a dashboard route                                     | Redirect to `/login`, **not** `403`                      | They may hold the permission once authenticated. A `403` is a dead end with no way forward.                                                                                 |
| Authenticated `user` requests `admin.dashboard`                   | `403` before the component boots                         | The route middleware (`permission:admin:access`) refuses it. A rendered admin shell with hidden buttons would still leak the layout and any data the component loads.       |
| `seller` requests a route needing `role:manage`                    | `403`                                                    | `seller` holds fifteen abilities — the six inherited from `user`, plus `post:publish` and `taxonomy:manage`, plus all seven of the selling group — and not one of them is in the administration group. Enumerated in [PERMISSIONS.md](./PERMISSIONS.md).                                               |
| Admin attempts to delete another admin                               | Refused (`UserPolicy@delete`)                            | `$account->accountRole()->isAtLeast($user->accountRole())` refuses any target at or above the actor's level. Without it, two admins could delete each other.                |
| Admin attempts to delete their own account                           | Refused through the admin path                           | Self-deletion is a separate, confirmed flow. Allowing it through the user-management table would make it a one-click irreversible act.                                      |
| An account whose role was just downgraded has an open dashboard tab  | The next Livewire request re-checks permission and fails | Livewire re-authorises on every round-trip rather than trusting the snapshot. A demoted admin does not keep admin rights for the session.                                   |
| Route declares a misspelled permission (`permission:prodcut:create`) | `500` with "Route declares no valid permission"          | Being permissive on an unresolvable alias would turn a typo into "everyone is allowed". Failing loudly at the route is the only safe direction.                             |
| An account holds a permission whose module is not built              | Nothing — the permission is inert                        | Five of the thirty abilities have no surface at all (`sale:view-own`, `sale:view-any`, `refund:process`, `payment:configure`, `support:manage-own`), and `ai-hub:use` and `marketplace:use` gate only a nav entry. `ideas:use` and `dev-lab:use` no longer belong on this list: both now guard a route group (`routes/web.php:122` and `:164`) and re-check inside the component `boot()` on every Livewire round-trip. Enumerated in [PERMISSIONS.md](./PERMISSIONS.md); see [TRD §7.2](./TRD.md#72-deferred-by-decision-not-by-omission). |
| An account that lost `ideas:use` mid-session clicks delete in an open tab | `403` on that Livewire request                         | The route middleware never sees the request — a mutation posts to `/livewire/update` — so the component's `boot()` re-checks the permission on every round-trip. |
| A stranger posts a foreign idea id to the Livewire endpoint          | `404`, and the row is untouched                        | The workspace resolves its model through an `authoredBy()`-scoped lookup, so the scoping rule is the authorisation rule and it refuses before any policy is consulted. |
| A `seller` has `product:create` and no listings                      | Seller dashboard renders an empty state, not an error    | The role is a capability, not a guarantee of data.                                                                                                                          |
