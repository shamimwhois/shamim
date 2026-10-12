# Soft UI — Dashboard Design Guidelines

> The design system for the authenticated workspace. Tokens live in
> `resources/css/app.css`; primitives live in the `sui-*` utilities in the same
> file. This document is the contract: when a component and this file disagree,
> this file is right and the component is a bug.

Vocabulary used throughout:

| Word | Meaning |
| --- | --- |
| **must** | Non-negotiable. A violation is a defect, not a preference. |
| **should** | Strong recommendation. Deviate only with a written reason in the code comment. |
| **may** | Allowed, never required. |

Cross-references: `§2` is the token layer, `§3` the measured contrast table,
`§5` the component rules, `§6` the density budget, `§7` responsive behaviour,
`§8` accessibility, `§10` anti-patterns.

---

## 1. Context and goals

### 1.1 What this governs

Every screen behind authentication — the role dashboards, post management,
taxonomy, comments, users, plans and settings. The public site is governed by
`docs/UI-UX-BRIEF.md` and shares nothing but the empty state and the
pagination component.

### 1.2 The mechanism

One attribute does the work. The three workspace layouts —
`layouts::app.dashboard`, `layouts::seller.dashboard` and
`layouts::admin.dashboard` — put `data-sui` on
`<body>`, and `resources/css/app.css` redefines Tailwind's colour, radius,
type-scale and shadow variables inside `[data-sui]`. Because Tailwind v4 emits
utilities as runtime `var()` references rather than baked-in values, a single
block of declarations recolours every utility in that subtree — no component
rewrites.

Two consequences that must never be forgotten:

1. **The cascade is load-bearing.** An `!important` or an inline style anywhere
   in the dashboard tree falls outside the scope and silently reverts to the
   public palette.
2. **The scope sits on `<body>`, not on an inner wrapper**, because the mobile
   drawer and Flux's toast group are teleported straight onto the body. Anything
   outside the scope would render in the wrong palette.

Dark mode works because `.dark [data-sui]` outranks `[data-sui]`.

### 1.3 Goals

| Goal | How it is met |
| --- | --- |
| Depth without borders everywhere | Three soft shadow levels plus an inset well, so a 1px hairline is the exception rather than the rule |
| One accent, used sparingly | A single indigo accent scale; the accent marks the current destination, the primary action and the focused control — nothing else |
| A workspace you can sit in all day | 16px/25.6px base rhythm, one type family, no decorative colour |
| Legible before it is beautiful | Every ink/surface pair clears WCAG 2.2 AA on the surface it is actually used on, measured rather than assumed |
| Consistency that survives new work | 30-odd named `sui-*` primitives, so a new page composes rather than invents |

### 1.4 Non-negotiables (the whole system in six lines)

1. No component may contain a raw hex colour, an arbitrary spacing value or an
   arbitrary `text-[` size. Tokens only.
2. Every interactive component defines default, hover, focus-visible, active,
   disabled, loading and error states. A state that is not defined does not
   exist.
3. Focus is always visible, always reachable by keyboard, and never removed.
4. Nav and table styles have exactly one variant each. See `§6`.
5. Reduced-motion is honoured with no exceptions.
6. Spacing comes from the 2/4/8/10/10.8/12/16/20px scale, and 10.8px has the
   name `row` rather than an arbitrary value.

---

## 2. Design tokens and foundations

### 2.1 Surfaces

| Token | Light | Dark | Role |
| --- | --- | --- | --- |
| `sui-canvas` | canvas grey | near-black | The page behind the cards |
| `sui-surface` | white | dark card | The raised surface — cards, panels, table headers |
| `sui-well` | canvas grey | darker card | Inset: inputs, selects, textareas, code panes, code result frames |
| `sui-hover` | light grey wash | lifted card | The wash a pointer leaves behind a row or a quiet button |
| `sui-hairline` | near-white grey | dark slate | 1px separation, barely there |
| `sui-border` | light grey | slate | The default interactive boundary |
| `sui-border-strong` | slate | lighter slate | Focused and emphasised boundaries |

Rules:

- A card must be `bg-sui-surface`; a field must be `bg-sui-well`. The two must
  never be the same colour in the same theme — that difference is what tells the
  user which is which without an outline.
- `sui-hairline` is for separation. `sui-border` is for anything a user can
  interact with. Using a hairline on an interactive boundary is a defect.
- The well is the same colour as the canvas it sits on, on purpose. Its depth
  comes from `shadow-soft-well`, not from a different fill.

### 2.2 Ink (text)

| Token | Light | Dark | Role |
| --- | --- | --- | --- |
| `sui-ink` | navy-grey | near-white | Headings, values, anything you must read |
| `sui-ink-secondary` | slate | light slate | Body copy |
| `sui-ink-muted` | grey-slate | mid slate | The spec's secondary — **white surfaces only** |
| `sui-ink-disabled` | light grey-slate | dark slate | Disabled and non-essential text, large sizes only |
| `sui-ink-on-accent` | white | near-black | Text on a filled accent surface |
| `rail-ink` | white | near-white | Primary text on the rail |
| `rail-ink-secondary` | grey | mid slate | Secondary text on the rail |

Two rules govern this table and they are the most-broken rules in the system:

- **`ink-400` (`sui-ink-muted`) fails AA on the canvas** at 4.46:1. It is
  restricted to white raised surfaces. Anything sitting on the canvas uses
  `ink-500` or darker. This is why the ink numbering runs "backwards": the
  spec's third colour is verbatim `ink-400`, and `ink-500` is the darkened
  derivation that is safe on both surfaces.
- **`ink-disabled` is not for disabled buttons.** It is for decorative or
  redundant text. Disabled controls must still name themselves; see `§5.2`.

The `ink-*` utility scale (50 → 950) is the public site's naming, reused inside
the scope. On the dashboard, prefer the `sui-*` semantic names; the numeric
scale exists for the handful of cases where a step is needed rather than a role.

### 2.3 Accent

| Token | Light | Dark | Role |
| --- | --- | --- | --- |
| `sui-accent` | indigo | light indigo | Filled primary buttons, active filter pills, active nav bar |
| `sui-accent-hover` | darker indigo | lighter indigo | Hover over a filled accent surface |
| `sui-accent-active` | darkest indigo | lightest indigo | Press over a filled accent surface |
| `sui-accent-soft` | indigo wash | deep indigo | Tint behind a selected row or a selected category |
| `sui-accent-softer` | paler indigo wash | darker indigo | The gentlest tint, for bordered selections |
| `sui-accent-ink` | indigo | pale indigo | Accent-coloured **text** on white |

The accent is derived, not supplied — the spec fixes the neutrals and leaves the
accent open. It was chosen because it measures 7.13:1 on white and 6.76:1 on the
canvas, so a white button label clears AA at 4.5:1 with room to spare. The
brighter indigos that usually fill this slot do not.

