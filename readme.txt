=== Paper to Quiz ===
Contributors: sawet
Tags: quiz, exam, pdf, education, test
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn PDF exams and worksheets into image-based quizzes without retyping questions.

== Description ==

Paper to Quiz helps you publish questions from existing PDF documents as online
tests and exams in WordPress.

Upload a PDF, draw around each question, choose the correct answer and points,
then publish the assessment on any page with a shortcode. Because each question
is kept as an image, diagrams, formulas and page layout remain intact.

With Paper to Quiz you can:

* Create tests and scheduled exams from PDF files.
* Organize assessments by class and subject.
* Choose which participant information to request.
* Set answer choices, correct answers, points and time limits.
* Allow guests or require a WordPress account for exams.
* Show results immediately or at a date you choose.
* Review scores and subject-based results in WordPress.
* Send result emails through your WordPress email setup.
* Keep source PDFs and question images in private, encrypted storage.

Paper to Quiz works on your own WordPress site. It does not require an external
account and does not send usage data to the developer.

== Installation ==

1. Install Paper to Quiz from WordPress or upload the plugin ZIP.
2. Activate the plugin.
3. Open Paper to Quiz in the WordPress administration menu.
4. Add your classes and subjects.
5. Create a test or exam, upload a PDF and follow the steps on screen.
6. Add the generated shortcode to the page where the assessment should appear.

Deactivation keeps your assessments and results. Permanent cleanup happens only
if you enable it in Paper to Quiz settings before deleting the plugin.

== Frequently Asked Questions ==

= Do I need to rewrite questions from my PDF? =

No. You select each question directly on the PDF page. Paper to Quiz turns the
selected area into a clear question image.

= What is the difference between a test and an exam? =

Tests are open and repeatable. Exams can have a schedule, time limit, membership
requirement, participation limit and a chosen result release time.

= Are source PDF files public? =

No. Source PDFs and question images are stored privately and encrypted. Students
receive only the question image needed for their current assessment.

= Can students continue after refreshing the page? =

Yes. Unsubmitted answer choices are kept in the same browser for up to seven days.

= Does the plugin send data to the developer? =

No. Paper to Quiz does not include telemetry and does not send site or participant
data to the developer. Result emails use the email service configured in WordPress.

== Privacy ==

Depending on the choices made by the site administrator, an assessment may store
participant details, answers, score, duration, IP address and basic browser
information. Exams that require membership also connect the result to the
participant's WordPress account. Paper to Quiz supports the WordPress personal
data export and erasure tools, and administrators can choose how long results are
kept.

== Development ==

Human-readable source code and build instructions are included with the plugin.
The current development source is available at
https://github.com/sawetco/paper-to-quiz.

== Changelog ==

= 1.1.3 =

* Prevented intermittent blank screens after saving an authoring step.
* Added a recoverable editor error screen that confirms saved changes are safe.
* Prevented stale browser caches from reusing incompatible PDF editor code after an update.

= 1.1.2 =

* Fixed Turkish translations in the test and exam creation workflow.
* Ensured translations load for the code-split admin wizard and PDF question selector.
* Strengthened release packaging so generated translation catalogs are never bundled.

= 1.1.1 =

* Simplified the plugin information and installation guidance.
* Removed compatibility code that is not part of the WordPress.org edition.
* Improved release checks for the public repository.

= 1.1.0 =

* First WordPress.org release.
