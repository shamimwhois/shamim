# Idea — Master Product & Development Prompt

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
