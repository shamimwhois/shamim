<!--
  Fill this in. Delete any heading you cannot answer — an unanswered heading is
  better than a filled-in box that says nothing. See docs/CODE-REVIEW-STANDARD.md.
-->

## What this does

<!--
  One paragraph. What failure does this prevent, or what capability does it add?
  If the answer is "refactor" or "cleanup", say what becomes possible that was
  not possible before. Standard §4.2.1.
-->

## Which seam does it touch?

<!--
  Tick the ones that apply. This routes the reviewer to the right checklist, so
  the review does not have to be read end to end. Standard §4.2.2.
-->

- [ ] PHP ↔ write path / Action (§5.1)
- [ ] Ledger ↔ count (§5.2) — **any new interactive count**
- [ ] PHP ↔ Livewire wire protocol (§6.1) — **any `wire:submit` / `wire:click` / `wire:target`**
- [ ] Blade ↔ Alpine / component structure (§6.2–6.4)
- [ ] CSS ↔ cascade / `--dn-*` tokens (§7.1–7.2)
- [ ] CSS ↔ geometry contract read by PHP (§7.4) — **run the named test**
- [ ] Permissions / routes / role-owned surfaces (§9.1)
- [ ] Handles: display `@handle` vs `users.handle` (§9.3)
- [ ] None of the above (docs, tests only, assets)

## What did you try that did not work?

<!--
  The highest-value line in the PR. It stops the reviewer re-proposing the
  approach you already disproved. Standard §4.2.3.
-->

## Deliberately out of scope

<!--
  Known-unfinished work is already recorded in docs/CODE-REVIEW-STANDARD.md §11
  and the Open Items of project memory. An honest "not in this PR" beats a
  reviewer discovering it. Standard §4.2.4.
-->

## Verification

<!-- Paste real output. A claim without output is not verification. -->

```
# composer test
<paste: counts of passed / skipped / assertions, and that Pint + PHPStan are green>
```

- [ ] `composer test` green (Pint check → PHPStan → Pest), output pasted above
- [ ] `npm run build` green — required if any `.css` / `.js` / Blade changed
- [ ] `git ls-files --eol | grep -v 'lf$'` prints nothing
- [ ] Self-reviewed the diff in the GitHub UI, not the editor

<!--
  Livewire only: if this adds or changes a form's primary verb, click it in a real
  browser. A green test does not prove a wire:submit works — the test client
  bypasses the wire protocol (Standard §6.1).
-->
- [ ] If a `wire:submit` is involved: verified in a browser, not only in a test
- [ ] If a geometry contract was edited: the named test in §7.4 was run

## Reviewer notes

**Primary reviewer (owner):** <!-- @name — ownership is singular by design §4.3 -->

**Severity of the findings on this PR:** <!-- S1 / S2 / S3 / S4 / nit-only -->

**Approval means "I would ship this."** A new commit after approval invalidates it
whenever it changes behaviour; a cosmetic follow-up does not (§4.5).
