# Automations architecture audit and refactor | 2026-10-26

Status: **PARTIAL SOURCE AUDIT / TARGETED P0 FIX COMMITTED / VALIDATION OPEN**

Repository: `Core-Blueprint/wp-core-blueprint-automations`  
Inspected baseline: `feature/automations-a-inf-s1-clean-v1-schema-readiness-20261025` @ `3110ec83ae2ea65ad9fb75572c1a174e015c6fc0`  
Audit workbranch: `feature/automations-golden-refactor-audit-20261026`  
No main merge, release, deployment or CI execution authorized.

## Design criteria

- Preserve Base public Designer Foundation ownership and Automations' domain orchestration, authorization, runtime and persistence authority.
- Use supported WordPress APIs wherever they meet the actual domain requirements.
- No pre-v1 legacy shims or speculative abstractions; do not remove safety fences just to reduce line count.
- Red/green focused regression, Level 1, ZIP and installed WordPress field acceptance are distinct gates.
- Schema lifecycle changes and destructive operations require adversarial evidence, never silent repair.

## Confirmed findings

### P0 | Admin boot namespace resolution

`src/Plugin.php` invoked `WorkflowDeletionController::init()` without importing `CB\Automations\Admin\WorkflowDeletionController`. Autoloading therefore resolves the wrong namespace under the plugin autoloader and can terminate admin boot with a missing class.

Correction: add the exact import. `tests/unit/runtime-bootstrap.php`, already covered by CI, now checks that every `::init()` / `::init_maintenance()` call in `Plugin.php` maps to an existing source class. A read-only GitHub source probe confirmed one unresolved initializer before the fix and zero afterward. **PHP test execution and browser acceptance remain unrun.**

### P1 | Incomplete existing CI selection

The repository contains deletion tests `tests/unit/workflow-delete-controller-w32.php`, `tests/unit/workflow-delete-repository-w32.php`, and `tests/js/workflow-delete-ui.test.mjs`. The inspected `.github/workflows/ci.yml` has explicit test commands and did not include these three W3.2 tests. Manual source test selection must include them; a separately reviewed CI test-selection update is advisable. No CI configuration was modified.

### P1 | Large responsibilities needing measured refactor

Source inspection identifies these candidates; file length alone does **not** establish redundancy:

| File | Lines (baseline) | Verified responsibility mix |
| --- | ---: | --- |
| `assets/js/admin-editor.js` | 748 | Workflow state, DOM composition, binding controls, condition controls and editor session adapter |
| `src/Persistence/Schema.php` | 533 | Nine-table declarations, read-only inspect, install claim/CAS, repair and explicit install |
| `src/Workflow/Recovery/WorkflowRecoveryPlanner.php` | 600 | Recovery classification, planning, validation and application |
| `src/Validation/WorkflowValidator.php` | 554 | Workflow rules, bindings, capability, schema/condition and sensitivity validation |
| `assets/js/admin-designer-shell.js` | 462 | Base Designer shell adapter and multi-workflow context transitions |
| `assets/js/admin-builder-history.js` | 412 | Base Domain Session/history consumer and selection-integrity fencing |

Do not split physical schema authority or Base session ownership prematurely. First map callers and invariants, identify genuinely independent pure logic or duplicated checks, and preserve fail-closed behavior.

### Security | W3.2 deletion path inspected, not field-verified

`WorkflowDeletionController` checks POST, `manage_options`, a per-action nonce, confirmation, bounded IDs and revisions. `WorkflowRepository::delete_disabled_batch()` checks physical readiness, locks selected records with `FOR UPDATE`, rejects enabled/stale/missing records, and deletes assignments/workflows in one transaction while preserving run snapshots. Commit-acknowledgement uncertainty is not auto-retried. Existing tests cover controller negative cases and a simulated rollback. Real InnoDB/WordPress behavior remains an acceptance gate.

## Next controlled steps

