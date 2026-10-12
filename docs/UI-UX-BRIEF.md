# UI/UX Brief — DevFolio

Design principles, component behaviour, and user experience guidelines.

---

## 1. Design Philosophy

### 1.1 The site is the portfolio piece

A senior engineer's site is itself evidence. Bad spacing, inconsistent colour, and jumpy interactions undercut every claim in the copy. The design must read as **deliberate** — every choice traceable to a reason.

### 1.2 Principles

| # | Principle | What it means in practice | What it forbids |
| --- | --- | --- | --- |
| P1 | **Content first, chrome never** | Typography carries the design. The interface recedes; the writing and the reading experience are the product. | Decorative gradients behind body text, floating elements that overlap content, background animation. |
| P2 | **Engineering aesthetic, not startup aesthetic** | Precision over playfulness. Monospace accents, a strict grid, hairline rules, numeric labels. Feels like a well-built tool, not a marketing funnel. | Drop shadows competing for attention, oversized rounded corners, illustration mascots, "hero image" stock photography. |
| P3 | **One idea per section** | Each section of a page makes a single point and ends. | Sections that sprawl across three sub-topics. |
| P4 | **Motion must mean something** | Animation expresses a state change: entering, loading, confirming. | Motion that exists to feel "alive". Nothing loops. |
| P5 | **Accessible by construction** | Contrast, focus, and semantics are checked at token-design time, not patched later. | Colour as the sole carrier of meaning; focus rings removed; icon-only controls without labels. |
| P6 | **Dark mode is a first-class theme** | Both themes designed simultaneously from the same token set. | Dark mode as an inverted afterthought with muddy greys. |
| P7 | **Density with air** | Information-dense listings, generous reading measure. The two coexist because the grid is strict. | Cramped body text; wasteful whitespace in lists. |

### 1.3 Visual language

| Element | Treatment |
| --- | --- |
| **Grid** | 12-column, max content width `76rem`. Reading measure capped at `68ch` regardless of viewport. |
| **Type scale** | Modular, ratio 1.25, anchored at a 1rem base. Display sizes use tighter tracking as they grow. |
| **Rhythm** | Spacing derives from a 4 px base unit. Section padding scales with viewport; element spacing does not. |
| **Rules** | Hairline borders (1 px, low-contrast token) separate regions. Used instead of shadows for structure. |
| **Depth** | Shadows are reserved for genuinely floating layers (popovers, modals, sticky headers). Two elevation levels, no more. |
| **Accent** | One accent hue, used sparingly: active nav, links, focus, primary buttons. It must be able to justify that it earned the attention. |
| **Code** | Monospace, distinct background token, no border-radius inconsistency with body content. |
| **Iconography** | Heroicons via Flux, Lucide where Heroicons has no equivalent. 1.5 px stroke, 20 px default, never decorative-only for a control. |
| **Texture** | A single fine grid or dot pattern, low contrast, confined to the hero background. Nowhere else. |

### 1.4 Signature elements

Three deliberate choices give the site an identity that a template does not have:

1. **Numbered section markers.** Sections carry `01`, `02`, `03` in monospace beside the heading. Reads as an engineering document; costs nothing; impossible to mistake for a generic theme.
2. **Terminal-tinted accent.** The accent colour is drawn from the terminal-green/cyan family rather than the default brand-blue/violet. It signals "developer" without resorting to a fake terminal window.
3. **Hairline grid reveals.** The hero's fine grid pattern fades at the edges rather than ending abruptly, tying the hero to the page background instead of sitting on it as a rectangle.

---

## 2. Design Tokens

All tokens are declared once in `resources/css/app.css` under `@theme` (Tailwind v4 CSS-first configuration). **A raw hex value in a template is a defect.**

### 2.1 Colour

