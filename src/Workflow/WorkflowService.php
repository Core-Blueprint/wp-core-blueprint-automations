<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Validation\WorkflowValidator;

defined( 'ABSPATH' ) || exit;

final class WorkflowService {
	private WorkflowValidator $validator;

	public function __construct( ?CapabilitySource $source = null ) {
		$this->validator = new WorkflowValidator( $source ?? new CapabilityCatalog() );
	}

	public function validate( Definition $definition ): ValidationResult {
		return $this->validator->validate( $definition );
	}

	public function create( string $name, Definition $definition, int $user_id ): int {
		return WorkflowRepository::create( $name, $definition, $user_id );
	}

	public function find( int $id ): ?WorkflowRecord {
		return WorkflowRepository::find( $id );
	}

	/**
	 * Persist one editor revision. Draft/disabled definitions may be invalid;
	 * enabling is fail-closed against the current capability source.
	 */
	public function save(
		int $id,
		int $expected_revision,
		string $name,
		ActivationState $activation_state,
		Definition $definition,
		int $user_id
	): WorkflowSaveResult {
		$validation = $this->validator->validate( $definition );
		if ( ! ActivationPolicy::allows( $activation_state, $validation ) ) {
			return WorkflowSaveResult::validation_failed( $validation );
		}

		$saved = WorkflowRepository::update(
			$id,
			$expected_revision,
			$name,
			$activation_state,
			$definition,
			$user_id
		);

		return $saved
			? WorkflowSaveResult::saved( $validation )
			: WorkflowSaveResult::conflict( $validation );
	}
}
