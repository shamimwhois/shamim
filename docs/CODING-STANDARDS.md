# Coding Standards

**Applies to:** every change to `D:\laravel\shamim` (DevFolio).
**Status:** normative for *how code is written*. `docs/CODE-REVIEW-STANDARD.md` owns
the *review process*; this document owns the conventions the code itself must hold.
**Companion documents:** `AGENTS.md` (tool usage), `docs/CODE-REVIEW-STANDARD.md`
(gates, severities, checklists), `docs/FEED-DESIGN-SYSTEM.md` (the `--dn-*` system).

> Every rule below cites **the failure it prevents**, because a rule with no failure
> story is a preference, and preferences lose arguments to deadlines. Where a rule is
> a convention with no bug behind it, it says so.

**Scope note.** `resources/views/**` is outside PHPStan's analysis paths and Pint's
directory discovery silently skips `⚡` files (see §2.6). Anything in this document
covering a Livewire single-file component is therefore enforced **only by review and
by feature tests.** Treat those sections as carrying a higher, not lower, burden.

---

## 0. The four seams

This codebase's expensive bugs live in the *seam between two layers*, never inside a
layer. Naming them once makes every rule below findable:

| Seam | Failure shape | Where the rules live |
|---|---|---|
| **PHP ↔ wire protocol** | A `wire:*` verb names a magic or non-existent action | §5.1 |
| **CSS ↔ cascade** | A duplicate class or a lost media query changes computed style | §6 |
| **Ledger ↔ count** | A denormalised count drifts from its rows | §3.3 |
| **Handle ↔ handle** | A display handle is resolved as a key | §3.4 |

Before writing code, decide which seam you are in. If the answer is "none", you are
probably changing a leaf, and the standards that apply are style and naming.

---

## 1. Language, formatting, and the mechanical gates

### 1.1 Formatting is not a debate

Pint owns formatting and its config is authoritative. Do not hand-format against it,
and never reformat a file you did not otherwise change — a formatting-only diff hides
the real change from the reviewer.

```bash
vendor/bin/pint --dirty --format agent     # format what you touched
vendor/bin/pint --test                     # the gate (but see §1.2)
```

**Line endings are LF in the repository, always.** `.gitattributes` declares
`text=auto eol=lf`. On Windows a working copy can still hold CRLF, and because Pint
checks the *working copy*, the first gate fails and the other three never run. Detect
it before it costs you a red CI:

```bash
git ls-files --eol | grep -v 'lf$'         # must print nothing
```

### 1.2 Pint's directory discovery skips `⚡` files

Pint **does not descend into filenames containing `⚡` (U+26A1)**, and it reports
`PASS` while never having looked. Verified on Pint 1.32.1:

```
$ vendor/bin/pint --test
PASS ................ 1313 files          # ← no resources/ at all
$ vendor/bin/pint --test "resources/views/pages/ai-hub"
PASS ................ 0 files             # ← directory is invisible
$ vendor/bin/pint --test "…/⚡index.blade.php"
FAIL ................ 1 file, 1 style issue   # ← an explicit path IS checked
```

**Rule.** Any review touching a `⚡` file runs Pint against it **by explicit path**:

```bash
vendor/bin/pint --test "resources/views/pages/⚠your/⚡file.blade.php"
```

**Never write "Pint green" in a PR for a `⚡` change without that command's output.**
~28 of 80 components carry real violations that the aggregate run cannot see.
Reformatting them is a pending team decision (a 28-file diff); do not start it inside
an unrelated change.

### 1.3 PHP version and API honesty

The app runs on **PHP 8.5** with Laravel 13 and Livewire 4. Do not write from memory
about a package API — confirm the installed version first:

```bash
composer show --direct          # direct dependencies with versions
composer show laravel/framework
```

A rule that names a version is only true while that version is installed.

---

## 2. PHP (`app/`) — structure and craft

### 2.1 Types

- **Constructor property promotion** for every injected dependency:
  ```php
  public function __construct(protected SaveFeedDraft $saveDraft) {}
  ```
  Do not leave an empty zero-parameter `__construct()` unless it is `private`.
