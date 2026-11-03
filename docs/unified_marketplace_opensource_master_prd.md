# Master PRD --- Unified Marketplace & Open-Source Package Hub

**Purpose:** Merge the supplied software-marketplace homepage, Laravel
package catalogue, and package documentation page into one cohesive
developer platform.\
**Implementation target:** Extend the existing repository; do not
rebuild from scratch.\
**Suggested stack:** Laravel 13, PHP 8.4+, existing Livewire version,
Blade components, Tailwind CSS 4, Pest PHP.\
**Core principle:** One shared Product Engine, with distinct workflows
for commercial products and open-source packages.

## 1. Product vision

Users discover software products, packages, plugins, themes, templates,
integrations, SaaS tools, and open-source projects in one place.
Publishers manage listings, versions, artifacts, documentation,
changelogs, pricing, and analytics. Customers can install free packages,
purchase commercial products, manage subscriptions/licenses, and access
protected downloads.

Merge the three reference designs as follows: - **Marketplace
homepage:** prominent search, featured products, category navigation,
recommendations, recent items, price-aware cards, publisher CTA, and
footer. - **Package catalogue:** categorized package grids, search,
compact descriptions, "Read docs" actions, and category groupings. -
**Package detail:** focused package hero, install command, compatibility
metadata, documentation sidebar, structured overview/features, version
history, changelog, support, and licensing.

The product must feel like one system, not two websites combined.

## 2. Non-negotiable implementation rules

1.  Audit the existing repository before editing.
2.  Preserve working authentication, data, routes, features,
    integrations, and brand identity.
3.  Reuse existing models/components where possible; do not create
    duplicate implementations.
4.  Replace hardcoded listings, statistics, categories, prices,
    versions, and documentation with database-backed data.
5.  Every visible control must work or be clearly marked as
    unavailable/not configured.
6.  Do not fabricate ratings, reviews, install counts, compatibility
    claims, revenue, or download counts.
7.  Never treat a browser redirect as proof of payment; verify payment
    server-side.
8.  Never expose paid/private artifacts through public URLs.
9.  Implement incrementally and run Pest tests after each phase.
10. Preserve current URLs or provide documented redirects for changes.

## 3. Roles

-   **Guest:** browse public listings, search, read public docs, view
    publishers, register/sign in.
-   **Customer:** manage purchases, subscriptions, licenses, downloads,
    favorites, reviews, and support.
-   **Publisher:** manage profile, products, package metadata, releases,
    artifacts, documentation, pricing, changelog, and analytics.
-   **Moderator:** review submissions, handle reports, request changes,
    reject, suspend, or unpublish listings with audit trails.
-   **Administrator:** manage users, permissions, categories, product
    types, moderation, provider configuration, orders, entitlements,
    licenses, and audit logs.

Enforce authorization through policies and server-side checks, not
merely hidden UI controls.

## 4. Information architecture and menus

### Global navigation

Home · Marketplace · Open Source · Packages · Categories · Collections ·
Publishers · Docs/Help · Search · Publish · Notifications · Account.

### Marketplace categories

Business & Operations, E-commerce, Marketing & SEO, Finance, Education,
Healthcare, Productivity, Developer Tools, AI & Automation, Design &
Media, Hosting & Infrastructure, Mobile, Industry Solutions.

### Package categories

Content & Media, Commerce, Social & Engagement, Workflow & Lifecycle,
Data & Modeling, Authentication & Security, Infrastructure, APIs &
Integrations, Developer Experience, Testing & Quality, Observability,
AI/ML, Deployment & Operations.

### Publisher dashboard

Overview, Products, Drafts, Pending Review, Releases, Documentation,
Media/Artifacts, Sales, Analytics, Support, Publisher Profile, Settings.

### Customer dashboard

Purchases, Orders/Receipts, Subscriptions, Licenses, Activations,
Downloads, Favorites, Followed Publishers, Support, Notifications,
Account Settings.

### Administration

Overview, Products, Review Queue, Publishers, Users/Roles,
Categories/Tags, Reports, Orders, Payments/Webhooks, Refunds,
Entitlements, Licenses, Security Reports, Site Settings, Audit Logs.

