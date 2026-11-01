# DevFolio

A portfolio and technical-writing platform for a full stack developer — long-form
blog, role-scoped dashboards, and the platform modules those dashboards
front — plus the documentation that describes all of it.

Built with Laravel 13, Livewire 4 single-file components and Flux UI. No build
step beyond Vite; no JavaScript framework. Every interaction is either a
server round-trip or a few lines of Alpine.

---

## What this actually is

It is worth being precise about scope, because "portfolio site" undersells it
and "platform" oversells it.

**It is:**

- A **published writing surface** — blog index, post detail, RSS, sitemap,
  reading progress, table of contents, social sharing.
- An **authenticated authoring tool** — draft, edit, publish, archive, with
  taxonomy management and comment moderation.
- A **role-scoped application** — three account roles (`user`, `seller`,
  `admin`) with an additive permission hierarchy, each on its own top-level
  dashboard path and sidebar.
- **Platform modules** — Ideas and the Developer Lab are workspaces with real
  CRUD: capture, filing, a stage workflow, plans converted from approved ideas and
  tasks owned through their plan; a three-pane editor with a sandboxed live preview,
  shareable snippets and a per-day run history. The AI Hub is a curated library and
  the Marketplace a read-only catalogue. Each is guarded by its own permission
  (`ideas:use`, `dev-lab:use`, `ai-hub:use`, `marketplace:use`) and each has a
  deferred half, recorded in [`docs/PRD.md`](docs/PRD.md) §4.7.
- **Documented** — PRD, TRD, UI/UX brief, application flow, schema, ERD,
  architecture, components, routes, permissions and an API design.

**It is not (yet):**

- A multi-tenant SaaS. There is no organisation hierarchy, no tenancy and no
  per-tenant theming — accounts are flat and platform-wide. (The starter kit's
  team feature was removed outright; see [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md) §1.)
- A code runtime. The Lab executes HTML, CSS and JavaScript in a sandboxed iframe
  inside the visitor's own browser, so there is no server-side runner, no container,
  and no CPU or memory quota. PHP is stored, shared and readable, never run.
  Ideas carry no sharing, invitations, projects or revision history either.
- A storefront. The `seller` role, its plan limits and its permissions are
  **fully defined and enforced**, and the catalogue — `MarketplaceProduct`,
  versions, categories, reviews — exists, is seeded, is readable by a stranger and
  is moderated from `/admin/marketplace`. What is missing is the two verbs: nobody
  can author a listing, and there is no `Sale` model, so nothing is transacted.
  This is a deliberate half-built layer — see
  [`docs/audit/PHASE-0-AUDIT.md`](docs/audit/PHASE-0-AUDIT.md) §10.
- A public API. [`docs/API.md`](docs/API.md) specifies one; nothing in it is
  implemented.

---

## Requirements

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | 8.5+ | Uses property hooks, enums, asymmetric visibility |
| Composer | 2.x | |
| Node | 22+ | For Vite |
| SQLite | 3.x | Default database; no server needed |

The development environment used to build this is **Laravel Herd**. Paths in
the notes below assume macOS or Windows with Herd; nothing depends on it.

---

## Getting started

There is a one-command setup for a fresh clone:

```bash
composer setup
```

That runs `composer install`, creates `.env`, generates a key, migrates, and
builds assets. It does **not** seed — see the warning below.

To do it by hand:

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create the database and seed the demo accounts:

```bash
touch database/database.sqlite
php artisan migrate --seed
```

Build assets and serve:

```bash
npm run build          # or: npm run dev
php artisan serve
```

Visit `http://localhost:8000`.

### Demo accounts

`UserSeeder` creates one account per role, all with the password `password`:

| Email | Role | Lands on |
| --- | --- | --- |
| `shamimstack@gmail.com` | `admin` | `/admin/dashboard` |
| `seller@example.com` | `seller` | `/seller/dashboard` |
| `user@example.com` | `user` | `/dashboard` |

