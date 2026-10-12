# shamim.it Developer Lab — Master PRD & Implementation Prompt

## 1. Project Overview

Build a modern, secure, responsive, browser-based coding and practice environment named **Developer Lab** inside the existing **shamim.it** platform at `https://shamim.it`.

Developer Lab will help students, beginners, and professional developers write code, run supported programs, inspect output, debug errors, practice programming, complete challenges, and save their work.

The primary workflow is:

**Write → Run → Inspect → Test → Debug → Improve**

The experience should feel like a lightweight online IDE: easy to start using, fast, accessible, and powerful enough to support structured programming practice.

### Core principles

- Keep the first release simple and practical.
- Build only Developer Lab functionality.
- Reuse the existing application's authentication, layouts, navigation, components, permissions, and coding conventions.
- Do not rebuild or duplicate the existing application.
- Do not execute untrusted code inside the main Laravel application process.
- Design the architecture for future expansion without overengineering the MVP.

---

## 2. Technology Stack and Repository Rules

Use the following stack where compatible with the existing project:

- **Backend:** Laravel 13
- **PHP:** PHP 8.5+ when supported by the deployment environment and dependencies
- **Frontend:** Livewire 3, if already installed
- **Styling:** Tailwind CSS 4, if compatible with the current build
- **Interactions:** Alpine.js
- **Build system:** Existing Vite configuration
- **Code editor:** Monaco Editor or CodeMirror
- **Queue:** Existing queue infrastructure; Redis where available and justified
- **Testing:** Existing PHPUnit/Pest setup and frontend testing tools
- **Execution:** A separately isolated code-execution service

Do not upgrade dependencies or replace the existing frontend stack without first establishing compatibility and documenting the reason.

### Mandatory repository audit

Before writing code:

1. Inspect the existing Laravel application and repository structure.
2. Identify Laravel, PHP, Livewire, Tailwind CSS, Alpine.js, and Vite versions.
3. Review existing authentication, authorization, layouts, navigation, middleware, and user roles.
4. Review existing database conventions, queue configuration, storage, logging, testing, and deployment setup.
5. Determine which code-execution capabilities the current hosting environment can safely support.
6. Identify reusable components and existing services.
7. Document findings, risks, dependencies, and implementation decisions.

Do not assume a feature, package, service, or runtime is installed unless the repository confirms it.

---

## 3. Product Scope

### MVP features

- Developer Lab dashboard
- Browser-based code editor
- Language selection
- HTML/CSS/JavaScript live preview
- Console and output panel
- Error inspection
- Secure PHP execution through an isolated runner
- Basic programming challenges
- Test results and pass/fail status
- Private code snippets
- User workspaces and saved files
- Execution history
- Execution limits and usage tracking
- Responsive editor interface
- Basic administrator controls
- Technical documentation and automated tests

### Explicitly out of scope for the first release

- Full cloud development environments
- Complete remote Linux desktops
- Collaborative multiplayer editing
- Integrated Git hosting
- Arbitrary terminal access
- Unlimited package installation
- Production Laravel application hosting for arbitrary users
- Arbitrary background servers or persistent processes
- A complex AI coding agent that automatically changes and executes user code

These features may be considered in later phases after the core platform is stable and secure.

---

## 4. Routes and Navigation

Use the existing routing and authentication conventions. The following routes are proposed and must be adjusted if equivalent routes or conventions already exist.

### Public or authenticated application routes

- `/it-flash/lab` — Developer Lab homepage
- `/it-flash/lab/editor` — Coding workspace
- `/it-flash/lab/practice` — Practice dashboard
- `/it-flash/lab/challenges` — Challenge catalog
- `/it-flash/lab/challenges/{slug}` — Challenge details
- `/it-flash/lab/snippets` — Snippet library
- `/it-flash/lab/snippets/{slug}` — Public snippet details
- `/it-flash/lab/learn` — Programming learning paths
- `/it-flash/lab/history` — Authenticated execution history

Use authentication for saving private workspaces, accessing private snippets, viewing personal execution history, and submitting progress.

