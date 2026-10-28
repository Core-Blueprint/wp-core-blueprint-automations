# ADV-02C C3 — Provider result and Action crash safety

**Date:** 28 October 2026  
**Status:** SOURCE CANDIDATE / PHP REGRESSION + BUILD PENDING  
**Parent:** `feature/automations-adv02c-c2-durable-checkpoints-20261028@234ecd096a3c242744b24cd73bca75fe90d572d7`  
**Authorization:** Chris / Product Owner GO for bounded C3 SOURCE implementation. No merge, deployment or Advanced v2 runtime admission.

## Delivered

### 1. Exact current provider output-schema gate

`src/Runtime/GraphProviderOutputGuard.php` validates a State/Action output against the **exact provider, capability ID, kind and schema version** currently available through the existing `CapabilitySource`.

It rejects unknown fields, absent required fields, wrong scalar/array types, non-finite numbers and invalid/unavailable capability identity. An empty output schema permits only an empty output. No sensitive output values are logged.

The existing C2 `GraphCheckpointStore::commit_completion()` now calls the output gate **within its lease-fenced transaction, after verifying the matching running attempt, before writing output, step status or cursor**. A malformed result rolls back all acknowledgement state. No Standard v1 code changes.

**Important:** checking output shape cannot establish that a PHP array genuinely originated from a provider call. The authoritative future executor must pass only the actual return value of the public Base `StateInvoker` / `ActionInvoker`, tied to its exact run, attempt, capability identity and `InvocationContext`. Until that executor is integrated and tested, arbitrary callers must not invoke `GraphCheckpointStore::commit_completion()`.

### 2. Durable no-replay Action start/quarantine

`src/Runtime/GraphActionStartFence.php` exposes a narrow, internal **pre-invocation** Action gate, `begin(run_id,node_id,expected_cursor,lease,catalog)`.

Inside a single existing `RunExecutionLease::transaction()` the gate:

1. Locks the v2 immutable run row, validates definition hash, status RUNNING, workflow/principal identity and exact v2 cursor.
2. Confirms the node is an Action in the locked immutable graph.
3. **Before reading the encrypted run context**, looks up the latest durable Action attempt under row lock. If any attempt exists, it **must not open another**. It moves the run to `indeterminate` (and marks a still-running attempt indeterminate) inside the same lease transaction, returning `null`. This remains safe even if Vault is unavailable after an external side effect.
4. For a first attempt only, loads the authenticated encrypted C1 journal, checks the journal-length/cursor agreement and B3 replay, and verifies that this precise Action is the unique pending intent on the chosen route.
5. Creates the matching `running` Action attempt **atomically under the same lease**. A future governed executor must invoke the provider only after receiving this committed attempt. If the commit result is uncertain, treat the attempt and any possible external effect as uncertain. Never automatically retry a mutating Action.

A prior terminal attempt whose acknowledgement is missing is also quarantined, never replayed. Lost lease, bad hash, unauthorized Action, incorrect route, corrupt context and SQL errors fail closed.

**C3 does not itself invoke a provider, admit a v2 workflow, create an Advanced run, or bind a public worker.** It supplies the safety boundary required by those later steps. No new tables, schema changes, dependencies, UI or frameworks.

## Behavioral regressions

`tests/unit/adv02c-provider-recovery.php` uses the real v2 graph, output guard, encrypted Sodium Vault, existing lease and step/run repositories, plus transactional fake SQL to cover:
- Correct output schema and negative wrong/missing/extra fields and provider identity.
- Wrong/off-branch/stale Action, v1 snapshot or corrupt immutable hash rejection.
- Lease loss and uncertain Action-start transaction rollback.
- First authorized Action attempt records exactly once.
- Simulated worker crash and unreadable Vault: previous running Action becomes indeterminate without re-invocation.
- Failed step/run quarantine updates rollback atomically; prior terminal attempts never replay.
- Source isolation: Standard v1 worker, pipeline and intake do not call the C3 primitives.

The C2 regression gained an additional **real C2-store transaction negative**: malformed provider result cannot mark the running step succeeded, write the journal or advance the cursor.

## Remaining before real Advanced execution

The future governed invoker must establish **actual provider-call provenance** from Base's public invocation APIs, enforce the execution principal and `InvocationContext`, capture result/error semantics and Action causal ancestry, and commit only the actual returned value. Action errors, thrown exceptions and uncertain provider or COMMIT outcomes must be indeterminate and operator-governed, never replayed. Live MySQL/MariaDB concurrency and crash interruption evidence, v2 admission, worker integration and UI are separately gated.

No **C3 production execution PASS** or live Action safety acceptance is asserted by source-only tests. AUT-DB-01 upgrade compatibility remains separate before customer release.

## Local validation (operator)

```bash
cd "$HOME/Downloads/wp-core-blueprint-automations"
export CB_AUTOMATIONS_BASE_FIXTURES_DIR="$HOME/Downloads/cb-automations-base-pins"
php -l src/Runtime/GraphProviderOutputGuard.php
php -l src/Runtime/GraphActionStartFence.php
php -l tests/unit/adv02c-provider-recovery.php
php -l src/Persistence/GraphCheckpointStore.php
php tests/unit/adv02c-provider-recovery.php
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

Expected focused regression messages:
- `ADV-02C C3 provider result schema and Action no-replay fence: PASS`
- `ADV-02C C2 durable checkpoint transaction and rollback: PASS`

**Status until operator evidence:** SOURCE IMPLEMENTED / REGRESSION AND ZIP VALIDATION PENDING / MERGE HOLD / DEPLOYMENT HOLD.
