# AGENTS.md

This file is the current working agreement for people and coding agents in this
repository. Update it in the same change whenever architecture, commands, data
contracts, or release behavior changes.

## Project

- Paper to Quiz (`paper-to-quiz`) is an independent assessment plugin for
  WordPress 6.8+ and PHP 8.1+.
- The administration application uses React, TypeScript, and
  `@wordpress/components`.
- The direct React 18.3.1 development dependencies intentionally match the React
  runtime supplied by supported WordPress Core releases. Keep React externalized
  from production assets, and move React, React DOM, their types, and React-Konva
  together only after the supported WordPress matrix proves a Core React 19
  transition.
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

The package manager is npm. Preserve `package-lock.json`. Use Node.js 26
(the `.nvmrc` and CI default) with npm 11.16+ for the current WordPress
development tooling. Node.js 24.18+ remains accepted within the 24.x
compatibility line; unrelated future majors are not implicitly supported.
React remains externalized at the WordPress React 18 runtime.

Security overrides in `package.json` pin vulnerable transitive development
tools brought in by `@wordpress/scripts` and `@wordpress/env`. Keep overrides
narrow, verify their dependency paths with `npm explain`, and remove them when
the upstream WordPress packages adopt patched ranges. Do not use
`npm audit fix --force` for development-only findings.

Until upstream publishes fixes, `postinstall` applies version-checked depth
guards to braces 3.0.3 and width/precision bounds to sprintf-js 1.0.3.
`npm run security:audit` verifies these guards and rejects every other advisory;
it prints raw npm counts because registry audit still flags the original versions.
The security regression runs hostile inputs in a bounded subprocess. Do not skip
install scripts. These development helpers stay outside the plugin ZIP.
wp-env 12.0.0 uses the simple-git 4 named export upstream. Its two git-source
helpers are pinned by source fingerprints and the exact package version in the
security verifier; do not restore the obsolete wp-env 11.17.0 adapter.
WordPress scripts 37 uses webpack-dev-server 6; keep its reviewed 6.1 override
aligned rather than forcing the older v5 API. No custom SockJS/proxy middleware
is configured by this project.
Jest is installed explicitly and uses `test-unit-jest` with the published
WordPress preset; ESLint uses the flat Jest configuration.
Keep Babel Core 7 and the WordPress Babel preset 8 together for this retained
Jest transformer. Babel 8/preset 9 need a separate tested toolchain migration;
Jest patch updates are grouped and move together.
The webpack React reconciler alias resolves from react-konva so both hoisted
and nested npm layouts preserve the same React 18 reconciler.
The PHP unit CI job builds assets before bootstrapping WordPress. The PHPUnit
bootstrap must fail with a nonzero exit status if WordPress exits before it
finishes loading, and an empty test suite must never pass.
The Quality package check uses the repository's lockfile-installed wp-env in a
separate disposable environment mapped to the extracted ZIP. Do not install an
unlocked global wp-env in this gate. Run Plugin Check with its CLI bootstrap so
runtime checks also run, and fail on any reported error or warning.
Ranking search must also work without optional mbstring case conversion; the PHP
job repeats RankingTest with mb_strtolower disabled.

```powershell
npm ci
npm run security:audit
npm run lint:js
npm run lint:css
npx tsc --noEmit
npm run test:unit -- --runInBand
npm run build
npm run check:build-portability
npm run check:release-metadata
npm run plugin-zip
```

The `files` field in `package.json` is the release archive allowlist. The
production ZIP includes `src-js/`, `build-tools/`, `webpack.config.js`,
`tsconfig.json`, and `third-party-licenses.txt`, plus the contribution and
security documents and the primary README, so human-readable source remains
available during WordPress.org review. The Turkish GitHub README is deliberately
excluded because Plugin Check flags additional root Markdown files; tests,
development-only release/test runners (including the metadata checker and wp-env
matrix runner), development dependencies, and repository metadata also stay out
of the archive.

GitHub Releases and WordPress.org are the versioned distribution channels. The
release planner prepares an App-authored version PR from product changes on
`main`. Required source, package, compatibility and security gates must pass
before automatic merge and publication. `release.yml` downloads the tested ZIP,
creates a draft GitHub release, attests the archive, publishes the same files to
WordPress SVN, verifies the public WordPress.org ZIP, and then publishes the
immutable GitHub release with checksums and a distribution manifest. Do not
replace a published release asset or send untested source directly to SVN.
See `docs/automated-maintenance.md` for recovery and credential configuration.

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
stable `[name].js` filenames for those translatable chunks. Name the shared PDF
editor vendor chunk `admin-pdf-editor-vendors` and include its content hash in
the filename. The PDF.js worker also uses a content-hashed filename so the
browser loads the worker that matches the current PDF.js API after an update.
Anonymous numeric chunk names break this contract.
`npm run check:build-portability` enforces the workspace-path and filename rules.

