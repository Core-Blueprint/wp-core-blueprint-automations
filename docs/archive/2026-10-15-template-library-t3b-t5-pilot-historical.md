> **ARCHIVED HISTORICAL EVIDENCE — NOT CURRENT IMPLEMENTATION AUTHORITY**
>
> Preserved verbatim from `docs/TEMPLATE-LIBRARY-T3B-T5-PILOT.md` at exact source branch commit `ccc76ea0a82666f1da585465d5215e779f4aa689` during the 2026-10-19 Automations branch reconciliation. The historical status, missing-API claims, proposed changes, permissions and old SHAs below describe the *2026-10-15/16 state only*. They are superseded by the current `main` (pre-archive runtime source `baf2e82d4ee61f1f835eedb8aba3d609accfce43`), subsequent Base G1a v2.1 consumer work and the current Designer Consumer Standard v1. **Do not replay commands or port old source from this record.** No legacy compatibility code is authorized.

---

# Template Library T3B + T5 pilot | Automations consumer

**Status:** feature implementation candidate, **not merged**, full Level 1/WordPress/browser field/release gates pending.

**Base dependency:** Core Blueprint Base branch
`feature/base-template-library-t3-delegated-management-v1`
at `9dc56711bc459ec20f77f536ad1820ac706a8c32`, not yet
published/merged or WordPress Level 2 accepted. Its T0–T2 ancestor
`15dafa47ad043498b73187e546970cd540da3d9a` passed operator-run
Level 1. This Automations branch must be installed/tested with matching
Base candidate, not Base main.

## Technical layout

- `TemplateLibraryBridge` attaches `TemplateSourceAdapter` at Base
  `core_blueprint_register_template_sources`. It uses registered extension
  `core-blueprint-automations`, Designer `workflow`, source ID
  `core-blueprint-automations-workflows`. No extension or Designer switching.
- The provider projects only `TemplateMetadata` with per-user permission
  and stable cursor paging; existing three WordPress StarterTemplates are
  carried unchanged in the same provider as saved templates. No workflow
  document, actor, credential or definition is sent to Base or the browser.
- `StoredTemplateRepository` registers one private, non-REST, hidden
  `cb_auto_tpl` post type in **Automations**. No custom SQL schema migration,
  Base document store or external storage. `_cb_auto_tpl_scope` is either
  `personal` (owner only) or `site` (capability-gated operator).
  SQL search filters title/description, ownership and scope BEFORE paging
  with a 48-item hard cap. Actor and item permission are checked again on GET.
- Internal `cb_auto_tpl` WordPress events are excluded in
  `TriggerListeners::is_content_post`. Merely managing templates can never
  enqueue normal `post.created`/`post.updated` workflow jobs.
- Browser actions use `@cb-core/template-browser` and a Base-issued
  session-bound DesignerScope; only expected source/extension/Designer and
  same-origin admin-post URLs can trigger the **existing** POST
  `cb_automations_create_from_template`. The handler ignores client metadata
  as authority and re-resolves its registered built-in or actor-visible saved
  definition before calling `WorkflowService::create`.
- `WorkflowRepository::create` enforces
  `ActivationState::Disabled` and `execution_principal_user_id=0`. There
  is no silent execution authority or automatic Run.
- `TemplateSafety::scrub` drops every literal step binding and conditions
  with literal operands, retaining only typed step-output references. This
  conservatively removes literals that might contain credentials, recipients,
  private messages or customer data, even when the schema does not flag them
  as sensitive. New drafts may therefore require the operator to restore
  missing inputs before manually enabling. Save/duplicate/export/instantiate
  apply this scrub; existing source workflows stay unchanged.
- Save, Rename, Duplicate, Delete, Export JSON use dedicated
  `admin-post.php` POST handlers with current-user capability, per-action
  nonces and own-record policies. Save as personal requires workflow creator
  identity. Site templates require `manage_options`; duplicating always
  makes a **personal** copy. Export produces a locally downloaded declarative
  JSON with only sanitized definition; no T4 ZIP importer/signatures exist.
- Core Admin overview keeps the existing three starter cards as a no-JS,
  pre-acceptance fallback; Browse templates adds the scalable modal.
  The workflow editor gets Browse templates and Save as template.
  Preview and management stay in Automations' canonical admin page, not Base.

## Operator acceptance the following morning (no real sends)

1. Ensure current Base branch matches the T3A candidate and Automations
   feature matches this document's reported exact HEAD. Do not merge.
2. Read-only Automations source gates: PHP 8.4+ `php -l` on changed
   PHP files; `node --check assets/js/template-library-bridge.js`;
   `node --test tests/js/template-library-bridge.test.mjs`;
   `php tests/unit/template-library-t3b-contract.php`;
   existing workflow-template/privacy/provider unit tests.
3. Run `bash tools/i18n/check`; all six PO catalogs should contain the
   new strings. Only after source green, run the repository's **canonical**
   `bash tools/build-release` to produce a **candidate** ZIP and SHA
   sidecar in `dist/`. Build execution creates/overwrites its own generated
   dist artifacts and uses a generated temporary staging folder, NOT the
   operator's WordPress test root. Confirm this side-effect before running.
4. On an **already-approved staging install** of matching Base+Automations,
   without resetting databases, verify the existing three starters and
   workflow create are untouched, then use Browse templates in overview
   and Designer. Select a starter; new independent workflow must be
   DISABLED with no principal or automatic Run.
5. From an operator-owned workflow choose Save as template PERSONAL;
   verify it appears in Base global Library's My templates, and in
   Designer modal within the workflow scope; preview, rename, duplicate,
   Export JSON and delete through authorized POST only. Reuse to create
   a new independent DISABLED workflow; verify no literal recipient,
   password, message or customer data in exported JSON.
6. Save an explicit SITE template, verify operator visibility and
   separate management permission; a second user cannot browse personal
   templates, cannot read their metadata/GET management page or mutate via
   forged nonce/IDs. Subscriber cannot acquire a scope token.
7. Verify 0/1/1,000+ templates with search/origin pagination, cursor
   continuations and no data loss; desktop/mobile keyboard, focus,
   dark/light, six locales, RTL and no-JS fallback.
8. Canonical isolated WordPress Level 2 integration and release/field
   acceptance remain separate; **never** reuse/reset an unproven workspace
   or schema based on `CB_BASE_TEST_SLOT`.

## Explicitly pending

Actual runtime WordPress E2E, full Base T3A integration gate, browser QA,
artifact checksum evidence, T4 hostile-ZIP import, human-reviewed
translation phrasing and consumer-API stabilization. The feature candidate
must not be described as Golden/release-ready until proven.

No merge, CI, marketplace/vendor calls, deployment or data cleanup without
explicit user approval. No commands containing `exit` are to be copied
into the operator's interactive terminal.

## Follow-up: category facets (2026-10-15)

The additive optional Base `TemplateCategorySourceInterface` is now implemented
by Automations' source adapter. It advertises categories from registered
starter templates and advertises `custom` only when the current actor can see
at least one saved template. No document, owner identity, secret or count is
sent to Base. Requires a compatible Base candidate with category facets.
