<?php
declare(strict_types=1);

namespace CB\Automations\Template;

use CB\Automations\Provider\WordPress\CapabilityRegistrar;

defined( 'ABSPATH' ) || exit;

final class StarterTemplates {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'cb_automations_register_workflow_templates', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$provider = CapabilityRegistrar::PROVIDER;

		WorkflowTemplateRegistry::register( [
			'provider' => $provider,
			'id' => 'wordpress.welcome_user',
			'version' => '1',
			'title' => __( 'Welcome new users', 'core-blueprint-automations' ),
			'description' => __( 'Send a welcome email when a new WordPress user account is created.', 'core-blueprint-automations' ),
			'category' => 'wordpress',
			'definition' => [
				'definition_version' => 1,
				'trigger' => self::step( 'trigger_1', 'trigger', $provider, 'user.registered' ),
				'states' => [],
				'conditions' => [],
				'actions' => [
					self::step( 'action_1', 'action', $provider, 'mail.send', [
						'to' => self::output( 'trigger_1', 'user_email' ),
						'subject' => self::literal( __( 'Welcome to our website', 'core-blueprint-automations' ) ),
						'message' => self::literal( __( 'Welcome! Your WordPress account is ready.', 'core-blueprint-automations' ) ),
					] ),
				],
			],
		] );

		WorkflowTemplateRegistry::register( [
			'provider' => $provider,
			'id' => 'wordpress.notify_post_author',
			'version' => '1',
			'title' => __( 'Notify author when a post is published', 'core-blueprint-automations' ),
			'description' => __( 'Look up the post author and email them when their post is published.', 'core-blueprint-automations' ),
			'category' => 'wordpress',
			'definition' => [
				'definition_version' => 1,
				'trigger' => self::step( 'trigger_1', 'trigger', $provider, 'post.published' ),
				'states' => [
					self::step( 'state_1', 'state', $provider, 'post.current', [
						'post_id' => self::output( 'trigger_1', 'post_id' ),
					] ),
				],
				'conditions' => [],
				'actions' => [
					self::step( 'action_1', 'action', $provider, 'mail.send', [
						'to' => self::output( 'state_1', 'author_email' ),
						'subject' => self::literal( __( 'Your post has been published', 'core-blueprint-automations' ) ),
						'message' => self::literal( __( 'Your post is now published.', 'core-blueprint-automations' ) ),
					] ),
				],
			],
		] );

		WorkflowTemplateRegistry::register( [
			'provider' => $provider,
			'id' => 'wordpress.acknowledge_comment',
			'version' => '1',
			'title' => __( 'Acknowledge new comments', 'core-blueprint-automations' ),
			'description' => __( 'Email a commenter after a new comment is created, but only when an email address is available.', 'core-blueprint-automations' ),
			'category' => 'wordpress',
			'definition' => [
				'definition_version' => 1,
				'trigger' => self::step( 'trigger_1', 'trigger', $provider, 'comment.created' ),
				'states' => [],
				'conditions' => [
					[
						'condition_id' => 'condition_1',
						'left' => self::output( 'trigger_1', 'author_email' ),
						'operator' => 'is_not_empty',
						'right' => null,
					],
				],
				'actions' => [
					self::step( 'action_1', 'action', $provider, 'mail.send', [
						'to' => self::output( 'trigger_1', 'author_email' ),
						'subject' => self::literal( __( 'Thanks for your comment', 'core-blueprint-automations' ) ),
						'message' => self::literal( __( 'Thanks for taking the time to leave a comment.', 'core-blueprint-automations' ) ),
					] ),
				],
			],
		] );
	}

	/** @param array<string,array<string,mixed>> $bindings */
	private static function step( string $step_id, string $kind, string $provider, string $id, array $bindings = [] ): array {
		return [
			'step_id' => $step_id,
			'capability' => [
				'kind' => $kind,
				'provider' => $provider,
				'id' => $id,
				'schema_version' => '1',
			],
			'bindings' => $bindings,
		];
	}

	private static function output( string $step_id, string $field ): array {
		return [ 'source' => 'step_output', 'step_id' => $step_id, 'field' => $field ];
	}

	private static function literal( mixed $value ): array {
		return [ 'source' => 'literal', 'value' => $value ];
	}
}
