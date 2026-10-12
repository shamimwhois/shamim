# Application Flow — DevFolio

User journeys and key interactions for every actor in the system.

---

## 1. Actors and Surfaces

| Actor | Surfaces | Authentication |
| --- | --- | --- |
| **Guest reader** | Home, blog index, blog detail, about, auth pages | None |
| **Authenticated reader** | All guest surfaces + comment submission | Registered user |
| **Author** | All reader surfaces + dashboard, post editor, taxonomy, moderation | Post owner |

---

## 2. Route Map

| Route | Name | Middleware | Component / View |
| --- | --- | --- | --- |
| `GET /` | `home` | — | `⚡home` |
| `GET /blog` | `blog.index` | — | `pages/blog/⚡index` |
| `GET /blog/{post:slug}` | `blog.show` | — | `pages/blog/⚡show` |
| `GET /about` | `about` | — | `pages/blog/⚡about` |
| `GET /feed.xml` | `blog.feed` | — | `RssController` |
| `GET /sitemap.xml` | `sitemap` | — | `SitemapController` |
| Auth routes | `login`, `register`, `logout`, `password.*`, `verification.*`, `two-factor.*` | — | Fortify + `pages/auth/*` |
| `GET /settings/*` | `profile.edit`, `security.edit`, `appearance.edit`, `teams.*` | `auth`, `verified` | existing |
| `GET /dashboard` | `dashboard` | `auth`, `verified` | `⚡dashboard` |
| `GET /dashboard/posts` | `dashboard.posts.index` | `auth`, `verified` | `pages/dashboard/⚡posts-index` |
| `GET /dashboard/posts/create` | `dashboard.posts.create` | `auth`, `verified` | `pages/dashboard/⚡post-editor` |
| `GET /dashboard/posts/{post}/edit` | `dashboard.posts.edit` | `auth`, `verified` | `pages/dashboard/⚡post-editor` |
| `GET /dashboard/comments` | `dashboard.comments.index` | `auth`, `verified` | `pages/dashboard/⚡comments-index` |
| `GET /dashboard/taxonomy` | `dashboard.taxonomy.index` | `auth`, `verified` | `pages/dashboard/⚡taxonomy-index` |

> **Note on the existing `{current_team}` prefix.** The starter kit scopes `dashboard` under a team slug. DevFolio is a single-author product and does not use teams for content scoping. The dashboard routes are therefore registered **outside** the `{current_team}` prefix group, while the existing team routes remain untouched so nothing in the starter kit breaks.

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

| Trigger | Response |
| --- | --- |
| Page load | CSS-driven entrance animation, suppressed under `prefers-reduced-motion`. |
| Hover a post card | Card lifts slightly; title underlines; category chip brightens. |
| Keyboard tab | Focus follows DOM order: skip-link → nav → hero CTAs → content. |
| Click "Read the blog" | Client-side navigation preserves scroll position policy (top). |

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

| Property | Type | Default | URL key |
| --- | --- | --- | --- |
| `search` | `string` | `''` | `q` |
| `category` | `?string` (slug) | `null` | `category` |
| `tag` | `?string` (slug) | `null` | `tag` |
| `page` | `int` | `1` | `page` |

**Filter semantics.** All three predicates are combined with logical AND. Changing `search`, `category`, or `tag` resets `page` to 1 — otherwise a reader narrowing results would land on an empty page 3.

**Interaction timing.**

| Interaction | Debounce | Loading affordance |
| --- | --- | --- |
| Search typing | 300 ms | Results area dims; spinner on the search field |
| Category click | none | Immediate; chips show `data-loading` |
| Tag toggle | none | Immediate |
| Pagination | none | Scroll to top of grid |

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

| Condition | Behaviour |
| --- | --- |
| Long line | Horizontal scroll within the block; the page itself never scrolls sideways. |
| Copy button | Present on every `pre > code`; copies raw source. |
| Narrow viewport | Reduced padding and font size; no wrapping of code lines. |
| Reduced motion | No animation on the copy confirmation. |

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

| Value | Source | Constraint |
| --- | --- | --- |
| `og:title` | `post.title` | ≤ 95 chars for full display |
| `og:description` | `post.excerpt` | ≤ 200 chars |
| `og:url` | `route('blog.show', $post)` | Absolute, canonical |
| `og:type` | `article` for posts, `website` elsewhere | — |
| `og:image` | site OG image (no per-post uploads in v1) | 1200×630 |
| `article:published_time` | `post.published_at` | ISO 8601 |
| `article:tag` | repeated per tag | — |
| `twitter:card` | `summary_large_image` | — |

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

