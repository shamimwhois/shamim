# Shamim Developer Community --- Master PRD

**Deliverable:** Product requirements + Laravel implementation
blueprint\
**Stack:** Laravel 13, compatible Livewire release, Blade components,
Tailwind CSS 4, Alpine.js, Pest PHP\
**Reference:** Uploaded dark-mode Shamim Developer Community dashboard\
**Purpose:** Build a responsive, dynamic developer social network and
technical forum---not a static screenshot.

## 1. Product overview

Shamim Developer Community combines a developer social feed, Q&A forum,
topic-based communities, project showcase, and knowledge-sharing
platform. Preserve the reference identity: deep navy/near-black
surfaces, subtle cyan borders, green-teal primary actions, compact
technical metadata, a left navigation rail, central feed, contextual
right rail, profile cover, and structured footer.

The application must be database-driven. Never hard-code the signed-in
user's avatar, name, cover, follower counts, posts, trending tags, or
activity metrics into production components.

### Goals

-   Make technical posts and unanswered questions easy to discover.
-   Provide fast Livewire interactions for composing, filtering,
    reacting, commenting, saving, and joining communities.
-   Use reusable layouts, Blade UI components, and focused Livewire
    components.
-   Support dark, light, and system themes.
-   Protect private content with server-side authorization.
-   Deliver an accessible responsive layout for desktop, tablet, and
    mobile.

### MVP exclusions

No live video calls, full online IDE/code execution, paid marketplace,
or complex AI answer generation in the first release.

## 2. Design analysis

### Layout zones

1.  **Top bar:** logo, global search, Create menu, notifications,
    optional messages, settings, user avatar/menu.
2.  **Left sidebar:** Home, Following, For You, Latest, Trending, Saved;
    communities; forum categories; showcase links.
3.  **Main column:** profile/cover area where appropriate, composer,
    feed tabs, type/tag filters, feed cards, pagination.
4.  **Right rail:** profile overview, About Me, trending topics,
    unanswered questions, featured projects, communities to join.
5.  **Footer:** brand/tagline, quick links, resources, community links,
    contact, optional newsletter, dynamic copyright year.

### Visual rules

-   Dark mode: near-black/navy background, raised navy panels, thin
    cyan/teal borders, bright teal primary actions.
-   Light mode: cool off-white background, white cards, soft blue-gray
    borders, deep teal actions. Design it intentionally; do not merely
    invert dark colors.
-   Sans-serif for body copy; monospaced text only for code, counts, and
    compact technical labels.
-   Consistent 4px spacing scale, 10--14px card radius, clear heading
    hierarchy, restrained motion.
-   Keep the feed visually dominant; right-rail cards should be
    secondary.
-   Use real loading, empty, error, success, and unauthorized states.
-   On mobile, collapse sidebars and use a single-column feed with a
    drawer or bottom navigation.

### Suggested design tokens

**Dark** - background `#050B10`; surface `#07131C`; raised surface
`#0B1B26` - hover `#102633`; border `#153342`; strong border `#205064` -
text `#EAF5FA`; muted text `#91A8B6` - primary `#00D7B0`; primary hover
`#00F0C4`; cyan `#00CFFF` - success `#22C99A`; warning `#F5B942`; danger
`#FF5D73`

**Light** - background `#F4F8FB`; surface `#FFFFFF`; raised surface
`#F8FBFD` - hover `#EAF5F8`; border `#D7E4EA`; strong border `#B8CDD7` -
text `#10212D`; muted text `#526A79` - primary `#008F79`; primary hover
`#007563`; cyan `#007EAA` - success `#0C9876`; warning `#9B6500`; danger
`#C8324B`

Implement tokens as CSS custom properties and verify text contrast
against WCAG AA. Never convey status by color alone.

## 3. Roles and permissions

-   **Guest:** browse public content, search public entities,
    register/login/reset password.
-   **Member:** manage own profile; publish/edit/delete own content;
    react, comment, bookmark, follow, join communities; receive
    notifications; report content.
-   **Moderator:** review reports and moderate within assigned scope;
    lock/hide/restore content with a reason.
-   **Administrator:** manage users, roles, communities, categories,
    tags, reports, site settings, and audit records.

Use Laravel policies/gates and middleware. Hiding a button is not
authorization.

## 4. Navigation and pages

### Navigation

-   Home, Following, For You, Latest, Trending, Saved
-   Communities: All Communities, PHP, Laravel, JavaScript, Vue.js,
    React, Next.js, Python, Django, DevOps, Cloud, AI/ML, Database,
    Security, More
