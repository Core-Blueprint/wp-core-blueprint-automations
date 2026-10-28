# ADV-03C Advanced Editor native history and viewport polish

**Date:** 28 October 2026 · **SOURCE branch:** `feature/automations-advanced-ux-test-batch-20261028` · **Base pinned:** `2755fd4ef0a54c76b8ed07caf1cbe341eeb393cd`

## History — public Base API only

Advanced Composer now uses the published `createDomainSession` API from `@cb-core/design-editor`, gated by the same installed Base build via PHP `Assets::DOMAIN_SESSION_API >= 3`.

- Session-only DesignProject generation 0 wraps the consumer v2 definition + workflow name **in memory** for History snapshots. The canonical saved payload is **still only** `GraphDefinition` v2, submitted through the existing protected native WordPress Save/CAS POST. No second persistence format or duplicate History engine.
- Each authoring change is committed through Base `session.execute`; only Base `session.undo()`, `session.redo()`, `session.subscribeHistory()` and `session.historyStatus()` control the stack. History is disposed on pagehide.
- Undo/Redo restores graph and name, invalidates stale route-preview UI, reconciles selected nodes/connections, updates the hidden saved-document field and recomputes the dirty/Exit Guard flag against the original authoritative page baseline.
- Keyboard shortcuts Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z and Ctrl+Y work in the Editor but **never steal native undo from text inputs, selects or textareas**.
- Node/edge selection remains a transient consumer presentation choice and is not a second History/Selection engine.
- The existing Standard Mode Domain Session is untouched.

## Viewport boundary

Base CN-1 remains the sole owner of viewport transforms and fit/pan. Fullscreen Chrome may hide visible browser scrollbars without disabling native scroll events, wheel, trackpad or keyboard fallback. **Manual arbitrary zoom is NOT implemented:** the pinned public Base controller has `panBy`, `fitToScreen`, `centerOnContent`, `clientToCanvas`, `canvasToClient`, `getView`, `destroy` but no authorized zoom/setScale API. Do not introduce a private or parallel controller just to add +/− controls. This remains an optional future contract request, not an ADV-03 batch release blocker.

## Read-only Test Workflow boundary

ADV-03B currently offers **saved-route simulation**, visible route highlights and skipped-node summaries, never real provider calls or side effects. A full controlled provider test executor / per-step real outcome ledger remains OPEN and requires independent security/runtime authority review. The popup/toolbar may not call it a real executed run.

## Verification

Canonical PHP/JS regression and build, six languages, ZIP hash, paired Base/Automations WordPress FIELD and owner acceptance are required before marking the batch ready for merge or release. GitHub CI results must be tied to the exact head. The SOURCE branch remains DRAFT.
