# ADV-02B — Deterministic Graph Routing (isolated Batch B1)

**Status:** SOURCE IMPLEMENTED / OPERATOR REGRESSION PENDING  
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

## Not implemented yet — mandatory for actual execution

**ADV-02B remaining:** integrate graph node capability execution into a controlled Advanced-only runtime path after runtime authority and acknowledgement contracts are specified; verify action invocation ordering and error semantics using an isolated provider, not only supplied output projections.

**ADV-02C:** durable graph cursor, branch choice and skipped-branch evidence with lease-fenced CAS/acknowledgements and crash recovery. Mutating action UNKNOWN/indeterminate remains fail-closed; never blindly replay. Preserve Vault encryption and existing provider permission/causality contracts. The distinction between chosen/unselected branch and an executed/failed step must stay explicit.

Until these gates pass, GraphDefinition v2 remains disabled and never enters `RunPipeline`, `RuntimeWorker`, `EventMaterializer`, or Standard trigger admission. Do not wire up `GraphRoutePlanner` to runtime dispatch as a shortcut.

## Required local gates

```sh
php tests/unit/adv02b-graph-route-planner.php
php tests/unit/adv02a-graph-validator.php
php tests/unit/adv02a-draft-repository.php
bash tools/prepare-base-fixtures --verify
bash tools/test-regressions
bash tools/i18n/check
bash tools/build-release
```

**Governance:** child feature branch deliberately stacked on unmerged ADV-02A. No automatic merge or public release. Existing Golden v1 `main` must remain unchanged. Conversion and Node Editor are separate subsequent milestones.