-   Forum: All Discussions, Questions & Answers, Unanswered, Solved,
    Tutorials, Code Reviews, Architecture, Debugging Help
-   Showcase: Projects, Open Source, Portfolios, Product Launches,
    Challenges

Counts and labels should be data-driven/configurable. Do not use
screenshot counts as live data.

### Pages/routes

-   `/` or `/feed` --- personalized feed
-   `/trending` --- trending feed
-   `/unanswered` --- open questions without an accepted answer
-   `/search` --- search users, posts, tags, communities, projects
-   `/communities` and `/communities/{community}`
-   `/questions/{question}`
-   `/projects` and `/projects/{project}`
-   `/articles` and `/articles/{article}`
-   `/u/{user:username}` --- public profile
-   `/saved` --- saved content
-   `/notifications` --- notification center
-   `/settings/profile`, `/settings/account`, `/settings/notifications`
-   `/admin`, `/admin/reports` --- authorized administration

Use named routes, route model binding, unique slugs, authentication
middleware, and policies. Private content must not be accessible by
direct URL.

## 5. Functional requirements

### Authentication and profile

-   Registration, login/logout, password reset, email verification.
-   Profile display name, handle, bio, skills, location (optional),
    website, approved social links.
-   Avatar and cover upload with preview/crop where practical.
-   Validate MIME type, size, dimensions, and storage path.
-   Show fallback initials when no avatar exists.
-   Dashboard profile data comes from `auth()->user()` and the
    associated profile. Public profile pages use the target profile's
    data.
-   Optional account deletion, suspension, and two-factor authentication
    can follow MVP.

### Content types

-   General post, question, discussion, project showcase,
    article/tutorial, code snippet.
-   Shared fields: author, type, title where required, body, tags,
    visibility, community association, media, status, timestamps.
-   Support draft/published/hidden/deleted states as required.
-   Type-specific composer fields, server validation, Markdown/code
    preview, ownership checks, duplicate-submit prevention.
-   Escape output and sanitize any permitted rich text.

### Feed

-   **For You:** follows, joined communities, tags, recency, and quality
    signals.
-   **Following:** content from followed members and joined communities.
-   **Latest:** newest published content.
-   **Trending:** recency-adjusted engagement over a documented time
    window.
-   **Unanswered:** questions without accepted answers.
-   Compose filters for type, tag, community, and sort order.
-   Use stable pagination; exclude hidden, deleted, blocked, private, or
    unauthorized content.
-   Keep ranking logic in a query/service layer, not Blade. Start with a
    transparent tunable score rather than an opaque algorithm.

### Interactions

-   React/unreact, comment/reply, bookmark/unbookmark, follow/unfollow,
    join/leave community, copy/share URL, report content.
-   Question owner or authorized moderator can accept an answer.
-   Database unique constraints prevent duplicate reactions, bookmarks,
    follows, and memberships.
-   Optimistic UI only where rollback/error handling exists.

### Communities

-   Name, slug, description, icon/cover, category, visibility, rules,
    owner, moderators.
-   Community feed, tags, members, join/leave action.
-   Enforce membership authorization for private communities.
-   Owners/moderators can manage community settings and membership
    within permission scope.

### Search and notifications

-   Debounced search across users, posts, questions, tags, projects,
    communities.
-   Results obey visibility, blocking, and authorization rules.
-   In-app notifications for follows, comments, replies, accepted
    answers, mentions, and relevant community events.
-   Read/unread state, mark-all-read, pagination, user-controlled email
    preferences.
-   Queue slow notification delivery.

### Right rail

-   Profile Overview from real counts.
-   About Me from the current profile.
-   Trending Topics derived from recent tag activity.
-   Needs Answers from open questions.
-   Featured Projects from curated records or documented ranking rules.
-   Communities to Join based on membership and discovery logic.
-   Every widget supports loading, empty, and failure states.

### Admin/moderation

-   User/role/permission management, category/tag/community management.
-   Report queue, report resolution, hide/restore/lock actions, reason
    fields, audit history.
-   Rate-limit settings and spam handling.
-   Privileged actions are permission-checked and auditable.

## 6. Laravel architecture

### Stack and compatibility

Use Laravel 13, the PHP version supported by the selected Laravel
release, Livewire version verified as compatible with that Laravel
release, Blade components, Tailwind CSS 4, Alpine.js for small
client-side interactions, Vite, Pest PHP, Laravel queues, and MySQL or
PostgreSQL. Redis is recommended for production cache/queues when scale
requires it.

