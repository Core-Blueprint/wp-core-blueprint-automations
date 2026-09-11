# Core Blueprint Automations

Workflow orchestration for capabilities exposed through the Core Blueprint Base Automation Foundation.

## Architecture

Core Blueprint keeps three ownership boundaries deliberately separate:

- **Extensions own business semantics.** Domain plugins declare triggers, read-only state capabilities, and actions through Base.
- **Base owns interoperability.** Base validates and exposes typed capability contracts; it is not a workflow engine.
- **Automations owns orchestration.** Automations stores, validates, and composes workflow definitions without taking ownership of extension domain logic.

WordPress Admin is canonical. The product is builder-agnostic; frontend builders are not dependencies or special cases.

## AU1 workflow model

AU1 uses a linear, versioned workflow definition:

```text
WHEN      Trigger
GET DATA  Optional read-only State lookups
ONLY IF   Optional Conditions
THEN      Actions
```

A durable capability reference contains `kind + provider + id + schema_version`. Workflow steps have their own stable `step_id` so the same capability can appear more than once.

Bindings are deliberately limited to typed literals and prior step outputs. There is no expression language, arbitrary PHP, JSONPath, or hidden fallback behavior.

## Validation and activation

Validation is derived live from the current capability catalog. It is not persisted as canonical truth.

- Disabled drafts may be structurally or semantically incomplete.
- Enabled workflows must validate fully against the current provider and capability contracts.
- An invalid enable attempt preserves the edited definition as **Disabled** and reports that explicitly.
- Missing providers/capabilities and schema drift preserve the stored definition and enter a read-only recovery view; Automations never silently rewrites capability references.
- Sensitive literals are not allowed in persisted workflow definitions. Sensitive values may only move through typed step-output references.

## Persistence

AU1 stores only workflow configuration in `{$wpdb->prefix}cb_automations_workflows`:

- name
- activation intent
- workflow definition version
- versioned definition JSON
- optimistic revision
- creator/updater metadata
- timestamps

There is intentionally no run history, queue, retry state, scheduler state, or execution payload storage in AU1. Deactivation and uninstall are non-destructive.

## Runtime boundary

AU1 does **not** execute automations. It does not subscribe to emitted triggers, invoke Base action executors or state resolvers, schedule cron work, enqueue background jobs, impersonate users, or introduce an execution principal.

Those concerns belong to the separate AU2 execution-security design gate.

## Requirements

- WordPress 7.0+
- PHP 8.4+
- Core Blueprint Base API 1.x with the public Trigger, Action, State, Extension Registry, and Core Admin page contracts

Plugin version remains `1.0.0-rc1` during the AU1 roadmap.
