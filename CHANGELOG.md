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
  task list, theme toggle, welcome card, why-hire-me); seller payouts component.
- **Soft UI system**: `docs/SOFT-UI.md` design system with tokens, states, and rules;
  `docs/UI-UX-BRIEF.md` design language and type scale.
- **About page**: `⚡about.blade.php` Livewire single-file component with hero,
  experience, work/writing, marquee stat band, and CTA sections, plus matching CSS.
- **Theme system**: theme boot partial, theme toggle, dark mode variants, and
  `docs/themes.md`.
- **Modules**: Marketplace show page; Developer Lab, AI Knowledge Hub, and Ideas
  placeholder routes claimed in the URL space.
- **Tests**: new feature tests for About (CTA marquee, experience, hero, marquee stat
  band, work/writing), Dashboard (composition, navigation, shell, taxonomy), Routing
  structure, and Theme system.

### Changed

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