Categories and menu counts must be database-driven. Do not show
meaningless zero-count badges everywhere.

## 5. Design system

### Visual direction

Use the references as inspiration: premium marketplace layout, warm
neutral light surfaces, restrained violet/cobalt/orange accents, tidy
product cards, compact metadata, and a focused documentation layout.

### Light and dark themes

-   **Light:** warm off-white background, white surfaces, dark readable
    text, soft borders, subtle violet/blue/orange accents.
-   **Dark:** deep navy/charcoal background, elevated dark surfaces,
    muted readable text, accessible accent colors.
-   Persist theme for authenticated users; use a safe client preference
    for guests.
-   Prevent flash-of-wrong-theme on load.
-   Test code blocks, tables, cards, modals, menus, and forms in both
    themes.

### Reusable components

Global header, responsive navigation, search panel, product/package
card, category chip, filters, sort menu, publisher card, compatibility
badge, price/access badge, version selector, copy-install-command
button, docs table of contents, code block, changelog timeline, CTA,
modal, toast, skeleton, empty state, error state, pagination.

### Responsive behavior

-   Desktop: multi-column catalogue, filter rail, docs sidebar.
-   Tablet: fewer columns, collapsible filters.
-   Mobile: single-column cards, navigation drawer, filter sheet,
    horizontally scrollable metadata, readable code blocks.
-   Keyboard-accessible controls, visible focus states, semantic
    headings, adequate contrast, reduced-motion support.

Suggested tokens (adapt to existing design tokens): `--color-bg`,
`--color-surface`, `--color-surface-raised`, `--color-border`,
`--color-text`, `--color-muted`, `--color-primary`, `--color-accent`,
`--color-danger`. Do not scatter raw color values across components.

## 6. Home and marketplace

### Homepage sections

1.  Hero search: "Find software, packages, and tools for your stack."
2.  Browse Marketplace / Explore Open Source / Publish actions.
3.  Featured products and packages.
4.  Recently viewed items, only when history exists.
5.  Recommendations based on real signals and user preferences.
6.  Popular categories.
7.  Recently updated packages and new releases.
8.  Curated/verified listings only when genuinely reviewed.
9.  Publisher call-to-action.
10. Help, trust/safety, and footer links.

### Marketplace catalogue

Search title, summary, publisher, tags, and stack. Filter by category,
type, price/access model, license, rating (only if reviews exist),
compatibility, update date, and publisher. Sort by relevance, recently
updated, newest, popularity, or price where meaningful. Keep filters in
query parameters for shareable URLs and browser navigation.

Cards show real title, summary, category, publisher, image, price/access
model, compatibility, and genuine review/download signals when
available.

### Open-source catalogue

Distinguish **Open Source**, **Free**, and **Commercial** correctly;
free does not automatically mean open source. Show repository URL,
license identifier, latest stable version, last release date,
maintenance status, verified compatibility, and installation command
when available. Filter by framework, PHP/Laravel version, category,
license, release recency, maintenance status, and price. Mark
deprecated, archived, or security-restricted packages clearly.

## 7. Unified Product Engine

Use a shared product identity and lifecycle, with type-specific
fields/records rather than an unstructured catch-all.

### Shared fields

Publisher, product type, title, slug, short/full description, category,
tags, media, demo/docs/support URLs, license/access model, visibility,
moderation status, SEO metadata, timestamps, and real aggregate metrics.

### Product types

Software application, PHP/Laravel package, Composer package,
plugin/extension, theme/template, API/integration, SaaS product, MCP
listing, open-source repository, and configurable future types.

### Lifecycle

Draft → submitted → under review → approved/published, or
rejected/requested changes. Releases are versioned. Risk-sensitive
updates can require review. Products may be deprecated, archived, or
suspended with clear customer impact and audit history.

## 8. Package catalogue, registry, and releases

### Package metadata

-   Vendor/package name and unique registry identity.
-   Repository URL, category, description, tags.
-   PHP constraints and verified Laravel compatibility.
-   Required PHP extensions/dependencies.
-   Composer install command.
-   License identifier/text/URL.
-   Stable version, prereleases, release date, changelog.
-   Requirements, migration notes, support/maintenance status.
-   Artifact checksum and signature metadata if implemented.

