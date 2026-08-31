<?php

declare(strict_types=1);

namespace PaperToQuiz\Rest;

use PaperToQuiz\Application\AssessmentPurgeService;
use PaperToQuiz\Application\AssessmentService;
use PaperToQuiz\Application\AssetService;
use PaperToQuiz\Application\AttemptService;
use PaperToQuiz\Application\TermService;
use PaperToQuiz\Infrastructure\Database;
use PaperToQuiz\Infrastructure\EncryptedStorage;
use PaperToQuiz\Infrastructure\EncryptionMigration;
use PaperToQuiz\Infrastructure\OperationalErrorReporter;
use PaperToQuiz\Infrastructure\Settings;
use PaperToQuiz\Infrastructure\StorageException;

final class AdminController {
	private const CHUNK_SIZE = 2097152;

	public function __construct(
		private readonly Database $db,
		private readonly EncryptedStorage $storage,
		private readonly AssetService $assets,
		private readonly AssessmentService $assessments,
		private readonly AttemptService $attempts,
		private readonly TermService $terms,
		private readonly AssessmentPurgeService $purge_service,
		private readonly ?EncryptionMigration $encryption_migration = null
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_assessments'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => $this->assessment_list_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'save_assessment'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => $this->assessment_args(true),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'bulk_assessments'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => $this->bulk_action_args(array('trash', 'restore', 'delete_permanently')),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'get_assessment'),
					'permission_callback' => array($this, 'can_manage'),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array($this, 'save_assessment'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => $this->assessment_args(false),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array($this, 'trash_assessment'),
					'permission_callback' => array($this, 'can_manage'),
				),
				'args' => array(
					'id'    => $this->positive_integer_arg(),
					'force' => array('type' => 'boolean', 'default' => false),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments/(?P<id>\d+)/delete-impact',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'assessment_delete_impact'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments/(?P<id>\d+)/publish',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'publish_assessment'),
				'permission_callback' => array($this, 'can_publish'),
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'duplicate_assessment'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assessments/(?P<id>\d+)/source-media',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'attach_media_pdf'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'            => $this->positive_integer_arg(),
					'attachment_id' => $this->positive_integer_arg(),
					'question_strategy' => array('type' => 'string', 'enum' => array('preserve', 'clear')),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/classes',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_classes'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => array(
						'status'   => array('type' => 'string', 'enum' => array('active', 'archived', 'trash'), 'default' => 'active'),
						'search'   => array('type' => 'string', 'default' => '', 'maxLength' => 190),
						'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
						'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'save_class'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => array(
						'name' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => 190,
						),
						'id'    => $this->positive_integer_arg(false),
						'color' => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 7,
							'pattern'   => '^$|^#[0-9A-Fa-f]{6}$',
						),
					),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/classes/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'bulk_classes'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => $this->bulk_action_args(array('archive', 'trash', 'restore', 'delete_permanently')),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/classes/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'delete_class'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'    => $this->positive_integer_arg(),
					'force' => array('type' => 'boolean', 'default' => false),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/subjects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_subjects'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => array(
						'status'   => array('type' => 'string', 'enum' => array('active', 'archived', 'trash'), 'default' => 'active'),
						'search'   => array('type' => 'string', 'default' => '', 'maxLength' => 190),
						'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
						'per_page' => array('type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 100),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'save_subject'),
					'permission_callback' => array($this, 'can_manage'),
					'args'                => array(
						'name' => array('type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 190),
						'id'   => $this->positive_integer_arg(false),
					),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/subjects/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'bulk_subjects'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => $this->bulk_action_args(array('archive', 'trash', 'restore', 'delete_permanently')),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/subjects/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'delete_subject'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'    => $this->positive_integer_arg(),
					'force' => array('type' => 'boolean', 'default' => false),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/uploads',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'start_upload'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'name'        => array('type' => 'string', 'required' => true, 'minLength' => 5, 'maxLength' => 255),
					'size'        => array('type' => 'integer', 'required' => true, 'minimum' => 1),
					'chunk_count' => array('type' => 'integer', 'required' => true, 'minimum' => 1),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/uploads/(?P<id>[a-f0-9-]+)/chunks/(?P<index>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array($this, 'upload_chunk'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'    => $this->uuid_arg(),
					'index' => array('type' => 'integer', 'minimum' => 0),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/uploads/(?P<id>[a-f0-9-]+)/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'complete_upload'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'            => $this->uuid_arg(),
					'assessment_id' => $this->positive_integer_arg(),
					'sha256'        => array(
						'type'      => 'string',
						'required'  => true,
						'pattern'   => '^[a-f0-9]{64}$',
						'minLength' => 64,
						'maxLength' => 64,
					),
					'question_strategy' => array('type' => 'string', 'enum' => array('preserve', 'clear')),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/assets/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'asset'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/revisions/(?P<id>\d+)/questions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'save_question'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'       => $this->positive_integer_arg(),
					'metadata' => array('type' => 'string', 'required' => true),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/revisions/(?P<id>\d+)/answer-key',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array($this, 'save_answer_key'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array(
					'id'        => $this->positive_integer_arg(),
					'questions' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array('id', 'correct_option', 'points'),
							'properties'           => array(
								'id'             => $this->positive_integer_arg(),
								'correct_option' => array('type' => 'string', 'enum' => array('', 'A', 'B', 'C', 'D', 'E')),
								'points'         => array('type' => 'integer', 'minimum' => 0),
							),
						),
					),
					'prune_missing' => array('type' => 'boolean', 'default' => false),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/questions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'delete_question'),
				'permission_callback' => array($this, 'can_manage'),
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/results',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'results'),
				'permission_callback' => array($this, 'can_view_results'),
				'args'                => array(
					'assessment_id'  => $this->positive_integer_arg(false),
					'participant_type' => array('type' => 'string', 'enum' => array('member', 'guest')),
					'status'         => array('type' => 'string', 'enum' => array('in_progress', 'submitted', 'auto_submitted', 'expired')),
					'search'         => array('type' => 'string', 'default' => '', 'maxLength' => 190),
					'orderby'        => array('type' => 'string', 'enum' => array('title', 'started', 'finished', 'duration', 'correct', 'wrong', 'blank', 'score', 'status'), 'default' => 'started'),
					'order'          => array('type' => 'string', 'enum' => array('asc', 'desc'), 'default' => 'desc'),
					'page'           => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
					'per_page'       => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
				),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/results/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'result'),
				'permission_callback' => array($this, 'can_view_results'),
				'args'                => array('id' => $this->positive_integer_arg()),
			)
		);
		register_rest_route(
			'paper-to-quiz/v1',
			'/admin/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array($this, 'settings'),
					'permission_callback' => array($this, 'can_settings'),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array($this, 'save_settings'),
					'permission_callback' => array($this, 'can_settings'),
					'args'                => $this->settings_args(),
				),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can('paper_to_quiz_manage_assessments');
	}

	public function can_publish(): bool {
		return current_user_can('paper_to_quiz_publish_assessments');
	}

	public function can_view_results(): bool {
		return current_user_can('paper_to_quiz_view_results');
	}

	public function can_settings(): bool {
		return current_user_can('paper_to_quiz_manage_settings');
	}

	public function list_assessments(\WP_REST_Request $request): \WP_REST_Response {
		$result = $this->assessments->list(
			sanitize_key((string) $request->get_param('type')),
			max(1, (int) $request->get_param('page')),
			min(100, max(1, (int) ($request->get_param('per_page') ?: 20))),
			sanitize_key((string) $request->get_param('status')),
			sanitize_text_field((string) $request->get_param('search')),
			sanitize_key((string) $request->get_param('orderby')),
			sanitize_key((string) $request->get_param('order'))
		);
		$result['items'] = array_map(array($this, 'decorate_assessment_list_item'), $result['items']);
		return rest_ensure_response($result);
	}

	public function bulk_assessments(\WP_REST_Request $request): \WP_REST_Response {
		$action = (string) $request->get_param('action');
		$changed = 0;
		$errors = array();
		foreach (array_unique(array_map('absint', (array) $request->get_param('ids'))) as $id) {
			if (! $id) {
				continue;
			}
			$result = match ($action) {
				'restore'            => $this->assessments->restore($id),
				'delete_permanently' => $this->purge_service->purge($id),
				default              => $this->assessments->trash($id),
			};
			if (is_wp_error($result)) {
				$errors[] = $result->get_error_message();
			} elseif ($result) {
				++$changed;
			}
		}
		return rest_ensure_response(array('changed' => $changed, 'errors' => $errors));
	}

	public function get_assessment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$record = $this->assessments->get((int) $request['id']);
		return $record
			? rest_ensure_response($this->decorate_assessment($record))
			: new \WP_Error('paper_to_quiz_not_found', __('Record not found.', 'paper-to-quiz'), array('status' => 404));
	}

	public function save_assessment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->assessments->save(
			(array) $request->get_json_params(),
			$request['id'] ? (int) $request['id'] : null,
			get_current_user_id()
		);
		return is_wp_error($result) ? $result : rest_ensure_response($this->decorate_assessment($result));
	}

	public function publish_assessment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->assessments->publish((int) $request['id']);
		return is_wp_error($result) ? $result : rest_ensure_response($this->decorate_assessment($result));
	}

	public function duplicate_assessment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->assessments->duplicate((int) $request['id'], get_current_user_id());
		return is_wp_error($result) ? $result : rest_ensure_response($this->decorate_assessment($result));
	}

	public function attach_media_pdf(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$attachment_id = (int) $request->get_param('attachment_id');
		$attachment    = get_post($attachment_id);
		if (! $attachment || $attachment->post_type !== 'attachment') {
			return new \WP_Error('paper_to_quiz_media_not_found', __('The selected PDF could not be found.', 'paper-to-quiz'), array('status' => 404));
		}
		if (! current_user_can('edit_post', $attachment_id)) {
			return new \WP_Error('paper_to_quiz_media_forbidden', __('You do not have permission to use this PDF.', 'paper-to-quiz'), array('status' => 403));
		}
		if (get_post_mime_type($attachment_id) !== 'application/pdf') {
			return new \WP_Error('paper_to_quiz_pdf_only', __('Only PDF files can be selected.', 'paper-to-quiz'), array('status' => 400));
		}

		$path = get_attached_file($attachment_id, true);
		if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
			return new \WP_Error('paper_to_quiz_media_unavailable', __('The PDF could not be accessed.', 'paper-to-quiz'), array('status' => 404));
		}

		$size     = filesize($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Local media is copied as a bounded encrypted stream.
		$settings = Settings::get();
		$limit    = max(1, (int) ($settings['max_pdf_mb'] ?? 50)) * MB_IN_BYTES;
		if (! is_int($size) || $size < 1 || $size > $limit) {
			/* translators: %d: Maximum PDF file size in megabytes. */
			return new \WP_Error('paper_to_quiz_pdf_size', sprintf(__('The PDF can be at most %d MB.', 'paper-to-quiz'), $limit / MB_IN_BYTES), array('status' => 413));
		}

		$handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Only the PDF signature is read here.
		$prefix = is_resource($handle) ? fread($handle, 5) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if (is_resource($handle)) {
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ($prefix !== '%PDF-') {
			return new \WP_Error('paper_to_quiz_invalid_pdf', __('The file is not a valid PDF.', 'paper-to-quiz'), array('status' => 400));
		}

		try {
			$asset_id = $this->assets->create_from_file($path, 'source_pdf', 'application/pdf');
			$attached = $this->assessments->set_source_asset(
				(int) $request['id'],
				$asset_id,
				$request->get_param('question_strategy') ? sanitize_key((string) $request->get_param('question_strategy')) : null
			);
		} catch (\Throwable $exception) {
			if (isset($asset_id)) {
				$this->release_asset_safely($asset_id);
			}
			return $this->storage_error($exception);
		}

		if (is_wp_error($attached)) {
			$this->release_asset_safely($asset_id);
			return $attached;
		}

		return rest_ensure_response(
			array(
				'asset_id'   => $asset_id,
				'assessment' => $this->decorate_assessment($attached),
			)
		);
	}

	public function trash_assessment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		if ($request->get_param('force')) {
			$result = $this->purge_service->purge((int) $request['id']);
			return is_wp_error($result) ? $result : rest_ensure_response(array('deleted' => true, 'impact' => $result));
		}
		return rest_ensure_response(array('deleted' => $this->assessments->trash((int) $request['id'])));
	}

	public function assessment_delete_impact(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->purge_service->purge_impact((int) $request['id']);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function list_classes(\WP_REST_Request $request): \WP_REST_Response {
		return rest_ensure_response(
			$this->terms->classes(
				sanitize_key((string) $request->get_param('status')),
				sanitize_text_field((string) $request->get_param('search')),
				max(1, (int) $request->get_param('page')),
				min(100, max(1, (int) ($request->get_param('per_page') ?: 20)))
			)
		);
	}

	public function save_class(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->terms->save_class(
			(string) $request->get_param('name'),
			$request->get_param('id') ? (int) $request->get_param('id') : null,
			(string) $request->get_param('color')
		);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function delete_class(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $request->get_param('force')
			? $this->terms->purge_class((int) $request['id'])
			: $this->terms->trash_class((int) $request['id']);
		return is_wp_error($result) ? $result : rest_ensure_response(array('deleted' => (bool) $result));
	}

	public function bulk_classes(\WP_REST_Request $request): \WP_REST_Response {
		$action = (string) $request->get_param('action');
		$changed = 0;
		$errors = array();
		foreach (array_unique(array_map('absint', (array) $request->get_param('ids'))) as $id) {
			if (! $id) {
				continue;
			}
			$result = match ($action) {
				'restore'            => $this->terms->restore_class($id),
				'trash'              => $this->terms->trash_class($id),
				'delete_permanently' => $this->terms->purge_class($id),
				default              => $this->terms->archive_class($id),
			};
			if (is_wp_error($result)) {
				$errors[] = $result->get_error_message();
			} elseif ($result) {
				++$changed;
			}
		}
		return rest_ensure_response(array('changed' => $changed, 'errors' => $errors));
	}

	public function list_subjects(\WP_REST_Request $request): \WP_REST_Response {
		return rest_ensure_response(
			$this->terms->subjects(
				sanitize_key((string) $request->get_param('status')),
				sanitize_text_field((string) $request->get_param('search')),
				max(1, (int) $request->get_param('page')),
				min(100, max(1, (int) ($request->get_param('per_page') ?: 100)))
			)
		);
	}

	public function save_subject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->terms->save_subject(
			(string) $request->get_param('name'),
			$request->get_param('id') ? (int) $request->get_param('id') : null
		);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function delete_subject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $request->get_param('force')
			? $this->terms->purge_subject((int) $request['id'])
			: $this->terms->trash_subject((int) $request['id']);
		return is_wp_error($result) ? $result : rest_ensure_response(array('deleted' => (bool) $result));
	}

	public function bulk_subjects(\WP_REST_Request $request): \WP_REST_Response {
		$action = (string) $request->get_param('action');
		$changed = 0;
		$errors = array();
		foreach (array_unique(array_map('absint', (array) $request->get_param('ids'))) as $id) {
			if (! $id) {
				continue;
			}
			$result = match ($action) {
				'restore'            => $this->terms->restore_subject($id),
				'trash'              => $this->terms->trash_subject($id),
				'delete_permanently' => $this->terms->purge_subject($id),
				default              => $this->terms->archive_subject($id),
			};
			if (is_wp_error($result)) {
				$errors[] = $result->get_error_message();
			} elseif ($result) {
				++$changed;
			}
		}
		return rest_ensure_response(array('changed' => $changed, 'errors' => $errors));
	}

	public function start_upload(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$name  = sanitize_file_name((string) $request->get_param('name'));
		$size  = max(0, (int) $request->get_param('size'));
		$count = max(1, (int) $request->get_param('chunk_count'));
		$settings = Settings::get();
		$limit = max(1, (int) ($settings['max_pdf_mb'] ?? 50)) * MB_IN_BYTES;

		if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
			return new \WP_Error('paper_to_quiz_pdf_only', __('Only PDF files can be uploaded.', 'paper-to-quiz'), array('status' => 400));
		}
		if ($size <= 0 || $size > $limit) {
			/* translators: %d: Maximum PDF file size in megabytes. */
			return new \WP_Error('paper_to_quiz_pdf_size', sprintf(__('The PDF can be at most %d MB.', 'paper-to-quiz'), $limit / MB_IN_BYTES), array('status' => 413));
		}
		if ($count !== (int) ceil($size / self::CHUNK_SIZE)) {
			return new \WP_Error('paper_to_quiz_chunk_count', __('The upload chunk count is invalid.', 'paper-to-quiz'), array('status' => 400));
		}

		$id = wp_generate_uuid4();
		$inserted = $this->db->wpdb()->insert(
			$this->db->table('upload_sessions'),
			array(
				'id'              => $id,
				'owner_user_id'   => get_current_user_id(),
				'original_name'   => $name,
				'expected_size'   => $size,
				'received_size'   => 0,
				'chunk_count'     => $count,
				'status'          => 'pending',
				'manifest_json'   => wp_json_encode(array()),
				'expires_at'      => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
				'created_at'      => current_time('mysql', true),
			)
		);
		if (! $inserted) {
			return $this->storage_error(
				new StorageException(
					'Upload session insert failed: ' . (string) $this->db->wpdb()->last_error,
					__('The PDF upload could not be started. Please try again.', 'paper-to-quiz')
				)
			);
		}
		return rest_ensure_response(array('id' => $id, 'chunk_size' => self::CHUNK_SIZE, 'expires_at' => gmdate(DATE_ATOM, time() + DAY_IN_SECONDS)));
	}

	public function upload_chunk(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$session = $this->upload_session((string) $request['id']);
		if (is_wp_error($session)) {
			return $session;
		}
		$index = (int) $request['index'];
		if ($index < 0 || $index >= (int) $session['chunk_count']) {
			return new \WP_Error('paper_to_quiz_chunk_index', __('The chunk number is invalid.', 'paper-to-quiz'), array('status' => 400));
		}
		$body = $request->get_body();
		if ($body === '' || strlen($body) > self::CHUNK_SIZE) {
			return new \WP_Error('paper_to_quiz_chunk_size', __('The upload chunk is invalid.', 'paper-to-quiz'), array('status' => 400));
		}
		$sha = hash('sha256', $body);
		$expected_sha = strtolower((string) $request->get_header('x-paper-to-quiz-chunk-sha256'));
		if ($expected_sha !== '' && ! hash_equals($expected_sha, $sha)) {
			return new \WP_Error('paper_to_quiz_chunk_hash', __('The upload chunk is corrupted.', 'paper-to-quiz'), array('status' => 400));
		}

		$manifest = json_decode((string) $session['manifest_json'], true) ?: array();
		if (isset($manifest[$index]) && hash_equals((string) $manifest[$index]['sha256'], $sha)) {
			return rest_ensure_response(array('received' => true, 'duplicate' => true));
		}
		if (isset($manifest[$index]['storage_key'])) {
			$this->storage->delete((string) $manifest[$index]['storage_key']);
		}
		try {
			$stored = $this->storage->put_string($body, 'source_pdf');
		} catch (\Throwable $exception) {
			return $this->storage_error($exception);
		}
		$manifest[$index] = array(
			'storage_key' => $stored['storage_key'],
			'sha256'      => $sha,
			'size'        => strlen($body),
		);
		ksort($manifest);
		$received = array_sum(array_column($manifest, 'size'));
		$updated = $this->db->wpdb()->update(
			$this->db->table('upload_sessions'),
			array('manifest_json' => wp_json_encode($manifest), 'received_size' => $received),
			array('id' => $session['id'])
		);
		if ($updated === false) {
			$this->storage->delete((string) $stored['storage_key']);
			return $this->storage_error(
				new StorageException(
					'Upload session update failed: ' . (string) $this->db->wpdb()->last_error,
					__('The PDF upload could not be saved. Please try again.', 'paper-to-quiz')
				)
			);
		}
		return rest_ensure_response(array('received' => true, 'index' => $index, 'received_size' => $received));
	}

	public function complete_upload(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$session = $this->upload_session((string) $request['id']);
		if (is_wp_error($session)) {
			return $session;
		}
		$manifest = json_decode((string) $session['manifest_json'], true) ?: array();
		if (count($manifest) !== (int) $session['chunk_count'] || array_sum(array_column($manifest, 'size')) !== (int) $session['expected_size']) {
			return new \WP_Error('paper_to_quiz_upload_incomplete', __('The PDF upload is not complete yet.', 'paper-to-quiz'), array('status' => 409));
		}
		ksort($manifest);
		$keys   = array_column($manifest, 'storage_key');
		try {
			$stored = $this->storage->combine($keys);
			$prefix = $this->storage->prefix($stored['storage_key'], 'source_pdf', 5);
		} catch (\Throwable $exception) {
			return $this->storage_error($exception);
		}
		if ($prefix !== '%PDF-') {
			$this->storage->delete($stored['storage_key']);
			return new \WP_Error('paper_to_quiz_invalid_pdf', __('The file is not a valid PDF.', 'paper-to-quiz'), array('status' => 400));
		}

		$whole_sha = strtolower((string) $request->get_param('sha256'));
		if ($whole_sha !== '' && ! hash_equals($whole_sha, $stored['sha256'])) {
			$this->storage->delete($stored['storage_key']);
			return new \WP_Error('paper_to_quiz_pdf_hash', __('The PDF was corrupted during upload.', 'paper-to-quiz'), array('status' => 400));
		}

		try {
			$asset_id = $this->assets->create_from_stored($stored, 'source_pdf', 'application/pdf');
			$attached = $this->assessments->set_source_asset(
				(int) $request->get_param('assessment_id'),
				$asset_id,
				$request->get_param('question_strategy') ? sanitize_key((string) $request->get_param('question_strategy')) : null
			);
		} catch (\Throwable $exception) {
			if (isset($asset_id)) {
				$this->release_asset_safely($asset_id);
			} else {
				$this->storage->delete($stored['storage_key']);
			}
			return $this->storage_error($exception);
		}
		if (is_wp_error($attached)) {
			$this->release_asset_safely($asset_id);
			return $attached;
		}
		foreach ($keys as $key) {
			$this->storage->delete($key);
		}
		$this->db->wpdb()->update($this->db->table('upload_sessions'), array('status' => 'completed'), array('id' => $session['id']));
		return rest_ensure_response(array('asset_id' => $asset_id, 'assessment' => $this->decorate_assessment($attached)));
	}

	public function asset(\WP_REST_Request $request): BinaryResponse|\WP_Error {
		$asset = $this->assets->get((int) $request['id']);
		if (! $asset) {
			return new \WP_Error('paper_to_quiz_asset_not_found', __('File not found.', 'paper-to-quiz'), array('status' => 404));
		}
		return new BinaryResponse(
			(string) $asset['storage_key'],
			(string) $asset['type'],
			(string) $asset['mime'],
			(string) ('ptq-' . $asset['id'] . $this->extension_for_mime((string) $asset['mime'])),
			(int) $asset['byte_size']
		);
	}

	public function save_question(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$params   = $request->get_body_params();
		$metadata = json_decode((string) ($params['metadata'] ?? '{}'), true);
		if (! is_array($metadata)) {
			return new \WP_Error('paper_to_quiz_question_metadata', __('Question metadata is invalid.', 'paper-to-quiz'), array('status' => 400));
		}
		$files = $request->get_file_params();
		$main  = isset($files['main']) ? $this->store_image($files['main'], 'question_image') : null;
		if (is_wp_error($main)) {
			return $main;
		}
		$thumb = isset($files['thumb']) ? $this->store_image($files['thumb'], 'question_thumb') : null;
		if (is_wp_error($thumb)) {
			if ($main) {
				$this->release_asset_safely($main);
			}
			return $thumb;
		}

		$result = $this->assessments->save_question(
			(int) $request['id'],
			$metadata,
			$main,
			$thumb,
			! empty($metadata['id']) ? (int) $metadata['id'] : null
		);
		if (is_wp_error($result)) {
			if ($main) {
				$this->release_asset_safely($main);
			}
			if ($thumb) {
				$this->release_asset_safely($thumb);
			}
			return $result;
		}
		return rest_ensure_response($this->decorate_question($result));
	}

	public function save_answer_key(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->assessments->update_answer_key(
			(int) $request['id'],
			(array) $request->get_param('questions'),
			(bool) $request->get_param('prune_missing')
		);
		return is_wp_error($result)
			? $result
			: rest_ensure_response(array_map(array($this, 'decorate_question'), $result));
	}

	public function delete_question(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->assessments->delete_question((int) $request['id']);
		return is_wp_error($result) ? $result : rest_ensure_response(array('deleted' => $result));
	}

	public function results(\WP_REST_Request $request): \WP_REST_Response {
		return rest_ensure_response($this->attempts->admin_results($request->get_params()));
	}

	public function result(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$result = $this->attempts->admin_result((int) $request['id']);
		return $result
			? rest_ensure_response($result)
			: new \WP_Error('paper_to_quiz_result_not_found', __('Result not found.', 'paper-to-quiz'), array('status' => 404));
	}

	public function settings(): \WP_REST_Response {
		$settings = Settings::get();
		$settings['storage_writable'] = $this->storage->is_available();
		$settings['openssl']          = extension_loaded('openssl');
		$settings['max_upload_bytes'] = wp_max_upload_size();
		$migration = $this->encryption_migration
			? $this->encryption_migration->status()
			: array('status' => 'complete', 'failures' => 0);
		$settings['encryption_migration'] = array(
			'status'   => $migration['status'],
			'failures' => $migration['failures'],
		);
		return rest_ensure_response($settings);
	}

	public function save_settings(\WP_REST_Request $request): \WP_REST_Response {
		$settings = Settings::sanitize($request->get_json_params());
		update_option(Settings::OPTION, $settings, false);
		return rest_ensure_response($settings);
	}

	private function upload_session(string $id): array|\WP_Error {
		$row = $this->db->wpdb()->get_row(
			$this->db->wpdb()->prepare(
				'SELECT * FROM ' . $this->db->table('upload_sessions') . ' WHERE id = %s AND owner_user_id = %d',
				$id,
				get_current_user_id()
			),
			ARRAY_A
		);
		if (! $row || $row['status'] !== 'pending' || strtotime($row['expires_at'] . ' UTC') < time()) {
			return new \WP_Error('paper_to_quiz_upload_session', __('The upload session was not found or has expired.', 'paper-to-quiz'), array('status' => 404));
		}
		return $row;
	}

	private function store_image(array $file, string $type): int|\WP_Error {
		if (! empty($file['error']) || empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
			return new \WP_Error('paper_to_quiz_image_upload', __('The question image could not be uploaded.', 'paper-to-quiz'), array('status' => 400));
		}
		$mime = wp_get_image_mime($file['tmp_name']);
		if (! in_array($mime, array('image/png', 'image/webp'), true)) {
			return new \WP_Error('paper_to_quiz_image_type', __('The question image must be PNG or WebP.', 'paper-to-quiz'), array('status' => 400));
		}
		$dimensions = getimagesize($file['tmp_name']);
		if (! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
			return new \WP_Error('paper_to_quiz_image_dimensions', __('The question image dimensions are invalid.', 'paper-to-quiz'), array('status' => 400));
		}
		return $this->assets->create_from_file($file['tmp_name'], $type, $mime, (int) $dimensions[0], (int) $dimensions[1]);
	}

	private function decorate_assessment(array $record): array {
		if (! empty($record['assessment']['created_at'])) {
			$record['assessment']['created_at_display'] = $this->format_site_date((string) $record['assessment']['created_at']);
		}
		if (! empty($record['revision']['source_asset_id'])) {
			$record['revision']['pdf_url'] = rest_url('paper-to-quiz/v1/admin/assets/' . $record['revision']['source_asset_id']);
		}
		$record['questions'] = array_map(array($this, 'decorate_question'), $record['questions']);
		return $record;
	}

	private function decorate_assessment_list_item(array $item): array {
		$item['created_at_display'] = ! empty($item['created_at'])
			? $this->format_site_date((string) $item['created_at'])
			: '—';
		return $item;
	}

	private function format_site_date(string $utc_date): string {
		return wp_date(
			get_option('date_format') . ' ' . get_option('time_format'),
			strtotime($utc_date . ' UTC'),
			wp_timezone()
		);
	}

	public function decorate_question(array $question): array {
		if (! empty($question['main_asset_id'])) {
			$question['image_url'] = rest_url('paper-to-quiz/v1/admin/assets/' . $question['main_asset_id']);
		}
		if (! empty($question['thumb_asset_id'])) {
			$question['thumb_url'] = rest_url('paper-to-quiz/v1/admin/assets/' . $question['thumb_asset_id']);
		}
		return $question;
	}

	private function extension_for_mime(string $mime): string {
		return match ($mime) {
			'application/pdf' => '.pdf',
			'image/png'       => '.png',
			'image/webp'      => '.webp',
			default           => '.bin',
		};
	}

	private function positive_integer_arg(bool $required = true): array {
		return array(
			'type'     => 'integer',
			'required' => $required,
			'minimum'  => 1,
		);
	}

	private function uuid_arg(): array {
		return array(
			'type'      => 'string',
			'required'  => true,
			'format'    => 'uuid',
			'minLength' => 36,
			'maxLength' => 36,
		);
	}

	private function assessment_list_args(): array {
		return array(
			'type'     => array('type' => 'string', 'enum' => array('', 'exam', 'test'), 'default' => ''),
			'status'   => array('type' => 'string', 'enum' => array('', 'draft', 'published', 'archived', 'trash'), 'default' => ''),
			'search'   => array('type' => 'string', 'default' => '', 'maxLength' => 190),
			'orderby'  => array('type' => 'string', 'enum' => array('updated', 'created', 'title', 'class', 'questions', 'status', 'participation'), 'default' => 'updated'),
			'order'    => array('type' => 'string', 'enum' => array('asc', 'desc'), 'default' => 'desc'),
			'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
			'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
		);
	}

	private function bulk_action_args(array $actions): array {
		return array(
			'action' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => $actions,
			),
			'ids'    => array(
				'type'        => 'array',
				'required'    => true,
				'minItems'    => 1,
				'maxItems'    => 100,
				'uniqueItems' => true,
				'items'       => $this->positive_integer_arg(),
			),
		);
	}

	private function storage_error(\Throwable $exception): \WP_Error {
		if ($exception instanceof StorageException) {
			$message = $exception->user_message;
		} else {
			$message = __('The file could not be processed. Please try again.', 'paper-to-quiz');
		}
		return OperationalErrorReporter::report(
			'paper_to_quiz_storage_failed',
			$exception,
			$message,
			500
		);
	}

	private function release_asset_safely(?int $asset_id): void {
		try {
			$this->assets->release($asset_id);
		} catch (\Throwable $exception) {
			OperationalErrorReporter::report(
				'paper_to_quiz_asset_cleanup_failed',
				$exception,
				__('A superseded private file could not be cleaned up.', 'paper-to-quiz'),
				500
			);
		}
	}

	private function assessment_args(bool $creating): array {
		$field = array(
			'type'                   => array('type' => 'string', 'enum' => array('exam', 'test'), 'required' => $creating),
			'title'                  => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'required' => $creating),
			'description'            => array('type' => 'string'),
			'class_id'               => array('type' => 'integer', 'minimum' => 0),
			'subject_ids'            => array(
				'type'        => 'array',
				'required'    => $creating,
				'minItems'    => 1,
				'uniqueItems' => true,
				'items'       => array('type' => 'integer', 'minimum' => 1),
			),
			'access_mode'            => array('type' => 'string', 'enum' => array('guest_allowed', 'login_required')),
			'options'                => array(
				'type'        => 'array',
				'minItems'    => 3,
				'maxItems'    => 5,
				'uniqueItems' => true,
				'items'       => array('type' => 'string', 'enum' => array('A', 'B', 'C', 'D', 'E')),
			),
			'total_points'           => array('type' => 'integer', 'minimum' => 1),
			'duration_seconds'       => array('type' => array('integer', 'null'), 'minimum' => 60),
			'window_start_utc'       => array('type' => array('string', 'null')),
			'window_end_utc'         => array('type' => array('string', 'null')),
			'results_release_at_utc' => array('type' => array('string', 'null')),
			'allow_repeat'           => array('type' => 'boolean'),
			'ranking_enabled'        => array('type' => 'boolean'),
			'feedback_timing'        => array('type' => 'string', 'enum' => array('never', 'immediate', 'after_submit', 'scheduled')),
			'result_visibility'      => array('type' => 'string', 'enum' => array('hidden', 'score_only', 'summary', 'detailed')),
		);

		$participant_properties = array();
		foreach (array('first_name', 'last_name', 'school', 'class_section', 'email', 'phone') as $participant_field) {
			$participant_properties[$participant_field] = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'enabled'  => array('type' => 'boolean'),
					'required' => array('type' => 'boolean'),
				),
			);
		}
		$field['participant_fields'] = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $participant_properties,
		);
		return $field;
	}

	private function settings_args(): array {
		return array(
			'max_pdf_mb'     => array('type' => 'integer', 'minimum' => 1, 'maximum' => 500),
			'retention_days' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 3650),
			'crop_dpi'       => array('type' => 'integer', 'minimum' => 120, 'maximum' => 360),
			'max_image_edge' => array('type' => 'integer', 'minimum' => 1200, 'maximum' => 6000),
			'page_warning'   => array('type' => 'integer', 'minimum' => 20, 'maximum' => 1000),
			'network_grace'  => array('type' => 'integer', 'minimum' => 0, 'maximum' => 120),
			'purge_on_uninstall' => array('type' => 'boolean'),
		);
	}
}
