# AI Workflow

The observability surface for autonomous software engineering. This document is the
design specification for the **AI Workflow dashboard** — the screen an engineering
lead opens when they need to answer one question: *what is the system doing right
now, and can I trust what it produced?*

---

## 1. What this surface is

Every autonomous coding product eventually confronts the same failure: the work
happens somewhere the operator cannot see, and the only artefact left behind is a
diff. That is a demo. A workflow that an organisation will actually put in front of
production needs the opposite property — **every decision the system makes must be
observable while it is being made, not reconstructed afterwards.**

The AI Workflow dashboard is built on that premise. It is not a project board with
an AI column bolted on, and it is not a log viewer with a graph drawn over it. It is
a single screen where two representations of the same run are held in tension:

- a **Kanban board** that expresses *commitment* — where each task sits in the
  pipeline, and which gate it has or has not cleared;
- a **flow graph** that expresses *causality* — which agent acted, on whose input,
  with what evidence, and what it handed downstream.

The board answers "where is it". The graph answers "why". Neither is sufficient
alone, which is why they share one viewport and one state model rather than living
on separate routes.

| Property | Requirement |
| --- | --- |
| **Truthfulness** | Every node, edge, and card is backed by an event. Nothing on screen is decorative telemetry. |
| **Legibility under load** | A run with forty active agents must remain readable at a glance. Density is managed by hierarchy, not by hiding. |
| **Interruptibility** | Any stage can be paused, inspected, or taken over by a human without restarting the run. |
| **Attribution** | Every artefact carries the agent, model, and gate that produced it. |

---

## 2. Design intent

Five principles govern every choice on this screen. They are stated as constraints
because a dashboard of this density will drift toward decoration without them.

| # | Principle | In practice | Forbidden |
| --- | --- | --- | --- |
| W1 | **The interface is an instrument, not a showcase** | Data density is a feature. Chrome recedes; the run is the only subject. | Hero illustrations, marketing copy, mascots, empty-state art. |
| W2 | **Glow is state, never style** | Light is emitted only by things that are *doing* something. A static node is dim. | Ambient gradients, decorative particles, glowing borders on idle panels. |
| W3 | **Two accents, two meanings** | Cyan means *machine intent*; green means *human-verified*. They never appear on the same element. | A third accent, or either accent used for branding. |
| W4 | **Colour is never the only signal** | Every status carries a shape, icon, or label alongside its hue. | A red edge with no label; a green dot with no text. |
| W5 | **Motion encodes progress, then stops** | Animation runs while work runs. When the run is idle, the screen is still. | Looping ambient animation, easing curves chosen for feel. |

---

## 3. Interface style

**Dark mode only, split view.** This is a deliberate departure from the rest of the
product, and it is justified: the dashboard is a monitoring surface, typically
opened on a second display or a wall-mounted screen and left running for hours. A
light canvas at that duty cycle is fatiguing, and a luminous node graph only reads
as luminous against a deep ground.