- **Explicit return types and full parameter type hints** on every method:
  ```php
  public function isAccessible(User $user, ?string $path = null): bool
  ```
- **Curly braces on every control structure**, including single-line bodies. No
  `if ($x) return;` on one line.
- **Array shapes in PHPDoc**, not in a comment beside the code:
  ```php
  /** @param array{id: int, name: string} $row */
  ```
- Prefer a **PHPDoc block** to an inline comment. Add an inline comment only for
  genuinely non-obvious logic — and when you do, explain *why*, not *what*.

### 2.2 Naming

- **Descriptive names carry the intent.** `isRegisteredForDiscounts()`, not
  `discount()`. A reader should not have to open the body to learn what a method does.
- **Enum keys in `TitleCase`:** `FavoritePerson`, `Monthly`, `BestLake`.
- **Never name a Livewire action `commit`, `refresh`, `set`, or `sync`.** These are
  Livewire's *magic actions*; the wire protocol reduces a call to them to `$refresh`,
  silently. See §5.1 — this is the most expensive bug in this repository's history.
- Boolean methods read as a question (`isOwner()`, `hasReplied()`), and boolean
  properties/variables take an `is`/`has` prefix. Never `enabled`.

### 2.3 One write path per operation

There are **201 Action classes** under `app/Actions`. Before writing write-logic:

```bash
grep -ril "your <domain> idea" app/Actions
```

- **Reuse the Action; do not invent a second.** A second path is how
  `SaveBillingProfile` ended up implemented twice and how `UpdateFeedPost` sat
  built-but-unused while a page reimplemented it.
- An Action is a **verb**: `SaveFeedDraft`, `AttachFeedMedia`, `ClaimFeedMedia`.
- An Action's failure surface is a **return value or an exception**, not a redirect.
  Redirects belong to the component.
- **Keep the write path single even when many buttons feed it.** The composer has
  three attach buttons (images, files, clipboard) and one `<input type="file">`;
  the editor's "Add image" *asks the composer* for that picker over the event bus
  rather than owning a second field. Two file inputs means two `uploads` states, two
  validating hooks, and a cap that can be bypassed by alternating between them.

### 2.4 Guards belong in the query

Ownership and permission checks go **into the query that does the work**, not into a
read-then-mutate conditional:

```php
// correct — the guard and the mutation are one statement
FeedPost::query()->visible()->where('user_id', $reader->id)->update([...]);

// wrong — a race between the read and the write
if ($post->user_id === $reader->id) { $post->update([...]); }
```

- **Resolve the parent, do not trust the child's id.** An attachment id must not be
  taken to imply its parent: query on `feed_post_id` **and** the owner. A row id that
  arrives from the client is untrusted input.
- **One rule, one statement.** A route guard and an Action guard must not be two
  statements of the same rule — that drift is exactly what `DashboardNavigation` was
  created to remove (§3.5).

### 2.5 Livewire single-file components

- One **anonymous class** per file: `new class extends Component`.
- A component has **exactly one root element**.
- Tests address an SFC **by string name** (`'pages::blog.show'`), never `::class`.
- The default layout is public. A dashboard page opts in:
  `#[Layout('layouts::dashboard')]`. A dashboard page missing the attribute renders
  in the public shell — a silent, visible-only-in-the-browser defect.
- **A `#[Computed]` property is cached for the request.** When the rows behind it
  change, `unset()` it explicitly:
  ```php
  public function openEdit(int $id): void
  {
      $this->editingId = $id;
      unset($this->editingMedia, $this->commits, $this->rows);
  }
  ```
  Forgetting this renders the **previous** row's data under the new row's controls —
  a stale read that no server-side test catches, because the test reads the value it
  just changed.

### 2.6 `resources/` is statically unanalysed

`phpstan.neon` analyses `app/`, `bootstrap/app.php`, `config/`, `database/` and
`routes/`. **Not `resources/`** — which holds 62 page components with real methods
and real properties. Combined with §1.2, an SFC is checked by neither Pint nor
PHPStan.

**Consequence:** a typo in a `⚡` body reaches production behind a green suite. This
is how `wire:submit="commit"` survived for months. Treat every SFC edit as
higher-risk, and lean on §5 and on feature tests.