Semantic naming, not literal. Templates reference intent.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--color-canvas` | near-white, warm | near-black, cool | Page background |
| `--color-surface` | white | slightly lifted from canvas | Cards, raised panels |
| `--color-surface-sunken` | subtly darker than canvas | darker than canvas | Code blocks, inset regions |
| `--color-border` | low-contrast grey | low-contrast grey | Hairline rules, card outlines |
| `--color-border-strong` | mid grey | mid grey | Inputs, focus-adjacent structure |
| `--color-ink` | near-black | near-white | Primary text |
| `--color-ink-muted` | mid grey | mid grey | Secondary text, metadata |
| `--color-ink-subtle` | light grey | dark grey | Timestamps, de-emphasised labels |
| `--color-accent` | terminal teal/green | brighter terminal teal | Links, active state, primary CTA |
| `--color-accent-ink` | on-accent text | on-accent text | Text on accent fill |
| `--color-success` | green | green | Confirmed save, approved |
| `--color-warning` | amber | amber | Draft state, pending moderation |
| `--color-danger` | red | red | Destructive actions, validation errors |

**Contrast requirements.** `ink` on `canvas` and `accent` on `canvas` must both clear **4.5:1**. `ink-muted` on `canvas` must clear 4.5:1 for body-size text and may drop to 3:1 only for text ≥ 18.66 px bold or ≥ 24 px regular. These are verified at token-definition time, and the accent is the token most likely to fail — check it whenever it changes.

### 2.2 Typography

| Token | Value | Role |
| --- | --- | --- |
| `--font-sans` | Instrument Sans (existing) | All UI and body text |
| `--font-mono` | system monospace stack | Code, slugs, numeric markers, metadata |
| `--text-display` | fluid, max ~4.5rem | Hero headline only |
| `--text-h1` | fluid, max ~2.75rem | Page titles |
| `--text-h2` | ~2rem | Section headings, post `h2` |
| `--text-h3` | ~1.5rem | Subsection headings, post `h3` |
| `--text-body` | 1rem / 1.7 | Body copy |
| `--text-sm` | 0.875rem | Metadata, captions |
| `--text-xs` | 0.75rem | Chips, numeric markers, labels |

**Rules.**

- Body line-height 1.7; headings 1.15–1.25. Longer measure needs looser leading.
- Tracking tightens as size grows: `-0.02em` at h2, `-0.03em` at display.
- Reading measure capped at `68ch`. Beyond that, the eye loses the line return.
- Numeric and technical strings use `font-mono` with `font-variant-numeric: tabular-nums` so figures align.
- Only two weights ship from the font loader (400, 500) plus a synthetic 600 where the browser provides it. A third web font weight is not worth the request.

### 2.3 Space, radius, elevation, motion

| Category | Tokens | Guidance |
| --- | --- | --- |
| Space | 4 px base scale: 1, 2, 3, 4, 6, 8, 12, 16, 24 | `gap` for siblings, never margins between siblings |
| Radius | `sm` 6 px, `md` 10 px, `lg` 14 px, `full` | One radius family; do not mix a 4 px and a 12 px corner in the same view |
| Elevation | `e1` subtle, `e2` pronounced | `e1` for sticky bars, `e2` for modals and popovers |
| Duration | `fast` 120 ms, `base` 200 ms, `slow` 320 ms | Interaction feedback uses `fast`; layout transitions use `base`; entrance animations use `slow` |
| Easing | `standard` ease-out, `entrance` decelerate | Never `linear` for UI; never bounce for structural elements |

### 2.4 Dark mode

- Implemented as a token redefinition on `.dark`, declared alongside the light values so the two are read together.
- Flux owns the theme toggle and persistence via `@fluxAppearance`. Do not build a second toggle.
- Surfaces are *lifted* in dark mode (surface is lighter than canvas), the inverse of light mode. Shadows are ineffective on dark backgrounds — use a 1 px border on elevated layers instead.
- Images and inline SVG must not assume a light background: give diagrams explicit fill tokens rather than leaving them transparent.
- `color-scheme: dark` must be set so native form controls and scrollbars follow.

---

## 3. Layout System

### 3.1 Page shells

| Shell | Used by | Structure |
| --- | --- | --- |
| **Public** | Home, blog index, blog detail, about | Skip-link → sticky header (logo, nav, theme toggle, auth action) → `main` → footer → back-to-top |
| **Auth** | Login, register, reset, verify, 2FA | Split panel (editorial left, form right) collapsing to a centred card below `lg`; skip-link → `main#auth-form` |
| **App** | Dashboard, post editor, settings | Sidebar shell with permission-filtered navigation and a role/plan card |

