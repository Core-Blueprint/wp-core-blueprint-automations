<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Support\Requirements;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Validation\WorkflowValidator;

defined( 'ABSPATH' ) || exit;

final class WorkflowService {
	private WorkflowValidator $validator;
	private CapabilitySource $source;

	public function __construct( ?CapabilitySource $source = null ) {
		$this->source = $source ?? new CapabilityCatalog();
		$this->validator = new WorkflowValidator( $this->source );
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
	 * invalid, unauthorized or cryptographically unsafe definition can never
	 * remain enabled. Execution authority is never inferred from audit metadata.
	 */
	public function save(
		int $id,
		int $expected_revision,
		string $name,
		ActivationState $activation_state,
		Definition $definition,
		int $user_id,
		bool $rebind_execution_principal = false
	): WorkflowSaveResult {
		$validation = $this->validator->validate( $definition );
		if ( ! PersistencePolicy::allows( $validation ) ) {
			return WorkflowSaveResult::persistence_blocked( $validation );
		}

		$current = WorkflowRepository::find( $id );
		if ( null === $current ) {
			return WorkflowSaveResult::conflict( $validation );
		}

		$principal_user_id = $rebind_execution_principal
			? $user_id
			: $current->execution_principal_user_id();
		$target_state = $activation_state;
		$block_reason = null;

		if ( ActivationState::Enabled === $activation_state ) {
			if ( ! $validation->is_valid() ) {
				$target_state = ActivationState::Disabled;
				$block_reason = WorkflowSaveResult::WORKFLOW_INVALID;
			} elseif ( ! Requirements::execution_ready() ) {
				$target_state = ActivationState::Disabled;
				$block_reason = WorkflowSaveResult::EXECUTION_UNAVAILABLE;
			} else {
				$authority = ExecutionPrincipalPolicy::evaluate(
					$definition,
					$principal_user_id,
					$user_id,
					$this->source
				);
				if ( ! $authority->is_allowed() ) {
					$target_state = ActivationState::Disabled;
					$block_reason = $authority->reason();
				}
			}
		}

		$saved = WorkflowRepository::update(
			$id,
			$expected_revision,
			$name,
			$target_state,
			$definition,
			$principal_user_id,
			$user_id
		);

		if ( ! $saved ) {
			return WorkflowSaveResult::conflict( $validation );
		}

		if ( $target_state !== $activation_state ) {
			return WorkflowSaveResult::saved_disabled(
				$validation,
				$principal_user_id,
				$block_reason ?? WorkflowSaveResult::WORKFLOW_INVALID
			);
		}

		return WorkflowSaveResult::saved( $validation, $target_state, $principal_user_id );
	}
}
