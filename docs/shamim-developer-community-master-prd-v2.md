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

# MASTER PRD V2 --- FULL SOCIAL + COMMUNITY + FORUM FEATURES AND TEST PLAN

> This section expands the original PRD into a senior-engineer
> implementation specification. It extends the existing document; it
> does not replace the design identity or the Laravel/Livewire
> architecture. Implement features in phases and do not treat every
> advanced feature as an MVP blocker.

## 21. Product scope expansion

The product combines three connected experiences:

1.  **Social network:** profiles, timeline, media posts, reactions,
    comments, sharing, follows, notifications, privacy, blocking, saved
    content, and discovery.
2.  **Developer community:** topic communities, memberships, community
    feeds, projects, open-source showcases, events, and contributor
    discovery.
3.  **Technical forum:** questions, answers, accepted answers,
    discussions, tutorials, code reviews, tags, duplicate detection,
    moderation, and reputation.

The UX should feel familiar to users of mainstream social networks and
technical forums, but retain Shamim's own branding, navigation, design
tokens, information architecture, and content model. Do not copy
proprietary logos, wording, or exact UI assets from another platform.

## 22. Complete CRUD matrix

Every entity must have an explicit lifecycle, authorization policy,
validation rules, audit needs, and tests. "CRUD" does not mean every
user can mutate every record.

  -------------------------------------------------------------------------------------------------------------------
  Entity                 Create                 Read                    Update                  Delete/archive
  ---------------------- ---------------------- ----------------------- ----------------------- ---------------------
  Profile                Registration/profile   Public or               Owner; admin under      Deactivate/delete
                         setup                  privacy-authorized view policy                  account workflow

  Avatar/cover/media     Authorized upload      Visibility-authorized   Owner replaces media    Owner removes;
                                                                                                cleanup job deletes
                                                                                                orphaned files

  Post                   Authenticated member   Visibility-authorized   Author within edit      Author soft-delete;
                                                                        policy                  moderator
                                                                                                hide/restore

  Question               Authenticated member   Visibility-authorized   Author; moderator under Author close/delete
                                                                        policy                  under rules;
                                                                                                moderator action
                                                                                                audited

  Answer                 Authenticated member   Visible question        Author within edit      Author delete under
                                                participants/public     window/policy           policy; moderation
                                                                                                action

  Comment/reply          Authenticated member   Same visibility as      Author under policy     Author delete;
                                                parent                                          moderator hide

  Reaction               Authenticated member   Aggregate or permitted  Toggle/change own       Remove own reaction
                                                detail                  reaction                

  Bookmark/collection    Authenticated member   Owner only              Owner manages           Owner removes
                                                                        collections             bookmark/collection

  Follow                 Authenticated member   Public counts; details  N/A                     Unfollow
                                                according to privacy                            

  Community              Authorized             Public or member-only   Owner/moderator/admin   Archive/delete under
                         creator/admin                                                          retention rules

  Membership             Member request/join    Public membership       Role changes by         Leave/kick/ban under
                                                summary or permitted    authorized              policy
                                                roster                  owner/moderator         

  Tag/category           Admin/moderator as     Public taxonomy         Authorized staff        Merge/archive;
                         delegated                                                              preserve old URLs
                                                                                                where possible

  Project showcase       Authenticated member   Visibility-authorized   Owner                   Owner archive/delete

  Article/tutorial       Authenticated member   Visibility-authorized   Owner; revision history Owner archive/delete
                                                                        recommended             

  Poll                   Authenticated member   Visibility-authorized   Owner before votes or   Owner closes/deletes
                                                                        according to rules      under policy

  Event/challenge        Community              Visibility-authorized   Organizer               Cancel/archive with
                         owner/moderator                                                        notification

  Report                 Authenticated member   Reporter sees own       Reporter may withdraw   Retain moderation
                                                report status;          before review           audit according to
                                                moderators see assigned                         policy
                                                queue                                           

  Notification           System-generated       Recipient only          Recipient marks         Recipient clears;
                                                                        read/preferences        retention policy
                                                                                                applies

  Message/conversation   Authenticated          Participants only       Edit own message only   Delete/hide own view;
                         participants                                   if product policy       retention and abuse
                                                                        allows                  reporting rules apply

  Admin settings         Administrator only     Authorized staff        Administrator with      Avoid destructive
                                                                        audit trail             deletion; version
                                                                                                settings where needed
  -------------------------------------------------------------------------------------------------------------------

