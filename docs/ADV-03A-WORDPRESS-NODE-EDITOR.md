# ADV-03A: WordPress-native Advanced Node Editor

**Date:** 28 October 2026. **Lane:** owner-visible disabled-draft authoring, not runtime acceptance.

## WordPress acceptance path

1. Install/replace the development ZIP through **WordPress → Plugins → Add New → Upload Plugin**; activate as normal. No WP-CLI, config constants, SQL or developer controls.
2. Open **Core Blueprint → Automations → New automation**. Choose **Advanced** and enter a name.
3. The draft opens on a WordPress overview with a native Base **Design with Core Blueprint** launch button. Click it to open the fullscreen Advanced Designer. The canvas starts with Trigger → End. Add State, IF/ELSE, Action or Merge in the left palette. Drag nodes to arrange the canvas; select one to inspect its capability, input bindings and graph connections in the right sidebar.
4. Choose typed capability options from installed Base providers, configure required inputs, connect ports, choose an IF/ELSE operator and pair an explicit Merge node where needed.
5. Press **Save automation**, leave the page and reopen the workflow from the list. Confirm the same nodes, connections, bindings and positions remain. Review the server-side Workflow health details shown after saving.
6. Open an existing Standard workflow and confirm it still uses the unchanged Standard Designer.

**Execution boundary:** Advanced drafts stay disabled; no general activation, no runtime dispatch and no CLI-only test toggle. A native **Test Workflow** action with safe fixtures and in-UI results is a separate ADV-03B acceptance milestone. Do not mistake ADV-03A authoring for a fully testable Advanced runtime.

## Technical boundaries

- Existing `WorkflowController::create()` remains nonce- and capability-guarded. It now accepts an explicit `mode`, defaults to Standard, and uses the already accepted v2 `GraphDefinition` document codec for Advanced.
- The existing guarded Save/CAS service accepts v2 only when the stored workflow is already v2. Definition-version switching, v2 activation and execution-principal rebinding remain forbidden.
- `AdvancedEditorData` uses registered Base catalog schemas and refuses to project a stored literal whose capability is missing or whose current field is sensitive. Browser integer-safety checks remain active.
- Advanced scripts mount only in their own root; Standard Base Domain Session, Undo/Redo, context switching and Standard Save adapters remain v1-only.
- Connections are typed (next/in or IF true/false and Merge true/false), one connection per port. The server still validates graph cycles, merge pairing, reachability and required inputs; incomplete drafts can be saved but never activated.
- No new database tables or authorization gates, no `wp-config.php` flags, no external provider calls, no new test REST endpoint.

## ADV-03A-R2: Base Designer Foundation and admin theme correction

**Field report:** The first ADV-03A ZIP opened the entire canvas inline on WordPress admin and rendered a light surface inside the dark Core Blueprint admin theme. This was a consumer integration defect, not a WordPress server configuration problem.

**Correction:** The Advanced template now uses the public Base manual launch contract (`data-cb-design-launch-root`, launch context, hidden `data-cb-design-shell`, fullscreen control and primary Save action). An isolated ESM consumer adapter calls Base's exported `createDesignerShell` and registers the Base Exit Guard. Base alone owns the launcher, fullscreen state, responsive rails, and unsaved-change confirmation. Name and workflow-health content remain inside the fullscreen Inspector. The graph still uses its own typed canvas/Inspector and the unchanged nonce/CAS-protected disabled-draft WordPress POST. There is no new v2 execution admission.

**Theme:** Graph-only CSS consumes Base's `--cb-surface-0..3`, `--cb-text*`, `--cb-border`, and `--cb-accent` values. No light fallback surface, no hard-coded alternate dark theme, and no consumer fullscreen overlay.

**Verification:** The branch contains targeted Base launch/theme regression tests. SOURCE/CI, local WordPress build, and installed browser-field acceptance must be recorded independently. The product owner continues to pull the GitHub MCP-pushed SOURCE branch and run the usual local build. No automated ZIP distribution is part of this correction.

## Known ADV-03A limitations

- Canvas is a first authoring slice: typed form Inspector and SVG connections, no Base-native advanced node renderer or full graph Undo/Redo yet.
- New UI-only terms require the full six-locale translation/polish pass before Golden; existing translatable labels reuse the current catalog.
- Workflow validation feedback is refreshed after Save/reopen, not live. Graph-specific untranslated diagnostic codes are shown in addition to the generic message.
- Browser authoring is single-revision and uses a regular WordPress POST with server CAS. Network uncertainty requires reopening the workflow before trying another Save.
- Test execution belongs to ADV-03B. Do not enable v2 runs or include v2 in regular trigger fanout in ADV-03A.

## Evidence gates

- Local new-file `php -l`, `node --check`, focused JS source regressions: record separately from full repository validation.
- Full `tools/test-regressions`, `tools/i18n/check`, `tools/build-release`, ZIP integrity, installed WordPress flow and user acceptance must all be verified before marking a ZIP field-ready. A committed branch alone is not an installable artifact.
- Owner only receives a normal installable WordPress ZIP and WordPress UI test instructions; internal command-line QA belongs to engineering.