### 4.1 Journey G — Signing in

```
Entry: /login
  │
  ├─► [Optional] Passkey login offered via <x-passkey-verify />
  │
  ├─► Email + password submit → POST /login (Fortify)
  │     ├─ rate limited: 5/min per transliterated email + IP
  │     ├─ 2FA enabled → redirect to /two-factor-challenge (5/min limiter)
  │     ├─ failure → generic error, no field-level disclosure of which was wrong
  │     └─ success → LoginResponse
  │
  └─► Redirect target resolution:
        ├─ session intended URL present → redirect there
        └─ otherwise                    → dashboard
```

**Security posture.** The error message never distinguishes "unknown email" from "wrong password". Timing is not equalised, which is an accepted trade-off documented here rather than silently ignored.

### 4.2 Journey H — Authoring a post

```
Entry: dashboard → [+ New post]
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

| Breakpoint | Layout |
| --- | --- |
| `< lg` | Single column: form, then a preview toggle button. Preview replaces the form rather than stacking below it, so the author is never scrolling 4 000 px to see the top of the preview. |
| `≥ lg` | Two columns: form (7/12) · preview (5/12), both independently scrollable. |

### 4.3 Journey I — Managing posts

```
Entry: dashboard → Posts
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
Entry: dashboard (badge shows pending count) → Comments
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
Entry: dashboard
  │
  ├─► Metric tiles (computed from persisted data):
  │     Posts · Published · Drafts · Total views · Comments · Pending moderation
  │
  ├─► Recent posts table: title, status, views, comments, updated
  │
  ├─► Pending comments widget → deep link to the moderation queue
  │
  └─► Quick actions: [+ New post] · [Manage posts] · [Moderate comments]
```

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

| Transition | Trigger | Side effects | Reversible |
| --- | --- | --- | --- |
| ∅ → Draft | Create post | Row inserted; slug assigned | n/a |
| Draft → Published | `PublishPost` | `published_at = now()`, public listings now include the post | Yes |
| Published → Draft | `UnpublishPost` | `published_at = null`, removed from public surfaces | Yes |
| Any → Archived | Archive action | Hidden from the author's default list, still in the DB | Yes |
| Any → Trashed | Delete | Soft delete; public URL 404s | Yes, from the DB |

**Invariant.** A post appears on a public surface **if and only if** `status = published` **and** `published_at` is not null **and** `deleted_at` is null. Every public query enforces all three conditions.

### 5.2 Comment status

```
[submit] ──► Pending ──[approve]──► Approved   (publicly visible)
                │
                └──[reject]───► Rejected        (never publicly visible)
```

### 5.3 Authentication state

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

| Scenario | Expected behaviour | Rationale |
| --- | --- | --- |
| Draft URL requested by a guest | `404` | A `403` confirms the resource exists; drafts are private, so they should be indistinguishable from nothing. |
| Post unpublished while a reader has it open | Reader's tab still shows the cached HTML; a refresh yields `404` | No websocket channel exists to push the change; forcing a refresh would be worse. |
| Search term with regex metacharacters | Treated as a literal; `%` and `_` escaped before reaching `LIKE` | Prevents wildcard injection and full-table scans. |
| Simultaneous autosave and manual save | The later-issued write wins; the earlier is discarded by timestamp check | Prevents a stale autosave clobbering a deliberate save. |
| Slug collision on publish | Suffix appended (`my-post-2`) | Never blocks publishing on a cosmetic conflict. |
| Comment submitted while logged out in another tab | Session is gone; submission redirects to login | Standard session behaviour; no special handling. |
| JS disabled | All public pages render and navigate; share intents work as links; filtering falls back to a full-page form submission | Progressive enhancement is a hard requirement for the read path. |
| Clipboard API blocked by permissions policy | Fallback selects the URL and shows "Press Ctrl+C to copy" | Silent failure would look like a broken button. |
| Web Share API cancelled by the user | Nothing happens; no error state | Cancellation is a normal user action, not a failure. |
| Very long post body (10 000+ words) | Editor and preview remain usable; TOC paginates visually with max-height and scroll | The TOC must never push the article off-screen. |
| Empty blog (no published posts) | Index shows a friendly empty state, not a broken grid | First-run experience matters. |