> **Revised — the original guidance was wrong.** This table previously read
> "Existing starter-kit auth shells — **reuse, do not rebuild**" and "Existing
> starter-kit sidebar shell — **extend the nav, do not replace the shell**",
> justified on the grounds that the starter kit "already ships accessible auth
> and app shells **with working theme handling**".
>
> That premise was false. All three starter-kit auth layouts (and the app
> shells) hardcoded `class="dark"` on `<html>`, **overriding the visitor's theme
> choice**. Someone who had selected the light theme was shown a dark sign-in
> page and then dropped into a light dashboard. The shells also rendered the
> framework's placeholder branding (a random `Inspiring` quote) rather than
> product copy, and only two of the three auth layouts were referenced at all —
> the third was dead weight with the same bug.
>
> Rebuilding was therefore the correct call, not churn. The lesson worth
> recording: "the framework starter kit handles this" is an assumption that
> needs verifying, not a rationale. The `app` sidebar shell *was* worth
> extending rather than replacing; the `auth` shells were not.

**Current auth layout structure.** A two-column grid at `lg` and above — an
editorial panel carrying the product's own copy, and the form column. Below
`lg` the panel is dropped and the form is centred, because there is no room for
a second column and no reason to make someone scroll past a manifesto to reach a
password field.

The layout is theme-neutral: it reads the visitor's preference through
`@fluxAppearance` like every other page, and hardcodes nothing. `AuthLayoutTest`
asserts this, because it is a regression that renders a perfectly usable form
and is therefore invisible without a test.

### 3.1.1 The dashboard sidebar

The sidebar is the densest navigational surface in the product, so its behaviour
is specified rather than left to the implementation.

**Structure.** Three levels, and no deeper, because a fourth is where sidebars
stop being readable:

| Level | Interactive? | Notes |
| --- | --- | --- |
| **Section** | No | A labelled band (`Home`, `Writing`, `Workspaces`, `Manage`). Dropped entirely — heading included — when permission filtering empties it |
| **Group** | Only if it holds > 1 visible item | Collapsible, with an icon and a one-line description |
| **Item** | Yes | A leaf link |

A group holding exactly one visible item renders as a **plain link with no
chevron**. A disclosure control that expands to reveal a single child costs a
click and teaches nothing. This is decided from the *filtered* item count, so a
group declared with three items still becomes static for a role that can only
reach one of them.

**Desktop — collapsible rail.** The sidebar animates between a 264 px expanded
state and a 68 px icon-only strip. The collapsed strip is built from the same
navigation data, flattened; labels are replaced by icons but every link keeps a
`title` and an `aria-label`, because an icon with no accessible name is
unusable to a screen reader. The preference persists per account
(`df.rail.{userId}`), so two people sharing a browser do not fight over it.

**Below `lg` — off-canvas drawer.** The sidebar leaves the flow entirely and
becomes a drawer over a backdrop. It is a `role="dialog" aria-modal="true"` and
implements all three modal obligations: scroll lock on `<html>`, focus trapped
within the drawer, and focus restored to the trigger on close. Escape closes it.

**Group collapse state** persists per account (`df.nav.groups.{userId}`). A group
containing the current route is **forced open** regardless of stored state — a
collapsed group holding the page you are on would hide the very link the sidebar
exists to highlight.

**Active state** is carried by three signals, never by colour alone: a
signal-coloured left spine on the item, a filled background, and
`aria-current="page"` in the markup.