1. Execute the strengthened bootstrap test, W3.2 deletion tests, all applicable unit and JS regressions, i18n and exact RC2 release build; verify SHA and package contents.
2. Complete read-only full-repository class/call-site and JS/DOM authority inventory. No claim of complete unused-code removal before this scan.
3. Refactor one cohesive boundary at a time, starting with separating editor presentation helpers from canonical workflow state **only when** dependencies and field behavior are proved. Do not introduce a second Designer/History/Selection framework.
4. Review schema install/recovery complexity with specific failure evidence before considering simplification, retaining data ownership and fail-closed execution.
5. Operator installs only an independently validated ZIP on the existing sandbox; confirm create, Save/Reopen, Undo/Redo, switching, disabled deletion/bulk deletion, permissions and error recovery.

## Evidence status

- GitHub baseline and workbranch identification: VERIFIED.
- Bootstrap missing import and source-path resolution after patch: STATIC SOURCE VERIFIED.
- PHP/JS executable suites: NOT RUN in this agent environment (GitHub connector access only).
- ZIP, checksum and installation: NOT BUILT / NOT RUN.
- Golden acceptance: OPEN.


## Validation receipt and audit follow-up | 2026-10-26

Operator-provided local evidence on commit `0e24097ede88baeb77e1d7721aa3ffb61f9902ae`:
- `php -l src/Plugin.php`: PASS.
- `php -l tests/unit/runtime-bootstrap.php`: PASS.
- `php tests/unit/runtime-bootstrap.php`: PASS.
- Standalone PHP tests over `tests/unit/*.php`: all reported PASS (loop stops at first failure).
- W3.2 controller/repository tests: PASS. Logged "Uncertain COMMIT" is the deliberately injected negative case.
- i18n: six catalog locales PASS, canonical tooling 1.1.1.
- Full JS suite initially 124/129 PASS due solely to an incorrect *local F02 checkout path*.
- The operator subsequently selected the existing read-only `wp-core-blueprint-f02-automations-pin-20261025` checkout at the tests' exact SHA `11622c6224862b0b414352b0fda6e895e2cf153c`; full JS suite: **133 tests, 133 PASS, 0 FAIL**.
- No live WordPress browser, InnoDB or release-ZIP evidence is established by the above.

Source housekeeping after this receipt (not yet covered by a new full operator run):
- Added explicit W3.2 controller, repository and JavaScript tests to the existing GitHub CI test selection. **CI NOT TRIGGERED**.
- Added root `.gitignore` for generated `/dist/`, without deleting or overwriting existing operator artifacts.

Architecture priority:
- `WorkflowRecoveryPlanner` is an AU-R1A *pure recovery planning* facility; AU-R1B's privileged service is documented as separately gated. Production entrypoints inspected so far (Plugin, workflow controllers/service, admin read/editor, runtime recovery controller/service) do not call it. **Do not declare it globally unused until a complete `src/` call-site scan**, and do not conflate it with the active runtime operator recovery service.
- `WorkflowValidator` and `WorkflowRecoveryPlanner` have single coherent domain purposes despite large size. A line-count-only split would create abstractions without proving value.
- Next: prove all production references and exact execution boundary before a deliberate pre-v1 clean-break decision (integrate or remove the dormant AU-R1A path); then tackle editor presentation separation only with equivalent tests.


## Refactor R1 | AU-R1A dormant code clean-break (implementation pending operator validation)

**Decision:** remove the never-integrated `src/Workflow/Recovery/WorkflowRecoveryPlanner.php` and its dedicated test `tests/unit/workflow-recovery-planner.php` from the active v1 branch. Source reachability checked with an operator-side exact grep across `src/` and `tests/`: the only class reference in `src/` was its own declaration; all other references were in tests. This is a pre-launch clean break, not removal of an active entrypoint. The removed source is recoverable from Git history if a future authorized AU-R1B workflow remediation project is approved.

**Preserved:**
- Active **run** recovery: `Admin/OperatorRecoveryController.php` and `Runtime/OperatorRecoveryService.php` remain untouched. The planner was about **workflow definition** remediation, not indeterminate-run operator recovery.
- Editor read-only recovery route remains unchanged; no destructive or automatic cleanup was introduced.
- Existing editor redaction, privacy persistence policy, authorization, storage and runtime safety checks remain untouched. Their active tests remain in `tests/unit/editor-save-boundary.php`, `tests/unit/privacy-policy.php` and other runtime suites.
- `tests/unit/ainf-s1-no-legacy.php` now asserts the dormant planner cannot reappear in the v1 runtime tree.

