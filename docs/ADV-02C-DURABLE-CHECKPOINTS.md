# ADV-02C C2 — Lease-fenced, encrypted Advanced checkpoints

**Status:** SOURCE CANDIDATE / OPERATOR REGRESSION AND BUILD PENDING  
**Authorized:** Chris, 28 October 2026, bounded C2 SOURCE GO  
**Parent:** C1 `dec9edc43a6d96ef1fe9922db831e58033f042e5`  
**Guardrails:** Standard v1 Golden frozen; no v2 activation, no provider execution, no merge or deployment; no new database table, external dependency or framework.

## What C2 implements

`src/Persistence/GraphCheckpointStore.php` has exactly two internal, version-fenced checkpoint writes: `commit_branch` and `commit_completion`. They deliberately have **no entry point in the existing WordPress worker, admin, activation or intake pipelines**.

Both operations use the existing `RunExecutionLease::transaction()` to lock the current run-job lease. Inside that same DB transaction they:

1. Lock the immutable run row `FOR UPDATE`. Require `definition_version=2`, `status=running`, positive workflow identity and principal, matching SHA-256 over raw definition JSON, and strict v2 `GraphDefinitionCodec`. No Standard v1 run may enter this path.
2. Require the caller's exact current cursor and validate `graph:N` against the number of journal entries held in the authenticated, encrypted `RunContextRepository` context. The C2-only cursor never changes `RunCursor` or `RunRepository::advance_cursor` (both remain v1).
3. Revalidate/replay all previously recorded journal entries with the B3 planner against the immutable graph and source's current capability catalog; reject disagreements, stale prefixes, invalid branches, or unknown outputs.
4. Advance by exactly **one** evidence entry. A branch choice is derived deterministically from prior outputs, then records one successful local Condition step. A completion requires an existing matching `running` State/Action attempt (same run, attempt ID, node ID, kind, provider, capability ID and schema version) and changes that attempt to `succeeded`.
5. Write the updated journal into the existing authenticated encrypted run context, then CAS the v2 run cursor with the original hash/status/version/expected cursor. A single row must be updated.
6. Commit only when **all** components succeed; missing/expired lease, SQL error, step mismatch, stale cursor, malformed context/definition, or a failed/uncertain COMMIT aborts via rollback and exposes an exception. No automatic Action retry.

Context is required to already contain the strict `graph_checkpoint` object with **exact ordered keys** `journal`, `trigger_outputs`. C2 intentionally does **not** initialize a v2 run, create a job, issue a lease, or write a trigger event. These are future governed runtime-admission concerns.

The journal is encrypted and bound to the run ID by the existing `Vault` and `RunContextRepository`. The run row contains only non-sensitive cursor metadata; no plaintext step outputs are logged or stored there.

## Critical safety limits

**C2 is not yet a production executor.** The SOURCE cannot prove that a supplied State/Action output genuinely came from a provider: C3 must implement output-schema validation and trusted post-invocation acknowledgement; C3 must also enforce Action uncertainty and no-replay on crashes. No call from untrusted HTTP, user input or plugin hooks is permitted. A previously committed result is not safe to re-execute merely because the caller sees a timeout.

**InnoDB transaction assumption:** all nine canonical v1 tables are required to be matched and READY before any future v2 admission. The existing schema definition requires InnoDB. The canonical lease, step and context repositories are reused without modifying their behavior.

## Test gate

`tests/unit/adv02c-durable-checkpoints.php` uses real C1/B3 graph planning, real `RunExecutionLease`, `RunLeaseRepository`, `RunStepRepository`, `RunContextRepository` and Sodium Vault with a deterministic fake SQL adapter implementing rollback. It covers:

- One State completion, deterministic true-branch acknowledgement, corresponding Condition attempt, one Action completion, and encrypted End-state replay.
- Stale/duplicate completions, off-branch Action, incorrect node kind and mismatching attempt/provenance.
- Missing lease, mismatched immutable definition hash/version, journal/cursor divergence.
- Injected failures in context storage, step acknowledgement, run cursor update and COMMIT; all require rollback.
- Live v1 runtime's intentional lack of any C2 entry point.

The canonical `tools/test-regressions` discovers the C2 PHP test automatically. Run exact branch HEAD and verify:

```bash
export CB_AUTOMATIONS_BASE_FIXTURES_DIR="$HOME/Downloads/cb-automations-base-pins"
php -l src/Persistence/GraphCheckpointStore.php
php -l tests/unit/adv02c-durable-checkpoints.php
php tests/unit/adv02c-durable-checkpoints.php
bash tools/prepare-base-fixtures --verify
bash tools/test-regressions
bash tools/i18n/check
bash tools/build-release
unzip -tq dist/core-blueprint-automations-1.0.0-rc2.zip
( cd dist && sha256sum -c core-blueprint-automations-1.0.0-rc2.zip.sha256 )
sha256sum dist/core-blueprint-automations-1.0.0-rc2.zip
git rev-parse HEAD
git status --short
```

Expected standalone test message: `ADV-02C C2 durable checkpoint transaction and rollback: PASS`.

**No SOURCE/BUILD PASS can be claimed until the operator returns complete validation evidence.**