**Before scaffolding, verify and pin Laravel, PHP, Livewire, and
frontend package compatibility.** Do not assume the latest Livewire
major is compatible without checking its package constraints.

### Principles

-   Thin routes; named routes and policies.
-   Server-side validation for every mutation.
-   Query/service/action classes for domain logic that is reused or
    complex.
-   Transactions for multi-record operations.
-   Events/listeners for decoupled side effects; queues for slow work.
-   Eager-load relations; avoid N+1 queries.
-   Keep business logic out of Blade templates.
-   Use enums/constants for stable statuses and types.
-   Use factories/seeders for repeatable demo data.
-   Avoid unnecessary abstraction; introduce DTOs only where they
    improve boundaries.

### Suggested structure

``` text
app/
├── Actions/
│   ├── Posts/{PublishPost,UpdatePost,DeletePost}.php
│   ├── Reactions/ToggleReaction.php
│   ├── Communities/{JoinCommunity,LeaveCommunity}.php
│   └── Questions/AcceptAnswer.php
├── Enums/{ContentType,ContentStatus,Visibility,ReportStatus}.php
├── Events/
├── Http/{Controllers,Middleware,Requests}/
├── Jobs/
├── Livewire/
│   ├── Layout/
│   ├── Feed/
│   ├── Posts/
│   ├── Comments/
│   ├── Questions/
│   ├── Communities/
│   ├── Profile/
│   ├── Search/
│   ├── Notifications/
│   └── Admin/
├── Models/
├── Notifications/
├── Policies/
├── Queries/{FeedQuery,TrendingTopicsQuery,UnansweredQuestionsQuery}.php
├── Services/{FeedRankingService,MediaService,ModerationService}.php
└── Support/
resources/
├── css/app.css
├── js/app.js
└── views/
    ├── layouts/{app,guest,admin}.blade.php
    ├── components/
    │   ├── ui/
    │   ├── navigation/
    │   ├── profile/
    │   ├── feed/
    │   ├── posts/
    │   ├── communities/
    │   └── widgets/
    ├── livewire/
    │   ├── layout/
    │   ├── feed/
    │   ├── posts/
    │   ├── comments/
    │   ├── questions/
    │   ├── communities/
    │   ├── profile/
    │   ├── search/
    │   ├── notifications/
    │   └── admin/
    └── pages/
routes/{web,console}.php
database/{migrations,factories,seeders}/
tests/{Unit,Feature,Browser}/
```

This is a staged structure, not a requirement to create every class
before its feature exists.

## 7. Reusable Blade component catalogue

### Global shell

-   `AppLayout`, `GuestLayout`, `AdminLayout`
-   `TopBar`, `SidebarNavigation`, `MobileNavigation`, `RightRail`,
    `SiteFooter`
-   `ThemeToggle`, `CommandSearch`, `UserMenu`

### UI primitives

-   `Button`, `IconButton`, `Input`, `Textarea`, `Select`, `Checkbox`,
    `Switch`
-   `Badge`, `Avatar`, `AvatarStack`, `Tooltip`, `Dropdown`, `Modal`,
    `Drawer`
-   `Tabs`, `Chip`, `Skeleton`, `EmptyState`, `ErrorState`,
    `Pagination`, `Toast`, `ConfirmDialog`

### Profile

-   `ProfileCover`, `ProfileAvatar`, `ProfileIdentity`, `ProfileStats`,
    `ProfileActions`, `ProfileTabs`
-   `ProfileAboutCard`, `SocialLinks`, `EditProfileForm`,
    `AvatarUpload`, `CoverUpload`

### Feed

-   `FeedComposer`, `ComposerTypeSelector`, `FeedTabs`,
    `PostTypeFilters`, `FeedList`, `FeedCard`
-   `PostHeader`, `PostBody`, `PostMedia`, `CodeBlockPreview`,
    `TagList`, `PostActions`
-   `ReactionButton`, `CommentThread`, `CommentForm`, `BookmarkButton`,
    `ShareButton`, `ReportMenu`
-   `SolvedBadge`, `AcceptedAnswerPanel`

### Discovery and account

-   `CommunityList`, `CommunityCard`, `CommunityBadge`,
    `MembershipButton`
-   `TrendingTopics`, `NeedsAnswers`, `FeaturedProjects`,
    `ContributorList`, `SearchResults`
-   `NotificationBell`, `NotificationList`, `NotificationItem`,
    `AccountSettingsForm`

**Component rule:** each component has one responsibility, clear inputs,
responsive behavior, accessible labels, and appropriate
loading/empty/error states. Presentational Blade components must not
hide database mutations.