**Mobile drawer internals.** The scrolling nav region is separate from the pinned
footer, so the rail toggle and the utility links never scroll out of reach.

### 3.2 Public header behaviour

| State | Behaviour |
| --- | --- |
| At top of page | Transparent background, no border |
| Scrolled > 8 px | Background becomes `surface` with a hairline bottom border and `e1` elevation |
| Mobile `< md` | Logo + hamburger; nav opens as a full-height panel |
| Auth state | Guest sees `Log in` / `Get in touch`; authenticated sees `Dashboard` |

The header never hides on scroll-down. A portfolio reader frequently wants to jump to the blog mid-article, and a hiding header makes that a two-step interaction for no gain.

### 3.3 Responsive breakpoints

| Name | Width | Layout behaviour |
| --- | --- | --- |
| `xs` | 360 px | Single column; reduced section padding; TOC becomes a drawer |
| `sm` | 640 px | Two-column post grid |
| `md` | 768 px | Nav collapses to full menu; editorial two-column layouts activate; settings nav becomes a horizontal tab strip |
| `lg` | 1024 px | Three-column post grid; sticky TOC appears beside articles; **dashboard sidebar leaves the drawer and becomes a persistent rail** |
| `xl` | 1280 px | Editor splits into form + preview panes |
| `2xl` | 1536 px | Content width stops growing; only whitespace increases |

**Rule.** No horizontal overflow at any width from 360 px up. Verify with a long unbroken code line and a long unbroken URL — those are what actually break layouts.

---

## 4. Component Behaviour

Each component lists its states. **Every interactive component must define all of: default, hover, focus-visible, active, disabled, and loading.** A component with an undefined focus state is incomplete.

### 4.1 Buttons

| Variant | Use | Light treatment |
| --- | --- | --- |
| `primary` | One per view — the single most important action | Accent fill, `accent-ink` text |
| `solid` | Secondary emphasis | Ink fill, canvas text |
| `outline` | Neutral actions | Transparent fill, `border-strong` |
| `ghost` | Tertiary, icon-only, table rows | Transparent, no border |
| `danger` | Destructive | Danger fill; requires a preceding confirmation |

**States.**

| State | Behaviour |
| --- | --- |
| Hover | Fill darkens ~6 %; no size change (prevents layout shift) |
| Focus-visible | 2 px accent ring with a 2 px canvas offset ring; always visible, never removed |
| Active | 1 px translate down |
| Disabled | 45 % opacity, `cursor: not-allowed`, no pointer events |
| Loading | Spinner replaces the leading icon; label persists; button becomes non-interactive; width is locked to prevent reflow |

**Sizing.** `sm` 32 px, `md` 38 px, `lg` 44 px height. Icon-only buttons are square and **must** carry an accessible label via `aria-label` or a visually-hidden span.

### 4.2 Post card

```
┌─────────────────────────────────────────────┐
│ ┌─────────────────────────────────────────┐ │
│ │  category chip            reading time  │ │  ← meta row
│ └─────────────────────────────────────────┘ │
│                                             │
│  Post title that may wrap to two lines      │  ← h3, underline on hover
│                                             │
│  Excerpt, clamped to three lines...         │  ← ink-muted
│                                             │
│  #tag  #tag  #tag            12 Oct · 1.2k  │  ← footer row
└─────────────────────────────────────────────┘
```

| State | Behaviour |
| --- | --- |
| Default | `surface` fill, hairline border, `md` radius |
| Hover | Border colour shifts toward accent; title underlines; card translates up 2 px; accent left-edge marker grows from 0 to 3 px |
| Focus-within | Focus ring on the card container (the whole card is one link target) |
| Loading | Skeleton: shimmer bars matching the title/excerpt/meta line heights |
| Truncation | Title clamped to 2 lines, excerpt to 3, with a real ellipsis rather than a hard cut |

