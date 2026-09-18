# AU3 — WordPress Core Provider

Status: implementation planning baseline for the native WordPress provider.

## Goal

Make Core Blueprint Automations useful on a normal WordPress installation without requiring a domain extension.

The WordPress provider must use the same public Core Blueprint Base Automation Foundation contracts as every other provider:

```text
WordPress hook
→ WordPress provider adapter
→ Base Emitter / TriggerRegistry / StateRegistry / ActionRegistry
→ Automations discovery + validation + runtime
```

There are no direct runtime shortcuts for WordPress-owned capabilities.

## Boundary

The provider exposes automation-relevant WordPress semantics. It does not expose arbitrary WordPress hooks as untyped events.

Every capability must have:

- a stable provider + capability identity;
- a bounded transport schema;
- semantic types where they materially improve binding safety;
- explicit sensitivity metadata;
- deterministic event identity where WordPress exposes enough stable context;
- explicit WordPress capability requirements for State and Action invocation;
- regression coverage against the public Base Automation Foundation.

Provider identity for the built-in Automations WordPress provider:

```text
core-blueprint-automations
```

Capability IDs use dotted lowercase identities.

## Trigger inventory

### Users and authentication

Initial canonical trigger set:

- `user.registered`
- `user.updated`
- `user.deleted`
- `user.role_changed`
- `user.role_added`
- `user.role_removed`
- `user.logged_in`
- `user.logged_out`
- `user.login_failed`
- `user.password_reset`

Primary WordPress sources include:

- `user_register`
- `profile_update`
- `delete_user` / `deleted_user`
- `set_user_role`
- `add_user_role`
- `remove_user_role`
- `wp_login`
- `wp_logout`
- `wp_login_failed`
- password-reset lifecycle hooks

The provider must never expose raw passwords, reset keys, cookies, session tokens, password hashes, or arbitrary raw user-data arrays.

### Posts, pages and custom post types

Initial canonical trigger set:

- `post.created`
- `post.updated`
- `post.status_changed`
- `post.published`
- `post.trashed`
- `post.restored`
- `post.deleted`

The generic post capabilities intentionally apply to public and custom post types where WordPress represents the record as a normal post.

Primary sources include:

- `transition_post_status`
- `post_updated`
- trash / untrash hooks
- final-delete hooks

The adapter must suppress revision/autosave noise where the event would not represent a meaningful content automation event.

### Comments

Initial canonical trigger set:

- `comment.created`
- `comment.updated`
- `comment.status_changed`
- `comment.trashed`
- `comment.restored`
- `comment.deleted`

Primary sources include:

- `wp_insert_comment`
- `edit_comment`
- `transition_comment_status`
- trash / untrash / spam / delete lifecycle hooks

### Media / attachments

Initial canonical trigger set:

- `attachment.created`
- `attachment.updated`
- `attachment.deleted`

Primary sources include:

- `add_attachment`
- `edit_attachment`
- `delete_attachment`

Generated metadata is State, not an unbounded trigger payload.

### Taxonomies and terms

Initial canonical trigger set:

- `term.created`
- `term.updated`
- `term.deleted`
- `object.terms_changed`

Primary sources include:

- `created_term`
- `edited_term`
- `delete_term`
- `set_object_terms`

### WordPress system lifecycle

Initial advanced trigger set:

- `plugin.activated`
- `plugin.deactivated`
- `theme.switched`
- `update.completed`

Primary sources include:

- `activated_plugin`
- `deactivated_plugin`
- `switch_theme`
- `upgrader_process_complete`

These are operational events. Actions that mutate plugin/theme/core lifecycle are deliberately not part of the first WordPress Action set and require a separate privileged-action design.

### Options and metadata

WordPress exposes generic option/meta hooks, but arbitrary option/meta values can be unbounded, sensitive, serialized, or plugin-private.

AU3 therefore does not mirror every option/meta hook into a raw event.

A later advanced capability may expose a deliberately configured key-specific watcher with bounded scalar transport. This is preferable to a generic `option.updated` payload carrying arbitrary values.

### Multisite

Multisite-specific lifecycle capabilities are a conditional WordPress-provider module and are registered only when Multisite is available.

