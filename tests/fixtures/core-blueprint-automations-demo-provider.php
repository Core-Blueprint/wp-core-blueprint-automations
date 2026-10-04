<?php
declare(strict_types=1);
/**
 * Plugin Name: Core Blueprint Automations — Demo Provider
 * Description: Development/staging-only Automation Foundation provider used to exercise the AU1 workflow editor. Do not use in production.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.4
 */

use CoreBlueprint\Core\Automation\ActionRegistry;
use CoreBlueprint\Core\Automation\StateRegistry;
use CoreBlueprint\Core\Automation\TriggerRegistry;
use CoreBlueprint\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

add_action(
	'core_blueprint_register_extensions',
	static function (): void {
		if ( ! class_exists( ExtensionRegistry::class ) ) {
			return;
		}

		ExtensionRegistry::register(
			[
				'id'            => 'cb-automations-fixture',
				'plugin_file'   => plugin_basename( __FILE__ ),
				'requires_api'  => '1.0',
				'requires_base' => '1.0.0-rc1',
				'menu_url'      => '',
				'status_id'     => '',
			]
		);
	}
);

add_action(
	'core_blueprint_register_automation_capabilities',
	static function (): void {
		if (
			! class_exists( TriggerRegistry::class )
			|| ! class_exists( StateRegistry::class )
			|| ! class_exists( ActionRegistry::class )
		) {
			return;
		}

		TriggerRegistry::register(
			[
				'provider'       => 'cb-automations-fixture',
				'id'             => 'order.paid',
				'label'          => 'Order paid',
				'description'    => 'Demo trigger fired after an order has been paid.',
				'schema_version' => '1',
				'payload_schema' => [
					'order_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'customer_email' => [
						'type'      => 'string',
						'required'  => true,
						'sensitive' => true,
					],
					'total' => [
						'type'     => 'number',
						'required' => true,
					],
					'tags' => [
						'type'     => 'array',
						'items'    => 'string',
						'required' => false,
					],
				],
			]
		);

		StateRegistry::register(
			[
				'provider'            => 'cb-automations-fixture',
				'id'                  => 'customer.current',
				'label'               => 'Current customer state',
				'description'         => 'Demo read-only state with numeric, boolean and sensitive output fields.',
				'schema_version'      => '1',
				'input_schema'        => [
					'email' => [
						'type'      => 'string',
						'required'  => true,
						'sensitive' => true,
					],
				],
				'output_schema'       => [
					'lifetime_value' => [
						'type'     => 'number',
						'required' => true,
					],
					'vip' => [
						'type'     => 'boolean',
						'required' => true,
					],
					'email' => [
						'type'      => 'string',
						'required'  => true,
						'sensitive' => true,
					],
				],
				'required_capability' => 'manage_options',
				'resolver'            => static fn ( array $input ): array => [
					'lifetime_value' => 1250.50,
					'vip'            => true,
					'email'          => (string) ( $input['email'] ?? '' ),
				],
			]
		);

		ActionRegistry::register(
			[
				'provider'            => 'cb-automations-fixture',
				'id'                  => 'followup.create',
				'label'               => 'Create follow-up',
				'description'         => 'Demo action with typed inputs for binding and editor review.',
				'schema_version'      => '1',
				'input_schema'        => [
					'order_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'customer_email' => [
						'type'      => 'string',
						'required'  => true,
						'sensitive' => true,
					],
					'priority' => [
						'type'     => 'string',
						'required' => false,
					],
				],
				'output_schema'       => [
					'follow_up_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
				'required_capability' => 'manage_options',
				'executor'            => static fn ( array $input ): array => [
					'follow_up_id' => 9001,
				],
			]
		);
	}
);