**Not proven yet:** full PHP suite, 133 JS tests, six-locale i18n, fresh ZIP, checksum, installed sandbox. Previous PASS was established on commit `d6099d84624a2f4bbcf7442d0727785dd8bbfd8d` **before** this controlled source removal. The exact new branch HEAD and all evidence must be refreshed after the patch.

**Follow-up architecture audit:** identify genuinely duplicated active responsibilities in the editor, validation and persistence modules. Do not split their files based only on size.


## R1 sandbox acceptance and R2 admin request-method boundary (2026-10-26)

**R1 validated baseline:** operator confirmed PHP suite PASS, JavaScript **133/133 PASS**, Base F02 pairing PASS, six-locale i18n PASS, installable ZIP integrity/checksum PASS and stable sandbox behavior. Built on clean detached worktree at source `5dd3ddfa0f48730652b845daa9de54e948b18715`. R1 artifact SHA-256: `ec4c394f781e7fbc783956bde80bd94294ef0dcd181332647dc804669faedaf2`.

**R2 finding:** native WordPress `admin_post_*` hooks dispatch regardless of the HTTP request verb. The mutation/transfer endpoints checked capabilities and POST-derived nonces, but several guards lacked an explicit HTTP method boundary. This is an inconsistent security contract and undesirable 403/302 behavior for non-POST requests; **it is not evidence of a successful CSRF or permissions bypass** because POST nonces and capability checks were already enforced.

**R2 implementation (feature branch only):**
- Require exact `POST` before auth/nonce/storage for WorkflowController, WorkflowFolderController, TemplateManagementController, WorkflowTransferController and OperatorRecoveryController.
- WorkflowTransferController::export() additionally checks the verb **before** extracting the POST-scoped ID, so even malformed non-POST exports consistently receive 405.
- Reuse the existing localized "This action requires HTTP POST." message; no new translations, abstraction layers or runtime dependencies.
- Add `tests/unit/admin-post-method-boundary.php`: actual production guard methods + workflow export entrypoint, six rejected HTTP verbs, 405 before authorization/nonce calls, and successful POST authorization for the four reusable guards. Selected explicitly in CI without starting any workflows.

**R2 verification HOLD:** all R1 evidence predates this new hardening. Run the new PHP test, entire unit suite, 133 JS tests, i18n, release build/ZIP checksum, verify exact R2 SHA and clean worktree, then sandbox smoke. Do not merge or trigger CI without user GO.

**Next architecture candidates:** the active editor modules (~748 lines of canonical authoring behavior in `admin-editor.js`, plus presentation polish) should be evaluated for repeated DOM metadata projections and redundant event ownership, but split only when tests prove behavior-preserving benefit. Shared Base shell/history/selection must remain unmodified.


### Structural namespace sweep (R2)

Read-only GitHub source scan across all **109 remaining `src/**/*.php` files** at the R2 feature branch: the declared namespace and top-level class/interface/enum/trait name match the PSR-4-equivalent source path, including root `CB\\Automations` classes. Result: **109/109 structural matches, 0 mismatches**. This is not a PHP runtime/autoload execution result and does not establish absence of stale/unreferenced classes. The bootstrap initializer resolution is separately covered by `tests/unit/runtime-bootstrap.php`.

`admin-editor.js` remains the primary presentation-density candidate (~748 lines), but it owns concrete domain authoring controls and is already wired to public Base Selection/History; a file size alone does not justify another framework or a wholesale rewrite. More targeted duplication/reachability evidence is required before R3 source changes.


## R2 validation receipt and R3 single-owner condition preview refactor (2026-10-26)

**R2 validated by operator:** source `1dc753ed9417184e322470bee4859843c2a6720c` on clean detached worktree; PHP unit regressions PASS, JavaScript **133/133 PASS**, six-locale i18n PASS, Base v1 contract PASS, release ZIP integrity and checksum PASS. ZIP SHA-256 `46d9744e9c0c51f0d6969c0a47f75238a18c975b82de6407ef510dd18aecfd03`. No R2 sandbox acceptance yet provided.

