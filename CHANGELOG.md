# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Roles & auth**: three account roles (`user`, `seller`, `admin`) each with its own
  top-level dashboard path, sidebar, and chrome; role-aware Fortify redirect responses;
  `EnsureAccountPermission` middleware; `DashboardNavigation` as the single source of
  truth for sidebar entries and route guards.
- **Dashboard redesign**: soft UI dashboard shell (`dashboard-shell`) composing sidenav,
  drawer, and focus-trap machinery once per workspace; new UI primitives (accent picker,
  chart card, CTA marquee, nav crumb, navbar, sidenav footer, social icon, stat band,
  theme toggle, welcome card, why-hire-me).
- **Soft UI system**: `docs/SOFT-UI.md` design system with tokens, states, and rules;
  `docs/UI-UX-BRIEF.md` design language and type scale.
- **About page**: `⚡about.blade.php` Livewire single-file component with hero,
  experience, work/writing, marquee stat band, and CTA sections, plus matching CSS.
- **Theme system**: theme boot partial, theme toggle, dark mode variants, and
  `docs/themes.md`.
- **Modules**: Marketplace show page; Developer Lab, AI Knowledge Hub, and Ideas
  placeholder routes claimed in the URL space (both modules built later in this
  release — see **Ideas workspace** and **Developer Lab** below).
- **Tests**: new feature tests for About (CTA marquee, experience, hero, marquee stat
  band, work/writing), Dashboard (composition, navigation, shell, taxonomy), Routing
  structure, and Theme system.
- **Feed composer & publishing (PRD Phase 1)**: the community feed was a read-only
  shape — no `user_id`, no status, no write path. It is now a form.
  - Schema, all additive: `users.handle` (nullable, unique, stored bare), the
    publishing columns on `feed_posts` (`user_id`, `status`, `scheduled_at`,
    `audience`, `community_id` + a `status`/`posted_at` index), and the new
    `feed_attachments` table.
  - `App\Enums\FeedPostStatus` (`draft` / `scheduled` / `published`) and
    `App\Enums\FeedPostAudience` (`public` / `self` / `community`); `FeedPost`
    gained `attachments()`, `community()`, `user()`, `isPublishedNow()`, and
    `isVisibleTo()` as the one audience-aware visibility predicate, with
    `visible()` extended into the SQL floor nothing escapes.
  - `App\Actions\Feed\*` verb classes (`ComposeFeedPost`, `SaveFeedDraft`,
    `ScheduleFeedPost`, `UpdateFeedPost`, `DeleteFeedPost`, `AttachFeedMedia`,
    `DetachFeedMedia`, `ResolveFeedAudience`, `HydrateFeedPostContent`) behind a
    convention-discovered `FeedPostPolicy`.
  - Private media: a `feed_media` disk configured `'serve' => false`, so a stored
    file is reachable only through the signed, ten-minute
    `feed.attachments.show` route, which checks the signature, then the parent
    post's visibility rule, then streams with `nosniff` and a sandboxed CSP.
  - `⚡composer-modal.blade.php` (`pages::feed.composer-modal`): audience selector,
    type chips, per-image alt text, drag-drop/paste/picker uploads with progress,
    autosave to drafts, scheduling, and a state-driven publish flow. The composer
    entry point now opens it for members and keeps the sign-in dialog for guests.
  - Commands and schedule: `feed:publish-scheduled {--dry-run}` every minute,
    `feed:prune-media` daily (stale `temp_key` rows, their files, and orphaned
    files under `posts/`), joining the existing `feed:simulate`.
  - `App\Support\FeedPersonas` — one registry of the ten corpus authors, now the
    single source read by `UserSeeder`, `CommunitySeeder`, `FeedPostSeeder`, and
    `feed:simulate`.
  - Rate limiting: a `feed-publish` limiter (5 posts / 60 s per user, exempt for
    admins) plus a 10-second duplicate-submission guard, both enforced inside
    `ComposeFeedPost`.
  - Tests: `tests/Feature/FeedPublishingTest.php`, `tests/Feature/FeedMediaTest.php`,
    `tests/Unit/FeedPostVisibilityTest.php`, factory states on `FeedPostFactory`, and
    a Laravel Dusk browser suite (`tests/Browser/ComposerPublishTest.php`) covering
    open → type → attach → publish and the 390×844 mobile layout.
