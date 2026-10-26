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
