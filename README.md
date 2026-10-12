# DevFolio

A portfolio and technical-writing platform for a senior developer — long-form
blog, role-scoped dashboard, team workspaces, and the documentation that
describes all of it.

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
  `admin`) with an additive permission hierarchy, each with its own dashboard
  and sidebar.
- **Documented** — PRD, TRD, UI/UX brief, application flow, schema, ERD,
  architecture, components, routes, permissions and an API design.

**It is not (yet):**

- A multi-tenant SaaS. Teams exist and work; there is no billing, no
  organisation hierarchy, no per-team theming.
- A storefront. The `seller` role, its plan limits and its permissions are
  **fully defined and enforced**, but no `Product` model exists, so there is
  nothing to sell. This is a deliberate half-built layer — see
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
| `test@example.com` | `admin` | `/dashboard/admin` |
| `seller@example.com` | `seller` | `/dashboard/seller` |
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
runs PHPStan, then runs the suite. It is what CI should call — and it will fail
on the known failing test described below.

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
| Tests | `php artisan test` | **169 passing**, 403 assertions |
| Types | `vendor/bin/phpstan analyse` | **No errors** (level 7) |
| Style | `vendor/bin/pint --dirty` | **Clean** |
| Build | `npm run build` | **Green** |

There is **one known failing test**:
`Tests\Feature\Teams\TeamInvitationTest > accepted team invitations cannot be
accepted again`. It is a defect in the inherited starter-kit test, not in the
application — Livewire swallows the `ModelNotFoundException` before Pest's
`expectException` can observe it. It is left in place rather than deleted,
because `AGENTS.md` forbids removing tests without approval. See
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) §9.

---

## Project layout

```
app/
├── Actions/          Single-purpose write operations, grouped by domain
│   ├── Comments/     ApproveComment, RejectComment
│   ├── Fortify/      CreateNewUser, ResetUserPassword
│   ├── Posts/        Create, Update, Publish, Archive, Delete, Sync…
│   └── Teams/        CreateTeam
├── Concerns/         Reusable model traits (roles, teams, slugs, reading time)
├── Data/             Read-only DTOs: AuthorProfile, PostMetrics, TeamPermissions
├── Enums/            AccountRole, AccountPermission, RolePlan, PostStatus…
├── Http/
│   ├── Controllers/  Feed, Sitemap, Robots — the three non-Livewire responses
│   ├── Middleware/   EnsureAccountPermission, EnsureTeamMembership
│   └── Responses/    Per-flow Fortify redirect responses
├── Models/           User, Post, Category, Tag, Comment, Team, Membership…
├── Policies/         PostPolicy, TeamPolicy, UserPolicy
├── Support/          DashboardNavigation, MarkdownRenderer, Seo
└── Views/pages/      Livewire single-file components (⚡ prefix)
```

The `⚡` prefix marks a **Livewire single-file component**. These are anonymous
classes living beside their template, which is why tests address them by
**name string** (`'pages::blog.show'`) and never by `::class`.

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
| [`docs/ROUTES.md`](docs/ROUTES.md) | Every route, its middleware and its guard |
| [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md) | Roles, permissions and plans |
| [`docs/API.md`](docs/API.md) | Intended API surface — **not implemented** |
| [`docs/audit/PHASE-0-AUDIT.md`](docs/audit/PHASE-0-AUDIT.md) | Verified state before changes |

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
`<x-modal>` + `<x-modal.actions>` + `<x-modal.trigger>`. The value is not
avoided duplication — it is that the four decisions every modal makes (reopen
on validation failure, accessible name, destructive signalling, focus
behaviour) are made **once**. Copy-pasted dialogs do not drift loudly; the odd
one out is invisible until someone is stuck in a dialog they cannot close.

Measured effect: this migration surfaced four **non-existent icon names**
(`storefront`, `book-open-text`, `folder-git-2`, `layout-grid`) that threw hard
exceptions on render. They had been latent in copied markup.

---

## Licence

Released under the MIT licence.
