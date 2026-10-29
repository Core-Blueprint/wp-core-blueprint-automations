# ADV-03 — Functional Ready, one consolidated WordPress FIELD acceptance

**Date:** 29 October 2026  
**Source branch:** `feature/automations-advanced-ux-test-batch-20261028`  
**Paired Base SOURCE:** `2755fd4ef0a54c76b8ed07caf1cbe341eeb393cd`  
**Scope:** Advanced v2 visual authoring and read-only saved-route simulation. **NOT** production Advanced execution, provider invocation or C6 worker-crash acceptance.

## One build, one installation

Once SOURCE QA is complete, run just:

```bash
cd "$HOME/Downloads/wp-core-blueprint-automations"
git fetch origin
git switch feature/automations-advanced-ux-test-batch-20261028
git pull --ff-only
bash tools/adv03-functional-ready
```

The gate verifies immutable Base fixtures, full PHP/JS regression discovery and execution, six locale catalogs, release build, archive integrity and matching SHA-256. It prints exact SOURCE HEAD and package hash. It does not use WP-CLI, Docker, a server database, or the WordPress admin. The resulting ZIP is a **field-test candidate**, not a production release.

## Joint field matrix — all scenarios once, using the same ZIP

| Area | Operator scenario | Acceptance |
|---|---|---|
| Launch | Open saved Advanced v2 via native Base Designer | Shell, panels, Inspector, canvas and Save appear; no browser console error |
| Navigation | Pan empty canvas; middle mouse; Space+drag; Fit to Screen | Node cards and SVG edges remain aligned after scale/pan |
| Nodes | Add and configure Trigger/State/Action/IF/ELSE/Merge/End | Supported types appear, unique Trigger/End respected, graph stays edit-safe |
| Connect | Drag a node output to an unused compatible input | Preview follows pointer; connection saved into same graph document |
| Keyboard connect | Focus output, Enter; focus input, Enter; Escape | Equivalent connection, live announcements; no focus loss |
| Refusals | Occupied target, incompatible port, self-link, cycle | No mutation or history entry; understandable nonmodal feedback |
| Rewire | Drag a previously connected output to another free input | Existing edge ID kept, old path removed, Undo restores old destination |
| Edge UX | Click/keyboard focus edge, select, Delete/Backspace | Selected edge visual state and Inspector; focus not removed by selection |
| Context menu | Right-click node/blank canvas and Shift+F10 | Shared Base flat menu; Configure/Duplicate/Delete/Add work where allowed |
| Duplicate | Duplicate Action; attempt protected Trigger/End | New node ID, no cloned edges, protected nodes unchanged |
| Undo/Redo | Connect, move, duplicate, rewire, delete, undo and redo | Base canonical History only; persisted document matches current state |
| Save/reopen | Save via existing native guarded POST, reopen | Positions, edges, caps and names retained; no false dirty state |
| Validation | Incomplete or semantically invalid graph | Clear validation feedback, never silently activated |
| Preview | Save/reopen disabled valid v2; Generate samples; Test route | Only simulated visited nodes/true branch, no provider side effect |
| Alternate branch | Flip Boolean samples; Test again | Different route with exact chosen edges; unvisited nodes dim |
| Stale results | Edit graph or fixture while preview request is in flight | Late reply cannot overwrite newer edits or preview |
| Standard | Open and edit one Standard v1 workflow | Existing Designer, guarded Save and execution unchanged |
| Exit | Leave with dirty Advanced draft | Shared Base Exit Guard prompts correctly |

**Evidence to retain:** pair of exact Base/Automations SOURCE HEADs, ZIP SHA-256, WordPress version, browser console state, and a pass/fail per row. Record reproducible defects together for **one blocker-only fix batch**. Do not schedule owner tests between source commits.

## Safety classification

- The WordPress preview controller requires manager capability, POST method, nonce, exact stored revision, disabled Advanced v2 draft, valid graph and bounded non-sensitive sample outputs. It returns only graph route IDs and Boolean branch decisions.
- **Route simulated** is not **Action executed**: no actual State/Action providers, jobs, run ledger, side effects or production activation are authorized here.
- Manual +/- zoom is deferred because pinned Base CN-1 exports pan/fit/center but not a public scale setter. Never add a second private zoom engine.
- C6 F1/F2/F3, Golden Final, merge and release remain independent higher assurance gates.
