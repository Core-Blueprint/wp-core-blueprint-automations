# ADV-02C C4 — Authoritative Provider Invocation

**Date:** 28 October 2026  
**Scope:** C4 SOURCE only. Operator-approved implementation; no runtime admission, worker integration, schema migration, production deployment or merge.

## Implementation

- `GraphInvocationSnapshot::prepare()` locks the immutable v2 run **under the existing live `RunExecutionLease`**, verifies identity, graph hash, revision, cursor, authenticated encrypted journal, replay and exact current capability. It checks the explicit principal's current permission. It starts a *read-only State* attempt inside that transaction; no Action attempt is started here.
- `GraphProviderExecutor::execute()` consumes only the prepared route intent and binding input, constructs the exact Base `InvocationContext` with run UUID, correlation ID, workflow ID/revision, principal, node ID and durable attempt number; invokes **only public** `StateInvoker::resolve()` / `ActionInvoker::invoke()`.
- Before an Action, the actual C3 `GraphActionStartFence::begin()` must durably record its unique start. If an attempt already exists, the run is quarantined as `indeterminate` and no provider is invoked. `ActionCausality` preserves the existing anti-loop semantics.
- Successful output is passed directly from Base to the C3 `GraphProviderOutputGuard` and the C2 `GraphCheckpointStore::commit_completion()`; the latter validates again under a lease-fenced transaction and advances the cursor atomically.
- A State error is `blocked` (provider/permission contract failures) or `failed`; a potentially invoked Action error or invalid output is `indeterminate`. No automatic mutating Action retry is permitted. An uncertain checkpoint COMMIT throws without inventing a terminal acknowledgement. Restart recovery remains a C6 gate.

## Isolation

- Standard v1's `RunPipeline`, `RunRepository::find()`, intake and worker remain unchanged.
- This C4 code is not called from the worker or admin. **Advanced cannot execute through WordPress yet**. Explicit sandbox-only v2 admission and worker routing belong to C5.
- No new API endpoint, column, table, dependency or consumer-facing UI.

## Validation

Local direct checks were performed on the four C4 source/test files (PHP 8.4):

```bash
php -l src/Runtime/GraphInvocationSnapshot.php
php -l src/Runtime/GraphProviderExecutor.php
php -l tests/unit/adv02c-invocation-snapshot.php
php -l tests/unit/adv02c-authoritative-provider.php
php tests/unit/adv02c-invocation-snapshot.php
php tests/unit/adv02c-authoritative-provider.php
```

The focused tests use controlled doubles at the persistence/Base boundaries. They check immutable run hashes/cursors, principal checks, pending branch refusal, lease-transaction ownership, State-attempt reservation, exact Base invoker parameters and context, Action start-fencing, causal execution, invalid output, permission failures, Action uncertainty and uncertain acknowledgement propagation.

**Important validation boundary:** the focused tests are not live WordPress/Base/MySQL integration evidence. Canonical project regression suite, i18n, full build and installable development ZIP must be run against the committed branch before C4 can be marked SOURCE/BUILD PASS. C5 must separately prove real provider invocation; C6 must test live database crash and concurrency recovery.

### Canonical commands

```bash
cd "$HOME/Downloads/wp-core-blueprint-automations"
export CB_AUTOMATIONS_BASE_FIXTURES_DIR="$HOME/Downloads/cb-automations-base-pins"
bash tools/prepare-base-fixtures --verify
php tests/unit/adv02c-invocation-snapshot.php
php tests/unit/adv02c-authoritative-provider.php
php tests/unit/adv02c-provider-recovery.php
php tests/unit/adv02c-durable-checkpoints.php
bash tools/test-regressions
bash tools/i18n/check
bash tools/build-release
unzip -tq dist/core-blueprint-automations-1.0.0-rc2.zip
( cd dist && sha256sum -c core-blueprint-automations-1.0.0-rc2.zip.sha256 )
sha256sum dist/core-blueprint-automations-1.0.0-rc2.zip
git rev-parse HEAD
git status --short
```