### Release management

Validate versions, record release notes and dependency constraints,
associate documentation with versions, and preserve immutable published
artifacts. Never silently replace an artifact behind an existing
version. Support stable, prerelease, deprecated, and yanked states with
upgrade/breaking-change notes.

### Composer workflow

Public packages use a valid Composer installation workflow.
Commercial/private packages use an authenticated registry/repository
workflow. Provide copyable commands, secure token/device authentication
instructions, and actionable errors. Never expose long-lived credentials
in client code or logs.

**Roadmap:** (1) catalogue metadata, versions, docs, protected
downloads; (2) authenticated private Composer repository and entitlement
checks; (3) device authorization, install-time license validation,
release sync; (4) npm/MCP ecosystems after Composer is stable.

## 9. Package detail and documentation

### Header

Breadcrumb, package name/type, publisher, license/access badge, latest
version, release date, verified compatibility, install command with copy
action, repository/demo/support links, and purchase CTA when paid.

### Sections

Overview, Installation, Quick Start, Configuration, Features, Usage,
Examples, API Reference, Requirements/Compatibility, Version History,
Changelog, Security, License, Support, FAQ.

Support versioned docs, Markdown or controlled rich text, sanitized
rendering, code blocks with copy buttons, anchors, tables, callouts,
table of contents, documentation search, preview, and draft/published
states. Do not expose private drafts. Hide empty sections rather than
inventing content.

## 10. Commerce, payments, and entitlements

### Access models

Open-source/free, free registration-required, one-time/lifetime,
subscription, and paid license with defined update/support duration.
Keep open-source licensing distinct from paid support or convenience
services.

### Checkout flow

1.  Customer selects product/plan.
2.  Server calculates price, currency, discount, and applicable fees.
3.  Create pending order.
4.  Start configured provider flow.
5.  Verify signed webhook/callback server-side.
6.  Validate amount, currency, merchant/reference, state, and
    idempotency.
7.  Mark paid only after verification.
8.  Issue entitlement/license if applicable.
9.  Enable protected download/registry access.
10. Send receipt/notification and write audit records.

Use replaceable provider adapters, including bKash, Nagad, and Binance
Pay where officially supported and configured. Encrypt provider
credentials at rest, restrict access, and never expose secrets to the
browser. Provider callbacks must be signature-verified and idempotent.

Support configurable marketplace commission, including the previously
specified 5% fee where applicable. Record gross, platform fees, provider
fees when available, refunds, seller proceeds, and currency. Use
auditable ledger-style records. Do not mark payouts complete without
provider confirmation.

Subscriptions need renewal, expiry, cancellation, failed renewal, grace
period, and access-revocation states with clear user messaging.

## 11. Licenses and protected downloads

Issue entitlement/license records only after verified payment or an
eligible free grant. Track product, customer, order, version policy,
status, issue/expiry dates, activation limits, and revocation. Add
rate-limited license validation endpoints, audited
activation/deactivation, and authenticated or signed time-limited
downloads. Enforce entitlement at download and private-registry/install
checkpoints. Handle refunds, chargebacks, expiry, and revocation under
documented policy. Do not claim client-side checks prevent all
circumvention.

## 12. Publisher and customer dashboards

### Publisher

Overview, listing statuses, releases, compatibility, sales/fees, genuine
downloads/installs, reviews, support requests, security/moderation
alerts, product CRUD, media/artifacts, documentation, pricing, release
publishing, and date-filtered analytics. Distinguish server-verified
installs from downloads.

### Customer

Purchases, subscriptions, licenses/activations, private package access,
download history, receipts, favorites, followed publishers, reviews,
support, release/security notices, and preferences. Minimize access to
other customers' personal data.

## 13. Admin and trust

Moderation queue, product/publisher review, user/role management,
categories/tags, compatibility metadata, scam/malware/infringement
reports, version yanking/deprecation, payment/webhook inspection,
refunds, licenses, entitlements, provider configuration, settings, and
audit logs.

Every privileged decision records actor, reason, timestamp, and affected
resource. Uploaded artifacts must never execute inside the web request
process. Use private storage for commercial files and an isolated
malware scanning/quarantine workflow when available.

## 14. Search and recommendations

