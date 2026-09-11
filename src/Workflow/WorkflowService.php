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
		$validation = $this->validator->validate( $definition );
		if ( ! PersistencePolicy::allows( $validation ) ) {
			throw new \DomainException( 'Workflow definition contains sensitive literal data that cannot be persisted safely.' );
		}

		return WorkflowRepository::create( $name, $definition, $user_id );
	}

	public function find( int $id ): ?WorkflowRecord {
		return WorkflowRepository::find( $id );
	}

	/**
	 * Persist one editor revision. Draft definitions may be invalid, but an
	 * invalid definition can never remain enabled. When an operator requests
	 * Enabled for an invalid definition we preserve the edits as Disabled and
	 * return an explicit result so the UI can explain what happened.
	 * Sensitive literals remain a hard persistence block in every state.
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
		if ( ! PersistencePolicy::allows( $validation ) ) {
			return WorkflowSaveResult::persistence_blocked( $validation );
		}

		$target_state = ActivationPolicy::allows( $activation_state, $validation )
			? $activation_state
			: ActivationState::Disabled;

		$saved = WorkflowRepository::update(
			$id,
			$expected_revision,
			$name,
			$target_state,
			$definition,
			$user_id
		);

		if ( ! $saved ) {
			return WorkflowSaveResult::conflict( $validation );
		}

		return $target_state !== $activation_state
			? WorkflowSaveResult::saved_disabled( $validation )
			: WorkflowSaveResult::saved( $validation );
	}
}
