# Automations release tooling

## Canonical localization

Use only:

```bash
tools/i18n/update
tools/i18n/check
```

`tools/i18n/check` is the mandatory read-only release gate. Automations does not commit MO files; `tools/build-release` compiles fresh MO catalogs from reviewed PO source inside the staged customer package.

Automations is currently on a localization content hold: the release POT and six reviewed PO catalogs are not yet established. The release builder therefore intentionally fails closed until the canonical gate can pass. Do not bypass this with placeholder or machine-generated release translations.

## Release build

Run:

```bash
bash tools/build-release
```

The builder requires PHP, Node.js, Python 3, WP-CLI with `wp i18n`, GNU gettext `msgfmt`, `rsync`, `zip` and `unzip`.

Release preflight requires canonical localization plus PHP and JavaScript syntax validation. Product unit/regression authority remains in `.github/workflows/ci.yml`; the packaging script does not duplicate that full matrix.

The customer ZIP stages the runtime surface only and rejects `.git`, `.github`, `tests`, `tools`, `docs`, `dist`, `build`, `node_modules` and `vendor`.

## Maintenance

When strings change, use `tools/i18n/update`, review translations, then require `tools/i18n/check`. When runtime paths change, update the release staging/boundary checks together. Do not introduce alternate localization or compatibility entrypoints before launch.