The accent marks at most three things on a screen: the current destination, the
primary action, and the focused control. A fourth accent is noise.

### 2.4 Status

| Token | Light | Dark | Role |
| --- | --- | --- | --- |
| `sui-success` | deep green | light green | Success text and success icons |
| `sui-success-soft` | green wash | dark green | Tint behind a success message |
| `sui-warning` | deep amber | light amber | Warning text and icons |
| `sui-warning-soft` | amber wash | dark amber | Tint behind a warning message |
| `sui-warning-ink` | dark brown-amber | pale amber | Warning text **on** an amber wash |
| `sui-danger` | deep red | light red | Danger text, icons, and destructive boundaries |
| `sui-danger-soft` | red wash | dark red | Tint behind a danger message |
| `sui-info` | deep blue | light blue | Informational text and icons |
| `sui-info-soft` | blue wash | dark blue | Tint behind an informational message |

Every status colour that ever carries text is a **700-weight** colour. The
600-weight family measures below 4.5:1 on white and must not carry text. The
`-soft` washes are for backgrounds only; never for text.

### 2.5 The rail

`--color-sui-rail` is the one dark surface in the light theme. Its text follows
the rail's own tokens rather than the ink scale, because the light-theme
secondary would measure 4.47:1 on it. Design rules for the rail live in `§5.7`.

### 2.6 Radii

Only two radii exist on the dashboard:

| Token | Size | Applied to |
| --- | --- | --- |
| `rounded-md` | 8px | Anything you press or type into — buttons, inputs, selects, chips' containers, icon tiles |
| `rounded-xl` | 16px | Anything that holds content — cards, panels, dialogs, empty states |

`rounded-full` (the 9999px pill) remains untouched and is the third shape,
reserved for filter pills, chips and status badges. There is no fourth radius.
`rounded-xs`, `rounded-sm`, `rounded-lg` are aliases of the two values in the
scope so that a stray utility cannot produce a third size.

### 2.7 Spacing

The scale is 2 / 4 / 8 / 10 / 10.8 / 12 / 16 / 20px. Six of the seven already
exist as numeric Tailwind steps (`0.5 / 1 / 2 / 2.5 / 3 / 4 / 5`). The seventh
is a named token:

| Token | Size | Purpose |
| --- | --- | --- |
| `py-row` / `px-row` | 10.8px | The list-row vertical rhythm, via the `sui-row` primitive |

`row` is a name, not an arbitrary value. `py-[0.675rem]` in a view is a defect.
Horizontal padding for rows is `px-5` (20px) by convention, applied inside the
`sui-row` primitive itself.

### 2.8 Shadows

Three drop levels, plus three insets. This is the entire vocabulary — there is
no fourth.

| Token | Level | Role |
| --- | --- | --- |
| `shadow-soft-1` | Resting | The default lift on a raised card. Barely there. |
| `shadow-soft-2` | Lifted | A card that is interactive, on hover. A dialog at rest. |
| `shadow-soft-3` | Floating | Modals, the generate dialog, anything that sits above the page |
| `shadow-soft-well` | Inset | Pressed *into* the surface — inputs, wells, code frames |
| `shadow-soft-ring` | Inset | A hairlight ring for borderless surfaces |
| `shadow-soft-highlight` | Inset | A 1px top-edge highlight on anything raised |

Shadows are tinted with the neutral slate rather than with black: a black shadow
over a blue-grey surface reads as dirt, a tinted one reads as depth. In dark
mode depth is carried by borders instead, because drop shadows read as mud on a
dark field.

### 2.9 Typography

One family. Open Sans is set both as `--font-sans` and directly on the scope
element, because Tailwind's preflight computes the font on `<html>` before a
descendant's variable override can apply. `--font-mono` is remapped to the same
family: the dashboard is a single-family surface, and a stray monospace glyph is
a leak from the public editorial site.

| Size | px | Role |
| --- | --- | --- |
| `text-2xs` | 11px | Eyebrows, table headers, metadata |
| `text-xs` | 12px | Labels, captions, inline help, small actions |
| `text-sm` | 14px | UI chrome, navigation, control text, table cells |
| `text-base` | 16px | Body copy — the base |
| `text-lg` | 18px | Lead paragraphs |
| `text-xl` | 20px | Card titles |
| `text-2xl` | 24px | Page titles |
| `text-3xl` | 32px | Metric values |

Leading: `leading-tight` 1.25, `leading-snug` 1.375, `leading-normal` **1.6**
(the body rhythm — 25.6px at the 16px base), `leading-relaxed` 1.7.
Tracking: `tracking-tightest` −0.02em, `tracking-tighter` −0.015em,
`tracking-tight` −0.01em for headings; `tracking-wide` 0.06em for uppercase
eyebrows only.

Body copy must use `leading-normal`. Tightening body leading to make content
fit is a defect — remove content instead.

### 2.10 Motion

Three durations, one easing curve:

| Token | Value | Role |
| --- | --- | --- |
| `duration-150` | 150ms | Colour, border, background — anything that answers a hover |
| `duration-200` | 200ms | Position, size, shadow — anything that moves a pixel |
| `duration-250` | 250ms | The slowest thing on screen. Rare. |
| `ease-soft` | `cubic-bezier(0.4, 0, 0.2, 1)` | The only easing curve |

`duration-150` pairs with colour changes, `duration-200` with movement. A
`duration-500` or a custom easing curve does not exist in this system.

---

## 3. Colour and contrast (measured)

Every pair below was measured against the surface it is actually used on, not
against white in the abstract. These numbers are the reason the token values
are what they are — changing a token invalidates this table, and changing the
table requires re-measuring.

### 3.1 Light theme

| Foreground | Background | Ratio | Verdict |
| --- | --- | --- | --- |
| `#344767` (ink-600 / ink-900) | white raised surface | 9.36:1 | AA, AAA |
| `#344767` | `#f8f9fa` canvas | 8.89:1 | AA, AAA |
| `#5f6b7e` (ink-500) | white raised surface | 5.40:1 | AA |
| `#5f6b7e` | `#f8f9fa` canvas | 5.12:1 | AA |
| `#67748e` (ink-400) | white raised surface | 4.70:1 | AA, marginal |
| `#67748e` | `#f8f9fa` canvas | **4.46:1** | **fails AA** — hence §2.2's restriction |
| `#8392ab` (rail secondary) | `#000000` rail | 6.66:1 | AA |
| `#2b4acb` (accent) | white raised surface | 7.13:1 | AA, AAA |
| `#2b4acb` | `#f8f9fa` canvas | 6.76:1 | AA, AAA |
| white on `#2b4acb` | — | 7.09:1 | AA, AAA — the filled button |
| `#5b76d6` (rail accent) | `#000000` rail | 5.00:1 | AA as text; ≥3:1 as a non-text bar |
| `amber-600` | white raised surface | **3.19:1** | **fails AA** — attention text moved to amber-700/800 |
| white on `amber-500` | — | **~2.1:1** | **fails AA** — badges became `bg-amber-400 text-amber-950` |
| `amber-950` on `amber-400` | — | 5.44:1 | AA — the replacement badge pair |

