# ADV-02C C5: sandbox-only Advanced runtime integration

**Date:** 28 October 2026. **Scope:** internal SOURCE pilot only. No public activation, no automatic v2 trigger fanout, no workflow mode conversion, no new database tables or endpoints, no main merge.

## Authority and execution

- Standard v1 stays on `RunPipeline::execute()`. `RuntimeWorker` checks the immutable run's `definition_version` and routes only version 2 to `GraphRunPipeline`. A missing/unrecognized version fails closed.
- `GraphRuntimeGate` requires an **explicit** `CB_AUTOMATIONS_ADVANCED_SANDBOX === true` *and* WordPress environment `local`, `development` or `staging`. Production can never enable this route. When the gate is off, v2 jobs must not fall back to Standard v1.
- Every v2 runtime read uses the existing job `RunExecutionLease`, verifies the immutable graph SHA-256, the cursor and the authenticated encrypted `graph_checkpoint`; only runs with encrypted `advanced_admission=cli_sandbox_v1` are accepted.
- The bounded route repeats: locked journal replay -> durably checkpoint IF/ELSE when pending -> C4 governed State/Action invocation -> durably checkpoint the Base return value -> End -> terminal `succeeded`. One invocation is **not** inside a database transaction; the C2/C3 writer is. No browser-supplied output or routing decision enters execution.
- For worker errors, first inspect a running Action attempt **without reading Vault**. Any running Action is `indeterminate`, never retried; Vault loss or repeated failures without running Action blocks, and other recoverable failures use the existing job backoff.
- C4's Action start fence additionally refuses *any* prior attempt for the same pending Action node after an unacknowledged provider mutation. C6 must still verify this against real MySQL/MariaDB crash and concurrency conditions.

## Explicit sandbox pilot (WP-CLI only)

`GraphSandboxAdmission::enqueue(int $workflow_id, string $admission_uuid, array $trigger_payload): int` is callable **only** from WP-CLI with an authenticated `manage_options` actor, when the sandbox gate is enabled. It accepts an existing **disabled** v2 workflow with a configured current execution principal, a graph validated against live Base capabilities and an exact typed trigger payload. It checks provider authorization for each State/Action before admitting the run.

The method creates a *synthetic, terminal* event receipt (not a real emitted Base event), immutable v2 run, encrypted journal + trigger context and durable run job in **one DB transaction**. It never enables the workflow or changes its `trigger_key`. The UUID is the operator's durable idempotency key. Reusing it for the same workflow revision yields the existing run rather than invoking Actions again; do not reuse with a different trigger payload. A lost SQL COMMIT acknowledgment is **UNKNOWN**: inspect persisted run/receipt before trying another UUID.

### Local/staging operator procedure

1. On a disposable WordPress sandbox with a tested backup, set in `wp-config.php`:

   ```php
   define( 'WP_ENVIRONMENT_TYPE', 'local' ); // Or staging/development.
   define( 'CB_AUTOMATIONS_ADVANCED_SANDBOX', true );
   ```

   Never put this flag in production. A second explicit CLI authorization is required for run creation.

2. Prepare a valid v2 **disabled** workflow and assign a real execution principal with the required provider permissions. There is no general Advanced UI activation in C5. Confirm the exact trigger schema, including required fields and types.

3. Supply the actual path, administrator username, workflow ID, and **operator-created UUID**. The payload below is only an illustrative schema-dependent example; adjust it to match the registered Base trigger exactly:

   ```bash
   WP_ROOT="$HOME/Projects/wordpress-test-site-1/wordpress"
   wp --path="$WP_ROOT" --user=admin eval '
   $id = \CB\Automations\Runtime\GraphSandboxAdmission::enqueue(
       123,
       "11111111-1111-4111-8111-111111111111",
       [ "id" => 1 ]
   );
   WP_CLI::log( "Advanced sandbox run ID: " . $id );
   '
   ```

   **Do not paste and execute this sample unchanged**: workflow ID, operator, UUID and trigger payload must be deliberate, valid sandbox values. If WP-CLI/WordPress reports that the actor is unauthorized, do not bypass the gate.

4. Use the normal one-shot WP-Cron worker or run its *existing* hook on the sandbox. Observe the run in the existing history and inspect step attempts. Verify Standard v1 still works, both IF/ELSE directions, State error, Action `indeterminate` and restart. Restore/disable the sandbox flag after the test.

## C5 acceptance limits

C5 SOURCE tests are not a substitute for real Base provider and MariaDB evidence. C6 is the independent crash/recovery, race and replay gate. No Advanced mode button or public activation is exposed until visual Node Editor and end-to-end acceptance. Checksum and ZIP identity must come from the operator's canonical `tools/build-release` execution.

### Operator validation commands

```bash
cd "$HOME/Downloads/wp-core-blueprint-automations"
export CB_AUTOMATIONS_BASE_FIXTURES_DIR="$HOME/Downloads/cb-automations-base-pins"
php tests/unit/adv02c-sandbox-routing.php
php tests/unit/adv02c-worker-execution.php
bash tools/prepare-base-fixtures --verify
bash tools/test-regressions
bash tools/i18n/check
bash tools/build-release
unzip -tq dist/core-blueprint-automations-1.0.0-rc2.zip
(cd dist && sha256sum -c core-blueprint-automations-1.0.0-rc2.zip.sha256)
sha256sum dist/core-blueprint-automations-1.0.0-rc2.zip
git rev-parse HEAD
git status --short
```