Public pages must never expose private source code, hidden tests, private execution results, or other users' workspaces.

### Administrator routes

Integrate into the existing admin area using established authorization conventions.

Proposed sections:

- `/admin/it-flash/lab`
- `/admin/it-flash/lab/languages`
- `/admin/it-flash/lab/challenges`
- `/admin/it-flash/lab/snippets`
- `/admin/it-flash/lab/executions`
- `/admin/it-flash/lab/quotas`
- `/admin/it-flash/lab/settings`

Administrators must have explicit permissions for sensitive operations.

---

## 5. Developer Lab Dashboard

Create a clean dashboard that helps users start coding immediately.

### Dashboard sections

1. Welcome header and primary **Open Editor** action
2. Quick-start cards for supported languages
3. Continue working on recent workspaces
4. Recently executed programs
5. Recommended practice challenges
6. Learning progress
7. Saved snippets
8. Execution usage and applicable limits
9. Helpful getting-started resources

Only show functionality that is implemented and available to the current user.

Use empty states with helpful instructions when a user has not saved code or completed challenges.

Avoid unnecessary charts, excessive cards, decorative animations, and complicated navigation.

---

## 6. Online Code Editor

### Desktop layout

Design a professional, IDE-inspired interface with:

- **Top toolbar:** language selector, workspace name, Save, Run, Reset, and settings
- **Left panel:** file explorer and workspace files
- **Center panel:** code editor
- **Right panel:** live preview or execution output
- **Bottom panel:** console, errors, test results, and execution information

Support resizable and collapsible panels when practical.

### Mobile layout

Provide a mobile-friendly experience with switchable panels:

- Editor
- Preview
- Output
- Tests
- Files

Do not force a desktop-sized editor onto small screens. Preserve code readability and make primary actions easy to reach.

### Editor functionality

Implement according to editor-library capabilities:

- Syntax highlighting
- Line numbers
- Code indentation
- Find and replace
- Keyboard shortcuts
- Theme selection
- Basic formatting where a suitable formatter exists
- Error and warning markers where available
- File tabs when multiple files are supported
- Unsaved-change indicators
- Copy and download code
- Reset to starter template
- Save and reopen workspaces

Do not create custom implementations of features already provided by the selected editor library.

### Editor performance

Code editing must primarily happen on the client side. Do not send a Livewire request for every keystroke.

Synchronize editor content with the backend only when needed, such as explicit saves, autosaves with debouncing, or execution requests.

Preserve unsaved code during ordinary UI interactions where practical.

---

## 7. Supported Languages and Runtime Registry

### Initial language support

**HTML, CSS, and JavaScript**

- Run in a controlled browser preview.
- Support separate HTML, CSS, and JavaScript files.
- Display live preview updates.
- Capture browser-console output and relevant runtime errors.
- Provide a resettable starter template.

**PHP**

- Execute through an isolated server-side runner.
- Capture standard output, standard error, exit status, and execution time.
- Support bounded execution and clear error messages.
- Never evaluate submitted PHP using the main Laravel application's PHP process.

**SQL**

Add only when a disposable, restricted database execution environment is available. Do not run user-submitted SQL against the production database.

### Future languages

Consider Python, TypeScript, C, C++, C#, Java, Go, Rust, and other languages individually after their execution environments pass security and deployment validation.

### Language capability registry

Maintain a configurable registry describing each language's capabilities:

- Language identifier and display name
- Syntax-highlighting mode
- Enabled/disabled status
- Execution method
- Live-preview support
- Standard input support
- Multi-file support
- Unit-test support
- Dependency support
- Default starter template
- Execution and resource limits

Only enable a capability when its implementation is available and tested.

Do not display a functional Run button for a language that cannot actually execute code.

---

## 8. Live Preview and Browser Execution

For HTML, CSS, and JavaScript, provide an isolated preview.

Requirements:

