# AGENTS.md

This file is the current working agreement for people and coding agents in this
repository. Update it in the same change whenever architecture, commands, data
contracts, or release behavior changes.

## Project

- Paper to Quiz (`paper-to-quiz`) is an independent assessment plugin for
  WordPress 6.8+ and PHP 8.1+.
- The administration application uses React, TypeScript, and
  `@wordpress/components`.
- PDF questions are selected manually with PDF.js, Konva, and React-Konva.
- The student application is isolated from theme styles.
- `PaperToQuiz`, `PTQ_*`, `ptq_`, `ptq/v1`, and `[paper_to_quiz]` are permanent
  public identities. Changing them requires a complete migration and backward-
  compatibility plan.

## Important paths

- `paper-to-quiz.php`: plugin bootstrap and version constants.
- `src/`: PHP application, REST, infrastructure, administration, and privacy layers.
- `src-js/admin/`: WordPress administration React application.
- `src-js/student/`: student assessment application.
- `build/`: generated production assets; never edit by hand and do not commit.
- `uninstall.php`: permanent cleanup only when the administrator explicitly opts in.

## Local development

The package manager is npm. Preserve `package-lock.json`.

```powershell
npm ci
npm run lint:js
npm run lint:css
npx tsc --noEmit
npm run test:unit -- --runInBand
npm run build
npm run check:build-portability
npm run plugin-zip
```

The `files` field in `package.json` is the release archive allowlist. The
production ZIP includes `src-js/`, `build-tools/`, `webpack.config.js`,
`tsconfig.json`, and `third-party-licenses.txt`, plus the contribution and
security documents, so human-readable source remains available during
WordPress.org review; tests, development dependencies, and repository metadata
stay out of the archive.

PDF.js contains a Node fallback whose `import.meta.url` would otherwise embed
the build machine's absolute path. `build-tools/pdfjs-browser-loader.js`
replaces only that fallback base with a portable URL, and
`npm run check:build-portability` prevents workspace paths from reaching the
compiled assets.

Translation catalogs are generated after the production build. Keep the
compiled-bundle references in `languages/paper-to-quiz.pot`, the Turkish PO/MO
files, and the handle map synchronized.

The default flow uses the wp-env wp-cli (no external tools required). With
`npm run env:start` running and after `npm run build`:

```powershell
npm run i18n
```

This runs the four steps in the cli container: `make-pot`, `update-po`,
`make-mo`, and `make-json --use-map`. `make-pot` needs a raised PHP memory
limit to parse the large bundled JS without fataling on the 128 MB default, so
the script invokes it as `php -d memory_limit=512M ... wp i18n make-pot` and
excludes `build/pdf.worker.min.js` (a vendored 1.19 MB worker with no
translatable strings).

`languages/paper-to-quiz-json-map.json` maps each lazy chunk (`build/0.js`,
`build/115.js`, `build/244.js`) and entry bundle to its parent bundle. The
values intentionally use the `.min.js` suffix: WP-CLI `make-json` strips
`.min.js` to `.js` before naming each output `textdomain-locale-{md5}.json`, so
`build/admin.min.js` produces the `md5("build/admin.js")` handle that WordPress
loads. (With WP-CLI 2.12.0 the `.min`-strip regex has an unescaped dot, so a
non-`.min` value like `build/admin.js` is corrupted to `build/a.js` — keep the
`.min` suffix.) CI validates that every map key exists under `build/` after the
build (`.github/workflows/quality.yml`); if Webpack changes chunk filenames,
update the JSON map before regenerating the catalogs.

Integration gate (disposable local wp-env only):

```powershell
npm run test:integration
```

This starts a disposable local WordPress environment, runs both regression
scripts with the required local guards, and stops the environment afterward.
Never run the regression scripts against production or against real data.

The data regression script creates and then removes temporary plugin records:

```powershell
wp eval-file wp-content/plugins/paper-to-quiz/tests/data-regression.php
```

## Product invariants

- Published revisions are immutable; edits use a separate working revision.
- Started attempts remain tied to their original `revision_id`.
- Administrator `ptq_*` capabilities are reconciled idempotently for existing active installations.
- Tests are public, timeless, unranked, and repeatable without limit.
- Selecting an answer does not write to the server; submission is atomic and idempotent.
- Access, schedule, and submission decisions use server UTC, never client time.
- Source PDFs and question images remain in encrypted private storage.
- Each revision stores its selected subject IDs; every question subject must be
  one of those selected subjects before the revision can be published.
- Permanent deletion affects only the target and dependencies it owns; it never
  archives, trashes, or deletes shared class and subject records.
- Privacy anonymization removes the attempt's user link and ranking identity
  while preserving the anonymous assessment record.
- Result-email workers send only jobs whose conditional claim update they won;
  a job claimed by another worker must never be sent by the losing worker.

## Data and migrations

- A schema change updates `PTQ_DB_VERSION`, install/migration logic, uninstall
  coverage, types, and tests together.
- Check every database write and use transactions for multi-table operations.
- Convert unique-index conflicts into useful field-level REST errors; never leak
  raw database failures as a generic 500 response.
- Never run unverified destructive repair logic against production data.

## Code and security

- Sanitize and validate input early; escape output at the final rendering boundary.
- Preserve capability and nonce checks together on state-changing REST routes.
- Use `$wpdb->prepare()` and explicit allowlists for dynamic database operations.
- Never expose personal data, private keys, or internal error details to clients.
- Preserve unrelated worktree changes.

## Completion gates

Behavior changes require JS lint, style lint, TypeScript, unit tests, and a
production build. PHP changes also require syntax checks, Plugin Check/PHPCS as
applicable, and the relevant local REST regression. A production behavior is
verified only after checking the actual production environment.
