# Automations | Final historical branch reconciliation

**Date:** 2026-10-19  
**Classification:** DOCS-ONLY reconciliation, SOURCE scope; **not** a new consumer-ABI or WordPress Golden acceptance.  
**Pre-reconciliation canonical main:** `baf2e82d4ee61f1f835eedb8aba3d609accfce43` (installed operator-reported stable; CI push success).  
**Policy:** no legacy/backwards compatibility or obsolete history engine; no code copied from historical branches. Existing Base G1a v2.1 and DC-01..DC-15 are canonical. Do not change Base.

## Branches being retired

| Branch | HEAD | Unique commits vs original main | Result |
| --- | --- | ---: | --- |
| `docs/automations-designer-g1a-audit-20261016` | `054047ed7555116cbe81937a3ed124f8ddaaf7c4` | 2 | Historical report preserved below; all technical findings are timestamped, not active blockers |
| `feature/automations-template-library-t3b-t5-pilot-v1` | `d6a0855bcacee413616eb07e83bee6be1764f30f` | 3 | Earlier ancestor of category branch, no separate code integration |
| `feature/automations-template-category-provider-20261015` | `ccc76ea0a82666f1da585465d5215e779f4aa689` | 4 | Final historical category implementation already present in evolved canonical main; original pilot record archived |

The three original branch tips remain reachable via immutable full SHAs recorded here, but remote branch deletion is a separate operator action. No branch should be marked as deleted before GitHub confirms it.

## Main implementation evidence

GitHub exact blob comparisons between `main@baf2e82d4...` and category branch `ccc76ea0...` show **byte-identical** versions of:

- `src/Template/TemplateSourceAdapter.php` (category-capable Base public adapter, per-actor categories)
- `src/Admin/TemplateManagementController.php` (authorized WordPress actions)
- `src/Template/TemplateSafety.php` (literal stripping)
- `src/Provider/WordPress/TriggerListeners.php` (internal template post event exclusion)
- `assets/js/template-library-bridge.js` (Base modal integration)
- `tests/js/template-library-bridge.test.mjs`

Other overlapping files in current main have developed independently and contain intentional later changes, including the guarded Base category-ABI admission in `TemplateLibraryBridge.php` and the canonical source identity in `StoredTemplateRepository.php`. Do **not** cherry-pick outdated overlapping files, old PO/POT catalogs or either historical commit series.

The current `main` includes the public `TemplateCategorySourceInterface` adapter and actor-visible custom-category registration, the private `cb_auto_tpl` CPT, management, per-record privacy boundaries, safe template creation (disabled/no execution principal), and corresponding SOURCE tests. The earlier category branch itself directly descends from the T3B/T5 pilot head and adds one category facet commit, so there is no separate pilot feature line to integrate.

## Historical record preservation

1. `docs/archive/2026-10-16-designer-g1a-audit-historical.md` — original G1a branch ledger, former Base integration concerns and W3.1 receipt. Historical original at `054047ed...`.
2. `docs/archive/2026-10-15-template-library-t3b-t5-pilot-historical.md` — complete original category-provider T3B/T5 implementation rationale, privacy model, test plan and unfulfilled early acceptance claims. Original at `ccc76ea0...`; contains the earlier pilot's content too.

Both are explicitly marked **ARCHIVED HISTORICAL EVIDENCE**, not instructions, live blockers or valid current approval gates.

## Non-functional nature of this reconciliation

- Only documentation files are added to a child branch of exact `main@baf2e82d...`. No PHP, JS, catalog, Composer, CI, release script, template or runtime configuration changes.
- Existing installed ZIP provenance remains tied to `baf2e82d...` and SHA-256 `00a5057b3821a16fae6131eb67953f7f862d97d8ea5ca1e56520eaa90b63d95f`. A later docs-only main commit does not change the contents of that prior ZIP.
- Main push CI should be recorded separately from the earlier `baf2e82d...` CI run.
- The operator-reported Automations sandbox is stable; full Template Library browser/authorization/facet acceptance and the final Base Golden installed pair remain separate gates. No unearned Golden claim.

## Cleanup gate

After the docs-only reconciliation is merged to main and exact main SHA + changed paths are verified, classify these three historical branches as `RECONCILED / SAFE TO DELETE` in the GitHub cleanup issue. The operator can delete remote branches from the GitHub Branches page. **No deletion of main**, no force push, no automatic release, no database test/migration, no CI workflow edit.
