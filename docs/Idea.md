# Idea — Master Product & Development Prompt

> **Status: UPSTREAM PRODUCT SPEC — PARTIALLY IMPLEMENTED.**
>
> This document is an **input** that has since been partly built. It was written as a
> build prompt for an "Ideas, Plans, Tasks, Projects & Collaboration" module and
> predates the current architecture. Ideas, Plans, Tasks and their categories exist in
> the codebase, each owned by the account that wrote it, with a public surface for the
> rows an author chose to publish. Projects, milestones, sharing, revision history,
> the activity log and notifications do not.
>
> Read §1 and onward as: *what the module was specified to become.* Read
> [§ What is shipped](#what-is-shipped) below as: *what the application currently
> does.* Where the two disagree, this document defers — the shipped system is described
> in [ROUTES](./ROUTES.md), [SCHEMA](./SCHEMA.md), [ERD](./ERD.md),
> [ARCHITECTURE](./ARCHITECTURE.md) and [COMPONENTS](./COMPONENTS.md), and those are
> the files kept in step with the code.
>
> **Where the rest of the system is documented instead:**
> [README](../README.md) · [ARCHITECTURE](./ARCHITECTURE.md) ·
> [PRD](./PRD.md) · [TRD](./TRD.md) · [SCHEMA](./SCHEMA.md) · [ERD](./ERD.md) ·
> [ROUTES](./ROUTES.md) · [COMPONENTS](./COMPONENTS.md) ·
> [PERMISSIONS](./PERMISSIONS.md) · [APP-FLOW](./APP-FLOW.md) ·
> [UI-UX-BRIEF](./UI-UX-BRIEF.md) · [API](./API.md)
>
> **Reused from the substrate rather than rebuilt:** three account roles and their
> permission matrix ([PERMISSIONS.md](./PERMISSIONS.md)) — the module gates on the
> pre-existing `ideas:use` and `admin:access` cases and **adds no new permission**,
> which is why the matrix is unchanged; the modal system
> ([COMPONENTS.md](./COMPONENTS.md)); the dashboard shell with permission-driven
> navigation; the Soft UI tokens ([SOFT-UI.md](./SOFT-UI.md)); and the module layering
> the AI Hub and the marketplace established (enum → migration → model → action →
> policy → single-file Livewire screen).
>
> **There is still no team or membership layer to reuse.** The starter kit's team
> feature was removed, so the collaboration half of this spec ("Team members", sharing,
> invitations) would be new work on a second ACL, and is listed under deviations rather
> than treated as a screen away.
>
> **Naming note.** The word "plan" is already spoken for: `App\Enums\RolePlan` is a
> dashboard subscription tier. The module's plans are therefore `IdeaPlan`, on the
> `idea_plans` table, at `/ideas/plans`. Nothing in this module is called `Plan`.
> In-repository documentation calls the application **DevFolio**, while this spec
> addresses `https://shamim.it`; they refer to the same product.

## What is shipped

Ten routes beneath `/ideas`, one operator console at `/admin/ideas`, four module-owned
tables plus the platform's shared `topics` table and the `idea_topic` pivot, four
enums of its own (`IdeaStatus`, `PlanStatus`, `TaskStatus`, `IdeaPotential`) alongside
the shared `WorkPriority` and `ContentVisibility`, thirteen actions, four policies,
eight workspace and public screens.

| Surface | Path | Who reaches it |
| --- | --- | --- |
| Public index | `/ideas` | public, published ideas only |
| Public detail | `/ideas/{idea:slug}` | public when published; otherwise the author or `admin:access` |
| Workspace home | `/ideas/dashboard` | `auth`, `verified`, `ideas:use` |
| Capture / edit | `/ideas/create`, `/ideas/{idea:slug}/edit` | same |
| Categories | `/ideas/categories` | same |
| Plans | `/ideas/plans`, `/ideas/plans/create`, `/ideas/plans/{plan:slug}`, `/ideas/plans/{plan:slug}/edit` | same |
| Operator console | `/admin/ideas` | `admin:access` |

The four module tables are `idea_categories` (per-account folders), `ideas`,
`idea_plans` and `idea_tasks`. Tasks hang off a plan and carry no `user_id` of their
own: ownership derives through `$task->plan->user_id`, so a task can never drift away
from the account that owns its plan, and every mutating action re-asserts the parent's
owner. Filing an idea by subject goes through two tables the module does not own,
`topics` and the `idea_topic` pivot, shared with the Developer Lab — see
[§ Two taxonomies](#two-taxonomies-and-which-one-is-public).

Publication is the conjunction the hub and the marketplace already use — `visibility`
is `ContentVisibility::Public` **and** `published_at` is set — and it is only available
at the stage the workflow allows: `IdeaStatus::Approved`. `SyncIdeaVisibility` is the
only writer of the pair.

Routing order is the first constraint on this URL space, because `/ideas/{idea:slug}`
is a catch-all one segment deep. Workspace literals (`dashboard`, `create`,
`categories`, `plans`) are registered ahead of it, in one section of
`routes/web.php` so the ordering is legible on one screen, and
`app/Rules/IdeaSlug.php` refuses those words plus `edit` as slugs on top of format and
uniqueness. An account must not be able to name an idea "plans" and shadow the plans
screen; both defences are required, and both are pinned by tests.

### Two taxonomies, and which one is public

An idea is filed twice, and the difference between the two filings is the point.

`IdeaCategory` is one account's private filing system. It is per-user, its slug is
unique inside that account (`unique(['user_id', 'slug'])`) rather than across the
application, every list of it is written through `authoredBy()`, and it stays private.
Its name is printed on an idea card only when the reader owns the idea, which is
decided by the `showCategory` prop in
`resources/views/components/app/ideas/card.blade.php` and defaults to exactly that
test. A folder says how somebody organises their notes, which is not something the
site has a right to publish, so no public surface — the index, an idea page, or a
topic shelf — can leak it.

`App\Models\Topic` is the other half: a platform-owned, public, shared subject
vocabulary. An operator seeds it, an author picks from it when saving an idea, and it
is the facet a stranger browses by. One flat table serves both Ideas and the Developer
Lab, through the `idea_topic` and `lab_snippet_topic` pivots, because "what this is
about" means the same thing to an idea and to a snippet. It is **not**
`App\Models\AiTopic`, which is the hub's own hierarchical tree with a `parent_id` — a
different shape for a different module, and the two are not interchangeable.

Filing is capped at `Topic::MAX_PER_ROW`, eight topics to an idea: a facet is
navigation, and a wall of twelve chips is not. An id that is not an integer, does not
exist, or has been switched off by an operator is dropped silently by
`App\Models\Topic::syncRows()` rather than failing the save, because a stray checkbox
should not cost somebody twenty minutes of writing. The sync runs inside the
transaction `App\Actions\Ideas\SaveIdea` already opens, through
`App\Actions\Ideas\SyncIdeaTopics`, so a row and its subjects cannot disagree
mid-write. An absent `topics` key is not the same instruction as an empty one: a
caller that does not file by topic must not clear what the author chose.

A topic is invisible to the public until a **published** idea carries it. The gate is
`App\Services\PublicTaxonomy`, shared with the gallery and with the two platform pages
`/topics` and `/topics/{topic:slug}`: a chip appears only when a row a stranger can
open sits behind it, so a subject an operator switched off and a subject seeded but
never published under disappear for the same reason. The query parameters the index
takes beside `?q=` and `?page=` are documented in [API](./API.md).

### Deviations from the spec, and why

| The spec asks for | Shipped instead | Reason |
| --- | --- | --- |
| Idea → Plan → Task → **Project** → Release | Ideas, plans and tasks. No `projects` table | A project is a delivery object with its own lifecycle, budget and owners. Four columns on a plan would be a project in name only, and a second table invented now would have to be reconciled with the real one later |
| Milestones and progress percentage | A task completion ratio on the plan row | Derived from the tasks that exist. A progress number that nobody can edit is honest; one that can be set to 60% by hand is decoration |
| Sharing, invitations, per-item comments | Not built. Publishing is the only form of sharing | Each of these needs a permission the module does not have, and `AccountPermission` was left untouched on purpose. Adding cases would break the pinned role matrix and require a migration of the matrix data itself |
| Revision history and an activity log | Not built | A history table is a second write path on every save and a pruning problem on a SQLite monolith. Nothing in the current lifecycle requires it |
| Estimated value or potential as a number | `IdeaPotential`: Low, Medium, High | Three honest steps instead of a fake decimal |
| Full-text search | `LIKE` over title, summary and content | The default connection is SQLite, which has no full-text index configured here. This is substring matching, and the UI describes it as substring matching rather than as a search engine. The public index waits for two characters before filtering at all; that floor sits on the screen and not in `Idea::search()`, which still means what its name says |
| Tags and cover images | Not built | Uploads need a disk, a signed route and a prune command, as the feed's media does. Half-wiring them is worse than waiting |
| Categories as a shared taxonomy | `idea_categories` stays one account's own folders; the public facet is the platform's own `topics` list | Reusing the blog's `categories` and `tags` would leak user taxonomy into blog filters and public navigation, and the public index still has no *category* facet, because another account's folders are not a public taxonomy. What a stranger may browse by is a subject the platform seeded and the author chose — [§ Two taxonomies](#two-taxonomies-and-which-one-is-public) |
| Status set from the spec | `IdeaStatus`: Inbox, Draft, UnderReview, Approved, Archived; `PlanStatus`: Draft, Planning, Ready, InProgress, OnHold, Completed, Cancelled | Transitions are enum-owned (`allowedTransitions()` / `canTransitionTo()`), the way `AiPublicationStatus` already treats a workflow, so "Archived back to Approved" is refused by the type rather than by a UI promise |
| Task statuses: Backlog, To Do, In Progress, Blocked, In Review, Completed, Cancelled | `TaskStatus`: Todo, InProgress, Blocked, Done | Four states is what a checklist on a plan document can actually be walked through. `In Review` and `Cancelled` describe a delivery pipeline a plan does not have, and `Backlog` is what the idea bank already is |
| Priority including `Normal` | `WorkPriority`: Low, Medium, High, Urgent | The spec's own list has both `Medium` and `Normal`, which is one slot asked to mean two things. `Medium` is the middle, and a task with no priority set is left unranked rather than silently called normal |
| Task assignee, parent task, subtasks, start date, estimated and actual effort, checklist, comments, attachments, activity history | Title, description, status, priority, due date, sort order, completed timestamp | An assignee needs the sharing model this build does not have, a parent task needs a recursive tree and a reorder rule that is not "swap two rows", and effort needs a time tracker behind it. Each is a column that would be a promise about a feature |
| Kanban / board views, duplicate idea, duplicate plan | List views with tab filters, and no duplicate action | A board is the same rows in columns, and the columns would be `TaskStatus`'s four cases; the plan screen's tabs already filter on them. Duplication is a slug-and-children copy that reads as trivial until the children are tasks with sort order, and nothing asked for it yet |
| Publish approved plans to a public portfolio | Plans are never public. `IdeaPlan` has no `visibility` or `published_at`, and its `isVisibleTo()` answers false for a stranger | Decision for this build was that the publishable objects are the idea and the snippet. A plan carries the reasoning behind an idea, including the parts marked out of scope; publishing it is a separate product call, not a checkbox |
| Notifications (Module I) | Not built | Everything in that list except "assigned tasks" depends on sharing, and assigned tasks do not exist. `app/Notifications` was empty and has been removed rather than kept as a hint of intent |
| Link multiple relevant ideas | One plan carries one `idea_id`, unique in the schema | The link is provenance, and provenance is singular: "this plan came out of that idea". A many-to-many would need a pivot whose only purpose is saying which of several ideas was the source |

### What conversion does

`ConvertIdeaToPlan` is the one behaviour in this module with a guarantee attached: the
idea row survives unchanged, the plan carries `idea_id` as a `nullOnDelete` link so
deleting the idea never destroys the plan it produced, the plan slug derives from the
idea slug with a `-plan` suffix, the summary and content are copied forward into the
plan's problem statement and notes, and the idea's status does **not** move. Having a
plan is not a later stage of thinking about an idea, so the two workflows stay separate
objects. Eligibility and singularity are enforced twice: the action refuses an idea that
is not `Approved` or that already has a plan, and `idea_plans.idea_id` carries a UNIQUE
index, so the second attempt cannot be written by a request that skipped the class. The
requirement lives in that one class and is pinned by a test rather than left to the
screen that triggers it.

### What a published idea says about itself

Publishing is a machine-readable claim as well as a page. A published idea emits an
`Article` node and a `BreadcrumbList`, pushed through `App\Support\Seo::$jsonLd` from
`resources/views/pages/ideas/⚡show.blade.php`. The author node is a `Person` carrying
a `name` and never an email address — an address in structured data is an address
published for harvesting, and "this person wrote it" does not need one — and its `url`
appears only when the account has a handle, for the same reason the byline renders a
plain name instead of a link when it does not. There is no `image` and no `license`
rather than an invented one. A draft that its author is reading sets `noindex` and
emits no structured data at all, because a draft announcing itself to a search engine
is a leak with extra steps.

Reading is the page's job, so it borrows the layer the blog articles already use
instead of inventing a second one: a table of contents derived from
`MarkdownRenderer::outline()` run over the same single Markdown render the body is
printed from, the scroll progress rule that `resources/js/app.js` drives from the
`data-reading-target` attribute, word count and reading time, a share bar above and
below the prose, and previous/next links. Neither figure is stored — a column would be
a second answer to a question the content already answers, and it would be wrong the
first time its author edited the idea. The neighbours walk the whole published set
while the related-ideas rail stays with the same author, so the two are different ways
out of the page rather than the same way twice.


## 1. AI Developer Role

Act as a Senior Laravel Architect, Product Manager, UI/UX Designer, Database Engineer, Security Engineer, and Full-Stack Developer.

Your task is to design and implement a production-ready **Idea — Ideas, Plans, Tasks, Projects & Collaboration Management System** inside my existing personal developer portfolio website:

**Website:** `https://shamim.it`

The system must help me capture spontaneous ideas, organize long-term plans, break plans into actionable tasks, track project progress, and collaborate with other users through controlled plan sharing.

Do not build an unrelated standalone application. Integrate Idea into my existing Laravel portfolio while preserving the current website, authentication, design conventions, and working features.

Do not stop at a conceptual design. Produce the documentation, implementation plan, database architecture, UI, routes, permissions, and working application in clearly defined phases.

---

## 2. Product Vision

Idea is a personal innovation workspace integrated into my developer portfolio.

Whenever I think of a new SaaS product, Laravel package, AI tool, automation system, programming project, or business idea, I should be able to save it quickly and develop it later.

The system must support this lifecycle:

Idea → Plan → Tasks → Project → Release → Completed

Not every idea must become a plan, and not every plan must become a project. Users must be able to organize ideas independently and convert them when ready.

The system should also allow authorized users to share plans, collaborate, comment, and suggest updates without exposing private information.

### Core objectives

1. Capture ideas before they are forgotten.
2. Organize ideas with categories, tags, and searchable content.
3. Convert ideas into detailed implementation plans.
4. Break plans into tasks and milestones.
5. Connect tasks to their relevant plans and projects.
6. Track status, priority, deadlines, and progress.
7. Share selected plans with specific users or the public.
8. Review comments, suggestions, and shared-plan updates.
9. Maintain an activity timeline and revision history.
10. Showcase selected public ideas and completed projects on my portfolio.

---

## 3. Technology Requirements

First, inspect the existing repository and identify its actual framework versions, packages, authentication system, route conventions, layout, and design system.

Use the following preferred stack where compatible with the existing application:

- Backend: Laravel 13
- Language: PHP 8.5+ where supported by the installed Laravel version and hosting environment
- UI: Laravel Blade and Livewire
- Livewire: Version compatible with the existing application; prefer Livewire 3 if it is already installed
- Styling: Tailwind CSS 4 where compatible with the current build setup
- JavaScript: Alpine.js where needed
- Database: Existing supported database; MySQL is the preferred default
- Build system: Existing Vite configuration
- Authentication: Reuse the current authentication system
- Authorization: Laravel Policies and Gates
- Testing: Pest or PHPUnit according to the existing project

Do not upgrade Laravel, Livewire, PHP, Tailwind, or authentication packages unnecessarily.

Do not replace the existing frontend, rewrite unrelated modules, or duplicate authentication systems.

Use migrations, Eloquent models, form requests or appropriate validation, service classes where justified, and automated tests.

---

## 4. Main Product Modules

### Module A — Idea Dashboard

Create a central dashboard for quick access to all ideas, plans, tasks, and projects.

Dashboard widgets:

- Total ideas
- Unreviewed ideas
- Draft plans
- Active plans
- Tasks due today
- Overdue tasks
- Tasks in progress
- Completed tasks
- Active projects
- Recently updated records
- Upcoming deadlines
- Shared plans
- Pending collaboration requests
- Recent activity

Provide quick actions:

- Capture an Idea
- Create a Plan
- Add a Task
- Create a Project
- Review Shared Updates

Show meaningful empty states for new users.

Do not display fabricated analytics or sample statistics in production.

### Module B — Ideas Management

Ideas are lightweight records for capturing thoughts without requiring a complete plan.

Each idea should support:

- Title
- Slug
- Short summary
- Detailed description
- Rich-text or Markdown content
- Category
- Multiple tags
- Optional cover image
- Source or reference URLs
- Estimated value or potential
- Priority
- Status
- Private/public visibility
- Created date
- Updated date
- Related ideas
- Linked plan
- Linked project
- Author
- Revision history where appropriate

Idea statuses:

- Inbox
- Draft
- Under Review
- Approved
- Archived

Features:

- Quick idea capture
- Full create, read, update, and delete operations
- Search by title and content
- Filter by status, category, tags, and date
- Sort by newest, oldest, priority, and recently updated
- Pin important ideas
- Add notes and references
- Duplicate an idea
- Convert an idea into a plan
- Archive completed or rejected ideas
- Restore archived ideas when appropriate

Conversion from idea to plan must preserve the original idea and link the resulting plan to it.

### Module C — Plans Management

Plans represent structured intentions and implementation strategies.

Each plan should support:

- Title and slug
- Summary
- Problem statement
- Proposed solution
- Goals and measurable outcomes
- Scope and out-of-scope items
- Target audience
- Requirements
- Feature list
- Technical approach
- Technology stack
- Milestones
- Risks and dependencies
- Estimated effort
- Target start and end dates
- Priority
- Status
- Visibility
- Author
- Linked ideas
- Linked tasks
- Linked projects
- Comments and activity history

Plan statuses:

- Draft
- Planning
- Ready
- In Progress
- On Hold
- Completed
- Cancelled

Provide structured sections that can be filled in gradually. Users must not be forced to complete every field before saving a draft.

Features:

- Create and edit plans
- Save drafts
- Duplicate plans
- Archive plans
- Link multiple relevant ideas
- Create tasks directly from a plan
- Track milestone completion
- Display plan progress
- View revision history
- Publish approved plans to the public portfolio
- Share plans privately with selected collaborators
- Receive collaboration suggestions and updates

Do not automatically publish private plans.

### Module D — Tasks Management

Tasks represent actionable work items associated with an idea, plan, or project.

Each task should support:

- Title
- Description
- Parent task, if subtasks are enabled
- Linked idea, plan, and/or project
- Assignee
- Creator
- Priority
- Status
- Start date
- Due date
- Estimated effort
- Actual effort, if tracked
- Completion timestamp
- Checklist or subtasks
- Comments
- Attachments where appropriate
- Activity history

Task statuses:

- Backlog
- To Do
- In Progress
- Blocked
- In Review
- Completed
- Cancelled

Priorities:

- Low
- Normal
- High
- Urgent

Features:

- Create, edit, delete, and restore tasks where supported
- List and Kanban views
- Filter by status, priority, assignee, and deadline
- Mark tasks as complete
- Add subtasks
- Track overdue work
- Connect tasks to milestones
- Reorder tasks within supported views
- Display completion progress on the associated plan or project
- Add comments and record status changes

Progress calculations must use a documented rule, such as completed tasks divided by the total eligible tasks. Exclude cancelled tasks where appropriate and apply the same rule consistently.

Do not allow arbitrary task updates by unauthorized users.

### Module E — Projects Management

Projects represent actual implementation work.

A project may originate from a plan, an idea, or an independently created record.

Project fields:

- Name
- Slug
- Description
- Project goals
- Status
- Priority
- Category
- Tags
- Owner
- Team members
- Start date
- Target completion date
- Actual completion date
- Repository URL
- Live demo URL
- Documentation URL
- Cover image
- Related plans
- Related tasks
- Milestones
- Progress
- Release notes

Project statuses:

- Planned
- Active
- On Hold
- Completed
- Cancelled
- Archived

Features:

- Project dashboard
- Task board
- Milestone tracking
- Progress tracking
- GitHub and demo links
- Project activity timeline
- Project notes
- Public portfolio showcase
- Private internal projects
- Convert a plan into a project while preserving existing relationships

A completed project may be published to the portfolio only when explicitly approved.

### Module F — Categories, Tags & Search

Create reusable categories and tags.

Categories should support:

- Name
- Slug
- Description
- Icon or supported visual identifier
- Sort order
- Active status

Example categories:

- SaaS
- Laravel Packages
- AI & LLM
- Developer Tools
- APIs & Integrations
- Automation
- Business Ideas
- Open Source
- Learning
- Experiments

Tags must support multiple records and allow filtering across ideas, plans, tasks, and projects.

Implement:

- Global Idea search
- Module-specific search
- Filters
- Sorting
- Pagination
- Useful empty states
- Clear filters action

Use database-backed search appropriate for the installed database. Do not introduce a dedicated search engine without a demonstrated need.

### Module G — Sharing & Collaboration

This module is essential.

Users must be able to share selected plans without exposing their entire workspace.

Support these visibility options:

1. Private — accessible only to the owner and authorized administrators.
2. Specific Users — accessible to explicitly invited users.
3. Unlisted Link — accessible to people possessing a secure share link, subject to configured permissions.
4. Public — visible to visitors through the public Idea pages.

Private is the default visibility for new ideas and plans.

Sharing permissions:

- Viewer: Can view permitted content.
- Commenter: Can view and comment.
- Contributor: Can suggest or submit permitted changes.
- Editor: Can directly edit permitted content.
- Owner: Has full control over the record and sharing settings.

Implement the minimum required permissions first and expand only when tested.

Sharing features:

- Invite a registered user
- Generate a secure, revocable share link
- Configure link expiration
- Revoke access
- Configure password protection if supported and required
- View current collaborators
- View pending invitations
- View recent collaboration activity
- Comment on a shared plan
- Submit an update suggestion
- Review suggested changes
- Accept or reject suggestions
- Record accepted changes in the activity timeline
- Notify relevant users of important updates

Do not expose private database IDs or use guessable identifiers as authorization.

A public or unlisted URL must never bypass an explicitly revoked share permission or an expired link.

Store only hashed share tokens where feasible. Do not store plaintext share passwords. Apply rate limits to sensitive endpoints.

For contributor access, prefer a review-and-approve workflow instead of allowing unrestricted edits to the owner's plan.

### Module H — Activity Timeline & Revision History

Record important events:

- Idea created or updated
- Plan created or published
- Task assigned or completed
- Deadline changed
- Project status changed
- Collaborator invited or removed
- Comment added
- Update suggestion submitted
- Suggestion accepted or rejected
- Sharing permissions changed

Activity records should include the actor, action, related record, timestamp, and an appropriate summary.

Use revision history for important editable content.

Do not expose internal security information, private notes, or sensitive data in public activity feeds.

Avoid storing unnecessary personal information in logs.

### Module I — Notifications

Implement in-app notifications for:

- Invitations
- Comments and replies
- Assigned tasks
- Due dates
- Plan update suggestions
- Accepted or rejected suggestions
- Important shared-plan changes

Start with in-app notifications. Add email notifications only when email delivery is properly configured.

Allow users to mark notifications as read and navigate to the relevant record.

Do not create notification loops when updates trigger other automated events.

---

## 5. Public Portfolio Experience

Create a polished public-facing Idea section that matches the existing portfolio.

Suggested primary route:

`/Idea`

Suggested pages:

- `/Idea` — overview and featured content
- `/Idea/ideas` — public ideas
- `/Idea/ideas/{slug}` — idea details
- `/Idea/plans` — approved public plans
- `/Idea/plans/{slug}` — public plan details
- `/Idea/projects` — featured projects
- `/Idea/projects/{slug}` — project details

Use named Laravel routes and validate all slugs.

### Public page sections

1. Hero section introducing Idea.
2. Quick explanation of Ideas → Plans → Tasks → Projects.
3. Featured ideas.
4. Recently published plans.
5. Public project progress.
6. Categories and tags.
7. Search and filters.
8. Selected progress summaries.
9. Calls to action for viewing details or exploring projects.

Public visitors must see only explicitly published records.

Never expose:

- Draft ideas
- Private plans
- Internal notes
- Private task descriptions
- Confidential attachments
- Internal collaborator details
- Private comments
- Unapproved suggestions
- Unauthorized project information

Do not make private content public simply because its parent record is public.

Public pages should support responsive layouts, accessible interactions, meaningful loading and empty states, metadata, canonical URLs, and social sharing metadata.

---

## 6. Admin Panel & Content Publishing

Use the existing authenticated admin system.

Do not create a separate login system if the portfolio already has one.

Suggested admin routes:

- `/admin/Idea`
- `/admin/Idea/ideas`
- `/admin/Idea/plans`
- `/admin/Idea/tasks`
- `/admin/Idea/projects`
- `/admin/Idea/categories`
- `/admin/Idea/tags`
- `/admin/Idea/shares`
- `/admin/Idea/activity`

Admin workflow:

1. Log in to the existing admin panel.
2. Open Idea.
3. Select Ideas, Plans, Tasks, or Projects.
4. Create or edit a record.
5. Save as draft or publish where supported.
6. Preview the public version before publishing.
7. Manage visibility and collaboration permissions.
8. Review activity and revision history.

Implement server-side authorization for every protected action.

Do not rely on hiding buttons in the UI as a security mechanism.

---

## 7. Database Architecture

Design normalized migrations and Eloquent relationships.

Evaluate the following tables and create only those needed for the actual implementation:

- `ideas`
- `plans`
- `tasks`
- `projects`
- `milestones`
- `categories`
- `tags`
- Pivot tables for tags
- Relationship tables for linked ideas, plans, tasks, and projects where many-to-many relationships are needed
- `plan_collaborators` or an equivalent access-control structure
- `plan_invitations`
- `share_links`
- `comments`
- `comment_replies` or a supported self-referencing comment relationship
- `change_suggestions`
- `activity_logs`
- `record_revisions`
- Laravel notifications table if database notifications are used

Reuse Laravel's users table and existing identity model.

Database requirements:

- Foreign keys
- Appropriate indexes
- Unique slugs where required
- Consistent timestamps
- Explicit status and visibility fields
- Appropriate deletion behavior
- Transactional operations for multi-step conversions
- Ownership relationships
- Soft deletes where restoration is useful
- Safe handling of orphaned relationships

Avoid storing every relationship as an arbitrary JSON field. Use relational tables for relationships that require filtering, authorization, or querying.

Use JSON only for suitable structured metadata.

Document the final schema and relationship diagram.

---

## 8. Laravel Application Architecture

Follow the existing application structure.

Organize Idea code into clearly separated responsibilities.

Suggested conceptual organization:

- Models
- Policies
- Form Requests or equivalent validation
- Services
- Events and listeners where justified
- Notifications
- Livewire components
- Blade views
- Database migrations
- Factories and seeders
- Feature and unit tests

Suggested UI organization:

- `layouts`
- `dashboard`
- `ideas`
- `plans`
- `tasks`
- `projects`
- `sharing`
- `comments`
- `notifications`
- `settings`

Use descriptive, consistent naming.

Keep complex business logic out of Blade templates.

Use database transactions when converting records or accepting proposed changes.

Use authorization policies for viewing, updating, deleting, publishing, commenting, and sharing.

Use queued jobs only where asynchronous work is useful.

Avoid unnecessary abstractions, oversized components, duplicated business logic, and overengineering.

Document important architectural decisions.

---

## 9. UI/UX Design System

Design Idea as a modern developer productivity workspace.

Visual direction:

- Clean, professional, developer-focused interface
- Dark cinematic base where compatible with the existing portfolio
- Subtle blue and purple accents
- Glass effects used selectively
- Clear typography and information hierarchy
- Responsive cards, tables, and Kanban boards
- Restrained animations
- Accessible contrast
- Keyboard-friendly controls
- Consistent spacing and component styles

Avoid excessive cyberpunk effects, unnecessary glowing elements, distracting animations, and oversized decorative components.

Desktop layout:

- Sidebar navigation
- Top header
- Breadcrumbs
- Search
- Quick-create actions
- Main content area
- Optional contextual detail panel

Mobile layout:

- Collapsible navigation
- Touch-friendly actions
- Responsive cards
- Usable filters
- Accessible dialogs and forms

Reusable UI components:

- Status badge
- Priority badge
- Idea card
- Plan card
- Task row
- Project card
- Progress indicator
- Tag selector
- Search input
- Filter panel
- Rich-text or Markdown editor
- Comment thread
- Activity timeline
- Confirmation dialog
- Share dialog
- Empty state
- Pagination

Reuse existing design tokens and UI components whenever possible.

---

## 10. User Flows

### Flow A — Capture a new idea

1. Open Idea.
2. Click Capture Idea.
3. Enter a title and optional notes.
4. Select category and tags if desired.
5. Save immediately as a private record.
6. Return later to expand the idea.
7. Convert it into a plan when ready.

### Flow B — Create an implementation plan

1. Open an existing idea.
2. Select Convert to Plan.
3. Review the copied title and description.
4. Add goals, scope, features, milestones, and technical details.
5. Save as draft.
6. Create tasks from the plan.
7. Track readiness and progress.

### Flow C — Manage tasks

1. Open the plan or project.
2. Create tasks and assign priorities.
3. Set deadlines and dependencies when supported.
4. Update task status as work progresses.
5. Review overdue and completed work.
6. Track overall progress.

### Flow D — Convert a plan into a project

1. Open an approved plan.
2. Select Create Project.
3. Carry over relevant goals and relationships.
4. Select project dates and status.
5. Link or create implementation tasks.
6. Add repository and demo URLs.
7. Publish the project to the portfolio only when approved.

### Flow E — Share a plan

1. Open the plan.
2. Select Share.
3. Choose private invitation, unlisted link, or public visibility.
4. Select permitted actions.
5. Generate the invitation or share link.
6. Recipient opens the plan.
7. The server verifies the access token and permission.
8. Recipient comments or submits an update suggestion.
9. The owner reviews the change.
10. Approved changes update the plan and activity history.

### Flow F — Publish content

1. Create or edit content in Admin Panel.
2. Save the draft.
3. Preview it.
4. Confirm public visibility and content.
5. Publish.
6. Verify the public page.
7. Unpublish or archive the content when appropriate.

---

## 11. Security Requirements

Security is mandatory.

Implement:

- Server-side authentication and authorization
- Laravel Policies and Gates
- CSRF protection
- Input validation
- Output escaping
- Protection against stored and reflected XSS
- Safe Markdown or rich-text rendering
- Rate limiting
- Secure invitation tokens
- Expiring and revocable share links
- Safe file uploads
- File type and size validation
- Authorization for file downloads
- Protection against IDOR
- Safe public/private query scopes
- Audit records for sensitive permission changes
- Secure password handling
- Proper production error handling

Never trust client-side role values, record ownership fields, status changes, or submitted permissions.

A user must not be able to modify another person's plan merely by changing a URL, record identifier, request parameter, or Livewire component state.

Test all visibility and authorization boundaries.

---

## 12. Documentation Deliverables

Create and maintain these Markdown documents:

- `docs/README.md`
- `docs/PRD.md`
- `docs/TRD.md`
- `docs/DATABASE.md`
- `docs/ROUTES.md`
- `docs/PERMISSIONS.md`
- `docs/UI-UX.md`
- `docs/WORKFLOWS.md`
- `docs/ROADMAP.md`
- `docs/TESTING.md`
- `docs/DEPLOYMENT.md`
- `docs/CHANGELOG.md`

Documentation must describe the actual implemented system, not imaginary features.

Include:

- Product scope
- User stories
- Acceptance criteria
- Architecture diagrams
- Entity relationship diagram
- Route map
- Permission matrix
- Component inventory
- Database indexes
- State transitions
- Error handling
- Testing instructions
- Migration and rollback guidance
- Deployment configuration
- Known limitations

---

## 13. Implementation Roadmap

Build the system in these phases.

### Phase 0 — Repository Audit

Inspect the existing Laravel application.

Identify framework versions, authentication, admin layout, database, frontend dependencies, route conventions, and existing reusable components.

Document findings before making changes.

### Phase 1 — Foundation

Implement:

- Idea navigation
- Database migrations
- Models and relationships
- Authorization policies
- Admin navigation
- Basic dashboard
- Factories and tests

### Phase 2 — Ideas

Implement:

- Idea CRUD
- Draft and archive workflow
- Categories and tags
- Search and filtering
- Public idea pages
- Idea-to-plan conversion

### Phase 3 — Plans

Implement:

- Plan CRUD
- Structured planning sections
- Visibility controls
- Milestones
- Progress summaries
- Linked ideas and tasks
- Preview and publishing workflow

### Phase 4 — Tasks

Implement:

- Task CRUD
- Status and priority
- Deadlines
- Subtasks where needed
- List and Kanban views
- Progress calculation
- Overdue task filtering

### Phase 5 — Projects

Implement:

- Project CRUD
- Plan-to-project conversion
- Project milestones
- Repository and demo links
- Task relationships
- Portfolio project showcase

### Phase 6 — Sharing & Collaboration

Implement:

- User invitations
- Access-control policies
- Secure share links
- Comments
- Update suggestions
- Approval workflow
- Revision history
- Activity timeline

### Phase 7 — Notifications & Polish

Implement:

- In-app notifications
- Responsive UI
- Accessibility improvements
- Metadata and SEO
- Performance improvements
- Production error handling

### Phase 8 — Verification & Release

Run migrations, automated tests, authorization tests, build checks, and manual acceptance tests.

Fix regressions before release.

Update documentation to match the final implementation.

---

## 14. Acceptance Criteria

The implementation is accepted only when:

1. I can create and edit ideas from the admin interface.
2. New ideas remain private until explicitly published.
3. I can search and filter ideas.
4. I can convert an idea into a plan without losing its original data.
5. I can create tasks linked to a plan.
6. I can track task and project progress.
7. I can convert a plan into a project.
8. I can publish approved content to my portfolio.
9. I can share a selected plan with authorized users.
10. Unauthorized users cannot read or modify private plans.
11. Shared-plan comments and suggestions follow the assigned permissions.
12. I can revoke a share link or collaborator's access.
13. Accepted suggestions are reflected in revision or activity history.
14. Public pages do not reveal private records.
15. All core workflows work on desktop and mobile.
16. Migrations, authorization tests, feature tests, and production build checks pass.
17. Existing portfolio functionality remains intact.
18. The documentation reflects the implemented behavior.

Do not claim that tests passed unless they were actually executed and passed.

---

## 15. AI Coding Instructions

Work systematically.

Before writing code:

1. Inspect the repository.
2. Identify the existing architecture.
3. List reusable components and dependencies.
4. Write the PRD and TRD.
5. Design the schema and authorization matrix.
6. Define implementation milestones.

During implementation:

1. Work phase by phase.
2. Make small, reviewable changes.
3. Follow existing conventions.
4. Avoid duplicating existing functionality.
5. Add validation and authorization with every relevant feature.
6. Write tests alongside implementation.
7. Run tests and build checks after meaningful changes.
8. Update documentation when behavior changes.
9. Report completed work, changed files, test results, and remaining issues.
10. Do not silently skip incomplete requirements.

Do not generate thousands of lines of unreviewable code in one response.

Do not create placeholder buttons that appear functional but do nothing.

Do not fabricate integrations, test results, database records, or deployment success.

If a requirement conflicts with the existing application, explain the conflict and propose the smallest compatible solution.

If the environment cannot execute commands or modify files, provide exact file paths and implementation instructions rather than claiming the application has been built.

## Final Objective

Deliver a maintainable, secure, responsive, and production-oriented Idea system integrated into `shamimhossain.bd`.

The system must provide a practical workspace for capturing ideas, developing plans, executing tasks, managing projects, sharing selected plans, reviewing changes, and publishing approved work to my developer portfolio.

Prioritize a reliable MVP first, then expand through the documented roadmap.

Begin with Phase 0: audit the existing repository and report the architecture, risks, implementation sequence, and files that need to be created or changed. Do not overwrite existing application files before understanding their purpose.