### Shared lifecycle requirements

-   Validate every mutation on the server.
-   Apply a policy before reading or mutating sensitive data.
-   Use database transactions for multi-record operations.
-   Use soft deletes where recovery/moderation history is required; do
    not soft-delete every table automatically.
-   Keep immutable audit records for privileged actions.
-   Clean up replaced/orphaned media asynchronously after database
    changes succeed.
-   Use idempotency or unique constraints for retry-prone actions.
-   Return consistent success/error feedback and preserve form input on
    validation errors.
-   Record `created_by`, `updated_by`, or audit events where meaningful,
    without duplicating standard timestamps unnecessarily.

## 23. Social publishing and post features

### 23.1 Composer

The composer must support: - Text-only posts. - Image and multi-image
gallery posts. - Video upload or supported video embeds with strict
URL/provider allowlists. - Link previews with safe server-side metadata
fetching. - Code snippets with language selection and syntax
highlighting. - Questions, discussions, articles/tutorials, project
showcases, polls, and event posts. - Tag selection and optional
community destination. - Draft, preview, publish, edit, and discard
flows. - Audience/visibility selection: public, followers,
community-only, or private draft, subject to product policy. - Alt text
for images, captions, and media ordering. - Character limits and upload
limits shown before submission. - Drag/drop and paste-to-upload where
supported; keyboard-accessible upload fallback. - Duplicate-submit
protection and upload progress. - Autosave drafts only after
retention/privacy rules are defined.

### 23.2 Post card

Each card may show: - Author avatar/name/handle and profile link. -
Timestamp and edit indicator. - Community, tags, and content type. -
Verified badge only when the backend grants it. - Post body with safe
Markdown rendering. - Media, link preview, code block, poll, or
accepted-answer panel. - Reaction summary and counts. - Comment/reply
count. - View count only if collection rules and privacy are
documented. - Bookmark/save, share/copy link, follow/mute/block/report,
and overflow menu based on permissions. - "Edited" history or edit
timestamp where appropriate. - Loading, deleted/removed,
unavailable-media, and moderation states.

### 23.3 Post actions and behavior

-   Like/reaction toggles must be idempotent and have a database
    uniqueness constraint.
-   Reaction types may include Like, Helpful, Celebrate, Insightful, and
    Support; keep the initial release configurable and do not
    overcomplicate the interaction model.
-   Comments support replies, mentions, emoji, code formatting, editing,
    deletion, and reporting.
-   Sharing supports copy-link and in-app reshare with optional
    commentary. Preserve attribution and original visibility. Never
    allow a reshare to bypass the original post's permissions.
-   Reposting should not silently duplicate media files or break
    attribution.
-   Pinning is available to the author on their own profile; community
    moderators can pin within their community.
-   Post edit/delete permissions must be enforced in the server action
    and policy.
-   A deleted/hidden post must not remain discoverable through search,
    trending, notifications, or cached widget results.
-   View counts must not be incremented repeatedly by each Livewire
    re-render. Define deduplication/windowing if view counts are
    implemented.
-   Report, hide, mute, block, and unfollow actions must have distinct,
    clear meanings.

### 23.4 Copy/clipboard requirements

"Clipboard" means copy/paste and clipboard interaction---not a new data
repository. - Copy post URL. - Copy comment/question/answer URL. - Copy
code snippet as plain code, without syntax-highlighting markup. - Copy
invite link for a public community or event, when authorized. - Copy
profile URL. - Copy share text only when the user explicitly chooses
it. - Provide success/failure feedback and a fallback for unsupported
clipboard APIs. - Do not automatically read the clipboard. Clipboard
read access requires a direct user gesture and a clear reason. - Never
copy private content to a public share link. - Test clipboard
interactions in browser tests with clipboard permission/API mocked where
possible.

## 24. Social graph, privacy, and safety

### Follow and relationship controls

-   Follow/unfollow users.
-   Optional follow requests for private profiles.
-   Followers/following lists with privacy rules.
-   Mute a user to reduce their content without notifying them.
-   Block/unblock a user to prevent defined interactions.
-   Prevent self-follow, duplicate follow, and follow actions on
    suspended accounts.
-   When blocked, define behavior for feed visibility, comments,
    mentions, follows, messaging, search, and profile access
    consistently.

### Visibility and audience

-   Public content: visible to guests where product settings allow.
-   Followers-only: visible to approved followers according to
    relationship rules.