**Whole-card link with a nested link caveat.** The card's primary link covers the card via a stretched pseudo-element; any inner link (category, tag) receives a higher `z-index` and `position: relative` so it stays independently clickable. Nested `<a>` elements are invalid HTML and are never emitted.

### 4.3 Tag pill / category chip

| Property | Value |
| --- | --- |
| Interactive (filter) | Full pill, border, hover fills with accent-tinted background, active state shows a checkmark |
| Non-interactive (display) | Lower-contrast text with a `#` prefix in monospace; no border |
| Focus | Accent ring |
| Count badge | Optional trailing count in `text-xs` with `tabular-nums` |

Active filters are visually unambiguous: filled background, accent border, and a leading check icon. Colour alone is not the signal.

### 4.4 Reading progress bar

| Property | Behaviour |
| --- | --- |
| Position | Fixed, 2 px tall, top edge, above the header |
| Fill | Accent, `scaleX` transform (not `width`, to avoid layout thrash) |
| Range | 0 → 1 across the article's scrollable extent, not the whole page |
| Reduced motion | Progress still updates but without the smoothing transition |
| Absent | Not rendered on the index, home, or about pages |

### 4.5 Table of contents

| Breakpoint | Behaviour |
| --- | --- |
| `≥ lg` | Sticky in the article's left column; max-height with internal scroll; hidden when the article has fewer than three headings |
| `< lg` | Collapsible disclosure above the article body, collapsed by default |
| Active item | Accent-coloured text plus a 2 px accent left border; determined by `IntersectionObserver`, not scroll arithmetic |
| Click | Smooth-scrolls, accounting for the sticky header height via `scroll-margin-top` on headings |

**Rule.** The active-section indicator uses both colour and a border, because colour alone fails for readers with colour-vision deficiency.

### 4.6 Share bar

| Property | Behaviour |
| --- | --- |
| Placement | Below the post header and after the article body |
| Destinations | X, LinkedIn, Facebook, Reddit, Hacker News, Email |
| Native share | When `navigator.share` exists, a primary `Share` button appears and the destination row is demoted to a "more" disclosure |
| Copy link | Icon button with a 2 s "Copied!" inline confirmation |
| Popups | `window.open` at 600×540, `noopener,noreferrer` |
| Keyboard | All destinations reachable by tab; `Enter` and `Space` both activate |
| No-JS | Destinations render as real `<a href>` elements, so sharing works with JS disabled |

### 4.7 Comment item and form

| Element | Behaviour |
| --- | --- |
| Comment item | Avatar (initials fallback), name, relative timestamp with an absolute tooltip, body |
| Pending indicator | Amber badge, visible only to the author |
| Empty state | "No comments yet — start the discussion." |
| Form | Autosizing textarea, 3–2000 char counter, honeypot field visually hidden but not `display: none` (bots skip `display: none`) |
| Validation | Inline error below the field; error text also announced via `aria-live` |
| Success | Toast + confirmation copy + form reset |
| Unauthenticated | Form replaced by a login prompt that preserves the return URL |

### 4.8 Modal

| Aspect | Behaviour |
| --- | --- |
| Trigger | `flux:modal.trigger`; every modal has a stable `name` |
| Focus trap | Focus moves to the first focusable element on open and returns to the trigger on close |
| Escape | Closes the modal |
| Backdrop click | Closes, except for destructive confirmations which require an explicit choice |
| Scroll lock | Body scroll locked while open, including the iOS body-scroll quirk |
| Labelling | `aria-labelledby` pointing at the modal heading; `role="dialog"` |
| Destructive variant | Requires typed confirmation; the confirm button stays disabled until the input matches |

**Modal inventory.**

| Modal | Purpose | Confirm action |
| --- | --- | --- |
| Publish confirmation | Confirm a post going public | Publish |
| Unpublish confirmation | Confirm a post returning to draft | Unpublish |
| Delete post | Destructive, requires the exact title | Delete |
| Delete category | Explains that posts become uncategorised | Delete |
| Reject comment | Destructive, requires confirmation | Reject |