- **Ideas workspace (docs/Idea.md)**: `/ideas` stopped being a placeholder and became
  a four-table module with real CRUD.
  - Schema: `idea_categories`, `ideas`, `idea_plans`, `idea_tasks`. No `user_id` on
    tasks — ownership resolves through the parent plan, so a task can never drift away
    from its plan's author.
  - Enums: `IdeaStatus` (inbox → draft → under review → approved → archived),
    `PlanStatus`, `TaskStatus`, `WorkPriority`, `IdeaPotential`, `ContentVisibility`.
    Transitions are enum-owned (`allowedTransitions()` / `canTransitionTo()`) and
    enforced in `SaveIdea` and `SaveIdeaPlan`, so "archived back to approved" is
    refused by the type rather than by a UI promise.
  - Thirteen actions in `app/Actions/Ideas` (the thirteenth is `SyncIdeaTopics`, added
    by the taxonomy work below), four policies
    (`IdeaPolicy`, `IdeaPlanPolicy`, `IdeaTaskPolicy`, `IdeaCategoryPolicy`) —
    ownership-first, with `admin:access` admitted to `delete` but never to `update`,
    so an operator can moderate without rewriting somebody's plan.
  - Eight screens in `pages/ideas/`: the public index and detail, the workspace home
    with quick capture, the create-or-edit form, category management, plans list, plan
    detail with the task board inline, and the plan form.
  - `ConvertIdeaToPlan` keeps the idea row, links `idea_id`, copies the summary and
    body into the plan, and derives the slug as `{idea-slug}-plan`. One idea converts
    once: the action refuses and `idea_plans.idea_id` carries a UNIQUE index.
  - `IdeaSlug` / `PlanSlug` reject the literal second-segment words of their own URL
    space, so an account cannot name an idea "plans" and shadow the plans screen. This
    is the write-side half of the route-ordering defence.
  - `config/ideas.php`, `IdeaCategorySeeder` (idempotent by slug), factories for all
    four models.
- **Developer Lab (docs/lab.md)**: `/dev-lab` became an editor that runs something.
  - Schema: `lab_workspaces` (the HTML/CSS/JS scratch buffer), `lab_snippets` (the
    publishable object), `lab_executions` (one row per press of Run, and nothing else).
  - `LabLanguage` (`html`, `css`, `javascript`, `php`) carries
    `isBrowserRunnable()`. That one method is the execution policy, and
    `RecordLabExecution` checks it server-side, so PHP has no Run button in the UI and
    cannot be granted one by a hand-crafted request.
  - Execution is `<iframe sandbox="allow-scripts">` with **no** `allow-same-origin`,
    so the preview runs at an opaque origin: it cannot read this site's cookies,
    storage, or DOM, and cannot call a Livewire endpoint with the visitor's session
    attached. A console shim inside the frame posts `{ __lab, level, text }` to the
    parent, which accepts a message only when `event.source` is that frame's
    `contentWindow` — origin is not compared, because for an opaque origin it is the
    literal string `"null"`. Received text is capped again and printed with `x-text`,
    never `innerHTML`. The page's `csrf-token` meta is never copied into the frame.
  - Limits this design can actually enforce: `config/dev_lab.max_code_bytes` (30 000
    per pane) and `config/dev_lab.daily_run_limit` (100 per account per day). There is
    no CPU or memory ceiling, because the runtime is the visitor's tab. A `while (true)`
    does hang that tab until Stop or another Run replaces the frame, and the panel under
    the preview says exactly that rather than leaving it to be discovered.
  - Seven actions (the sixth and seventh are `SyncLabSnippetTopics` and
    `CloneSnippetToWorkspace`, both from the taxonomy work below),
    `LabWorkspacePolicy` and `LabSnippetPolicy`, eight screens (the
    sandbox, dashboard, snippet list and form, run history, the public landing, the
    gallery, and one published snippet), `SnippetSlug`, `LabSnippetSeeder`, and
    factories. Published code is always printed as `{{ $snippet->code }}` inside
    `<pre><code>` — escaped, with a copy button, and an opt-in preview that opens the
    same sandboxed frame with a warning that the code is not the visitor's.
