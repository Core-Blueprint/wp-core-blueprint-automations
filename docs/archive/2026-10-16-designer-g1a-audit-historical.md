> **ARCHIVED HISTORICAL EVIDENCE — NOT CURRENT IMPLEMENTATION AUTHORITY**
>
> Preserved verbatim from `docs/DESIGNER-G1A-SOURCE-AUDIT-BASE-CONTRACT-REQUEST-20261016.md` at exact source branch commit `054047ed7555116cbe81937a3ed124f8ddaaf7c4` during the 2026-10-19 Automations branch reconciliation. The historical status, missing-API claims, proposed changes, permissions and old SHAs below describe the *2026-10-15/16 state only*. They are superseded by the current `main` (pre-archive runtime source `baf2e82d4ee61f1f835eedb8aba3d609accfce43`), subsequent Base G1a v2.1 consumer work and the current Designer Consumer Standard v1. **Do not replay commands or port old source from this record.** No legacy compatibility code is authorized.

---

# Automations Designer G1a | Exact-source reconciliation and BASE CONTRACT REQUEST

**Date:** 2026-10-16  
**Status:** SOURCE AUDIT / CONTRACT REQUEST / BLOCKED BY BASE. Not Golden.  
**Repository/write authority:** Core-Blueprint/wp-core-blueprint-automations only.  
**Approval boundary:** No consumer integration, merge, CI, database write, release or deployment authorized by this report.  
**Evidence method:** GitHub connected read-only branch comparisons and exact-ref source reads. A dedicated documentation-only branch was created for this report. This environment has no mounted operator checkout and outbound git networking was unavailable, so **no local PHP, JS, PHPUnit, i18n, package, WordPress runtime or browser tests were executed**.

## 1. Re-pinned source authority

| Repository / branch | Full SHA | Meaning |
| --- | --- | --- |
| Automations main | e0049f672c569c3759fa07d826a6c5223e8d5244 | common ancestor |
| Automations feature/automations-workspace-import-export-golden-v1 | ce9c6d08e7d7ac47d406c9b6c446aca87adc5d2a | +38 vs main; ancestor of Folders |
| Automations feature/automations-workflow-folders-w31-v1 | f4f7e23c61ca2b9448cfd03615b5dc66b8b1106d | +48 vs main; +10 after Workspace |
| Automations feature/automations-template-library-t3b-t5-pilot-v1 | d6a0855bcacee413616eb07e83bee6be1764f30f | three unique template commits vs main |
| Automations feature/automations-template-category-provider-20261015 | ccc76ea0a82666f1da585465d5215e779f4aa689 | four unique template commits vs main; one after T3B/T5 |
| Base main | 00975654517f657ca95be7a0dc0d901e95cb764c | observed reference engine; G1a not approved |
| Base feature/base-template-library-navigation-categories-20261015 | 000e5939d1ebed7a363f5a93b0fe0bd7d673c99c | divergent category contract candidate; not Base main |

GitHub compare status: Workspace -> Folders is ahead +10, behind 0; Templates -> Folders is diverged, Folders ahead 48, behind 4, common merge-base Automations main. Branch names are not runtime guarantees.

## 2. Unique-delta and overlap ledger

**Workspace -> Folders (+10 commits, 19 files changed):**
- Persistence/schema: new src/Organisation/FolderRepository.php establishes two InnoDB tables for folders and assignments; src/Persistence/WorkflowRepository.php adds folder-aware queries, src/Admin/WorkflowAdminReadService.php wires folder reads. Folder assignment is separate from workflow revision and activation.
- Authorization/actions: new src/Admin/WorkflowFolderController.php guards create, rename, delete and assign with Automations capability and operation nonce. Folder deletion unfiles workflows; does not delete them. Verify under real DB/concurrency and rollback.
- UI/routes: src/Admin/AutomationsPage.php, templates/admin/automations.php, assets/css/admin-overview.css, src/Plugin.php. Metadata: core-blueprint-automations.php.
- i18n: six locale PO catalogs plus POT. Tests: workflow-folders-query.php and workflow-folders-w31.php. Documentation: WORKFLOW-FOLDERS-W31.md.