Encrypted data format and upgrade behavior:

- New participant ciphertext is prefixed with `PTQ2:` and new private files use
  the `PTQ2` file magic. Their HKDF keys use fixed Paper to Quiz v2 domain and
  purpose information derived from the master key, without `wp_salt()`.
- Readers accept unprefixed participant ciphertext and `PTQ1:` participant
  values, plus `PTQ1` files, through explicitly named legacy derivations
  (`secure_auth` for participant data and `auth` for files). Unknown delimited
  markers fail closed, while a legacy base64 value that merely begins with
  `PTQ` remains legacy. A pending migration requires the original WordPress
  salts; do not rotate salts until the Settings health state is complete. No
  legacy writer may be introduced.
- `EncryptionMigration` upgrades existing values through the non-blocking
  `paper_to_quiz_process_encryption_migration` cron event in batches of 25
  participant rows and 5 asset files. It writes and verifies a temporary PTQ2
  file before the database pointer swap, streams every file in bounded chunks,
  and advances its non-autoloaded cursors only after a successful item.
- Migration failures leave PTQ1 data untouched and retain only the record ID,
  record type, and a safe error class. The Settings health response exposes
  only `pending`, `running`, or `complete` plus a failure count. Fresh installs
  initialize as `complete`; interrupted upgrades are restartable and idempotent.
- Each worker retries only a small fixed slice of recorded failures and reserves
  the rest of the bounded batch for fresh IDs, so persistent corrupt records
  cannot starve healthy records behind them. The persisted failure queue is
  capped; an overflow flag forces a full cursor rescan before completion so an
  evicted failure can never be mistaken for migrated data.

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
`build/pdf.worker.*.min.js` (a vendored worker with no translatable strings).
Translations installed from WordPress.org are loaded through WordPress core;
the plugin must not override `load_textdomain_mofile` with bundled catalogs.

Compatibility gates (disposable local wp-env only):

```powershell
npm run test:integration
npm run test:e2e
npm run test:integration:wp68
npm run test:e2e:wp68
```

The readme `Tested up to` field uses the major/minor series (`7.1`); exact
patch versions belong in the compatibility runner and changelog.
The default pair runs on WordPress 7.1.3; the `:wp68` pair proves the minimum
supported WordPress 6.8.8/PHP 8.1 baseline. Each command uses an isolated
`WP_ENV_HOME`, starts and resets a disposable environment, verifies the exact
Core version, and stops the environment afterward. Integration runs both
clean-install regression scripts; E2E creates a guarded synthetic assessment
and exercises authoring, member/guest submissions, release/access gates, and the
mobile ranking list.
The guarded `tests/release-upgrade.php` fixture verifies actual ZIP clean installs
and upgrades from 1.1.6 in an unmapped disposable wp-env; never replace a mapped
source plugin with an older ZIP. The migration branch additionally
runs its MySQL prefix migration regression.
With the disposable WordPress 7.1 environment running on port 8888, set
`PTQ_TEST_CLI_CONTAINER` to its wp-env CLI container and run
`node tests/submission-burst.mjs`. It verifies three simultaneous deadlines and
then concurrent public and personal reads from rankings with over 3,000 entries.
Never run the regression scripts against production or against real data.

The data regression script creates and then removes temporary plugin records:

```powershell
wp eval-file wp-content/plugins/paper-to-quiz/tests/data-regression.php
```

## Product invariants

- Published revisions are immutable; edits use a separate working revision.
- Existing assessments with a source PDF and saved questions can save their
  information and jump directly to publishing checks without opening the PDF
  editor or rewriting the question and answer key data.
- Started attempts remain tied to their original `revision_id`.
- Administrator `paper_to_quiz_*` capabilities are reconciled idempotently for existing active installations.
- Tests are public, timeless, unranked, and repeatable without limit.
- Non-repeatable exams can rank members and guests. Guests are ranked per
  submission, never deduplicated using unverified names or contact information.
- Ranking eligibility belongs to the original attempt revision. Do not backfill
  historical guest results or automatically enable participant lists on upgrade.
- Lists require the separate ranking_list_enabled opt-in, summary/detailed
  results, the exam end and optional release time, and the normal assessment
  access checks/filter. Never expose participant or user IDs, tokens, contact
  fields, or unmasked surnames. Only masked public labels/search projections
  may be stored unencrypted; original identity fields stay encrypted.
- GET assessments/{id}/ranking accepts page (1..1000000), per_page (1..100,
  default 20), and search (up to 80 characters). Search sees only public labels;
  returned ranks remain global. Responses are private/no-store; the independent
  ranking read limit is 120 requests per assessment/IP/minute.
- Preserve schema 1.1.0 in rollback fixes; never downgrade nullable guest rows
  to the old mandatory-user ranking schema.