- **Public subject taxonomy — what a stranger sees of Ideas and the Lab**: the two
  modules had real data and thin view layers. Both landings now navigate by subject,
  author and order, the detail pages read like the blog's, and `/topics` exists as the
  one public surface that belongs to neither module.
  - Schema, all additive (`2026_10_20_100007`–`100009`): one platform-owned `topics`
    table (flat, global unique slug, `is_active`, `sort_order`, optional Flux `icon`)
    with two natural-keyed pivots, `idea_topic` and `lab_snippet_topic`, each carrying
    the author's `sort_order` so the first link is the primary subject. One shared
    table rather than a per-module pair, because a subject is what a row is *about* and
    "accessibility" means the same thing to an idea as to a snippet. `idea_categories`
    is untouched and stays private; `ai_topics` stays the hub's own hierarchy.
  - `App\Models\Topic` (with `MAX_PER_ROW = 8` and the shared `syncRows()`), `topics()`
    and `#[Scope] inTopic()` on `Idea` and `LabSnippet`, and `SyncIdeaTopics` /
    `SyncLabSnippetTopics` called inside the existing `SaveIdea` and `SaveLabSnippet`
    transactions. A hand-crafted id list is int-cast, de-duplicated, capped, then
    filtered against active topics, and what fails is dropped silently.
  - `App\Services\PublicTaxonomy` is the count gate, written once and registered as a
    scoped binding beside `Seo`: a subject is advertised only where clicking it opens
    something published. Four screens read it — both facet rows, `/topics`, and the
    structured-data keywords — so none of them can drift into a chip that leads
    nowhere. The author-facing picker is the deliberate exception: it still offers a
    retired topic that an existing row carries, so the pivot can be un-checked.
  - Both landings gained the same five things the AI Hub already had: `?topic=`,
    `?author=` and `?sort=` as URL-bound state, per-facet counts, one removable chip per
    active filter, a `wire:loading` skeleton shaped like the grid it replaces, and a
    minimum-two-character guard that says "type at least two characters" instead of
    silently returning nothing. No new `/search` route; the navbar search stays
    author-only.
  - `/topics` and `/topics/{topic:slug}` are public, route-bound, and 404 through the
    same gate (`isBrowseable()`) for both an inactive subject and one nothing published
    carries. Each topic page shows a limited shelf from both families with an explicit
    "showing the first N of M" notice and a "see all" link that carries `?topic=`, so
    the facet and the topic page never disagree.
  - `/ideas/{slug}` got the reading layer the components already supported: `MarkdownRenderer::outline()`
    into `<x-app.ui.post.toc>` (aside and narrow-screen), the reading-progress bar the
    page's existing `data-reading-target` had been waiting for, live reading time and
    word count, a share bar, an author link into `?author=`, and prev/next across the
    published set. `x-app.ui.post.share-bar` was generalised with `url`/`title` props so
    the two blog call sites render byte-identically.
  - `x-app.dev-lab.snippet-card` extracted from the gallery markup and reused on the
    Lab landing's featured strip in `compact` mode and on `/topics/{slug}`; the strip's
    private `languageBadge()` helper and its `['label','runnable']` array shape went
    with it.
  - Two sandbox surfaces that move no security invariant: the `/dev-lab` landing now
    runs a built-in showcase through the same frame (`allow-scripts`, never
    `allow-same-origin`, `runStored()` only, no `$wire` read, `alert()` replaced by a
    console warning), and a published snippet offers "Open in your sandbox" through
    `CloneSnippetToWorkspace`, which updates one cloned workspace on repeat clicks rather
    than piling them up. PHP gets neither Run nor Clone.
  - Structured data: `Seo` gained one property (`$jsonLd`) and `partials/head.blade.php`
    one guarded block, printed with the four `JSON_HEX_*` flags so a title containing
    `</script>` cannot close the tag. `/ideas/{slug}` emits `Article` +
    `BreadcrumbList`, `/dev-lab/snippets/{slug}` emits `SoftwareSourceCode` +
    `BreadcrumbList` (with `runtime` only when the language is browser-runnable, and no
    invented `license`), and the two topic pages emit `CollectionPage` + `ItemList`.
    Authors are `Person` with a name and never an email; drafts and private rows emit
    nothing.
  - **Privacy fix found on the way**: `x-app.ideas.card` printed the private
    `IdeaCategory` name on every surface it rendered, including `/ideas` and the topic
    page, for anybody. The folder now renders only for its author (`showCategory`
    defaults to the ownership test inside the card, not a per-page flag), so every
    present and future surface inherits the rule.
  - Tests: `tests/Feature/PublicTaxonomyTest.php`, `TopicsPagesTest.php`,
    `StructuredDataTest.php`, `tests/Feature/Ideas/IndexFacetsTest.php` +
    `IdeaTopicsTest.php` + `IdeaShowReadingTest.php`, and
    `tests/Feature/DevLab/GalleryFacetsTest.php` + `SnippetCardTest.php` +
    `SandboxShowcaseTest.php` + `CloneSnippetTest.php`, plus new pins in
    `RoutingStructureTest.php` and in `LabSnippetSeederTest.php` (the seeded gallery is
    filed under its subjects, and a re-run does not stack the pivots). Two Dusk tests
    cover what only a browser can: `tests/Browser/IdeasReadingTest.php` proves the
    scroll listener computes the bar's `aria-valuenow` and that the TOC observer marks
    the section in view, and `tests/Browser/LabShowcaseTest.php` proves the landing's
    frame is created by Run, reports an opaque origin, refuses `localStorage`, and
    echoes the console line escaped. No test file was deleted.
  - Unchanged by design: no new dependency, no new `AccountPermission` case, no PHP
    executed server-side, no row visible to an account that does not own it.