### 3.2 Dark theme

| Foreground | Background | Ratio | Verdict |
| --- | --- | --- | --- |
| `#94a3b8` (dark ink-400) | `#18233c` dark card | 6.09:1 | AA |
| `#8794ab` (dark ink-500) | `#18233c` dark card | 5.10:1 | AA |

### 3.3 Rules

- Normal text must reach **4.5:1** against its actual background. Large text
  (18.66px bold, or 24px regular and above) must reach **3:1**. UI component
  boundaries and graphical objects must reach **3:1**.
- A colour pair that measures 4.46:1 is a failure, not a rounding error. The
  ink split in `§2.2` exists precisely because of that row.
- A wash must be light enough to read as a background and dark enough that the
  ink on it clears 4.5:1. That is why every `-soft` token is paired with an
  `-ink` token rather than being used with a generic ink.
- Any new tone must be measured and added to this table before it ships.

---

## 4. The universal state model

Every interactive component defines all seven states. A component that cannot
express one of them — a `<div>` with a click handler, a link that points at the
current page, a button with no action — must still declare the state as inert
in its own comment, so the omission is visible rather than invisible.

| State | Definition | Required treatment |
| --- | --- | --- |
| **Default** | Untouched | The component's resting appearance |
| **Hover** | Pointer over the component | A visible change of surface or ink. Never a change that implies a different meaning |
| **Focus-visible** | Keyboard or assistive focus | A ring that survives being on any of the component's variants. Never removed |
| **Active** | Being pressed, or being activated | The darkest/loudest state — a real answer to a real input |
| **Disabled** | Not actionable | Reduced contrast permitted by exemption, but the control must still name itself and explain why it is unavailable in nearby text |
| **Loading** | Action accepted, result pending | The component's label or an adjacent status line says so in words; the control is not left to pulse silently |
| **Error** | The component's value or action is invalid | A danger ink message adjacent to the control, in text, plus `aria-invalid` and `aria-describedby` on the control |

Three rules that apply to all seven:

1. **Hover is not a substitute for focus.** Every hover treatment in `§5` is
   paired with a focus treatment. If a component has a hover state without a
   focus state, it is optically polished and unusable by keyboard.
2. **Hover must not be the only affordance.** Anything discoverable only by
   pointing is undiscoverable by keyboard.
3. **State is never carried by colour alone.** A selected row carries
   `aria-current="page"`; a danger message carries a word; an error field
   carries a message and an attribute, not a red border.

The `sui-focus` primitive is the focus ring for anything that suppresses the
default outline. It draws a 2px accent ring with a 2px offset using
`box-shadow` rather than `outline`, so it follows a pill radius cleanly. The
global `:focus-visible` rule does the same for everything that keeps the default
outline — both share an 8px border radius so a ring hugs a pill as cleanly as a
square.

---

## 5. Component rules

Each subsection gives anatomy, variants, the seven states, and responsive
behaviour. No component may be introduced without all four.

### 5.1 Cards

**Primitives:** `sui-card` (`rounded-xl border border-sui-hairline bg-sui-surface shadow-soft-1`), `sui-card-lift`.

**Anatomy.** An optional header, a body, an optional footer. Sections inside a
card are separated by `divide-y divide-sui-hairline` on the list container and
`border-b border-sui-hairline` on the header. Header padding is `px-5 py-4`; the
page-level page container is `shell` (`max-w-6xl` with `px-5 sm:px-8 lg:px-10`).

**Variants.**

| Variant | When |
| --- | --- |
| `sui-card` | The default. Any raised container. |
| `sui-card-lift` | A card that is itself a link or a button — it rises on hover |
| Card + `bg-sui-danger-soft border-sui-danger/25` | A destructive section. Never a raised white card |
| Card + `border-dashed border-sui-border bg-sui-well` | A reserved placeholder. See `§5.10` — it deliberately casts no shadow |

**States** (for `sui-card-lift`; a static card is inert in all seven and says so
in its comment):

| State | Treatment |
| --- | --- |
| Default | `sui-card` — hairline plus `shadow-soft-1` |
| Hover | `border-sui-border` and `shadow-soft-2` |
| Focus-visible | `sui-focus` ring; the card is a link, so the ring must be on the focusable element |
| Active | `shadow-soft-2` retained; no further change |
| Disabled | Not applicable — a card that cannot be activated is not a link |
| Loading | `aria-busy="true"` on the card; the loading indicator lives in the body |
| Error | Not applicable — errors belong to the field or the section, not to the container |

**Responsive.** Cards stack from one column to the grid the page defines. The
shell's horizontal padding steps `px-5` → `sm:px-8` → `lg:px-10`; the card's own
internal padding does not change with breakpoint — it changes with content
density. A card's `overflow-hidden` must be set when it contains a table so the
card's 16px radius clips the table's corners.

### 5.2 Form controls

**Primitives:** `sui-control`, `sui-control-label`, `sui-help`, `sui-well`, `sui-well-focus`.

**Anatomy.** A `sui-control-label` above the control, the control, and optional
`sui-help` below. The label must be associated with the control by `for`/`id` —
not by proximity. Help text is wired with `aria-describedby`.

`sui-control` is a **well, not a card**: `rounded-md border border-sui-hairline bg-sui-well px-3 py-2 text-sm shadow-soft-well`, with `focus:border-sui-border-strong`. The inset shadow says "type here" in a way a plain border cannot, and the control stays legible against the white card behind it because the two surfaces differ by fill rather than by outline.

**Variants.**

| Variant | When |
| --- | --- |
| `sui-control w-full` | Full-width text input, select or textarea |
| `sui-control w-auto` | An inline control — a filter select beside a search field |
| `sui-well` | A read-only display surface: a slug, a resolved URL |
| `sui-well-focus` | A well that accepts focus but is not a text control |

**States.**

| State | Treatment |
| --- | --- |
| Default | `rounded-md`, hairline border, `bg-sui-well`, `shadow-soft-well`, `text-sm text-sui-ink`, placeholder at `text-ink-400` |
| Hover | `border-sui-border` — the boundary firms up |
| Focus-visible | `border-sui-border-strong` **and** the global ring. Both, deliberately: a 1px ring on a 4px edge is easy to miss at 150% zoom |
| Active | Same as focus |
| Disabled | `bg-sui-well` retained, `text-ink-disabled` on the label, `cursor-not-allowed`, plus a short reason in nearby text. A disabled field with no explanation is a dead end |
| Loading | `wire:loading` swaps a spinner into the control; the control stays enabled so the user can cancel |
| Error | Danger ink message below the control in `sui-help`, `aria-invalid="true"` on the control, and `border-sui-danger/30` if a boundary is used |