**R3 finding (actual duplicate UI ownership):**
- `admin-editor-polish.js` rendered/moved `.cb-automations-condition-summary` **before** the condition grid, using generic lowercase operator labels and its own literal-format helpers.
- `admin-condition-builder.js` separately rendered/moved the **same node after** the condition grid with schema-aware operator phrasing and unary/binary arity handling. Both registered definitionchange/change/input/click refreshes. The later-loaded condition-builder was authoritative but both could repeatedly rewrite and reposition the same DOM node.

**R3 scope (no domain or runtime rewrite):**
- Remove the duplicate preview constructor/updater and now-unused literal/condition text helpers from `admin-editor-polish.js` (31 fewer lines).
- Preserve the polish layer's step titles, input labels, technical/advanced details.
- Keep `admin-condition-builder.js` as the only preview renderer and preserve existing CSS, translation sources, event ownership and Base integration.
- New focused source-ownership regression `tests/js/editor-condition-preview-owner.test.mjs` checks the real modules and unary-aware after-grid placement. Add to explicit CI selection; CI not triggered.
- Static authoring-side regex checks over fetched new sources passed (not a substitute for `node --test` or sandbox browser validation).

**R3 validation HOLD:** obtain exact new source SHA from GitHub branch, create isolated detached worktree, run new regression, entire existing **133 JavaScript tests plus the new test**, all PHP unit regressions, Base F02 pairing and i18n, release builder and checksum. Sandbox smoke specifically condition preview after editing unary/binary operators, switching context, save/reopen and undo/redo. No release acceptance or merge before verified receipt.

**Deferred cleanup:** The old `reads_as` localization key in AdminAssets is no longer consumed by `admin-editor-polish.js`; remove its source string and synchronize canonical POT/PO in a dedicated future i18n change rather than destabilizing this narrow DOM-ownership patch. The R3 source should not introduce a second condition renderer or custom Base history.


## R3 verification and R4 template admin error boundaries (2026-10-26)

**R3 receipt:** operator validated `411a5f4b14f0110d50c3f09e7dbd50a4b7cd6807` on its isolated, clean detached worktree. PHP regressions PASS, JS **134/134 PASS** (including single-owner condition-preview test), six-locale i18n PASS, Base v1 contract PASS and ZIP integrity/checksum PASS. The R3 archive SHA-256 was `dd30a06361a19d06bd01b6d6fe6c03075dcc24ce015aecc94f87b80d7e4eb789`. The R3 sandbox visual acceptance is still open.

**R4 finding:** WordPress native template storage and third-party template registration can throw. `TemplateManagementController::delete()` previously called `StoredTemplateRepository::remove()` without handling exceptions; `TemplateManagementController::export()` called `get()`, `definition()` and serialized the result before any catch; and `WorkflowController::create_from_template()` loaded templates before its existing try/catch. Those faults can produce uncaught admin errors despite the rest of the product using controlled notices. No claim that ordinary WordPress storage failures always throw; this is resilience to exceptions from DB/storage hooks and provider integrations.

**R4 minimal behavior-preserving patch:**
- Template delete catches unexpected exceptions and routes to existing `template_unavailable` notice, never claiming success without a confirmed return value.
- Template export catches storage/encoding exceptions and refuses to send any download headers or JSON until the complete definition is ready.
- Workflow creation from saved/registered template catches failure during source lookup and redirects to existing `template_unavailable` notice. The existing principal, disabled-by-default workflow creation, nonce and capability policies remain unchanged.
- New `tests/unit/template-admin-error-boundary.php` invokes the real controllers under simulated WordPress hook/read/delete/provider failures and checks safe notices. The test is selected in CI but CI was not started.

**R4 SOURCE HOLD until operator tests:** isolated worktree at the new GitHub branch SHA; run `php -l` on changed PHP files and the new test, `php tests/unit/template-admin-error-boundary.php`, full PHP loop, all JS tests, i18n, release build, ZIP integrity and checksum. Repeat template create/delete/export smoke in sandbox. The previous validated R3 archive stays available until acceptance.

The next audit step should **not** invent a new template storage abstraction: R4 deliberately keeps canonical WordPress post APIs and existing repository boundaries. All nontrivial refactors require a demonstrated functional or maintenance benefit.