## 8. Livewire component plan

  Component                        Responsibility
  -------------------------------- -----------------------------------------------
  `Feed\Index`                     Feed tabs, type/tag filters, pagination state
  `Feed\FeedList`                  Query and render paginated results
  `Feed\Composer`                  Create post/question/project/article
  `Posts\Card`                     Render post; react, bookmark, share, report
  `Posts\EditForm`                 Edit owned content
  `Comments\Thread`                Paginated comments and reply target
  `Comments\CreateForm`            Create validated comment/reply
  `Questions\Detail`               Question, answers, sort, answer form
  `Questions\AcceptAnswerButton`   Accept answer with policy check
  `Communities\Index`              Search/filter community directory
  `Communities\Show`               Community page and membership action
  `Profile\Show`                   Public profile tabs and follow action
  `Profile\Edit`                   Update own profile and media
  `Search\GlobalSearch`            Search query and typed results
  `Notifications\Bell`             Unread count and dropdown
  `Notifications\Index`            Paginated notification center
  `Admin\Reports\Index`            Filtered moderation queue
  `Admin\Reports\Review`           Resolve report with reason

### Livewire rules

-   Treat public properties as user-controlled input.
-   Validate and authorize every action server-side.
-   Keep state small; use pagination and stable keys.
-   Prevent duplicate submissions with loading/disabled states and
    database constraints.
-   Avoid N+1 queries and sending entire collections to the browser.
-   Keep complex ranking/query logic in dedicated classes.
-   Use polling only when necessary; use queues for slow side effects.
-   Test success, validation failure, unauthorized actions, and
    persistence.

## 9. Initial data model

Core tables: - `users`, `profiles`, `social_links` - `posts`,
`post_media`, `tags`, `post_tag` - `communities`,
`community_memberships` - `comments`, `answers` - `reactions`,
`bookmarks`, `follows` - `notifications` (Laravel-compatible schema) -
`reports`, `moderation_actions`, `audit_logs` -
`newsletter_subscriptions` only if newsletter signup is implemented

Use foreign keys, indexes, factories, seeders, and unique constraints.
Index common filters and joins: published timestamp, status, type,
author, community, and pivot keys. Use UTC timestamps. Decide whether
questions/projects share a common posts table plus related metadata or
use dedicated tables based on actual domain differences. If polymorphic
relations are used, explicitly allowlist target types.

## 10. Performance and security

### Performance

-   Paginate feeds, comments, search, notifications, and admin tables.
-   Eager-load author/profile, tags, media, and reaction summaries.
-   Do not load all comments for every feed card.
-   Cache trending/aggregate widgets with explicit TTL/invalidation.
-   Lazy-load media and generate responsive thumbnails.
-   Keep Livewire payloads bounded.
-   Rate-limit auth, publishing, comments, reactions, follows, reports,
    and search.
-   Measure with realistic seeded data; do not promise performance
    without load tests.

Initial targets to validate: - Feed server-side p95 below 500 ms in
production-like conditions. - Simple mutations p95 below 300 ms
excluding network latency. - No obvious N+1 query patterns. - Lighthouse
accessibility score 90+ on key public pages.

### Security/privacy

-   Laravel auth, CSRF, secure cookies, session regeneration, password
    hashing.
-   Policies/gates for ownership, membership, and moderator actions.
-   Validate uploads and store them through Laravel Storage.
-   Escape output; sanitize permitted Markdown/rich text.
-   Prevent private content leakage through direct URLs, feed, search,
    widgets, and notifications.
-   Audit privileged actions; require reasons for moderation.
-   Never log passwords, tokens, or unnecessary private data.
-   Define user deletion, content retention, and account suspension
    behavior.
-   Keep secrets in environment variables and scan dependencies
    regularly.

## 11. Testing with Pest PHP

### Unit

-   Feed ranking, trending score, content status transitions, tag
    normalization.

### Feature/Livewire

-   Auth and profile authorization.
-   Avatar/cover validation and persistence.
-   Create/edit/delete content.
-   Feed filters and pagination.
-   Reaction/bookmark uniqueness.
-   Follow/unfollow and community join/leave.
-   Private community access.
-   Comments and accepted-answer permissions.
-   Search visibility rules.
-   Notification read state.
-   Report submission and moderation authorization.

### Browser/E2E

