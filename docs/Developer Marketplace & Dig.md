# shamimV3 — Developer Marketplace & Digital Product Ecosystem

> **Status: UPSTREAM PRODUCT SPEC — NOT IMPLEMENTED.**
>
> This document is an **input**, not a description of the shipped system. It
> specifies a commercial marketplace with purchasing, protected downloads,
> licensing, and version management, and predates the current architecture.
> None of it exists in the codebase today.
>
> Read it as: *what the module should become, once it is built.* Do not read it
> as: *what the application currently does.*
>
> **Where the rest of the system is documented instead:**
> [README](../README.md) · [ARCHITECTURE](./ARCHITECTURE.md) ·
> [PRD](./PRD.md) · [TRD](./TRD.md) · [SCHEMA](./SCHEMA.md) · [ERD](./ERD.md) ·
> [ROUTES](./ROUTES.md) · [COMPONENTS](./COMPONENTS.md) ·
> [PERMISSIONS](./PERMISSIONS.md) · [APP-FLOW](./APP-FLOW.md) ·
> [UI-UX-BRIEF](./UI-UX-BRIEF.md) · [API](./API.md)
>
> **Already built and deliberately shaped for this module.** The `seller` role
> and its selling permissions (`product:create`, `product:update-own`,
> `product:delete-own`, `product:submit`, `sale:view-own`,
> `support:manage-own`) are defined; the admin review set (`product:review`,
> `product:manage-any`, `sale:view-any`, `refund:process`,
> `payment:configure`) is defined; and `RolePlan` already carries the first real
> entitlement, `productListingLimit()` (Free 0, Seller 25, Platform unlimited)
> alongside `commissionPercent()`. These were declared before the module existed
> so that entitlements are read as data through the account
> (`$user->plan()->productListingLimit()`) rather than inlined as conditionals
> once code arrived.
>
> **What is genuinely missing before this module can ship:** payments (no
> provider is integrated, and `payment:configure` has no surface), an order and
> licence schema, and file storage with access control. See
> [TRD §7.2](./TRD.md#72-deferred-by-decision-not-by-omission) and
> [audit §10](./audit/PHASE-0-AUDIT.md) for the recorded gaps.
>
> **Naming note.** This spec addresses `https://shamim.it`. In-repository
> documentation calls the application **DevFolio**; they refer to the same
> product.

## 1. Product Vision

Build a professional developer-focused digital marketplace inside my existing portfolio website:

**Website:** `https://shamim.it`

shamimwill evolve into a developer ecosystem where creators can publish, demonstrate, sell, distribute, and maintain digital products.

The platform must support both free and premium products, with a clear purchasing journey, protected downloads, product documentation, demo previews, version management, and appropriate licensing.

This marketplace is an extension of the existing shamimplatform, not a separate application.

Preserve existing Ideas, Plans, Tasks, Projects, AI Knowledge Hub, authentication, and admin functionality.

## 2. Product Categories

Create configurable product categories.

Initial categories:

- PHP Packages
- Laravel Packages
- Laravel Plugins
- Laravel Modules
- WordPress Plugins
- WordPress Themes
- Website Templates
- Admin Dashboard Templates
- UI Component Libraries
- JavaScript Libraries
- React Components
- Vue Components
- Next.js Starters
- APIs and SDKs
- Developer Tools
- CLI Applications
- Desktop Applications
- SaaS Starter Kits
- Mobile App Templates
- AI Tools and Agents
- Automation Tools
- Scripts and Utilities
- Documentation Templates
- Source Code Projects
- Premium Digital Assets

Administrators must be able to create, edit, reorder, and deactivate categories.

Do not hard-code the complete category list into application logic.

## 3. Product Listing and Discovery

### Public routes

- `/marketplace`
- `/marketplace/categories/{slug}`
- `/marketplace/products/{slug}`
- `/marketplace/search`
- `/marketplace/collections/{slug}`
- `/marketplace/sellers/{username}`

Use named Laravel routes and appropriate route-model binding.

### Marketplace homepage

Include:

- Hero section and prominent search
- Featured products
- Trending products
- Recently released products
- Free products
- Premium products
- Popular categories
- Recommended developer tools
- New releases
- Curated collections
- Best-rated products, based on genuine reviews
- Recently updated products

Do not fabricate popularity, ratings, sales, or review counts.

### Search and filters

Allow customers to search by:

- Product name
- Description
- Category
- Tags
- Creator
- Framework
- Technology
- Supported version

Filters:

- Free or paid
- Category
- Price range
- Framework
- Programming language
- Product type
- Compatibility
- License type
- Rating
- Recently updated

Sorting:

- Relevance
- Newest
- Recently updated
- Price low to high
- Price high to low
- Highest rated
- Popularity when reliable data is available

Implement pagination and preserve search filters in URLs.

## 4. Product Details Page

Every product must have a dedicated, informative detail page.

Suggested sections:

1. Product overview
2. Screenshots and gallery
3. Live demo
4. Demo credentials or demo access instructions, when applicable
5. Key features
6. Detailed description
7. Installation instructions
8. Requirements and dependencies
9. Compatibility information
10. Documentation
11. Changelog
12. Version history
13. License terms
14. Pricing and purchase options
15. Creator profile
16. Reviews and ratings
17. Support information
18. Frequently asked questions
19. Related products
20. Refund and update policy

### Product information

Each product should support:

- Name
- Slug
- Short description
- Full description
- Product type
- Category
- Tags
- Creator
- Thumbnail and screenshots
- Demo URL
- Documentation URL
- Repository URL, if public
- Installation guide
- Requirements
- Supported technologies
- Compatibility matrix
- Current version
- Changelog
- License type
- Pricing model
- Publication status
- Release date
- Last updated date

Use a sanitized rich-text or Markdown editor.

External links must be validated appropriately.

Never publish confidential demo credentials or private repository URLs.

## 5. Interactive Demo System

Demonstrations are a core marketplace feature.

Support these demo types:

### Type A — External live demo

Allow creators to provide a live demo URL.

Validate URLs and display the demo in a clearly labeled action.

### Type B — Embedded demo

Allow approved, compatible demo content to appear in an iframe or dedicated preview area.

Apply appropriate iframe sandboxing, origin restrictions, and content-security policies.

Do not embed arbitrary executable HTML into the primary application origin.

### Type C — Screenshots and video

Allow screenshots, short product walkthroughs, and product videos.

### Type D — Interactive sandbox

Where practical, allow isolated demos that customers can explore without accessing production data.

Use a separate sandbox or isolated deployment for executable demos.

### Demo access rules

- Demo access must not grant ownership of the paid product.
- Demo credentials must be isolated from production credentials.
- Private source code must remain protected.
- Demo environments should support reset or expiration where appropriate.
- Record demo usage only where useful and disclosed.
- Clearly label demo limitations.

Do not claim a product has a working demo unless the configured demo is actually available.

## 6. Product Pricing and Commercial Models

Support configurable pricing models:

- Free
- One-time purchase
- Paid license
- Subscription, if implemented
- Free with premium upgrade
- Product bundles

Support configurable currencies, with BDT as a possible local display currency and other currencies where payment and accounting requirements permit.

Clearly display:

- Product price
- Currency
- Included features
- License type
- Update entitlement
- Support duration
- Renewal terms, where applicable
- Taxes or additional fees when relevant

Do not silently change prices during checkout.

Record the applicable price and product version information for each order.

Do not advertise subscription billing unless recurring payment handling, cancellation, and renewal have actually been implemented.

## 7. Customer Accounts and Dashboard

Reuse the existing authentication system.

Create a customer dashboard with:

- Purchased products
- Free downloads
- Download history
- Available versions
- Active licenses
- License activation status
- Purchase history
- Order details
- Payment status
- Update notifications
- Support tickets
- Saved products
- Product reviews
- Account settings

Customers must be able to revisit previously purchased products without repeating the purchase.

Protect customer records using server-side authorization.

## 8. Secure Download and Version Management

Implement protected distribution for premium products.

### Product version management

Each version should support:

- Version number
- Release notes
- Changelog
- Release date
- Compatibility information
- Download artifact
- Checksums where appropriate
- Minimum requirements
- Deprecation status

### Download authorization

Before providing a protected artifact:

1. Identify the authenticated customer or authorized license holder.
2. Verify the product entitlement.
3. Verify the requested version.
4. Apply the product's download policy.
5. Record the download event where appropriate.
6. Provide a protected download or short-lived signed URL.

Do not expose protected files through predictable public storage URLs.

Use appropriate storage controls, rate limits, file validation, and download auditing.

### Update notifications

Notify eligible customers when:

- A new version is released
- A security update is available
- A purchased product becomes unsupported
- License or compatibility requirements change materially

Respect the customer's actual update entitlement.

Do not automatically promise lifetime updates.

## 9. License Management

Support licensing only for products that require license enforcement.

License types may include:

- Open Source
- Personal Use
- Commercial Use
- Single-Site License
- Multiple-Site License
- Developer License
- Extended Commercial License

Allow creators to define permitted usage, activation limits, update access, and support terms.

### License capabilities

- Issue a license after an eligible purchase
- Display license details
- Activate and deactivate authorized installations
- Track activation limits
- Revoke licenses according to documented terms
- Record license status changes
- Verify licenses through a protected API, when implemented
- Provide license documentation

A downloadable source-code product does not automatically require technical license enforcement. Make licensing configurable per product.

Do not implement invasive or undisclosed telemetry.

## 10. Seller and Creator Dashboard

Design a creator dashboard for managing products and sales.

### Seller capabilities

- Create products
- Save drafts
- Preview product pages
- Submit products for review
- Publish approved products
- Edit descriptions and pricing
- Upload new versions
- Manage screenshots and demo URLs
- Maintain documentation
- View sales
- View refunds
- Manage support requests
- Respond to reviews where supported
- View product analytics
- Manage seller profile
- Review payout status

### Product publication workflow

Draft → Submitted → Under Review → Approved → Published

Additional states:

- Changes Requested
- Rejected
- Suspended
- Archived

Do not allow a seller to publish unreviewed products if marketplace moderation is enabled.

Maintain revision history for material changes to product descriptions, pricing, files, and license terms.

## 11. Admin Panel and Moderation

Add a Marketplace section to the existing admin panel.

Suggested routes:

- `/admin/marketplace`
- `/admin/marketplace/products`
- `/admin/marketplace/categories`
- `/admin/marketplace/orders`
- `/admin/marketplace/payments`
- `/admin/marketplace/sellers`
- `/admin/marketplace/licenses`
- `/admin/marketplace/reviews`
- `/admin/marketplace/refunds`
- `/admin/marketplace/settings`

### Admin features

- Product review and approval
- Seller approval and suspension
- Category management
- Order management
- Payment verification
- Refund processing
- License administration
- Review moderation
- Abuse reports
- Dispute tracking
- Featured product management
- Marketplace configuration
- Audit history

Use role-based authorization and server-side policies.

Do not allow ordinary administrators to retrieve raw payment secrets unnecessarily.

## 12. Payment Gateway Integration

Provide a configurable payment-provider architecture.

Potential providers:

- bKash
- Nagad
- Binance Pay, subject to official eligibility and availability
- Other supported payment providers

The actual gateway availability must be verified before integration.

### Payment workflow

1. Customer selects a product.
2. Server calculates the authoritative price.
3. Customer reviews the order.
4. The application creates a pending order.
5. Customer proceeds to the selected gateway.
6. The gateway reports the payment result.
7. The server verifies the transaction.
8. The application updates the payment and order state.
9. The system grants the correct entitlement.
10. The customer receives a confirmation.

Implement idempotent webhook processing and prevent duplicate order fulfillment.

Never trust a client-side success redirect.

### Payment settings

Allow authorized administrators to configure:

- Provider enabled or disabled
- Test or live mode, where supported
- Merchant identifiers
- Encrypted credentials
- Currency support
- Webhook settings
- Verification status

Never expose secrets in frontend responses or logs.

Support manual payment verification only as a clearly defined alternative, not as a substitute for automated verification when a secure gateway integration is available.

## 13. Orders, Refunds, and Entitlements

Maintain separate records for:

- Orders
- Order items
- Payments
- Payment events
- Refunds
- Customer entitlements
- Licenses
- Download records

Each order item must retain the relevant purchase price, currency, product reference, and applicable license terms.

Order states:

- Pending
- Processing
- Paid
- Failed
- Cancelled
- Partially Refunded, where supported
- Refunded

Define clear entitlement rules for refunds, chargebacks, revoked licenses, and disputed transactions.

Use database transactions and idempotent processing.

Do not grant premium access until payment verification succeeds.

## 14. Reviews, Wishlist, Comparison, and Bundles

### Reviews

Allow eligible customers to review products.

Features:

- Star rating
- Written review
- Verified purchase indicator
- Moderation
- Report review
- Seller response, if supported

Prevent duplicate reviews where appropriate and do not fabricate verified-purchase status.

### Wishlist

Allow authenticated customers to save products and remove them later.

### Product comparison

Allow customers to compare selected products using:

- Features
- Pricing
- Supported frameworks
- Compatibility
- License
- Update policy
- Support policy

Do not compare unrelated product types using misleading criteria.

### Bundles

Allow administrators or authorized creators to group compatible products into bundles.

Display:

- Included products
- Total individual price
- Bundle price
- Savings calculated from actual prices
- Bundle license terms
- Included update entitlements

Do not advertise discounts without a valid price calculation.

## 15. Support and Documentation

Provide product-level support information.

Support capabilities:

- Product FAQs
- Documentation links
- Installation guides
- Troubleshooting articles
- Customer support tickets
- Ticket status
- Replies and attachments
- Ticket history
- Optional support entitlement checks

Support statuses:

- Open
- In Progress
- Waiting for Customer
- Resolved
- Closed

Clearly distinguish public documentation from private support messages.

Do not expose customer attachments or support conversations publicly.

## 16. Seller Analytics and Payouts

Provide useful analytics:

- Product views
- Demo visits
- Download counts
- Verified sales
- Gross revenue
- Refunds
- Net revenue
- Conversion rate where measurable
- Product update history

Use actual event data and define the calculation method for each metric.

### Multi-seller support

Design the architecture so multiple approved creators can sell products.

If multiple sellers are enabled, support:

- Seller ownership
- Sales attribution
- Configurable platform commission
- Seller balance ledger
- Payout requests
- Payout review
- Payout history
- Refund adjustments
- Financial reconciliation

Do not implement automatic payouts until a supported payout provider or controlled manual process exists.

Keep financial records auditable and prevent sellers from modifying their own balances directly.

If multi-seller functionality is outside the first release, prepare the architecture without unnecessarily implementing a full financial subsystem.

## 17. Security Requirements

Implement:

- Authentication and authorization
- Product ownership checks
- Protected downloads
- Secure artifact storage
- Safe file uploads
- Payment verification
- Webhook signature verification where supported
- Idempotent transaction processing
- XSS and CSRF protections
- Rate limiting
- Secure seller access
- License ownership checks
- Private support attachments
- Audit logs for sensitive actions
- Safe Markdown and rich-text rendering
- Protection against IDOR and unauthorized record enumeration

Never trust a submitted price, seller ID, license status, entitlement, or payment state without server-side verification.

## 18. Database Architecture

Inspect the existing database before adding migrations.

Potential tables:

- `marketplace_products`
- `marketplace_categories`
- `marketplace_tags`
- `marketplace_product_tag`
- `marketplace_product_versions`
- `marketplace_product_media`
- `marketplace_product_requirements`
- `marketplace_product_licenses`
- `marketplace_sellers`
- `marketplace_orders`
- `marketplace_order_items`
- `marketplace_payments`
- `marketplace_payment_events`
- `marketplace_refunds`
- `marketplace_entitlements`
- `marketplace_downloads`
- `marketplace_reviews`
- `marketplace_wishlists`
- `marketplace_bundles`
- `marketplace_bundle_items`
- `marketplace_support_tickets`
- `marketplace_support_messages`
- `marketplace_payout_requests`, if multi-seller payouts are implemented

Adapt naming to existing conventions and reuse compatible structures when appropriate.

Use foreign keys, indexes, appropriate deletion behavior, and transactions.

Document the entity relationship diagram and important business constraints.

## 19. UI/UX Requirements

Design a professional, responsive digital marketplace.

The customer experience must make these questions easy to answer:

- What does this product do?
- Is it compatible with my project?
- Can I try it before buying?
- What is included?
- How much does it cost?
- What license do I receive?
- How do I install it?
- How do I get updates?
- Where can I get support?

Use clear pricing, obvious demo actions, prominent installation instructions, readable compatibility information, and visible trust indicators.

Provide responsive layouts for desktop, tablet, and mobile.

Reuse existing shamimcomponents and styling conventions.

## 20. Implementation Roadmap

### Phase 1 — Marketplace Foundation

- Repository audit
- Product categories
- Product CRUD
- Product details page
- Search and filters
- Demo links and screenshots
- Admin review workflow

### Phase 2 — Free Distribution

- Free products
- Secure artifact storage
- Version management
- Download history
- Documentation and changelog
- Basic customer dashboard

### Phase 3 — Paid Products

- Orders
- Checkout
- First supported payment gateway
- Verified payment workflow
- Premium entitlements
- Protected downloads
- License issuance where required

### Phase 4 — Customer Experience

- Wishlist
- Reviews
- Product comparison
- Bundles
- Notifications
- Support tickets

### Phase 5 — Seller Ecosystem

- Seller profiles
- Seller dashboard
- Sales analytics
- Commission configuration
- Payout workflow if supported
- Seller moderation

### Phase 6 — Quality and Release

- Authorization tests
- Payment tests
- Download security tests
- Accessibility
- Performance
- Mobile responsiveness
- Deployment documentation
- Production readiness review

## 21. Required Documentation

Create:

- `docs/marketplace/README.md`
- `docs/marketplace/PRD.md`
- `docs/marketplace/TRD.md`
- `docs/marketplace/DATABASE.md`
- `docs/marketplace/ROUTES.md`
- `docs/marketplace/PERMISSIONS.md`
- `docs/marketplace/PAYMENTS.md`
- `docs/marketplace/LICENSING.md`
- `docs/marketplace/DEMOS.md`
- `docs/marketplace/SELLERS.md`
- `docs/marketplace/SECURITY.md`
- `docs/marketplace/TESTING.md`
- `docs/marketplace/ROADMAP.md`
- `docs/marketplace/DEPLOYMENT.md`

Document actual implementation status and unresolved limitations.

## 22. Final Execution Instructions

Start with a repository audit.

Identify the current Laravel version, authentication system, admin panel, route conventions, database, UI components, and existing shamimmodules.

Prepare the PRD, TRD, database design, route map, permissions matrix, and implementation sequence before changing application code.

Implement the marketplace in small, testable phases.

Preserve existing functionality and avoid unnecessary dependency upgrades.

Do not claim a payment integration, interactive demo, protected download, seller payout, or license enforcement is operational unless the corresponding feature has been implemented and tested.

The final objective is a reliable developer marketplace where customers can discover, preview, compare, purchase, download, license, and maintain digital products while creators can publish and manage their products professionally.

Integrate the complete marketplace into the existing shamimecosystem without replacing the portfolio or AI Knowledge Hub.
