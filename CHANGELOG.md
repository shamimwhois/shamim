# Changelog

All notable changes to DevFolio are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-25

First production release: the portfolio, the blog, the authoring workspace, and
the delivery pipeline.

### Added

- Public portfolio surface: landing page, about page, blog index with live
  search and category/tag filters, and blog detail with table of contents,
  reading progress, related posts, and social sharing.
- Server-rendered RSS feed, XML sitemap, and `robots.txt` endpoint.
- Author dashboard with content metrics, post list and editor, comment
  moderation queue, and inline category/tag management.
- Post lifecycle actions for creating, updating, publishing, unpublishing,
  archiving, and deleting posts, with queue-backed taxonomy counter
  reconciliation.
- Authentication through Laravel Fortify with two-factor authentication,
  passkeys, email verification, password reset, and profile/security settings.
- Team membership, invitations, and expiration pruning.
- SEO metadata for Open Graph and Twitter Cards, generated from a single
  server-side source of truth.
- Pest feature and unit suites, Larastan at level 7, and Pint formatting.
- GitHub Actions test workflow and a production deploy workflow (SSH + rsync)
  with an `/up` health check.
- `.env.production.example` documenting production database, mail, session,
  queue, and logging settings.
- `README.md` and `CHANGELOG.md`.

### Changed

- Production hardening: `AppServiceProvider` forces `APP_DEBUG=false` when the
  application runs in production, in addition to the environment default.
- Production deploys install dependencies with `--no-dev`, so all test and
  debug tooling remains a local/CI concern only.
- `laravel/tinker` moved from `require` to `require-dev`; the REPL is no longer
  installed on production servers.
- `composer setup` now creates `database/database.sqlite` when it is missing,
  so a fresh clone can migrate without manual steps.
- PHPStan runs with `--memory-limit=1G` so the level 7 analysis fits in CI.
- Expanded `.gitignore` for environment files, local databases, caches, logs,
  OS files, and local agent state.

### Removed

- `laravel/sail` and `laravel/pao`, which were unused in this project.
- Unused starter-kit layouts: `layouts/app/header`, `layouts/dashboard`,
  `layouts/auth/card`, and `layouts/auth/split`.
- `public/robots.txt`, which shadowed the dynamic `RobotsController` route and
  served stale directives.

### Fixed

- Moved the XML declaration for the RSS feed and sitemap into their
  controllers. A literal `<?xml` in a Blade file is parsed as a PHP open tag
  when `short_open_tag` is enabled, which corrupted the compiled view.
- Corrected the team-invitation re-acceptance test to assert the 404 response
  Livewire produces, instead of expecting an exception the framework handles.

### Security

- Updated `livewire/livewire` from 4.4.7 to 4.4.8 to resolve
  [GHSA-mhf7-xp6r-6qrc](https://github.com/advisories/GHSA-mhf7-xp6r-6qrc)
  (authorization bypass in `#[Authorize]`). `composer audit` is clean.