- Keep user JavaScript isolated from the main application's origin.
- Use an appropriately sandboxed iframe or a dedicated isolated preview origin.
- Apply suitable Content Security Policy restrictions.
- Prevent access to authenticated application cookies, private storage, and internal application APIs.
- Avoid unnecessary permissions such as top-level navigation, popups, or unrestricted same-origin access.
- Do not inject arbitrary user scripts into privileged application pages.
- Capture preview errors and console output using a controlled communication channel.
- Provide Refresh Preview and Reset controls.

Test malicious inputs and attempts to access the parent page, authentication state, or application resources.

Security must be based on browser isolation and origin boundaries, not on a visual preview panel alone.

---

## 9. Secure PHP Code Execution Architecture

Treat every submitted program as untrusted.

### Required execution flow

1. User selects a supported language and submits code.
2. Laravel validates the language, source size, file count, permissions, and quota.
3. The application creates an execution record.
4. A job or execution request is sent to the isolated execution service.
5. The execution service starts a restricted, temporary environment.
6. The program runs within enforced resource limits.
7. Standard output, standard error, exit status, duration, and relevant test results are collected.
8. The runner terminates the program and removes temporary resources.
9. Laravel stores an appropriately limited result and updates usage records.
10. The editor displays the result and allows the user to inspect errors.

### Isolation requirements

The execution environment must enforce:

- Non-root execution
- CPU limits
- Memory limits
- Wall-clock timeouts
- Process and thread limits
- Output-size limits
- Restricted filesystem access
- Temporary, isolated writable directories
- No privileged container mode
- No host filesystem mounts containing sensitive data
- No access to application secrets or environment variables
- No production database credentials
- Restricted or disabled outbound network access
- No access to cloud metadata endpoints
- Automatic process termination
- Automatic cleanup of temporary resources
- Rate limiting and concurrency limits

Docker can be part of the solution but must not be treated as a complete security boundary by itself. Evaluate stronger isolation, such as microVMs, when deployment risk warrants it.

### Critical prohibition

Never execute user-submitted PHP, Python, shell commands, or other arbitrary programs:

- Through `eval()` in the application
- By including submitted files in the main Laravel application
- Inside privileged queue workers
- In a production web-server process
- With production credentials or unrestricted filesystem/network access

If the available hosting environment cannot provide a secure runner, implement the editor and browser preview first. Keep server-side execution disabled until an appropriate execution service is configured.

---

## 10. Execution Limits and Abuse Prevention

Make limits configurable through administrator settings or environment-specific configuration.

At minimum, define limits for:

- Maximum source-code size
- Maximum files per workspace
- Maximum individual file size
- Maximum CPU time
- Maximum wall-clock execution time
- Maximum memory
- Maximum process count
- Maximum output size
- Maximum concurrent executions
- Daily or hourly execution quotas
- Temporary storage
- Dependency installation
- Execution queue waiting time

Requirements:

- Enforce quotas on the server side.
- Apply rate limits by user and appropriate network-level identifiers.
- Prevent users from bypassing limits by repeating requests.
- Handle duplicate submissions safely.
- Terminate timed-out executions.
- Record usage for auditing and operational monitoring.
- Return clear messages when quotas are exceeded.
- Prevent expensive workloads from exhausting application resources.

Never offer unlimited public code execution by default.

---

## 11. Practice Challenges and Automated Testing

Create a structured programming challenge system.

### Challenge fields

Each challenge should support:

- Title
- Slug
- Description
- Learning objectives
- Language
- Difficulty
- Category
- Starter code
- Input/output examples
- Instructions
- Hints
- Expected behavior
- Published status
- Ordering and estimated completion time

### Difficulty levels

- Beginner
- Intermediate
- Advanced

### Challenge workflow

1. Open a challenge.
2. Read the problem statement.
3. Load starter code into the editor.
4. Write and run a solution.
5. Submit the solution.
6. Execute the relevant tests in the trusted runner.
7. Display pass/fail status and permitted feedback.
8. Save submission history and update progress.

### Test security

- Execute submitted solutions in the isolated runtime.
- Keep hidden tests on the trusted server side.
- Never send hidden test definitions to the browser.
- Limit the number and cost of test executions.
- Normalize test results without exposing server paths, secrets, or internal infrastructure.
- Distinguish sample-test results from full submission results.