### 4.9 Toast

| Variant | Colour | Auto-dismiss | Use |
| --- | --- | --- | --- |
| Success | Success | 4 s | Saved, published, approved, copied |
| Warning | Warning | 6 s | Draft saved but not published, unsaved changes |
| Danger | Danger | Manual dismiss | Failed action, validation summary |

Toasts appear bottom-right on desktop, bottom-centre on mobile, and are announced through an `aria-live="polite"` region. **Max one toast at a time** — a queue that stacks five toasts is noise.

### 4.10 Form fields

| Aspect | Behaviour |
| --- | --- |
| Label | Always visible. A placeholder is not a label. |
| Required | Marked with an asterisk plus `aria-required`; the asterisk alone is not the signal |
| Help text | Below the field, `ink-muted`, `text-sm` |
| Error | Below the field, danger colour, `role="alert"`; the field gains `aria-invalid` and a danger border |
| Focus | Accent ring; never removed |
| Disabled | 45 % opacity, `cursor: not-allowed`, removed from the tab order |
| Autosave fields | Show an inline save state (`Saving…` / `Saved 14:32`) rather than a toast, to avoid toast spam while typing |

---

## 5. Page-Level UX Guidelines

### 5.1 Home

| Section | Purpose | Rules |
| --- | --- | --- |
| Hero | Identify and position | Name, role, specialisms, years. Two CTAs. Nothing else. |
| Stats band | Corroborate seniority | Real numbers from the database. Never invent a metric. |
| Expertise | Show depth | 4–6 items. Each is a capability, not a technology logo. |
| Selected work | Show outcomes | ≤3 projects. Problem → approach → result. Stack chips secondary. |
| Featured posts | Route to the blog | ≤3. Category + reading time on each. |
| Latest posts | Show the blog is alive | 6 newest. |
| Contact CTA | Convert | One clear ask. No form if email is enough. |

### 5.2 Blog index

- The featured post sits **above** the grid only when no filter is active. Showing a "featured" post that contradicts the active filter is confusing.
- Result count is always visible so the reader knows the scope of what they are looking at.
- Filters live in a sticky sub-bar on `lg+` so they remain reachable while scrolling a long grid.
- Pagination preserves every active filter in its links.
- Scroll position returns to the top of the grid on page change; the rest of the page (header, filters) stays put.

### 5.3 Blog detail

- The article's first paragraph is the hook. No "Introduction" heading — it is filler.
- In-article links use the accent colour with an underline. Never rely on colour alone.
- Blockquotes get a 3 px accent left border and italic `ink-muted` text.
- Tables scroll horizontally within a wrapper; the page never scrolls sideways.
- Footnotes are out of scope for v1; inline asides use a bordered callout.
- The author card appears once, after the article, before comments. It is not repeated in the sidebar.

### 5.4 Dashboard

- Metric tiles are scannable at a glance: one number, one label, one deltas-free presentation. No chart junk.
- The post list is a table on `lg+` and a stacked card list on smaller screens. Do not make a table horizontally scrollable on mobile if a card layout reads better.
- Status is conveyed by a chip with both text and colour.
- Destructive actions are visually separated from routine ones by a divider and are never the default-focused element.
- The pending-comment count is a persistent badge so moderation is never forgotten.

### 5.5 Post editor

- The preview is **server-rendered** by Livewire. Do not ship a second markdown parser to the browser — two implementations will diverge.
- The save-state indicator is always visible and never animates distractingly.
- Markdown shortcuts (bold, link, code fence, heading) are toolbar buttons that wrap the selection, so the author never has to remember syntax mid-thought.
- Autosave never publishes.
- Leaving with unsaved changes prompts for confirmation.
- Slug, when auto-derived, is shown as `preview` text and becomes editable on click. Do not silently change a published post's slug.

---

## 6. Accessibility Requirements

