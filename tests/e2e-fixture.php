<?php
/**
 * Disposable browser-smoke fixture for wp-env only.
 *
 * @package PaperToQuiz
 */

use PaperToQuiz\Application\AssessmentPurgeService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\TermService;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;

if (
	'local' !== wp_get_environment_type() ||
	'1' !== getenv('PAPER_TO_QUIZ_ALLOW_E2E')
) {
	throw new RuntimeException('The E2E fixture is restricted to disposable local environments.');
}

$db      = new Database();
$storage = new EncryptedStorage();
$assets  = new AssetService($db, $storage);
$purge   = new AssessmentPurgeService($db, $assets);
$title   = 'PTQ E2E Synthetic Fixture';

$cleanup = static function () use ($db, $purge, $title): void {
	$ids = array_map(
		'intval',
		$db->wpdb()->get_col(
			$db->wpdb()->prepare(
				'SELECT DISTINCT assessment_id FROM ' . $db->table('revisions') . ' WHERE title = %s',
				$title
			)
		) ?: array()
	);
	foreach ($ids as $assessment_id) {
		$db->wpdb()->update($db->table('assessments'), array('status' => 'trash'), array('id' => $assessment_id));
		$result = $purge->purge($assessment_id);
		if (is_wp_error($result)) {
			throw new RuntimeException('The E2E assessment fixture could not be removed.');
		}
	}
	$db->wpdb()->query(
		$db->wpdb()->prepare(
			'DELETE FROM ' . $db->table('terms') . ' WHERE slug IN (%s,%s)',
			'ptq-e2e-class',
			'ptq-e2e-subject'
		)
	);
};

$cleanup();
if ('cleanup' === getenv('PTQ_E2E_ACTION')) {
	echo "PTQ_E2E_CLEANUP=complete\n";
	return;
}

$terms   = new TermService($db);
$class   = $terms->save_class('PTQ E2E Class', null, '#2271b1');
$subject = $terms->save_subject('PTQ E2E Subject');
if (is_wp_error($class) || is_wp_error($subject)) {
	throw new RuntimeException('The E2E term fixtures could not be created.');
}

$service = new AssessmentService($db, $assets, $terms, $purge);
$created = $service->save(
	array(
		'type'              => 'test',
		'title'             => $title,
		'description'       => 'Synthetic browser compatibility fixture.',
		'class_id'          => (int) $class['id'],
		'subject_ids'       => array((int) $subject['id']),
		'access_mode'       => 'guest_allowed',
		'options'           => array('A', 'B', 'C', 'D'),
		'total_points'      => 100,
		'allow_repeat'      => true,
		'feedback_timing'   => 'after_submit',
		'result_visibility' => 'summary',
	),
	null,
	1
);
if (is_wp_error($created)) {
	throw new RuntimeException('The E2E assessment fixture could not be created.');
}

$stream  = 'BT /F1 18 Tf 72 720 Td (Paper to Quiz synthetic fixture) Tj ET';
$objects = array(
	'<< /Type /Catalog /Pages 2 0 R >>',
	'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
	'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
	"<< /Length " . strlen($stream) . ">>\nstream\n{$stream}\nendstream",
	'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
);
$pdf     = "%PDF-1.4\n";
$offsets = array(0);
foreach ($objects as $index => $object) {
	$offsets[] = strlen($pdf);
	$pdf      .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 6\n0000000000 65535 f \n";
for ($index = 1; $index <= 5; ++$index) {
	$pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
}
$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

$asset_id = $assets->create_from_string($pdf, 'source_pdf', 'application/pdf');
$attached = $service->set_source_asset((int) $created['assessment']['id'], $asset_id);
if (is_wp_error($attached)) {
	$assets->release($asset_id);
	throw new RuntimeException('The synthetic PDF could not be attached.');
}

echo 'PTQ_E2E_ASSESSMENT_ID=' . (int) $created['assessment']['id'] . "\n";
