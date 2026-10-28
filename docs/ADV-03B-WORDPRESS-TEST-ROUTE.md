# ADV-03B — WordPress Safe Route Simulation (SOURCE candidate)

Date: 28 October 2026 · Branch: `feature/automations-advanced-ux-test-batch-20261028`

## Product scope
The Advanced Builder has a **Test Workflow** panel that asks for sample node-output JSON and sends a nonce-protected, privileged WordPress POST. It uses `GraphValidator` and the existing `GraphRoutePlanner::trace()` to show a simulated path and the IF/ELSE branch choices.

**This is a read-only route simulation, not actual workflow execution.** State/Action providers are **not** called; no side effects, jobs, run ledger records, checkpoint mutations, remote requests, activation changes, execution-principal rebinding or temporary database tables occur. The UI must clearly say so. Do not present a simulated node as an executed provider action.

## Operator workflow
1. Open a **saved** disabled Advanced workflow in the WordPress Designer.
2. Configure the Trigger, nodes, branches and Merge; save, then reopen.
3. In the Inspector's Test Workflow section, choose **Generate samples** to produce non-sensitive schema-shaped example outputs, then review and edit the JSON if necessary, or enter sample outputs using JSON, for example `{"state":{"decision":true}}` **when** the node ID is `state` and its registered non-sensitive output schema defines a Boolean `decision` field.
4. Click **Test route**. The WordPress UI shows the simulated node sequence and IF/ELSE choices, not output values. To inspect the other Boolean branch, click **Flip Boolean samples** and test again without manually editing JSON.
5. Change the non-sensitive sample fixture and repeat to test the alternate route.
6. A dirty draft, outdated revision, invalid graph or sensitive/unrecognized fixture must be refused. Fix and save before retesting.

The test fixture is POST-only and never persisted or reflected in the returned JSON. The API allows only fields currently present in the registered output schema and refuses fields marked sensitive. The endpoint is for privileged managers only. The maximum serialized fixture is 8192 bytes.

## Technical boundaries
- `src/Admin/AdvancedRoutePreviewController.php` adds one **read-only** `admin_post_cb_automations_preview_advanced` handler with the same manager capability policy as editing, a dedicated nonce, workflow identity, exact server revision and disabled-v2 check.
- The handler independently decodes JSON, runs `GraphValidator`, verifies fixture node IDs and output field schemas, then calls `GraphRoutePlanner::trace`.
- Results consist only of a list of node IDs plus a map of branch decisions. User-supplied sample outputs are never returned.
- Existing Standard workflow UI, runtime admission, CLI sandbox, Action/State provider invocation, Base Designer Shell, save/CAS, graph codec, persistence, and worker remain unchanged.
- Real controlled Advanced test execution with provider mocks, run statuses and step-level errors is **not implemented** by this read-only route-simulation slice. Never label it field-accepted ADV-03B execution until that additional safety and behavior evidence is available.

## Acceptance gates
Canonical `tools/test-regressions`, all six locale checks, PHP/JS syntax, `tools/build-release`, ZIP checksum and WordPress FIELD are pending for this source candidate. Use one consolidated WordPress FIELD round for the batch once the shared safety gates are satisfied. No production activation, merge or public release is authorized.
