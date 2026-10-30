# ADV-03E — Canonical Base Designer Visual Composition

**Date:** 30 October 2026  
**Scope:** Automations consumer-owned sidebar markup/CSS only.  
**Base:** accepted current-Foundation CN-2 `5a68ef83ec61ce0ba70aa1ff7288d67ef389b665`; **no Base changes**.  
**Prior owner FIELD:** ADV-03D zoom, minimap, node editor and minimap positioning stable.

## Root cause
Automations applied `padding: var(--cb-space-3)` to the palette and Inspector
**roots** and placed its domain content directly under those roots. Base's
canonical `designer-composition.css` instead owns root `padding:0`, the
injected panel header, the scrollable `__panel`, and exactly one
`__panel-body` inset. Root padding displaced the headers and sidebar rhythm.

## Fix
Both sidebars now use the existing Base `__panel`, `__panel-body` and
`__panel-section` slots. Base still injects the same collapse headers,
owns responsive drawers, outer geometry, borders, visual tokens and scrolling.
Automations keeps domain-specific nodes, Inspector and guarded Test Workflow
controls inside those slots. No unnecessary Mail-like tabs are introduced.
No JS selector, domain state, Save, History, minimap or provider code changes.

## Acceptance
- DOM structural contract tests: exactly one canonical body per sidebar,
  both bodies inside their respective panel, no root padding/duplicate headers.
- PHP/JS regressions, six-locale i18n, Base v1 fixture contract, native test
  ZIP, ZIP integrity and SHA256 via `bash tools/adv03-functional-ready`.
- FIELD visual: both sidebar headers flush with rails; equal header height;
  content padding starts below header; scroll within panels; collapse and
  responsive overlays behave identically to Base Mail Designer.
- FIELD functional smoke: selected node settings, name field, create node,
  safe Test Workflow, Save/Reopen, Undo/Redo, zoom/minimap unchanged.

**No merge, deployment, Golden or public release without explicit owner GO.**
