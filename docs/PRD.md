# Product Requirements Document — DevFolio

**A portfolio and technical knowledge platform for a senior software engineer.**

| Field | Value |
| --- | --- |
| Product name | DevFolio |
| Document version | 1.0 |
| Status | Approved for build |
| Owner | Product / Engineering Lead |
| Last updated | 2026-10-12 |
| Related documents | [TRD](./TRD.md) · [Application Flow](./APP-FLOW.md) · [UI/UX Brief](./UI-UX-BRIEF.md) · [Schema](./SCHEMA.md) · [ERD](./ERD.md) |

---

## 1. Executive Summary

DevFolio is a self-hosted portfolio and technical publishing platform for a senior developer and engineer. It combines a hiring-manager-facing portfolio surface with a long-form technical blog, and gives the author an authenticated writing workspace to draft, publish, and moderate content without touching a CMS admin panel built by somebody else.

The product exists because senior engineers are routinely evaluated on two things that generic portfolio templates handle badly: **depth of demonstrated engineering judgment** and **evidence of clear technical communication**. DevFolio treats the blog as a first-class portfolio artifact rather than an afterthought, and treats the portfolio itself as a piece of engineering craft — the site is the proof of work.

### 1.1 Why build instead of using an existing platform

| Alternative | Why it is insufficient |
| --- | --- |
| Readymade portfolio themes (Webflow, Framer) | No ownership of the publishing pipeline; readers learn nothing about backend skill; no relational content model or moderation capability. |
| Medium / Dev.to / Hashnode | Audience belongs to the platform; no canonical URL ownership; no control over information architecture or presentation. |
| Generic Laravel CMS (Filament panel + Wordpress-like UI) | The admin surface is a generic CRUD table, not a writing experience. Editing prose is the primary job and deserves a purpose-built editor. |
| Static site generator (Hugo, Astro) | Excellent for reading, but cannot host the authenticated authoring workflow, comments, or post-authentication state that this product needs without bolting on a separate service. |

DevFolio therefore optimizes for **one author writing well**, not for thousands of tenants: a single-person publishing workflow, not a multi-tenant CMS.

