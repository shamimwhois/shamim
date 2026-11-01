# Review Checklists

**Applies to:** every change to `D:\laravel\shamim` (DevFolio).
**Status:** normative procedure. Every item is a **yes/no a human can answer in
seconds** — these are not opinions and not a substitute for judgement.
**Companion documents:** `docs/CODING-STANDARDS.md` (how code is written),
`docs/CODE-REVIEW-STANDARD.md` (gates, severity taxonomy, process).

> **How to use this document.** Find the seam the diff touches, read **that** section,
> and nothing else. Each checklist is ordered by cost of the miss: the first items are
> the ones that shipped here. Every item cites the failure it prevents, because an
> item without a failure story gets skipped under time pressure.

---

## 0. The five-minute pass (every diff, no exceptions)

Answer these **before** reading code. A "no" here means stop and ask the author.

- [ ] **The PR answers the four questions** in `CODE-REVIEW-STANDARD.md` §4.2 — what
      failure it prevents, which seam it touches, what was tried and did not work, and
      what is deliberately out of scope. If it cannot, the author has not finished
      thinking and reviewing an unfinished thought is wasted effort.
- [ ] **The gates are pasted, not asserted.** `composer test` output, `npm run build`
      if CSS/JS/Blade changed, and `git ls-files --eol | grep -v 'lf$'` printing
      nothing. A claim of "all green" without output is not evidence.
- [ ] **A `⚡` file in the diff has an explicit-path Pint run.** `pint --test` cannot
      see `⚡` files — it reports PASS while never looking. Demand
      `pint --test "…/⚡the-file.blade.php"`. Without it, *nothing* about that file's
      style, and neither Pint nor PHPStan, has been checked.
- [ ] **The diff is one logical change.** A formatting sweep, a rename and a behaviour
      change in one PR hides the third from the reviewer.
- [ ] **Read the diff in the GitHub UI, not an editor.** Side-by-side and the full file
      context are what surface a second class definition or a stray edit.

---

## 1. Routing — which checklist applies

Match the diff to a seam. Read that section only.

| Seam touched | Trigger in the diff | Read |
|---|---|---|
| **PHP ↔ wire** | `wire:submit`, `wire:click`, `wire:target`, a new form or action | §2 |
| **Livewire state** | `#[Computed]`, an array property, an upload, `unset(` | §2, §3 |
| **CSS ↔ cascade** | any `.css`, any new class, a `--dn-*` token | §4 |
| **Ledger ↔ count** | `withCount`, `count(`, a new counter or tally | §5 |
| **Handle ↔ handle** | `handle`, `@`, `profileUrl`, `LogRow` | §5 |
| **Write path** | an Action, a new insert/update/delete | §6 |
| **Money / billing** | `price`, `cents`, `snapshotFor`, an invoice field | §6 |
| **Permissions / routes** | a route, a guard, `DashboardNavigation` | §6 |
| **Tests only** | `tests/` | §7 |
| **Docs / fixtures only** | `docs/`, a factory | §7 |

---

## 2. The wire protocol — highest-cost section

Every item here has shipped as a bug in this repository.

- [ ] **Every `wire:submit` / `wire:click` / `wire:target` names a method that exists
      and is not a magic action.** Magic actions are `$refresh`, `$set`, `$sync`,
      `$commit`. `wire:submit="commit"` reduces **silently** to `$refresh` — no error,
      no console warning, in the browser *or* in tests, because `call()` bypasses the
      wire protocol. Grep the diff for `wire:` and check each name against the
      component body.
- [ ] **A method whose name a `wire:*` targets is not shadowed by a property.** A hook
      named `commit` was a no-op; `ComposerAttachTest` now asserts every
      `wire:target|click|submit` resolves to a real non-magic method. **Extend that
      pattern to any new form** rather than trusting this checkbox alone.
- [ ] **A new `wire:submit` has been clicked in a real browser.** A green `php artisan
      test` proves the component renders 200, **not** that the verb resolved. If the PR
      does not say it was exercised in a browser, the review is incomplete for a new
      write path.
- [ ] **`wire:navigate` and an Alpine handler do not share a trigger.** The navigator
      walks and the handler never fires — `aria-expanded` stays `false`, nothing
      errors.
- [ ] **`wire:loading` / `wire:target` reference a name that still exists** after a
      rename. Both were wrong for months while the markup looked correct.
- [ ] **A multi-node `@foreach` has `wire:key` on every node, keyed by identity** —
      never `$loop->index`. Livewire morphs unkeyed siblings *by position*, so deleting
      a middle row shifts every row below up a slot and a node's bindings move to a
      different element. Measured: a row showing `data-commit="35"` carried a Delete
      button that deleted **34** — invisible to every server-side test.

