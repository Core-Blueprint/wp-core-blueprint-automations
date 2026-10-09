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

Release preflight requires canonical localization plus PHP and JavaScript syntax validation. Product unit/regression authority remains in `.github/workflows/ci.yml`; the packaging script does not duplicate that full matrix.

The customer ZIP stages the runtime surface only and rejects `.git`, `.github`, `tests`, `tools`, `docs`, `dist`, `build`, `node_modules` and `vendor`.

## Maintenance

When strings change, use `tools/i18n/update`, review translations, then require `tools/i18n/check`. When runtime paths change, update the release staging/boundary checks together. Do not introduce alternate localization or compatibility entrypoints before launch.