**Compact controls.** A control at reduced height must be written
`sui-control … !py-1.5`. The `!` is required and is not optional: in the built
stylesheet, a plain `py-1.5` loses to `sui-control`'s own `py-2` and silently
renders 8px taller. Twelve occurrences in the current tree rely on this.

**Label colour.** `sui-control-label` is `text-ink-600` at `text-sm font-medium`.
It must never be `ink-400`: that fails AA at this size on the canvas, and a
control the user cannot name is a control the user cannot use.

### 5.3 Buttons

**Primitive:** `sui-action`.

**Anatomy.** `inline-flex items-center gap-2` at `rounded-md px-3 py-2 text-sm font-medium`, `transition duration-150 ease-soft`, with a quiet default — transparent background, `text-ink-500` — and a `bg-sui-hover` wash on hover. A quiet control must **never** carry a border: a bordered quiet control competes with the real buttons around it.

**Variants — seven, and no more.**

| # | Variant | Construction |
| --- | --- | --- |
| 1 | Primary | `bg-sui-accent text-white shadow-soft-1 rounded-md px-4 py-2 text-sm font-medium` — one per view |
| 2 | Quiet action | `sui-action` |
| 3 | Compact action | `sui-action !px-2.5 !py-1.5 !text-xs` — the `!` modifiers are load-bearing, see below |
| 4 | Destructive | `rounded-md border border-sui-danger/25 bg-sui-danger-soft px-4 py-2 text-sm font-medium text-sui-danger` |
| 5 | Icon-only | `grid size-8 place-items-center rounded-sm text-ink-500 hover:bg-sui-hover hover:text-sui-accent-ink`, always with an `aria-label` |
| 6 | Filter pill | See `§5.8` |
| 7 | Text link | See `§5.4` |

The `!` on variant 3 is not a hack: `py-2` (the control default) outranks `py-1.5`
in the built stylesheet, and `px-2.5` outranks `px-3` in the same way. Without
the modifier a "compact" button renders at full size.

**States.**

| State | Primary | Quiet action | Destructive |
| --- | --- | --- | --- |
| Default | accented fill, white label | transparent, `ink-500` | danger wash, danger ink |
| Hover | `bg-sui-accent-hover` | `bg-sui-hover` + `text-sui-accent-ink` | deeper danger wash |
| Focus-visible | `sui-focus` ring | `sui-focus` ring | `sui-focus` ring |
| Active | `bg-sui-accent-active` | wash retained | wash retained |
| Disabled | `opacity-60`, `cursor-not-allowed`, label intact | same | same |
| Loading | `wire:loading` reveals a spinner and keeps the label readable; the button never collapses | same | same |
| Error | An error above the button in danger ink, naming the field that failed | same | same |

A disabled button must keep its label. Removing the text from a disabled button
is how a user loses the map of what will become available.

### 5.4 Links

**Primitive:** `sui-link` — `font-medium text-sui-accent-ink`, `duration-150`,
`hover:text-sui-accent hover:underline hover:decoration-sui-accent/40 hover:underline-offset-2`.

**Variants.** Links carry fifteen variants in the system — the largest family in
the dashboard, because a link is the most context-sensitive element. They are:

| # | Context | Treatment |
| --- | --- | --- |
| 1 | Body link on a raised surface | `sui-link` |
| 2 | Body link on the canvas | `sui-link` |
| 3 | Link inside a table cell | `sui-link` at `text-sm` |
| 4 | Compact meta link ("All users →", "Save now") | `sui-focus sui-link text-xs` |
| 5 | Nav rail destination | See `§5.7` |
| 6 | Nav drawer destination | See `§5.7` |
| 7 | Inline link inside body copy | `sui-link` with `underline` always on — body prose underlines its links |
| 8 | Link inside an empty state | `sui-link` at `text-sm`, the sole call to action |
| 9 | Link inside a danger section | `sui-focus sui-link text-sui-danger` |
| 10 | Link inside a warning notice | `sui-link text-sui-warning-ink` |
| 11 | Link inside a disabled or reserved placeholder | A plain, non-interactive sentence — never a dead link |
| 12 | Skip link to main | The layout's `sr-only … focus:not-sr-only` treatment; see `§8` |
| 13 | Pagination link | `components/app/ui/pagination.blade.php`'s own treatment |
| 14 | Breadcrumb / "back to X" | `sui-link text-xs` |
| 15 | Logo / brand link | Rail's `text-sui-rail-ink`, plain and unadorned |

A sixteenth link variant requires either collapsing two of the above or adding a
token — see `§6`.

**States.** Default: `sui-link`. Hover: accent ink plus underline. Focus-visible:
`sui-focus` ring. Active: accent retained. Disabled: a link is never disabled —
if it cannot be used, it is removed or it is a non-interactive sentence. Loading:
`wire:loading` may reveal a pending indicator beside the link, never in place of
its label. Error: a link has no error state; the target page's own error
handling is the message.

### 5.5 Lists and rows

**Primitives:** `sui-row` (`px-5 py-row`), `sui-row-lg` (`px-5 py-4`), and `divide-y divide-sui-hairline` on the container.

**Anatomy.** A list inside a card is a `<ul>` or `<dl>` with
`divide-y divide-sui-hairline`; each row takes `sui-row`. Two row densities
exist and the choice is made by content, not by taste:

| Row | Content | Padding |
| --- | --- | --- |
| `sui-row` | One line of primary text and one line of meta | `py-row` (10.8px) |
| `sui-row-lg` | Two lines of body copy, or a row that carries actions | `py-4` (16px) |

Cramping a two-line row to 10.8px is how a list starts to feel like a
spreadsheet.

**Variants — six.**

| # | Variant | Treatment |
| --- | --- | --- |
| 1 | Definition list row (`<dl>`) | `sui-row`, `<dt>` at `text-sm text-ink-500`, `<dd>` at `text-sm font-semibold tabular-nums text-ink-900` |
| 2 | Activity row with an avatar | `sui-row-lg`, avatar well plus two lines |
| 3 | Static list row | `sui-row`, no hover — a list you cannot click must not look clickable |
| 4 | Navigable row | `sui-row` plus `hover:bg-sui-hover` and a focusable link inside |
| 5 | Current row | `sui-row` plus `bg-sui-accent-soft` and `aria-current="page"` on the link |
| 6 | Compact meta row | `sui-row` with `text-2xs` metadata and `tabular-nums` counts |

**States.**