---

## 3. Livewire state, uploads, and computed properties

- [ ] **A `#[Computed]` property is `unset()` wherever its rows change.** A computed
      value is cached for the request; without the `unset()` the previous row's data
      renders under the new row's controls. Check `openEdit` / `cancelEdit` /
      `removeEditMedia` and any sibling that loads different rows.
- [ ] **An upload hook clears its batch on *every* path, in a `finally`, and after
      `validate()`.** Clear on success only → single-use `TemporaryUploadedFile`s stay
      attached to a dead path and are re-decided on every later pick (measured: three
      picks left `["uploads.0","uploads.1","uploads.2"]`). Clear *before* `validate()`
      → a per-file failure re-keys from `uploads.0` to `uploads` and a keyed slot
      stops rendering.
- [ ] **A per-file error is read as `$errors->first('uploads.*') ?: $errors->first('uploads')`.**
      A slot written `@error('uploads')` renders **nothing** for the two likeliest
      refusals — the file vanishes unexplained. The error *bag* was always correct,
      which is why every server-side test passed. **Assert on rendered markup.**
- [ ] **The correct `uploadMultiple` slot position.** The signature is
      `(component, name, files, finish, error, progress, cancelled, append = true)`.
      A callback in the `progress` slot runs on **every XHR tick** — dozens of requests
      where one was meant. Staging goes in `finish` (3rd), failure in `error` (4th).
- [ ] **Uploads are instrumented on XHR, not `fetch`.** Livewire's message bus uses
      `fetch`; the file transfer uses XHR. A probe watching `window.fetch` sees zero
      uploads and proves nothing.
- [ ] **The upload cap counts the destination, not the batch.** `updatedUploads()`
      checks the **post-wide** total (`config('feed.composer.max_attachments')`) so
      several picks cannot exceed it together.
- [ ] **No second `<input type="file">`.** One field, one `uploads` property, one
      validating hook. A control in another scope *asks* for that picker over the
      event bus (`composer-attach-requested` → `openPicker`) rather than owning a
      second field. Two fields means two states and a bypassable cap.
- [ ] **Every `accept` is a subset of `AttachFeedMedia::ACCEPTED_MIMES`** (images
      only), **and every accessible name promises no more than the disk takes.**
      "Attach a file" on an images-only picker is an accessibility bug.
      `ComposerAttachTest` pins both.

---

## 4. CSS and the cascade

- [ ] **`npm run build` was run if any `.css` changed.** The page loads the built
      asset, not the source — a measurement without a rebuild reads the old file.
- [ ] **No duplicate class name was introduced.** Grep the new class in the whole
      stylesheet before assuming a specificity fight. Two `.dn-chip` rules made every
      filter chip look selected; two `.dn-filters` rules stacked every category pill
      full-width. **When computed style disagrees with source, the second definition is
      the cause.**
- [ ] **A media-query override sits *after* the base rule it overrides.** A media query
      adds no specificity, so an override declared earlier loses to a later same-
      specificity rule.
- [ ] **Element resets use `.dn :where(tag)`.** `.dn button` (0,1,1) beats `.dn-btn`
      (0,1,0), and `@import './log.css'` lands in Tailwind's `@layer utilities` — so
      specificity decides, not source order.
- [ ] **A custom property does not reference itself.** A switchable geometry value
      needs the two constants *named* plus one live var
      (`--dn-nav-live`); consumers read only the live one.
- [ ] **Text on a soft wash uses `--dn-primary`, never `--dn-primary-ink`.** Four rules
      were unreadable in dark mode from this.
- [ ] **`text-sui-ink`, not `text-ink-900`, inside `[data-sui]`.**
- [ ] **A new root that renders `dn-*` markup has a `.dn` ancestor.** Without it
      `--dn-primary` resolves to `rgba(0,0,0,0)`; every measurement is garbage while
      the page still *looks* mostly fine.
- [ ] **No second absolutely-anchored sibling.** They land on the same pixel and the
      later paints over the earlier — visible but unclickable, and invisible to any
      markup-reading test. **Position the group, not each child.**
- [ ] **No `z-index` on a `position: static` element** (it is ignored, so a positioned
      sibling paints over it).
- [ ] **A `flex-wrap` row has `width: 100%` + `min-width: 0`, and its slack-absorbing
      child has `flex: 1 1 0`** (not `auto`). A child of that group needs `flex: none`
      to keep its size — width/height alone measured 44 px × **21 px**.
- [ ] **A touch floor holds on both axes** and lives in `@media (max-width: 639px)`,
      not `@media (hover: none)` — a small laptop window is still a pointer.