Sign in as each in turn — the sidebar, the dashboard and the visible
navigation all change. That is the fastest way to see the permission model
working.

> **Is `password` a problem?** No, and this is worth stating plainly: these
> accounts are created by `UserSeeder`, which is a development seeder. It is
> never run in production, and a production deployment seeds nothing. If you
> deploy this, run `php artisan migrate --force` **without** `--seed`.

---

## Common tasks

| Task | Command |
| --- | --- |
| **All four gates at once** | `composer test` |
| Run all tests | `php artisan test` |
| Run one test file | `php artisan test tests/Feature/Auth/AuthLayoutTest.php` |
| Filter by name | `php artisan test --filter="role-scoped"` |
| Format changed files | `vendor/bin/pint --dirty --format agent` |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` |
| Build assets (prod) | `npm run build` |
| Dev server with HMR | `npm run dev` |
| Fresh database + data | `php artisan migrate:fresh --seed` |

`composer test` is the composed gate: it clears config, runs Pint in check mode,
runs PHPStan, then runs the suite. It is what CI should call.

**On this machine specifically**, `php` and `composer` are not on `PATH`
inside the sandboxed shell. Use the wrapper:

```bash
bash /tmp/phpx.sh artisan test
bash /tmp/phpx.sh vendor/bin/pint --dirty --format agent
bash /tmp/phpx.sh -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
```

The `-d memory_limit=1G` is required — Larastan exhausts the default limit.

---

## Quality gates

All four must be green before a change is considered finished.

| Gate | Command | Current state |
| --- | --- | --- |
| Tests | `php artisan test` | **1,227 passing**, 4,786 assertions, 13 environmental skips |
| Types | `vendor/bin/phpstan analyse` | **No errors** (level 7) |
| Style | `vendor/bin/pint --dirty` | **Clean** |
| Build | `npm run build` | **Green** |

The 13 skips are all one cause: `FeedMediaTest` needs PHP's **GD** extension to
synthesize the images it uploads, and this development machine's `php.ini` does not
have it enabled. Every one would throw `LogicException: GD extension is not installed`
from `Illuminate\Http\Testing\FileFactory::generateImage()` before reaching
application code, so the file calls `requiresGd()` — an explicit skip with a reason,
not a deleted test. Enabling `extension=gd` makes all 13 run.

Two narrower commands exist for the same suite: `composer test:security` runs only
the boundary tests (the guard sweeps, mass assignment, markup injection, throttling
and auth configuration, grouped in `tests/Pest.php`), and `composer test:coverage`
writes a coverage report on demand. Coverage is never enforced from `phpunit.xml`:
declaring a coverage block there stops the whole run on a machine without a driver.

Browser tests are a separate gate because they need a running server. `composer
test:dusk` (`php artisan dusk`) starts chromedriver itself, reseeds the SQLite file
with `migrate:fresh --seed`, and drives `http://127.0.0.1:8000`, so start the server
first with `php artisan serve --host=127.0.0.1 --no-reload`. Nine of its ten tests
pass here; `ComposerPublishTest`'s attach step needs the *served* PHP to have a
writable `upload_tmp_dir`, which this machine's does not — see the note at the top of
that file before reading the failure as an application defect.

The suite previously carried one inherited starter-kit defect
(`TeamInvitationTest`); that test was removed along with the team feature it
covered, so the only remaining red is environmental.

---

## Project layout

