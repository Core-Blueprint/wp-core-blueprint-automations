# ADV-03D — Zoom + Minimap (paired Base CN-2)

**Date:** 29 October 2026  
**Automations branch:** `feature/automations-adv03d-zoom-minimap-20261029`  
**Base CN-2 branch:** `feature/base-cn2-zoom-contract-20261029` (PR #143)  
**Base API floor:** `Assets::CANVAS_NAVIGATION_API >= 2`

## Single-owner architecture

Base Canvas Navigation remains the sole owner of graph presentation
transforms; its additive public `zoomAt({scale,clientX?,clientY?})` and
`zoomBy({factor,clientX?,clientY?})` preserve the graph coordinate under
the pointer. Explicit zoom is bounded 10–400%. Existing Fit may scale lower
for extremely large content. There is no second zoom engine.

Automations owns graph-aware minimap **projection only**, rendering read-only
nodes/edges and the currently visible world viewport. Click on the map
recenters via the public Base `panBy` method, and dragging the viewport
rectangle pans with a frozen projection (no jitter). Minimap collapse/size
preferences live only in sessionStorage. No graph mutation, Save, History,
new action execution or provider side effects.

- Toolbar: Zoom out / live percentage (reset to 100%) / Zoom in / Fit.
- Ctrl/Cmd+wheel and trackpad pinch inside the canvas zoom around cursor.
- Ordinary wheel pans. Outside the canvas, browser zoom remains native.
- Minimap: collapsible, resizable, keyboard arrow navigation, hidden body on
  narrow screens by default. Map redraw follows public Base view changes
  and consumer graph changes.
- All graph paths, node positions, typed ports and saved layout use the
  same original signed world coordinates.

## Consolidated acceptance

This is a **paired-build integration**. A WordPress site with the earlier
Base CN-1 ZIP must fail closed rather than load an incomplete ESM export.

After both PRs pass canonical independent and paired Level 1 checks:
1. Build and install matching **Base CN-2 development ZIP** on sandbox.
2. Build and install the **Automations ADV-03D test ZIP**.
3. In one FIELD round: zoom toolbar 10–400%; Ctrl/Cmd+wheel anchor accuracy;
   wheel/trackpad pan, browser zoom outside workspace; Fit; minimap map click,
   viewport drag, collapse, expand, large/normal; negative/distant nodes,
   long connections and typed-port drag alignment; Save/Reload and Undo/Redo
   preserve graph coordinates; safe Test Workflow and Standard v1 smoke.
4. Collect all blockers; correct them together before functional acceptance.

No production Advanced v2 execution, Base public merge, Automations merge,
deployment or public release without separate explicit approval.