The dark treatment is not "light theme inverted". It is a four-step navy elevation
ramp where each step is a genuine layer, not a shadow:

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ⌘K  search runs · agents · artefacts        ● 3 runs live   ◐ 12:04:38  │  header
├───────────────────────────────────────┬──────────────────────────────────┤
│  BOARD                                │  FLOW                            │
│  ┌────────┬────────┬────────┬────────┐│        ╭─────────╮               │
│  │Trigger │Planning│Encoding│ Review ││        │ Planner │               │
│  │   ●    │   ◐    │   ◐    │   ○    ││        ╰────┬────╯               │
│  │ ┌────┐ │ ┌────┐ │ ┌────┐ │ ┌────┐ ││      ╭──────┴──────╮            │
│  │ │card│ │ │card│ │ │card│ │ │card│ ││   ╭──┴───╮     ╭───┴──╮         │
│  │ └────┘ │ └────┘ │ └────┘ │ └────┘ ││   │Coder │ ··· │Coder │         │
│  │        │        │        │Deploy  ││   ╰──┬───╯     ╰───┬──╯         │
│  │        │        │        │  ●     ││      ╰────┬────────╯            │
│  └────────┴────────┴────────┴────────┘│        ╭──┴────╮                │
│                                       │        │Review │                │
├───────────────────────────────────────┴────────┴────────┴────────────────┤
│  tokens 1.2M · cost $4.18 · 3 gates passed · 0 blocked · p95 41s        │  telemetry
└──────────────────────────────────────────────────────────────────────────┘
```

| Region | Proportion | Behaviour |
| --- | --- | --- |
| Header | 56 px, fixed | Run selector, live counter, wall clock, global pause. |
| Board | 58 % at ≥1536 px | Horizontally scrollable columns; vertical scroll per column. |
| Flow | 42 % at ≥1536 px | Pan and zoom; auto-frames the active subgraph. |
| Telemetry strip | 40 px, fixed | Aggregate counters for the current run. Never per-card detail. |

The split is a single draggable divider, not two independent panes. Dragging it
re-frames the graph rather than clipping it — the layout is one system.

---

## 4. Left panel — the Kanban board

Five columns, in fixed order. The order is the pipeline; a card cannot skip a
column, and the column a card occupies is the *only* statement of its stage.

| # | Column | Owned by | Exit gate | Accent |
| --- | --- | --- | --- | --- |
| 01 | **Task Trigger** | Ingress | Intent parsed, repo resolved, acceptance criteria drafted | Muted navy |
| 02 | **AI Planning** | Planner | Plan decomposed into typed steps; risk and blast radius scored | Cyan, low intensity |
| 03 | **Multi-Agent Encoding** | Encoder pool | All steps produce diffs; build passes locally | Cyan, pulsing |
| 04 | **Review & Testing** | Reviewer + Test runner | Static analysis, unit and integration suites green; human sign-off where required | Neon green, on pass |
| 05 | **Cloud Deployment** | Deployer | Canary healthy, rollback armed, run closed | Neon green, steady |

**Card anatomy.** Every card carries the same six fields in the same order, so a
column can be scanned vertically without re-learning the layout:

1. **Identifier** — monospace, short, stable (`RUN-4821`). Never truncated.
2. **Intent line** — one sentence, the task as the operator stated it. This is the
   only prose on the card and it is capped at two lines.
3. **Agent chip** — avatar plus handle of the agent currently holding the card.
4. **Target** — repository and branch, monospace, middle-elided.
5. **Confidence** — a three-segment bar, not a percentage. Precision here is false
   precision; the operator needs a band, not a number.
6. **Gate badge** — the exit gate's state: `pending`, `running`, `passed`,
   `failed`, `waived`. A failed gate turns the card's left rule to the warning
   colour and adds a text label; it never relies on hue alone.

**Interaction model.**

- **Dragging a card is not a status edit.** It is a request to re-run the target
  column's gate. The card animates to the column, then sits in `running` until the
  gate returns. A rejected gate animates the card *back*, with the reason attached.
  This is the single most important behavioural decision on the board: it keeps the
  displayed stage and the true stage identical.
- **WIP limits are enforced visually.** A column at capacity shows a hairline
  meter under its header and refuses new cards with an inline explanation rather
  than a modal.
- **Blocked cards stay in place.** A card waiting on a human appears at the head of
  its column with a cyan bracket and a `needs you` label; it is never moved to a
  separate "blocked" column, because that would break the pipeline reading.
- **Hover reveals the last event**, not a tooltip summary. One line, monospace,
  with a timestamp.

---

## 5. Right panel — the flow visualization

The graph is a live causal record. Nodes are actors and artefacts; edges are
handoffs. It is laid out left-to-right by stage so that the graph's horizontal axis
aligns with the board's vertical columns — a deliberate correspondence that lets an
operator find the same task in both representations without hunting.

**Node taxonomy.**

| Node | Shape | Meaning | Glow |
| --- | --- | --- | --- |
| Trigger | Rounded ingress | External event: issue, message, schedule | None until active |
| Agent | Hexagon | A model-backed actor with a role and a budget | Cyan, intensity ∝ token throughput |
| Gate | Diamond | A blocking checkpoint; the only place a run can stop | Green when passed, amber when pending |
| Artefact | Rect, dashed edge | Diff, test report, build, deployment | None; artefact nodes are inert |
| Human | Circle, double ring | Required approval | Cyan bracket until cleared |

**Edge semantics.** Line style is the edge's type, and it is never varied for
decoration:

- **Solid** — a completed handoff, with a payload that was consumed.
- **Dashed** — a conditional or retried path; the retry count sits on the midpoint.
- **Animated pulse** — an in-flight handoff. The pulse travels source to target
  and its rate tracks throughput, so a saturated link looks saturated.
- **Amber dashed** — a rejection travelling back upstream. Rejections are the only
  edges that flow right-to-left, which makes them unmistakable.

**Glow language.** Glow intensity is a continuous variable bound to one metric per
node type — token throughput for agents, queue depth for gates. It is capped, so a
run under extreme load saturates rather than blooming into illegibility. Edges
inherit the colour of their source node and never glow independently.

**Interaction.**

- Hover a node: trace its full ancestry and its full downstream path, dimming
  everything else to 20 % opacity. This is the primary debugging gesture.
- Click a node: the inspector opens with the actor's system prompt version, model,
  budget consumed, and every artefact it emitted.
- A time scrubber replays the run's graph state at any past timestamp, reconstructed
  from the event log — the graph is deterministic and replayable, which is what makes
  it an audit trail rather than an animation.
- Auto-framing follows the active frontier, but any manual pan pins the viewport
  until the operator releases it. The camera never fights the user.

---

## 6. Colour

A single ground, two accents, and nothing else. The palette is deliberately
narrow because the screen carries a great deal of encoded meaning and a wide
palette would compete with it.

The panel wears the page's own scales: the ground is the `ink` family and the
machine-intent accent is the `signal` family, so the accent picker retunes it
and the light/dark theme both read. The roles below are declared once under
`@theme` in `resources/css/app.css` and overridden in the `.dark` scope.

| Token | Light | Dark | Role | Budget |
| --- | --- | --- | --- | --- |
| `--aw-canvas` | `#FFFFFF` | `ink-950` | The panel's own ground | Ground |
| `--aw-panel` | `ink-50` | `ink-900` | Panels, columns | Surface |
| `--aw-card` | `#FFFFFF` | `ink-800` | Cards, node fills | Raised |
| `--aw-raised` | `ink-100` | `ink-700` | Hover, selected card | Raised +1 |
| `--aw-line` | `ink-200` | `ink-800` | Hairline rules, grid, edge rails | Structure |
| `--aw-accent` | `signal-700` | `signal-400` | Machine intent: active agents, in-flight edges, live state | Accent 1 |
| `--aw-accent-soft` | `signal-700` | `signal-300` | The accent rendered as text on the surface | Accent 1 |
| `--aw-on-accent` | `#FFFFFF` | `ink-950` | Ink on a filled accent chip | Text |
| `--aw-verified` | `#4D7C0F` | `#A3E635` | Human-verified: passed gates, healthy canary, closed runs | Accent 2 |
| `--aw-warning` | `#B45309` | `#FBBF24` | Blocked, rejected, degraded — the only warning hue | Signal |
| `--aw-ink` | `ink-900` | `ink-50` | Primary text | Text |
| `--aw-ink-muted` | `ink-600` | `ink-300` | Secondary text, metadata | Text |
| `--aw-ink-faint` | `ink-500` | `ink-400` | Disabled, timestamps, placeholders | Text |