- [ ] **First-screen content is never behind `[data-reveal]`** — it starts
      `opacity: 0` and un-reveals on scroll-out.
- [ ] **A geometry-contract rule was edited with its test run.** Hero Deck
      (`HomeHeroDeckTest`), CI/CD (`HomeCicdSectionTest`), Thinking Explorer
      (`AboutThinkingExplorerTest`). PHP derives positions from these values.
- [ ] **Both shells got a new colour.** Two shells, one dark palette anchored at
      `#111112`; `DarkPaletteParityTest` enforces parity.

---

## 5. Ledgers, counts, and handles

### 5.1 The ledger doctrine

- [ ] **Every engagement count renders from rows** (`withCount` or an equivalent
      query). A new interactive count is a **table**, never a denormalised column.
- [ ] **A `withCount` attribute does not exist on a fresh model.** `$post->fresh()->replies_count`
      is `null`; tests assert `$post->fresh()->replies()->count()`. A test that reads
      the attribute is vacuous.
- [ ] **A poll's tally is never written.** `feed_poll_votes` is the ledger; the corpus
      baseline stays frozen and what renders is baseline + rows.
- [ ] **A unique index is on the intent, not the mechanism.** `feed_shares` has a
      `channel` column but is unique on `(feed_post_id, user_id)`. Do not "fix" this.
- [ ] **A toggle's visible state is derived, not stored separately from its ledger.**
      A poll vote removes the Vote button; count markers, do not measure disabled
      state.

### 5.2 Handles

- [ ] **A display handle is resolved by asking whether an account owns it.**
      `feed_posts.handle` carries `@` (a display string); `users.handle` does not (a
      key). Treating one as the other builds `/@ghost` → **404**.
- [ ] **Resolution goes through `App\Support\LogHandleIndex`** (a `scoped` binding).
      **No `private static` handle cache** — it leaks the first request's answer across
      Octane/queue workers *and* tests in one process.
- [ ] **A new groupable handle list resolves the same way.** The aside, the interlude
      and the commit row each had to be fixed separately; a fourth will too unless the
      review asks. Emit `profileUrl` (`?string`) and render a `<span>` when it is
      `null`.

---

## 6. Write paths, security, and data

### 6.1 Write paths

- [ ] **An existing Action is reused, not reimplemented.** 201 Actions exist. A second
      path is how `SaveBillingProfile` shipped twice and how `UpdateFeedPost` sat
      built-but-unused while a page reimplemented it.
- [ ] **The ownership guard is in the query, not in a read-then-mutate conditional.**
      `->where('user_id', $reader->id)` on the mutation is atomic; an `if` around it is
      a race.
- [ ] **A child's id is not trusted to imply its parent.** Query on the parent FK
      **and** the owner. An id from the client is untrusted input.
- [ ] **The guard does not exist twice.** A route guard and an Action guard that state
      one rule separately will drift.
- [ ] **No new controller without a route.** Ten controllers exist and all are live;
      `php artisan route:list --json` is authoritative.

### 6.2 Permissions and routes

- [ ] **A new route appears in `DashboardNavigation`**, which declares the permission
      its route requires. A route outside that mechanism reintroduces the nav-arm/guard
      drift the class exists to prevent.
- [ ] **Guards use the `permission:` alias.** `permission:all:a,b` means both; an
      unresolvable alias is a **500**, not a silent pass.
- [ ] **No stored permission.** Permissions come from the role by enum — no table, no
      pivot. A new enum permission granting admins automatically is **intended**.
- [ ] **The page is in the right role-owned surface.** `/dashboard`, `/seller/…` and
      `/admin/…` are owned by different roles; the wrong surface is a security bug.

### 6.3 Data and money

- [ ] **Money is `price_cents` as an integer**, never a float.
- [ ] **A new snapshot states its freeze point in a comment.** `snapshotFor()` copies
      `billing_*` onto an order **at checkout only**, so editing a profile can never
      restate an issued invoice.
- [ ] **`BillingCountry` stays the fixed ISO-3166-1 map** — do not reach for `intl`.
- [ ] **A soft-deleted parent makes a `belongsTo` nullable.** The accepted fix is a
      nullable class docblock plus a direct read (`$this->product->name ?? '…'`), not a
      PHPStan suppression.
- [ ] **A new tunable reads from `config()` with a default**, not a literal.
- [ ] **No secret in the diff** — not in a comment, a fixture, or a probe.

---

## 7. Tests and fixtures

- [ ] **The test asserts the behaviour, not that the page returned 200.** Read the
      assertions, not the test name.
- [ ] **The test cannot pass vacuously.** `following` can be legitimately empty, so the
      row must be asserted present *before* its verbs. A missing precondition makes
      every later assertion pass against nothing.