```
app/
├── Actions/          Single-purpose write operations, grouped by domain (10 groups, 68 classes)
│   ├── AiHub/        19: generation, curation, sort order, publication
│   ├── Ideas/        12: SaveIdea, ConvertIdeaToPlan, SyncIdeaVisibility, task CRUD…
│   ├── DevLab/       5: SaveLabWorkspace, SaveLabSnippet, RecordLabExecution…
│   ├── Posts/        10: Create, Update, Publish, Archive, Delete, Sync…
│   └── About|Comments|Communities|Feed|Fortify|Marketplace/
├── Concerns/         Reusable model traits (roles, slugs, reading time)
├── Data/             Read-only DTOs: AuthorProfile, PostMetrics, TextCorrection
├── Enums/            30: AccountRole, AccountPermission, RolePlan, PostStatus,
│                     IdeaStatus, PlanStatus, TaskStatus, LabLanguage, ContentVisibility…
├── Http/
│   ├── Controllers/  six non-Livewire responses: Feed, FeedAttachment, Sitemap,
│   │                 Robots, ErrorPage, Editor/EditorAi
│   ├── Middleware/   EnsureAccountPermission
│   └── Responses/    Per-flow Fortify redirect responses (role-aware)
├── Models/           29: User, Post, Comment, the seven module models
│                     (Idea, IdeaPlan, IdeaTask, IdeaCategory,
│                      LabWorkspace, LabSnippet, LabExecution) and the hub's
├── Policies/         10: PostPolicy, AiArticlePolicy, FeedPostPolicy and UserPolicy
│                     for platform content, plus the six ownership-first module
│                     policies (Idea, IdeaPlan, IdeaTask, IdeaCategory,
│                     LabWorkspace, LabSnippet)
├── Rules/            SlugRule, its three subclasses (IdeaSlug, PlanSlug,
│                     SnippetSlug) and PostSlug, which implements
│                     ValidationRule directly
├── Support/          DashboardNavigation, SettingsNavigation, MarkdownRenderer,
│                     Seo, FeedCorpus, FeedPersonas, HttpErrorCatalog
└── Views/pages/      Livewire single-file components (⚡ prefix)
```

Seven migrations sit behind the two newest modules, bringing
`database/migrations/` to 38; `IdeaCategorySeeder` and `LabSnippetSeeder` are
idempotent by slug and registered in `DatabaseSeeder`.

The `⚡` prefix marks a **Livewire single-file component**. These are anonymous
classes living beside their template, which is why tests address them by
**name string** (`'pages::blog.show'`) and never by `::class`.

The view layer mirrors the audience-first shape of the URL space:

```
resources/views/
├── components/
│   ├── auth/        the three guest Fortify dialogs and their bundle
│   ├── feed/        the feed's card, composer, filters, inspector, rail, avatar
│   ├── communities/ the community directory card
│   └── app/         everything else, 73 files
│       ├── home/          guest chrome — header, footer
│       ├── ui/            shared primitives, incl. the dashboard shell (41)
│       ├── icon/          glyph-only components
│       ├── ideas/         card, status-badge — module-owned, so not under ui/
│       ├── dev-lab/       code-pane, overview, capabilities, access
│       ├── ai-hub/ marketplace/   the other two modules' pieces
│       └── user|seller|admin/   each role's three chrome components
├── layouts/
│   ├── guest/home     the default shell
│   ├── auth/          every Fortify screen
│   └── app|seller|admin/dashboard   the three workspace layouts
└── pages/             62 Blade files, 57 of them Livewire single-file
                       components (⚡ prefix); the rest are the four mid-flow
                       auth screens and settings/layout.blade.php
```

Almost every component is namespaced under `app/` so `<x-app.ui.modal>` can never
be shadowed by a package's `<x-modal>`. The eleven files outside it (`auth/`,
`feed/`, `communities/`) are module vocabulary that predates the prefix being
applied consistently, and are documented as the exception in
[`docs/COMPONENTS.md`](docs/COMPONENTS.md) rather than renamed for no change in
behaviour. The three workspace layouts compose one shell —
`components/app/ui/dashboard-shell.blade.php` — rather than each carrying a copy
of ~270 lines of sidenav, drawer and focus-trap machinery.

Full breakdown: [`docs/COMPONENTS.md`](docs/COMPONENTS.md).

---

## Documentation