- **Module consoles**: `/admin/ideas` and `/admin/dev-lab` render platform-wide
  consoles — a stage filter, an author column, a remove action, no editing — in
  `layouts::admin.dashboard`, with `noindex` set and an `admin:access` `boot()` guard.
  Both gained entries in the admin rail, and the group docblock that promised they
  would stay out of it "until there is something to do there" was rewritten.
- **Tests**: fourteen new files. `tests/Feature/Ideas/` (CRUD, visibility, workflow,
  conversion, plans and tasks, categories), `tests/Feature/DevLab/` (sandbox, snippet
  CRUD, workspace, seeder), `tests/Feature/Admin/` (both consoles), and
  `tests/Unit/IdeaTest.php` / `tests/Unit/LabLanguageTest.php`. The ownership spine is
  pinned directly: a foreign row 404s rather than 403s, and a `/livewire/update` from
  an account without the module permission returns 403.

### Changed

- **The sidebar's Modules entries now point at workspaces.** `ideas.index` →
  `ideas.dashboard` and `dev-lab.index` → `dev-lab.dashboard` for the two rebuilt
  modules; labels, patterns, icons and permissions are unchanged, so the rail still
  lights for every screen in the space. The AI Hub and the Marketplace keep their
  public targets, because reading is what those two offer a visitor.
- **`/ideas` and `/dev-lab` stopped announcing that they were unbuilt.** Both landing
  pages lost the `module-placeholder` panel and the "Module" eyebrow; the Lab's
  `overview`, `capabilities`, `capability` and `status` components now describe what
  works today instead of what will exist, and `access()` gained a fourth branch for the
  account that is signed in and permitted. `x-app.ui.module-placeholder` still renders
  the two AI Hub shelves at `/ai-hub/ideas` and `/ai-hub/dev-lab`, which stay
  placeholders because a shelf needs a hub-side curation object, which is a different
  thing from the Ideas bank.
- **No permission case was added, and no dependency was installed.** `ideas:use` and
  `dev-lab:use` already existed and now guard real screens; `AccountPermission` stays at
  thirty cases and `tests/Feature/Enums/PermissionModelTest.php` — which pins the exact
  per-role sets — was deliberately not touched. The sandbox needed no package.
- **Test fallout.** `tests/Feature/AdminModulePlaceholdersTest.php` asserted "Not yet
  available" on the two module consoles, so it was retired and its four cases rewritten
  into `tests/Feature/Admin/IdeasConsoleTest.php` and `DevLabConsoleTest.php`; the
  guest-bounce and non-admin-403 cases moved verbatim, the shell and `noindex` cases
  moved with them. `tests/Feature/DevLabLandingTest.php` was rewritten in place to pin
  the real landing. `RoutingStructureTest` gained path pinning for all nineteen module
  routes, a guest-bounce loop, and an ordering test that `/ideas/plans` resolves to the
  plans screen rather than to an idea whose slug is "plans"; the assertion that a module
  page states it is not yet available was retired. `NavigationTest` and `DashboardTest`
  follow the new rail entries and targets.