**Rules.**

- **Budget: 80 / 15 / 5.** Eighty percent of the surface is ground, fifteen
  percent is text, five percent is accent. If an accent exceeds five percent,
  the screen has stopped communicating and started decorating.
- **Accent and verified never share an element.** An element is either
  machine-owned or human-verified. A card cannot be both, so a card cannot be
  both colours. Verified is a lime green precisely so it cannot be mistaken
  for the accent, which may itself be green.
- **Amber is a signal, not an accent.** It appears only where a human must act, and
  it is the only hue permitted to interrupt.
- **All text meets WCAG AA at 4.5:1** against the surface it sits on. The ground
  and the accent both flip stop between themes so the pairing holds in each
  direction: in the light theme the accent is a dark stop carrying white ink, in
  the dark theme it is a light stop carrying dark ink.
  `--aw-ink-faint` is restricted to non-essential metadata and never carries state.
- **No raw hex in templates.** Tokens are declared once under `@theme` and
  referenced by intent, consistent with the project's token rule. The one
  exception is the glass modal, which re-declares these roles locally so it
  stays dark in both themes.


---

## 7. Typography and density

| Role | Treatment |
| --- | --- |
| Identifiers, branches, timestamps | Monospace, tabular numerals, never proportional |
| Column headers | Small caps, tracked +4 %, muted |
| Card titles | 14 px, 1.35 line-height, two-line clamp |
| Body and inspector prose | 13 px, measure capped at 62 characters |
| Counters | Monospace, tabular, right-aligned so digits do not jitter |

