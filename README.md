# Core Blueprint Automations

WordPress-native workflow orchestration for capabilities exposed through the Core Blueprint Base Automation Foundation.

## Architecture

Core Blueprint keeps three ownership boundaries deliberately separate:

- **Extensions own business semantics.** Domain plugins declare triggers, read-only State capabilities and Actions through Base.
- **Base owns interoperability.** Base validates and exposes typed capability contracts; it is not a workflow engine.
- **Automations owns orchestration.** Automations authors, validates and executes workflows without taking ownership of extension domain logic.

WordPress Admin is canonical. The product is builder-agnostic; frontend builders are not dependencies or special cases.

## Workflow model

Automations uses a linear, versioned workflow definition:

```text
WHEN      Trigger
GET DATA  Optional read-only State lookups
ONLY IF   Optional Conditions
THEN      Actions
```

A durable capability reference contains `kind + provider + id + schema_version`. Workflow steps have stable identifiers so the same capability can appear more than once. Bindings are deliberately limited to typed literals and prior step outputs; there is no arbitrary PHP, expression language or hidden fallback behavior.

## Validation and activation

Validation is derived live from the current capability catalog and is not persisted as canonical truth.

- Disabled drafts may be structurally or semantically incomplete.
- Enabled workflows must validate fully against the current provider and capability contracts.
- An invalid or unauthorized enable attempt is persisted as **Disabled** and reports why it could not be enabled.
- Execution authority is an explicit WordPress user binding and is re-checked against provider-required capabilities.
- Missing providers/capabilities and schema drift preserve the stored definition and enter a read-only recovery view; Automations never silently rewrites capability references.
- Sensitive literals are not allowed in persisted workflow definitions. Sensitive values may only move through typed step-output references.

## AU2 runtime

Enabled workflows execute through a durable runtime pipeline:

```text
Trigger
→ encrypted receipt
→ event job
→ immutable run snapshot
→ run job
→ State
→ Conditions
→ Actions
→ run history
```

Runtime payloads and step outputs are stored in the encrypted Runtime Vault rather than exposed through run history. Jobs use leases and run-level fencing so stale workers cannot acknowledge or overwrite work owned by a newer worker. Worker failures use bounded infrastructure retry; mutating Action outcomes that cannot be proven are never blindly replayed.

Terminal run states are succeeded, skipped, failed and cancelled. Blocked and indeterminate/outcome-unknown runs halt automatic execution while remaining non-terminal so operator recovery can preserve the original evidence and resolve the run explicitly.

## Run history and operator recovery

The Runs screen exposes metadata-only operational history: workflow snapshot identity, execution principal, timestamps, machine codes and step attempt metadata. Runtime values remain excluded.

If a mutating Action entered an indeterminate state, a separately authorized operator can record one of three explicit decisions after independently verifying the external outcome:

- confirmed succeeded;
- confirmed did not occur, allowing a new Action attempt;
- abandon unresolved without replaying the mutation.

Recovery decisions are append-only evidence. They do not rewrite the original run or silently change execution-principal semantics.

## Persistence

Automations persists workflow definitions plus AU2 runtime records for encrypted event intake, durable jobs, immutable run snapshots, step attempts, run leases and operator recovery evidence. Deactivation and uninstall remain non-destructive unless an explicit product lifecycle policy says otherwise.

## Requirements

- WordPress 7.0+
- PHP 8.4+
- Core Blueprint Base API 1.x with the public Trigger, Action, State, Extension Registry, Core Admin and Designer contracts used by the current build

Plugin version remains `1.0.0-rc1` during the pre-v1 roadmap.