**Template T3B/T5 + category (+4 commits, 25 files changed):**
- Domain storage, privacy and scope: new src/Template/StoredTemplateRepository.php (private, REST-inert CPT; personal/site scope, actor filtering, pagination); src/Template/TemplateSafety.php (strips literal bindings and literal conditions); src/Template/TemplateSourceAdapter.php (Base metadata source, category facets, actor checks); src/Template/TemplateLibraryBridge.php.
- Domain actions/UI: new src/Admin/TemplateManagementController.php and TemplateManagementPage.php; assets/js/template-library-bridge.js, src/Admin/AdminAssets.php, src/Admin/WorkflowController.php, src/Admin/AutomationsPage.php, templates/admin/automations.php and workflow-editor.php. Browser selection rechecks identity/authority server side.
- Runtime exclusion: src/Provider/WordPress/TriggerListeners.php excludes internal template post events; must survive.
- Locale: six PO and POT files. New tests: tests/js/template-library-bridge.test.mjs, tests/unit/template-library-t3b-contract.php; updated tests/unit/wordpress-provider-contract.php.
- Last category-only commit changes TemplateSourceAdapter.php, the template pilot documentation and template-library-t3b-contract.php; it adds optional per-actor categories and **requires a Base interface currently absent from main**.

**Exact 13-file overlap between main...Folders and main...Templates:**
1. src/Admin/AdminAssets.php
2. src/Admin/AutomationsPage.php
3. src/Admin/WorkflowController.php
4. src/Plugin.php
5. templates/admin/automations.php
6. templates/admin/workflow-editor.php
7-13. languages/core-blueprint-automations-{de_DE,es_ES,fr_FR,it_IT,nl_NL,pt_PT}.po and languages/core-blueprint-automations.pot

All six PHP/template overlap points need manual semantic reconciliation, not wholesale file replacement or an unreviewed cherry-pick. Reconcile translation source messages and all six real translations after the code changes, rather than choosing either branch's PO content wholesale.

**Source-to-source conflict risk:** Folders owns Workspace, workflow navigation, context switch and import/export; Templates adds administrative workflows, new private CPT, create-from-template and browser interactions. Both alter admin listing, workflow editing and plugin bootstrap. Preserve Folders-specific data/query changes and Templates-specific capability checks, provenance, source registration and exclusion of internal template events.

## 3. Source-level Designer audit

**P0-BLOCK-01: ESM export mismatch, confirmed at exact refs.**
- Folders assets/js/admin-builder-history.js line 1 imports createDesignerStateHistory from @cb-core/design-editor; pinned Base assets/js/design/editor.js exports createDomainSession, not createDesignerStateHistory.
- Folders src/Admin/DesignerAssets.php enqueues the history module on workflow editor routes. This is a hard ESM module-resolution incompatibility by source. An installed-browser exception has NOT been separately reproduced.
- Folders assets/js/admin-designer-shell.js switchContext() requires window.cbAutomationsBuilderHistory.resetForContext(), so a history module initialization failure also blocks no-reload workflow switching. tests/js/designer-golden-contract.test.mjs only matches the import name, not its actual export.
- Do NOT copy historical Base createDesignerStateHistory or build a consumer history clone.

**P0-BLOCK-02: Missing Base PHP interface, confirmed at exact refs.**
- Template branch src/Template/TemplateSourceAdapter.php declares implements TemplateCategorySourceInterface. The interface exists on Base's divergent category branch but not on pinned Base main.
- TemplateLibraryBridge::init() checks the management source interface, not category interface availability, before scheduling registration of TemplateSourceAdapter. Autoloading this adapter against Base main risks a fatal missing-interface error. PHP activation/runtime failure NOT reproduced here.
- Fail closed on incompatible pairs; coordinate with Base to add one accepted interface. No optional historical fallback or surrogate type.

**P0-BLOCK-03: Domain Project mapping is NOT drop-in.**
- Automations admin-editor.js keeps mutable workflow state with trigger, states, conditions and actions, publishes a definitionchange event and exposes snapshot/replace through window.cbAutomationsEditorSession.
- Base createDomainSession() uses ProjectState, which requires exactly a DesignProject envelope with schema_version integer, design_type string and root plain object; raw workflow definitions are not accepted.
- The consumer needs an explicit lossless mapping between the canonical saved workflow DefinitionCodec payload and the Base project.root, without persisting Base transient editor/session state, mutating step IDs or changing domain validation. Base must own the only command history; Automations should only own domain rendering/projection, schema validation, and persistence.