-   Community-only: visible to authorized community members.
-   Draft/private: visible to owner and explicitly authorized staff
    only.
-   Visibility is enforced in every query and action, not only by the
    page controller.
-   Search snippets, link previews, notifications, emails, and counts
    must not leak private text or media.

### User safety

-   Report users, posts, comments, communities, projects, messages, and
    events.
-   Mute topics/tags and optionally hide content containing selected
    terms.
-   Rate-limit repeated reports and actions; do not punish legitimate
    reporters solely for reporting.
-   Moderation status transitions: open, triaged, actioned, dismissed,
    appealed (if appeal is supported).
-   Moderator actions require a reason and produce an audit entry.
-   Provide account suspension/ban with start/end times, reason, scope,
    and appeal path if the product supports appeals.
-   Do not reveal reporter identity to the reported user unless an
    explicit policy and legal review permit it.

## 25. Forum and Q&A feature set

### Questions

-   Required title, body, tags, optional community, and relevant
    code/error block.
-   Duplicate-question suggestions based on title/tags before
    publishing; do not block solely on a weak similarity score.
-   Status: open, answered, solved, closed, duplicate, locked, or
    removed as appropriate.
-   Question owner can accept one answer; acceptance must be
    transactional and ensure only one accepted answer per question.
-   Reopen/close behavior must be defined and permission-checked.
-   Sort answers by accepted, helpful score, or newest.
-   Related questions and unanswered queue.
-   Revision history for title/body edits where appropriate.
-   Clear author attribution and edit timestamps.

### Answers and comments

-   Answers are first-class records, not just generic comments.
-   Answers support code blocks, Markdown, references, and comments if
    enabled.
-   Upvote/downvote or helpful/not-helpful signals must be rate-limited
    and protected from self-voting.
-   Accepted answer is visually distinct and accessible without relying
    only on green color.
-   Deleting an accepted answer must either require unaccepting first or
    atomically clear acceptance according to policy.
-   Comments clarify rather than replace full answers.
-   Notify question owner and answer author of meaningful events, with
    notification preferences.

### Discussion forum

-   Discussions can be sorted by latest activity, newest, most helpful,
    and unanswered.
-   Thread lock, pin, move-to-community, merge, duplicate, and archive
    actions for authorized moderators.
-   Pagination and "new replies" indicators.
-   Quote/reply feature must preserve attribution and avoid embedding
    inaccessible private text.
-   Mention users with notification controls and anti-spam limits.

### Technical content

-   Code editor/input with language selection and escaped output.
-   Copy code button.
-   Markdown preview and safe rendering.
-   Optional diff view for edited code snippets.
-   Never execute user-submitted code on the web application server. If
    code execution is introduced later, design a separately isolated,
    resource-limited worker environment with a threat model.

### Reputation and gamification

-   Reputation ledger records reason, actor/system, target, amount, and
    timestamp.
-   Award reputation for accepted answers and helpful contributions
    under configurable rules.
-   Reverse or adjust reputation when the source content/action is
    reversed.
-   Prevent users from awarding themselves reputation through duplicate
    or collusive actions.
-   Badges have documented criteria and are awarded idempotently.
-   Streaks must not imply user activity unless real qualifying actions
    occurred.
-   Do not use reputation as a substitute for moderation or content
    quality review.

## 26. Communities and group management

-   Community directory with categories, search, sort, and featured
    communities.
-   Community home, feed, About, Members, Rules, Events, and Moderation
    tabs as enabled.
-   Join, leave, request-to-join, approve/deny requests, invite, remove
    member, mute, and ban flows as permitted.
-   Roles: owner, moderator, member; optionally trusted contributor if
    necessary.
-   Community settings: name, slug, description, icon, cover, rules,
    visibility, posting permissions, tags, and member approval.
-   Pin announcements and featured posts.
-   Community-specific unanswered questions and project showcase.
-   Community report queue and audit trail.
-   Ownership transfer must be explicit and transactional.
-   Deleting a community requires a clear archive/content-retention
    policy and confirmation.

## 27. Additional social features

### Profile and activity

-   Cover/avatar upload and crop preview.
-   Bio, skills, location optional, website and social links.
-   Posts, replies, answers, projects, articles, and activity tabs.
-   Privacy settings per profile section where appropriate.
-   Followers/following, mutual communities, and member since date.
-   Online indicator only if presence tracking is implemented
    transparently and can be disabled.
