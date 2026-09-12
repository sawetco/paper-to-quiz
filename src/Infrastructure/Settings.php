<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class Settings {
	public const OPTION = 'paper_to_quiz_settings';
	public const GROUP  = 'paper_to_quiz_settings';
	public const CROP_DPI_MIN = 120;
	public const CROP_DPI_MAX = 360;
	public const IMAGE_EDGE_MIN = 1200;
	public const IMAGE_EDGE_MAX = 6000;
	public const PAGE_WARNING_MIN = 20;
	public const PAGE_WARNING_MAX = 1000;

	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __('General Paper to Quiz settings.', 'paper-to-quiz'),
				'sanitize_callback' => array(self::class, 'sanitize'),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	public static function defaults(): array {
		return array(
			'max_pdf_mb'        => 50,
			'retention_days'    => 365,
			'crop_dpi'          => 300,
			'max_image_edge'    => 4000,
			'page_warning'      => 200,
			'network_grace'     => 30,
			'purge_on_uninstall' => false,
		);
	}

	public static function get(): array {
		$value = get_option(self::OPTION, array());
		return array_merge(self::defaults(), is_array($value) ? self::sanitize($value) : array());
	}

	/** @return array<string,array{minimum:int,maximum:int}> */
	public static function constraints(): array {
		return array(
			'crop_dpi'       => array('minimum' => self::CROP_DPI_MIN, 'maximum' => self::CROP_DPI_MAX),
			'max_image_edge' => array('minimum' => self::IMAGE_EDGE_MIN, 'maximum' => self::IMAGE_EDGE_MAX),
			'page_warning'   => array('minimum' => self::PAGE_WARNING_MIN, 'maximum' => self::PAGE_WARNING_MAX),
		);
	}

	public static function sanitize(mixed $value): array {
		$value = is_array($value) ? $value : array();

		return array(
			'max_pdf_mb'        => max(1, min(500, absint($value['max_pdf_mb'] ?? 50))),
			'retention_days'    => max(1, min(3650, absint($value['retention_days'] ?? 365))),
			'crop_dpi'          => max(self::CROP_DPI_MIN, min(self::CROP_DPI_MAX, absint($value['crop_dpi'] ?? 300))),
			'max_image_edge'    => max(self::IMAGE_EDGE_MIN, min(self::IMAGE_EDGE_MAX, absint($value['max_image_edge'] ?? 4000))),
			'page_warning'      => max(self::PAGE_WARNING_MIN, min(self::PAGE_WARNING_MAX, absint($value['page_warning'] ?? 200))),
			'network_grace'     => max(0, min(120, absint($value['network_grace'] ?? 30))),
			'purge_on_uninstall' => (bool) ($value['purge_on_uninstall'] ?? false),
		);
	}
}