| State | Treatment |
| --- | --- |
| Default | `sui-row` with a hairline divider |
| Hover | `bg-sui-hover` **only** when the row is interactive. A static row must stay static |
| Focus-visible | The ring lands on the focusable element inside the row, not on the row |
| Active | Wash retained; no colour change |
| Disabled | Not applicable — a row is not a control. If one of its cells is, that cell defines its own states |
| Loading | `wire:loading` shows a spinner in the row's trailing position, or a skeleton row |
| Error | An error row is a danger-wash section, never a red-tinted list row |

**Long content.** Rows must survive hostile content: truncate with `truncate`
plus `min-w-0` on the flex parent, never `overflow-hidden` alone. Counts use
`tabular-nums` so a changing number does not reflow its column.

### 5.6 Tables

**Primitives:** `sui-table-head`, `sui-table-cell`. **One variant. Exactly one.**

| Part | Treatment |
| --- | --- |
| Container | `sui-card overflow-hidden` so the 16px radius clips the corners |
| Header row | `border-b border-sui-hairline bg-sui-well`, cells at `sui-table-head` |
| Body | `divide-y divide-sui-hairline` |
| Row hover | `hover:bg-sui-hover`, interactive rows only |
| Numeric cell | `sui-table-cell` plus `tabular-nums` |
| Metadata cell | `text-2xs text-ink-500` |
| Avatar cell | A `size-8` well at `text-xs font-semibold text-ink-500` |

**States.**

| State | Treatment |
| --- | --- |
| Default | `sui-table-head` in the well-coloured header, `sui-table-cell` in the body |
| Hover | Row wash on interactive rows |
| Focus-visible | Ring on the focusable cell content — a link, a button, a checkbox |
| Active | Wash retained |
| Disabled | Row at reduced contrast with a reason in another cell; never a row that vanishes |
| Loading | `wire:loading` renders a spinner in a header cell or a skeleton row; the header stays put |
| Error | An empty result set is `§5.10`; a failed query is a danger-wash banner above the card |

**Responsive.** A table must not be forced to fit a narrow viewport. At small
widths the columns are progressively hidden — metadata first, then secondary
columns — and the remaining columns wrap. Horizontal scrolling (`overflow-x-auto`)
is the last resort and is used only inside the card's `overflow-hidden` when
every alternative has failed. The header must stay visible when scrolling.

**Long content.** Cell content truncates. A table cell with a paragraph in it is
a design error; move the text to a row in `§5.5`.

### 5.7 Navigation

**Two variants. The rail and the drawer.** Everything else that looks like
navigation is a filter pill (§5.8) or a tab within a page.

#### 5.7.1 The rail

**Primitives:** `sui-rail`, `sui-rail-item`, `sui-rail-item-active`, `sui-rail-group`, `sui-rail-hairline`.

**Anatomy.** The rail is the one dark surface in the light theme — that contrast
is deliberate, so the workspace reads as "be the tool" rather than as another
page. Brand block at the top, the scrolling nav region in the middle, a pinned
account block at the bottom.

A group label (`sui-rail-group`) precedes each set of destinations. The current
destination is `sui-rail-item-active` plus an accent bar on its left edge.

**States.**

| State | Treatment |
| --- | --- |
| Default | `sui-rail-item` — translucent white wash on hover, `text-sui-rail-ink-secondary` |
| Hover | `hover:bg-white/10 hover:text-sui-rail-ink` |
| Focus-visible | `sui-focus` ring, accent ring, accent offset |
| Active / current | `bg-white/10 text-sui-rail-ink` plus the left accent bar, and `aria-current="page"` |
| Disabled | Not applicable — a destination the user cannot reach is not rendered |
| Loading | A pending link shows `wire:loading` on the destination, never a spinner rail-wide |
| Error | Not applicable |

#### 5.7.2 The drawer

The drawer is the rail below `lg`, teleported onto `<body>` so no transformed or
overflowing ancestor can clip it. It owns four behaviours, and all four are
mandatory:

1. **Scroll lock** — `overflow-hidden` on `<html>` while open.
2. **Focus trap** — Tab cycles inside the panel. Hand-rolled, deliberately: Flux
   bundles only the `collapse` plugin here, so `x-trap` is not registered and
   using it would fail silently — the dialog would look modal while Tab walked
   out into the page behind it.
3. **Focus restore** — the element that opened the drawer takes focus back on
   close.
4. **Escape to close** — `@keydown.escape.window`.

The opener carries `aria-controls="drawer-id"` and `aria-expanded`. The panel
carries `role="dialog"` and `aria-modal="true"`.

#### 5.7.3 The rail collapse

At `lg` and above the rail may collapse to a 68px icon strip. The preference is
persisted in `localStorage` under a key namespaced by user id, so a shared
machine does not leak one account's layout into another's session. The collapse
must not be the only way to reach anything — the icon strip is a convenience,
and the expanded rail is always recoverable with one click.

### 5.8 Filter pills

**Primitives:** `sui-tab`, `sui-tab-active`.

**Anatomy.** `inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold`, quiet by default at `text-ink-500`, with the active state a **filled** pill rather than an underline — a filled pill survives being wrapped onto a second row.

Counts inside a pill are `<span class="opacity-60 tabular-nums">`, never a
separate chip.

**Variants — two.**

| Variant | Treatment |
| --- | --- |
| `sui-tab` | Inactive filter |
| `sui-tab-active` | Active filter — `bg-sui-accent text-white shadow-soft-1` |

**States.**

| State | Treatment |
| --- | --- |
| Default | Quiet pill, `text-ink-500` |
| Hover | `hover:bg-sui-hover hover:text-sui-ink` |
| Focus-visible | `sui-focus` ring |
| Active (chosen) | `sui-tab-active`, plus `aria-pressed="true"` or `aria-current` |
| Disabled | Pill retained at reduced contrast, with the count still shown |
| Loading | `wire:loading` marks the pending filter; the label never disappears |
| Error | Not applicable — filters do not error, the list behind them does |

### 5.9 Chips and badges

**Primitives:** `sui-chip` (shape only), then `sui-chip-accent`, `sui-chip-warning`, `sui-chip-neutral`, `sui-chip-success`, and the six `sui-type-*` legend utilities.

**Anatomy.** Shape comes from `sui-chip`
(`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold tabular-nums`).
Colour always comes from a variant — a chip is never hand-tinted at the point of
use.

**Variants.**

| Variant | Use |
| --- | --- |
| `sui-chip-neutral` | Counts, metadata, "no status" |
| `sui-chip-accent` | Selection, ownership, the current plan |
| `sui-chip-success` | Live, published, enabled |
| `sui-chip-warning` | Pending, scheduled, needs attention |
| `sui-type-*` (six) | The correction legend in the editor review panel — spelling, grammar, punctuation, style, clarity, tone |

