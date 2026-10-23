# Automations | Shared Designer Foundation Adoption (SOURCE candidate)

**Date:** 2026-10-23  
**Owner:** Automations consumer  
**Start commit:** `Core-Blueprint/wp-core-blueprint-automations@e7f7392ea16f1daea410ce9026531dc042110afd`  
**Review reference:** `Core-Blueprint/wp-core-blueprint@d7b251fa0fe8df3e5b53dc1e9f1b866df508905e` (`main` observed during implementation).  
**Decision:** Targeted SOURCE adoption requested by Overseer. SOURCE, browser, installed and Golden gates are separate. No merge/release authority.

## Public imports and boundaries

- `@cb-core/design-editor`: `createDesignerShell`, `configureDesignerSidebar`, `createDesignerNavigation`, `createDesignerLayerTree`, `createDomainSession`, `animateLayoutChange`.
- `@cb-core/modal`: `show` for explicit unsaved Save/Discard and close confirmation.
- `@cb-core/toast`: shared, Base-owned temporary operational feedback. Save uses `cb:design-shell:savechange` only, with success **after** verified server receipt and `rebase()`.
- Base owns the canonical left Elements/Layers roles, icons, tree row/disclosure/keyboard/drag presentation, right Inspector/Settings, responsive shell, modal and Toast.
- Automations owns the actual four workflow stages, typed steps/conditions/actions, authoring form, validation, per-record authorization, execution-principal rules, SQL revision CAS, authoritative persisted receipt and privacy-redacted editor data.
- No direct import from Base `assets/js/design/*`; no second workflow model, renderer, History engine, persistence adapter or legacy fallback.
- `createDomainSession()` is created once in `admin-builder-history.js`; the new Layers module only reads the existing editor snapshot, never opens a second session.
- Layer selection is a view-only intent bridged to the existing Inspector/card selection. Move/remove resolve canonical immutable step IDs through `cbAutomationsEditorSession.layerAction` and invoke the existing editor `renderAll()`/workflow command stream. Invalid IDs, busy saves and unsupported root-trigger operations fail closed.
- Native Base Host profile admission is restricted to `base/mail` and `base/reports`; Automations **does not register** a private native profile.

## Known public-API gaps / deliberate holds

1. **Layers context menu:** Base public `main@d7b251fa...` exports `createDesignerLayerTree` with accessible nested rows, keyboard navigation, drag and move/remove actions, but it does **not** contain a published `contextmenu` handler. A richer implementation was observed only on Base's separate `feature/designer-shared-layers-primitives-20261023` branch. Do not copy/import it from private source. Context-menu equivalence requires a published public Base contract before acceptance.
2. **Native selection controller for Domain Session:** `createDesignerSelectionController()` requires `session.select()`, `selection()`, `subscribeSelection()` and `inspector()`. The accepted `createDomainSession()` does not expose that interface, so this consumer retains transient selection projection in the existing Inspector, shared with Layers by events. No duplicate persisted selection model. Do not fake native session-selection methods.
3. **Base final pin:** this source candidate was checked against `main@d7b251fa...`; later Base Golden / Designer changes require exact installed-asset pairing and a targeted smoke test. Never equate a source export with installed runtime parity.

## Acceptance focus

- SOURCE: real public named exports, Elements/Layers Base tabs, no extra history; panel overflow/focus, Layers selection/Inspector sync, reorder/removal/Undo/Redo, action ID guards, no secrets in labels.
- SOURCE: Save receipt stays server authoritative, no premature `saved`, one Base Toast for verified Save/error, no transient toolbar messages, preserve persistent workflow health and conflict recovery action.
- WordPress field smoke: open and edit an existing workflow; select a Layer and inspect it; move/remove permitted steps then Undo/Redo; Save → Reopen; second-tab conflict and uncertain Save; late context switch; fullscreen/mobile keyboard focus; Base Toast one-per-outcome and no UI duplicates.
- Releasebuild, merges, Actions/CI, deployment and publication remain HOLD without independent user GO.

**Not a publication authority, not a claim that Base already supports private Automations native profile registration.**
