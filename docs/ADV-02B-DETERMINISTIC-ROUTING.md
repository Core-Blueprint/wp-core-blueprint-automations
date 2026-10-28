# ADV-02B — Deterministic Graph Routing (isolated Batch B1)

**Status:** B1 and B2 SOURCE REGRESSION/BUILD PASS (operator 2026-10-28); B3 SOURCE IMPLEMENTED / REGRESSION PENDING  
**Base:** ADV-02A `f34f1f269d3c23d3f2bdab7703d75d837c40e4a8` (unmerged, operator sandbox STABIEL)  
**Branch:** `feature/automations-adv02b-deterministic-graph-20261028`

## Why this is an isolated first batch

Advanced needs a deterministic way to select exactly one output port from an IF/ELSE node and reach its matching Exclusive Merge. However, the existing Standard runtime assumes ordered `states → conditions → actions`. Reusing that pipeline for GraphDefinition v2 would be unsafe.

## Implemented

- `GraphRoutePlanner` is a read-only planning kernel; it requires successful `GraphValidator` admission of the supplied definition for *route simulation*, not runtime authority.
- `next()` produces one typed transition `{from,to,port,decision}` without running callbacks.
- `trace()` returns the chosen node sequence and boolean branch decisions using externally supplied completed-step outputs.
- Conditions reuse canonical `BindingResolver` and `OperatorCatalog`; missing/malformed source values fail closed.
- True/false exclusivity, deterministic traversal, Exclusive Merge reached once, no implicit parallel fanout.
- No new tables, no modifications to Standard v1 phase runners or activation, no direct WordPress/Base action or state invocations.
- `tests/unit/adv02b-graph-route-planner.php` provides positive and negative route/regression evidence.

## B2 implemented — typed pre-invocation boundary

- `GraphNodeIntentPlanner` plans one **State** or **Action** with the exact current capability reference and resolved input bindings.
- Before producing an intent it rechecks semantic graph validation, provider status and capability identity. It rejects malformed, missing or type-incompatible dynamic inputs without coercion.
- No real provider execution, jobs, persistence, execution-principal authorization, Vault read/write, or workflow activation is performed. Intent output may include sensitive runtime values and MUST NOT be logged or returned to an admin endpoint.
- `tests/unit/adv02b-node-intent.php` covers typed input, required bindings, schema mismatch, secret literal, unknown node, unavailable provider and isolation from the live worker.
- This is **pre-invocation planning only**, not proof that a State or Action has completed; evidence of completion requires ADV-02C durable acknowledgement.

## B3 implemented — bounded checkpoint projection

- `GraphCheckpointPlanner` consumes an **ordered list of acknowledged State/Action IDs** and protected outputs to project the **next pending Node Intent** or a complete graph path. The list is an input assumption, not proof of execution.
- It composes the already reviewed B1 `GraphRoutePlanner` and B2 `GraphNodeIntentPlanner`, and requires an acknowledgement prefix matching the actual chosen branch. A missing State acknowledgement cannot be skipped, and acknowledgement of an unselected branch is rejected.
- Outputs from unacknowledged nodes are rejected. IF/ELSE choices must resolve from the already supplied outputs; no false-by-default or silently dropped nodes. Selected Exclusive Merge is reached once on completed paths.
- No executor, database mutation, hooks, provider invocation or activation gate is wired. A future ADV-02C executor must source **immutable trusted acknowledgement IDs, decisions and outputs from lease-fenced persistence**, never from an HTTP caller or this pure projection alone.
- `tests/unit/adv02b-checkpoint-planner.php` exercises true/false, pending/complete, node order, malformed and off-branch acknowledgements, output injection, missing/invalid inputs and Standard runtime separation.
- Performance note: the bounded reference projection revalidates the graph on each B1/B2 planning step. Before runtime admission, use a verified immutable graph plan with at-most-once validation per step/run and benchmark the maximum supported graph size.

## Not implemented yet — mandatory for actual execution

**ADV-02B remaining:** integrate graph node capability execution into a controlled Advanced-only runtime path after runtime authority and acknowledgement contracts are specified; verify action invocation ordering and error semantics using an isolated provider, not only supplied output projections.

**ADV-02C:** durable graph cursor, branch choice and skipped-branch evidence with lease-fenced CAS/acknowledgements and crash recovery. Mutating action UNKNOWN/indeterminate remains fail-closed; never blindly replay. Preserve Vault encryption and existing provider permission/causality contracts. The distinction between chosen/unselected branch and an executed/failed step must stay explicit.

Until these gates pass, GraphDefinition v2 remains disabled and never enters `RunPipeline`, `RuntimeWorker`, `EventMaterializer`, or Standard trigger admission. Do not wire up `GraphRoutePlanner` to runtime dispatch as a shortcut.

## Required local gates

```sh
php tests/unit/adv02b-graph-route-planner.php
php tests/unit/adv02b-node-intent.php
php tests/unit/adv02b-checkpoint-planner.php
php tests/unit/adv02a-graph-validator.php
php tests/unit/adv02a-draft-repository.php
bash tools/prepare-base-fixtures --verify
bash tools/test-regressions
bash tools/i18n/check
bash tools/build-release
```

**Governance:** B3 child branch `feature/automations-adv02b-b3-checkpoint-planning-20261028` is stacked on B2, itself stacked on unmerged ADV-02A. No automatic merge or public release. Existing Golden v1 `main` must remain unchanged. Conversion and Node Editor are separate subsequent milestones.