-   Profile view counts are optional and should not expose private
    viewers by default.

### Polls

-   Single-choice polls in MVP; multi-choice can follow.
-   Define closing time, visibility of results before/after voting, and
    whether vote changes are allowed.
-   One vote per user/poll enforced by database constraint.
-   Expired polls become read-only.
-   Show accessible results and vote counts without color-only
    distinctions.

### Events and challenges

-   Community event with title, description, time zone, start/end,
    location or online link, organizer, capacity optional.
-   RSVP: interested/going/not going; prevent duplicate RSVPs.
-   Notify participants of time/location changes.
-   Challenge pages with rules, submission window, project links, and
    moderation.
-   Event cancellation must notify RSVPs and update the page.

### Messaging (phase-gated)

-   One-to-one and group conversations only after privacy, blocking,
    reporting, retention, and abuse controls are designed.
-   Participants-only authorization for conversation and message
    retrieval.
-   Pagination, unread state, attachment validation, rate limits,
    report/block controls.
-   Do not mark a message read or expose typing/presence state without a
    documented product rule.
-   Do not store message content in general application logs.

### Notifications

-   In-app center, unread count, mark one read, mark all read,
    clear/hide.
-   Categories: replies, mentions, follows, accepted answer, community
    invites/requests, moderation/account events, event reminders.
-   Per-category and email preferences.
-   Batch or coalesce noisy notifications.
-   Queued delivery with retry/backoff and idempotency.
-   Users can mute noisy threads and stop notifications from a thread.

### Discovery

-   Search by users, posts, questions, answers, tags, projects,
    articles, communities, and events.
-   Filters by type, tag, author, community, date, solved status, and
    relevance where supported.
-   Trending algorithm is transparent, time-bounded, configurable, and
    excludes spam/hidden/private content.
-   "People to follow" and "Communities to join" recommendations must
    explain why when practical.
-   Avoid infinite loading loops; provide explicit retry and load-more
    controls.

## 28. Admin console and operational CRUD

Required sections: - Dashboard and operational summaries. - Users:
search, view, suspend/restore, role assignment with authorization and
audit. - Content: search, inspect, hide/restore, lock/unlock, remove,
merge/mark duplicate where supported. - Communities: manage
owner/moderators, visibility, rules, archive. - Tags/categories: create,
rename, merge, alias, archive. - Reports: triage, assign, resolve,
dismiss, appeal handling if supported. - Featured content and homepage
configuration. - Notification templates and platform settings where
needed. - Audit log viewer with restricted access. - Queue/failed-job
health should be surfaced through secure operational tooling, not
exposed publicly.

Admin bulk actions must show the selected count, explain impact, require
confirmation for destructive changes, and record audit details. Avoid
hard deletes for user-generated content unless the retention policy
requires them.

## 29. Database and consistency requirements

Recommended additional tables where feature scope requires them: -
`post_revisions` - `post_shares` or `reposts` - `polls`, `poll_options`,
`poll_votes` - `community_join_requests`, `community_invitations` -
`user_blocks`, `user_mutes`, `muted_tags` - `reports`,
`report_assignments`, `moderation_actions`, `appeals` -
`reputation_ledger`, `badges`, `badge_awards` - `events`, `event_rsvps`,
`challenges`, `challenge_submissions` - `conversations`,
`conversation_participants`, `messages`, `message_attachments`
(phase-gated) - `notification_preferences` - `user_saved_collections`,
`user_saved_items` - `idempotency_keys` only for operations that
genuinely need retry protection

Rules: - Add unique constraints for usernames/slugs, membership pairs,
follow pairs, reaction pairs, bookmarks, votes, RSVPs, and
accepted-answer invariants where supported by the database. - Add
foreign keys and appropriate deletion behavior; avoid cascading deletes
that erase moderation evidence unintentionally. - Use indexes for common
feed filters, visibility checks, timestamps, and joins. - Use database
transactions for accepting answers, membership role changes, poll votes,
event RSVP changes, and reputation ledger updates. - Use explicit
state-transition methods/actions rather than arbitrary status assignment
from client input. - Avoid storing serialized arbitrary user data when
normalized fields are needed for querying or authorization. - Consider
an outbox pattern if important domain events must reliably trigger
notifications after commit. - Ensure scheduled cleanup jobs are
idempotent.

## 30. Senior-engineer implementation standards

