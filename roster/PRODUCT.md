# Product

## Register

product

## Users

LiveWright internal staff, program admins, and coaches. Three roles: **viewer** (read-only), **editor** (can update fields, assign, drop, email), **admin** (adds user management, field organization, bulk-assign tools). They work at a desk on a laptop or external monitor during business hours, in normal office light. The typical session is short and purposeful: find a participant, check or change their Team / Coach / Quarter, drop someone, send a bulk email, or read a campaign report. Nobody lives in this tool all day, but during a working session they scan and edit a roster of a few hundred coaching-program participants and need to move fast without second-guessing what they clicked.

## Product Purpose

An internal admin console for LiveWright's assessment + Personal Development Plan (PDP) coaching program. It is the operational surface staff use to manage program participants: view the roster, search and filter by team/cohort/coach/quarter, edit names and assignments, bulk-assign Team and Quarter, drop contacts, send bulk email, and review campaign reports. It reads and writes to Keap (Infusionsoft) as the system of record. Success is a staff member completing a management task correctly and quickly, with total confidence about who they affected. The design serves the work; it is not a brand showcase.

## Brand Personality

Precise, calm, trustworthy. The voice is plain and operational, never cute. Three words: **composed, legible, dependable.** The interface should feel like a well-kept workbench: everything where you expect it, nothing shouting, the accent color reserved for the thing you're meant to act on. Emotional goal is quiet confidence, the opposite of anxiety, because staff are changing real records in a live CRM.

## Anti-references

- The **Flat-UI-2013 palette it currently uses** (`#2c3e50` slate + `#3498db` bright blue + `#27ae60` green, uppercase table headers, flat 4px cards). This is the exact "admin tool" training-data reflex and the thing we are moving away from.
- Generic Bootstrap-admin dashboards: gradient hero cards, big-number stat tiles, icon-in-a-rounded-square grids.
- Consumer-wellness softness: pastel gradients, rounded blobby shapes, oversized friendly illustrations. This is an operator tool, not a meditation app.
- Dark "developer console" cosplay. The scene is a lit office at 10am, not a war room at 2am.

## Design Principles

1. **The accent points at the action.** One reserved accent color, used almost only for interactive and active states (the control you should touch, the filter that's on, the row you selected). Chrome and data stay neutral so the accent always means something.
2. **Density with air.** Show a lot of roster at once, but give rows and controls enough rhythm that scanning never blurs. Vary spacing; don't pad everything identically.
3. **Confidence before action.** Anything that writes to Keap (assign, drop, bulk email) states plainly who and how many are affected before it commits. No ambiguous bulk actions.
4. **Restyle, never re-wire.** The filter/sort/modal/bulk JS is load-bearing and correct. Visual system changes; behavior and the `$quarterEnabled` guards stay intact.
5. **Legible over decorative.** Every color, weight, and border earns its place by aiding scanning or signaling state. If it's only decoration, it's cut.

## Accessibility & Inclusion

- Light theme, tuned for daytime office light. WCAG AA contrast for body text, controls, and state indicators.
- Visible, non-color-only focus states for full keyboard operation (the roster is heavily keyboard-and-mouse driven).
- Never rely on color alone to convey state: pair the selected-row tint with a checkbox, badges carry text labels, active filters show a count/marker.
- Respect `prefers-reduced-motion`: transitions are subtle and optional, no motion required to understand state.