When you do run PHPStan, honest use is **per-file** — the aggregate total is
meaningless while the unrouted controller tree remains (its errors are real, but they
are dead code):

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress 2>&1 | grep -i "your/file"
```

**Never add a new `ignoreErrors` entry to `phpstan.neon` to hide a new error.** The
three entries there are documented, path-scoped and justified in the file.

### 2.7 Config, not literals

- Tunables live in `config/` and are read with a **default**:
  ```php
  (int) config('feed.composer.max_attachments', 8)
  ```
- Never hardcode a limit, a path, a status code or an owner identity at a call site.
- Owner identity lives in `config/portfolio.php`; `FeedPersonas`, `FeedCorpus` and
  `UserSeeder` must agree with it. Do not "unify" the site handle with the GitHub one.

---

## 3. Domain invariants

These are not style. Violating one is a correctness bug.

### 3.1 Money

- **`price_cents`, as an integer.** Never a float. Never a formatted string in the
  database.
- Ratings and averages are **derived** from approved rows, never stored.

### 3.2 Frozen snapshots state their freeze point

`BillingProfile::snapshotFor()` copies five `billing_*` columns onto an order **at
checkout only**, so editing a profile can never restate an issued invoice. Any new
snapshot documents, in a comment on the method, **the moment it freezes**.

### 3.3 Ledger doctrine — a count is a row count

> **An engagement count is a row count, never a denormalised column.**

- `feed_stars`, `feed_replies`, `feed_poll_votes`, `feed_shares` are **ledgers**. A
  new interactive count is a table, and what renders comes from `withCount` or an
  equivalent query.
- Therefore a `withCount` attribute does **not** exist on a fresh model. `$post->fresh()->replies_count`
  is `null`; assert `$post->fresh()->replies()->count()`.
- A poll's tally is **never written**. The corpus baseline stays frozen; what renders
  is baseline + rows.
- **A unique index is on the intent, not the mechanism.** `feed_shares` has a
  `channel` column, but the unique index is `(feed_post_id, user_id)` — sharing one
  commit by two routes is one account amplifying it once. Do not "fix" this.

### 3.4 The two handle namespaces (a whole bug class)

| Place | Shape | Example |
|---|---|---|
| `feed_posts.handle` | display string **with** `@` | `@priya` |
| `users.handle` | key, **no** `@` | `priya` |
| corpus JSON byline | display name | `Priya R.` |

- Resolving a display handle to a profile URL **must first ask whether an account owns
  it**. A stale corpus handle with no `users` row otherwise builds `/@ghost` → **404**.
- Resolution goes through **`App\Support\LogHandleIndex`** (a `scoped` binding),
  returning `array<string, bool>`. It is `scoped`, not `static`, precisely because a
  `private static` cache leaks the first request's answer across Octane workers *and*
  across tests in one process. **Never reintroduce a static handle cache.**

### 3.5 Permissions are derived, never stored

- Permissions come from **the role, by enum**. There is no `permissions` table and no
  pivot. Adding a permission to the enum grants it to admins automatically — **that is
  intended.**
- Guards use the `permission:` alias (`permission:all:a,b` = both). An unresolvable
  alias is a **500**, not a silent pass.
- **A new route must appear in `DashboardNavigation`**, which declares the permission
  its route requires. Both the nav and the guard read that one declaration, which is
  what makes a visible-but-refused menu item structurally impossible. A route outside
  this mechanism reintroduces the drift the class exists to prevent.
- `/dashboard`, `/seller/…` and `/admin/…` are **role-owned surfaces**. A page in the
  wrong surface is a security bug, not a layout mistake.

### 3.6 Moderation

`FeedPost::visible()` is the SQL floor; `ComposeFeedPost` publishes immediately.
There is no path that promotes an account to `admin` (audited 2026-10-30 — see
`.workbuddy-ai/memory/security-audit-2026-10-30.md`). Do not add one incidentally.

---

## 4. Blade

- **A component that merges attributes must use `$attributes->class([...])`.**
  A literal `class` beside `{{ $attributes }}` yields two attributes and the first
  wins — this silently dropped `dn-pop-row` and its positioning from
  `x-log.remote-menu`.
- **Blade does not collapse `@@` into `@`.** Echo a literal:
  `{{ 'HEAD@{last visit}' }}`.
- **A parent-scope `@foreach ($x as $type)` shadows a Livewire `$type` property.**
  Rename the loop variable.
- **`x-flux::notice` does not exist** → `flux:callout`. Flux icons must be **real
  stubs**: four non-existent icon names once shipped and threw hard exceptions at
  render.
- **Every `@foreach` that can emit more than one node per iteration needs `wire:key`
  on every node, keyed by identity** — never `$loop->index`. Livewire morphs unkeyed
  siblings *by position*: delete a row from the middle and every row below shifts up a
  slot, so a node's rendering moves to a different element than its action bindings.
  Measured: a row showing `data-commit="35"` carried a Delete button that deleted
  **34**. This is invisible to every server-side test, because `call()` always
  receives the id it was given.
- **A toggle that opens a panel binds `aria-expanded`.** A hardcoded
  `aria-pressed="false"` is a control that paints a state it never announces. Give
  the panel an `id` so `aria-controls` resolves.
- **Accessible names promise no more than the disk takes.** The composer's picker is
  images-only (`AttachFeedMedia::ACCEPTED_MIMES`), so every `accept` is a subset of it
  and every label says "image", not "file". "Attach a file" on an images-only picker
  is an accessibility bug, not a wording choice. `ComposerAttachTest` pins both.
- **`wire:navigate` and an Alpine click handler do not share a trigger.** The
  navigator walks and the handler never fires — no error, `aria-expanded` stays
  `false`. Pick one.

---

## 5. Alpine & JavaScript

### 5.1 The wire protocol (read this before naming anything)

- **Every `wire:submit` / `wire:click` / `wire:target` must name a method that exists
  and is not a magic action.** Magic actions are `$refresh`, `$set`, `$sync`, `$commit`.
  `wire:submit="commit"` reduces silently to `$refresh` — no error, no console warning,
  in the browser *or* in tests, because `call()` bypasses the wire protocol.
  `ComposerAttachTest` asserts this for the composer; **extend the pattern to any new
  form.**
- **Prove a new `wire:submit` in a real browser.** `php artisan test` returning 200 is
  not evidence the verb resolved. `wire:target="commit"` meant the "Posting…" state
  and the disabled-during-submit guard never fired — for months.
- Uploads go over **XHR**; Livewire's message bus goes over **fetch**. Instrumenting
  `window.fetch` sees zero uploads. The authoritative signature is
  `uploadMultiple(component, name, files, finish, error, progress, cancelled, append = true)`
  (`livewire.js:915`) — **a callback in the wrong slot runs on every XHR progress
  tick.** The staging callback belongs in the `finish` position (3rd), the failure
  callback in `error` (4th).
- `_finishUpload` sets the property **without** firing an `updated` hook, so
  `$wire.call('updatedUploads')` is required. Measured: exactly one fire, never double.

### 5.2 An upload hook drops its batch on every path

`updatedUploads()` must clear `uploads` in a **`finally`**, and **after** `validate()`:

- Clear **only on success** → a refusal leaves Livewire's single-use
  `TemporaryUploadedFile` objects attached to a dead path, and because `validate()`
  reads the whole property they are **re-decided on every later pick**, appending a
  key per try. Measured: three picks left `["uploads.0","uploads.1","uploads.2"]`.
- Clear **before `validate()`** → a per-file failure is re-keyed from `uploads.0` to
  `uploads`, which breaks any slot written for the per-file key.

Both mistakes are silent. The shape is:

```php
try {
    if ($files === []) {
        return;
    }

    // … cap check, then validate() …
    $this->validate([...]);

    app(AttachFeedMedia::class)->handle($post, $reader, $files);
} finally {
    $this->uploads = null;
}
```

### 5.3 A per-file validation error is keyed `uploads.0`, not `uploads`

A slot written as `@error('uploads')` renders **nothing** for the two likeliest
refusals (an undersized image, a non-image file) — the file vanishes with no
explanation. Read both:

```blade
@if ($message = $errors->first('uploads.*') ?: $errors->first('uploads'))
    <p class="dn-composer-error" data-test="composer-media-error">{{ $message }}</p>