- Selecting an answer does not write to the server; submission is atomic and idempotent.
- Exam clients request deferred submission through the existing submit route.
  HTTP 202 confirms the validated snapshot and server receipt time are durable.
  The per-site worker grades bounded batches, retains failed snapshots for
  retry, writes each scored answer snapshot in groups, and uses the receipt
  time for duration and deadline eligibility.
  Legacy synchronous callers and test submissions keep their existing contract.
  Ensure WP-Cron runs at least once per minute on sites with few visits. Drain
  the submission queue before rolling back to a release without a worker.
- Result review displays answer text immediately and fetches a protected
  question image only when the student opens that question's image control.
- Refreshes at exam start and result release are spread over several seconds.
  A completed receipt loads the result directly. Public ranking pages calculate
  tied ranks without querying for every unfiltered row, and a personal result
  reads all subject positions together. Ranking data is never shared-cacheable.
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

SQLite local-development contract (1.3.0):

- The wp-env dependency paths additionally pin fast-xml-parser 5.12.0 under
  php-wasm/cli-util and isomorphic-git 1.38.6 under wp-playground/storage for
  GHSA-474x-hrwr-fgx4 and the three isomorphic-git advisories fixed in 1.38.6.
  Remove these narrow development-only overrides after upstream adopts the fixes.
- Support native PHP with SQLite Database Integration 3.1.2+ on local filesystems,
  including WordPress Studio. Do not claim network-filesystem, WebAssembly, or
  production SQLite support. Resolve the active driver/connection, not DB_HOST.
- Database locks use the existing MySQL GET_LOCK names. SQLite uses nonblocking
  flock descriptors beside the actual canonical database file. Never unlink a
  live lock file or substitute a transient, dummy query, or expiring lease.
- START TRANSACTION remains translated by the official driver to BEGIN IMMEDIATE.
  Check begin/commit/rollback results. Temporarily cap the connection busy timeout
  at 100ms, restore it on every exit, and retry safe independent writes/start at
  most three times. Never replay a failed commit or a partially executed transaction.
- Activation, upgrade, and repair share the schema mutex. Contention is transient;
  it must not mark an unverified schema ready or populate the failure cache.
- SQLite REST callbacks also scope direct wpdb operations to the bounded timeout;
  other namespaces and MySQL callbacks keep their existing connection behavior.
- Persist the unique result email outbox row in the finalization transaction;
  mail and public completion hooks run only after commit. No schema bump is
  needed: the current database version remains 1.2.0.
- Native PHP tests may use PTQ_TEST_WP_LOAD instead of the default wp-env path.
  The SQLite concurrency suite additionally requires the disposable site's
  paper_to_quiz_disposable_test option to equal sqlite-1.3.
  Run npm run test:sqlite:concurrency with PTQ_TEST_WP_LOAD and PTQ_PHP_BINARY.
  Never point this runner at a customer site. Test runners stay out of the ZIP.

- A schema change updates `PAPER_TO_QUIZ_DB_VERSION`, installation/update logic,
  uninstall coverage, types, and tests together.
- Schema `1.1.0` (plugin 1.2.0) adds revision `ranking_list_enabled` (default
  off), nullable ranking `wp_user_id`, and masked `public_label`/`public_search`.
  Preserve the unique attempt and revision/member indexes and verify nullability
  and uniqueness before advancing the schema version. The PTQ2 encryption
  migration remains separate from this additive schema upgrade.
- Schema `1.2.0` adds a submission snapshot, retry state, and worker scan index
  to attempts, plus an assessment/token index for restoring a guest result.
  `finish_requested_at` records the server receipt time. The worker
  clears the snapshot only in the transaction that commits final answers,
  scores, and ranking eligibility.
- Historical database-prefix migration requirements belong only to
  `legacy-migration-support`; the clean source still performs the bounded PTQ1
  to PTQ2 encryption upgrade described above.
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

## Autonomous dependency maintenance

See `docs/automated-maintenance.md`. The owner authorized tested minor/patch dependency auto-merges and automatic releases on 29 October 2026. Keep major/API/schema/formula changes under human review. GitHub Actions/workflow updates, including same-major tagged SHA changes, require explicit human review and manual merge; they are never eligible for the automatic queue or urgent-selection override. Never weaken required checks to unblock automation. GitHub Actions owns quality and automation; Vercel Git integration owns web delivery. Vercel CI migration is out of scope.

The October 30 minimatch/glob backports are applied by postinstall and verified by the audit; see `docs/automated-maintenance.md`. Do not skip install scripts or accept these advisories without installed-source and behavioral verification.

The release planner derives distributable paths from the package files allowlist, including build settings, translations and shipped documentation. Keep the path regression aligned with distribution rules; internal workflow-only edits do not require a new plugin version.

Maintenance may waive Release freshness only with exact-head published-baseline and distribution-path evidence; it must retain pending/failed publication attention and all required source-check gates. Run `node --test .github/automation/maintenance-*.check.cjs` for coordinator changes.