The six `sui-type-*` colours are a **legend, not a status scale**: each badge
carries its own text label, so the colour only has to separate one category from
the next rather than carry meaning on its own. They are defined once, in
`app.css`, rather than chosen per correction — six ad-hoc tints chosen at the
point of use is how a palette starts drifting.

**States.** Chips are inert in all seven states, and each component that renders
one must say so in a comment. A chip that must be dismissible is a button, not a
chip, and then it needs its own state table.

### 5.10 Empty states

**Primitive:** `sui-empty` (`px-6 py-16 text-center`) on
`sui-empty rounded-xl border border-dashed border-sui-border bg-sui-well`.

**Anatomy.** An icon tile — `size-11 rounded-md border border-sui-hairline bg-sui-surface text-ink-400` — a title at `text-ink-900` or `dark:text-ink-50`, a description at `text-ink-500` / `dark:text-ink-400`, and at most one action.

The dashed boundary and the inset fill together say "reserved, not yet built". A
raised card in that position would imply a feature that exists. Where the
placeholder is genuinely a planned module, a `sui-chip-warning` badge and a spec
line clarify the difference between "nothing here yet" and "this is coming".

**Variants — three.**

| Variant | Treatment |
| --- | --- |
| Empty | Nothing exists yet — no action, or a single action that creates the first item |
| No results | Something exists but the filter excluded it — a "clear filters" action, always |
| Reserved | The feature is planned — dashed border, well fill, no action |

**States.** All three are inert. Focus-visible never lands on the container. If
an action is present it carries its own full state set. The empty state must never
be announced twice: it is static content, so it should not be in an
`aria-live` region.

### 5.11 Avatars and identity tiles

**Anatomy.** A `size-8 rounded-md border border-sui-hairline bg-sui-well text-xs font-semibold text-ink-500` tile holding one character; `size-7` on the rail and in dense rows. The character is decorative — it is a rendering of the name beside it — so the tile is `aria-hidden="true"` and the name carries the identity.

Rail tiles invert: `bg-white/10` with `text-sui-rail-ink`.

### 5.12 Dividers

`sui-hairline` / `sui-divider` (both resolve to `border-sui-hairline`) is the
only separation device. A divider must not be used to separate two things that
should be two cards, and a hairline must not be used as an interactive
boundary (§2.1).

### 5.13 Modal scrims

The full-screen wash behind a dialog is `bg-ink-950/50`, matching the drawer's
`bg-ink-950/40` convention. The scrim is never the only indication that the
page is blocked — focus is trapped and `aria-modal="true"` is set on the panel
(see §5.7.2). The panel itself is
`rounded-xl border border-sui-hairline bg-sui-surface shadow-soft-3` and must
never carry `shadow-xl`; it is a floating surface at `shadow-soft-3`.

---

## 6. Density budget

A design system fails in one of two ways: it is incomplete, so every new page
invents its own treatment, or it sprawls, so the same element has fourteen
incompatible styles. The budget pins both ends at once. Each figure is the
**number of distinct visual variants the system guarantees for that element
family** — the variants listed in `§5` — and simultaneously the ceiling.

| Element family | Variants | Section |
| --- | --- | --- |
| Links | 15 | `§5.4` |
| Inputs | 12 | `§5.2` |
| Buttons | 7 | `§5.3` |
| Lists | 6 | `§5.5` |
| Navigation | 2 | `§5.7` |
| Tables | 1 | `§5.6` |

Rules:

- **Every guaranteed variant must have a state table.** A variant with no state
  rules is not a variant, it is an accident.
- **The ceiling is hard.** A sixteenth link variant or a second table variant
  requires either collapsing two existing variants into one or adding a token in
  `app.css` first. "It is only slightly different" is how the budget dies.
- **Navigation and tables are the tightest, on purpose.** They are the elements
  the user's muscle memory depends on. A table that looks different on every
  page costs the user the whole benefit of having learned the first one.
- **Links and inputs carry the most variants because they are the most
  context-sensitive.** That latitude is not an invitation to add more.

---

## 7. Responsive behaviour

### 7.1 Breakpoints in use

Tailwind's defaults: `sm` 640px, `md` 768px, `lg` 1024px, `xl` 1280px. `2xl` is
unused in the dashboard. `lg` is the structural breakpoint and does most of the
work; `sm` and `xl` tune grids.

| Breakpoint | What changes |
| --- | --- |
| Below `sm` | Single column. Stat grids become one column. Horizontal container padding is `px-5` |
| `sm` | Stat grids reflow to two columns. Container padding steps to `sm:px-8`. Buttons in a toolbar may move onto a second row |
| `md` | Used sparingly, for isolated cases such as revealing a wide action that does not fit a narrow header |
| `lg` | **The shell switches.** The rail appears, the mobile header hides, the drawer retires. Container padding steps to `lg:px-10` |
| `xl` | Grid column counts expand — two columns become three or four |

### 7.2 Layout rules

- **The shell owns layout; components own appearance.** A component must not
  change its own card, radius or shadow at a breakpoint. It changes its grid
  position, its padding within the shell scale, or which of its parts are
  rendered.
- **The nav is the scrolling region, the account block is pinned.** Only the
  nav region gets `overflow-y-auto` with `overscroll-contain`, so the account
  identity can never be scrolled out of reach.
- **Tables degrade by hiding columns, not by shrinking text.** See `§5.6`.
- **Sticky headers use `bg-sui-surface/85` plus `backdrop-blur-md`** so content
  scrolling underneath stays readable without the header becoming opaque.
- **Nothing may rely on hover to be reachable.** Every hover treatment pairs
  with a focus treatment, and a pointer-only affordance is a defect.

### 7.3 Long content and overflow

| Situation | Required treatment |
| --- | --- |
| One long line in a row or cell | `truncate` on the text plus `min-w-0` on the flex parent |
| A number that changes | `tabular-nums` so the column does not reflow |
| A heading that may wrap | `text-wrap: balance` |
| Body copy | `text-wrap: pretty` |
| A navigation region | `overflow-y-auto overscroll-contain` |
| A card containing a table | `overflow-hidden` so the radius clips |
| A code pane or image result frame | `overflow-x-auto` inside `bg-sui-well` |
| A word longer than its column | `break-words` on the container; never a fixed height |

`min-w-0` appears thirty-five times in the current tree because it is the thing
that stops a flex child from refusing to shrink. Removing one is a layout
regression.

### 7.4 Motion

Three durations, one curve (§2.10). Under
`@media (prefers-reduced-motion: reduce)` every animation and transition is
clamped, `scroll-behavior` becomes `auto`, and Alpine's `x-transition` elements
collapse to an instant swap — Alpine applies those classes regardless of the
media query, so the clamp needs `!important` to beat the inline classes it
writes. There are no exceptions to this rule anywhere in the dashboard, and a
component that needs motion to be understood is a component designed wrong.