@endif
```

The error *bag* was always correct, which is why the gap survived every server-side
test. **Assert on rendered markup**, not on the bag.

### 5.4 `this` does not survive an `await`

A method bound to `x-on:click="paste()"` receives the component as `this` **only for
that synchronous call**. Cross an `await` and the continuation is handed the raw
receiver — for `paste()` that is the paste *button*, whose `$el` is the button.

**Rule.** Any method that resumes after an `await`, or that runs as a bare event
listener, must not read `this.$el`. Capture the root synchronously in `init()` and
read through it:

```js
init() { this.root = this.$el; /* … */ },

async paste() {
    // … await …
    this.openPicker('image/jpeg,image/png,image/webp,image/gif');  // reads this.root
},

openPicker(accept = null) {
    const host = this.root ?? this.$el;
    const picker = host === null ? null : host.querySelector('[data-composer-picker]');
    if (picker === null) return;
    if (accept !== null) picker.setAttribute('accept', accept);
    picker.click();
},
```

Measured failure without this: `paste()` fell through every branch to
`this.openPicker(…)`, which queried inside the *button*, found nothing, and returned
at its null guard — **silently**. A button that looked wired did nothing.

### 5.5 A bare method reference as a listener loses `this`

```js
// wrong — addEventListener calls it with `this` = window
window.addEventListener('composer-attach-requested', this.attachRequestHandler);

