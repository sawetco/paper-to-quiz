<?php
/**
 * REST cache policy regressions.
 *
 * @package PaperToQuiz\Tests\Unit
 */

declare(strict_types=1);

namespace PaperToQuiz\Tests\Unit;

use PaperToQuiz\Rest\BinaryResponse;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RestCachePolicyTest extends TestCase {
	public function test_wp_rest_cache_excludes_only_paper_to_quiz_after_opt_in(): void {
		$allow_paper_to_quiz = static function (array $endpoints): array {
			$endpoints['paper-to-quiz/v1'] = array('/admin/classes', '/assessments');
			return $endpoints;
		};
		$other_endpoints = array('wp/v2' => array('posts'), 'another/v1' => array('items'));
		add_filter('wp_rest_cache/allowed_endpoints', $allow_paper_to_quiz);
		try {
			$this->assertSame($other_endpoints, apply_filters('wp_rest_cache/allowed_endpoints', $other_endpoints));
		} finally {
			remove_filter('wp_rest_cache/allowed_endpoints', $allow_paper_to_quiz);
		}
	}

	public function test_rest_cache_control_covers_success_and_errors(): void {
		$request = new \WP_REST_Request('GET', '/paper-to-quiz/v1/admin/classes');
		$server = rest_get_server();

		foreach (array(200, 403, 503) as $status) {
			$signals = did_action('litespeed_control_set_nocache');
			$response = apply_filters('rest_post_dispatch', new \WP_REST_Response(array(), $status), $server, $request);
			$this->assertSame($signals + 1, did_action('litespeed_control_set_nocache'));
			$this->assertSame('no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control']);
			$this->assertSame('private, no-store, max-age=0', $response->get_headers()['Cache-Control']);
			$this->assertSame($status, $response->get_status());
		}
	}

	public function test_binary_responses_can_keep_private_browser_caching(): void {
		$request = new \WP_REST_Request('GET', '/paper-to-quiz/v1/attempts/example/questions/1/image');
		$binary = new BinaryResponse('test.bin', 'question_image', 'image/png', 'question.png');
		$private_cache = 'private, max-age=86400, immutable';
		$binary->header('Cache-Control', $private_cache);
		$response = apply_filters('rest_post_dispatch', $binary, rest_get_server(), $request);

		$this->assertSame('no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control']);
		$this->assertSame($private_cache, $response->get_headers()['Cache-Control']);
	}

	public function test_other_rest_namespaces_keep_their_cache_headers(): void {
		$request = new \WP_REST_Request('GET', '/wp/v2/posts');
		$response = new \WP_REST_Response(array(), 200, array('Cache-Control' => 'public, max-age=60'));
		$signals = did_action('litespeed_control_set_nocache');
		$result = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

		$this->assertSame($signals, did_action('litespeed_control_set_nocache'));
		$this->assertArrayNotHasKey('X-LiteSpeed-Cache-Control', $result->get_headers());
		$this->assertSame('public, max-age=60', $result->get_headers()['Cache-Control']);
	}
}