---

## 8. Accessibility requirements

The target is **WCAG 2.2 Level AA** for every dashboard screen. Each criterion
below is stated so that it can be tested by a person with the screen, the
keyboard, and a contrast meter — no automated tool is able to verify most of
them.

### 8.1 Perceivable

| # | Level | Requirement | Acceptance criteria |
| --- | --- | --- | --- |
| A1 | must | Text contrast reaches 4.5:1, except large text (18.66px bold or 24px+ regular) which reaches 3:1 | Measured in both themes, against the actual background. The table in `§3.1`/`§3.2` is the reference set; any new pair is measured and added before merge |
| A2 | must | Non-text UI boundaries and graphical objects reach 3:1 | Checked on control borders, the accent bar on the current nav item, and the focus ring |
| A3 | must | Nothing is conveyed by colour alone | A selected row has `aria-current`; a danger message has a word; an invalid field has a message and `aria-invalid`. Test: set the page to greyscale and every state remains identifiable |
| A4 | should | Ink-400 is avoided on the canvas | Any `ink-400` use on a canvas-grey surface is a defect at 4.46:1 |
| A5 | must | The page is usable at 200% text size | Resize the browser to 200% and complete a primary task — no clipped control, no overlapping text, no lost label. `sui-control`'s combined border-thicken-and-ring exists for this case |
| A6 | must | The page reflows at 320px width | At 320px no horizontal scrollbar appears with content still reachable; tables have degraded by hiding columns, not by shrinking below legible size |
| A7 | must | Content and controls do not depend on shape, size or visual position | `aria-label` on every icon-only button; a name in text beside every initial tile |
| A8 | should | `prefers-reduced-motion` removes all motion, not most of it | At reduced motion, no transition, no reveal-on-scroll offset, no Alpine slide remains |

### 8.2 Operable

| # | Level | Requirement | Acceptance criteria |
| --- | --- | --- | --- |
| B1 | must | Every function is available from the keyboard | Complete these with no pointer: reach the skip link, open the drawer, change a filter, submit a form, open and close a dialog, navigate every rail destination |
| B2 | must | Keyboard focus is never trapped | In the drawer and every dialog, Tab cycles within the panel and Shift+Tab cycles back in; closing restores focus to the opener |
| B3 | must | Focus order matches visual and reading order | Tab through a card and confirm the sequence reads top-to-bottom and left-to-right |
| B4 | must | A visible focus indicator is present on every focusable element | Tab through the whole page; every stop shows a ring. `sui-focus` is applied wherever the default outline is suppressed |
| B5 | must | Focus is never obscured by sticky or absolutely positioned elements | Focus each element in a page with a sticky header; the focused element is not hidden behind it. `scroll-padding-top: 6rem` on `<html>` handles anchor jumps |
| B6 | must | Pointer targets meet 24×24 CSS px | Measured on icon buttons, pills, checkboxes and rail items in every state |
| B7 | should | Primary controls meet 44×44 CSS px | Measured on primary buttons, rail destinations and the drawer opener |
| B8 | must | Every dragging action has a single-pointer alternative | If any component allows dragging (reordering, selection), the same result is reachable by keyboard |
| B9 | must | Every landmark region is identified by an accessible name | `<main>` has `id="dashboard-main"`; every `<nav>` has `aria-labelledby`; every `role="dialog"` is named by its own heading or `aria-label` |
| B10 | must | Help appears in a consistent location | Any contextual help, plan explanation or usage hint appears in the same relative position on every screen that offers one |
| B11 | should | Repeated entries are auto-populated or offered for selection | When a form asks for a value the user already supplied in this flow, it is pre-filled or selectable |

### 8.3 Understandable

| # | Level | Requirement | Acceptance criteria |
| --- | --- | --- | --- |
| C1 | must | The language of the page is set | `<html lang>` reflects the app locale |
| C2 | must | Changing a setting does not change context unexpectedly | Switching a filter pill changes the list, and nothing else. Selecting a radio in a settings card does not navigate or submit |
| C3 | must | Forms identify the field, mark required fields, and identify errors in text | Every control has a `<label>`; required fields are marked in text, not by an asterisk's colour; errors are sentences adjacent to the control |
| C4 | should | A user can review and correct a submission before it completes | Destructive and state-changing actions in the editor and post flows are reversible or confirmed in words |
| C5 | must | Authentication does not depend on a cognitive test | No dashboard flow requires transcribing a code or solving a puzzle to continue |

### 8.4 Robust

| # | Level | Requirement | Acceptance criteria |
| --- | --- | --- | --- |
| D1 | must | Status messages are announced | Loading, success and failure in the editor's AI panels are exposed through `aria-live`, and a screen reader announces them without moving focus |
| D2 | must | Every custom control has role, name and value | Filter pills report pressed/current state; disclosure widgets report `aria-expanded`; icon-only buttons report a name |
| D3 | should | Error messages identify the field and suggest a fix | An error line names the field and says what to change, rather than "invalid input" |
| D4 | must | Interactive elements are real elements | Anything clickable is a `<button>` or an `<a>`. A `<div>` with a click handler is a defect — it has no keyboard, no role and no focus |

---

## 9. Content and tone standards

### 9.1 Voice

The dashboard is a workplace tool, not a marketing surface. Copy must be plain,
specific and short. The user is mid-task and reading around the thing they came
for.

| Rule | Do | Do not |
| --- | --- | --- |
| Name the object | "Save now", "Delete post" | "Submit", "OK", "Confirm" |
| Name the outcome | "3 posts need review" | "You have notifications" |
| Use the user's noun | The same word the navigation uses for a thing | A synonym in each place |
| State the effect of a destructive action | "This deletes the post and its comments. This cannot be undone." | "Are you sure?" |
| Keep eyebrow labels literal | "Recently created", "Accounts by role" | "Insights", "At a glance" |

### 9.2 Uppercase and eyebrows

`text-2xs font-semibold uppercase tracking-wide text-ink-400` — the `sui-label`
and `sui-table-head` treatment — is reserved for labels that precede content:
section headings inside a card, table headers, and the small "AI" marker in the
editor toolbar. Uppercase is not emphasis. If a word needs emphasis, it needs a
heavier weight or a brighter ink, not capitals.

### 9.3 Numbers, dates and empty values

- Counts use `tabular-nums` and are followed by the noun they count, in the
  plural when the count is not 1 — "1 post", "3 posts".
- Dates use the application's locale format and appear at `text-2xs text-ink-500`
  in a meta position.
- A value that does not exist renders as an em dash in `text-ink-400`, never as
  "N/A", "null" or a blank cell.

### 9.4 Writing errors and loading

- An error says what failed, what to do, and where. "Could not reach the AI
  service. Try again, or shorten the text." beats "Error".