Only enable language-specific testing tools when their execution environment is securely configured.

---

## 12. Workspaces and Code Snippets

Allow authenticated users to save and organize their work.

### Workspace features

- Create, rename, open, and delete workspaces
- Save source files
- Store the selected language
- Store optional starter-template and challenge references
- Restore saved work
- Track created and updated timestamps
- Keep private work private by default

### Snippet features

- Create and edit snippets
- Store a title and description
- Store language and source code
- Search and filter snippets
- Copy or download code
- Optionally publish a snippet
- Support ownership checks and visibility controls

### Privacy and ownership

- Private is the default visibility.
- Validate ownership for every read, update, delete, and share operation.
- Warn users before publishing code publicly.
- Never make a private workspace public through an accidental default.
- Apply source-size limits.
- Do not expose access tokens, secrets, or private execution data.
- Avoid logging complete source code unless explicitly justified and protected.

Use pagination for large collections.

---

## 13. Execution History

Create a personal history page that shows:

- Language
- Workspace or challenge
- Execution status
- Start time
- Duration
- Exit status
- Limited output preview
- Test summary when applicable

Users should be able to inspect their own execution records and reopen eligible workspaces.

Do not expose internal sandbox paths, infrastructure details, environment variables, or other users' execution data.

Define a retention policy for execution records and output. Apply stricter retention to temporary execution artifacts than to user-saved workspaces.

---

## 14. Learning Paths

Provide a small set of structured learning paths that connect programming concepts with hands-on practice.

Suggested paths:

1. HTML and CSS Fundamentals
2. JavaScript Fundamentals
3. PHP Basics
4. PHP Functions and Arrays
5. Object-Oriented PHP
6. Laravel Fundamentals
7. Laravel Testing
8. PHP Debugging
9. SQL Practice
10. Problem-Solving Fundamentals

Each path can contain modules, lessons, exercises, and linked challenges.

Track completion for authenticated users. Keep learning paths lightweight in the MVP and avoid building an entire learning-management system.

Do not mark a lesson or challenge complete unless the corresponding completion criteria are satisfied.

---

## 15. Optional AI Coding Assistant

Implement only after the editor, execution system, and security foundations are stable.

Possible actions:

- Explain an error
- Explain selected code
- Suggest a fix
- Generate test cases
- Explain test failures
- Suggest a refactoring
- Explain a programming concept

Requirements:

- Invoke AI only when the user explicitly requests assistance.
- Never claim code has run unless an actual execution result exists.
- Base debugging explanations on available code, errors, and execution results.
- Never automatically apply or execute a proposed fix without explicit user action.
- Keep provider credentials on the server.
- Apply usage quotas and rate limits.
- Avoid sending secrets or unrelated private workspace data to AI providers.
- Tell users when code is sent to an external AI service.
- Allow AI functionality to be disabled when no provider is configured.

AI support must be optional and must not block the core Developer Lab experience.

---

## 16. UI/UX Design Requirements

Create a modern developer-oriented interface that is visually consistent with the existing shamim.it website.

### Visual direction

- Clean and professional
- Dark theme compatible with the existing site
- Subtle blue or purple accents where appropriate
- Clear typography
- Restrained borders and shadows
- Consistent spacing
- Accessible contrast
- Minimal unnecessary decoration
- Fast interactions
- Responsive layout

### Essential states

Design and implement:

- Initial loading
- Empty workspace
- Unsaved changes
- Running execution
- Queued execution
- Successful execution
- Compilation or runtime error
- Timeout
- Memory or resource limit exceeded
- Quota exceeded
- Unsupported language
- Runner unavailable
- Network or service failure
- Saved successfully
- Challenge passed
- Challenge failed

Use accessible labels, keyboard navigation, visible focus states, and screen-reader-friendly status messages.

Do not use fake execution output, fabricated test results, or nonfunctional controls in the production implementation.

---

## 17. Laravel and Livewire Architecture

Follow the current repository conventions rather than imposing a new structure blindly.

Organize the module into small, focused components and services.

### Suggested Livewire components