**History/save observation:**
- Folders history currently records definitionchange events only when source=editor. It has a 100-entry limit, undo/redo controls, animation and keyboard handling. Saving publishes cb:design-shell:savechange and resets the baseline; it tries to keep in-flight definition changes as dirty. The shell separately tracks name, activation and principal-rebind dirty state.
- Base createDomainSession() already exposes execute/undo/redo/historyStatus/clearHistory/dispose. Underlying CommandHistory clears redo after command; ProjectState.replace(non-command source) clears history. CommandHistory.execute expects apply(project) to return {project}. **Every command yields an entry**, so the consumer must define meaningful edit/typing boundaries and avoid no-op history entries.
- Current keyboard handler excludes text inputs, selects and contenteditable, but lacks an explicit open-modal, disabled-command, saving, or focus-within-other-interactive-control guard. Test keyboard behavior and reduced-motion, rather than claiming visual keyboard Golden.
- Current shell creates Base shell/navigation controllers and adds window/document/root listeners, but no explicit consumer teardown or controller.destroy() path was located in the reviewed entrypoints. Audit lifecycle after context switch and leave/unmount.
- Current Inspector uses the documented Base global shell.inspector helpers, but its availability gate may silently return false when unavailable. Missing required Base capabilities should be visibly fail-closed, not invisible degradation.
- Consumer modifies Base-style class selectors (palette, sidebar, panel heading) and uses data-cb-design-* selectors. Those need G1a explicit stable/public DOM and stylesheet ownership classification. No stale selector bug has been reproduced in a browser.
- Workflow save has an async POST and baseline/current-dirty reconciliation. The unparseable-success response path reloads the page deliberately; do not call all saving reload-free without covering that exception.

**Other domain source boundaries, not installed acceptance:**
- Import: WorkflowTransferController enforces capability+nonce, bounded JSON, encrypted user-bound preview transient, second confirmation, single-use claim, validation/PersistencePolicy and creation via WorkflowService. New workflow create stores activation_state=disabled and execution_principal_user_id=0. Export excludes execution identity/run data but allows explicitly configured non-sensitive literal text. Test no credential leak with both known and disappeared providers.
- Folders: nonce+capability operations, InnoDB transactional assignment/deletion; verify migrations, concurrent deletion/assignment, unknown IDs, index/pagination and workflow integrity under isolated WP DB.
- Stored templates: nonpublic private CPT; user-scoped metadata, personal creator restrictions, site scope and safe scrub on persistent stored-template path. Test hostile metadata, actor isolation, source spoofing and literal leakage, especially built-in provider template path.
- Preview: settings sidebar includes server-authoritative validation and status. Browser/DOM/visual parity and accessibility are not yet measured.
- No evidence was found of a second imported Base private class in the inspected key entrypoints. A complete all-files dependency scan remains for source gate.

## 4. BASE CONTRACT REQUEST | P0 history + categories + shell

**Recipients:** Overseer coordinating Luna and Base Designer G1a Luna.  
**Consumer reference:** Automations Folders f4f7e23c61ca2b9448cfd03615b5dc66b8b1106d; Templates ccc76ea0a82666f1da585465d5215e779f4aa689; Base reference 00975654517f657ca95be7a0dc0d901e95cb764c.

