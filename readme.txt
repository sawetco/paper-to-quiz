=== Paper to Quiz ===
Contributors: sawet
Tags: quiz, exam, pdf, education, test
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert PDF exams and worksheets into secure, image-based WordPress quizzes without OCR.

== Description ==

Paper to Quiz lets administrators select questions directly from PDF pages,
assign subjects, answer keys, and points, then publish them as tests or exams.
Question text, formulae, diagrams, and answer choices stay together as one
high-quality image.

Key features:

* Manual PDF question selection without OCR.
* High-resolution private question assets and list thumbnails.
* Immutable published revisions with safe draft editing.
* Public, timeless, unranked, unlimited-repeat tests.
* Policy-controlled exams with server-authoritative schedules.
* Seven-day IndexedDB answer drafts and one atomic, idempotent submission.
* Per-subject scoring, rankings, result documents, and queued result email.
* Encrypted private storage for source PDFs and question images.
* Theme-isolated Shadow DOM student interface.
* WordPress privacy exporter, eraser, retention, and opt-in uninstall cleanup.
* `[paper_to_quiz id="123"]` shortcode.

Paper to Quiz operates only on its own data and does not send telemetry to an
external service.

== Installation ==

1. Upload the `paper-to-quiz` folder to `/wp-content/plugins/` or install the ZIP.
2. Activate “Paper to Quiz” in WordPress.
3. Create class and subject records under the Quiz menu.
4. Create an exam or test and complete the guided workflow.
5. Add the generated `[paper_to_quiz]` shortcode to a page.

For production, define `PTQ_PRIVATE_STORAGE_KEY` with at least 32 random bytes
in WordPress configuration. Deactivation preserves data. Permanent uninstall
cleanup runs only when explicitly enabled under Settings > Danger Zone.

== Frequently Asked Questions ==

= Is the source PDF exposed to students? =

No. Source PDFs and question images are encrypted in private storage. Student
requests receive only the authorized question asset for the active attempt.

= Does selecting an answer send a request? =

No. Non-personal answer drafts stay in IndexedDB for up to seven days. The full
answer set is saved and scored in one idempotent submission.

= Can membership be required? =

Exams can allow guests or require WordPress membership. Trusted rankings require
membership and a single-attempt policy. Tests are always public and repeatable.

= Does the plugin collect telemetry? =

No. Paper to Quiz does not send usage or site data to the developer. Result
emails use the WordPress site's configured `wp_mail()` transport.

== Privacy ==

Depending on administrator settings, an attempt may store participant fields,
answers, duration, score, IP address, and basic browser information. Membership-
required exams associate the WordPress user ID with the result. WordPress privacy
export and erasure tools are supported. Administrators control retention globally.

== Development ==

Human-readable JavaScript and stylesheet source is included in `src-js` with
the dependency manifest, build tools, and build configuration. Build
instructions, the security policy, and contribution guidelines are included in
`README.md`, `SECURITY.md`, and `CONTRIBUTING.md`.

== Changelog ==

= 1.0.0 =

* Initial public release of Paper to Quiz.
* Added secure PDF question selection, immutable revisions, atomic submissions,
  class colors, subject reporting, result email, and isolated student UI.
