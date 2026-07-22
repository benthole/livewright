# Design

Visual system for the LiveWright Roster admin console. Register: **product** (design serves the tool). Direction: **calm modern workbench** — quiet light chrome, one reserved accent, dense-but-breathable data. Self-contained CSS (no CDN framework; Quill stays for the email editor). All colors OKLCH.

## Visual Theme

Light, daytime-office theme. A warm off-white page with a slightly lighter "paper" work surface, warm-graphite ink, hairline borders, and near-flat elevation. Chrome is quiet: the header and table frame recede so the data reads first. A single deep petrol-teal accent is reserved almost entirely for interactive and active states, so accent always means "act here / this is on." No dark mode. Deliberately NOT the slate + bright-blue Flat-UI look it replaces.

## Color

Strategy: **Restrained** — tinted warm neutrals carry the surface; one accent stays ≤~10% of the pixels, concentrated on controls and state. Neutrals are tinted toward warm hue ~80; the accent sits at hue ~205. Never `#000`/`#fff`.

Define as CSS custom properties on `:root`.

```
/* Canvas & surface */
--bg:            oklch(0.975 0.004 85);   /* page background */
--surface:       oklch(0.995 0.003 85);   /* container / cards / header bar */
--surface-sunk:  oklch(0.965 0.004 85);   /* zebra / inset wells / toolbar */

/* Ink */
--ink:           oklch(0.29 0.008 75);    /* primary text, headings */
--ink-soft:      oklch(0.46 0.007 75);    /* secondary text, header cells */
--ink-faint:     oklch(0.60 0.006 75);    /* placeholder, meta, disabled */

/* Lines */
--line:          oklch(0.915 0.004 80);   /* hairline dividers, borders */
--line-strong:   oklch(0.86 0.005 80);    /* input borders, emphasis rule */

/* Accent — deep petrol teal (the reserved "act here" color) */
--accent:        oklch(0.52 0.078 205);
--accent-hover:  oklch(0.46 0.082 205);
--accent-weak:   oklch(0.955 0.018 205);  /* selected-row tint, active-filter bg */
--accent-ink:    oklch(0.40 0.085 205);   /* accent text on light */
--focus:         oklch(0.62 0.10 205);    /* focus ring */

/* Semantic tags (harmonized L/C, hue-differentiated; always paired with a text label) */
--tag-individual-bg: oklch(0.95 0.02 205);  --tag-individual-ink: oklch(0.42 0.08 205);
--tag-group-bg:      oklch(0.95 0.03 155);  --tag-group-ink:      oklch(0.42 0.08 155);
--tag-eteam-bg:      oklch(0.95 0.03 300);  --tag-eteam-ink:      oklch(0.44 0.10 300);
--tag-neutral-bg:    oklch(0.93 0.004 80);  --tag-neutral-ink:    var(--ink);

/* Feedback */
--ok:      oklch(0.55 0.09 150);
--warn-bg: oklch(0.95 0.05 85);   --warn-ink: oklch(0.42 0.06 75);
--danger:  oklch(0.53 0.15 27);   --danger-hover: oklch(0.47 0.16 27);
```

State usage: default controls are neutral (ink text, `--line` border, surface bg). Hover deepens neutral. The **active/selected** state is the only place `--accent`/`--accent-weak` appears in the data area (selected row, on filter, current nav item, primary button). Dropped-roster view is signaled by a labeled amber state chip in the header, NOT by recoloring the whole bar.

## Typography

System stack (fast, native, no dependency): `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`. Tabular figures for counts/IDs: `font-variant-numeric: tabular-nums`.

Scale (ratio ≥1.25):
- Page title (wordmark): 20px / 600 / -0.01em, `--ink`.
- Section & modal heading: 16px / 600.
- Body & cells: 14px / 400–500, line-height 1.45.
- Table header cells: 11.5px / 600, uppercase, letter-spacing 0.04em, `--ink-soft` (quiet, not white-on-slate).
- Meta / counts / role badge: 12px / 500, `--ink-faint` or tag ink.

Body line length in modals capped ~65ch.

## Layout & Spacing

- Base 4px scale: 4, 8, 12, 16, 20, 24, 32.
- Container: `max-width: 1440px`, centered, `--surface`, `--r-lg` radius, 1px `--line` border + `--shadow-sm`. It frames, it doesn't shout.
- Header bar: light `--surface`, 14px/24px padding, `--line` bottom border. Three zones: identity + search (left), record count + refresh (center-right), nav + user (right). Wraps gracefully under ~1100px.
- Table: rows 11px/16px padding (denser than the old 15px), zebra with `--surface-sunk` on even rows, hairline `--line` row dividers. Header row sticky-feeling via `--surface-sunk` bg + bottom `--line-strong`. Horizontal scroll wrapper preserved.
- Cards are avoided; the roster is a table, tools are forms. No stat-tile grids, no nested cards.

Radii: `--r-sm: 6px` (badges, inputs, buttons), `--r-md: 8px` (menus, modals, container inner), `--r-lg: 10px` (outer container).

## Elevation

Near-flat. `--shadow-sm: 0 1px 2px rgb(from var(--ink) r g b / 0.06)` for the container and resting controls. `--shadow-md: 0 8px 24px rgb(from var(--ink) r g b / 0.14)` for popovers, dropdowns, and modals only. No decorative glass, no glow.

## Components

- **Nav "Tools" dropdown** (admin): a neutral button `Tools ▾` opening a `--shadow-md` menu of Assign Teams / Assign Quarters / Organize Fields / Manage Users. Same menu pattern reused on the two bulk-assign tool pages so they share the roster's top nav (with the current page marked active in `--accent`).
- **Buttons**: primary = `--accent` bg / surface text; secondary = `--surface` bg / `--line` border / ink text; danger = `--danger`. 8px/16px padding, `--r-sm`, 140ms ease-out hover.
- **Inputs / search**: `--surface` bg, `--line-strong` border, `--r-sm`; focus = `--accent` border + 3px `--focus`-at-low-alpha ring.
- **Badges/pills**: role badge (viewer/editor/admin), coach tags (individual/group), E-Team, CARE — all text-labeled, tag tokens above, `--r-sm`, 2px/8px.
- **Filter & bulk-action menus**: keep existing markup/JS; restyle to `--surface` + `--line` + `--shadow-md`; active filter column shows an accent dot + count.
- **Modals**: `--surface`, `--r-md`, `--shadow-md`, 28px padding; footer right-aligned primary/secondary. Overlay `rgb(from var(--ink) r g b / 0.45)`.

## Motion

Transitions 120–160ms, ease-out-quint `cubic-bezier(0.22, 1, 0.36, 1)`. Animate color/background/opacity/box-shadow only, never layout. Refresh spinner keeps its rotate. `@media (prefers-reduced-motion: reduce)` drops transitions/animation to near-zero.

## Accessibility

WCAG AA: `--ink` on `--surface` ≈ 11:1; `--ink-soft` on `--surface` ≈ 6:1; accent text and primary-button contrast verified ≥4.5:1. Focus visible on every interactive element (accent ring, not just border). State never color-only: selected row = tint + checked box; active filter = dot + count; dropped view = labeled chip. Hit targets ≥ 32px. `prefers-reduced-motion` honored.
