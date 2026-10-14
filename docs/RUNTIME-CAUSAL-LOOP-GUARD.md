# Runtime causal loop prevention (Automations)

This is an Automations-owned **internal** guard, not a new public Base API. When an
Action invoked by Automations emits a Base trigger synchronously (for example,
`post.created` from `wp_insert_post`), TriggerIntake captures authenticated
in-process provenance. The lineage is written alongside the event payload to the
existing encrypted event receipt. WP-Cron event materialization restores it into
the encrypted Run context, where the next Action extends the chain.

Before scheduling a workflow whose ID already occurs in its ancestry, or beyond
the bounded 12-workflow chain, EventMaterializer creates a terminal **Skipped**
Run with `runtime.causal_cycle_prevented` or `runtime.causal_depth_limit`
respectively. No action job is created. The error code (not record payload or
customer data) explains the skip in Runs. Unrelated workflows may still respond
to the derived event. A separate external event starts a new lineage.

Existing receipts and runs without ancestry remain valid as independent roots.
Base's immutable TriggerEvent and provider schemas remain unchanged. Ordinary
event deduplication still happens independently by event ID.

## Scope and limitations

- Supported: actions emitting WordPress hooks / Base triggers **synchronously**
  while the governed ActionInvoker is running, including chains continued by a
  separate WP-Cron job; direct (A→A) and indirect (A→B→A) cycles.
- Not automatically attributable: third-party services that schedule a callback
  for another request and emit a trigger later without carrying a trusted origin.
  Those events appear as independent roots. A future signed origin handoff and
  broader budget limits need their own architecture/security review.
- Depth 12 is not a global budget for total sibling events or side effects.
  Operators must not enable untrusted/high-volume automation chains without
  observing Runs. No arbitrary vendor-supplied provenance is accepted.
- No provider action is executed when causal data from authenticated storage is
  invalid. Root user-created posts remain unaffected.