- `DeveloperLab/Dashboard`
- `DeveloperLab/Editor/Workspace`
- `DeveloperLab/Editor/LanguageSelector`
- `DeveloperLab/Editor/FileExplorer`
- `DeveloperLab/Editor/ExecutionPanel`
- `DeveloperLab/Editor/ConsolePanel`
- `DeveloperLab/Editor/TestResults`
- `DeveloperLab/Editor/PreviewPanel`
- `DeveloperLab/Challenges/Index`
- `DeveloperLab/Challenges/Show`
- `DeveloperLab/Snippets/Index`
- `DeveloperLab/Snippets/Show`
- `DeveloperLab/History/Index`
- `Admin/DeveloperLab/Dashboard`
- `Admin/DeveloperLab/Languages`
- `Admin/DeveloperLab/Challenges`
- `Admin/DeveloperLab/Executions`
- `Admin/DeveloperLab/Settings`

These are proposed names, not mandatory names. Reuse existing naming conventions where possible.

### Suggested service responsibilities

- `WorkspaceService` — workspace persistence and ownership
- `LanguageRegistry` — supported languages and capabilities
- `ExecutionRequestValidator` — input and policy validation
- `ExecutionDispatcher` — secure runner dispatch
- `ExecutionResultNormalizer` — consistent result format
- `ExecutionQuotaService` — quota enforcement and usage
- `ChallengeGradingService` — trusted challenge grading
- `SnippetService` — snippet management
- `LearningProgressService` — learning progress

Do not make a single Livewire component responsible for every feature.

Keep editor interaction and high-frequency code changes client-side. Use Livewire for server-backed actions, validation, persistence, and authorization.

Keep the execution service independent from the UI so future runtimes can be added without rewriting the editor.

---

## 18. Database Design

Inspect the existing schema before creating tables. Reuse existing user, role, permission, and activity-log tables wherever suitable.

Suggested tables:

### `lab_languages`

- `id`
- `name`
- `slug`
- `runtime_type`
- `capabilities`
- `starter_template`
- `is_enabled`
- `settings`
- timestamps

### `lab_workspaces`

- `id`
- `user_id`
- `name`
- `description`
- `language_id`
- `visibility`
- `settings`
- timestamps
- soft deletes where appropriate

### `lab_workspace_files`

- `id`
- `workspace_id`
- `path`
- `content`
- `sort_order`
- timestamps

### `lab_executions`

- `id`
- `user_id`
- `workspace_id` nullable
- `challenge_id` nullable
- `language_id`
- `status`
- `exit_code` nullable
- `stdout`
- `stderr`
- `duration_ms`
- `resource_usage`
- `failure_reason` nullable
- `started_at` nullable
- `finished_at` nullable
- timestamps

Store bounded output only. Keep sensitive internal execution metadata out of user-visible fields.

### `lab_snippets`

- `id`
- `user_id`
- `title`
- `slug`
- `description` nullable
- `language_id`
- `code`
- `visibility`
- timestamps

### `lab_challenges`

- `id`
- `title`
- `slug`
- `description`
- `language_id`
- `difficulty`
- `starter_code`
- `sample_cases`
- `hints`
- `is_published`
- timestamps

### `lab_challenge_test_cases`

- `id`
- `challenge_id`
- `input_data` nullable
- `expected_output` nullable
- `is_hidden`
- `sort_order`
- timestamps

Protect hidden test data from public serialization and unauthorized access.

### `lab_submissions`

- `id`
- `user_id`
- `challenge_id`
- `language_id`
- `source_snapshot`
- `status`
- `passed_count`
- `total_count`
- `submitted_at`
- timestamps

### `lab_user_progress`

- `id`
- `user_id`
- `learning_path_id` or equivalent existing relation
- `item_type`
- `item_id`
- `status`
- `completed_at` nullable
- timestamps

### `lab_execution_usage`

- `id`
- `user_id`
- `execution_id`
- `resource_summary`
- `usage_period`
- timestamps

Create additional tables only when required by actual implementation. Add foreign keys, indexes, unique constraints, ownership policies, and appropriate cascading behavior.