Candidate semantics:

- site created / initialized / deleted
- user added to site / removed from site
- site spam/archive state changed

They remain separate from the single-site baseline.

## State inventory

The first WordPress State set should expose bounded, read-only facts:

- `user.current`
- `post.current`
- `comment.current`
- `attachment.current`
- `term.current`

Candidate semantic field vocabulary includes:

- `wp.user_id`
- `wp.post_id`
- `wp.comment_id`
- `wp.attachment_id`
- `wp.term_id`
- `wp.site_id`
- `wp.post_type`
- `wp.post_status`
- `wp.user_role`
- `wp.taxonomy`

States return selected scalar facts and flat scalar lists only. They never return `WP_User`, `WP_Post`, `WP_Comment`, `WP_Term`, arbitrary metadata maps, or nested domain objects.

## Action inventory

The first WordPress Action set should cover common site automation without introducing plugin/theme/core self-management.

### Users

- `user.update`
- `user.add_role`
- `user.remove_role`

Potential destructive account actions require a separate review before admission.

### Content

- `post.create`
- `post.update`
- `post.change_status`
- `post.trash`
- `post.restore`
- `post.delete`

### Comments

- `comment.change_status`
- `comment.trash`
- `comment.restore`
- `comment.delete`

### Taxonomy

- `term.create`
- `term.update`
- `term.delete`
- `post.set_terms`

### Communication

- `mail.send`

The mail action uses the WordPress mail boundary and accepts a deliberately bounded mail contract. It must not become an arbitrary header/injection surface.

## Capability safety

WordPress callbacks frequently expose objects and large raw arrays. The provider adapter converts them into stable IDs and selected immutable facts before emission.

Examples:

```text
WP_User      → user_id + selected non-secret facts
WP_Post      → post_id + post_type + status + author_id
WP_Comment   → comment_id + post_id + user_id + status/type
WP_Term      → term_id + taxonomy + parent_id
WP_Theme     → stylesheet + name/version where appropriate
```

Sensitive fields such as email addresses are classified with `sensitive: true`.

No password, auth token, nonce, cookie, reset key, filesystem path, arbitrary option value, or arbitrary metadata map crosses the provider transport boundary.

## Workflow Template Foundation

Workflow examples are a product-level Automations contract, not a Base Automation Foundation concern.

Planned lifecycle:

```text
plugin / pack
→ cb_automations_register_workflow_templates
→ WorkflowTemplateRegistry
→ Templates gallery
→ create editable workflow copy
→ normal WorkflowValidator
→ normal activation/runtime
```

A template is immutable provider-owned starter content. Creating from a template produces a normal user-owned workflow definition. Later provider updates do not silently rewrite workflows created from that template.

A template descriptor should include at least:

- provider
- stable template id
- schema/version
- title
- description
- category
- difficulty / audience metadata
- required providers/capabilities
- workflow definition draft
- optional setup prompts for required literal values

Third-party plugins may register templates when Automations is installed without making Automations a mandatory dependency for their ordinary runtime.

## AI workflow composer — later layer

AI is intentionally downstream of the capability and template contracts.

The AI composer must not generate PHP or arbitrary WordPress hook names. It receives a projection of currently available typed capabilities and templates and returns a candidate workflow definition.

The candidate then passes through the same authoritative server-side validator as a manually authored workflow.

```text
Natural-language goal
→ available capability/template projection
→ AI candidate Definition
→ WorkflowValidator
→ user review
→ save / enable
```

This keeps AI replaceable and non-authoritative while preserving the existing permission, schema, semantic-binding and runtime boundaries.

## AU3 implementation order

1. WordPress provider bootstrap and contract tests.
2. User/authentication triggers.
3. Post/content triggers.
4. Comment triggers.
5. Attachment triggers.
6. Taxonomy triggers.
7. WordPress State set.
8. WordPress Action set.
9. Advanced system lifecycle events.
10. Multisite conditional module.
11. Workflow Template Foundation.
12. Official WordPress starter templates.
13. Full runtime/golden/staging closure.

The canonical AU3 goal is broad coverage of meaningful native WordPress automation semantics, not one-to-one exposure of every internal WordPress action/filter.