Search products, packages, publishers, categories, tags, and docs.
Support accessible keyboard navigation, shareable filters, query
validation, pagination, and useful no-results suggestions. Rank using
text relevance, verified metadata, freshness, category, and genuine
engagement. Recommendations must be explainable enough to debug and
respect privacy settings. Trending must be time-windowed and
abuse-resistant.

## 15. Suggested Laravel architecture

Adapt to current repository conventions; do not reorganize blindly.

``` text
app/
  Domain/Catalog/
  Domain/Packages/
  Domain/Releases/
  Domain/Documentation/
  Domain/Commerce/
  Domain/Payments/
  Domain/Entitlements/
  Domain/Licensing/
  Domain/Publishers/
  Domain/Moderation/
  Livewire/Marketplace/
  Livewire/OpenSource/
  Livewire/Package/
  Livewire/Documentation/
  Livewire/Publisher/
  Livewire/Customer/
  Livewire/Admin/
  Models/
  Policies/
  Jobs/
  Events/
  Listeners/
  Notifications/
resources/views/
  layouts/
  components/
  livewire/marketplace/
  livewire/packages/
  livewire/docs/
  livewire/publisher/
  livewire/customer/
  livewire/admin/
database/migrations/
database/factories/
database/seeders/
tests/Feature/
tests/Unit/
tests/Browser/
docs/
```

Suggested domain entities: User, PublisherProfile, Product, ProductType,
Category, Tag, ProductMedia, PackageMetadata, PackageRelease,
ReleaseArtifact, DocumentationPage/Version, ProductPrice, Plan,
Subscription, Order, OrderItem, Payment, PaymentWebhookEvent,
Entitlement, License, LicenseActivation, DownloadRecord,
InstallVerificationRecord, Review, Favorite, PublisherFollow,
SupportTicket, ProductReport, ModerationAction, AuditLog.

These are conceptual entities. Reuse equivalent existing models and
avoid duplicate tables.

### Responsibility boundaries

-   Models/relationships: persistence and domain relationships.
-   Livewire: UI state and interactions.
-   Policies: authorization.
-   Form Requests/validators: input validation.
-   Services: complex business operations and transactions.
-   Jobs: slow processing.
-   Events/listeners: decoupled side effects.
-   Notifications: user-facing alerts.
-   Blade components: consistent presentation.

Avoid oversized Livewire components, needless service classes, and
business logic hidden in templates.

## 16. Suggested routes

### Public

`/`, `/marketplace`, `/marketplace/{category}`, `/products/{slug}`,
`/open-source`, `/packages`, `/packages/{vendor}/{name}`,
`/packages/{vendor}/{name}/versions/{version}`,
`/packages/{vendor}/{name}/docs/{page?}`, `/publishers/{username}`,
`/categories/{slug}`, `/search`, `/docs`, `/legal/terms`,
`/legal/privacy`, `/legal/licenses`.

### Customer

`/dashboard`, `/account/purchases`, `/account/subscriptions`,
`/account/licenses`, `/account/downloads`, `/account/favorites`,
`/account/orders`, `/account/settings`.

### Publisher

`/publisher`, `/publisher/products`, `/publisher/products/create`,
`/publisher/products/{product}/edit`,
`/publisher/products/{product}/releases`,
`/publisher/products/{product}/documentation`, `/publisher/analytics`,
`/publisher/sales`, `/publisher/support`.

### Admin

`/admin`, `/admin/products`, `/admin/reviews`, `/admin/publishers`,
`/admin/categories`, `/admin/reports`, `/admin/orders`,
`/admin/payments`, `/admin/licenses`, `/admin/settings`,
`/admin/audit-logs`.

Reconcile these with existing routes; preserve URLs or add redirects.

## 17. Security, data integrity, and performance

-   Validate all inputs server-side; apply policies to every private
    operation.
-   Protect against IDOR, mass assignment, CSRF, XSS, unsafe Markdown,
    and direct artifact access.
-   Validate uploads by size, type, extension, and storage location.
-   Encrypt payment secrets, redact logs, verify webhook signatures, and
    enforce idempotency.
-   Rate-limit login, checkout, downloads, reviews, license checks, and
    abuse-prone search.
