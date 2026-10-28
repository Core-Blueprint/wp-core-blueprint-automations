# ADV-02C C1 — Replay-verified checkpoint journal

**Status:** SOURCE CANDIDATE / LOCAL REGRESSION & BUILD PENDING  
**Parent:** `feature/automations-adv02b-b3-checkpoint-planning-20261028@be4be243f0323ea46d1f51de63a3ac8cc215cd5d`  
**Authorization:** Product Owner GO for next bounded Advanced SOURCE phase, 28 October 2026. No merge, deployment, or runtime admission.

## Purpose

B1 resolves graph routing; B2 builds a pre-invocation State/Action intent; B3 projects the next step from ordered acknowledged IDs and trusted outputs. **C1 adds a strict, immutable in-memory journal format** for durable checkpoint evidence that a *future* lease-fenced writer can persist atomically with run cursor and step status in the existing encrypted run context.

`src/Runtime/GraphCheckpointJournal.php` records an ordered set of distinct entries, each exactly one of:

- `{kind:"state"|"action",node_id:string,output:object}`: proposed acknowledged State/Action completion.
- `{kind:"if_else",node_id:string,decision:boolean}`: branch choice obtained **only** from B3 deterministic routing; a caller cannot choose arbitrary true/false.

The strict codec enforces exact keys, unique bounded IDs, a 512-entry maximum, JSON-serializability, and 256 KiB maximum serialized document. Replay verifies each State/Action entry against the actual pending intent and each recorded branch choice against the route derived from previously supplied outputs. Step acknowledgements cannot leap over an unrecorded branch decision, and no unselected branch may become acknowledged. Every append returns a new journal value rather than mutating its predecessor. The trigger output envelope is supplied separately by a future trusted, encrypted run-context reader and is constrained to the graph trigger identity.

**Security boundary:** C1 is a *candidate evidence data structure*, not a trusted ledger or proof a provider executed. Outputs are not authenticated by C1; they must originate from the verified provider invocation, be validated against provider output schema, and be committed with the run-step state and cursor **under the same durable lease-fenced transaction**. Existing `RunExecutionLease`, `RunContextRepository`, `RunStepRepository` and `RunRepository` are reference primitives only; C1 does not call or modify them.

## Regression contract

`tests/unit/adv02c-checkpoint-journal.php` reuses the existing B3 graph fixture and exercises:

1. Initial pending State, both route branches, and normal complete-to-End replay.
2. Rejection of skipped/wrong-kind/off-branch actions, duplicate branch evidence and out-of-order entries.
3. Rejection of forged branch decisions, unrecognized fields, duplicate IDs, non-JSON and oversized outputs.
4. Immutable earlier journal values and strict serialization round-trip.
5. Standard v1 worker, run pipeline and trigger intake remain independent of the disabled Advanced journal.

The test is discovered automatically by `tools/test-regressions` (PHP files in `tests/unit`).

## Remaining mandatory implementation before Advanced execution

1. C2: implement encrypted, lease-fenced, immutable run-snapshot-derived journal read/write with transactional coupling between step attempt, output, branch choice and run cursor; reject stale/ambiguous writes.
2. C3: enforce capability output schema and durable crash recovery semantics (especially Action invocation uncertainty and idempotency policy); independently verify concurrency/failure-injection tests.
3. C4: only after C2/C3 acceptance, wire controlled v2 run admission and worker execution; preserve v1 behavior, execution principal and activation governance.

No new table, schema migration, third-party dependency, UI, public API, or live v2 admission in this C1 patch.

## Operator validation

```bash
cd "$HOME/Downloads/wp-core-blueprint-automations"
export CB_AUTOMATIONS_BASE_FIXTURES_DIR="$HOME/Downloads/cb-automations-base-pins"
php -l src/Runtime/GraphCheckpointJournal.php
php -l tests/unit/adv02c-checkpoint-journal.php
php tests/unit/adv02c-checkpoint-journal.php
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