- A loading state says that something is happening. A spinner with no label is a
  spinner with no meaning; `aria-live` announces the state in words.
- Avoid "Oops", "Whoops", "Something went wrong" — these read as amusement at the
  user's expense in a tool they did not choose to be blocked in.

### 9.5 Tone in disabled and empty states

- Disabled: state the reason nearby. "Saving is paused until the title is
  filled in."
- Empty: state what will be here and what creates it. "No listings yet. When a
  buyer requests one it will appear here."
- Reserved: state that the module is planned. "Planned" as a `sui-chip-warning`
  badge, plus a short spec line. Never imply the feature exists.

---

## 10. Anti-patterns and prohibited implementations

Each of these has caused a real defect in this codebase. The fix noted is the
only acceptable resolution.

1. **Raw hex in a component.** A component must not carry a hex colour, an
   arbitrary spacing value or an arbitrary `text-[` size. Add the token to
   `app.css` first. *(Fixed: the correction-type legend, which defined its six
   tints once in `app.css` instead of at the point of use.)*
2. **Stacking two primitives with the same property.** `sui-card` plus
   `bg-sui-well` is two background colours and no winner. Pick one. *(Fixed: the
   seller dashboard empty state, which dropped `sui-card` for
   `sui-empty rounded-xl border border-dashed border-sui-border bg-sui-well`.)*
3. **A utility that silently loses to a primitive.** In the built stylesheet,
   several plain utilities are ordered *before* the `sui-*` primitives and
   therefore lose: `py-1.5` loses to `py-2`; `py-2` loses to `py-1.5` in reverse
   order; `bg-sui-well` beats `bg-sui-accent-softer`; `border-amber-300` loses to
   `border-sui-hairline`. Any override of a primitive's property must use the
   `!` modifier. *(Fixed in seven compact controls, the pending-comment accent
   border, and the refined-prompt background.)*
4. **`focus-ring` on the dashboard.** `focus-ring` is the public site's green-teal
   ring. On the dashboard it is always `sui-focus`.
5. **`font-mono` on the dashboard.** The scope remaps mono to Open Sans, so a
   `font-mono` usage does nothing visible and leaves a misleading class behind.
   Remove it.
6. **A hand-tinted badge or chip.** Chips take their colour from a `sui-chip-*`
   or `sui-type-*` variant. A `bg-amber-100 text-amber-800` written inline is a
   palette that has started to drift.
7. **A fourth radius.** `rounded-xs`/`sm`/`md` are 8px, `lg`/`xl` are 16px.
   `rounded-[10px]` does not exist here.
8. **`shadow-xl` on a panel.** Panels are `shadow-soft-3`. `shadow-xl` is a
   non-token shadow on a tinted surface and reads as dirt. *(Fixed in both
   editor dialogs.)*
9. **An ink token used outside its measured surface.** `ink-400` on the canvas,
   or a status colour at 600 weight carrying text, is a measured failure. *(Fixed:
   attention text moved from amber-600 to amber-700/800; the white-on-amber-500
   badge became amber-400/amber-950.)*
10. **A shared component that only works inside the scope.** The empty state is
    shared with the public site, so its ink tokens carry `dark:` variants even
    though its surface tokens do not. A shared component must render correctly in
    both trees. *(Fixed by the global `.dark` block carrying only the `sui-*`
    family, and by documenting the split in the component's docblock.)*
11. **A modal-looking drawer with no focus trap.** A `role="dialog"` with
    `aria-modal="true"` that Tab can walk out of is worse than no dialog at all.
    The drawer's trap is hand-rolled because `x-trap` is not registered in this
    application.
12. **A disabled control that loses its label.** Reduced contrast is permitted;
    a missing name is not. State the reason instead.
13. **A hover state with no focus state.** Every hover treatment in this document
    is paired with a focus treatment. An unpaired one means the component is
    unusable by keyboard.
14. **A `<div>` with a click handler.** No role, no keyboard, no name. Use a
    `<button>` or an `<a>`.
15. **A second table style.** Tables have one variant ( §5.6). A second one
    breaks the muscle memory the user built on the first.
16. **Pinning the analysis config to one machine.** Configuration that silence
    a static-analysis rule by hardcoding an absolute path only works on the
    machine it was written on. *(Fixed in `phpstan.neon`, where the
    larastan `noEnvCallsOutsideOfConfig` false positive is suppressed by a
    portable `config/*` path glob rather than by a drive-letter-specific
    entry.)*

---

## 11. QA checklist

Run this before a screen is called done. Every item is a pass/fail, and the
first four are blocking.

**Structure and tokens**

- [ ] No raw hex, arbitrary spacing value or arbitrary `text-[` size in any view.
- [ ] Every colour, shadow, radius and spacing value resolves to a token.
- [ ] Every repeated pattern three times or more has become a `sui-*` primitive.
- [ ] No primitive is stacked with another primitive on the same property.

**States**

- [ ] All seven states exist for every interactive component (§4).
- [ ] Every hover state has a matching focus-visible state.
- [ ] Disabled controls keep their label and state their reason nearby.
- [ ] Loading states are announced in words, not only drawn.
- [ ] Errors are sentences adjacent to the control, with `aria-invalid` and
      `aria-describedby` where applicable.

**Accessibility**

- [ ] Contrast verified in both themes against the actual background (§3).
- [ ] The whole flow is completable by keyboard alone.
- [ ] Tab order follows reading order; no focus trap except in the drawer and
      dialogs.
- [ ] A visible ring on every stop, never removed, never obscured.
- [ ] Icon-only controls have `aria-label`; decorative graphics are
      `aria-hidden`.
- [ ] 200% zoom and 320px width both work; tables degrade by hiding columns.
- [ ] `prefers-reduced-motion` removes all motion, including Alpine
      `x-transition`.
- [ ] Custom controls report role, name, and value; status messages are announced.

**Responsive**

- [ ] The shell switches at `lg`; the rail, header and drawer behave per §7.2.
- [ ] Long content truncates, wraps or breaks rather than clipping.
- [ ] Numbers use `tabular-nums`; changing values do not reflow a column.
- [ ] No component changes its radius, shadow or card style at a breakpoint.

**Consistency**

- [ ] Nav and table styles are the single defined variant (§6).
- [ ] The accent appears on at most three things: current destination, primary
      action, focused control.
- [ ] Any new variant is measured, added to §3's table, and counted against the
      §6 budget.
- [ ] Open Sans throughout; no `font-mono`, no `focus-ring` in the dashboard.

**Verification gates**

- [ ] `composer test` passes — Pint, PHPStan level 7 with no errors, and the Pest
      suite.
- [ ] `npm run build` succeeds.
- [ ] The change is committed; uncommitted working-tree changes are not done.