| Document | Answers |
| --- | --- |
| [`docs/PRD.md`](docs/PRD.md) | What is being built and for whom |
| [`docs/TRD.md`](docs/TRD.md) | Technical requirements and constraints |
| [`docs/UI-UX-BRIEF.md`](docs/UI-UX-BRIEF.md) | Design language, tokens, type scale |
| [`docs/APP-FLOW.md`](docs/APP-FLOW.md) | User journeys and state transitions |
| [`docs/SCHEMA.md`](docs/SCHEMA.md) | Every table, column and index |
| [`docs/ERD.md`](docs/ERD.md) | Entity relationships |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | How the code is organised and why |
| [`docs/COMPONENTS.md`](docs/COMPONENTS.md) | Every Blade and Livewire component |
| [`docs/SOFT-UI.md`](docs/SOFT-UI.md) | The dashboard design system — tokens, states, rules |
| [`docs/AI-WORKFLOW.md`](docs/AI-WORKFLOW.md) | AI Workflow — the split-view board + flow-graph specification for autonomous runs |
| [`docs/ROUTES.md`](docs/ROUTES.md) | Every route, its middleware and its guard |
| [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md) | Roles, permissions and plans |
| [`docs/API.md`](docs/API.md) | Intended API surface — **not implemented** |
| [`docs/Idea.md`](docs/Idea.md) | Upstream spec for Ideas, Plans & Tasks — **partially implemented**, with a shipped-state section and a deviation table |
| [`docs/lab.md`](docs/lab.md) | Upstream spec for the Developer Lab — **partially implemented**, and the security document for the sandbox |
| [`docs/audit/PHASE-0-AUDIT.md`](docs/audit/PHASE-0-AUDIT.md) | Verified state before changes |

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for a record of notable changes, organised by
release. The `Unreleased` entry covers the roles/auth dashboard redesign work.

---

## Contributing

All changes must pass the quality gates before being considered finished:

| Gate | Command |
| --- | --- |
| Tests | `php artisan test` |
| Types | `vendor/bin/phpstan analyse` (level 7) |
| Style | `vendor/bin/pint --dirty --format agent` |
| Build | `npm run build` |

`composer test` runs all four gates in order: config clear, Pint check mode,
PHPStan, then the Pest suite. On this machine `php` and `composer` are not on
`PATH` inside the sandboxed shell — use `bash /tmp/phpx.sh` and pass
`-d memory_limit=1G` to PHPStan.

---

## Design notes

Three decisions that shape most of the code, recorded here because they are
non-obvious and easy to undo by accident.

### Permissions are derived, never stored

An account has a **role**. A role has **permissions**. Permissions are computed
from the role by matching against a single enum — there is no
`permissions` table and no pivot. A role can be redefined in one file and every
guard, every sidebar entry and every test follows.

The consequence: `AccountRole::Admin->permissions()` returning
`AccountPermission::cases()` means adding a permission automatically grants it
to admins, and **that is the intended behaviour** — an admin is defined as
"can do everything", not as a list that must be maintained.

### Navigation cannot disagree with its own routes

`app/Support/DashboardNavigation.php` is the single definition of the
dashboard sidebar. Each entry declares the permission its route requires. The
`routes/web.php` guard and the rendered sidebar both read from it, so a menu
item **cannot** be visible to an account whose route would refuse it. Before
this existed, a nav link and its route guard were two separate statements of
the same rule, and they drifted.

### Modals go through one shell

Eight modals existed as copies of each other. They are now all
`<x-app.ui.modal>` + `<x-app.ui.modal.actions>` + `<x-app.ui.modal.trigger>`.
The value is not avoided duplication — it is that the four decisions every modal
makes (reopen on validation failure, accessible name, destructive signalling,
focus behaviour) are made **once**. Copy-pasted dialogs do not drift loudly; the
odd one out is invisible until someone is stuck in a dialog they cannot close.

Measured effect: this migration surfaced four **non-existent icon names**
(`storefront`, `book-open-text`, `folder-git-2`, `layout-grid`) that threw hard
exceptions on render. They had been latent in copied markup.

---

## Licence

Released under the MIT licence.