-   Use foreign keys, unique constraints, transactions, and indexes for
    package identity, version, slug, status, category, and search
    fields.
-   Use pagination, eager loading, query profiling, cache invalidation,
    queues, retries, and failed-job handling.
-   Queue media processing, notifications, release synchronization, and
    expensive analytics.
-   Document storage, scheduler, queues, backups, restore procedures,
    monitoring, and rollback.
-   Keep private artifact metadata separate from publicly accessible
    media.

## 18. Pest PHP testing plan

### Unit

Pricing/fees, compatibility parsing, version constraints, entitlement
transitions, license rules, release immutability, and deprecation.

### Feature

Catalogue/search/filter/pagination; publisher CRUD/ownership;
moderation; releases/docs versioning; checkout; valid and
forged/replayed webhooks; entitlement issuance; private downloads;
license activation/expiry/revocation; refunds; role boundaries; theme
persistence; and real/empty metrics.

### Livewire

Search/filter state, product forms, media validation, version selector,
docs editor, checkout state, release workflow, moderation actions, and
loading/empty/error states.

### Test tooling

Use factories, database assertions, `Storage::fake()`, `Queue::fake()`,
notification fakes, and deterministic time. Use fake payment adapters
and signed test fixtures---never live payment APIs in automated tests.

### Browser journeys

1.  Guest discovers package and reads docs.
2.  Publisher creates and submits listing.
3.  Moderator approves listing.
4.  Customer purchases through fake provider and receives entitlement
    only after verified webhook.
5.  Entitled customer downloads; unauthorized customer is denied.
6.  Publisher releases a new compatible version.
7.  Theme preference persists.
8.  Mobile catalogue and documentation remain usable.

Run existing tests before and after changes; report pre-existing
failures separately from regressions.

## 19. Implementation phases

**Phase 0 --- Audit:** inspect stack, routes, models, migrations,
components, policies, tests, integrations, and deployment. Produce
feature inventory, route/model map, risks, and file-by-file plan.

**Phase 1 --- Design system:** shared layout, tokens, light/dark themes,
cards, filters, responsive navigation, and reusable states.

**Phase 2 --- Discovery:** homepage, marketplace, open-source catalogue,
categories, search, filters, sorting, publisher pages, and real data.

**Phase 3 --- Package pages:** overview, install command, compatibility,
releases, changelog, versioned docs, and support.

**Phase 4 --- Publishing:** listing CRUD, media/artifacts, release
management, docs editor, review submission, and moderation.

**Phase 5 --- Commerce:** prices, orders, provider adapters, verified
webhooks, entitlements, licensing, protected downloads, refunds, and
customer dashboard.

**Phase 6 --- Registry:** authenticated private Composer repository and
install-time verification; add CLI/device authorization and npm/MCP only
after Composer is reliable.

**Phase 7 --- Hardening:** Pest/browser tests, accessibility, mobile QA,
performance, security review, deployment documentation, backups, and
rollback.

## 20. Definition of done

-   Existing application is extended, not replaced.
-   Marketplace and open-source experiences share design and Product
    Engine.
-   All listings, categories, statistics, prices, versions, and docs are
    real data.
-   Search, filters, package detail, and documentation work.
-   Publisher CRUD, release publishing, and moderation work end-to-end.
-   Payment success requires verified server-side confirmation.
-   Commercial artifacts and entitlements are protected.
-   Dark/light themes and responsive behavior are consistent.
-   Existing features and URLs are preserved or migrated deliberately.
-   Pest tests cover security-sensitive workflows and pass, or remaining
    failures are documented.
-   Setup, environment variables, storage, queues, scheduler, payments,
    registry authentication, deployment, backup, and rollback are
    documented.

## 21. Coding-agent execution instruction

First audit the real repository and report: installed versions;
routes/models/tables/components/auth; existing marketplace/package
functionality; reusable code; missing features; security/data migration
risks; and a phased file-by-file plan. Then implement in small stages,
connecting every UI to real backend behavior. Do not stop at
screenshots, static HTML, demo data, or documentation. After each phase,
run relevant Pest tests and provide a concise report of changed files,
migrations, environment variables, tests, limitations, and deployment
steps.
