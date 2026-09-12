<?php
/**
 * PDF setting boundary tests.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Infrastructure\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SettingsTest extends TestCase {
	private mixed $previous;

	public function setUp(): void {
		parent::setUp();
		$this->previous = get_option(Settings::OPTION, null);
	}

	public function tearDown(): void {
		if (null === $this->previous) {
			delete_option(Settings::OPTION);
		} else {
			update_option(Settings::OPTION, $this->previous, false);
		}
		parent::tearDown();
	}

	public function test_pdf_boundaries_are_clamped_to_the_public_contract(): void {
		$minimum = Settings::sanitize(
			array('crop_dpi' => 119, 'max_image_edge' => 1199, 'page_warning' => 19)
		);
		$this->assertSame(120, $minimum['crop_dpi']);
		$this->assertSame(1200, $minimum['max_image_edge']);
		$this->assertSame(20, $minimum['page_warning']);

		$maximum = Settings::sanitize(
			array('crop_dpi' => 361, 'max_image_edge' => 6001, 'page_warning' => 1001)
		);
		$this->assertSame(360, $maximum['crop_dpi']);
		$this->assertSame(6000, $maximum['max_image_edge']);
		$this->assertSame(1000, $maximum['page_warning']);
	}

	public function test_get_normalizes_previously_stored_out_of_contract_values(): void {
		update_option(
			Settings::OPTION,
			array('crop_dpi' => 600, 'max_image_edge' => 8000, 'page_warning' => 200),
			false
		);

		$settings = Settings::get();
		$this->assertSame(360, $settings['crop_dpi']);
		$this->assertSame(6000, $settings['max_image_edge']);
		$this->assertSame(200, $settings['page_warning']);
	}

	public function test_constraints_match_sanitizer_limits(): void {
		$this->assertSame(
			array(
				'crop_dpi'       => array('minimum' => 120, 'maximum' => 360),
				'max_image_edge' => array('minimum' => 1200, 'maximum' => 6000),
				'page_warning'   => array('minimum' => 20, 'maximum' => 1000),
			),
			Settings::constraints()
		);
	}
}