1.  Register → complete profile → publish post → react/comment.
2.  Create question → answer → accept answer.
3.  Join community → publish a community post.
4.  Upload avatar/cover → verify dashboard and public profile display.
5.  Switch dark/light/system theme.
6.  Test mobile navigation and composer.
7.  Moderator resolves report and audit entry is written.

Quality gates: tests pass, formatter passes, frontend build succeeds,
migrations run on a clean database, and authorization regression tests
cover private content.

## 12. Delivery phases

### Phase 0 --- Foundation

Confirm package compatibility, auth approach, repo conventions, design
tokens, layouts, responsive navigation, CI, and test setup.

### Phase 1 --- Identity

Auth, profile, avatar/cover uploads, social links, user menu, public
profile.

### Phase 2 --- Feed

Content model, composer, cards, type/tag filters, pagination, questions
and projects.

### Phase 3 --- Interactions

Reactions, comments, bookmarks, follows, communities, membership, core
notifications.

### Phase 4 --- Discovery

Search, trending topics, unanswered questions, featured projects,
profile metrics, empty/loading/error states.

### Phase 5 --- Moderation and production hardening

Reports, moderator workflow, audit logs, rate limiting, queues,
production storage, deployment docs.

### Later backlog

Messaging, mentions, reputation/badges, advanced Markdown editor,
community events, challenges, and carefully scoped AI-assisted
discovery.

## 13. Acceptance criteria

1.  Desktop layout follows the reference hierarchy without being a
    static copy.
2.  Dark/light/system themes work across pages, menus, and dialogs.
3.  Shared layouts and components power navigation, feed, widgets, and
    footer.
4.  Logged-in profile data is dynamic; public profiles use the correct
    target user.
5.  Livewire publishing and interactions validate and persist correctly.
6.  Feed filters compose with pagination and stable ordering.
7.  Reactions, bookmarks, follows, and memberships cannot duplicate.
8.  Questions clearly show unanswered/solved/accepted-answer states.
9.  Private content is inaccessible through unauthorized routes and
    queries.
10. Forms provide validation, loading, success, and error feedback.
11. Pest tests cover key flows, policies, and failure cases.
12. Mobile uses a single-column feed instead of a squeezed three-column
    layout.
13. Keyboard focus, accessible icon labels, and reduced-motion support
    exist.
14. Screenshot metrics and sample content are never presented as real
    live data.
15. Setup, storage, queue, test, and deployment instructions are
    documented.

## 14. Coding-agent implementation prompt

> Build Shamim Developer Community using Laravel 13, a Livewire release
> verified compatible with the selected Laravel version, Blade
> components, Tailwind CSS 4, Alpine.js for small client-side
> interactions, and Pest PHP. First inspect the existing repository and
> preserve working conventions; do not overwrite authentication or
> routes without understanding them.
>
> Recreate the reference's design language: deep navy/near-black
> surfaces, thin cyan/teal borders, green-cyan primary actions, compact
> technical metadata, left navigation, central feed, right contextual
> rail, dynamic profile cover/avatar, and footer. Implement a cohesive
> light theme using shared CSS variables. Make the layout responsive,
> accessible, and usable on mobile.
>
> Build reusable layouts and UI components, then implement one vertical
> slice at a time: foundation; auth/profile; feed/publishing;
> interactions/communities; search/discovery; moderation/hardening. Use
> Livewire for interactive filters, composer, reactions, comments,
> profile editing, membership, notifications, and search.
>
> Keep business logic out of Blade. Use policies, server-side
> validation, transactions, database constraints, eager loading,
> pagination, queues, and dedicated query/service classes where
> appropriate. Never hard-code user identity, avatars, counts, trending
> tags, or production feed content. All dashboard profile information
> must come from the authenticated user's database record; public
> profile pages use the requested user's record.
>
> Deliver migrations, models, factories/seeders, named routes, policies,
> Livewire components, Blade components, responsive CSS tokens, and Pest
> tests. Include loading/empty/error states, dark/light/system themes,
> private-content checks, upload validation, and mobile navigation. Do
> not mark a feature complete until it works end-to-end and its tests
> pass. Before coding, provide a short plan and proposed file tree; then
> implement the foundation and first vertical slice.

## 15. Decisions to confirm before production

-   Username/handle rules and whether users can change them.
-   Default profile and post visibility.
-   Community-only/unlisted content rules.
-   Supported reaction types and comment nesting depth.
-   Whether messaging is in MVP.
-   Media storage provider and upload limits.
-   Moderation policy, report categories, and appeals.
-   Whether reputation/streaks are included.
-   Pagination strategy and supported locales/time zones.
-   Whether newsletter signup is required.

**End of PRD.**
