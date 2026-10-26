# DevFolio

A self-hosted portfolio and technical publishing platform for a single author, built with Laravel, Livewire, and Tailwind CSS. It pairs a hiring-manager-facing portfolio with a long-form blog, an authenticated writing workspace, and a comment moderation queue.

The product specification lives in [`docs/`](docs/). Start with [`docs/PRD.md`](docs/PRD.md), then [`docs/TRD.md`](docs/TRD.md) and [`docs/APP-FLOW.md`](docs/APP-FLOW.md).

---

## Stack

| Layer | Technology |
| --- | --- |
| Runtime | PHP 8.3+ (tested on 8.3–8.5) |
| Framework | Laravel 13 |
| UI | Livewire 4 single-file components, Flux UI 2, Tailwind CSS 4 |
| Auth | Laravel Fortify, two-factor authentication, passkeys |
| Build | Vite 8 via `vite-plus`, `laravel-vite-plugin` |
| Database | SQLite locally, MySQL/MariaDB (or SQLite) in production |
| Quality | Pest 5, Larastan (PHPStan level 7), Pint |
| CI/CD | GitHub Actions — test, build, rsync over SSH, release tasks |

---

## Requirements

- PHP **8.3+** with the extensions required by Laravel and `pdo_sqlite`
- Composer **2**
- Node.js **22** and npm
- SQLite (default) or MySQL/MariaDB

---

## Local development

```bash
# Install dependencies, create .env, generate a key, migrate, build assets
composer setup

# Start the app, queue listener, and Vite dev server together
composer dev
```

`composer setup` copies `.env.example` to `.env`, creates `database/database.sqlite` when it is missing, runs migrations, and builds the frontend. To seed demo content:

```bash
php artisan db:seed
```

The app is served at `http://localhost:8000` by `php artisan serve`.

### Common commands

| Command | Purpose |
| --- | --- |
| `composer dev` | Serve the app, run the queue listener, and Vite together |
| `composer test` | Clear config, run Pint, PHPStan, and the Pest suite |
| `composer lint` | Format PHP with Pint |
| `composer lint:check` | Verify formatting without changing files |
| `composer types:check` | Run PHPStan/Larastan |
| `php artisan test` | Run the Pest suite only |
| `npm run dev` | Vite dev server with hot reload |
| `npm run build` | Production asset build |
| `php artisan migrate` | Apply migrations |
| `php artisan db:seed` | Seed local content |

---

## Routes at a glance

| Area | Routes |
| --- | --- |
| Public | `/`, `/blog`, `/blog/{post:slug}`, `/about`, `/feed.xml`, `/sitemap.xml`, `/robots.txt` |
| Auth | Fortify routes: login, register, password reset, email verification, two-factor challenge, passkeys |
| Author dashboard | `/dashboard`, `/dashboard/posts`, `/dashboard/posts/create`, `/dashboard/posts/{post}/edit`, `/dashboard/comments`, `/dashboard/taxonomy` |
| Settings | `/settings/profile`, `/settings/appearance`, `/settings/security`, `/settings/teams` |
| Health | `/up` |

Dashboard and settings routes require an authenticated, verified account. Posts are owned by their author; see `app/Policies/PostPolicy.php`.

---

## Project structure

```
app/
├── Actions/        Single-purpose write operations (Posts, Comments, Teams, Fortify)
├── Concerns/       Reusable model traits (slugs, reading time, teams, validation rules)
├── Data/           Readonly DTOs handed to views
├── Enums/          Backed enums for post status, comment status, team roles
├── Http/           Controllers, middleware, Fortify response classes
├── Models/         Eloquent models
├── Observers/      Post counter reconciliation
├── Policies/       Authorization
├── Providers/      Application and Fortify service providers
├── Rules/          Custom validation rules
└── Support/        Markdown rendering and SEO metadata

resources/views/
├── pages/          Livewire page components (⚡ prefix)
├── components/     Blade and Livewire components
├── layouts/        App, auth, dashboard, and public layouts
└── partials/       Head and settings fragments

routes/             web, settings, and console (schedule) routes
config/             Framework configuration + portfolio.php (site content)
database/           Migrations, factories, seeders
docs/               Product, technical, schema, and flow documentation
tests/              Pest feature and unit tests
```

Conventions used throughout the codebase:

- Livewire pages are single-file components at `resources/views/pages/**/⚡name.blade.php`.
- Write operations live in `app/Actions/<Domain>/<Verb><Noun>.php`.
- Models use PHP attributes (`#[Fillable]`, `#[Hidden]`) and a `casts()` method.
- A new public page must be registered with `Route::livewire()` in `routes/web.php`.

---

## Production deployment

Production runs with debug output and development tooling removed:

- `APP_ENV=production` and `APP_DEBUG=false`. `AppServiceProvider` also forces `app.debug` to `false` at boot, so a misconfigured environment cannot leak stack traces.
- Deployment installs dependencies with `composer install --no-dev`, so Pest, PHPStan, Pint, Pail, Tinker, Faker, Mockery, and the rest of `require-dev` never reach the server.
- Config, routes, and views are cached with `php artisan optimize`.
- The production environment template enables encrypted sessions and `Secure` cookies.

### Server preparation (once per host)

The target directory must contain a real `.env` that survives releases. Copy the template and fill it in:

```bash
ssh deploy@example.com
cd /var/www/devfolio
cp .env.production.example .env
php artisan key:generate
```

Requirements on the host:

- PHP 8.3+ with the same extensions used locally, plus Composer 2
- Nginx/Apache docroot pointed at `public/`
- Writable `storage/` and `bootstrap/cache/`
- MySQL/MariaDB database (or an absolute SQLite path), migrated by the pipeline
- A queue worker, because team invitation notifications are queued:
  `php artisan queue:work --tries=1 --timeout=90` under systemd or Supervisor
- The scheduler cron entry:
  `* * * * * cd /var/www/devfolio && php artisan schedule:run >> /dev/null 2>&1`

### GitHub Actions pipeline

Two workflows are included:

| Workflow | Trigger | What it does |
| --- | --- | --- |
| `.github/workflows/tests.yml` | Push to `main`, pull requests, or called by deploy | Installs dependencies, builds assets, runs Pint, PHPStan, and Pest |
| `.github/workflows/deploy.yml` | Push a `v*.*.*` tag, or manual dispatch | Runs the test workflow, refuses a tag without a matching `CHANGELOG.md` entry, builds assets, rsyncs the release, installs production dependencies, migrates, optimizes, restarts workers, and health-checks `/up` |

Configure the **production** environment in GitHub with these secrets:

| Secret | Required | Description |
| --- | --- | --- |
| `SSH_HOST` | Yes | Server hostname or IP |
| `SSH_USER` | Yes | SSH user with write access to the deploy path |
| `SSH_PRIVATE_KEY` | Yes | Private key for that user (no passphrase, or use an agent) |
| `DEPLOY_PATH` | Yes | Absolute path to the application, e.g. `/var/www/devfolio` |
| `SSH_PORT` | No | SSH port; defaults to `22` |

Also set an `APP_URL` **environment variable** (not a secret) to the public URL, e.g. `https://example.com`. The deploy job uses it for the health check and as the environment URL. The HTTP check only runs when `APP_URL` is set.

The pipeline deploys the working tree without `.git`, `.github`, `tests`, `docs`, `node_modules`, or `vendor`. It preserves the server's `.env`, `storage/`, and `bootstrap/cache/`. Production dependencies are installed on the server, so the platform PHP version is the one that matters.

### Releasing

```bash
git tag v1.0.0
git push origin v1.0.0
```

The tag must match `v*.*.*`. A failed test job blocks the deploy. The **production** environment can be protected with required reviewers in repository settings.

### Notes and limitations

- The pipeline expects the host to have Composer available; it does not ship a vendor directory.
- Database backups and zero-downtime release directories are infrastructure concerns and are not handled by this workflow. Run migrations during a low-traffic window until you add a release-directory strategy.
- Configure TLS (Let's Encrypt or your host's certificate) and force HTTPS at the web server. The app sets secure session cookies but does not force the URL scheme.
- Keep `APP_KEY` stable. Changing it invalidates sessions and encrypted data.

---

## Quality gates

`composer test` is the same command CI runs. It executes:

1. `php artisan config:clear`
2. `pint --parallel --test`
3. `phpstan analyse --memory-limit=1G`
4. `php artisan test` (Pest)

Tests use an in-memory SQLite database and the array cache/session drivers (see `phpunit.xml`), so they never touch development data.

---

## Documentation

| Document | Contents |
| --- | --- |
| [`docs/PRD.md`](docs/PRD.md) | Product requirements, personas, success metrics |
| [`docs/TRD.md`](docs/TRD.md) | Architecture decisions and technical design |
| [`docs/APP-FLOW.md`](docs/APP-FLOW.md) | User journeys, route map, state model |
| [`docs/SCHEMA.md`](docs/SCHEMA.md) | Table definitions and relationships |
| [`docs/ERD.md`](docs/ERD.md) | Entity relationship diagram |
| [`docs/UI-UX-BRIEF.md`](docs/UI-UX-BRIEF.md) | Visual language and accessibility brief |
| [`CHANGELOG.md`](CHANGELOG.md) | Release history |

---

## License

MIT. See `composer.json` for package metadata.
