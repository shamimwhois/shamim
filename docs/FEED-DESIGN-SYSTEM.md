# FEED-DESIGN-SYSTEM

The `/feed` surface's design contract. Everything the module renders — the
index, the thread page, the rails, the inspector, the composer — draws from
the layers documented here. If a value you need is not here, add it here
first; never hand-tint at the point of use.

Upstream context: `docs/themes.md` (§5 defines the feed bridge, §7 the
always-dark panel rule), `docs/UI-UX-BRIEF.md` (the editorial thesis),
`docs/PRD.md` (the feed's product scope).

---

## 1. Design thesis

The feed is an **editorial social-forum hybrid**: the rhythm of a timeline,
the structure of a forum. Its visual language is the site's own — a
near-monochrome canvas, one terminal-tinted action colour, monospace used as
a *structural device* (metadata, counts, telemetry, section labels) rather
than as decoration. Nothing on the surface is a screenshot number: every
count, score and chart is derived from the corpus at render time.

Three commitments the module never breaks:

1. **Role names, not hues.** Colour is always consumed through the
   `--color-feed-*` bridge (§2). A component that needs a colour asks for a
   *role* (`mint`, `azure`, `ink-2`), never a hex.
2. **The theme flips, the module does not.** Light and dark are one token
   set re-scoped by `.dark` (§6). No surface hardcodes a text colour that
   should flip.
3. **Motion is state, not decoration.** Animation announces arrivals and
   marks activity (§7); nothing loops when nothing is live.

---

## 2. Colour tokens — the `--color-feed-*` bridge

Defined in `resources/css/app.css` (`@theme`), re-scoped for dark in the
`.dark` block. Every value is a `var()` alias over the editorial
`ink` / `signal` / `info` / `danger` scales — a pure alias layer, enforced by
`FeedTest` (each declaration must start with `var(`).

| Role | Light | Dark | Used for |
| --- | --- | --- | --- |
| `feed-canvas` | ink-50 | ink-950 | the page behind everything |
| `feed-rail` / `feed-card` | white | ink-900 | the fixed rail / the card drum |
| `feed-card-low` | ink-100 | ink-950 | recessed card fill (filter pills) |
| `feed-raised` | ink-100 | ink-800 | hover wash, selected rows |
| `feed-well` | ink-100 | ink-950 | inset fields, wells, stat tiles |
| `feed-line` | ink-200 | ink-800 | hairlines, default borders |
| `feed-line-strong` | ink-300 | ink-700 | emphasised boundaries |
| `feed-ink` / `ink-2` / `ink-3` | ink-900 / 600 / 500 | ink-50 / 300 / 400 | primary / secondary / muted text |
| `feed-azure` | `info` | `info` | links, focus, filters, accent text |
| `feed-mint` | signal-700 | signal-400 | the action tone: publish, solved, pinned |
| `feed-error` | `danger` | `danger` | moderation, unanswered demand |

**Rules**

- `mint` and `azure` are *roles*. Because they alias `signal-*` and `info`,
  the public accent picker (`data-accent`) re-tunes the feed for free. Never
  write `text-emerald-*` or `text-blue-*` in a feed view.
- `feed-mint` is the **action** colour; `feed-azure` is the **navigation**
  colour. A button that acts is mint; a link that moves you is azure. They
  are never swapped.
- The **always-dark terminal panels** (code sample, review diff) are the one
  fixed-colour surface — static `ink-950`/`ink-800`/`ink-300` tokens that
  never re-scope (`docs/themes.md` §7). `feed.css` may contain exactly one
  hex literal: the diff's delete red `#f87171`, which must not brighten with
  the theme. `FeedTest` pins this.

---

## 3. Type vocabulary

Base scale is the site's editorial scale (1.200 minor third) — the feed adds
four named grades in `feed.css` so metadata never composes
`font-mono text-[10px] …` by hand:

| Class | Spec | Used for |
| --- | --- | --- |
| `.feed-eyebrow` | 10px mono, uppercase, tracked, ink-3 | section labels, count lines |
| `.feed-meta` | 11px mono, ink-3 | bylines, timestamps, footnotes |
| `.feed-count` | 10px mono, tabular-nums, ink-3 | numbers inside rail rows |
| `.feed-stat-value` | 18px mono semibold, tabular | telemetry tile values |

Weight (`font-semibold`) and colour (`text-feed-mint`, `text-feed-ink`) stay
utilities so a variant is visible where it is used. Body copy and headings
use the shared scale (`text-sm`, `text-base`, clamp()ed `h1`) — the feed
invents no sizes of its own beyond the four grades.

---

## 4. Component vocabulary

All defined in `resources/css/feed.css`. Markup adds utilities for padding
and layout; anything structural or stateful lives here.

| Class | Shape |
| --- | --- |
| `.feed-card` | the enclosure: module radius, hairline, card fill; hover lifts the border to azure/40; `.is-pinned` swaps it to a mint spine |
| `.feed-cover` | legacy cover fill, retained for the band base: card fill + two anchor-colour radial washes that flip with the theme |
| `.feed-command-bar` | sticky strip above the stream: canvas at 92%, blurred, hairline below |
| `.feed-tab` | underline tab; active rule is mint |
| `.feed-tab-count` | the unanswered count badge (error wash) |
| `.feed-pill` / `.feed-filter` | filter triggers; selected = mint / azure border + wash |
| `.feed-menu` / `.feed-menu-item` | dropdown panel and rows; current item azure |
| `.feed-chip` | the small status pill; variants `is-mint`, `is-azure`, `is-error`, `is-attention`, `is-quiet` — a chip is **never hand-tinted** |
| `.feed-segment` | continuous well with a floating raised pill (segmented control) |
| `.feed-vote` | the score drum |
| `.feed-action` | the mint primary action with its hover glow |
| `.feed-ghost` | quiet utility button; hover raises |
| `.feed-search` | the well input with azure focus ring |
| `.feed-rail-link` | rail row; current page carries the azure spine |
| `.feed-inset` (`.is-action`) | recessed well tile; `is-action` raises on hover (links/buttons) |
| `.feed-prompt` | the composer's collapsed pill line |
| `.feed-empty` | the stream's empty state: centred, generous padding |
| `.feed-live-pill` / `.feed-live-dot` | the arrivals announcer and its mint pulse |
| `.feed-code`, `.diff-*`, `.comment-*` | always-dark terminal panels and threads |
| `.feed-card-enter` | one-shot arrival animation (§7) |

**Fragments on the index**

- **Broadcast band** — identity (avatar, name, role, verification) + the
  headline, the four-figure readout, the always-dark topic-signal panel and
  the ticker; then the section tabs. Tabs name routes that exist; nothing is
  a dead link.
- **Command bar** — live pill above the filter row, so an arrival never moves
  the stream.
- **Three-column grid** — `lg:grid-cols-[minmax(0,1fr)_20rem]`,
  `xl:grid-cols-[14rem_minmax(0,1fr)_20rem]`; on phones the stream is
  `order-1`. Pinned by `FeedTest`'s responsive contract.
- **Stream** — one card per row; each card is author → body → artefact →
  tags → meta bar. The artefact is the type's own: terminal panel (code),
  poll bars, topic tile, accepted-answer callout.
- **Inspector widgets** — every widget is a `.feed-card` with a
  `.feed-eyebrow` header and `.feed-inset` tiles; every figure is derived
  from the passed collections.

---

## 5. The broadcast layer

The feed reads as a **broadcast surface**, not a timeline: a loud top band
that states what the platform is and how much of it is live, a moving ticker
that surfaces the actual vocabulary of the corpus, a single-column wire of
cards, and a console-style rail on the left.

| Class | Shape |
| --- | --- |
| `.feed-hero` | the broadcast band: two anchor-colour radial washes + a masked 64px technical grid over the canvas fill |
| `.feed-hero-eyebrow` | the band eyebrow — mono, uppercase, with a live mint dot |
| `.feed-hero-title` | the headline (weight 800, tracking -0.035em, `clamp()` scale) |
| `.feed-hero-mark` | the marker-highlight swipe behind the two emphasised words |
| `.feed-hero-sub` | the supporting line under the headline |
| `.feed-hero-stats` / `-stat-value` / `-stat-label` | the four-figure readout: posts live, people, solved, need answers |
| `.feed-hero-panel` | the always-dark instrument panel (static `ink-*` tokens, §7 of `docs/themes.md`) |
| `.feed-hero-panel-title` | the panel's title |
| `.feed-meter` / `-track` / `-fill` | per-topic volume bars; fill width is the topic's real share of the corpus |
| `.feed-ticker` / `-run` / `-items` / `-item` | the marquee: trending tags + real destination links |
| `.feed-rise` / `.feed-rise-1..4` | the 620ms entrance choreography of the band's children, staggered 60ms |
| `.feed-card-kicker` | the card's kicker row: index, topic, relative time |
| `.feed-card-title` | the card headline (17px, 18px at 1536px, weight 700) |
| `.feed-card::before` | the accent spine that scales in on hover/focus-within; always on for `.is-pinned` |
| `.feed-reactions` | the meta bar's reaction cluster |
| `.feed-transmit` / `-prompt` | the composer bar and its live-dotted prompt line |
| `.feed-type-rail` / `.feed-type-button` | the composer's six type chips; selected is a solid mint block (`aria-pressed`) |
| `.feed-rail-index` | the console section number (`01`…`04`) in the left rail's headers |
| `.feed-eyebrow.is-accent` | an eyebrow in the navigational azure, for widget titles |
| `.feed-signal` / `.feed-signal-bar` | the 12-week corpus activity chart: real weekly volume as bar height |

**Rules the layer obeys**

1. **One home for the totals.** The corpus figures (posts / people / solved /
   unanswered) live in the hero and *nowhere else* on the page. `FeedTest`
   pins `substr_count($html, 'People') === 1`. The inspector shows
   reader-specific numbers instead (your posts, member since) and
   corpus-*derived* but non-duplicated figures (answer rate, peak week).
2. **Every figure is real.** The hero readout, the topic meters, the ticker
   tags and the activity chart all read the memoised `posts` / `topics` /
   `typeCounts` / `trendingTags` computed properties. The band adds **zero**
   SQL — the page still renders four owned queries.
3. **The marquee loops on a duplicate track.** The second copy is
   `inert aria-hidden="true"`, so the loop is seamless without duplicating
   focusable links or tripping the focusable-inside-`aria-hidden` rule.
4. **Drama comes from scale and structure, not decoration.** No glass, no
   neon, no gradient wash of a second hue. The impact is a huge headline, a
   hard-edged grid texture, one solid accent block, and a real ticker.

### The composer (`pages/feed/⚡composer-modal.blade.php`)

The write surface rides the same tokens as the rest of the module; the modal
*shell* is the app's (`x-app.ui.modal`), the composer's insides are the
module's. The composer never hardcodes a surface colour — a sticky bar on a
phone fills with `bg-feed-canvas`, never a raw `bg-white`/`dark:bg-zinc-800`
pair (that pair once shipped here; it stranded a light bar on the dark
canvas exactly like the amber chip did).

| Class | Shape |
| --- | --- |
| `.feed-well` | recessed fill + hairline (discard confirm, staged media, deferred options). The name was in markup before the rule existed — the fill was silently missing, which is why the rule was written |
| `.feed-input` | every form field: well fill, hairline, azure focus ring. `.is-headline` earns the one piece of scale; `.is-mono` is terminal text for code/URLs |
| `.feed-dropzone` | the editor's field: the hero's masked technical grid echoed at desk scale; `:focus-within` raises the azure ring; `.is-dragging` turns the border mint dashed — the one moment drama is warranted |
| `.feed-preview` | the preview renders as the wire card will: card fill, `.feed-preview-title` at card-title weight, snippet inside the always-dark `.feed-code` panel |
| `.feed-composer-rule` | the hairline above the publish bar |
| `.feed-steps` | the workflow rail: the four steps as chips joined by hairline connectors — done is `is-mint`, the step being worked is `is-azure` and carries `aria-current="step"`, an optional step with nothing in it is `is-quiet` (never struck through) |

Rules the composer obeys:

1. **Sticky bars are canvas-filled, not colour-guessed.** The dialog is the
   scroll container at every size, so header and publish bar are sticky on
   phones and must carry a token fill.
2. **Fields never re-declare their run.** A form-field change is one edit in
   `feed.css`, not twelve in the Blade.
3. **The preview is the proof.** It renders through the same vocabulary the
   wire card uses — same title weight, same terminal panel for snippets —
   so what an author approves is what a reader sees.
4. **In the grid texture's zone, content paints above it.** Children of
   `.feed-dropzone` carry `relative` — the `::before` is positioned and
   would otherwise paint over in-flow text.

---

## 6. Interaction & spacing rhythm

- Spacing sits on Tailwind's 4px grid; the module's recurring paddings are
  `p-2` (rails), `p-4` (cards/widgets), `p-2.5` / `p-3` (insets).
- Surfaces separate by **fill**, not by heavier borders: canvas → card →
  well/raised is a three-step ladder; the hairline is always 1px.
- Hover is a fill or a border shift (150ms `--ease-soft`); the active page
  or tab is a spine or an underline, not a heavier shadow.
- Focus is the global `:focus-visible` outline; interactive elements that
  suppress it use the shared `focus-ring` utility.

---

## 7. Theming — how the module matches the rest of the app

- Dark is the site default; `partials/theme-boot` applies `.dark` pre-paint.
  The feed flips through the same `.dark` re-scope as every public surface —
  it ships no private dark palette.
- The dashboard's Soft UI scope (`[data-sui]`) never reaches the feed: the
  two surfaces are intentionally different voices of one brand.
- The accent picker (`data-accent` on `<html>`) re-tunes `signal-*`, and the
  bridge resolves `feed-mint` from it — so mint, the live dot, pinned
  spines and the action glow all follow the visitor's chosen accent while
  `azure` stays navigational blue.
- Semantic `warning` (attention chips) and `danger` (error chips, unanswered
  counts) also re-scope, which is why `.feed-chip.is-attention` /
  `.is-error` are token-built rather than raw amber/red utilities: a raw
  `bg-amber-500/15!` chip is a light chip stranded on the dark canvas.

---

## 8. Motion

| Pattern | Rule |
| --- | --- |
| Band entrance (`feed-rise`) | 620ms `--ease-out-quint` rise + fade, staggered `.feed-rise-1..4`; opt-out under `prefers-reduced-motion` |
| Marquee (`feed-ticker`) | one continuous `translateX(-50%)` loop over a duplicated track; stopped under `prefers-reduced-motion` |
| Card spine (`feed-card::before`) | `scaleY` 0→1 on hover/focus-within, 220ms; pinned cards carry it permanently |
| Arrival (`feed-card-enter`) | one-shot 480ms lift, only on cards revealed by the live pill; opt-out under `prefers-reduced-motion` |
| Live dot (`feed-live-dot`) | slow 2.4s mint pulse — heartbeat, never a spinner; static under reduced motion |
| Hover / focus | 150ms `--ease-soft` colour transitions only; no layout properties animate |
| Theme switch | the shell's 250ms colour cross-fade (`.theme-switching`) covers the feed too |
| Poll | `wire:poll.15s` re-renders in place; arrivals fold behind the pill until the reader asks (top-of-page auto-fold, client interval) |

---

## 9. Accessibility contract

- Landmarks: skip link → `<main id="main">`; the band labelled by its `h1`
  (`feed-cover-heading`); both pinned by `FeedTest`.
- Live regions: the stream and the count line are `aria-live="polite"`; the
  arrivals pill announces the pending count.
- Menus/tabs: `aria-haspopup`, `x-bind:aria-expanded`, `aria-current`,
  `role="menu"/"menuitem"/"listbox"`, Escape to close.
- The composer's type chips are a toggle group: `aria-pressed` marks the
  selected type, and the active rail item carries `aria-current="page"`.
- The marquee's duplicated track is `inert aria-hidden="true"` — a seamless
  loop without duplicate focus stops.
- Every `<img>` (post media) carries the author's own `alt`; icons are
  `aria-hidden`; telemetry charts carry an `sr-only` summary.
- Text pairs were chosen against the surfaces they sit on (ink-3 never on
  well fills at small sizes except for non-essential metadata).

---

## 10. Conventions for change

1. New colour need → add a `--color-feed-*` role in `app.css` (a `var()`
   alias, both themes), then consume it. `FeedTest` rejects raw values.
2. A utility run repeated three or more times → promote it to a class in
   `feed.css`'s primitives section and migrate every use.
3. New chip tone → a named `is-*` variant, never an ad-hoc tint.
4. New figure on the page → decide its **single home** first. The corpus
   totals belong to the band; the inspector carries reader-specific and
   derived-not-duplicated numbers only.
5. New motion → one signature loop per surface. The band owns the rise
   choreography and the ticker; nothing else animates continuously.
6. Tests guard the system: the bridge purity, the one-hex rule, the
   responsive grid tracks, the landmarks, the active-tab contract, the
   `compare&nbsp;notes` headline and the single-home totals rule.