- Documentation was corrected against the code rather than against the plan, and the
  sweep also repaired claims that had drifted before this build: `docs/API.md` §6.6
  said there was no `Product` model when `MarketplaceProduct` and its three companions
  exist and are seeded; `docs/TRD.md` described two policies where ten exist, totalled
  the permission enum at 28 where it holds 30 and listed ten administration abilities
  where the group holds twelve, and still drew `pages/dashboard/⚡post-editor` after
  that screen moved to `pages/admin/posts/`; `docs/APP-FLOW.md` carried a stale route
  count. `docs/Idea.md` and `docs/lab.md` had
  their "UPSTREAM PRODUCT SPEC — NOT IMPLEMENTED" banners replaced with
  "PARTIALLY IMPLEMENTED", a *What is shipped* section, a deviation table with a reason
  per row, and — for the Lab — the sandbox boundary written out in full, since the
  repository has no SECURITY.md and `lab.md` is where a reader looks for the lab.
- **Feed page query budget**: `posts()` now eager-loads `attachments` (+1) and
  `bounties()` became an in-memory projection over the already-memoised posts
  (−1), so the pinned `≤ 4 queries per feed render` holds at exactly four.
  Audience filtering joins the existing in-memory `matchesFilters()` predicates
  rather than costing a second query.
- **`feed.show` is now gated.** The detail page takes a slug, runs `visible()`, and
  aborts 404 unless `isVisibleTo($reader)` passes — which also fixes the
  long-standing "Answer" link that 404ed on detail-less questions.
- **Persona renames.** Where the feed corpus and the community seeder disagreed,
  the corpus won: the seeded `Kenji Watanabe · @kenji` account is now
  `Kenji Sato · @kenji`, and `@daniel` is now `@danielo`. Those two strings change
  in the community member lists; the published cards never changed.
- **Rendered identity stays denormalised on purpose.** `author`, `handle`,
  `avatar`, and `role` remain what a card prints; `user_id` answers only *who may
  act on this*.
- **`bootstrap/app.php`** now renders the styled `errors.500` view for an uncaught
  exception while deliberately returning `null` for anything implementing
  `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`. That exclusion is
  what lets the attachment route's 400 (bad or expired signature) and 404 (not
  visible to this reader) keep their status codes instead of collapsing into a
  generic error page.
- `docs/SCHEMA.md` and `docs/ROUTES.md` were corrected against the code rather than
  against memory: the route count (78 → 109), `/feed/{post:slug}` →
  `/feed/{slug}`, the false "`routes/console.php` registers no routes", and the
  false "JSON columns: not used" portability row.
- **Five hand-tinted status badges now derive their colour.** The Public/Private
  badges in `pages::dev-lab.snippets`, `pages::dev-lab.dashboard`,
  `pages::admin.dev-lab` and `pages::admin.ideas` typed `green`, `gray` and `amber`
  into the template while `ContentVisibility::badgeColor()` already said `green` and
  `zinc`. Two of the five were also wrong, not just untidy: the snippets list read
  `isPublished() ? "Public" : "Private"`, so a row flagged public before its
  `published_at` was set was labelled Private. It now prints
  `visibility->label()` in `visibility->badgeColor()`, one state from one column.
  The six literals left in the tree are booleans (`is_featured`, `is_active`), a
  count, a "no Run button" marker, and the console's three-way
  flag-versus-timestamp disagreement — none of which an enum can express, and
  `docs/SOFT-UI.md` §5.9 now says so per site.
- **Documentation audited against the code, not against memory, end to end.** Every
  claim in `README.md` and `docs/*.md` that could be checked was checked, and the
  corrections that came out of it: `docs/API.md` listed `/dashboard` as gated by
  `post:create` when its middleware is only `auth` + `verified`; `docs/PERMISSIONS.md`
  said `marketplace:use` "guards `/marketplace`" and `/seller/dashboard` "manages
  listings", when both the storefront and the seller home are `product:*`-gated or
  ungated, and said `ai-hub:use` gates "the hub's reading surfaces", which are
  public; `docs/APP-FLOW.md` still named `dashboard.seller.index` and
  `dashboard.admin.index`, which are now 301 redirects, and invented a
  `product:configure` permission (`payment:configure` is the real case, and `seller`
  holds neither); `docs/COMPONENTS.md` documented a `topbar` component that has never
  existed, described the role chrome partials as accepting a label they do not
  declare (the three sidebars take `variant` and nothing else, the admin one only
  *forwards* `scope="admin"` to the shared nav, and the six headers and footers take
  no props at all), and kept `desktop-user-menu` documented after `sidenav-footer`
  had replaced it;
  `docs/SCHEMA.md` counted 26 `AccountPermission` cases where there are 30 and glued
  two table rows together; `docs/ERD.md` was titled "Full Entity Relationship
  Diagram" while 19 of the 32 application tables had no entity box, and claimed
  "Eleven migrations" where there are 38; `docs/ROUTES.md` claimed three non-Livewire
  routes where there are six controllers and 135 routes, the gap being that
  `route:list --except-vendor` hides every Livewire page route. `docs/API.md` §6.6,
  §6.7 and §6.8 were verified rather than rewritten — the seller gaps, the
  publish/pin/convert verbs and the no-`POST /executions` decision all still hold
  against the shipped code.