Use transactions where multiple related database changes must remain consistent.

Never store executable PHP files inside the Laravel application's publicly accessible directory.

---

## 19. Authorization and Application Security

Implement server-side authorization for every protected action.

Requirements:

- Reuse existing authentication.
- Do not create a second authentication system.
- Validate all incoming data.
- Enforce workspace and snippet ownership.
- Protect private execution history.
- Keep hidden challenge tests private.
- Validate IDs and resource ownership.
- Use CSRF protection where applicable.
- Escape untrusted content when rendering it.
- Avoid unsafe HTML rendering.
- Apply rate limiting.
- Protect administrator routes with existing role and permission conventions.
- Avoid mass-assignment vulnerabilities.
- Avoid exposing exception traces or secrets.
- Keep secrets out of source control.
- Log administrative actions and security-relevant execution events without unnecessarily logging source code.

Review the entire feature for insecure direct object references, cross-user access, stored XSS, injection, quota bypasses, and execution isolation failures.

---

## 20. Administrator Controls

Build an administrator interface that fits the existing admin panel.

### Dashboard

Display useful aggregate metrics:

- Total executions
- Successful and failed executions
- Timeout counts
- Active languages
- Challenge submissions
- Resource usage summaries
- Runner health status

Do not expose source code or private user data to administrators by default unless a legitimate, authorized operational workflow requires it.

### Language management

Administrators can:

- Enable or disable configured languages
- Manage starter templates
- Review supported capabilities
- Configure allowed limits within safe bounds

An administrator must not be able to enable an unconfigured or unsafe runtime merely by toggling a setting.

### Challenge management

Administrators can:

- Create and edit challenges
- Configure sample and hidden tests
- Set difficulty and categories
- Preview the challenge
- Publish or unpublish challenges

### Execution and settings management

Administrators can:

- Review execution status and failure categories
- Inspect aggregated usage
- Configure quotas within approved safety limits
- Monitor runner health
- Disable a failing runtime
- Review abuse signals

Use audit logs for sensitive changes.

---

## 21. Testing Strategy

Write and execute tests rather than merely creating test files.

### Unit tests

Test:

- Language capability resolution
- Request validation
- Quota calculation
- Result normalization
- Challenge grading
- Workspace ownership rules

### Feature tests

Test:

- Workspace CRUD
- Snippet CRUD
- Authentication requirements
- Private workspace isolation
- Execution request validation
- Execution status transitions
- Execution history authorization
- Challenge submission and grading
- Administrator authorization
- Disabled language behavior
- Quota enforcement

### Frontend and integration tests

Test:

- Editor initialization
- Language switching
- Preview refresh
- Console output
- Error display
- Saving and restoring workspaces
- Responsive panel behavior
- Keyboard-accessible controls

### Security tests

Test:

- Attempts to access another user's workspace
- Attempts to read hidden test cases
- Excessively large source submissions
- Rate-limit bypass attempts
- Oversized output
- Execution timeout behavior
- Attempts to access application secrets
- Preview attempts to access the parent origin
- Network restrictions
- Runner cleanup after success, failure, and timeout

Never claim a test passed unless it was actually executed and passed.

---

## 22. Documentation Requirements

Create the following documentation under `docs/developer-lab/`, adjusting the location if the repository has an established documentation convention.

- `README.md`
- `PRD.md`
- `TRD.md`
- `ARCHITECTURE.md`
- `DATABASE.md`
- `ROUTES.md`
- `EXECUTION-SECURITY.md`
- `RUNTIMES.md`
- `CHALLENGES.md`
- `AI-ASSISTANT.md`
- `TESTING.md`
- `DEPLOYMENT.md`
- `ROADMAP.md`

Documentation must explain:

- Product scope and limitations
- Architecture and data flow
- Installation and configuration
- Database migrations
- Route and permission structure
- Supported languages and their capabilities
- Execution-service setup
- Isolation and resource limits
- Queue and runner failure handling
- Testing commands
- Deployment requirements
- Monitoring and troubleshooting
- Known limitations and future improvements