// right — bind once, and keep the bound reference so it can be removed
this.attachRequestHandler = this.attachRequestHandler.bind(this);
window.addEventListener('composer-attach-requested', this.attachRequestHandler);
```

Alpine supplies the component as `this` only for a call **it** evaluates
(`x-on:click="…"`). A function passed to `addEventListener` is called with no
receiver, so `this.openPicker` is `undefined` and the handler throws into the console
where nobody looks.

### 5.6 Cross-scope events are bound where both scopes meet

The inline editor is a **sibling** of the composer, not a descendant. A `$dispatch`
from the editor bubbles to `<body>` and never reaches a listener on the composer's
element — the control looked wired and did nothing. Bind shared events on **`window`**,
the one node both share.

- Remove the listener in `destroy()` using the **same reference** (`bind()` once and
  store it). An inline arrow cannot be removed.
- **The event carries no destination.** `updatedUploads()` decides where the bytes
  land from whether an editor is open, so a caller cannot aim an upload at a row it
  does not own by dispatching with a crafted detail.

### 5.7 Alpine component conventions

- Register with `window.Alpine.data('name', (options) => ({ … }))`. One component per
  concern; `log-shell.js` holds four.
- **State is written to the DOM as a `data-*` attribute; the stylesheet paints.**
  The CI/CD section sets `data-stage` + `data-cursor` and lets CSS do the painting.
  Never split a visual truth between a class and an attribute.
- **Alpine skips `<script>` content.** Payloads are addressed by attribute, **one
  attribute per band** (`data-thinking-explorer-payload` vs `data-thinking-payload`) —
  reusing an attribute across two bands hands one band the other's data.
- Expose a controller only when a second component needs to drive it, and scope it
  (`Alpine.data('feedMore', () => ({ … }))` → `this.$el._feedMore`).
- **Never measure before hydration.** A measurement taken against unhydrated markup
  is garbage, and `getComputedStyle` returns **stale paint** if you read it in the
  same JS task that mutated the DOM. Set state before load, kill `transition`, or
  wait a frame.

### 5.8 JavaScript style

- ES modules; no bundler-specific globals beyond the Vite entry.
- `const` by default, `let` only when reassigned. Never `var`.
- Guard optional APIs with `?.` and fall back rather than throwing:
  `if (! navigator.clipboard?.read) { … }`.
- Guard a per-item failure so one bad item does not discard the rest:
  ```js
  try { … } catch { continue; }
  ```
  An unreadable clipboard entry must not lose an image the reader *did* have.
- **Never a silent return.** A user-visible control either does the thing or offers
  the next step. `paste()` opens the picker when the clipboard holds no image, when
  the API is missing, and when permission is refused.
- Add a comment when the *why* is not in the code — especially for a branch that
  exists because of a past failure.

---

## 6. CSS and the design system

> The `--dn-*` system is the largest unanalysed surface in the repository (17k lines
> across eight stylesheets). Read `docs/FEED-DESIGN-SYSTEM.md` before editing
> `log.css`.

### 6.1 Any CSS change requires a build

`npm run build` must run before a browser measurement sees a `.css` change — the
built asset is what the page loads, not the source. `npm run build` failing with
`Missing opening {` on `app.css` means a broken `@import`.

### 6.2 The cascade

- **Grep for a duplicate class name before touching specificity.** A duplicate is a
  cascade bug, not a specificity fight. Two `.dn-chip` rules made every filter chip
  render selected; two `.dn-filters` rules stacked every category pill full-width.
  **When computed style disagrees with source, find the second definition first.**
- **A media query adds no specificity.** A `display`/`color` inside `@media (…)` loses
  to a same-specificity rule declared *later in the file*. Phone/tablet overrides sit
  **after** the base rule they override.
- **Element resets use `.dn :where(tag)`.** `.dn button` (0,1,1) beats `.dn-btn`
  (0,1,0), and `@import './log.css'` lands in Tailwind's `@layer utilities` in the
  build, so **specificity decides, not source order.**
- **A shared wrapper class used by two layouts must fully specify direction and
  alignment.** Omitting one is how the same class renders right in one place and wrong
  in the other.
- Tailwind v4: the important modifier is a **trailing** `!`. Arbitrary
  `min-[1800px]:` loses the cascade to named breakpoints — use `--breakpoint-3xl`.

### 6.3 Tokens

- **`--dn-primary-ink` is the text colour *on* a primary fill.** Text on a **soft
  wash** (`--dn-primary-soft`, `--dn-wash`) takes `--dn-primary`, **never** `-ink`.
  Four rules were unreadable in dark mode because of this.
- **`text-ink-900` is invisible inside `[data-sui]` in dark** — use `text-sui-ink`.
- **`--dn-*` live on `.dn`, and dark on `.dark .dn`.** Any harness or new root that
  renders `dn-*` markup without a `.dn` ancestor resolves `--dn-primary: unset` →
  `rgba(0,0,0,0)`, and every measurement is garbage — while the page still *looks*
  mostly fine, which is what makes it dangerous.
- **A custom property cannot reference itself.** To make one geometry value
  reader-switchable, keep the constants *named* and add a third carrying whichever is
  live, and have consumers read only the third:

  ```css
  .dn { --dn-nav-column: 256px; --dn-nav-collapsed: 76px; --dn-nav-live: var(--dn-nav-column); }
  ```
  This is why the rail collapse works, and why "simplifying" it breaks it.
- **Two shells share one dark palette** anchored at `#111112`
  (`DarkPaletteParityTest`). A new colour must land in both or the test fails.

### 6.4 Layout

- **Never add a second absolutely-anchored sibling.** `.dn-delete` was
  `position: absolute; top: 50%; right: -6px`; a second lands on the **same pixel**
  and the later paints over the earlier, so the first is visible but **unclickable**
  — and no markup-reading test can see it. **Position the group, not each child.**
- **z-index is ignored on `position: static`.**
- **A `flex-wrap` row sizes to its children's max-content** and can report wider than
  its parent while no single child does. Fix: `width: 100%` + `min-width: 0` on the
  row, and a **zero** flex basis (`flex: 1 1 0`, not `auto`) on the slack-absorbing
  group. One `auto` margin plus `justify-content: space-between` is two competing push
  rules — pick one.
- **A child of a `flex: 1 1 0` group needs `flex: none` to keep its size.** Width and
  height alone measured 44 px tall × **21 px** wide — a touch floor must hold on
  **both** axes.
- **A `position: fixed` child of a `display: none` ancestor is not painted**, and
  `overflow: hidden` clips an absolute child. Popovers that must exist on both the
  rail and a phone live at the end of `.dn-shell`, anchored by measuring whichever
  trigger fired — not inside `.dn-nav`.
- **Touch floors live in `@media (max-width: 639px)`**, not `@media (hover: none)`: a
  small laptop window is still a pointer.

### 6.5 Reveal animations

- `[data-reveal]` starts `opacity: 0` and **un-reveals** — the observer removes
  `data-visible` on scroll-out, so a fast scripted scroll ends with everything hidden.
  **Never put first-screen content behind it.**
- `[data-reveal][data-visible='true'] { transform: none }` (0,2,0) **beats any single
  class**, so a section rule centring an absolutely positioned child with
  `transform: translate(-50%,-50%)` is silently dropped the moment that child reveals.
  Use the independent `translate: -50% -50%` property instead.
- For static capture, emulate `prefers-reduced-motion: reduce`.

### 6.6 Geometry contracts

Several homepage sections have CSS values that **PHP reads to derive positions**.
Changing one without the other is a silent visual break. Each is pinned by a named
test; **if you edit the rule, run the test.**

| Section | Contract | Test |
|---|---|---|
| Hero Deck | `--hx-plane-h = frame × (1 − 0.478)`; `--hx-perspective = plane × 1.429` | `HomeHeroDeckTest` |
| CI/CD | `.cicd-track { min-width: 980px }`, `.cicd-cell { margin-right: 26px }` read by Blade | `HomeCicdSectionTest` |
| Thinking Explorer | 3D phases in **ms** (`0.00012`), 2D in **s** (`0.12`) — keep periods matched | `AboutThinkingExplorerTest` |

---

## 7. Tests

Tests are required for **behaviour and logic** changes; pure copy, styling and
layout-only changes do not need new tests. Per `AGENTS.md`: do not create a
verification script or use tinker when a test covers it.

- **One test file per behaviour group**, named for the behaviour:
  `EditMediaTest`, `ComposerResetTest`, `LogRowKeyTest`.
- **A test that can pass vacuously is not a test.** `following` can legitimately be
  empty, so each log test asserts the row rendered **before** asserting its verbs.
- **Assert on computed state, not substrings.** Slugs are random per run and the page
  also carries the aside, the rail and a `wire:snapshot` blob, so `strpos`/`toContain`
  over the whole HTML can be satisfied by something that is not the stream. Read
  `commits->pluck('id')` for order **and** membership.
- **Pest's `toContain` on a needle containing `.` needs `strpos`.** A CSS selector like
  `.dn-shell[data-nav='collapsed'] .dn-nav-item` fails `toContain` even when present:
  `expect(strpos($css, $sel))->not->toBeFalse()`.
- **Anchor markup slices on a regex, not a `data-test` offset.** Slicing forward from
  a hook walks past attributes that precede it.
- **Fixtures must not mint randomness on the axis under test.** `FeedPostFactory`
  randomises `reactions`, and `trending` scores `stars*3 + reactions + replies*2`, so a
  fixture adding five stars won or lost to noise. Zero the unrelated axes with
  `forceFill` and run ~20×.
- **No GD on the development machine.** `requiresGd()` tests **skip** (16 skipped is
  the baseline). A fixture that must run GD-free uses a fixed PNG built in code — and
  it must satisfy the `dimensions:` floor (a 1×1 PNG **fails** the 16 px minimum and
  writes nothing, silently). `EditMediaTest::editableImage()` has a working 32×32.
- **Mutation-test every fix.** Reintroduce the bug → the new test must **fail** →
  restore. And restore the old **semantics**, not merely delete the new line: deleting
  a `finally` leaves a bare `try` → `FatalException`, which proves nothing.
- **State what a test does *not* guard.** A green suite proves the assertions, not the
  behaviour you assumed.
- **Do not delete a test without approval.** Tests are part of the application.
- `abort()` inside a Livewire action is swallowed by tests — a 403 assertion on a
  Livewire action proves nothing. Assert the **absence of the side effect**.

---

## 8. Comments, naming files, and commits

- **Comments explain *why*.** A comment that restates the code is noise; a comment
  that records the failure a branch prevents is load-bearing. Every non-obvious guard
  in this codebase should read as a short post-mortem.
- **File names mirror the class or the behaviour they hold.** `AttachFeedMedia.php`,
  `EditMediaTest.php`, `⚡feed.blade.php`.
- **Commit messages: `type(scope): why`,** matching `git log` (`fix:`, `feat:`). The
  body states the failure the change prevents. One logical change per commit.
- **Never commit a secret.** `.env` is ignored; do not paste a value into a comment,
  a test fixture or a probe.

---

## 9. Browser probes (`.workbuddy-ai/probes/`)

The CDP harnesses are part of the verification story. They carry their own standards
because a probe that lies is worse than no probe.

- **Drive the real code path, not the transport it shares.** The original clipboard
  probe called `stage()` directly — the function the clipboard path happens to reuse —
  so it passed while every clipboard-specific line was broken. `paste()` and
  `onPaste()` must be exercised as themselves.
- **Observe at the prototype or on a capturing listener.** A Livewire morph replaces
  nodes, so a monkey-patched `element.click` is discarded with the node. Patch
  `HTMLInputElement.prototype.click`, or listen on the document with `capture: true`.
- **Assert on a signal only the code under test can produce.** The picker's `accept`
  is **server-rendered**, so reading it back proves nothing about whether the picker
  was opened; the recorded `click` does.
- **Stub what the headless browser cannot do** — `navigator.clipboard.read()` — and
  say so in the module docstring. Headless Chrome cannot write to the system
  clipboard.
- **Select the sign-in form by `auth_flow === 'login'`.** The auth page carries three
  forms (sign in / register / forgot) each with a hidden `auth_flow`; `querySelector('form')`
  picks the **register** one and the probe silently runs as a guest.
- **Answer `Page.javascriptDialogOpening` with `handleJavaScriptDialog`.** `confirm()`
  blocks the renderer and headless Chrome auto-**dismisses** it, so a guarded control
  looks like it worked while its branch never ran.
- **Always pass `--window-size`** when hit-testing.
- **`php artisan serve` 404s static files**; `python -m http.server` reaches `public/`.
  Servers die between shell calls — start one and use it in the **same** command.

---

## 10. The short list

When in doubt, these are the rules that have cost the most:

1. Never name a Livewire action `commit` / `refresh` / `set` / `sync`.
2. A count is a row count. Never a column.
3. One write path per operation. Never a second `<input type="file">`.
4. Ownership guards go in the query, not in a conditional.
5. `wire:key` on every node of a multi-node `@foreach`, keyed by identity.
6. Bind a listener with `bind(this)`, and bind it where both scopes meet (`window`).
7. Don't read `this.$el` after an `await` — read the root captured in `init()`.
8. `unset()` a `#[Computed]` property when its rows change.
9. Clear an upload batch in a `finally`, **after** `validate()`.
10. Read a per-file error as `$errors->first('uploads.*') ?: $errors->first('uploads')`.
11. Grep for a duplicate class before fighting specificity.
12. Due to `⚡` handling, `pint --test` and `phpstan` both miss SFCs — run Pint by
    explicit path and review SFCs harder.
13. Mutation-test every fix, and restore the old **semantics** when you revert it.

---

## Appendix — commands

```bash
# Format and check only what you touched
vendor/bin/pint --dirty --format agent

# The `⚡` workaround — required for any SFC change
vendor/bin/pint --test "resources/views/pages/⚠your/⚡file.blade.php"

# Types, scoped to your change
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress 2>&1 | grep -i "your/file"

# Line-ending drift (must print nothing)
git ls-files --eol | grep -v 'lf$'

# Rebuild before any browser measurement of CSS/JS
npm run build

# Narrowest test run
php artisan test --filter="theBehaviourName"

# Behaviour suites for the surfaces in this document
php artisan test tests/Feature/Log/EditMediaTest.php
php artisan test tests/Feature/Feed/ComposerUploadLimitsTest.php
```

On this machine `php` and `composer` are on `PATH` via Herd Lite; a sandboxed shell
may need the wrapper at `/tmp/phpx.sh`. PHPStan always needs `-d memory_limit=1G` —
Larastan exhausts the default.