Density targets: 40 cards visible across the board at 1440 px without scrolling the
page, and a graph legible at 100 % zoom with 60 nodes. Both are verified in review
rather than estimated.

---

## 8. Motion

| Event | Duration | Curve |
| --- | --- | --- |
| Card enters a column | 220 ms | `ease-out` |
| Card rejected, returns | 320 ms | `ease-in-out` |
| Edge pulse, per hop | 900 ms, linear | Linear, because it represents throughput |
| Node glow ramp | 400 ms | `ease-out`, monotonic |
| Inspector open | 180 ms | `ease-out` |

Only the edge pulse loops, and only while a handoff is in flight. Under
`prefers-reduced-motion: reduce` every pulse becomes a static 2 px stroke at the
edge's midpoint, glow ramps resolve instantly, and no layout animation runs — the
information is preserved, only the movement is removed.

---

## 9. States

| State | Board | Graph |
| --- | --- | --- |
| **Idle** | Cards static, no meters | Fully dim, edges at rest, no pulses |
| **Streaming** | Active card shows a progress rule | Frontier nodes glow, in-flight edges pulse |
| **Blocked** | Bracket and `needs you` label at column head | Human node ringed, upstream path held at full opacity |
| **Degraded** | Amber left rule, gate badge reads `waived` | Amber edge to the gate, retry count visible |
| **Failed** | Card holds position, reason inline | Red-amber sink node, path highlighted end to end |
| **Complete** | Card in Deploy with a steady green rule | All gates green, pulses stopped, run stamp applied |

Empty, loading, and error states use the same geometry as the populated screen —
skeleton columns, skeleton nodes — so the layout never reflows when data arrives.

---

## 10. Responsive behaviour

| Breakpoint | Layout |
| --- | --- |
| ≥1536 px | Split 58 / 42, both panes fully visible, graph at 100 % |
| 1280–1535 px | Split 62 / 38, graph auto-zooms to fit width |
| 1024–1279 px | Split 68 / 32, graph collapses to a focus mode showing one stage at a time |
| <1024 px | Stacked: board first, graph below with a sticky stage selector. The board is never horizontally clipped — columns scroll, the page does not. |

The split view is the product. Below 1024 px it degrades honestly into two stacked
surfaces rather than pretending a two-pane dashboard fits a phone.

---

## 11. Accessibility

- Every status is encoded three ways: colour, icon or shape, and a text label.
- The board is a real list structure, navigable by keyboard; columns are labelled
  regions, and cards are reorderable with a documented key command.
- The graph exposes an equivalent linear list — "accessible flow" — reachable by a
  single tab stop, ordered by stage. The visualisation is an enhancement, never the
  only path to the information.
- Focus rings are 2 px cyan at 2 px offset, visible against every surface.
- Live regions announce stage transitions at a polite priority; token-level
  streaming is never announced.

---

## 12. Acceptance checklist

A build of this screen ships only when all of the following hold:

- [ ] Every visible node and card traces to an event in the run log.
- [ ] Dragging a card re-runs a gate and cannot silently change a stage.
- [ ] Glow intensity is bound to a real metric and is capped.
- [ ] Cyan and green never co-occur on one element.
- [ ] Accent coverage is ≤ 5 % of the rendered surface at 1440 px.
- [ ] The graph is replayable from the event log at any timestamp.
- [ ] 60 nodes remain legible at 100 % zoom; 40 cards fit without page scroll.
- [ ] Reduced-motion mode preserves all state information.
- [ ] Every status survives a greyscale screenshot.
- [ ] The linear "accessible flow" view exposes the same data as the graph.

---

*Implemented.* The band renders on `/about` through
`resources/views/components/app/ui/ai-workflow.blade.php`, driven by
`AboutContent::aiWorkflow()`; every row is managed at `/admin/about` behind
`about:manage`. The vocabulary — five stages, three agent states, two metric
bands, four tones — lives once in `App\Support\WorkflowTaxonomy`, which the
board, the graph and the console all read, so a rename cannot make the board
and its graph disagree. The board's client-side interactions (adding a card,
advancing a stage) are a preview and are labelled as such on the page; the
stored rows are the source of truth. The token discipline the surface inherits
is defined in [`UI-UX-BRIEF.md`](UI-UX-BRIEF.md) §2.*