### Boundaries

-   Routes define endpoints; policies decide authorization; Livewire
    components coordinate UI state; Actions/Services perform complex
    domain mutations; Query objects encapsulate complex reads; models
    define relationships and simple domain behavior.
-   Do not create service classes for every trivial one-line method.
    Extract when logic is reused, transactional, complex, externally
    integrated, or independently testable.
-   Keep Blade components presentational and reusable.
-   Do not duplicate the same business mutation in a controller and
    Livewire component; both should call the same action/domain boundary
    where appropriate.
-   Use Form Requests for traditional HTTP endpoints. Livewire
    components must still validate their own public state and authorize
    each action.
-   Prefer explicit enums and state transitions over magic strings
    spread across the application.
-   Avoid premature microservices; use a modular Laravel monolith for
    the initial product.
-   Document any decision that affects privacy, moderation, deletion,
    ranking, or cross-entity consistency.

### Definition of done for every feature

1.  Requirements and authorization rules are written.
2.  Migration, indexes, constraints, model relations, and factory are
    implemented.
3.  Server-side validation and policy checks exist.
4.  Loading, empty, success, failure, and unauthorized UI states are
    handled.
5.  Unit/feature/Livewire tests cover positive and negative paths.
6.  Browser test covers the critical user journey where useful.
7.  Accessibility and mobile behavior are checked.
8.  No N+1 query regression or private-content leakage is found.
9.  Logs avoid secrets and unnecessary personal data.
10. Documentation and test commands are updated.

## 31. Pest PHP testing strategy --- comprehensive plan

Use Pest PHP as the primary testing framework. Pin a version compatible
with the chosen PHP/Laravel stack. Organize tests by behavior, not only
by class name.

### 31.1 Test layers

-   **Unit:** pure ranking, scoring, status transition, normalization,
    and domain rules.
-   **Feature:** routes, authentication, middleware, database
    persistence, policies, validation, HTTP responses.
-   **Livewire component:** form state, validation, events, pagination,
    action authorization, persisted results.
-   **Integration:** queue, storage, notifications, mail, events, HTTP
    clients, cache, and database transaction boundaries.
-   **Browser/E2E:** high-value end-to-end workflows, responsive
    navigation, clipboard interactions, uploads, theme selection.
-   **Architecture/regression:** route access, policy coverage for
    critical models, duplicate mutation regressions, private-content
    leakage.

Do not replace all integration tests with mocks. Use real test database
behavior for persistence/constraints and use fakes for external side
effects.

### 31.2 Test environment

-   Use a dedicated testing database; never run destructive tests
    against production.
-   Use factories with explicit states such as `published`, `private`,
    `suspended`, `moderator`, and `communityMember`.
-   Use `RefreshDatabase` or the project's appropriate isolation
    strategy.
-   Use `Storage::fake()` for upload tests.
-   Use `Queue::fake()`, `Mail::fake()`, `Notification::fake()`,
    `Event::fake()`, and `Http::fake()` where the test's purpose is to
    verify dispatch or external interaction.
-   Use `Bus::fake()` for job dispatch tests and run jobs explicitly in
    integration tests when job behavior itself is under test.
-   Freeze time for time-window ranking, expirations, scheduled
    notifications, and event boundaries.
-   Avoid relying on global seed data for assertions; create the records
    the test needs.
-   Keep tests deterministic: no real network calls, random timing
    dependencies, or external service credentials.

### 31.3 Pest examples: feature and database assertions

``` php
<?php
// tests/Feature/Posts/CreatePostTest.php

use App\Livewire\Feed\Composer;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows an authenticated member to publish a valid post', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Composer::class)
        ->set('type', 'post')
        ->set('body', 'Laravel transaction notes')
        ->call('publish')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('posts', [
        'author_id' => $user->id,
        'body' => 'Laravel transaction notes',
        'status' => 'published',
    ]);
});

it('requires authentication before publishing', function () {
    Livewire::test(Composer::class)
        ->set('body', 'Unauthenticated attempt')
        ->call('publish')
        ->assertUnauthorized(); // Adjust to the app's chosen redirect/guard behavior.
});

it('rejects an empty post body', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Composer::class)
        ->set('type', 'post')
        ->set('body', '')
        ->call('publish')
        ->assertHasErrors(['body']);
});
```

Adapt component names, fields, and response expectations to the actual
implementation. Do not paste illustrative tests without matching them to
the code.