1. **One public domain history/session model:** prefer existing createDomainSession() if it meets below; do not restore the obsolete helper merely to satisfy an import. Document canonical DesignProject envelope and support explicit domain validation, denied commands with no mutation, complete immutable snapshots, source-labeled editor/history/context notifications, historyStatus, safe disposal and lifecycle. Decide whether atomic save-baseline reconciliation needs a narrowly defined public session API, or whether replace(saved baseline) followed by a single validated command(current in-flight definition) is the preferred tested consumer pattern. No duplicate state engine.
2. **History invariants/candidate tests:** grouping per user-intent (add/remove/reorder step, trigger, binding/condition change, bounded typing commit), immutable complete definition and stable IDs, rejected and invalid operations do not create history entries; no-op does not create an entry; new command clears redo; undo/redo updates renderer, validation and dirty flag; saving after an in-flight edit leaves newer edits unsaved and undoable; context switching discards/reset history for the previous workflow; unauthorized context load fails without partial mutation; leave/unmount disposes listeners; keyboard shortcuts avoid editable controls, open dialogs and interactive/disabled modes; animations respect prefers-reduced-motion and are not transaction authority.
3. **Executable API checks:** install/build the exact Base candidate; run browser-native import('@cb-core/design-editor') under WordPress's registered import map and assert named exports exist and execute a miniature DesignProject command/undo/redo lifecycle. Explicit negative assertion that a missing requested export fails the integration gate. Also verify registered module dependency resolution and cap/version gate DOMAIN_SESSION_API. No string-only tests as Golden proof.
4. **Template Library PHP contract:** publish exact TemplateCategorySourceInterface::categories(int $user_id): array<string,string> or a deliberate replacement, with bounded category cardinality, actor-local visibility, no content/count/identity leakage, and strict capability/scoped metadata checks; browser remains metadata-only, mutation is Automations-owned POST+nonce. Make class/interface loading and incompatible-pair behavior testable and fail-closed without maintaining two API generations.
5. **Public UI/asset contract:** confirm createDesignerShell/createDesignerNavigation/configureDesignerSidebar, inspector APIs, contextrequest synchronous respondWith and its rollback/completion/error semantics, savechange state, declarative data-cb-design-* roles, focus/close/fullscreen policy, reduced motion and the semantic stylesheet dependency contract. If internal class selectors such as .cb-core-design-shell__palette or __panel-header are implementation details, expose stable semantic query points and coordinate the consumer migration.

**Expected Base deliverable:** approved public G1a contract proposal with candidate SHA and breaking-impact matrix (Automations, Evaluator, Mail/Reports, later Newsletter), then source tests for full envelope/command safety, DOM/focus, PHP category interface and negative actor-scoped Template Library searches. No Base/Overseer edit is attempted by this consumer report.

## 5. Proposed ordered patch gates

- **A (independent, NOW):** finish branch-delta reconciliation ledger, identify DOM/source test gaps, prepare isolated negative/security tests. Dedicated Automations audit branch/doc only at this stage; preserve both source lines. No Base API selection yet.
- **B (after G1a approval):** one canonical Base domain session/history migration with explicit workflow DefinitionCodec-to-DesignProject projection; remove obsolete history integration, rewire save/context lifecycle and test meaningful command boundaries. No shims.
- **C (after approved Base category interface):** manually integrate the four Template Library commits into a reviewed child of the Folders line, resolving 13 overlap files; verify private CPT, actor scope, built-ins, source registration, internal-trigger suppression, safe instantiation and disabled/no-principal semantics. Six locale catalogs regenerated and reviewed.
- **D:** quality gate for Inspector, Preview, Builder Save, focus, keyboard, reduced motion, responsive/dark/light presentation, import/export/folders/switching. Build isolated ZIP; run installed WordPress/browser acceptance and owner field acceptance; report to Overseer. Separate merge/CI/release GO.

**Smallest joint candidate:** fork new Automations integration branch from exact Folders HEAD only after the approved G1a contract. Manually port Templates in domain/UI/test/i18n bounded layers; do not merge whole branches and do not discard local worktrees.

## 6. Test receipt and operator plan

**Actually executed:** GitHub source reads for Base/Automations modules, Designer shell/editor/history/Inspector/save/routes, folder repository/controllers, template bridge/safety/storage/actions, transfer controller/codec, canonical Base history/project-state contract, documentation; GitHub branch SHA/ancestry/file-stat comparisons. These are source inspection, NOT executable tests.

**Not executed:** local PHP lint, node syntax/tests, full unit suite, gettext/POT/PO check, Base resolved-module probe, PHP interface load, WordPress DB/activation/browser/mobile/keyboard/a11y/security runtime, release build, ZIP/checksum, CI. No customer ZIP produced.

**Read-only suggested local preflight, after inspecting and preserving status:**

    cd ~/Downloads/wp-core-blueprint-automations
    git status --short --branch
    git fetch origin --prune
    git rev-parse origin/main origin/feature/automations-workflow-folders-w31-v1 origin/feature/automations-template-category-provider-20261015
    git diff --stat origin/feature/automations-workspace-import-export-golden-v1...origin/feature/automations-workflow-folders-w31-v1
    git diff --stat origin/main...origin/feature/automations-template-category-provider-20261015

