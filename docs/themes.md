# Theming — dark by default, light on request

Every surface in DevFolio — the public site, the auth screens and all three
role dashboards — renders in one of two themes: **dark (the default)** and
**light (the alternative)**. The visitor switches with one control that is
present on every surface, the choice is persisted across pages and sessions,
and both themes are built from the same token architecture, so a surface
styled once is correct in both.

This document is the system's contract: how the default is applied, where
the state lives, what the tokens are, and what the tests hold in place.

---

## 1. The one-sentence architecture

There is exactly one theme switch in the entire cascade — the `dark` class
on `<html>` — and exactly one writer for it: Flux's appearance store. Every
token in `resources/css/app.css` has a light value and a `.dark` value, so
toggling one class re-skins the whole application.

```
localStorage['flux.appearance']        ← the only persisted state
        │
        ▼
partials/theme-boot (inline, <head>)   ← fills the default, applies pre-paint
        │
        ▼
.dark class on <html>                  ← the single cascade switch
        │
        ├── @custom-variant dark       → Tailwind `dark:` utilities
        ├── .dark { … }                → public-site token re-scopes
        └── .dark [data-sui] { … }     → dashboard (Soft UI) token re-scopes
```

## 2. The moving parts

| Piece | File | Owns |
| --- | --- | --- |
| Appearance controller | `@fluxAppearance` (Flux), rendered in `resources/views/partials/head.blade.php` | The reactive store (`$flux.dark`), the `.dark` class, persistence to `flux.appearance` |
| Boot script | `resources/views/partials/theme-boot.blade.php` | The **dark default**, pre-paint application, `window.DevFolioTheme` helper |
| Toggle | `resources/views/components/app/ui/theme-toggle.blade.php` | The visitor-facing control (all surfaces) |
| Tokens | `resources/css/app.css` (`@theme`, `[data-sui]`, `.dark` blocks) | Every colour, shadow, gradient and effect value |
| Browser chrome | `<meta name="theme-color">` in `partials/head.blade.php` | The mobile address bar / task-switcher colour |

## 3. The boot flow, line by line

On every page load, two inline scripts run in `<head>` in a fixed order
(pinned by `ThemeSystemTest`):

1. **Flux's appearance script** defines `window.Flux.applyAppearance()` and
   applies whatever `localStorage['flux.appearance']` holds — falling back
   to the OS `prefers-color-scheme` when nothing is stored.
2. **`partials/theme-boot`** runs immediately after and makes the product
   decision: a *missing* preference is filled with `dark` before anything
   can resolve it to the OS value. It then re-derives the class from the
   (now-filled) preference, so the first paint is already correct — no
   flash of light content on a dark-default product. A stored choice of
   `light` is never touched.

Two consequences worth knowing:

- **The default is `dark`, not `system`.** This is deliberate. A portfolio
  whose default shifts with the visitor's OS is a portfolio that looks
  different every time you open it. A visitor who wants light clicks the
  toggle once, and that choice outlives the session.
- **No JavaScript ⇒ light.** The class is applied by script, so a
  no-JS visitor gets the light theme, the same graceful degradation every
  other behaviour in this codebase makes. Nothing renders broken — merely
  plainer.

The invariant that makes this safe everywhere: **no layout may
server-render `class="dark"`**. The auth layout once hardcoded it, which
overrode the visitor's choice on the highest-leverage view in the app;
`AuthLayoutTest` now forbids the literal string on every auth screen, and
`ThemeSystemTest` extends the same assertion to the public site and the
dashboard.

## 4. Switching

The toggle (`<x-app.ui.theme-toggle />`) is one component mounted on all
three surfaces:

| Surface | Location | Why there |
| --- | --- | --- |
| Dashboards (user / seller / admin) | the shared workspace navbar (`components/app/ui/navbar.blade.php`) | One mount reaches every role, because the layouts compose the same component |
| Public site | desktop nav and the mobile panel (`components/app/home/header.blade.php`) | The mobile panel uses a labelled row — a sun glyph with no tooltip teaches nothing on a phone |
| Auth screens | fixed top-right (`layouts/auth/split.blade.php`) | Over the form column on every breakpoint, which is where the visitor is looking |

