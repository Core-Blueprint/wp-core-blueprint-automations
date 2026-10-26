# Automations release tooling

## Canonical localization

Use only:

```bash
tools/i18n/update
tools/i18n/check
```

`tools/i18n/check` is the mandatory read-only release gate. Automations does not commit MO files; `tools/build-release` compiles fresh MO catalogs from reviewed PO source inside the staged customer package.

The repository contains a POT and all six locale PO catalogs (NL, DE, FR, ES, IT, PT). The shared first-party i18n toolchain is pinned to version **1.1.1**; this does not by itself establish release readiness. Verify source/POT/PO alignment with `tools/i18n/check` on the candidate HEAD before building. Any newly introduced messages require checked, meaningful locale translations. Do not bypass a failing check with placeholders.

## Release build

Run:

```bash
bash tools/build-release
```

The builder requires PHP, Node.js, Python 3, WP-CLI with `wp i18n`, GNU gettext `msgfmt`, `zip`, `unzip` and `sha256sum`.

Release preflight requires canonical localization plus PHP and JavaScript syntax validation. Product regression authority is `tools/test-regressions`, called by `.github/workflows/ci.yml`. The packaging script is a separate quality gate and does not duplicate the complete test matrix.

The customer ZIP stages the runtime surface only and rejects `.git`, `.github`, `tests`, `tools`, `docs`, `dist`, `build`, `node_modules` and `vendor`.

## Maintenance

When strings change, use `tools/i18n/update`, review translations, then require `tools/i18n/check`. When runtime paths change, update the release staging/boundary checks together. Do not introduce alternate localization or compatibility entrypoints before launch.

## Full regression matrix and Base pins

The canonical local and CI regression command is:

```bash
bash tools/test-regressions
```

It discovers and runs every `tests/unit/*.php` and `tests/js/*.test.mjs` file, checks exit codes, and rejects skipped or TODO JavaScript tests. The runner **never downloads** dependencies and fails closed when Base pins are missing, changed, dirty or incomplete.

Prepare pinned public Base source only through an explicit, separate command:

```bash
bash tools/prepare-base-fixtures --prepare
bash tools/prepare-base-fixtures --verify
```

By default the three read-only worktrees live under `.cb-base-fixtures/{b1,b2,f02}`, which is excluded from Git and the customer ZIP. To reuse previously accepted worktrees, set `CB_AUTOMATIONS_BASE_FIXTURES_DIR` to their parent directory (containing exactly `b1`, `b2` and `f02`). Existing paths are never overwritten or repaired. Provisioning is intentionally networked, while verification and test execution never fetch or switch revisions.

Pinned commits in `Core-Blueprint/wp-core-blueprint`:

| Fixture | Immutable SHA |
| --- | --- |
| B1 | `ff8d62acf0360025ac23482c08017d99104abe9b` |
| B2 | `9ce68f14c12ceac88d20aa692be8a5bb064c56aa` |
| F02 | `11622c6224862b0b414352b0fda6e895e2cf153c` |
| Domain Session v2 ancestor (available through F02 history) | `5e9d59a2f7ffe384873a6c0650822bbb0e7eb557` |

CI runs the complete matrix on PHP 8.4 and PHP 8.5 using Node.js 22, with Base provisioning as an explicit prerequisite. Run `bash tools/i18n/check` and `bash tools/build-release` separately before submitting a release candidate; a green regression run alone does not establish release readiness.