**Source/test commands to run in a clean isolated checkout on the source branch** (not executed here; these are exact file paths from the CI and tooling):

    node tests/js/designer-shell.test.mjs
    node tests/js/builder-maturity.test.mjs
    node tests/js/designer-golden-contract.test.mjs
    php tests/unit/workflow-folders-w31.php
    php tests/unit/workflow-folders-query.php
    php tests/unit/workflow-transfer-codec.php
    php tests/unit/workflow-import-claim-cleanup.php
    tools/i18n/check

On the Template branch separately:

    node tests/js/template-library-bridge.test.mjs
    php tests/unit/template-library-t3b-contract.php
    php tests/unit/wordpress-provider-contract.php
    tools/i18n/check

The release script is confirmed present: bash tools/build-release. It stages dist and performs localized PHP/JS and ZIP boundary checks, but must wait until the resolved Base contract and full source tests pass. Before operator acceptance use an isolated WordPress installation/DB and test create/load/edit/undo/redo/save/reopen/context switch, folder create/assign/delete, import/export, create-from-template disabled without inherited principal, denied actor, invalid nonce, unavailable provider, invalid schema, accessible keyboard navigation, light/dark/mobile and reduced motion. Never run automated workflow actions on a live site.

**Decision requested from Overseer:** approve canonical Base history/session and category/API contract design and exact Base candidate pin, then authorize bounded B/C integration. All branches and source data remain preserved; no merge, CI, publication or deployment without distinct user GO.

## 7. Subsequent operator validation receipt (2026-10-16)

This section is an append-only operator-reported validation update; the source-inspection-only execution declaration in section 6 describes the earlier audit and is not retroactively replaced. No local commands were executed by the auditing assistant.

- First W3.1 folder query run **FAIL** in `tests/unit/workflow-folders-query.php`, with the captured SQL for Unfiled correctly using `NOT EXISTS`, but fake `$wpdb->args` still retaining `['disabled', 4, '%hello%']` from a previous test scenario. The operator captured this exact error/SQL/arguments in the terminal.
- Isolated test-only correction, based on W3.1 source HEAD `f4f7e23c61ca2b9448cfd03615b5dc66b8b1106d`: branch `fix/automations-w31-folder-query-test-fixture-20261016`, HEAD `2b99d2b33dfb7105f6e0939ee05724897b62b48d`. Final diff adds an explicit fake-query-arguments reset **between independent scenarios**; production source and fake `get_var()` behavior are unchanged. A preliminary intermediate commit was superseded by this final corrected commit. This fix is **unmerged**.
- Operator-reported **PASS** on the isolated fix checkout `~/Downloads/wp-core-blueprint-automations-w31-test`: full printed PHP suite including workflow core/runtime, WordPress providers, Template foundation, W3.1 folder isolation and the previously failing SQL query regression; i18n canonical check for six locales; Base v1 contract check; release build; `sha256sum -c` and ZIP archive integrity. Earlier separately reported targeted Designer JS tests: 1 + 4 + 5 passing. Full JS test output for the latest fix checkout was not furnished here, so that specific suite is not asserted as independently witnessed by this receipt.
- RC2 artifact: `dist/core-blueprint-automations-1.0.0-rc2.zip`; operator-verified SHA-256 `eb10ca893cb909790b77b34feeae094981dc6c14872c8b682e63a3bd4563b9a2`. Identical runtime artifact digest to the earlier W3.1 artifact is expected because the only final change was test code excluded from the package.
- **Gate state:** the local W3.1 test-fixture blocker is closed; the **Designer G1a Base public history/session and TemplateCategorySourceInterface integration gates remain OPEN**. Passing this standalone RC2 build does not demonstrate cross-repository installed runtime compatibility or authorize merge, CI, staging installation, release or production activation.

**Next coordinator decision:** record W3.1 regression closure, nominate the approved Base public G1a contract SHA, and authorize the single reconciled Automations integration child branch before combining Folders and Template sources. No edits to Base or Overseer were made from this Automations report.
