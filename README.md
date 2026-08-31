# Paper to Quiz

[Türkçe](https://github.com/sawetco/paper-to-quiz/blob/main/docs/README.tr.md) · **English**

[![Latest release](https://img.shields.io/github/v/release/sawetco/paper-to-quiz?label=release)](https://github.com/sawetco/paper-to-quiz/releases/latest)
[![Quality](https://github.com/sawetco/paper-to-quiz/actions/workflows/quality.yml/badge.svg)](https://github.com/sawetco/paper-to-quiz/actions/workflows/quality.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

If you already have an exam or worksheet as a PDF, entering every question
into WordPress again does not make much sense. Paper to Quiz lets you select
the questions directly from the PDF and publish them as an online test or exam.

It does not use OCR. Formulas, tables, diagrams, and answer choices stay exactly
as they appear in the original document.

## What can you do with it?

- Create a question by selecting any area on a PDF page.
- Add a subject, correct answer, and score to each question.
- Prepare the assessment as either a test or an exam.
- Add dates, membership requirements, and attempt limits to exams.
- View per-subject scores, result documents, and rankings.
- Send results through the WordPress email system.
- Publish an assessment on any page with the generated shortcode.

Source PDFs and question images are encrypted in private storage. The student
screen is isolated from WordPress theme styles, so it keeps its intended layout.
The plugin does not send usage data or telemetry to the developer.

## How does it work?

1. Upload an exam or worksheet PDF.
2. Select the questions directly on the PDF pages.
3. Set the correct answers, subjects, and scores.
4. Complete the test or exam settings.
5. Add the generated shortcode to a WordPress page.

## Test or exam?

| Test                            | Exam                                         |
| ------------------------------- | -------------------------------------------- |
| Public and always available     | May be available only between specific dates |
| Can be repeated without a limit | Can be limited to one attempt                |
| Does not include rankings       | May include member rankings                  |
| Suited to practice and revision | Suited to controlled assessment              |

## Installation

Install and update Paper to Quiz through WordPress by searching for **Paper to
Quiz** under **Plugins → Add New Plugin**, or use the [official WordPress.org
plugin directory](https://wordpress.org/plugins/paper-to-quiz/).

For a manual installation, download the installable `paper-to-quiz.zip` from
the [latest GitHub release](https://github.com/sawetco/paper-to-quiz/releases/latest).
In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, upload the
ZIP, and activate Paper to Quiz.

### About the encryption key

You do not need to create an encryption key yourself. Paper to Quiz securely
generates the key it needs and stores it in the WordPress database. For most
installations, there is nothing else to configure.

Advanced users who prefer to keep the key outside the database may optionally
add the following constant to `wp-config.php` **before uploading the first PDF**:

```php
define('PAPER_TO_QUIZ_PRIVATE_STORAGE_KEY', 'a-random-and-secret-value-of-at-least-32-bytes');
```

If you use this method, keep the value in a secure backup and never change it.
Adding the constant after files have already been created with the automatic
key, or changing the key later, may make existing encrypted files unreadable.

Deactivating the plugin does not remove its data. Permanent cleanup runs only
when an administrator explicitly enables it in the Danger Zone before deleting
the plugin.

## Requirements

- WordPress 6.8 or newer
- PHP 8.1 or newer
- Node.js 22 or newer for development only

## Security and privacy

Depending on the exam settings, attempts may contain participant details,
answers, duration, scores, IP addresses, and basic browser information. The
WordPress personal-data export and erasure tools are supported.

If you find a security issue, please report it privately by following
[SECURITY.md](SECURITY.md). Do not add real user data, database backups, PDFs,
or encryption keys to public issues.

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

Run the local integration suite with:

```sh
npm run test:integration
```

Never run these tests against a production site or real data. If you would like
to contribute, see [CONTRIBUTING.md](CONTRIBUTING.md). The technical working
rules for this repository are documented in [AGENTS.md](AGENTS.md).

## License

Paper to Quiz is free software licensed under the GNU General Public License
version 2 or later. See [LICENSE](LICENSE) for details.
