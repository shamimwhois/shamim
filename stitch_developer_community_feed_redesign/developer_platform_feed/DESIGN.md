\---  
name: Developer Platform Feed  
colors:  
  surface: '#0f1419'  
  surface-dim: '#0f1419'  
  surface-bright: '#353a3f'  
  surface-container-lowest: '#0a0f14'  
  surface-container-low: '#171c21'  
  surface-container: '#1b2025'  
  surface-container-high: '#252a30'  
  surface-container-highest: '#30353b'  
  on-surface: '#dee3ea'  
  on-surface-variant: '#c1c6d6'  
  inverse-surface: '#dee3ea'  
  inverse-on-surface: '#2c3137'  
  outline: '#8b91a0'  
  outline-variant: '#414754'  
  surface-tint: '#abc7ff'  
  primary: '#abc7ff'  
  on-primary: '#002f65'  
  primary-container: '#428fff'  
  on-primary-container: '#002959'  
  inverse-primary: '#005cba'  
  secondary: '#45f1ba'  
  on-secondary: '#003828'  
  secondary-container: '#00d5a0'  
  on-secondary-container: '#00563f'  
  tertiary: '#bcc8d5'  
  on-tertiary: '#27323c'  
  tertiary-container: '#86929e'  
  on-tertiary-container: '#202b35'  
  error: '#ffb4ab'  
  on-error: '#690005'  
  error-container: '#93000a'  
  on-error-container: '#ffdad6'  
  primary-fixed: '#d7e3ff'  
  primary-fixed-dim: '#abc7ff'  
  on-primary-fixed: '#001b3f'  
  on-primary-fixed-variant: '#00458e'  
  secondary-fixed: '#54fdc5'  
  secondary-fixed-dim: '#27e0aa'  
  on-secondary-fixed: '#002116'  
  on-secondary-fixed-variant: '#00513b'  
  tertiary-fixed: '#d8e4f1'  
  tertiary-fixed-dim: '#bcc8d5'  
  on-tertiary-fixed: '#121d26'  
  on-tertiary-fixed-variant: '#3d4853'  
  background: '#0f1419'  
  on-background: '#dee3ea'  
  surface-variant: '#30353b'  
typography:  
  headline-xl:  
    fontFamily: Geist  
    fontSize: 36px  
    fontWeight: '600'  
    lineHeight: 44px  
    letterSpacing: -0.03em  
  headline-xl-mobile:  
    fontFamily: Geist  
    fontSize: 28px  
    fontWeight: '600'  
    lineHeight: 36px  
    letterSpacing: -0.025em  
  headline-lg:  
    fontFamily: Geist  
    fontSize: 24px  
    fontWeight: '600'  
    lineHeight: 32px  
    letterSpacing: -0.02em  
  headline-md:  
    fontFamily: Geist  
    fontSize: 18px  
    fontWeight: '500'  
    lineHeight: 26px  
    letterSpacing: -0.015em  
  headline-sm:  
    fontFamily: Geist  
    fontSize: 15px  
    fontWeight: '500'  
    lineHeight: 22px  
    letterSpacing: -0.01em  
  body-lg:  
    fontFamily: Geist  
    fontSize: 15px  
    fontWeight: '400'  
    lineHeight: 24px  
    letterSpacing: -0.005em  
  body-md:  
    fontFamily: Geist  
    fontSize: 13px  
    fontWeight: '400'  
    lineHeight: 20px  
    letterSpacing: 0em  
  body-sm:  
    fontFamily: Geist  
    fontSize: 12px  
    fontWeight: '400'  
    lineHeight: 18px  
    letterSpacing: 0.005em  
  label-md:  
    fontFamily: Geist  
    fontSize: 12px  
    fontWeight: '500'  
    lineHeight: 16px  
    letterSpacing: 0.01em  
  label-sm:  
    fontFamily: Geist  
    fontSize: 11px  
    fontWeight: '500'  
    lineHeight: 14px  
    letterSpacing: 0.02em  
  code-inline:  
    fontFamily: Geist  
    fontSize: 12px  
    fontWeight: '400'  
    lineHeight: 16px  
    letterSpacing: 0em  
rounded:  
  sm: 0.25rem  
  DEFAULT: 0.5rem  
  md: 0.75rem  
  lg: 1rem  
  xl: 1.5rem  
  full: 9999px  
spacing:  
  gutter: 1rem  
  gutter-desktop: 1.5rem  
  margin: 1rem  
  margin-tablet: 1.5rem  
  margin-desktop: 2.5rem  
  space-xs: 0.25rem  
  space-sm: 0.5rem  
  space-md: 0.75rem  
  space-lg: 1rem  
  space-xl: 1.5rem  
\---

## Brand & Style

This design system expresses a refined, developer-native environment engineered for high cognitive throughput and minimal friction. Drawing inspiration from precision engineering tools like Raycast, Linear, and GitHub Discussions, it delivers a tactile, keyboard-first ethos suited for technical discussions, code sharing, and ecosystem telemetry.

The visual tone balances utilitarian efficiency with high-craft surface detail. Clean geometric typography, micro-borders, and disciplined information hierarchy reduce noise while elevating context. The UI balances technical rigor with modern luxury: dark charcoal tones, subtle translucency, luminous electric mint actions, and sharp azure anchors.

## Colors

The palette is tuned specifically for prolonged deep-work sessions in dark environments, using a tiered slate structure to establish layered depth rather than stark elevation contrasts:

