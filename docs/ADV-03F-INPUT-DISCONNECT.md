# ADV-03F — Connected Input Disconnect & Rewire

**Date:** 30 October 2026  
**Repo:** `Core-Blueprint/wp-core-blueprint-automations`  
**Accepted parent:** `beb65c883eb52dfcdc041f4f75eee06161f55cfb` (ADV-03E FIELD stable).  
**Base:** paired CN-2 `5a68ef83ec61ce0ba70aa1ff7288d67ef389b665` (no new API or Base patch).

## Purpose
The Advanced Node Editor already supports output-to-input wiring, output
rewiring, click/keyboard edge selection and edge deletion via Inspector or
Delete/Backspace. ADV-03F completes native endpoint gestures by letting an
**occupied input port** detach its wire.

## Interaction contract
- Single click on a connected input selects its node; no graph mutation.
- Primary pointer drag on a connected input previews its source wire following
  the cursor (6px movement threshold); the original graph remains unchanged.
- Drop on **blank canvas**: remove exactly the captured edge, one Base History
  mutation; Undo recreates the original edge.
- Drop on a **free compatible input**: reuse existing `canConnect` / `connect`;
  rewire with unchanged edge ID and one Base History mutation.
- Drop on the original input, occupied/incompatible input, node body, toolbar,
  sidebar or outside the viewport: preserve the original edge.
- Escape, pointercancel, lost pointer capture: restore the original visual wire
  without mutation. The original edge ID + endpoints are rechecked before any
  final mutation.
- Keyboard parity remains the existing focusable connection SVG path with
  Delete/Backspace and Inspector's Delete connection control.
- SVG transient projection uses the same Base `clientToCanvas` coordinates,
  with no second pan/zoom engine. Existing output gesture is unchanged.

## Scope / constraints
Consumer-only interaction extension in `admin-advanced-editor.js` plus a
focused Node regression suite. No graph schema, persistence migration,
provider execution, Base code, Standard v1, public activation or release changes.

## Acceptance
1. Click connected input: no deletion and node selected.
2. Drag to empty canvas: wire follows pointer, disappears only on release.
3. Drag to free compatible input: same edge ID, new input, Undo restores old.
4. Drop occupied/incompatible, original port, node body, outside canvas:
   original edge remains; no new History entry.
5. Escape/pointercancel/lost capture: no mutation.
6. Pan + 50%, 100%, 200% zoom: preview and endpoint align.
7. Save/Reopen, Undo/Redo, Standard v1 and prior zoom/minimap functional smoke.
8. Canonical local gate `bash tools/adv03-functional-ready`: regressions,
   six locale catalogs, native ZIP and SHA-256 PASS before WordPress FIELD.

**Golden, merging, publishing, deployment and production Advanced provider
execution remain HOLD pending separate owner approval.**