- [ ] **Assertions target computed state, not substrings of the whole HTML.** Slugs are
      random per run and the page carries the aside, the rail and a `wire:snapshot`
      blob — `toContain` can be satisfied by something that is not the stream.
- [ ] **A `toContain` needle containing `.` uses `strpos`.**
      `expect(strpos($css, $sel))->not->toBeFalse()`.
- [ ] **Markup is sliced by regex, not by a `data-test` offset.** `data-composer-input`
      occurs twice page-wide; `type="submit"` also appears twice. Anchor the exact
      element.
- [ ] **A fixture does not randomise the axis under test.** Zero the unrelated axes
      with `forceFill` and run ~20× before blaming the product for flakiness.
- [ ] **A GD-dependent test carries a reason.** `requiresGd()` → **skip** is the
      baseline (16 skipped). A GD-free image fixture must satisfy the `dimensions:`
      floor — a 1×1 PNG **fails** the 16 px minimum and writes nothing, silently.
- [ ] **Every fix was mutation-tested.** Reintroduce the bug → the new test **fails** →
      restore. And restore the old **semantics**: deleting a `finally` leaves a bare
      `try` → `FatalException`, which proves nothing.
- [ ] **The PR states what the test does *not* guard.** A green suite proves the
      assertions, not the behaviour you assumed.
- [ ] **No test was deleted without approval.**
- [ ] **`abort()` inside a Livewire action is swallowed by tests** — assert the absence
      of the side effect, not a 403.

---

## 8. Rejecting a pull request — the short list

Copy this into the review when blocking. Each line is a real, shipped defect class.

1. **A `wire:*` verb naming a magic action** (`commit`/`refresh`/`set`/`sync`) or a
   non-existent method.
2. **A multi-node `@foreach` without `wire:key` by identity.**
3. **A count stored as a column, or read from an attribute that only `withCount`
   provides.**
4. **An upload hook that clears its batch on the success path only, or before
   `validate()`.**
5. **A per-file error slot written `@error('uploads')`** (renders nothing).
6. **A second `<input type="file">`, or a second write path where an Action exists.**
7. **An ownership guard as a read-then-mutate `if` instead of a query constraint.**
8. **`this.$el` read after an `await` or inside a bare event listener** — use the root
   captured in `init()`, and `bind(this)` the listener.
9. **A listener for a cross-scope event bound to the composer's element** — the editor
   is a sibling; bind on `window`.
10. **A duplicate CSS class**, a lost media-query override, or `--dn-*` markup without
    a `.dn` ancestor.
11. **Two absolutely-anchored siblings**, or `z-index` on a `position: static` element.
12. **A geometry-contract rule edited without running its test.**
13. **A route not reflected in `DashboardNavigation`**, or a guard not using the
    `permission:` alias.
14. **A new `phpstan.neon` `ignoreErrors` entry hiding a new error.**
15. **A deleted test without approval**, or a fix with no mutation test.
16. **A `⚡` file claimed as Pint-clean** without an explicit-path run.

---

## 9. Approving

- [ ] **"I would ship this"** — not "I read it" and not "I trust you".
- [ ] **Each `nit:` / `optional:` comment is marked as such.** An unmarked comment is
      blocking by default; this is the only mechanism that keeps a review from becoming
      a negotiation.
- [ ] **Every blocking comment carries exactly one severity** (S1/S2/S3/S4 — see
      `CODE-REVIEW-STANDARD.md` §4.6).
- [ ] **A new commit after approval invalidates it** if it changes behaviour. A
      cosmetic follow-up may merge with a note; the distinction is stated, not assumed.
- [ ] **Deliberate exceptions were confirmed, not "fixed".** The `CODE-REVIEW-STANDARD.md`
      §11 table lists what looks wrong but is intentional — `feed_shares`' unique index,
      the three `trait.unused` suppressions, the orphan `⚡composer-modal`, the skipped
      tests, the PHPStan total.

---

## 10. Reviewing a change you did not write, in practice

For a reviewer short on time, **in this order**:

1. **Read the PR description.** §0 of this document. If it cannot answer the four
   questions, stop and ask.
2. **Route by seam.** §1. Read **one** checklist, not all of them.
3. **Grep the diff for landmines** — cheap greps with a high hit rate:
   ```
   wire:submit   wire:target   withCount   count(
   position: absolute   --dn-   .dn-chip   @foreach
   this.$el   addEventListener   @error(
   ```
4. **Check the test asserts the behaviour**, not that a page returned 200. §7.
5. **Only then** form an opinion on the design. A design objection on a diff with an
   S1 defect is noise.
