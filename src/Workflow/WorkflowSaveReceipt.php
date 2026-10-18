<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Runtime\TriggerKey;

defined( 'ABSPATH' ) || exit;

/**
 * AU-DC-02: current, independently hydrated persisted-row proof after SQL CAS.
 * Never expose the stored definition or its hash to a browser: the controller
 * may acknowledge the submitted authoring JSON only after this proof succeeds.
 */
final class WorkflowSaveReceipt {
	/**
	 * @return array{workflow_id:string,revision:string,name:string,activation_state:string,execution_principal_user_id:int}|null
	 */
	public static function verify(
		?WorkflowRecord $readback,
		int $workflow_id,
		int $expected_revision,
		string $submitted_name,
		ActivationState $accepted_state,
		Definition $accepted_definition,
		int $execution_principal_user_id,
		int $actor_user_id
	): ?array {
		if (
			null === $readback
			|| $workflow_id < 1
			|| $expected_revision < 1
			|| $expected_revision >= PHP_INT_MAX
			|| $readback->id() !== $workflow_id
			|| $readback->revision() !== $expected_revision + 1
			|| $readback->name() !== trim( wp_strip_all_tags( $submitted_name ) )
			|| $readback->activation_state() !== $accepted_state
			|| $readback->execution_principal_user_id() !== $execution_principal_user_id
			|| $readback->updated_by() !== $actor_user_id
			|| $readback->trigger_key() !== TriggerKey::for_definition( $accepted_definition )
			|| DefinitionCodec::encode( $readback->definition() ) !== DefinitionCodec::encode( $accepted_definition )
		) {
			return null;
		}

		return [
			'workflow_id'                => (string) $readback->id(),
			'revision'                   => (string) $readback->revision(),
			'name'                       => $readback->name(),
			'activation_state'           => $readback->activation_state()->value,
			'execution_principal_user_id' => $readback->execution_principal_user_id(),
		];
	}
}
