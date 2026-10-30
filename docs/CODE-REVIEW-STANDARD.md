# Code Review Standard

**Applies to:** every change to `D:\laravel\shamim` (DevFolio).
**Status:** normative. A change is not finished until it satisfies the gates in §2
and has been reviewed against the checklists in §5–§7.
**Owner:** the reviewer named on the pull request. See §4 for what that role owns.

> This document does not restate Laravel or Livewire documentation. It records the
> rules *this* codebase needs, drawn from bugs that actually shipped here. Every
> rule below cites the failure it prevents, because a rule without a failure story
> gets argued away in review.

---

## 1. Why this exists

The codebase is large (201 Action classes, 166 test files, 62 page components, the
debounced CSS design system in `log.css`) and its worst bugs share one property:
**they are invisible to the test suite and to a casual read.**

Three real examples, all of which passed a green suite:

| Shipped bug | Why tests missed it |
|---|---|
| `wire:submit="commit"` never ran — `$commit` is a Livewire *magic action*, so the wire protocol reduced it to `$refresh` | `call('commit')` in a test bypasses the wire protocol entirely |
| `wire:target="commit"` meant the "Posting…" state and the disabled-during-submit guard never fired — **for months** | The markup named a method that no longer existed; nothing asserted the name resolved |
| Two `.dn-chip` CSS rules made every feed filter chip render as selected | No test reads computed style; markup was correct |

The common thread: **the failure is in the seam between two layers** — PHP and the
wire protocol, CSS and the cascade, the ledger and its denormalised count. This
standard is organised around seams, not around style preferences.

---

## 2. The gate model

Three layers. **A layer may only cover what the layers below it cannot.**

### Layer 1 — Automated (must be green, no human judgement)

| Gate | Command | Blocks merge |
|---|---|---|
| Style | `vendor/bin/pint --test` | yes |
| Types | `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress` | yes |
| Tests | `php artisan test` | yes |
| Build | `npm run build` | yes |

Run all of them with **`composer test`** (not `composer ci:check` — see §3.1).

**Baseline (2026-10-30):** `1722 passed, 13 skipped (7310 assertions)`. The 13 skips
are all one cause — `FeedMediaTest` needs the GD extension, which is absent on the
development machine. **A skip must carry a reason.** `requiresGd()` is acceptable;
a deleted test is not.

**PHPStan honesty rule.** PHPStan currently reports **761 errors across 280 files**,
so it cannot function as a pass/fail gate today. The previous explanation — "a cold
run reports ~760 errors from the abandoned `Conversation`/`Message` port, a warm one
is clean" — **is wrong.** Verified 2026-10-30:

| Identifier | Count | Meaning |
|---|---|---|
| `method.notFound` | 260 | Calls to methods that do not exist |
| `class.notFound` | 232 | References to classes that do not exist |
| `property.notFound` | 115 | Reads of properties that do not exist |
| `larastan.relationExistence` | 28 | A relation used in a query is not defined |
| `identical.alwaysFalse` | 25 | A comparison that can never be true |

By directory: `app/Http/Controllers` 236 errors / 76 files, `app/Actions` 129 / 44,
`app/Http` (other) 129 / 75, `app/Models` 49 / 21, `app/Services` 53 / 10.

The errors are **real and they are dead code.** For example `PageMetadata` calls
`$user->nameWithHandle()` and `User::mention()`; neither method exists anywhere in
`app/`. `PageMetadata` is injected into `AboutController`, `AppController`,
`BrandController`, `CommitController` and others — and **every one of those
controllers is referenced by zero routes.** The application routes through Livewire
single-file components instead. There are 134 files in `app/Http/Controllers` for
109 `Route::` calls that touch a few of them.