### 31.4 CRUD test matrix

For each resource, test: - Guest cannot create or mutate protected
content. - Authorized owner can create/read/update/delete according to
policy. - Another member cannot update/delete the owner's resource. -
Moderator can perform only assigned moderation actions. - Administrator
access is permission-checked and audited. - Invalid fields produce
validation errors and do not persist. - Soft deletion/archiving removes
content from normal feeds/search. - Restore behavior is available only
to authorized actors. - Related records and media are handled
consistently. - Duplicate submissions do not create duplicate records. -
Concurrent/duplicate operations preserve invariants where relevant. -
Database constraints reject invalid relationship combinations.

### 31.5 Authentication and middleware tests

-   Guest access redirects or returns the intended status.
-   Verified-email middleware behaves as configured.
-   Suspended users cannot publish, comment, react, or message.
-   Rate limits apply to login, posting, comments, reactions, reports,
    and search.
-   CSRF/session behavior follows Laravel defaults; do not weaken
    security for tests.
-   Admin middleware denies normal members.
-   Policies deny access to private profiles, private communities, and
    followers-only posts.
-   Blocked relationships apply consistently across direct routes,
    feeds, search, and interactions.

### 31.6 Form validation tests

Test required fields, max lengths, invalid URLs, invalid enum/type
values, malformed tags, unsupported MIME types, oversize files, invalid
image dimensions, invalid IDs, expired poll options, invalid RSVP
transitions, and attempts to assign protected fields such as
`author_id`, `status`, `role`, `accepted_at`, or moderation flags
directly from user input.

### 31.7 Database assertions and invariants

Use `assertDatabaseHas`, `assertDatabaseMissing`, `assertDatabaseCount`,
and model assertions where appropriate. Verify: - Unique
reaction/bookmark/follow/membership/vote/RSVP constraints. - Only one
accepted answer per question. - Owner IDs cannot be spoofed. - Failed
validation does not create partial records. - Transactions roll back
related mutations on failure. - Deleting/archiving content applies the
defined relation/media policy. - Reputation ledger and badge award are
idempotent. - Notification creation follows committed successful
actions, not rolled-back transactions.

### 31.8 Mocking and fakes

Mock or fake external boundaries, not the domain logic under test: -
`Storage::fake()` for uploaded files and cleanup. - `Queue::fake()` /
`Bus::fake()` for dispatch expectations. - `Mail::fake()` and
`Notification::fake()` for delivery assertions. - `Event::fake()` for
event dispatch contracts. - `Http::fake()` for webhook, link-preview, or
external API clients. - Mock a clock abstraction only when time is an
explicit dependency; otherwise use Laravel/Pest time-travel utilities. -
Mock payment/email/search providers behind interfaces if those
integrations are added. - Prefer testing a concrete action against the
test database rather than mocking Eloquent internals. - Avoid mocking
the method whose behavior the test is intended to verify.

### 31.9 HTTP and API tests

If JSON/API endpoints are exposed: - Authentication and authorization
for every endpoint. - Request validation and consistent error schema. -
Pagination, filters, sort allowlists, and invalid query parameters. -
Resource serialization and no private field leakage. - Rate limiting and
idempotency where needed. - Webhook signature verification and replay
protection if webhooks exist. - External HTTP clients use fakes; no real
network calls in CI.

### 31.10 Console and scheduled-job tests

-   Feed/trending cache refresh.
-   Scheduled cleanup of orphaned uploads.
-   Notification delivery and retry behavior.
-   Event reminders and poll closure.
-   Account suspension expiry.
-   Reputation reconciliation.
-   Failed jobs and retry idempotency.
-   Commands report meaningful output and return correct exit codes.
-   Scheduled commands are safe to run twice.

### 31.11 Browser tests

Use Laravel Dusk or another explicitly selected browser automation tool
compatible with the repository. Keep browser tests focused on critical
journeys rather than duplicating every unit test.

Required browser journeys: 1. Sign up/login → profile setup →
avatar/cover upload → dashboard shows the correct user. 2. Create post
with text/media/code → copy post link → verify success feedback. 3.
Create question → submit answer → accept answer → solved state is
visible. 4. Comment/reply → react → bookmark → verify counts/state. 5.
Join community → publish within it → leave community. 6. Search for
public content and verify private content is absent. 7. Change theme to
light/dark/system and navigate across pages. 8. Report content →
moderator resolves report → audit entry exists. 9. Mobile navigation
opens/closes and composer remains usable. 10. Clipboard copy code/link
works with browser clipboard permission/API stubbed or granted in test
configuration.