The click does four things:

```html
@click="
    dark = ! dark;                    @{{-- local icon state --}}
    $flux.dark = dark;                @{{-- the store: applies + persists --}}
    window.DevFolioTheme.transition(); @{{-- the colour cross-fade --}}
    window.DevFolioTheme.sync();       @{{-- theme-color meta --}}
"
```

`$flux.dark` is the only state writer. Its setter maps to
`appearance: 'dark' | 'light'`, the store's effect applies the class and
writes `localStorage['flux.appearance']`, and the change survives reloads
and Livewire navigation (the class lives on `<html>`, which navigation
does not replace). The cross-fade adds a `theme-switching` class to
`<html>` for 350ms — colours transition, layout never does — and the
global `prefers-reduced-motion` clamp in app.css collapses it to an
instant swap for users who opt out of motion.

`Flux.appearance` never holds `'system'` in practice: the boot script
converts a missing value to `dark`, and the toggle only ever writes
`dark` / `light`.

## 5. The token architecture

Both themes are *token sets*, not stylesheets. Utilities never hardcode a
colour; they resolve a role (`--color-sui-surface`, `--color-ink-600`) that
re-scopes per theme and per surface. That is why a component written once —
`stat`, `navbar`, `theme-toggle` — is correct in dark and light without a
single `dark:` variant of its own.

Two palettes, two scopes:

| Scope | Palette | Used by |
| --- | --- | --- |
| Default (`@theme`) | editorial `ink-*` / `signal-*` — warm zinc + terminal green-teal, Instrument Sans / JetBrains Mono | Public site, auth screens, settings |
| `[data-sui]` | Soft UI `sui-*` — Creative-Tim-style neutrals + accessible indigo, Open Sans | The dashboard body (the scope sits on `<body>` of every dashboard layout) |

Each scope re-declares its values under `.dark` (`.dark { … }` for the
shared `sui-*` surface family, `.dark [data-sui] { … }` for the full
dashboard set). Because Tailwind v4 emits runtime `var()` references,
redefining the variables remaps every utility in the subtree at once —
one block of declarations recolours the whole dashboard.

Practical rules that fall out of this:

- **Never reach for a raw hex in a component.** If the role you need does
  not exist, add the token first.
- **Inside `[data-sui]`, the `ink-*` scale runs "backwards"** — `ink-900`
  is the dark *card surface* in dark mode, not dark text. Cross-surface
  components should use `sui-*` roles, which are stable everywhere.
- **The dashboard's indigo does not leak.** `signal-*` / `accent` re-scopes
  stay inside `[data-sui]`; the public site keeps its green-teal.

## 6. The effect vocabulary

The modern-surface effects — glassmorphism, glow, gradient type,
neumorphism — are a closed vocabulary of utilities, all resolving scoped
tokens so they adapt to both themes by construction.

| Utility | Effect | Rules of engagement |
| --- | --- | --- |
| `sui-glass` | Glassmorphism: 72% surface, 20px blur, saturation boost, hairline + top highlight | Chrome and overlays only — never behind body copy |
| `sui-nav` / `sui-sidenav` | The floating navbar/sidebar, built as glass (76% surface + blur) | The dashboard chrome is the reference implementation |
| `sui-glow` | Glowmorphism: 1px accent edge + wide soft accent halo | Active/primary moments only — `sui-tab-active`, hover states. One glowing element per region |
| `sui-text-gradient` | Gradient display type: `--text-gradient-from` → `--text-gradient-to`, 115deg | Display sizes only (stat values, the hero headline). Outside `[data-sui]` a caller **must** override both endpoints (see below) |
| `sui-hero` | The gradient welcome card: layered light pool + accent glow | The only surface allowed to carry a string on a gradient |
| `--shadow-soft-1/2/3`, `--shadow-soft-well` | Neumorphism: tinted dual shadows, pressed wells | The dashboard's depth vocabulary; never black — always the neutral tint |
| `[data-sui]` canvas washes | Ambient accent radial gradients behind the flat canvas | ≤9% opacity, `fixed` attachment — every ink contrast measurement was taken against the flat colour, so the wash must stay quiet |

