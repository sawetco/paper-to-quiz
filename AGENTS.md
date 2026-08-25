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
- `PaperToQuiz`, `PAPER_TO_QUIZ_*`, `paper_to_quiz_`, `paper-to-quiz/v1`, and `[paper_to_quiz]` are permanent
  public identities. Changing them requires a complete migration and backward-
  compatibility plan.

## Important paths

- `paper-to-quiz.php`: plugin bootstrap and version constants.
- `src/`: PHP application, REST, infrastructure, administration, and privacy layers.
- `src-js/admin/`: WordPress administration React application.
- `src-js/student/`: student assessment application.
- `build/`: generated production assets; never edit by hand and do not commit.
- `uninstall.php`: permanent cleanup only when the administrator explicitly opts in.

## Branches and distribution variants

- `main` is the clean WordPress.org source. It installs only the current
  `paper_to_quiz_*` schema and must not contain compatibility code for the
  pre-directory `ptq_*` installation.
- `legacy-migration-support` is the migration-supported source for existing
  private/GitHub installations. It preserves the one-time prefix, option,
  capability, cron, private-storage, and schema/data migration paths.
- Build `paper-to-quiz.zip` from the branch whose behavior is required. Never
  upload a ZIP built from `legacy-migration-support` to WordPress.org.
- Shared product changes land on `main` first and are then cherry-picked or
  reapplied deliberately to `legacy-migration-support`. Do not merge `main`
  wholesale over migration-specific compatibility code.

WordPress.org SVN is a release repository, not a development mirror. Publish
only verified `main` release contents at the root of `trunk/`, create the
matching numeric `tags/X.Y.Z/` tag with `svn copy`, and keep directory artwork
in the top-level `assets/` directory. Never commit ZIP archives, Git metadata,
tests, development dependencies, bundled locale catalogs, or migration-support
code to SVN. The `Stable tag:` in both trunk and tagged `readme.txt` must match
the plugin header version before committing a release.

## Local development

The package manager is npm. Preserve `package-lock.json`.

Security overrides in `package.json` pin vulnerable transitive development
tools brought in by `@wordpress/scripts` and `@wordpress/env`. Keep overrides
narrow, verify their dependency paths with `npm explain`, and remove them when
the upstream WordPress packages adopt patched ranges. Do not use
`npm audit fix --force` for development-only findings.

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
security documents and the primary README, so human-readable source remains
available during WordPress.org review. The Turkish GitHub README is deliberately
excluded because Plugin Check flags additional root Markdown files; tests,
development dependencies, and repository metadata also stay out of the archive.

GitHub Releases is the versioned binary distribution channel. After the
version constants, package metadata, changelog, and POT template are synchronized
and the `main` quality workflow passes, push the matching `v*` tag. The
`.github/workflows/release.yml` workflow rebuilds the production package,
checks production dependencies and path portability, and creates or replaces
the installable `paper-to-quiz.zip` asset on that GitHub release. GitHub
Packages is not used for the WordPress installation ZIP.

PDF.js contains a Node fallback whose `import.meta.url` would otherwise embed
the build machine's absolute path. `build-tools/pdfjs-browser-loader.js`
replaces only that fallback base with a portable URL, and
`npm run check:build-portability` prevents workspace paths from reaching the
compiled assets.

The admin authoring UI is code-split into the stable
`build/admin-wizard.js` and `build/admin-pdf-editor.js` chunks. Register both
as dependencies of `paper-to-quiz-admin` and call `wp_set_script_translations()`
for every translatable chunk so WordPress language-pack JSON is installed
before a lazy module evaluates. Keep explicit `webpackChunkName` values and
`chunkFilename: '[name].js'`; anonymous numeric chunk names break this contract.

WordPress.org distributes locale files through translate.wordpress.org. Do not
ship `.po`, `.mo`, locale `.json`, or translation `.php` files in the plugin
archive. Keep only the source template at `languages/paper-to-quiz.pot`.

The default flow uses the wp-env wp-cli (no external tools required). With
`npm run env:start` running and after `npm run build`:

```powershell
npm run i18n
```

This regenerates the POT template in the cli container. `make-pot` needs a
raised PHP memory limit to parse the large bundled JS without fataling on the
128 MB default, so the script invokes it as
`php -d memory_limit=512M ... wp i18n make-pot` and excludes
`build/pdf.worker.min.js` (a vendored worker with no translatable strings).
Translations installed from WordPress.org are loaded through WordPress core;
the plugin must not override `load_textdomain_mofile` with bundled catalogs.

Integration gate (disposable local wp-env only):

```powershell
npm run test:integration
```

On `main`, this starts a disposable local WordPress environment, resets both
wp-env databases, installs WordPress from scratch, runs both clean-install
regression scripts with the required local guards, and stops the environment
afterward. The migration branch additionally runs its MySQL prefix migration
regression.
Never run the regression scripts against production or against real data.

The data regression script creates and then removes temporary plugin records:

```powershell
wp eval-file wp-content/plugins/paper-to-quiz/tests/data-regression.php
```

## Product invariants

- Published revisions are immutable; edits use a separate working revision.
- Started attempts remain tied to their original `revision_id`.
- Administrator `paper_to_quiz_*` capabilities are reconciled idempotently for existing active installations.
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

## Data and schema

- A schema change updates `PAPER_TO_QUIZ_DB_VERSION`, installation/update logic,
  uninstall coverage, types, and tests together.
- `main` starts from database schema version `1.0.0` and contains no historical
  data migration. Add future migrations only for WordPress.org versions
  released after this baseline.
- Historical migration requirements belong only to
  `legacy-migration-support`.
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
