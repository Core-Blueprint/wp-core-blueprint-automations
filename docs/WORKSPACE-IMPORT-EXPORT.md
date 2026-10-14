# Workflows Workspace W1 and Offline JSON Transfer W2

W1 separates management, starter templates, and creation into dedicated tabs
within the existing Automations product screen. Workflow list search/status
filters/sort are server-side and applied **before pagination**, using a bounded,
allowlisted SQL query. Existing WordPress capability and activation policies
remain in charge of mutations and execution.

W2 supplies native offline-only JSON Import / Export (no SaaS dependency).
Export contains exactly `format`, `format_version`, `name` and the canonical
`definition`. It never exports runtime data, run history or execution identity.
Import also accepts standalone DefinitionCodec JSON, including the A/B Golden
test fixture files. All imports create a **Disabled** workflow with no execution
principal; the operator must explicitly assign authority and enable it.

Import uses server-side bounded file upload, DefinitionCodec validation, a
user-bound encrypted Vault transient (10 minute preview TTL), and a second
nonce-checked confirmation. The preview deliberately does not show untrusted
literal values or execute actions. Imported missing-provider capability
definitions may be saved as disabled drafts; validation issues are shown before
activation. Sensitive literals are blocked through existing PersistencePolicy.

Export downloads can include non-sensitive literal text configured by users:
operators should review before sharing JSON externally. File upload and
preview remain strictly offline. No Base Template Library private API is used.

W3 will introduce Automations-owned folders/labels and richer operational
filtering; these do not belong in the workflow DefinitionCodec or WordPress CPTs.
