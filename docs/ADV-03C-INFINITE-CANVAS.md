# ADV-03C — Infinite Virtual Canvas

**Date:** 29 October 2026  
**Repository:** `Core-Blueprint/wp-core-blueprint-automations`  
**Base API pin:** `2755fd4ef0a54c76b8ed07caf1cbe341eeb393cd`

## Root cause and design
Automations previously restricted every node to a 1400 x 900 fixed rectangle.
The SVG and dot grid were also physical 1400 x 900 elements. Base Canvas
Navigation already permits unbounded pan and exposes `onViewChange`, `getView`,
`clientToCanvas`, `fitToScreen` and `centerOnContent`. No new Base API is needed.

The view is now virtual:
- The **viewport** paints a repeating grid using Base's public pan, scroll
  offset and scale snapshot, so dots never end when panning.
- The **scene** is a standard transform root without a hardcoded world width
  or height. The Base controller continues to own all presentation transforms.
- Graph nodes use **signed** coordinates and a generous browser-safe guard
  of +/- 1,000,000 pixels; the existing GraphDefinitionCodec already permits
  signed numeric positions up to +/- 100,000,000. The stored document schema
  and Server graph semantics remain unchanged.
- Connections use an SVG dynamically positioned to include node extents
  and the active connection-drag cursor, with a margin for Bezier curves.
  SVG paths retain global graph coordinates, so pan/scale, hit targets,
  Undo/Redo and Save/Reload do not need coordinate translation hacks.
- Nodes inserted from the palette appear at the current viewport center;
  contextual insertion uses Base `clientToCanvas` and can be at negative X/Y.
- `getContentBounds` measures only the real node positions. Fit to Screen
  therefore works even when content spans negative and positive coordinates.

The grid is presentation-only and does **not** enter History or serialized
workflow layout. Existing Standard mode and Base shells are untouched.

## One combined FIELD acceptance (no micro-patch installations)
- Pan in each direction well beyond the old 1400x900 visible dot boundary.
- Add and drag nodes at negative X/Y and far-positive locations.
- Connect distant nodes; verify paths, hit targets and port drag alignment.
- Add a node from the left palette after panning far from origin: it must
  appear in the visible region.
- Use Fit to Screen, Undo/Redo, Save and reopen, confirming positions.
- Verify no stale grid background, abrupt edge clipping or broken Inspector.
- Run the existing Safe Test Workflow and Standard v1 smoke unchanged.

**No merge, deployment, public release or production v2 activation** is
implied by this SOURCE patch. The feature remains FIELD-HOLD until the
combined sandbox test is accepted.