### 31.12 Test naming and organization

``` text
tests/
├── Unit/
│   ├── FeedRankingServiceTest.php
│   ├── TrendingScoreTest.php
│   ├── ContentStateTransitionTest.php
│   └── TagNormalizerTest.php
├── Feature/
│   ├── Auth/
│   ├── Profiles/
│   ├── Posts/
│   ├── Questions/
│   ├── Comments/
│   ├── Reactions/
│   ├── Bookmarks/
│   ├── Follows/
│   ├── Communities/
│   ├── Search/
│   ├── Notifications/
│   ├── Moderation/
│   └── Admin/
├── Integration/
│   ├── MediaStorageTest.php
│   ├── NotificationDispatchTest.php
│   └── ExternalHttpClientTest.php
└── Browser/
    ├── ProfileSetupTest.php
    ├── PublishingJourneyTest.php
    ├── QuestionAnswerJourneyTest.php
    ├── ClipboardActionsTest.php
    └── ThemeAndResponsiveNavigationTest.php
```

### 31.13 CI quality gates

A pull request should pass: - Dependency installation from lockfiles. -
Formatting/linting (e.g. Laravel Pint). - Static analysis if
configured. - Unit and feature test suites. - Frontend production
build. - Migration test against a clean test database. - Browser smoke
suite on a supported CI runner. - Dependency/security audit according to
team policy.

Do not require every browser test on every fast local iteration if it
slows development excessively; run critical smoke tests on pull requests
and the full suite on the appropriate CI schedule.

### 31.14 Coverage policy

Set a baseline after the first implementation exists. Prioritize
coverage of authorization, visibility, state transitions, and
money/security-sensitive operations over a vanity global percentage. Set
a measurable coverage threshold only after the test suite and CI runtime
are understood. A high coverage number does not prove correct behavior.

## 32. Observability and production readiness

-   Structured logs with request/correlation IDs where appropriate.
-   Error monitoring and alerting for repeated failures, queue backlog,
    failed jobs, upload errors, and elevated 5xx rates.
-   Metrics for feed latency, query count in development/profiling, job
    duration, notification failures, and moderation backlog.
-   Health endpoints reveal only operationally safe information.
-   Backups and restore drills for database and media storage.
-   Document migration rollback/forward-fix strategy; never assume every
    production migration can be safely rolled back.
-   Feature flags for staged rollout of messaging, polls, events, and
    reputation.
-   Data export/deletion policies and incident response ownership.

## 33. Updated delivery sequence

### Release A --- Foundation and identity

Theme system, responsive shell, auth, profile, avatar/cover, settings,
policies, baseline Pest setup.

### Release B --- Social feed

Composer, post CRUD, media, tags, reactions, comments/replies,
bookmarks, clipboard copy, sharing, follow graph, feed filters.

### Release C --- Developer forum

Questions, answers, accepted answer, duplicate suggestions, tags, code
snippets, copy-code, solved/unanswered views, moderation basics.

### Release D --- Communities and discovery

Community CRUD, membership/roles, community feeds, search, trending,
featured projects, notifications.

### Release E --- Safety and advanced social

Reports, block/mute, privacy audiences, revisions, polls,
events/challenges, reputation/badges where approved.

### Release F --- Optional messaging and hardening

Private messaging, attachments, abuse controls, audit and retention
rules, performance tests, browser journeys, deployment and recovery
documentation.

Every release must pass its relevant Pest unit/feature/Livewire tests
and include a review of authorization, accessibility, and responsive
behavior.

## 34. Final engineering acceptance gate

The application is not complete merely because all screens exist. For
every delivered feature, verify: - CRUD behavior and lifecycle are
implemented end-to-end. - Authorization applies to read and write paths,
including Livewire actions, search, cached widgets, and direct URLs. -
Validation and database constraints protect invariants. - Fakes/mocks
isolate external dependencies without hiding domain defects. - Pest
tests cover success, failure, forbidden access, persistence, and
duplicate/retry behavior. - Browser tests cover critical user journeys
and clipboard/media/theme interactions. - Mobile, keyboard, empty,
loading, and error states work. - Logs, metrics, privacy, deletion, and
moderation behavior are documented. - The test suite runs
deterministically from a clean checkout.