**Revised (M6).** "One author" describes the *publishing workflow*, not the authorisation model. The system now distinguishes three account roles — `user`, `seller`, `admin` — because the deferred product modules (marketplace, Idea Bank, Developer Lab) need them, and because a reader who may comment is not the same principal as the author who may publish. The original intent still holds where it matters: there is no tenancy, no per-organisation data isolation, and no multi-tenant concern anywhere in the schema. See [TRD §2.5](./TRD.md#25-authorisation-model) and [PERMISSIONS.md](./PERMISSIONS.md).

---

## 2. Goals & Non-Goals

### 2.1 Goals

| # | Goal | Success signal |
| --- | --- | --- |
| G1 | Present professional credibility within 10 seconds of landing | Hero states role, specialisms, and years of experience above the fold; primary CTA reaches the résumé/contact path in one click. |
| G2 | Convert a technical blog into a portfolio asset | Every post shows a category, tags, reading time, and published date; posts are reachable from home, index, category, tag, and related-post surfaces. |
| G3 | Make writing fast for the author | Author can move from "new post" to published with autosaved draft, live markdown preview, and no page reloads during the editing session. |
| G4 | Make sharing frictionless for readers | Every post exposes six share destinations plus native OS share and copy-link, with correct Open Graph and Twitter Card metadata so link unfurls are accurate. |
| G5 | Demonstrate engineering quality | The codebase is typed, statically analysed, Pest-tested, and documented to a standard a reviewer can audit in an afternoon. |
| G6 | Keep the surface accessible and fast | WCAG 2.2 AA colour contrast, full keyboard operability, no layout shift from late-loading content, and Lighthouse performance ≥ 95 on mobile. |

### 2.2 Non-Goals (explicit scope exclusions)

- **Multi-author publishing.** One primary author. The schema keeps a `user_id` foreign key on posts so multi-author is a future extension, and no co-authoring UI ships in v1.

  > **Revised.** This exclusion originally read "no roles, permissions, or co-authoring UI ships in v1". The **roles and permissions model has since shipped** and is now a core part of the product: three account roles (`user`, `seller`, `admin`) with 24 derived permissions and role-scoped dashboards. Co-authoring remains out of scope. See [`PERMISSIONS.md`](PERMISSIONS.md).
  >
  > The distinction that matters: the role model governs *what an account may do on the platform*, not *who may collaborate on a given post*. A post still has one author.
- **Newsletter delivery.** Email capture is limited to the contact form; no campaign tooling, no double opt-in list management.
- **Full-text search engine.** Search is a scoped `LIKE`/indexed-column query over title, excerpt, and body. No Meilisearch, Algolia, or Typesense in v1.
- **Rich text / WYSIWYG editing.** Markdown only. A WYSIWYG round-trip destroys the fidelity that technical writers need for code fences.
- **Real-time collaborative editing.** No websockets, no presence, no CRDT.
- **Payments, subscriptions, or gated content.** All published content is public.
- **Internationalisation.** English (en) only in v1. All user-facing strings use the `__()` helper so localisation is a translation-file change, not a refactor.
- **Analytics dashboards.** Author dashboard shows content metrics (drafts, published, views, comments). No third-party analytics integration.

---

## 3. Target Audience

### 3.1 Primary audience — readers evaluating the author

| Persona | Context | What they need | How DevFolio serves it |
| --- | --- | --- | --- |
| **The Hiring Manager** ("Priya") | Screens 40 candidates in a week. Lands on the site from a LinkedIn link. Has 90 seconds. | Immediate signal of seniority, domain fit, and communication ability. | Hero block with role, specialisms, and experience; featured work cards; three pinned posts; one-click résumé download. |
| **The Staff Engineer / interviewer** ("Marcus") | Will conduct a deep technical screen. Wants to see how the candidate reasons, not just what they've shipped. | Evidence of judgement: trade-off analysis, architecture rationale, failure post-mortems. | Posts tagged `Architecture`, `Deep Dive`, `Post-Mortem`; code-complete markdown with diagrams; related-post graph so a single interesting read leads to three more. |
| **The Peer Developer** ("Anika") | Found a post through a search engine while debugging. | A fast page, readable code, no cookie wall, and a way to bookmark or share it into her team's Slack. | Copy-link and native share; semantic HTML with code blocks that wrap; TOC for long posts. |

### 3.2 Secondary audience — the author themselves

| Persona | Context | What they need | How DevFolio serves it |
| --- | --- | --- | --- |
| **The Author** ("the user") | Writes in bursts, often mid-week after solving something interesting. | Zero-friction capture, reliable drafts, and a pleasant editing surface. | Authenticated dashboard; markdown editor with live preview and autosave; draft/publish workflow; comment moderation queue. |

### 3.3 Audience assumptions

- Readers are technically literate; they do not need concepts explained at an introductory level.
- A meaningful share of readers arrive on a **mobile device from a social or search referrer**, so mobile-first layout is the design baseline, not an adaptation.
- Readers may have `prefers-reduced-motion` enabled; animation must never be load-bearing for comprehension.
- The site owner is the only privileged user; there is no privilege-escalation surface to defend beyond "is this the owner".

---

## 4. Feature Requirements

Requirements use MoSCoW priority. Each carries an ID for traceability into the [TRD](./TRD.md) and test suite.

### 4.1 Landing / Home page

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-1.1 | Must | Hero section stating name, role, specialisms, and years of experience. |
| FR-1.2 | Must | Primary CTA to the blog index; secondary CTA to contact/résumé. |
| FR-1.3 | Must | Expertise grid covering 4–6 competencies with supporting copy. |
| FR-1.4 | Must | "Selected work" section rendering up to 3 featured projects with tech-stack chips. |
| FR-1.5 | Must | Featured posts strip rendering up to 3 featured posts with category and reading time. |
| FR-1.6 | Should | Capability stats band (years, projects shipped, posts published) computed from real data, not hardcoded. |
| FR-1.7 | Should | Latest posts list (6 most recent) with link to the full index. |
| FR-1.8 | Could | Newsletter/contact capture block with server-side validation. |

### 4.2 Blog listing

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-2.1 | Must | Paginated list of published posts, newest first, 9 per page. |
| FR-2.2 | Must | Each row shows title, excerpt, category, tags, published date, reading time, and view count. |
| FR-2.3 | Must | Live search across title, excerpt, and body — results update without page reload. |
| FR-2.4 | Must | Category filter and tag filter, combinable with search and with each other. |
| FR-2.5 | Must | Visible active-filter state with a one-click "clear filters" affordance. |
| FR-2.6 | Must | Empty state when a filter combination yields nothing, with a reset action. |
| FR-2.7 | Should | Result count and query-time feedback while filtering. |
| FR-2.8 | Should | Featured post promoted above the grid when no filter is active. |
| FR-2.9 | Could | RSS/Atom feed of published posts. |

### 4.3 Blog detail

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-3.1 | Must | Full markdown body rendered as safe HTML with syntax-highlighted code blocks. |
| FR-3.2 | Must | Post header: title, category, tags, author, published date, reading time. |
| FR-3.3 | Must | Table of contents generated from `h2`/`h3` headings, with active-section highlighting. |
| FR-3.4 | Must | Social share controls (see FR-5.x). |
| FR-3.5 | Must | Related posts (up to 3) selected by shared tags then shared category. |
| FR-3.6 | Must | Author card with bio and link to the about page. |
| FR-3.7 | Should | Reading progress indicator. |
| FR-3.8 | Should | Threaded comment section with moderation gate. |
| FR-3.9 | Should | Draft preview mode for the authenticated author, with a visible "unpublished" banner. |
| FR-3.10 | Could | Previous/next post navigation. |

### 4.4 Authentication & authoring

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-4.1 | Must | Registration, login, logout, password reset, and email verification provided by the existing Fortify + Livewire stack. |
| FR-4.1a | Must | **Every authentication flow lands the account on its role's dashboard**, resolved from the account role rather than a fixed path. |
| FR-4.1b | Must | **One shared authentication layout** across all seven Fortify screens, respecting the visitor's light/dark preference and exposing a skip link to the form. |
| FR-4.2 | Must | Authenticated dashboard showing content metrics and recent activity. |
| FR-4.2a | Must | **Three account roles — `user`, `seller`, `admin` — with permissions derived from the role, and a sidebar that shows only what the account's routes will accept.** |
| FR-4.2b | Must | **A seller dashboard** stating the plan's entitlements (listing limit, commission) even where the corresponding feature is not yet built. |
| FR-4.2c | Must | **An admin dashboard** with platform metrics and an account list supporting role changes, refusing any change at or above the actor's own level. |
| FR-4.3 | Must | Post list scoped to the author with status filters (draft, scheduled, published, archived). |
| FR-4.4 | Must | Create and edit post with title, slug, excerpt, body, category, tags, status, and featured flag. |
| FR-4.4a | Must | **Publishing requires at least a seller role.** A plain reader may draft and edit but cannot make a post public. |
| FR-4.5 | Must | Live markdown preview while editing. |
| FR-4.6 | Must | Autosaved drafts, with visible "saved / saving / unsaved" status. |
| FR-4.7 | Must | Publish and unpublish transitions with confirmation. |
| FR-4.8 | Must | Delete requires typing the post title to confirm. |
| FR-4.9 | Should | Reading-time and slug auto-derived from content, with manual override. |
| FR-4.10 | Should | Category and tag management inline from the editor (create-on-the-fly). |
| FR-4.11 | Should | Comment moderation queue with approve/reject. |
| FR-4.11a | Should | **Moderation is restricted to the admin role**; an author sees pending comments on their own posts without being able to approve or reject. |
| FR-4.12 | Could | Post view counter incremented on public read. |

### 4.5 Social sharing

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-5.1 | Must | Share intents for X/Twitter, LinkedIn, Facebook, Reddit, Hacker News, and email. |
| FR-5.2 | Must | Copy-link control with success feedback. |
| FR-5.3 | Must | Native OS share sheet via the Web Share API where supported, with graceful degradation. |
| FR-5.4 | Must | Open Graph and Twitter Card meta tags on every public page. |
| FR-5.5 | Should | Share URLs generated server-side so metadata and UTM parameters are consistent. |
| FR-5.6 | Could | Per-post share count surfaced in the author dashboard. |

### 4.6 Cross-cutting

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-6.1 | Must | Fully responsive from 360 px to 2560 px. |
| FR-6.2 | Must | Light and dark themes, defaulting to the OS preference and persisting the user's choice. |
| FR-6.3 | Must | Keyboard-operable throughout; visible focus rings; correct landmark structure and heading order. |
| FR-6.4 | Must | `prefers-reduced-motion` respected; all transitions suppressible. |
| FR-6.5 | Must | SEO fundamentals: canonical URLs, sitemap, semantic HTML, meaningful titles and descriptions. |
| FR-6.6 | Should | Open Graph images per post. |

---

## 5. User Stories

Stories are written in the standard form and carry acceptance criteria that map to test cases. Priority uses MoSCoW.

### 5.1 Reader stories

**US-01 — Evaluate the author quickly** *(Must)*
> As a **hiring manager**, I want to understand who this person is and what they specialise in within seconds, so that I can decide whether to keep reading.

- Landing page renders name, role, specialism list, and years of experience without scrolling on a 1280×800 viewport.
- Two CTAs are visible above the fold: "Read the blog" and "Get in touch".
- The page reaches its largest contentful paint under 2.5 s on a throttled 4G profile.

**US-02 — Browse technical writing by topic** *(Must)*
> As a **peer developer**, I want to filter posts by category and tag, so that I can find writing relevant to the problem I am currently solving.

- Selecting a category narrows the list to that category only.
- Tag filters combine with the category and search term (logical AND).
- Each active filter is visibly indicated and individually removable.
- The URL reflects the filter state so the view is shareable and reload-safe.

**US-03 — Find a post by keyword** *(Must)*
> As a **returning reader**, I want to search posts by keyword, so that I can re-find something I half-remember.

- Typing in the search field updates results without a full page reload.
- Matching is case-insensitive across title, excerpt, and body.
- A search with no matches shows an empty state with a "clear search" action rather than an empty page.

**US-04 — Read a long technical post comfortably** *(Must)*
> As a **staff engineer**, I want to navigate a long post and read its code samples clearly, so that I can absorb the argument without fighting the page.

- Headings generate a table of contents that highlights the current section on scroll.
- Code blocks are syntax-highlighted, horizontally scrollable on narrow viewports, and never overflow their container.
- Reading progress is visible; estimated reading time is shown before the body.
- Line length stays within a comfortable measure (65–75 characters) on wide screens.

**US-05 — Share a post into my team's channel** *(Must)*
> As a **reader**, I want to share a post to the platform my team uses, so that others benefit from it.

- Share controls are reachable at both the top and bottom of the post.
- The copy-link control confirms success and falls back to a manual selection prompt when the Clipboard API is unavailable.
- Native share sheet is used on supporting mobile devices instead of a custom menu.
- Pasted links unfurl with the correct title, description, and image in Slack, X, LinkedIn, and iMessage.

**US-06 — Leave a comment** *(Should)*
> As a **reader with a question**, I want to ask it directly under the post, so that the author and other readers can respond.

- Only authenticated users may comment; unauthenticated readers see a prompt linking to login with a return URL back to the post.
- Comments require a body of 3–2000 characters and are validated server-side.
- Submitted comments are held for moderation and clearly communicate that state to the author.
- Approved comments render with author name, relative timestamp, and body.

### 5.2 Author stories

> **Scope note.** US-07 through US-12 were written when "the author" was the only privileged principal. The system now distinguishes three account roles, and the stories below have been restated in role terms so the acceptance criteria name the role that satisfies them. US-13 through US-18 are new and cover the role, permission, and dashboard behaviour that did not previously have a requirement. The full matrix is in [PERMISSIONS.md](./PERMISSIONS.md).

**US-07 — Sign in securely** *(Must)*
> As an **account holder of any role**, I want to authenticate with email and password (plus two-factor where enabled), so that only I can reach my own data.

- Unauthenticated access to any `/dashboard` route redirects to login with a return URL. The redirect is a **redirect, not a 403** — the account may hold the permission once authenticated.
- Successful authentication redirects to the intended destination, or to the dashboard **owned by the account's role**: `dashboard.index` (user), `dashboard.seller.index` (seller), `dashboard.admin.index` (admin).
- Two-factor challenge and passkey flows continue to work unmodified.
- Five failed attempts within a minute trigger rate limiting.
- The sign-in screen renders in the application's active theme. It must not force dark mode.

**US-08 — Write a post without friction** *(Must)*
> As the **author**, I want to draft in markdown with a live preview and autosave, so that I can focus on the writing.

- The editor and preview update together with no page navigation.
- Drafts autosave at most a few seconds after the author stops typing, and the save state is always visible.
- Navigating away with unsaved changes warns the author.
- The post body is never lost on a validation error elsewhere in the form.

**US-09 — Publish and unpublish** *(Must)*
> As the **author**, I want explicit control over what is public, so that half-finished thinking never goes live.

- New posts default to `draft`.
- Publishing sets `published_at` and makes the post appear on all public surfaces.
- Unpublishing returns the post to draft and removes it from public listings within one request.
- The post list distinguishes status visually.

**US-10 — Moderate discussion** *(Should)*
> As the **author**, I want to review comments before they appear, so that the site stays free of spam.

- Pending comments appear in a moderation queue with the post they belong to.
- Approve publishes the comment immediately; reject keeps it hidden permanently.
- The queue shows a count badge in the dashboard navigation.

**US-11 — Manage taxonomy** *(Should)*
> As the **author**, I want to create categories and tags while writing, so that I never have to leave the editor to file a post.

- Categories and tags can be created inline from the post editor.
- Names are slugified automatically and de-duplicated case-insensitively.
- Deleting a category does not delete its posts; they become uncategorised.

**US-12 — See how content is performing** *(Could)*
> As the **author**, I want a summary of views and comments per post, so that I can tell which topics resonate.

- Dashboard shows totals for posts, published posts, drafts, views, comments, and pending moderation.
- Post list shows per-post view and comment counts.
- Metrics are computed from persisted data, not from a third-party service.

### 5.3 Role and permission stories

Added with M5. These did not previously have a requirement, which is how the system shipped for several milestones with an authorisation model that existed only in policies.

**US-13 — Be recognised as a reader, not an author** *(Must)*
> As a **registered reader**, I want a dashboard I am allowed to be on, so that signing in does not drop me on a page that refuses me.

- A `user`-role account lands on `dashboard.index`, which is reachable with `post:create`.
- The sidebar shows Content (posts, comments) and hides Selling and Administration entirely, **including their headings**.
- No navigation entry is visible unless the account holds the permission its route requires.

**US-14 — Own my writing without owning the platform** *(Must)*
> As a **user-role account**, I want to edit and delete my own posts and nobody else's, so that the boundary is unambiguous.

- `PostPolicy@update` and `@delete` allow the author, and any account holding `post:update-any` / `post:delete-any`.
- Ownership resolves through `User::canActOn($owner)` — one rule, so the "admins can do anything" clause cannot be remembered in one policy and forgotten in another.
- The post list shows only the account's own posts unless an `-any` permission is held.

**US-15 — Operate as a seller** *(Must)*
> As a **seller**, I want everything a reader has plus the ability to publish without review and to sell, so that I can run a commercial presence.

- `seller` inherits the full `user` permission set by spreading it, not by restating it — a new baseline permission reaches both higher roles automatically.
- `seller` additionally holds `post:publish`, `taxonomy:manage`, and the six selling permissions.
- The Selling section appears in the sidebar; the seller home states the plan's entitlements (`25 listings`, `15 % commission`).
- A `user`-role account attempting a seller route is refused with `403` before the component boots.

**US-16 — Administer without becoming all-powerful by accident** *(Must)*
> As an **admin**, I want full platform reach, but I want the guard-rails that stop me destroying the platform in one click.

- `admin` holds `AccountPermission::cases()` — every permission, including `admin:access`, `user:manage`, and `role:manage`.
- An admin **cannot delete themselves** through the user-management table; self-deletion is a separate confirmed flow.
- An admin **cannot delete another admin** — `UserPolicy@delete` refuses any target at or above the actor's own level.
- Role assignment is bounded: `AccountRole::assignableBy($actor)` never returns `Admin`, so no UI path exists to mint a peer or a superior. This is a deliberate constraint, and its consequence is recorded as a release blocker in §7.1.

**US-17 — Understand what my role can do** *(Should)*
> As an **account holder**, I want to see what my plan includes, so that I understand what I can and cannot do before I hit a wall.

- The dashboard shows the account's role as a badge and its plan as a named set of features.
- Plan limits are read **through** the account (`$user->plan()->productListingLimit()`), never inlined as a conditional at a call site — so a limit shown in the UI and a limit enforced in a policy cannot disagree.
- A limit of `null` is rendered as "unlimited", not as `0` or blank.

**US-18 — Have the dashboard shell adapt to my role** *(Must)*
> As an **account holder of any role**, I want the dashboard chrome to match what I can do, so that I am not shown doors I cannot open.

- One shell (`layouts/dashboard.blade.php`) serves all three roles; each page opts in with `#[Layout('layouts::dashboard')]`.
- `DashboardNavigation::for($user)` filters items by permission and drops any section left empty, heading included.
- The pending-comment badge is scoped to the viewer: comments on their own posts, or all comments if they hold `comment:moderate`. It means "work waiting for *you*".
- The shell never branches on role directly; it branches on permission. Adding a fourth role is a change to `AccountRole` and its navigation items, and nothing in the shell.

---

## 6. Success Metrics

| Metric | Target | Measurement |
| --- | --- | --- |
| Lighthouse Performance (mobile) | ≥ 95 | Lighthouse CI on the deployed build |
| Lighthouse Accessibility | 100 | Lighthouse CI |
| Largest Contentful Paint (4G) | < 2.5 s | Web Vitals field data or Lighthouse throttled run |
| Cumulative Layout Shift | < 0.1 | Lighthouse |
| Time from "new post" to first save | < 5 s of author input | Manual usability check |
| Test suite pass rate | 100 % | `php artisan test` |
| Static analysis | 0 errors | `composer types:check` |
| Style compliance | 0 diffs | `composer lint:check` |

---

## 7. Release Plan

| Phase | Scope | Exit criteria |
| --- | --- | --- |
| **M1 — Foundation** | Data model, design tokens, public layout, migrations, seeders | Migrations run clean; seeder produces a browsable site. |
| **M2 — Public surface** | Home, blog index, blog detail, about | All reader stories US-01 → US-06 accepted. |
| **M3 — Authoring** | Dashboard, post CRUD, editor, taxonomy, moderation | All author stories US-07 → US-12 accepted. |
| **M4 — Polish** | SEO metadata, sharing metadata, a11y pass, performance pass | Success metrics in §6 met. |
| **M5 — Roles & access** | Account roles, permission matrix, role-scoped dashboards, shared auth layout, modal system | FR-4.1a → FR-4.2c accepted; 183 tests passing. |

### 7.1 Post-M5 scope, not committed

| Item | State |
| --- | --- |
| **Product listings** | The `seller` role, its six selling permissions and its plan limits (25 listings, 15 % commission) all ship and are enforced. There is **no `Product` model**, so the seller dashboard states entitlements against an empty feature. |
| **Public API** | Specified in [`API.md`](API.md) and **deliberately unimplemented**. No `routes/api.php`, no Sanctum. |
| **Admin bootstrap** | `AccountRole::assignableBy()` refuses to grant `admin` to anyone, so the role is reachable only via seeder or direct database write. Needs a deliberate answer before operation by anyone but the author. |

---

## 8. Risks & Mitigations

| # | Risk | Impact | Likelihood | Mitigation |
| --- | --- | --- | --- | --- |
| R1 | Markdown rendering introduces XSS through author- or user-supplied HTML | High | Medium | Render through an allow-list sanitiser; never render raw user HTML. Escape on output. Cover with a test asserting `<script>` is stripped. |
| R2 | Live preview becomes a performance bottleneck on long posts | Medium | Medium | Debounce preview regeneration; render server-side through Livewire rather than duplicating a JS markdown parser on the client. |
| R3 | Autosave causes write amplification and lost edits on race | Medium | Medium | Debounce writes, use a single authoritative timestamp check, and disable autosave while a manual save is in flight. |
| R4 | Share metadata drifts from the rendered page | Low | Medium | Generate share URLs and meta tags from one server-side source of truth per request. |
| R5 | SEO collisions between slugs and reserved routes | Medium | Low | Namespace all public post URLs and constrain the slug route parameter. |
| R6 | Design drifts into a generic template look | Medium | Medium | Design tokens defined once in CSS; a documented visual language in the UI/UX brief; component library enforces consistency. |
| R7 | Scope creep into a full CMS | Medium | High | Non-goals in §2.2 are binding. Anything outside them is a v2 candidate, not a v1 requirement. |
