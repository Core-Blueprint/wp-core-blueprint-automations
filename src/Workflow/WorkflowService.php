<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Admin\EditorSaveBoundary;
use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\Schema;
use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Support\Requirements;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Validation\GraphValidator;

defined( 'ABSPATH' ) || exit;

final class WorkflowService {
	private WorkflowValidator $validator;
	private GraphValidator $graph_validator;
	private CapabilitySource $source;

	public function __construct( ?CapabilitySource $source = null ) {
		$this->source = $source ?? new CapabilityCatalog();
		$this->validator = new WorkflowValidator( $this->source );
		$this->graph_validator = new GraphValidator( $this->source );
	}

	public function validate( Definition|GraphDefinition $definition ): ValidationResult {
		return $definition instanceof Definition
			? $this->validator->validate( $definition )
			: $this->graph_validator->validate( $definition );
	}

	public function create( string $name, Definition|GraphDefinition $definition, int $user_id ): int {
		if ( ! Schema::authoring_ready() ) { throw PersistenceFailure::schema(); }
		$validation = $this->validate( $definition );
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
		Definition|GraphDefinition $definition,
		int $user_id,
		bool $rebind_execution_principal = false
	): WorkflowSaveResult {
		$validation = $this->validate( $definition );
		if ( ! Schema::authoring_ready() ) { return WorkflowSaveResult::persistence_blocked( $validation ); }
		if ( ! EditorIntegerSafety::is_browser_safe( $definition ) ) {
			return WorkflowSaveResult::precision_blocked( $validation );
		}
		if ( ! PersistencePolicy::allows( $validation ) ) {
			return WorkflowSaveResult::persistence_blocked( $validation );
		}

		$current = WorkflowRepository::find( $id );
		if ( null === $current || $current->revision() !== $expected_revision ) {
			return WorkflowSaveResult::conflict( $validation );
		}

		// Never switch definition versions through Save. V2 stays disabled.
		if ( get_class( $definition ) !== get_class( $current->definition() ) ) {
			return WorkflowSaveResult::persistence_blocked( $validation );
		}
		if ( $definition instanceof GraphDefinition && (
			ActivationState::Disabled !== $activation_state
			|| ActivationState::Disabled !== $current->activation_state()
			|| $rebind_execution_principal
		) ) {
			return WorkflowSaveResult::persistence_blocked( $validation );
		}

		// The incoming browser definition is only the authorable projection.
		// Never interpret deliberately redacted stored fields as user deletions.
		// Block the entire write rather than re-persist prohibited literals.
		if ( ! EditorIntegerSafety::is_browser_safe( $current->definition() ) ) {
			return WorkflowSaveResult::precision_blocked( $validation );
		}

		$editor_candidate = $definition instanceof GraphDefinition
			? $definition // No Advanced browser projection exists in ADV-02A.
			: EditorSaveBoundary::reconcile(
				$current->definition(),
				$definition,
				$this->source
			);
		if ( null === $editor_candidate ) {
			return WorkflowSaveResult::persistence_blocked( $validation );
		}
		$definition = $editor_candidate;

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

		// An affected-row SQL CAS is necessary but not sufficient evidence for
		// Base History rebase. Hydrate the actual persisted row independently.
		// An intervening writer, lost read, or mismatched accepted content must
		// NEVER produce a Saved response or cause another write/retry.
		try {
			$readback = WorkflowRepository::find( $id );
			$receipt  = WorkflowSaveReceipt::verify(
				$readback,
				$id,
				$expected_revision,
				$name,
				$target_state,
				$definition,
				$principal_user_id,
				$user_id
			);
		} catch ( \Throwable ) {
			// A committed write may already exist. Refuse a false Saved result.
			return WorkflowSaveResult::readback_unverified( $validation );
		}

		if ( null === $receipt ) {
			return WorkflowSaveResult::readback_unverified( $validation );
		}

		if ( $target_state !== $activation_state ) {
			return WorkflowSaveResult::saved_disabled(
				$validation,
				$principal_user_id,
				$block_reason ?? WorkflowSaveResult::WORKFLOW_INVALID,
				$receipt
			);
		}

		return WorkflowSaveResult::saved( $validation, $target_state, $principal_user_id, $receipt );
	}
}
