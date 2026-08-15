# Contributing to Paper to Quiz

Thank you for helping improve Paper to Quiz. Keep changes focused, testable,
and compatible with the plugin's data and security contracts.

## Before you start

- Search existing issues before opening a new one.
- Use a public issue for ordinary bugs and features.
- Follow [SECURITY.md](SECURITY.md) for vulnerabilities; never disclose them in
  a public issue.
- Do not include production data, participant information, PDFs, or encryption
  keys in examples or fixtures.

## Local setup

1. Install WordPress 6.8+ and PHP 8.1+.
2. Clone the repository into `wp-content/plugins/paper-to-quiz`.
3. Run `npm ci`.
4. Run `npm run build` and activate Paper to Quiz.

## Required checks

```sh
npm run lint:js
npm run lint:css
npx tsc --noEmit
npm run test:unit -- --runInBand
npm run build
```

The integration gate is an explicit disposable/local-only check:

```powershell
npm run test:integration
```

It must run only against the local wp-env environment. Never run
`tests/data-regression.php` or `tests/rest-regression.php` against production,
and do not use production credentials or real participant data.

For PHP changes, also run PHP syntax checks, PHPCS when available, WordPress
Plugin Check, and the relevant local regression script.

## Pull requests

- Explain the problem and the behavior after the change.
- Add or update tests for behavior changes.
- Keep published revisions immutable and preserve started-attempt revision IDs.
- Never rename `PaperToQuiz`, `PAPER_TO_QUIZ_*`, `paper_to_quiz_`, `paper-to-quiz/v1`, or
  `[paper_to_quiz]` without a complete migration and compatibility plan.
- Update `AGENTS.md`, README, and changelog when architecture, commands, schema,
  or release behavior changes.

Contributions are accepted under GPL-2.0-or-later.