- **Canvas & Structural Layers**: Base background uses deep charcoal-navy `#080D12`. Persistent sidebars and utility chrome use `#0D141B`. Base card surfaces rest on `#11181F`, rising to `#151F28` for raised containers, toolbars, and popovers.
- **Accents**: The primary anchor `#1683FF` provides high-legibility focus rings, link indicators, and system triggers. Electric mint `#00D5A0` operates as the primary success and author action tone—reserved for state merges, primary execution buttons, and positive metric indicators.
- **Micro-Borders & Dividers**: Structural boundaries utilize `#26313B` at variable opacities (typically 60–100%) to provide laser-cut edge definition without visual bulk.
- **Text Hierarchy**: Primary content commands attention in crisp off-white `#F2F6FA`. Secondary meta-labels and timestamps reside at `#A5B1BE`, while keyboard shortcuts, icons, and muted context render in `#687583`.

## Typography

The type scale emphasizes micro-typography and dense visual hierarchy. Geist delivers neutral geometry, low optical distortion at small sizes, and distinct numeral forms suitable for technical interfaces.

Headings leverage negative tracking to preserve structural tightness. Body text targets 13px and 15px increments to strike a balance between high information density and effortless scanability. Code snippets, parameter tokens, commit SHAs, and keyboard hotkeys maintain identical baseline metrics to seamlessly integrate into inline sentences.

## Layout & Spacing

The layout employs an asymmetric 3-pane structure engineered for keyboard navigation:

1. **Navigation & Scope Sidebar**: 240px fixed rail on the left (`#0D141B`).
2. **Infinite Stream / Feed**: Centered column constrained to a maximum width of 680px for optimal reading line length.
3. **Inspector & Metadata Rail**: Collapsible 320px column on the right for thread participants, code artifacts, and issue metadata.

On desktop monitors, the structure locks to a fixed grid with `1.5rem` gutters. Tablet view collapses the inspector into an overlay drawer, retaining the feed and condensed navigation rail. Mobile screens stack into a single fluid column with a bottom command bar and reduced outer margins (`1rem`). Spacing tokens adhere to a strict 4px base unit, favoring tight interior padding (`space-xs` and `space-sm`) to support dense data displays.

## Elevation & Depth

Spatial hierarchy is defined through tonal layering and hair-thin borders rather than heavy drop shadows:

- **Level 0 (App Canvas)**: Flat `#080D12` base.
- **Level 1 (Feed Cards & Sections)**: `#11181F` background framed with a 1px solid border in `#26313B`. Hover states increase surface brightness to `#131C24`.
- **Level 2 (Active Threads & Menus)**: `#151F28` elevated surface with `box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.45)`.
- **Glass Overlays (Command Palette & Sticky Headers)**: Semi-transparent `#0D141B` with 80% opacity and an `12px` backdrop blur (`backdrop-filter: blur(12px)`), bound by a 1px perimeter border at 40% `#26313B` opacity.

## Shapes

The design uses roundedness tier 2 to project modern polish while maintaining structural discipline.

Feed item cards, code containers, and modal dialogs use `rounded-xl` (`1.5rem` / `24px`) for gentle enclosure. Interactive items such as input fields, keyboard badges, dropdown triggers, and filter chips map to base `rounded` (`0.5rem` / `8px`). Nested sub-elements (e.g., status pips, avatars, vote counters) match outer corner curves with `rounded-sm` or full circular radiuses.

## Components

### Action Buttons

- **Primary**: Solid mint background (`#00D5A0`) with dark typography (`#080D12`), weight 500, radius `0.5rem`. Subtle hover glow: `0 0 12px rgba(0, 213, 160, 0.25)`.
- **Secondary**: Elevated surface (`#151F28`), 1px border (`#26313B`), primary text (`#F2F6FA`). Transitions to `#1A2632` on hover.
- **Ghost / Utility**: Transparent background, slate text (`#A5B1BE`), highlighting with `#11181F` on hover.

### Feed Cards

- Built with `rounded-xl` geometry, surface tone `#11181F`, and 1px borders in `#26313B`. Internal padding is set to `1rem` horizontally and `0.875rem` vertically. Hover transitions illuminate the border to `#1683FF` at 40% opacity.

### Filter Chips & Tags

- `0.5rem` corner radius, background `#0D141B`, 1px border `#26313B`. Text uses `label-sm` with `#A5B1BE`. Selected status applies an azure border `#1683FF` and azure-tinted background `rgba(22, 131, 255, 0.08)`.

### Code Blocks & Inset Displays

- Inset canvas color `#090E13` encased within `#151F28` surfaces. High-contrast syntax accents, paired with top-right quick actions (copy SHA, switch syntax, toggle diff) enclosed in a micro-header.

### Command Menus & Search Input

- Keyboard-first command palette styled with backdrop blur (`#0D141B`/85%), featuring a sticky query input (`15px` font), inline hotkey cues (`kbd` styling: `#151F28` with `#26313B` border), and distinct item active-selection states glowing with `#1683FF` left accents.

### Checkboxes & Segmented Controls

- Checkboxes use `0.25rem` radius, `#11181F` background, `#26313B` border. When checked, fills with `#00D5A0` using a `#080D12` checkmark icon.
- Segmented tab bars sit within a continuous `#0D141B` container, using floating `#151F28` pills for active selections.