- Blade components across all three roles (headers, footers, sidebars, nav, utility
  links, logo mark) reworked for the soft UI system; legacy `rail-footer` and `topbar`
  removed.
- `routes/web.php` restructured for role-scoped dashboards and module URLs.
- `phpstan.neon` tightened for Larastan level 7.
- Vite config updated for the new asset layout.

### Removed

- Starter-kit team feature (`TeamInvitationTest` and related scaffolding).
- Duplicate modal markup consolidated into the `<x-app.ui.modal>` shell.
- Latent icon names that threw hard render exceptions.
- **Dead code and unreferenced files, from a repo-wide audit.** Each was verified
  to have no call site, no runtime resolution path, and no test reference before
  deletion:
  - `components/app/seller/payouts/index.blade.php` — an orphan that could not
    render even if asked: it called `<x-app.ui.seller.payouts>` and
    `<x-app.ui.payouts-table>`, neither of which exists, and posted to
    `seller.payouts.request`, a route that does not either. It was the one file
    making `php artisan view:cache` fail, because Blade compiles the whole tree.
  - `components/app/ui/desktop-user-menu.blade.php`, `task-list.blade.php` and
    `app-logo.blade.php` — superseded by `sidenav-footer`, never built, and by
    `app-logo-icon` respectively. `layouts/auth/split.blade.php` uses the icon
    only, so the wrapper had no caller left.
  - `resources/views/flux/` (5 files) — published Flux stubs whose vendor originals
    they no longer overrode meaningfully.
  - `public/icons/` (6 `skill-*.svg`), `public/images/darkmode.png`,
    `public/images/hero-portrait-dark.jpg`, and `EXCEPTION` at the repo root.
  - `storage/app/deadcode-scan.php`, a scratch script from an earlier pass.
  - Empty directories `app/Notifications` and `tests/Browser/Components`.
  - **Two inert component props.** `x-feed.nav-rail` declared `reader = null`, the
    feed page passed `:reader="$this->reader"`, and the rail never read it;
    `x-app.ui.module-placeholder` declared `status = 'planned'` and no template or
    call site touched it. The nav-rail pair went on both sides at once, because an
    undeclared Blade attribute is not discarded — it is merged onto the root
    element — so deleting only the `@props` entry would have converted a `?User`
    into a string at render time.
- **Unused model and enum members**, each the cause of a quality-gate failure or
  simply unreachable:
  - `Community::members()` — every consumer reads `memberships()`,
    `membershipOf()`, `ownerMembership()` or `memberships_count`. Its
    `->using(CommunityMembership::class)` was also the sole source of the two
    long-standing PHPStan level-7 errors, because the class extends `Model` and not
    `Pivot`; converting it would have flipped key semantics on a model created
    directly in five places, so the unused relation went instead of the errors.
    **`vendor/bin/phpstan analyse` now reports no errors.**
  - `TaskStatus::boardCases()` and `IdeaPlan::cameFrom()` — zero call sites
    anywhere, including tests and docs.
- **Retired test file**: `tests/Feature/AdminModulePlaceholdersTest.php`. Its four
  cases asserted that `/admin/ideas` and `/admin/dev-lab` say "Not yet available",
  which stopped being true when the consoles were built. Replaced by
  `tests/Feature/Admin/IdeasConsoleTest.php` and
  `tests/Feature/Admin/DevLabConsoleTest.php`, which keep the guest-bounce,
  non-admin-403, `data-sui` shell and noindex assertions the retired file owned.

### Fixed

Two defects in this release's own new code, both found by running the Dusk suite
against a served application rather than by a Blade assertion — which is why those
two tests are committed instead of the checks being done by hand:

