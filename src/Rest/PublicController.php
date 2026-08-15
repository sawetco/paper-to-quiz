<?php

declare(strict_types=1);

namespace PaperToQuiz\Rest;

use PaperToQuiz\Application\AttemptService;

final class PublicController {
	public function __construct(private readonly AttemptService $attempts) {
	}

	public function register_routes(): void {
		register_rest_route(
			'paper-to-quiz/v1',
			'/assessments/(?P<id>\d+)/bootstrap',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'bootstrap'),
				'permission_callback' => '__return_true',
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/assessments/(?P<id>\d+)/attempts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'start'),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'          => $this->positive_integer_arg(),
					'participant' => $this->participant_arg(),
					'client'      => $this->client_arg(),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/attempts/(?P<public_id>[a-f0-9-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'state'),
				'permission_callback' => '__return_true',
				'args'                => $this->attempt_args(),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/attempts/(?P<public_id>[a-f0-9-]+)/answers',
			array(
				'methods'             => 'PUT',
				'callback'            => array($this, 'answers'),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->attempt_args(),
					array(
						'answers' => array(
							'type'        => 'array',
							'required'    => true,
							'minItems'    => 1,
							'maxItems'    => 100,
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array('question_id', 'mutation_id'),
								'properties'           => array(
									'question_id' => $this->positive_integer_arg(),
									'option'      => array('type' => array('string', 'null'), 'enum' => array(null, 'A', 'B', 'C', 'D', 'E')),
									'flagged'     => array('type' => 'boolean', 'default' => false),
									'mutation_id' => array('type' => 'string', 'format' => 'uuid'),
								),
							),
						),
					)
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/attempts/(?P<public_id>[a-f0-9-]+)/answers/(?P<question_id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array($this, 'answer'),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->attempt_args(),
					array(
						'question_id' => $this->positive_integer_arg(),
						'option'      => array('type' => array('string', 'null'), 'enum' => array(null, 'A', 'B', 'C', 'D', 'E')),
						'flagged'     => array('type' => 'boolean', 'default' => false),
						'mutation_id' => array('type' => 'string', 'format' => 'uuid', 'required' => true),
					)
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/attempts/(?P<public_id>[a-f0-9-]+)/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'submit'),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->attempt_args(),
					array(
						'automatic'     => array('type' => 'boolean', 'default' => false),
						'submission_id' => array('type' => 'string', 'format' => 'uuid', 'required' => true),
						'answers'       => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => 500,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array('question_id'),
								'properties'           => array(
									'question_id' => $this->positive_integer_arg(),
									'option'      => array('type' => array('string', 'null'), 'enum' => array(null, 'A', 'B', 'C', 'D', 'E')),
									'flagged'     => array('type' => 'boolean', 'default' => false),
								),
							),
						),
					)
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/attempts/(?P<public_id>[a-f0-9-]+)/result',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'result'),
				'permission_callback' => '__return_true',
				'args'                => $this->attempt_args(),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/attempts/(?P<public_id>[a-f0-9-]+)/questions/(?P<question_id>\d+)/image',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'question_image'),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->attempt_args(),
					array('question_id' => $this->positive_integer_arg())
				),
			)
		);
	}

	public function bootstrap(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->bootstrap((int) $request['id']);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function start(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$limited = $this->rate_limit((int) $request['id']);
		if (is_wp_error($limited)) {
			return $limited;
		}
		$result = $this->attempts->start(
			(int) $request['id'],
			(array) $request->get_param('participant')
		);
		return is_wp_error($result) ? $result : $this->attempt_response($result);
	}

	public function state(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->state((string) $request['public_id'], $this->token($request));
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function answer(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->answer(
			(string) $request['public_id'],
			$this->token($request),
			(int) $request['question_id'],
			$request->get_param('option') !== null ? (string) $request->get_param('option') : null,
			(bool) $request->get_param('flagged'),
			sanitize_text_field((string) $request->get_param('mutation_id'))
		);
		if (is_wp_error($result)) {
			return $result;
		}
		$response = rest_ensure_response($result);
		$response->header('Deprecation', 'true');
		$response->header('Sunset', gmdate(DATE_RFC7231, time() + 180 * DAY_IN_SECONDS));
		return $response;
	}

	public function answers(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->answer_many(
			(string) $request['public_id'],
			$this->token($request),
			(array) $request->get_param('answers')
		);
		if (is_wp_error($result)) {
			return $result;
		}
		$response = rest_ensure_response($result);
		$response->header('Deprecation', 'true');
		$response->header('Sunset', gmdate(DATE_RFC7231, time() + 180 * DAY_IN_SECONDS));
		return $response;
	}

	public function submit(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->submit(
			(string) $request['public_id'],
			$this->token($request),
			(bool) $request->get_param('automatic'),
			sanitize_text_field((string) $request->get_param('submission_id')),
			(array) $request->get_param('answers')
		);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function result(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->result((string) $request['public_id'], $this->token($request));
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function question_image(\WP_REST_Request $request): BinaryResponse|\WP_Error {
		$asset = $this->attempts->question_asset(
			(string) $request['public_id'],
			$this->token($request),
			(int) $request['question_id']
		);
		if (is_wp_error($asset)) {
			return $asset;
		}
		return new BinaryResponse(
			(string) $asset['storage_key'],
			'question_image',
			(string) $asset['mime'],
			'soru-' . (int) $request['question_id'] . ($asset['mime'] === 'image/webp' ? '.webp' : '.png'),
			(int) $asset['byte_size']
		);
	}

	private function positive_integer_arg(): array {
		return array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
	}

	private function attempt_args(): array {
		return array(
			'public_id' => array(
				'type'      => 'string',
				'required'  => true,
				'format'    => 'uuid',
				'minLength' => 36,
				'maxLength' => 36,
			),
			'token'     => array(
				'type'      => 'string',
				'minLength' => 32,
				'maxLength' => 128,
			),
		);
	}

	private function participant_arg(): array {
		$properties = array();
		foreach (array('first_name', 'last_name', 'school', 'class_section', 'phone') as $field) {
			$properties[$field] = array('type' => 'string', 'maxLength' => 190);
		}
		$properties['email'] = array('type' => 'string', 'format' => 'email', 'maxLength' => 190);

		return array(
			'type'                 => 'object',
			'default'              => array(),
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	private function client_arg(): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'additionalProperties' => false,
			'properties'           => array(
				'timezone'    => array('type' => 'string', 'maxLength' => 100),
				'language'    => array('type' => 'string', 'maxLength' => 35),
				'platform'    => array('type' => 'string', 'maxLength' => 100),
			),
		);
	}

	private function token(\WP_REST_Request $request): string {
		$authorization = (string) $request->get_header('authorization');
		if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
			return trim($matches[1]);
		}
		$legacy = sanitize_text_field((string) $request->get_param('token'));
		if ($legacy !== '') {
			return $legacy;
		}
		$name = AttemptService::cookie_name((string) $request['public_id']);
		return isset($_COOKIE[$name]) ? sanitize_text_field(wp_unslash($_COOKIE[$name])) : '';
	}

	private function attempt_response(array $result): \WP_REST_Response {
		if (! empty($result['token']) && ! empty($result['public_id'])) {
			$this->set_attempt_cookie((string) $result['public_id'], (string) $result['token']);
			unset($result['token']);
		}
		return rest_ensure_response($result);
	}

	private function set_attempt_cookie(string $public_id, string $token): void {
		setcookie(
			AttemptService::cookie_name($public_id),
			$token,
			array(
				'expires'  => time() + 90 * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	private function rate_limit(int $assessment_id): bool|\WP_Error {
		$ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
		$key  = 'paper_to_quiz_rate_' . substr(hash_hmac('sha256', $assessment_id . '|' . $ip, wp_salt('nonce')), 0, 40);
		$hits = (int) get_transient($key);
		if ($hits >= 60) {
			return new \WP_Error('paper_to_quiz_rate_limited', __('Too many participation requests were sent. Please wait a moment.', 'paper-to-quiz'), array('status' => 429));
		}
		set_transient($key, $hits + 1, 10 * MINUTE_IN_SECONDS);
		return true;
	}
}
