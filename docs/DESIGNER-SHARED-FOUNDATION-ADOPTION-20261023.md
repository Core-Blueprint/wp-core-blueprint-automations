# Automations | Shared Designer Foundation Adoption (SOURCE candidate)

**Date:** 2026-10-23  
**Owner:** Automations consumer  
**Start commit:** `Core-Blueprint/wp-core-blueprint-automations@e7f7392ea16f1daea410ce9026531dc042110afd`  
**Review reference:** `Core-Blueprint/wp-core-blueprint@5e9d59a2f7ffe384873a6c0650822bbb0e7eb557` (`main`, verified 2026-10-23 after shared Designer merge).  
**Decision:** Targeted SOURCE adoption requested by Overseer. SOURCE, browser, installed and Golden gates are separate. No merge/release authority.

## Public imports and boundaries

- `@cb-core/design-editor`: `createDesignerShell`, `configureDesignerSidebar`, `createDesignerNavigation`, `createDesignerLayerTree`, `createDomainSession`, `animateLayoutChange`.
- `@cb-core/modal`: `show` for explicit unsaved Save/Discard and close confirmation.
- `@cb-core/toast`: shared, Base-owned temporary operational feedback. Save uses `cb:design-shell:savechange` only, with success **after** verified server receipt and `rebase()`. `SAVE_UNVERIFIED` reports `state: 'unknown'` and `uncertain: true`; primary and alias Save controls remain disabled for the session and must not retry.
- Base owns the canonical left Elements/Layers roles, icons, tree row/disclosure/keyboard/drag presentation and shared context menu, right Inspector/Settings, responsive shell, modal, Toast, and **Exit Guard** (clean/dirty close, Escape, beforeunload).
- Automations owns the actual four workflow stages, typed steps/conditions/actions, authoring form, validation, per-record authorization, execution-principal rules, SQL revision CAS, authoritative persisted receipt and privacy-redacted editor data.
- No direct import from Base `assets/js/design/*`; no second workflow model, renderer, History engine, persistence adapter or legacy fallback.
- `createDomainSession()` is created once in `admin-builder-history.js`; the new Layers module only reads the existing editor snapshot, never opens a second session.
- Layer selection is a view-only intent bridged to the existing Inspector/card selection. Move/remove resolve canonical immutable step IDs through `cbAutomationsEditorSession.layerAction` and invoke the existing editor `renderAll()`/workflow command stream. Invalid IDs, busy saves and unsupported root-trigger operations fail closed.
- The public `window.cbCore.designerExitGuard.register()` receives Automations' domain dirty predicate, verified Save adapter and UNKNOWN predicate. The consumer no longer intercepts the Base close button, Escape or `beforeunload` with a second guard.
- Native Base Host profile admission is restricted to `base/mail` and `base/reports`; Automations **does not register** a private native profile.

## Known public-API gaps / deliberate holds

1. **Shared context menu and Drag & Drop:** Base `main@5e9d59a2...` now includes the public `createDesignerLayerTree()` context-menu and sibling reorder interactions. Automations supplies domain callbacks only; no duplicate context-menu or drag controller is allowed.
2. **Native selection controller for Domain Session:** `createDesignerSelectionController()` requires `session.select()`, `selection()`, `subscribeSelection()` and `inspector()`. The accepted `createDomainSession()` does not expose that interface, so the consumer retains transient selection projection in the one Inspector. No fake native session-selection methods.
3. **Dirty workflow context switching (contract gap):** Base's shared `canTransition(shell)` intentionally allows context changes only when the registered domain is clean and the Save outcome is verified. This means the existing Automations dirty-switch choice cannot be reached via the shared context selector. The operator must Save before switching. Do not bypass Base's capture guard; a shared, explicit Save/Discard context-transition API needs Base ownership and separate review if this UX is restored.
4. **Exit and UNKNOWN safety:** The Base Exit Guard owns close, Escape, confirmation and unload, including UNKNOWN blocking. Save errors with uncertain post-write persistence are terminal for this editor session (no retry); re-open after independent verification. Automations only provides the domain dirty/verified-Save adapter.
5. **Base installation pin:** This SOURCE review targets `main@5e9d59a2...`. Exact installed asset parity and browser smoke remain independent gates. Later Base polishes may require another smoke, not duplicate architecture.

## Acceptance focus

- SOURCE: real public named exports, Elements/Layers Base tabs, no extra history; panel overflow/focus, Layers selection/Inspector sync, reorder/removal/Undo/Redo, action ID guards, no secrets in labels.
- SOURCE: Save receipt stays server authoritative, no premature `saved`, one Base Toast for verified Save/error, no transient toolbar messages, preserve persistent workflow health and conflict recovery action.
- WordPress field smoke: open and edit an existing workflow; select a Layer and inspect it; move/remove permitted steps then Undo/Redo; Save → Reopen; second-tab conflict and uncertain Save; late context switch; fullscreen/mobile keyboard focus; Base Toast one-per-outcome and no UI duplicates.
- Releasebuild, merges, Actions/CI, deployment and publication remain HOLD without independent user GO.

**Not a publication authority; dirty context switching awaits a shared Base transition contract, and Base/Automations browser acceptance remains open.**