Retuning the gradient endpoints outside the dashboard (the public site
keeps `ink`/`signal`, which are not indigo):

```html
class="sui-text-gradient
    [--text-gradient-from:var(--color-ink-900)] [--text-gradient-to:var(--color-signal-700)]
    dark:[--text-gradient-from:var(--color-ink-50)] dark:[--text-gradient-to:var(--color-signal-400)]"
```

The reason for the override rule: the default endpoints resolve to
`--color-sui-ink` / `--color-sui-accent`, and `sui-accent` is only
re-scoped inside `[data-sui]` — on a dark public page the un-scoped
default would put dark indigo on a near-black canvas.

## 7. Adding a themed surface

1. Pick the scope. Dashboard surface → the layout's `<body data-sui>`
   covers you; public/auth surface → the editorial palette.
2. Style with role tokens (`sui-*`, `ink-*`, `signal-*`) — no raw hexes,
   no `text-white` outside a surface that is dark in *both* themes.
3. Never write `dark:` variants for token-backed colours — add them only
   for the rare value with no token (see the correction-type legend in
   app.css for the pattern).
4. Add the toggle if the surface has its own chrome (it is already
   inherited from the shared navbar / public header / auth layout).
5. Render the page once and assert: no `class="dark"` in the HTML, and
   every class the markup names exists in the built CSS.

## 8. Accessibility

- **Contrast is measured, not estimated.** Every ink/surface pair in both
  themes clears WCAG 2.2 AA (4.5:1) on the surface it is actually used on;
  the measurements and the pairs they justify are recorded in
  `docs/SOFT-UI.md` §3 and inline in app.css. The effect layer is bounded
  by the same discipline: glass never sits behind copy, glows never carry
  state alone (the accent wash/ink does), canvas washes stay ≤9%.
- **Reduced motion** collapses the theme cross-fade, the reveal-on-scroll
  system and every Alpine transition to an instant swap — one global clamp
  in app.css, no per-feature opt-outs to maintain.
- **Focus is never a glow.** Focus-visible is a 2px outline/ring
  (`sui-focus`, `[data-sui] :focus-visible`); glows communicate state, not
  keyboard position.
- **The toggle is labelled** — `aria-label` states the theme you would
   switch to, and updates with the state.
- **Form controls follow the theme** — `color-scheme` is set for both
  halves (`:root.dark` by Flux's inline style, `:root:not(.dark)` in the
  base layer), so native widgets and scrollbars never render dark-on-dark.

## 9. The test contract

| Suite | Holds in place |
| --- | --- |
| `tests/Feature/Auth/AuthLayoutTest.php` | No server-rendered `class="dark"` on the auth screens; the appearance controller is wired |
| `tests/Feature/Theme/ThemeSystemTest.php` | The boot script runs after the controller it amends, on every surface; dark-first default is present (`flux.appearance` + `DevFolioTheme`); `theme-color` meta present; the toggle exists on all three dashboards, the public site and the auth screens; no surface renders a server-applied dark class |
| `tests/Feature/Dashboard/CompositionTest.php` | The dashboard's themed surfaces render the classes the stylesheet emits (the pastel family, the hero, the tile lift) |

When adding a surface, extend the surface lists in `ThemeSystemTest` —
they exist so a new layout cannot quietly skip the controller or the
toggle.

## 10. File map

```
resources/
  css/app.css                         ← tokens, .dark re-scopes, effect utilities
  views/
    partials/head.blade.php           ← theme-color meta + boot include
    partials/theme-boot.blade.php     ← dark default, pre-paint apply, helper
    components/app/ui/
      theme-toggle.blade.php          ← the control
      navbar.blade.php                ← dashboard mount (all three roles)
    components/app/home/header.blade.php  ← public mounts (desktop + mobile)
    layouts/auth/split.blade.php      ← auth mount
docs/themes.md                        ← this document
tests/Feature/Theme/ThemeSystemTest.php
```
