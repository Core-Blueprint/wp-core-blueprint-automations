# ADV-02C C6: crash/recovery validation

**Date:** 28 October 2026. **Scope:** fail-closed repair of Advanced v2 sandbox worker recovery and independent real-DB proof. Standard v1, public Advanced activation, schema, normal trigger fanout and Base invokers are unchanged.

## C6 safety invariant

`GraphActionRecoveryFence::before_vault()` is executed under the actual run-job lease **before** C5's encrypted checkpoint read. It locks the v2 run row and inspects the latest **unacknowledged Action attempt** (`running` or `indeterminate`) under `FOR UPDATE`.

- No unacknowledged Action exists: normal C5 replay of immutable graph + authenticated context can continue.
- A started Action has no durable successful checkpoint: mark an attempt `indeterminate` if still `running`, and move the running run to `indeterminate` in **the same lease-fenced transaction**. Do not call Base, decrypt Vault or retry it.
- A queued run somehow contains a started Action: mark its attempt `indeterminate` and move the queued run to `blocked`. The state-machine deliberately does not allow queued -> indeterminate. Never run the Action.
- A run already halted: return the terminal/quarantine status without rewriting evidence.
- Database error, invalid identity or lost lease: throw, roll back and rely on the C5 worker failure path. Never interpret uncertain SQL as proof that an Action was never invoked.

**Why this matters:** C5 would eventually reach `GraphActionStartFence` on a normal retry and quarantine there, but reading Vault first adds failure paths and leaves an earlier window where an orphaned Action has not yet been classified. C6 closes that window for the worker dispatch path. C3's per-node Action start fence remains the final no-replay authority.

## Source validation (operator)

```bash
cd "$HOME/Downloads/wp-core-blueprint-automations"
export CB_AUTOMATIONS_BASE_FIXTURES_DIR="$HOME/Downloads/cb-automations-base-pins"
php tests/unit/adv02c-crash-recovery.php
php tests/unit/adv02c-c6-field-contract.php
php tests/unit/adv02c-worker-execution.php
php tests/unit/adv02c-provider-recovery.php
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

## FIELD: real MySQL/MariaDB evidence (C6-F1)

**Do not run before installing the validated C6 development ZIP on a disposable, backed-up WordPress sandbox.** The script intentionally writes isolated synthetic rows to plugin tables and deletes them afterward. It never calls a provider, changes a workflow, enables Advanced for general events, or emits a Base trigger. Never execute it on a production database or shared customer data.

1. Ensure the sandbox has `WP_ENVIRONMENT_TYPE` set to `local`, `development` or `staging`, and `CB_AUTOMATIONS_ADVANCED_SANDBOX` explicitly set to `true` in `wp-config.php`. The operator passed through `--user` must have `manage_options`.
2. Verify the installed plugin is the C6 build; the script refuses to start without `GraphActionRecoveryFence` and the canonical runtime schema.
3. From the SOURCE checkout, on the machine that actually runs the WordPress sandbox:

   ```bash
   WP_ROOT="$HOME/Projects/wordpress-test-site-1/wordpress"
   WP_ADMIN="<YOUR_WP_ADMIN_USERNAME>"
   wp --path="$WP_ROOT" --user="$WP_ADMIN" eval-file \
     "$HOME/Downloads/wp-core-blueprint-automations/tools/adv02c-c6-field-audit.php"
   ```

The field audit creates a single synthetic terminal event receipt, v2 run, leased run job and Action attempt. It verifies a no-Action preflight, a **wrong-token stale worker** cannot change evidence, native InnoDB `ROLLBACK` restores the original attempt, the genuine C6 fence atomically quarantines the orphaned Action, and repeated recovery leaves the attempt unchanged. All tests use authenticated joblease SQL. The script cleans up **only its own generated primary keys** in a `finally` block, even on an assertion failure.

**Expected result:** `ADV-02C C6 sandbox InnoDB/lease/recovery FIELD PASS (9 assertions, no providers invoked).` If the script fails, do **not** perform a new Advanced Action run. Record the error and inspect the sandbox for any residual synthetic audit rows before retrying.

## Additional C6-F2 / C6-F3 acceptance evidence (not covered by the automated fixture)

C6-F1 tests real InnoDB and lease mechanics, but **not** a real two-process race or an actual external Action killed mid-invocation. Before declaring C6 **FIELD ACCEPTED**, collect and review:

- **C6-F2:** two independently leased workers attempting the same sandbox run; only the valid lease owner may persist a checkpoint, and a stale owner must fail without committing or replaying the Action. Use an isolated no-op/test provider on a disposable sandbox, not a production-integrated Action.
- **C6-F3:** controlled worker termination after a test Action's durable start but before its acknowledgement; on restart, confirm exactly one Action attempt, `indeterminate` run state, no second Base Action invocation, and safe operator recovery. Do not send real mail, alter posts, charge payments or trigger external webhooks for this test.

**C6 closure:** SOURCE/BUILD and C6-F1 are independent gates. The source test doubles and FIELD probe alone cannot prove multi-process races or a real provider-side effect; C6-F2/F3 remain explicit acceptance requirements. No automatic merge, general activation, release publication, or deployment.