So these are not live 500s — but they are also not something to ignore, because they
make PHPStan's total meaningless. Until the unrouted controller tree is deleted or
repaired, **the only safe use of PHPStan is per-file:**

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress 2>&1 | grep -i "your/changed/file"
```

Never report "PHPStan is clean" from the total. Never add an `ignoreErrors` entry to
`phpstan.neon` to make a *new* error disappear. The three entries there are
documented, path-scoped and justified in the file.

### Layer 2 — Structural review (a human, mechanical)

Things a machine cannot decide but a human can in seconds. These are **not**
opinions; each is a yes/no against a checklist in §5–§7.

- Does a new write path go through an existing Action, or does it invent a second one?
- Does a new count come from a row count, or from a new column?
- Does new markup hook a Livewire method that exists and is not a magic action?

### Layer 3 — Semantic review (a human, judgement)

Design, naming, whether the change is the right change, whether the abstraction is
warranted. **Layer 3 is where the reviewer's time should go, and the only layer where
"I would have done it differently" is a legitimate comment.** If the review is spent
re-litigating Layer 1 and Layer 2 items, the process has failed — those should have
been caught by the author's pre-flight (§4.1).

---

## 3. Known process defects — fix before relying on the process

These are real, verified on the 2026-10-30 working tree. The standard is only as
strong as its gates.

### 3.1 `composer ci:check` and `composer test` are near-duplicates

`composer.json` defines:

```json
"ci:check":  ["Composer\\Config::disableProcessTimeout", "@test"],
"test":      ["@php artisan config:clear --ansi", "@lint:check", "@types:check", "@php artisan test"]
```

`ci:check` resolves to `@test`, which is the full four-gate chain. This is *correct*
but misleading — it reads as if CI does less than it does. **Use `composer test`
everywhere.** Keep `ci:check` only as the CI alias and add a comment saying so, or
delete it. Two names for one gate invites someone to "optimise" one of them.

### 3.2 CI was red at the first gate, hiding the other three

**Verified:** `tests/Feature/Settings/ProfileUpdateTest.php` was committed with **120
CRLF line endings** while `.gitattributes` declares `text=auto eol=lf`. On Windows the
working copy stayed CRLF; Pint checks the working copy; `composer ci:check` failed at
`lint:check` and **never reached PHPStan or the test suite.**

Consequence: for an unknown period, CI reported failure and told the team nothing
about types or tests. A red first gate is worse than no gate, because it trains
people to ignore red.

**Fixed** by normalising the file to LF (2026-10-30). Detect the class of defect
before it recurs:

```bash
# any file whose working-copy endings disagree with .gitattributes
git ls-files --eol | grep -v 'lf$'
```

Add this as a CI step; it is faster and more legible than reading Pint's output.

### 3.3 `resources/` is outside PHPStan's analysis paths

`phpstan.neon` analyses `app/`, `bootstrap/app.php`, `config/`, `database/`,
`routes/`. **`resources/` is not analysed**, yet it contains 62 page components —
Livewire single-file components with real PHP class bodies, real methods, real
properties.

This is why `phpstan.neon` needs three `trait.unused` suppressions for
`HasCommitActions`, `ReportsCommits` and `VotesInPolls`: PHPStan sees the trait but
no class consuming it, because the consumers are all inside `resources/views`.

**The consequence for review: every Livewire single-file component is
statically unanalysed and untested except by feature tests.** A typo in a `⚡`
component's PHP body is caught by neither PHPStan nor Pint. This is precisely how a
`wire:submit="commit"` naming a non-existent method survived for months.

**Therefore:** a change touching a `⚡` component carries a **higher** review burden,
not a lower one. §6 exists for this reason. Adding `resources/views` to PHPStan's
paths is the correct long-term fix and should be attempted when someone has the
budget to absorb the error count it will surface.

### 3.4 Pint silently skips all 80 `⚡` components — verified

This is the most important finding in this document. **Pint's directory discovery
does not descend into filenames containing `⚡` (U+26A1).**

Verified on Pint 1.32.1, 2026-10-30:

```
$ find app bootstrap config database routes tests -name "*.php" | wc -l
1314
$ vendor/bin/pint --test            # reports over the whole project
PASS ................ 1313 files          # ← one short, and none of resources/
$ vendor/bin/pint --test "resources/views/pages/ai-hub"
PASS ................ 0 files             # ← the directory is invisible
$ vendor/bin/pint --test "resources/views/pages/ai-hub/⚡index.blade.php"
FAIL ................ 1 file, 1 style issue   # ← explicit path IS checked
```

The consequence: `vendor/bin/pint --test` returns **PASS** while **28 of the 80
`⚡` components carry real style violations.** Verified by enumerating them:

```bash
find resources/views -name "*⚡*" -print0 | xargs -0 vendor/bin/pint --test
# 28 files, e.g.:
#   ⚡recovery-codes.blade.php   braces_position
#   ⚡product-edit.blade.php     class_attributes_separation, class_definition, …
#   ⚡feed.blade.php             <not listed — checked and clean>
```

These are **genuine violations, not false positives.** The dominant one is
`class_definition`: Pint wants the opening brace on its own line for the
`new class extends Component` body, and every SFC in this repo writes it inline.
Pint's own fix is exactly that one-line change, confirming the rule applies:

```diff
- new class extends Component {
+ new class extends Component
+ {
```

The Livewire SFC convention simply never had Pint run over it, because discovery
skips these files. Whether to reformat all 28 or to exclude `class_definition` for
`⚡` paths is a **team decision that must be made explicitly** — it is a 28-file
diff and it changes a convention the whole codebase follows.

Combined with §3.3 — `resources/` is outside PHPStan's paths — this means **every
Livewire single-file component in this repo is checked by neither Pint nor PHPStan.**
Only feature tests see them, and feature tests cannot see style or a wrong wire verb.

**Mandatory workaround.** Any review touching a `⚡` file must run Pint against that
file by explicit path:

```bash
vendor/bin/pint --test "resources/views/pages/⚠your/⚡file.blade.php"
```

**Permanent fix** (recommended, not yet applied): add an explicit pass over the
single-file components to the gate, so discovery cannot skip them:

```bash
# in composer.json "lint:check", after the main run
find resources/views -name "*⚡*" -print0 | xargs -0 vendor/bin/pint --test
```

Until that lands, **the automation is not evidence for any `⚡` component.** Say so
explicitly in the PR rather than checking the "Pint green" box.


---

## 4. Process

### 4.1 Author pre-flight (before requesting review)

The author runs the gates **locally** and pastes the result. Requesting review on a
red branch wastes the reviewer's most expensive resource — their judgement — on
something a machine already rejected.

- [ ] `composer test` green locally, output pasted into the PR description
- [ ] `npm run build` green if any `.css`/`.js`/Blade changed
- [ ] `git ls-files --eol | grep -v 'lf$'` returns nothing
- [ ] Self-review done: read your own diff in the GitHub UI, not in your editor
- [ ] PR description answers §4.2 in full

### 4.2 What every pull request must state

The PR template (`.github/pull_request_template.md`) enforces this. The four
questions that must be answered in prose, not checkboxes:

1. **What failure does this prevent, or what capability does it add?** If the answer
   is "refactor" or "cleanup", say what becomes possible that was not possible before.
2. **Which layer of the seam does it touch?** PHP↔wire, CSS↔cascade, ledger↔count,
   handle↔handle. This routes the reviewer to the right checklist.
3. **What did you try that did not work?** The single highest-value line in a PR.
   It stops the reviewer re-proposing the approach you already disproved.
4. **What is deliberately out of scope?** §7 of this document and the Open Items in
   `.workbuddy-ai/memory/MEMORY.md` list known-unfinished work. An honest "not in this
   PR" beats a reviewer discovering it.

### 4.3 Reviewer assignment

- One **primary reviewer** owns the PR and is named on it. Ownership is singular;
  "the team will look at it" means nobody will.
- Changes to `resources/css/log.css`, `resources/js/log-shell.js`, or any
  `.whm-*`/`.te-*`/`.cicd-*`/`.hx-*` rule require a reviewer who has read
  `docs/FEED-DESIGN-SYSTEM.md` or the geometry-contract section of project memory.
- Changes to `docs/` only, or changes to test fixtures only, may take a **single
  reviewer with a 24-hour SLA**.
- A change that adds a permission, a route guard, or a write path touching money
  requires a second reviewer. These are the classes of change where a mistake is a
  security or correctness incident, not a style question.

### 4.4 Review response

- **Acknowledge within one business day.** If you cannot review properly now, say so
  and name when you can. Silence is the failure mode this rule prevents.
- Review in **one pass**. Dribbling comments across three sessions is worse for the
  author than a single thorough review, because the author cannot see the shape of
  the feedback.
- **Distinguish blocking from non-blocking.** Prefix optional comments with
  `nit:` or `optional:`. An unmarked comment is blocking by default. This is
  load-bearing — it is the only mechanism that stops a review becoming a negotiation.

### 4.5 Approval and merge

- **Approval means "I would ship this."** Not "I read it" and not "I trust you".
- **A new commit after approval invalidates the approval** whenever it changes
  behaviour. Cosmetic follow-ups after approval (a typo, a comment) may be merged by
  the author with a note. This distinction must be stated in the PR, not assumed.
- **Do not approve your own PR** except for the documented exceptions: a
  revert of a change that broke production, or a docs-only change.
- Squash-merge. The commit message must carry the *why*, matching the existing
  convention in `git log` (`fix:`, `feat:`).

### 4.6 Severity taxonomy

Every blocking comment must carry exactly one severity. This prevents the two
dysfunctions: treating everything as critical, and treating nothing as critical.

| Severity | Meaning | Examples from this repo |
|---|---|---|
| **S1 — Correctness** | Ships a wrong result, loses data, or breaks a user-visible flow | A count written as a column instead of derived from rows; an Action with a missing ownership guard |
| **S2 — Seam** | Correct today, silently wrong under a change nobody will notice | `wire:submit` naming a magic action; a duplicate CSS class; a `--dn-*` token resolved without its `.dn` ancestor |
| **S3 — Durability** | Works, but will decay or mislead the next reader | Positional arguments past ~4 params; a denormalised count that *can* drift; a fixture that mints randomness on the axis under test |
| **S4 — Consistency** | Diverges from an established convention for no reason | A second file-upload field; a second write path where an Action already exists |
| **nit** | Taste. Never blocks. | Naming preference, ordering of methods |

**S1 and S2 block the merge.** S3 blocks unless the author argues it down in writing.
S4 is at the primary reviewer's discretion. `nit` never blocks.

---

## 5. Checklist — PHP (`app/`)

### 5.1 Write paths and Actions

- [ ] **One write path per operation.** There are 201 Actions. Before writing new
      write logic, grep `app/Actions` for the domain. A second path is how
      `SaveBillingProfile` was reused verbatim by two pages, and how
      `UpdateFeedPost` sat built-but-unused while a page reimplemented it.
- [ ] **Ownership guards are in the query, not in a conditional.**
      `->where('user_id', $reader->id)` on the mutation, not `if ($post->user_id !== ...)`.
      A guard that reads state and then mutates is a race; a guard in the `where` is not.
- [ ] **Authorisation in the Action, not only at the route.** The route guard and the
      Action must not be two statements of one rule — that is exactly the drift
      `DashboardNavigation` was created to prevent.

### 5.2 The ledger doctrine (S1 if violated)

> **An engagement count is a row count, never a denormalised column.**

- [ ] `feed_stars`, `feed_replies`, `feed_poll_votes`, `feed_shares` are ledgers.
      A new interactive count **must** be a table, and its rendered value **must**
      come from `withCount` or an equivalent query.
- [ ] Existing precedent to imitate: `replies_count` is a `withCount` attribute, so
      `$post->fresh()->replies_count` is `null` — assert
      `$post->fresh()->replies()->count()` in tests.
- [ ] A poll's tally is **never written**. `feed_poll_votes` is the ledger; the
      corpus baseline stays frozen and what renders is baseline + rows.
- [ ] Unique indexes are on the *intent*, not the mechanism. `feed_shares` has a
      `channel` column but the unique index is `(feed_post_id, user_id)` — sharing
      one commit by two routes is one account amplifying it once. Do not "fix" this.

### 5.3 Types and static analysis

- [ ] Property promotion (`public function __construct(protected SaveFeedDraft $saveDraft) {}`),
      explicit return types, type hints on all parameters (AGENTS.md).
- [ ] Curly braces on every control structure, even single-line bodies.
- [ ] Array shapes in PHPDoc rather than inline comments.
- [ ] **Nullable relations from soft-deleted parents.** `cascadeOnDelete` does not
      help a soft delete. Larastan will flag `?->` and `??`; the accepted fix is a
      nullable class docblock plus a direct read (`$this->product->name ?? '…'`),
      not a suppression.
- [ ] **Never widen `phpstan.neon` to pass.** See §2.

### 5.4 Naming (per AGENTS.md)

- [ ] Descriptive names: `isRegisteredForDiscounts`, not `discount()`.
- [ ] Enum keys in `TitleCase` (`FavoritePerson`, `Monthly`).
- [ ] **Never name a Livewire action `commit`, `refresh`, `set`, or `sync`.** These
      are magic actions — see §6.1. This is an S2 and the single most expensive bug
      in this repo's history.

### 5.5 Fixtures and factories (S3)

- [ ] **A factory must not mint randomness on the axis the test manipulates.**
      `FeedPostFactory` randomises `reactions`; `trending` scores
      `stars*3 + reactions + replies*2`, so a fixture adding five stars (15 pts) won
      or lost to random noise. Zero unrelated axes with `forceFill` and re-run ~20×.
- [ ] `communities.description` is NOT NULL — build a topic community with
      `Community::factory()->topic($t)`, never `firstOrCreate`.
- [ ] `UploadedFile::fake()->image()` needs GD, which is absent. Use a fixed 1×1 PNG
      via `createWithContent`.

---

## 6. Checklist — Livewire / Blade / Alpine

> **Read §3.3 first.** Everything in this section is statically unanalysed. A typo
> here reaches production with a green suite.

### 6.1 The wire protocol (S2, always)

- [ ] **Every `wire:submit` / `wire:click` / `wire:target` names a method that
      exists and is not a magic action.**
      Magic actions are `$refresh`, `$set`, `$sync`, `$commit`. A `wire:submit="commit"`
      reduces silently to `$refresh` — no error, no console warning, in the browser
      *or* in tests, because `call()` bypasses the protocol.
      `ComposerAttachTest` asserts this for the composer; extend the pattern to any
      new form.
- [ ] **Prove a `wire:submit` in a browser, not only in a test.** If a new form's
      primary verb has never been clicked in a real browser, the review is incomplete.
      This is not optional for a new write path.
- [ ] `wire:navigate` and an Alpine click handler on the same element do not mix.
      A trigger that is also a button must not carry `wire:navigate` — the navigator
      walks and the handler never fires (`aria-expanded` stays `false`, no error).

### 6.2 Component structure

- [ ] **One root element per component.** Livewire requires it.
- [ ] Single-file components are **anonymous classes**. Tests address them by string
      name (`'pages::blog.show'`), never `::class`.
- [ ] Default layout is public; dashboard pages opt in via
      `#[Layout('layouts::dashboard')]`. A dashboard page missing the attribute
      renders in the public shell.
- [ ] **Alpine skips `<script>`.** Payloads are addressed by attribute, one attribute
      per band (`data-thinking-explorer-payload` vs `data-thinking-payload`) — reusing
      an attribute across two bands hands one band the other's data.

### 6.3 Blade traps

- [ ] `x-flux::notice` does not exist → `flux:callout`. Flux icons must be real stubs;
      four non-existent icon names once shipped and threw hard exceptions on render.
- [ ] **Blade does not collapse `@@` into `@`.** A literal `@` must be echoed:
      `{{ 'HEAD@{last visit}' }}`.
- [ ] A parent-scope `@foreach ($x as $type)` silently shadows a Livewire `$type`
      property. Rename the loop variable.
- [ ] A component that merges attributes **must** use
      `$attributes->class([...])`. A literal `class` beside `{{ $attributes }}` yields
      two attributes and the first wins — this silently dropped `dn-pop-row` and its
      positioning from `x-log.remote-menu`.

### 6.4 Nested-trait hooks (S2)

- [ ] Two traits used by one trait may both define the same method **only if** the
      outer trait also defines it. `commitStateChanged` is defined by
      `HasCommitActions` and the pages; `ReportsCommits`, `VotesInPolls` and
      `SharesCommits` must **not** redefine it. Adding a definition in the wrong place
      changes which one wins with no fatal and no test failure.

---

## 7. Checklist — CSS and the design system

> The `--dn-*` system is the largest unanalysed surface in the repo. Read
> `docs/FEED-DESIGN-SYSTEM.md` before editing `log.css`.

### 7.1 The cascade (S2)

- [ ] **Grep for a duplicate class name before touching specificity.** A duplicate is
      a cascade bug, not a specificity fight. Two `.dn-chip` rules made every filter
      chip render selected; two `.dn-filters` rules stacked every category pill
      full-width. When a computed style disagrees with the source, the second
      definition is the cause.
- [ ] **A shared wrapper class used by two layouts must fully specify direction and
      alignment.** Omitting one is how the same class renders correctly in one place
      and wrong in the other.
- [ ] **A media query adds no specificity.** A `display`/`color` inside `@media (…)`
      loses to a same-specificity rule declared *later in the file*. Phone/tablet
      overrides must sit **after** the base rule they override.
- [ ] **Element resets must use `.dn :where(tag)`.** `.dn button` (0,1,1) beats
      `.dn-btn` (0,1,0). `@import './log.css'` lands in Tailwind's `@layer utilities`
      in the build, so **specificity decides, not source order**.
- [ ] Tailwind v4: trailing `!`. Arbitrary `min-[1800px]:` loses the cascade to named
      breakpoints — use `--breakpoint-3xl`.

### 7.2 Tokens (S2)

- [ ] **A custom property cannot reference itself.** To make one geometry value
      reader-switchable, keep the two constants *named* (`--dn-nav-column`,
      `--dn-nav-collapsed`) and add a third carrying whichever is live
      (`--dn-nav-live`). Every consumer reads only the third, or reclaimed space
      becomes gap. This is why the rail collapse works and why "simplifying" it breaks it.
- [ ] `--dn-primary-ink` is the text colour **on** a primary fill. Text on a **soft
      wash** (`--dn-primary-soft`, `--dn-wash`) takes `--dn-primary`, **never** `-ink`.
      Four rules were unreadable in dark mode because of this.
- [ ] `text-ink-900` is invisible inside `[data-sui]` in dark — use `text-sui-ink`.
      Accent is `data-accent` on `<html>`; dark is the default.
- [ ] **The `--dn-*` tokens live on `.dn`, and dark on `.dark .dn`.** Any harness or
      new root that renders `dn-*` markup without a `.dn` ancestor resolves
      `--dn-primary: unset` → `rgba(0,0,0,0)` and every measurement is garbage — and
      the page still *looks* mostly fine, which is what makes it dangerous.

### 7.3 Layout traps (S2)

- [ ] **Never add a second absolutely-anchored sibling.** `.dn-delete` was
      `position: absolute; top: 50%; right: -6px`; a second one lands on the **same
      pixel** and the later paints over the earlier, so the first is visible but
      **unclickable** — and no test that reads markup can see it. **Position the
      group, not each child**: wrap them in one anchored flex row (`.dn-row-verbs`).
- [ ] **z-index is ignored on `position: static`.** A static layer lets a positioned
      sibling paint over it: visible, unclickable.
- [ ] **A `flex-wrap` row sizes to its children's max-content** and can report wider
      than its parent while no single child does. Fix: `width: 100%` + `min-width: 0`
      on the row, a **zero** flex basis (`flex: 1 1 0`, not `auto`) on the
      slack-absorbing group. One `auto` margin plus `justify-content: space-between`
      is two competing push rules — pick one.
- [ ] A `position: fixed` child of a `display: none` ancestor is not painted;
      `overflow: hidden` clips an absolute child. Popovers that must exist on both
      the rail and a phone live at the end of `.dn-shell`, anchored by measuring
      whichever trigger fired — not inside `.dn-nav`.

### 7.4 Geometry contracts (S2 — read the test before editing)

Several homepage sections have CSS values that **PHP reads to derive positions**.
Changing one without the other is a silent visual break. Each is pinned by a named
test; **if you edit the rule, run the test.**

- [ ] Hero Deck: `--hx-plane-h = frame × (1 − 0.478)`; `--hx-perspective = plane × 1.429`.
      `HomeHeroDeckTest`.
- [ ] CI/CD: `.cicd-track { min-width: 980px }` and `.cicd-cell { margin-right: 26px }`
      are read by Blade to derive spoke positions. One column at every width.
      State is `data-stage` + `data-cursor` on the plate — **the stylesheet paints,
      Alpine only writes the attribute.** `HomeCicdSectionTest`.
- [ ] Thinking Explorer: labels are **in the flow** so they cannot move;
      `measureSlots()` measures label rects against the scene box. `projectHubs` needs
      `scene.updateMatrixWorld()`. 3D phases in ms (`0.00012`), 2D in s (`0.12`) —
      keep periods matched. `AboutThinkingExplorerTest`.

### 7.5 Reveal animations (S2)

- [ ] `[data-reveal]` starts `opacity: 0` and **un-reveals** — the observer removes
      `data-visible` on scroll-out, so a fast scripted scroll ends with everything
      hidden. Never put first-screen content behind it.
- [ ] `[data-reveal][data-visible='true'] { transform: none }` (0,2,0) **beats any
      single class**, so a section rule centring an absolutely positioned child with
      `transform: translate(-50%,-50%)` is silently dropped the moment that child
      reveals. Use the independent `translate: -50% -50%` property.

---

## 8. Checklist — Tests

Per AGENTS.md: tests are required for behaviour and logic changes; pure copy, styling
and layout-only changes do not require new tests.

- [ ] **A test that can pass vacuously is not a test.** `following` can legitimately
      be empty, so each log test asserts the row rendered *before* asserting its
      verbs — otherwise the verb assertions pass against nothing.
- [ ] **Assert on computed state, not substrings.** Slugs are random per run and the
      page also carries the aside, the rail and a `wire:snapshot` blob, so
      `strpos`/`toContain` over the whole HTML can be satisfied by something that is
      not the stream. Read `commits->pluck('id')` for order *and* membership.
- [ ] **Pest's `toContain` on a string containing `.` needs `strpos`.** A CSS selector
      like `.dn-shell[data-nav='collapsed'] .dn-nav-item` fails `toContain` even when
      present. Use `expect(strpos($css, $sel))->not->toBeFalse()`.
- [ ] **Anchor markup slices on a regex, not on a `data-test` offset.** Slicing
      forward from a hook walks past attributes that precede it — the file input
      carries `accept` *before* `data-test`. Anchor with
      `/<input[^>]*data-composer-picker[^>]*>/s`.
- [ ] `data-composer-input` occurs **twice** (a nav button and the textarea) — anchor
      on `<textarea`. `type="submit"` also appears twice page-wide — scope "one verb"
      assertions to the form.
- [ ] **Prove flakiness before blaming the product.** A fixture that mints randomness
      on the manipulated axis produces failures that look like product bugs. Zero the
      unrelated axes and run ~20×.
- [ ] Do not delete tests without approval. They are part of the application.
- [ ] Livewire `abort()` inside an action is swallowed by tests — a 403 assertion on
      a Livewire action proves nothing. Assert the absence of the side effect instead.

---

## 9. Checklist — Security, permissions, data

### 9.1 Permissions

- [ ] Permissions are **derived from the role by enum, never stored**. No
      `permissions` table, no pivot. Adding a permission to the enum automatically
      grants it to admins — **that is intended**; do not "fix" it.
- [ ] Guards use the `permission:` alias. `permission:all:a,b` means both;
      an unresolvable alias is a 500, not a silent pass.
- [ ] **A new route must be reflected in `DashboardNavigation`.** The nav entry
      declares the permission its route requires and both read from it — this is what
      makes a visible-but-refused menu item structurally impossible. A new route
      outside this mechanism reintroduces the drift the class was built to remove.
- [ ] `/dashboard`, `/seller/…` and `/admin/…` are role-owned surfaces. A page placed
      in the wrong surface is an S1.

### 9.2 Data and migrations

- [ ] Soft-deleted parents make `belongsTo` nullable — see §5.3.
- [ ] A frozen snapshot is frozen at the right moment. `BillingProfile::snapshotFor()`
      freezes five `billing_*` columns onto an order **at checkout only**, so editing
      a profile can never restate an issued invoice. Any new snapshot must state its
      freeze point explicitly.
- [ ] `BillingCountry` is a fixed ISO-3166-1 map (no `intl` extension), upper-cased on
      write. Do not reach for `intl`.
- [ ] Money is `price_cents` as an integer. Never a float.

### 9.3 The handle namespace (S1 — a whole bug class)

Two namespaces that must never be confused:

| Place | Shape | Example |
|---|---|---|
| `feed_posts.handle` | display string **with** `@` | `@priya` |
| `users.handle` | key, **no** `@` | `priya` |
| corpus JSON byline | display name | `Priya R.` |

- [ ] Resolving a display handle to a profile URL **must first ask whether an account
      owns it**. A stale corpus handle with no `users` row (`@rpaucek` class)
      otherwise builds `/@ghost` → **404**.
- [ ] Resolve once per request via `LogRow::$knownHandles` (memoised), emit
      `profileUrl` (`?string`), and render a `<span>` when it is `null`.
- [ ] **Any new groupable handle list must do the same.** The aside, the interlude and
      the commit row each had to be fixed separately; a fourth will too unless the
      review asks.

---

## 10. What blocks a merge — the short list

Copy this into the PR when rejecting.

1. **S1** — a count that can drift from its rows; a write path without an ownership
   guard; a permission check outside the enum/route mechanism.
2. **S2** — a wire verb naming a magic or non-existent action; a duplicate CSS class;
   a `--dn-*` token resolved outside `.dn`; two absolutely-anchored siblings; a
   geometry contract edited without its test.
3. **S3** — positional arguments past ~4 parameters (`LogRow::make()` takes named
   arguments because a 7-arg call once put the report and poll arrays into
   `$lastVisitId`/`$interludeAfter`); a factory randomising the axis under test.
4. **S4** — a second write path where an Action exists; a second file-upload field.
5. Red `composer test`, with the output pasted.
6. A `phpstan.neon` `ignoreErrors` entry added to hide a new error.
7. A deleted test without approval.

---

## 11. Documented exceptions — do not "fix" these

A reviewer encountering these should confirm they are intentional, not propose
a change. Each was a decision, and the reasoning is recorded.

| Thing | Why it looks wrong but is not |
|---|---|
| `phpstan.neon` has three `trait.unused` suppressions | The consumers are in `resources/`, which is outside the analysed paths (§3.3) |
| `feed_shares` is unique on `(feed_post_id, user_id)`, not per channel | Two share routes are one account amplifying once |
| Admins get every permission automatically | "Admin" is *defined* as "can do everything", not as a maintained list |
| Terms/Privacy links are `Route::has()`-guarded, "Apps" is absent | The routes do not exist; the guard prevents dead links |
| `⚡composer-modal.blade.php` is complete but mounted nowhere | An orphan of the pre-redesign feed, kept deliberately |
| The abandoned `Conversation`/`Message` port is still in the tree | Untracked, unmigrated; the live page runs on `Remote`/`RemoteMessage`. Do not mix the two |
| `SilencedActorQuery` is built and barely called | A known open item, not a defect to be discovered in review |
| `config/portfolio.php` has four `/example/…` GitHub URLs | Live dead links until the owner supplies real repos — known, not a surprise |
| 13 skipped tests | All one cause: GD is absent on the development machine |
| PHPStan reports 761 errors | Pre-existing dead code in unrouted controllers (§2). Do not widen `ignoreErrors` to hide a *new* one |
| 134 files in `app/Http/Controllers` for a Livewire-routed app | The unrouted tree is the source of most PHPStan errors; deleting it is a known, unstarted cleanup |
| 28 `⚡` components fail Pint | Discovery skips them entirely (§3.4); reformatting is a pending team decision |

---

## 12. Reviewing a change you did not write, in practice

For a reviewer short on time, in priority order:

1. **Read the PR description first.** If §4.2 cannot be answered, stop and ask — the
   author has not finished thinking, and reviewing an unfinished thought is wasted.
2. **Read the diff for the seam.** Which of the four seams does it touch? Read that
   section of this document, not all of it.
3. **Grep the diff for the landmines.** `wire:submit`, `wire:target`, `withCount`,
   `count(` , `position: absolute`, `--dn-`, `.dn-chip`. These are cheap greps with
   high hit rates.
4. **Check the test actually asserts the behaviour**, not just that a page returned 200
   (§8).
5. **Then form an opinion on the design.** Last, not first. A design objection on a
   change with an S1 defect is noise.

---

## Appendix — commands

```bash
# The full gate (use this, not ci:check)
composer test

# Individually
vendor/bin/pint --dirty --format agent
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
php artisan test
npm run build

# Judge your own change against PHPStan, ignoring the abandoned-port noise
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress 2>&1 | grep -i "your/file"

# Line-ending drift (should print nothing)
git ls-files --eol | grep -v 'lf$'

# Narrowest test run
php artisan test --filter="theBehaviourName"
```

On this machine `php` and `composer` are on `PATH` via Herd Lite; if a sandboxed
shell loses them, the wrapper is `/tmp/phpx.sh`. PHPStan always needs
`-d memory_limit=1G` — Larastan exhausts the default.
