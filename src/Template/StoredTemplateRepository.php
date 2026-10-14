<?php
declare(strict_types=1);
namespace CB\Automations\Template;

use CB\Automations\Admin\AutomationsPage;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Domain-owned templates in core WordPress storage. Never a Base table.
 * CPT is private, not public, not REST-visible and not registered in wp-admin.
 */
final class StoredTemplateRepository {
	public const TYPE = 'cb_auto_tpl';
	private const SCOPE_KEY = '_cb_auto_tpl_scope';
	private const SCOPES = [ 'personal', 'site' ];
	private const MAX_OFFSET = 100000;

	public static function register(): void {
		register_post_type( self::TYPE, [
			'label' => 'Automations template storage',
			'public' => false,
			'show_ui' => false,
			'show_in_rest' => false,
			'exclude_from_search' => true,
			'publicly_queryable' => false,
			'supports' => [ 'title', 'author' ],
			'rewrite' => false,
			'query_var' => false,
		] );
	}

	public static function authorized( int $actor ): bool {
		return $actor > 0 && user_can( $actor, AutomationsPage::CAPABILITY );
	}

	public static function template_id( int $id ): string {
		return 'saved-' . $id;
	}

	/** @return \WP_Post|null */
	public static function get( int $id, int $actor ): ?\WP_Post {
		if ( $id < 1 || ! self::authorized( $actor ) ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type
			|| 'private' !== $post->post_status ) {
			return null;
		}
		$scope = (string) get_post_meta( $id, self::SCOPE_KEY, true );
		if ( 'personal' === $scope && (int) $post->post_author === $actor ) {
			return $post;
		}
		return 'site' === $scope ? $post : null;
	}

	public static function can_manage( int $id, int $actor ): bool {
		$post = self::get( $id, $actor );
		if ( null === $post ) {
			return false;
		}
		$scope = (string) get_post_meta( $id, self::SCOPE_KEY, true );
		return 'site' === $scope ? self::authorized( $actor ) : (int) $post->post_author === $actor;
	}

	/** @return 'personal'|'site' */
	public static function scope( int $id ): string {
		return 'site' === get_post_meta( $id, self::SCOPE_KEY, true ) ? 'site' : 'personal';
	}

	public static function definition( int $id, int $actor ): ?Definition {
		$post = self::get( $id, $actor );
		if ( null === $post || ! PersistencePolicy::allows_encoded_definition( $post->post_content ) ) {
			return null;
		}
		try {
			$data = json_decode( $post->post_content, true, 64, JSON_THROW_ON_ERROR );
			return is_array( $data ) ? TemplateSafety::scrub( DefinitionCodec::decode( $data ) ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** Create a new independent template from an accessible workflow. */
	public static function save_workflow( int $workflow_id, string $name, string $scope, int $actor ): int {
		if ( ! self::authorized( $actor ) || ! in_array( $scope, self::SCOPES, true ) ) {
			throw new \DomainException( 'Template scope or actor is not authorized.' );
		}
		$workflow = WorkflowRepository::find( $workflow_id );
		if ( null === $workflow ) {
			throw new \DomainException( 'Source workflow is unavailable.' );
		}
		// A user's personal library must not silently capture someone else's
		// stored workflow even if their operator role can view it.
		if ( 'personal' === $scope && $workflow->created_by() !== $actor ) {
			throw new \DomainException( 'Personal template requires source ownership.' );
		}
		return self::insert( $name, $scope, TemplateSafety::scrub( $workflow->definition() ), $actor );
	}

	public static function duplicate( int $id, int $actor ): int {
		$source = self::get( $id, $actor );
		$definition = self::definition( $id, $actor );
		if ( null === $source || null === $definition ) {
			throw new \DomainException( 'Template unavailable.' );
		}
		// Duplicates are ALWAYS actor-personal; no implicit elevation to site.
		return self::insert( $source->post_title, 'personal', $definition, $actor );
	}

	public static function rename( int $id, string $name, int $actor ): bool {
		if ( ! self::can_manage( $id, $actor ) ) {
			return false;
		}
		$name = self::normalize_name( $name );
		$result = wp_update_post( [ 'ID' => $id, 'post_title' => $name ], true );
		return ! is_wp_error( $result ) && (int) $result === $id;
	}

	public static function remove( int $id, int $actor ): bool {
		if ( ! self::can_manage( $id, $actor ) ) {
			return false;
		}
		return wp_delete_post( $id, true ) instanceof \WP_Post;
	}

	/** @return array{items:list<\WP_Post>,next:?string} */
	public static function search( int $actor, string $origin, string $search, int $limit, ?string $cursor ): array {
		global $wpdb;
		if ( ! self::authorized( $actor ) || ! in_array( $origin, [ '', 'personal', 'site' ], true )
			|| $limit < 1 || $limit > 48 || strlen( $search ) > 120 ) {
			return [ 'items' => [], 'next' => null ];
		}
		$offset = null === $cursor ? 0 : ( ctype_digit( $cursor ) ? (int) $cursor : self::MAX_OFFSET + 1 );
		if ( $offset > self::MAX_OFFSET ) {
			return [ 'items' => [], 'next' => null ];
		}
		$conditions = [
			'p.post_type = %s',
			"p.post_status = 'private'",
			"m.meta_key = %s",
			"((m.meta_value = 'personal' AND p.post_author = %d) OR m.meta_value = 'site')",
		];
		$params = [ self::TYPE, self::SCOPE_KEY, $actor ];
		if ( '' !== $origin ) {
			$conditions[] = 'm.meta_value = %s';
			$params[] = $origin;
		}
		if ( '' !== $search ) {
			$conditions[] = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s)';
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			array_push( $params, $like, $like );
		}
		$params[] = $limit + 1;
		$params[] = $offset;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE "
			. implode( ' AND ', $conditions ) . ' ORDER BY p.ID ASC LIMIT %d OFFSET %d';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
		if ( ! is_array( $ids ) ) {
			throw new \RuntimeException( 'Template index unavailable.' );
		}
		$has_more = count( $ids ) > $limit;
		$ids = array_slice( $ids, 0, $limit );
		$items = [];
		foreach ( $ids as $id ) {
			$post = self::get( (int) $id, $actor );
			if ( null !== $post ) {
				$items[] = $post;
			}
		}
		return [ 'items' => $items, 'next' => $has_more ? (string) ( $offset + $limit ) : null ];
	}

	private static function insert( string $name, string $scope, Definition $definition, int $actor ): int {
		$name = self::normalize_name( $name );
		$data = wp_json_encode( DefinitionCodec::encode( TemplateSafety::scrub( $definition ) ) );
		if ( ! is_string( $data ) || ! PersistencePolicy::allows_encoded_definition( $data ) ) {
			throw new \DomainException( 'Unsafe template content.' );
		}
		$id = wp_insert_post( [
			'post_type' => self::TYPE,
			'post_status' => 'private',
			'post_title' => $name,
			'post_content' => $data,
			'post_author' => $actor,
			'meta_input' => [ self::SCOPE_KEY => $scope ],
		], true );
		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			throw new \RuntimeException( 'Could not persist template.' );
		}
		return (int) $id;
	}

	private static function normalize_name( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name || strlen( $name ) > 191 ) {
			throw new \InvalidArgumentException( 'Invalid template name.' );
		}
		return $name;
	}

	private function __construct() {}
}