- **The `/dev-lab` showcase pane was dead.** `components/app/dev-lab/showcase.blade.php`
  put `@js($panes)` inside the attribute list of a Blade *component* tag
  (`<x-app.ui.section x-data="…">`), and Blade does not compile directives inside
  one: the alias reached the browser as the literal text `@js($panes)`, Alpine failed
  to parse `labSandbox(…)`, and the panel's `x-show` state never became reactive, so
  the pane rendered no Run button and nothing else on the page told anybody. The
  config object is now built with `Illuminate\Support\Js::from()` in the `@php` block
  and interpolated with `{{ }}` — the same escaping, on a path a component tag leaves
  alone. `tests/Browser/LabShowcaseTest.php` pins the rendered frame, and
  `docs/COMPONENTS.md` records the directive-in-a-component-tag trap.
- **A re-seed left the gallery's facet row empty.** `LabSnippetSeeder` combined the
  example with its topic ids using `+`, and on a duplicate key the left operand wins:
  the readable slug list from `defaults()` survived, the id list was discarded, and
  `SyncLabSnippetTopics` dropped every non-integer entry — so `lab_snippet_topic`
  came back with zero rows and `/dev-lab/gallery` was born with no subject chips at
  all. Array spread now replaces the key instead of joining to it, and
  `LabSnippetSeederTest` asserts both that every seeded snippet carries its subjects
  and that a second run does not stack the pivots.

Four more, found by reading the filter code end to end on every listing rather than
by running the suite — the existing tests all set a filter and read the list back
inside one request, which is exactly the shape that cannot see any of them:

- **Every filtered listing lost its filters on the second list.** Nineteen screens
  built their links from `paginate()` alone, and because this site's page controls are
  real `<a href>` elements carrying `wire:navigate`
  (`vendor/pagination/devfolio.blade.php`), moving to the next list is a navigation
  that rebuilds the component from the address bar rather than a Livewire round-trip
  that could read the filter off the component. So `?topic=`, `?q=`, `?author=`,
  `?sort=`, `?lang=`, `?tab=` and `?visibility=` all vanished: the second screen of a
  narrowed list became the second screen of everything, under a heading that still
  named the narrowing. Each chain now ends with `->withQueryString()`.
  `tests/Feature/FilteredPaginationTest.php` asserts the rendered link on seven of
  those screens over real HTTP, and
  `tests/Feature/FilteredPaginationGuardTest.php` reads the source of all nineteen so a
  twentieth listing cannot be written without it.
- **`/ideas/plans?tab=` crashed on a value the screen does not offer.** The empty-state
  sentence is built from the tab's own label, and the guard above the lookup excluded
  only `open` and `all`, so a stale bookmark naming a retired stage reached
  `tabs()['retired_stage']` and raised *Undefined array key* instead of rendering. The
  component now exposes a nullable `activeTabLabel()` and the sentence is only used when
  it resolves; `PlansTabsTest` pins both the crash and the partitions.
- **The run history never offered a language chip.** The Developer Lab run history
  (`pages/dev-lab/⚡history.blade.php`) built its filter row by asking whether the
  plucked-languages collection contained a stored string, but
  `lab_executions.language` is cast to an enum, so the collection holds `LabLanguage`
  cases and the comparison was false for every one of them. The row rendered the single
  "Every language" entry — and that entry, keyed by the empty string, was stored by PHP
  as the integer `0`, so its own click handler asked for `?lang=0`. The comparison is
  now case to case, the "Every language" control is markup rather than an array entry,
  and `tests/Feature/DevLab/HistoryFiltersTest.php` pins which chips appear, that the
  clear control clears, and what a language nobody has run says instead.
- **`/dev-lab/gallery?lang=perl` showed the whole gallery.** An unparseable language was
  dropped rather than applied, while the chip over the grid printed whatever the URL
  said, so a heading naming one language sat above a list of everything published — the
  same pretending the subject facet is tested against for a hand-typed topic. A
  `?lang=` that names nothing browseable now narrows to nothing, and the chip stays to
  be cleared. `GalleryFacetsTest` pins it for both an unknown value and a language with
  no published rows.

### Deferred (recorded deliberately)

Phase 1 wired structure, not speculation. Each item below is absent because the
data to honour it does not exist yet — not because it was forgotten:

- **Mentions, tag alerts, publication notifications** — there is no
  `notifications` table in any migration.
- **Reaction and comment rows** — the feed's JSON counters have no user behind
  them. Adding rows would either fabricate voters or create a second source of
  truth; the honest fix is its own phase.
- **`Followers` and `Custom` audiences** — the platform has no follow graph and no
  per-user allow-list. An option that silently meant "public" is a privacy lie, not
  a placeholder.
