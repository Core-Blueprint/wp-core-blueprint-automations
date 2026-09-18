<?php
declare(strict_types=1);

namespace CB\Automations\Template;

use CB\Automations\Workflow\DefinitionCodec;
use CB\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

final class WorkflowTemplateRegistry {
	/** @var array<string,WorkflowTemplate> */
	private static array $templates = [];
	private static bool $collected = false;

	/** @param array<string,mixed> $definition */
	public static function register( array $definition ): bool {
		if ( ! doing_action( 'cb_automations_register_workflow_templates' ) ) {
			return false;
		}

		$allowed = [ 'provider', 'id', 'version', 'title', 'description', 'category', 'definition' ];
		if ( [] !== array_diff( array_keys( $definition ), $allowed ) ) {
			return false;
		}

		$provider = isset( $definition['provider'] ) && is_string( $definition['provider'] ) ? trim( $definition['provider'] ) : '';
		$id = isset( $definition['id'] ) && is_string( $definition['id'] ) ? trim( $definition['id'] ) : '';
		$version = isset( $definition['version'] ) && is_string( $definition['version'] ) ? trim( $definition['version'] ) : '';
		$title = isset( $definition['title'] ) && is_string( $definition['title'] ) ? trim( wp_strip_all_tags( $definition['title'] ) ) : '';
		$description = isset( $definition['description'] ) && is_string( $definition['description'] ) ? trim( wp_strip_all_tags( $definition['description'] ) ) : '';
		$category = isset( $definition['category'] ) && is_string( $definition['category'] ) ? sanitize_key( $definition['category'] ) : '';
		$encoded = $definition['definition'] ?? null;

		if (
			! ExtensionRegistry::is_valid_id( $provider )
			|| null === ExtensionRegistry::definition( $provider )
			|| 1 !== preg_match( '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/D', $id )
			|| 1 !== preg_match( '/^[1-9][0-9]*$/D', $version )
			|| '' === $title
			|| strlen( $title ) > 191
			|| strlen( $description ) > 500
			|| '' === $category
			|| ! is_array( $encoded )
		) {
			return false;
		}

		try {
			$workflow = DefinitionCodec::decode( $encoded );
		} catch ( \Throwable ) {
			return false;
		}

		$template = new WorkflowTemplate( $provider, $id, $version, $title, $description, $category, $workflow );
		if ( isset( self::$templates[ $template->key() ] ) ) {
			return false;
		}
		self::$templates[ $template->key() ] = $template;
		return true;
	}

	/** @return WorkflowTemplate[] */
	public static function all(): array {
		self::collect();
		$templates = array_values( self::$templates );
		usort( $templates, static fn ( WorkflowTemplate $a, WorkflowTemplate $b ): int => strcasecmp( $a->title(), $b->title() ) );
		return $templates;
	}

	public static function get( string $provider, string $id ): ?WorkflowTemplate {
		self::collect();
		return self::$templates[ trim( $provider ) . '::' . trim( $id ) ] ?? null;
	}

	private static function collect(): void {
		if ( self::$collected ) {
			return;
		}
		self::$collected = true;
		/**
		 * Register workflow templates exposed by Automations providers.
		 *
		 * Plugins should attach callbacks during normal plugin loading and call
		 * WorkflowTemplateRegistry::register() only during this lifecycle.
		 */
		do_action( 'cb_automations_register_workflow_templates' );
	}
}
