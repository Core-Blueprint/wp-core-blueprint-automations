# W3.1 Workflow Folders

Candidate version: 1.0.0-rc2. Branch: feature/automations-workflow-folders-w31-v1.

## Scope

Site-wide folder management in WordPress Admin: create, rename, delete after explicit confirmation, and assign one folder per workflow. Existing workflows default to Unfiled. Folder selection combines with server-side search, status, sorting and 25/page pagination. Folder changes never update workflow definition, revision, activation, execution principal or Runs.

The feature has two isolated InnoDB tables: `cb_automations_folders` and `cb_automations_folder_assignments` (prefixed per WordPress installation). Schema installation is lazy, behind the authorized Workflows admin view, and uses its own version option `cb_automations_folders_schema_version`. It does not change AU2 execution schema or worker boot. Deletion clears assignments in a transaction and leaves workflows intact.

Folder handlers enforce `manage_options`, action-scoped WordPress nonces and confirmation on deletion. Folder names have a 191-byte limit; at most 1,000 folders are supported.

## Validation (not yet performed locally)

After fetching branch, run `php tests/unit/workflow-folders-w31.php` and `php tests/unit/workflow-folders-query.php` plus the complete existing PHP and JavaScript regressions. Next run `./tools/i18n/update` and `./tools/i18n/check`; the six PO and POT catalogs contain pre-seeded translations, but the official source extractor must rewrite references. Review and commit generated updates. Finally run `bash tools/build-release` and verify `dist/core-blueprint-automations-1.0.0-rc2.zip` against its `.sha256`.

Only install RC2 on backed-up staging after these tests. Disable older active Ghost Post and mail workflows first. Check folder create/move/filter/rename/confirmed-delete with a disabled workflow, including preserved definition, activation and revision. Reject forged nonce and unauthorized mutation. No merges, CI, production or Base changes without separate operator GO.