Target: **WCAG 2.2 Level AA**, verified with axe-core plus a manual keyboard pass.

| Requirement | Implementation |
| --- | --- |
| Skip link | First focusable element on every public page; targets `#main` |
| Landmarks | Exactly one `<header>`, `<main>`, `<footer>`; `<nav>` for each nav region with an `aria-label` |
| Heading order | No level skipped. One `<h1>` per page. |
| Focus visibility | 2 px accent ring with a canvas-coloured offset; never `outline: none` without a replacement |
| Keyboard operability | Every interactive element reachable and operable; no keyboard traps; modals trap focus intentionally and release on close |
| Colour independence | Active, selected, error, and status states carry a non-colour signal (icon, border, text) |
| Contrast | Body text ≥ 4.5:1; large text and UI borders ≥ 3:1; verified per token |
| Reduced motion | `prefers-reduced-motion: reduce` disables transforms, entrance animations, and smooth scrolling |
| Form errors | `role="alert"` plus `aria-invalid` plus a visible message tied by `aria-describedby` |
| Images | Decorative SVG `aria-hidden`; meaningful SVG carries a `<title>` |
| Live regions | Toasts and save-state changes announced via `aria-live="polite"`; validation summaries via `assertive` |
| Touch targets | ≥ 44×44 px on touch devices |
| Zoom | Usable at 200 % zoom and 400 % reflow without loss of content or function |

---

## 7. Motion Guidelines

| Interaction | Duration | Easing | Reduced-motion behaviour |
| --- | --- | --- | --- |
| Hover colour/border | 120 ms | standard | Instant |
| Card lift on hover | 200 ms | standard | No transform |
| Modal open/close | 200 ms | standard | Instant, opacity only |
| Dropdown / popover | 150 ms | standard | Instant |
| Page entrance (hero) | 320 ms | entrance | No transform; content visible immediately |
| Toast enter/exit | 200 ms | standard | Instant |
| Progress bar | transform-driven, no transition | — | No smoothing; still updates |
| TOC active change | 120 ms | standard | Instant |
| Skeleton shimmer | 1.4 s linear loop | linear | Static placeholder, no shimmer |

**Rule.** Skeleton shimmer is the only looping animation permitted anywhere, and it is disabled under reduced motion.

---

## 8. Content and Copy Guidelines

| Rule | Example |
| --- | --- |
| Write for a technical peer, not a recruiter | "Reduced p95 latency by 340 ms by replacing N+1 hydration with a single eager-loaded query" — not "improved performance". |
| Lead with the outcome, then the mechanism | Outcome first, implementation second. |
| Sentences under 25 words in UI copy | Long sentences belong in articles, not in a button label. |
| No exclamation marks in UI | "Saved." not "Saved!" |
| Buttons describe the action, not the mechanism | "Publish post", not "Submit". |
| Errors explain the fix | "Title is required." not "Validation error." |
| Empty states offer a next step | "No posts yet — write your first one." |
| Dates are absolute in metadata, relative in comments | "12 Oct 2026" in post headers; "3 hours ago" in comment threads. |
| Numbers are formatted with `tabular-nums` | View counts and dates align in columns. |

---

## 9. Quality Checklist

Before a UI change is considered complete:

- [ ] All six interaction states defined for every interactive element.
- [ ] Verified at 360 px, 768 px, 1024 px, 1440 px, 1920 px with no horizontal overflow.
- [ ] Verified in light and dark mode.
- [ ] Keyboard traversal complete in DOM order; focus never lost.
- [ ] axe-core reports zero violations.
- [ ] Contrast checked against the token table; accent is the usual failure point.
- [ ] `prefers-reduced-motion` verified by enabling the OS setting.
- [ ] No raw hex, px radius, or px shadow values in templates — tokens only.
- [ ] Loading, empty, error, and success states all render.
- [ ] Long content tested: a 40-character unbroken URL, a 200-character code line, a post title without spaces.
- [ ] Copy reviewed against the guidelines in §8.