- **Composer options that are present and disabled** (`aria-disabled`, "Not
  available yet", each asserted by a test): Live Video, Reel, Video, GIF, Tag
  People, Check In/Location, Get Calls/Contact CTA, Collaborator, Business
  Partnership, Event, Product/Project Attachment, Life Update, Content Warning,
  emoji picker, formatting controls, mention/hashtag autocomplete, and link-preview
  metadata.
- **Rich-text body HTML** — the feed renders `{{ $post->body }}` escaped. Adding
  sanitised HTML means changing every render site and re-trusting ten curated rows;
  the existing `type`, `code`/`code_file`, `poll`, and `activity` columns already
  are this feed's rich-content system. `App\Support\MarkdownRenderer` remains the
  only HTML entry point.
- **Syntax highlighting** (no highlighter dependency approved), **remote link
  fetching** (deliberately no SSRF surface in Phase 1), **per-user poll voting**,
  **analytics**, **bookmarks**, **reporting**, **author-side pinning**,
  **collaborators**, **product attachment**, and **content warnings**.
- **The PRD's twelve-model / six-`*Service` shape** — it duplicates the existing
  `posts` stack and this project's `App\Actions\{Domain}\{Verb}` convention, so the
  convention won.
- **Ideas: sharing, invitations, per-item comments, activity log, revision history,
  projects, milestones, tags and cover images.** Each needs either a second ACL — and
  `AccountPermission` was deliberately left at thirty cases — or an upload disk with a
  signer and a prune command, which is the feed's media problem again. The schema is
  built so these arrive additively.
- **Developer Lab: server-side runners, challenges and grading, autopairing,
  in-editor highlighting and debugging.** Execution is the visitor's browser, so there
  is nothing to grade securely and nothing to attach a debugger to. The full list with
  one reason per row is in [TRD §7.2](docs/TRD.md).

### Security

- **The Lab sandbox grants scripts and no origin.** `sandbox="allow-scripts"` is the
  whole attribute: no `allow-same-origin`, `allow-forms`, `allow-popups`,
  `allow-top-navigation`, `allow-downloads` or `allow-modals`. Consequences that are
  part of the design, not accidents: `alert()` and `confirm()` are silently blocked
  (the console pane says so, so the silence does not read as a bug), the frame cannot
  reach this site's cookies, `localStorage`, `sessionStorage`, indexedDB or the parent
  DOM, and the preview rebuilds only on an explicit Run rather than on keystroke.
- **Stored code is never markup.** Public snippet pages print `{{ $snippet->code }}`
  inside `<pre><code>`; `{!! !!}` appears nowhere near user content, and a test asserts
  that a snippet containing `<script>` does not emit one.
- **Refusals happen server-side.** `RecordLabExecution` re-checks
  `LabLanguage::isBrowserRunnable()` and the daily limit (`config('dev_lab.daily_run_limit')`,
  100), so neither the PHP run nor the hundred-and-first run can be minted by a crafted request.
  Ownership re-asserts itself
  the same way: workspace queries run through an `authoredBy()` scope, so a foreign id
  is a 404, and every guarded screen has a `boot()` permission check because Livewire
  mutations post to `/livewire/update`, where route middleware never runs.
- **Found while designing this, not introduced by it:** the application sends no
  `Content-Security-Policy` and no `X-Frame-Options` anywhere — not in
  `partials/head.blade.php`, the layouts, `app/Http/Middleware`, `bootstrap/app.php` or
  `config/`. That absence is why `iframe srcdoc` with inline script executes at all, and
  it means user-supplied markup is currently unsanitised by header policy. The sandbox is
  not the only line of defence here, and a CSP is worth its own piece of work.
- **A live `APP_KEY` was committed in a scratch dump.** `_diag/env.txt` — one of twenty
  diagnostic files (logs, Python image scripts, HTML dumps) that `a01f127` committed even
  though `/_diag/` is listed in `.gitignore`, because an ignore rule never untracks what
  is already in the index — held a full copy of the local `.env` with the same `APP_KEY`
  the running `.env` has. It is deleted from the working tree and the index. **The value
  is still in git history**, so the key should be rotated before this repository is
  pushed anywhere shared; removing it from history is a rewrite and has deliberately not
  been done. No other secret was in the file: it carries no `DB_PASSWORD`,
  `REDIS_PASSWORD` or `MAIL_PASSWORD`.
