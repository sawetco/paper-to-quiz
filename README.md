# Paper to Quiz

Paper to Quiz turns print-ready PDF exams and worksheets into secure,
image-based assessments for WordPress. Administrators select questions
visually—without OCR—then publish public tests or policy-controlled exams.

## Highlights

- Manual PDF question selection with high-resolution source images.
- Immutable published revisions and safe working drafts.
- Offline-friendly browser drafts with one atomic, idempotent submission.
- Public, timeless, unlimited-repeat tests and controlled exam policies.
- Subject scoring, rankings, result documents, and queued result emails.
- Encrypted private storage for source PDFs and question images.
- Shadow DOM student interface isolated from theme styles.
- WordPress privacy exporter, eraser, retention, and opt-in uninstall cleanup.

## Requirements

- WordPress 6.8 or newer
- PHP 8.1 or newer
- Node.js 22 or newer for development

## Development

```sh
npm ci
npm run lint:js
npm run lint:css
npx tsc --noEmit
npm run test:unit -- --runInBand
npm run build
npm run check:build-portability
npm run plugin-zip
```

The integration suite is disposable and local-only. It starts a temporary
`wp-env` site, runs the regression scripts, and stops the environment; never
run regression scripts against production:

```sh
npm run test:integration
```

To reproduce the translation catalogs, start the disposable `wp-env`
environment, build the production assets, and run the following from the
repository root. The generated artifacts are kept in `languages/`. The JSON
map assigns split Webpack chunks to the WordPress admin or student script
handle so WordPress can load one catalog per handle:

```sh
npm run env:start
npm run build
npm run i18n
```

Review the existing `paper-to-quiz-tr_TR.po`, `.mo`, and JSON catalogs after
regeneration. If the production build creates different chunk filenames,
update `paper-to-quiz-json-map.json` before generating JSON catalogs. Do not
run these commands or integration regressions against a production site.

The production archive is generated as `paper-to-quiz.zip`. The package uses
`paper-to-quiz.php` as its bootstrap file and `[paper_to_quiz id="123"]` as
its shortcode.

## Architecture

- `src/Application`: assessment revisions, scoring, attempts, email, and ranking rules.
- `src/Infrastructure`: database schema, encryption, storage, settings, and cleanup.
- `src/Rest`: capability-protected administration and public attempt endpoints.
- `src/Admin`: WordPress administration menu and React application mount.
- `src/Frontend`: shortcode and isolated student application mount.
- `src/Privacy`: WordPress personal-data exporter and eraser integration.
- `src-js/admin`: React/TypeScript administration application.
- `src-js/student`: React/TypeScript student application and IndexedDB drafts.

Published revisions are immutable. Editing creates one working draft while
new participants continue to receive the published revision. Started attempts
remain tied to the revision on which they began.

## Data isolation

Paper to Quiz is isolated under the `PaperToQuiz` PHP namespace, `PTQ_*`
constants, `ptq/v1` REST namespace, and `ptq_` database, option, hook, cookie,
shortcode, and private-storage identifiers.

Source PDFs and question images are encrypted under
`wp-content/uploads/ptq-private`. Production sites should define an independent
secret with at least 32 random bytes:

```php
define('PTQ_PRIVATE_STORAGE_KEY', 'replace-with-an-independent-random-secret');
```

Changing this secret makes existing encrypted assets unreadable. Include it in
the site's secure backup and disaster-recovery process.

## Security and privacy

Please report vulnerabilities privately according to [SECURITY.md](SECURITY.md).
Do not include personal data, production database dumps, or encryption keys in
public issues. The plugin does not send telemetry and uses the site's configured
`wp_mail()` transport for result messages.

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening
an issue or pull request. By contributing, you agree that your contribution is
licensed under GPL-2.0-or-later.

## License

Paper to Quiz is free software licensed under the GNU General Public License
version 2 or later. See [LICENSE](LICENSE).
