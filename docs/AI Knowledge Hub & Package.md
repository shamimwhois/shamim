# shamim V2 — AI Knowledge Hub & Package Marketplace

> **Status: UPSTREAM PRODUCT SPEC — NOT IMPLEMENTED.**
>
> This document is an **input**, not a description of the shipped system. It
> extends a "shamimsystem" that does not correspond to the current codebase and
> predates the current architecture. Neither module it specifies — the AI
> Knowledge Hub and the Package Marketplace — exists today.
>
> Read it as: *what the modules should become, once they are built.* Do not read
> it as: *what the application currently does.*
>
> **Where the rest of the system is documented instead:**
> [README](../README.md) · [ARCHITECTURE](./ARCHITECTURE.md) ·
> [PRD](./PRD.md) · [TRD](./TRD.md) · [SCHEMA](./SCHEMA.md) · [ERD](./ERD.md) ·
> [ROUTES](./ROUTES.md) · [COMPONENTS](./COMPONENTS.md) ·
> [PERMISSIONS](./PERMISSIONS.md) · [APP-FLOW](./APP-FLOW.md) ·
> [UI-UX-BRIEF](./UI-UX-BRIEF.md) · [API](./API.md)
>
> **Two premises in this spec that no longer hold.** First, it says to *preserve
> the existing Ideas, Plans and Tasks modules* — there is nothing to preserve;
> see the status banner in [Idea.md](./Idea.md) for why. Second, it assumes the
> knowledge base is single-author. The current system has three account roles,
> and a knowledge base with enrolled readers would need its own permission
> additions to `AccountPermission` rather than reusing the publishing set.
>
> **Already built and reusable:** the modal system, the dashboard shell with
> permission-driven navigation, and the `RolePlan` entitlements
> (`productListingLimit()`, `commissionPercent()`, `canSell()`) that a package
> marketplace would price against. See
> [TRD §7.2](./TRD.md#72-deferred-by-decision-not-by-omission).
>
> **Naming note.** This spec addresses `https://shamim.it`. In-repository
> documentation calls the application **DevFolio**; they refer to the same
> product.

## 1. Project Context

Extend the existing shamimsystem inside `https://shamim.it`.

Preserve the existing Ideas, Plans, Tasks, Projects, Admin Panel, sharing, authentication, and UI conventions.

Add two new major modules:

1. **AI Knowledge Hub** — A structured learning and knowledge-sharing platform.
2. **Package Marketplace** — A platform to publish free and premium software packages.

The existing application is Laravel-based. Inspect the actual repository and reuse compatible technologies and components.

Do not rebuild the application from scratch.

---

## 2. Module A — AI Knowledge Hub

### Primary route

`//ai`

Create a dedicated AI knowledge landing page containing searchable educational content, topic categories, learning paths, tutorials, references, and practical examples.

### Knowledge categories

Create configurable categories for:

- AI Engineering
- AI Fundamentals
- LLM Engineering
- Prompt Engineering
- Context Engineering
- Agent Engineering
- AI Agent Development
- Multi-Agent Systems
- RAG Engineering
- MCP (Model Context Protocol)
- AI SDKs and APIs
- AI Automation
- AI Application Architecture
- AI Evaluation and Testing
- AI Safety and Security
- AI Tools and Frameworks
- Local LLMs
- AI Coding Assistants
- AI Projects and Experiments
- AI Research and Technical Notes

Treat these as initial categories, not a permanent hard-coded list.

The administrator must be able to add, rename, reorder, deactivate, and organize categories.

### Public routes

- `/ai` — AI Knowledge Hub homepage
- `/ai/topics` — Browse topics
- `/ai/topics/{slug}` — Topic details and associated content
- `/ai/articles` — All published articles
- `/ai/articles/{slug}` — Article details
- `/ai/learning-paths` — Learning paths
- `/ai/learning-paths/{slug}` — Learning path details
- `/ai/resources` — Curated resources
- `/ai/projects` — AI experiments and practical projects

Use Laravel named routes and route-model binding where appropriate.

### Knowledge content types

Support:

- Articles
- Tutorials
- Technical notes
- Study notes
- Glossary entries
- Code examples
- Architecture explanations
- Practical guides
- Case studies
- Experiments
- Curated resources
- Learning paths

Each content type should use a suitable shared content architecture rather than duplicating every field.

### Article fields

- Title
- Slug
- Excerpt
- Body
- Content format
- Featured image
- Author
- Primary category
- Additional categories or tags
- Difficulty level
- Estimated reading time
- Prerequisites
- Related articles
- Reference links
- Publication status
- Published date
- Updated date
- SEO metadata

Content statuses:

- Draft
- In Review
- Published
- Archived

Difficulty levels:

- Beginner
- Intermediate
- Advanced
- Expert

### Knowledge Hub features

- Global search
- Category and tag filtering
- Difficulty filtering
- Recently published articles
- Featured content
- Related articles
- Table of contents
- Reading progress indicator
- Code blocks with syntax highlighting
- Copy-code action
- Previous and next article navigation
- Markdown rendering with safe sanitization
- Responsive reading experience
- Social sharing metadata
- Optional authenticated bookmarks
- Optional feedback and comments
- Revision history
- Related GitHub repositories and external resources

Do not create nonfunctional controls.

### Learning paths

Allow administrators to group articles into ordered learning paths.

Example:

AI Fundamentals → LLM Basics → Prompt Engineering → Context Engineering → RAG → AI Agents → MCP → AI Application Development

Each learning path should support:

- Title and description
- Difficulty
- Ordered lessons
- Prerequisites
- Estimated completion time
- Featured status
- Publication status
- Optional user progress tracking

Learning paths must be editable through the Admin Panel.

---

## 3. Module B — Admin Knowledge CMS

Add an AI Knowledge section to the existing admin navigation.

Suggested routes:

- `/admin//ai`
- `/admin//ai/articles`
- `/admin//ai/topics`
- `/admin//ai/learning-paths`
- `/admin//ai/resources`
- `/admin//ai/settings`

### Admin capabilities

- Create, edit, preview, publish, unpublish, and archive content
- Manage categories and tags
- Manage learning paths
- Upload and manage permitted media
- Add external references
- Preview code blocks and formatted content
- Configure SEO metadata
- Mark selected content as featured
- Schedule publication if supported
- Review revision history
- Search and filter all content

All administrative operations must enforce server-side authorization.

New content must remain a draft until explicitly published.

---

## 4. Module C — Package Marketplace

Create a dedicated package marketplace for my software packages, Laravel packages, developer tools, libraries, SDKs, and other downloadable digital products.

### Primary routes

- `/packages` — Marketplace
- `/packages/{slug}` — Package details
- `/packages/category/{slug}` — Category listings
- `/packages/checkout/{slug}` — Checkout initiation
- `/packages/downloads` — Authenticated user's purchased downloads
- `/packages/licenses` — License management where applicable

Use clear authorization rules for all private routes.

### Package types

- Free
- Premium
- Open Source
- Commercial
- Subscription-based, if needed

Do not assume that an open-source package is automatically free for every commercial use. Display its actual license terms.

### Package metadata

Each package should support:

- Name
- Slug
- Short description
- Full description
- Logo or cover image
- Category
- Tags
- Version
- Changelog
- Requirements
- Supported PHP versions
- Supported Laravel versions
- Installation instructions
- Documentation URL
- GitHub repository URL
- Demo URL
- Package download or distribution artifact
- License type
- Pricing model
- Price and currency
- Publication status
- Release date
- Updated date
- Author
- Featured status

### Package detail page

Display:

- Package overview
- Features
- Installation instructions
- Requirements
- Version history
- Changelog
- License terms
- Free or premium pricing
- Purchase or download action
- Documentation and repository links
- Relevant related packages

Show the correct action based on the package's actual access rules.

---

## 5. Free and Premium Access

### Free packages

Authorized public visitors may download packages marked free for public distribution.

Record downloads when appropriate, apply rate limits, and validate downloadable files.

### Premium packages

Require a verified purchase or active entitlement before providing protected downloads.

Implement:

- Checkout initiation
- Payment status
- Order history
- Purchase confirmation
- Protected download authorization
- Download history
- Refund status where supported
- License issuance when required
- License activation and revocation if the product supports licensing

Never expose protected package files through guessable public URLs.

Do not trust the browser's payment-success redirect as proof of payment.

Verify payment status through a trusted server-side verification process or verified provider webhook.

Use database transactions and idempotent payment-event handling to prevent duplicate orders, licenses, or entitlements.

---

## 6. Module D — Payment Gateway Settings

Create an Admin Panel section for configuring payment providers.

Suggested routes:

- `/admin/settings/payments`
- `/admin/settings/payments/{provider}`

Initial provider options:

- bKash
- Nagad
- Binance Pay, subject to official merchant eligibility and availability
- Other supported payment gateways added through the same provider architecture

### Admin settings

For each provider, support the appropriate configuration:

- Enabled or disabled
- Test or live mode where available
- Public identifier where required
- Secret credentials
- Merchant identifier where required
- Webhook configuration
- Currency support
- Payment method display name
- Checkout availability
- Configuration validation status

Keep secret credentials encrypted at rest using appropriate Laravel facilities.

Mask secrets in the interface. Never return secret values to the browser after saving.

Do not hard-code private keys, API secrets, or merchant credentials into source code or documentation.

Disabled providers must not be offered at checkout.

### Gateway architecture

Create a provider abstraction so that checkout and order management do not depend on a single gateway.

Use a provider adapter or driver pattern with consistent operations, such as:

- Check configuration
- Initiate payment
- Retrieve payment status
- Verify webhook signatures
- Process payment notifications
- Handle supported refunds

Implement only the operations actually supported by each provider's official API.

Do not simulate successful payments in production.

If a gateway does not support a required operation, report that limitation clearly.

### Payment security

- Verify webhook signatures where supported.
- Verify payment amounts, currency, merchant identity, and transaction references.
- Make webhook processing idempotent.
- Store provider references and payment status.
- Log payment events without recording sensitive credentials.
- Reject forged or replayed payment notifications.
- Keep pending, successful, failed, cancelled, and refunded states distinct.
- Never mark an order as paid merely because a user returns to the success URL.

For manual payment methods, use an explicit pending-verification process and administrator review.

---

## 7. Module E — Orders, Entitlements, and Licenses

Design the minimum required database structures for:

- Packages
- Package versions
- Package categories
- Package tags
- Package files or protected artifact references
- Orders
- Order items
- Payments
- Payment events
- Entitlements
- Download records
- Licenses, if applicable

Use existing user and authentication tables.

### Order lifecycle

Pending → Payment Processing → Paid

Alternative outcomes:

- Failed
- Cancelled
- Refunded

Use explicit state transitions and server-side verification.

A successful verified purchase should grant the appropriate entitlement exactly once.

A refunded order should follow documented rules for download and license access.

Do not automatically revoke a user's access to every package if only one order is refunded.

### Download protection

- Authorize every protected download.
- Verify package entitlement.
- Validate package version and release status.
- Use temporary signed URLs or a protected streaming endpoint as appropriate.
- Rate-limit sensitive download operations.
- Avoid exposing private storage paths.
- Record relevant download activity.

---

## 8. Suggested Database Design

Inspect the existing database before creating migrations.

Potential tables include:

### Knowledge

- `knowledge_articles`
- `knowledge_categories`
- `knowledge_tags`
- `knowledge_article_tag`
- `learning_paths`
- `learning_path_items`
- `knowledge_resources`
- `knowledge_bookmarks`, if bookmarks are enabled
- `knowledge_revisions`, or a compatible shared revision system

### Marketplace

- `packages`
- `package_versions`
- `package_categories`
- `package_tags`
- `package_tag`
- `package_downloads`
- `orders`
- `order_items`
- `payments`
- `payment_events`
- `entitlements`
- `licenses`, if licensing is enabled

Reuse existing tables and polymorphic structures only when they provide a clear, maintainable solution.

Avoid unnecessary duplication between the existing shamimmodules and the new modules.

Document foreign keys, indexes, ownership, deletion behavior, and authorization requirements.

---

## 9. UI and Navigation

Integrate the new modules into the existing shamimnavigation.

Suggested public navigation:

- Overview
- Ideas
- Plans
- Tasks
- Projects
- AI Knowledge
- Packages

Suggested AI Knowledge navigation:

- Overview
- Articles
- Topics
- Learning Paths
- Resources
- Experiments

Suggested Admin navigation:

- Dashboard
- Ideas
- Plans
- Tasks
- Projects
- AI Knowledge
- Packages
- Orders
- Payments
- Licenses
- Settings

Show only relevant admin options according to the user's permissions.

Maintain a consistent responsive design, existing typography, colors, spacing, and component conventions.

---

## 10. Testing Requirements

Write automated tests for:

### AI Knowledge

- Draft content is not publicly accessible.
- Published articles are accessible according to visibility rules.
- Admins can create and update content.
- Unauthorized users cannot publish content.
- Search, filters, and category relationships work.
- Markdown output is safely rendered.
- Learning paths preserve lesson order.

### Marketplace

- Free packages follow their configured download rules.
- Premium packages cannot be downloaded without entitlement.
- Paid orders require verified payment.
- Forged payment callbacks do not grant access.
- Duplicate webhook events do not duplicate entitlements.
- Disabled gateways cannot be used.
- Revoked access blocks protected downloads.
- License operations enforce ownership and permissions.

Use provider sandbox environments for payment integration testing where available.

Never use real payment credentials in automated tests.

---

## 11. Implementation Roadmap

### Phase A — AI Knowledge Foundation

- Audit the existing repository.
- Define the knowledge content schema.
- Create categories and tags.
- Build the AI Knowledge landing page.
- Implement article CRUD.
- Implement draft, preview, and publish workflows.
- Add search and filtering.

### Phase B — Knowledge Expansion

- Add learning paths.
- Add curated resources.
- Add syntax-highlighted code examples.
- Add related content.
- Add reading progress and optional bookmarks.
- Add revision history.

### Phase C — Package Catalog

- Create package CRUD.
- Build package listing and detail pages.
- Support free package downloads.
- Add protected storage and download authorization.
- Add version history and changelogs.

### Phase D — Payments

- Implement order and payment data models.
- Add provider configuration.
- Integrate the first officially supported gateway.
- Verify payment callbacks.
- Add order history and protected entitlements.
- Test payment failures and duplicate callbacks.

### Phase E — Additional Gateways

- Integrate additional eligible providers.
- Add Binance Pay only after verifying merchant eligibility and current integration requirements.
- Add license management if required.
- Complete security and regression testing.

---

## 12. Execution Instructions

Begin by inspecting the existing application.

Do not assume the repository structure, installed package versions, authentication mechanism, or existing admin panel without verification.

Create an implementation report that includes:

1. Existing architecture
2. Current route and component conventions
3. Reusable database structures
4. New tables and relationships
5. New public and admin routes
6. Required permissions
7. Payment integration risks
8. Implementation phases
9. Exact files to create or modify

Then implement one phase at a time.

Keep the existing shamimfunctionality working throughout the process.

Do not implement payment collection until the order lifecycle, gateway configuration, and server-side verification strategy are defined.

Do not claim that a gateway is integrated until its actual payment flow has been implemented and tested.

The final result must be a maintainable, secure AI Knowledge Hub and software Package Marketplace integrated into `shamim.it`.