Do not document a feature as available until it is implemented and verified.

---

## 23. Implementation Roadmap

### Phase 0 — Repository Audit

- Inspect the application and deployment environment.
- Confirm dependencies and conventions.
- Determine feasible runtime isolation.
- Record architectural decisions and security risks.

**Deliverable:** Repository audit and implementation plan.

### Phase 1 — Editor and Browser Preview

- Implement the Developer Lab entry page.
- Integrate the code editor.
- Add HTML, CSS, and JavaScript editing.
- Add isolated live preview.
- Add console and error inspection.
- Implement responsive layout.

**Deliverable:** Functional browser-based coding playground without server-side arbitrary code execution.

### Phase 2 — Secure PHP Runner

- Implement execution request validation.
- Configure an isolated PHP runtime.
- Add asynchronous dispatch where appropriate.
- Enforce CPU, memory, time, process, output, and quota limits.
- Capture execution results.
- Add execution history and cleanup.
- Verify security before enabling access.

**Deliverable:** Securely bounded PHP execution through a separate runner.

### Phase 3 — Challenges and Testing

- Build challenge catalog and detail pages.
- Add starter code.
- Add sample and hidden tests.
- Implement trusted grading.
- Save submissions and progress.

**Deliverable:** Working programming practice system.

### Phase 4 — Workspaces and Snippets

- Add workspace persistence.
- Add multiple-file support where applicable.
- Implement private snippets and optional public sharing.
- Add search and history integration.

**Deliverable:** Persistent personal coding environment.

### Phase 5 — Learning Paths and Additional Runtimes

- Add structured learning paths.
- Add SQL or another runtime only after its environment is ready.
- Test each new runtime independently.

**Deliverable:** Incremental language and learning expansion.

### Phase 6 — Optional AI Assistance

- Add user-initiated error explanation.
- Add fix suggestions and test generation.
- Apply privacy disclosures, quotas, and provider controls.

**Deliverable:** Optional AI assistance with explicit user control.

### Phase 7 — Production Verification

- Perform security and access-control review.
- Verify limits and abuse prevention.
- Test concurrency and failure recovery.
- Test accessibility and mobile responsiveness.
- Review logs, monitoring, retention, and documentation.

**Deliverable:** Production-readiness report with verified results and outstanding risks.

---

## 24. Definition of Done

The Developer Lab is ready for release only when:

- The editor loads correctly on supported browsers.
- HTML, CSS, and JavaScript preview work in isolation.
- Console output and relevant errors are visible.
- Saving and reopening workspaces work correctly.
- Private resources cannot be accessed by other users.
- PHP executes only through the isolated execution service.
- Execution and resource limits are enforced server-side.
- Hidden tests are not exposed to clients.
- Timeouts and runner failures are handled safely.
- Unsupported runtimes are clearly identified.
- No placeholder buttons falsely imply working functionality.
- Existing authentication and application modules remain functional.
- Required automated tests have been executed.
- Security limitations are documented.
- Deployment instructions match the actual implementation.
- Known gaps are explicitly reported.

---

## 25. Final Instructions to the Coding Agent

Work directly within the existing repository.

1. Audit before editing.
2. Present a concise implementation plan and identify execution-security constraints.
3. Reuse existing application architecture and conventions.
4. Implement the smallest complete, secure vertical slice first.
5. Build real functionality instead of placeholder UI.
6. Keep the editor responsive and avoid unnecessary Livewire requests.
7. Never execute untrusted code in the main application process.
8. Add migrations, authorization, validation, tests, and documentation with each relevant feature.
9. Run applicable tests, static analysis, and build commands.
10. Report commands actually run and their results.
11. List files changed, architectural decisions, setup requirements, and remaining risks.
12. Do not claim successful execution, security, or deployment without evidence.

**Primary objective:** Deliver a useful, maintainable, secure Developer Lab where students and developers can write code, preview supported web projects, run safely supported programs, inspect errors, complete challenges, and save their work—all within the existing shamim.it application.

**Priority order:** Security → Functional correctness → Usability → Maintainability → Performance → Extensibility.
