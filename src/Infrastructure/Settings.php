<?php

declare(strict_types=1);

namespace PaperToQuiz\Infrastructure;

final class Settings {
	public const OPTION = 'ptq_settings';

	public function register(): void {
		register_setting(
			'ptq_settings',
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

	public static function sanitize(mixed $value): array {
		$value = is_array($value) ? $value : array();

		return array(
			'max_pdf_mb'        => max(1, min(500, absint($value['max_pdf_mb'] ?? 50))),
			'retention_days'    => max(1, min(3650, absint($value['retention_days'] ?? 365))),
			'crop_dpi'          => max(120, min(600, absint($value['crop_dpi'] ?? 300))),
			'max_image_edge'    => max(1200, min(8000, absint($value['max_image_edge'] ?? 4000))),
			'page_warning'      => max(20, min(1000, absint($value['page_warning'] ?? 200))),
			'network_grace'     => max(0, min(120, absint($value['network_grace'] ?? 30))),
			'purge_on_uninstall' => (bool) ($value['purge_on_uninstall'] ?? false),
		);
	}
}
