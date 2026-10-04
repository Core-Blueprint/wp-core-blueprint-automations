<?php
declare(strict_types=1);

namespace CB\Automations\Provider\WordPress;

use CoreBlueprint\Core\Automation\Emitter;

defined( 'ABSPATH' ) || exit;

final class TriggerListeners {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'user_register', [ self::class, 'user_registered' ], 10, 2 );
		add_action( 'profile_update', [ self::class, 'user_updated' ], 10, 3 );
		add_action( 'deleted_user', [ self::class, 'user_deleted' ], 10, 3 );
		add_action( 'set_user_role', [ self::class, 'user_role_changed' ], 10, 3 );
		add_action( 'add_user_role', [ self::class, 'user_role_added' ], 10, 2 );
		add_action( 'remove_user_role', [ self::class, 'user_role_removed' ], 10, 2 );
		add_action( 'wp_login', [ self::class, 'user_logged_in' ], 10, 2 );
		add_action( 'wp_logout', [ self::class, 'user_logged_out' ], 10, 1 );
		add_action( 'wp_login_failed', [ self::class, 'user_login_failed' ], 10, 2 );
		add_action( 'password_reset', [ self::class, 'user_password_reset' ], 10, 2 );

		add_action( 'wp_after_insert_post', [ self::class, 'post_inserted' ], 10, 4 );
		add_action( 'post_updated', [ self::class, 'post_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ self::class, 'post_status_changed' ], 10, 3 );
		add_action( 'trashed_post', [ self::class, 'post_trashed' ], 10, 2 );
		add_action( 'untrashed_post', [ self::class, 'post_restored' ], 10, 2 );
		add_action( 'deleted_post', [ self::class, 'post_deleted' ], 10, 2 );

		add_action( 'wp_insert_comment', [ self::class, 'comment_created' ], 10, 2 );
		add_action( 'edit_comment', [ self::class, 'comment_updated' ], 10, 1 );
		add_action( 'transition_comment_status', [ self::class, 'comment_status_changed' ], 10, 3 );
		add_action( 'trashed_comment', [ self::class, 'comment_trashed' ], 10, 2 );
		add_action( 'untrashed_comment', [ self::class, 'comment_restored' ], 10, 2 );
		add_action( 'deleted_comment', [ self::class, 'comment_deleted' ], 10, 2 );

		add_action( 'add_attachment', [ self::class, 'attachment_created' ] );
		add_action( 'edit_attachment', [ self::class, 'attachment_updated' ] );
		add_action( 'delete_attachment', [ self::class, 'attachment_deleted' ] );

		add_action( 'created_term', [ self::class, 'term_created' ], 10, 3 );
		add_action( 'edited_term', [ self::class, 'term_updated' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'term_deleted' ], 10, 5 );
		add_action( 'set_object_terms', [ self::class, 'object_terms_changed' ], 10, 6 );

		add_action( 'activated_plugin', [ self::class, 'plugin_activated' ], 10, 2 );
		add_action( 'deactivated_plugin', [ self::class, 'plugin_deactivated' ], 10, 2 );
		add_action( 'switch_theme', [ self::class, 'theme_switched' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ self::class, 'update_completed' ], 10, 2 );
	}

	public static function user_registered( int $user_id, array $userdata ): void {
		unset( $userdata );
		self::emit_user( 'user.registered', $user_id );
	}

	public static function user_updated( int $user_id, \WP_User $old_user_data, array $userdata ): void {
		unset( $old_user_data, $userdata );
		self::emit_user( 'user.updated', $user_id );
	}

	public static function user_deleted( int $user_id, ?int $reassign, \WP_User $user ): void {
		unset( $user );
		$payload = [ 'user_id' => $user_id ];
		if ( null !== $reassign && $reassign > 0 ) {
			$payload['reassign_to'] = $reassign;
		}
		self::emit( 'user.deleted', $payload );
	}

	public static function user_role_changed( int $user_id, string $role, array $old_roles ): void {
		self::emit( 'user.role_changed', [
			'user_id' => $user_id,
			'new_role' => $role,
			'old_roles' => array_values( array_map( 'strval', $old_roles ) ),
		] );
	}

	public static function user_role_added( int $user_id, string $role ): void {
		self::emit( 'user.role_added', [ 'user_id' => $user_id, 'role' => $role ] );
	}

	public static function user_role_removed( int $user_id, string $role ): void {
		self::emit( 'user.role_removed', [ 'user_id' => $user_id, 'role' => $role ] );
	}

	public static function user_logged_in( string $user_login, \WP_User $user ): void {
		unset( $user_login );
		self::emit_user( 'user.logged_in', (int) $user->ID );
	}

	public static function user_logged_out( int $user_id ): void {
		self::emit( 'user.logged_out', [ 'user_id' => $user_id ] );
	}

	public static function user_login_failed( string $username, \WP_Error $error ): void {
		self::emit( 'user.login_failed', [
			'login' => $username,
			'error_code' => (string) $error->get_error_code(),
		] );
	}

	public static function user_password_reset( \WP_User $user, string $new_pass ): void {
		unset( $new_pass );
		self::emit( 'user.password_reset', [ 'user_id' => (int) $user->ID ] );
	}

	public static function post_inserted( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		unset( $post_id );
		if ( ! self::is_content_post( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$is_first_persisted_version = ! $update
			|| ( $post_before instanceof \WP_Post && 'auto-draft' === $post_before->post_status );

		if ( $is_first_persisted_version ) {
			self::emit( 'post.created', self::post_payload( $post ) );
		}
	}

	public static function post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		unset( $post_id );
		if (
			! self::is_content_post( $post_after )
			|| 'auto-draft' === $post_after->post_status
			|| 'auto-draft' === $post_before->post_status
		) {
			return;
		}
		self::emit( 'post.updated', self::post_payload( $post_after ) );
	}

	public static function post_status_changed( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || ! self::is_content_post( $post ) || 'auto-draft' === $new_status ) {
			return;
		}
		$payload = self::post_payload( $post ) + [
			'old_status' => $old_status,
			'new_status' => $new_status,
		];
		self::emit( 'post.status_changed', $payload );
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			self::emit( 'post.published', self::post_payload( $post ) );
		}
	}

	public static function post_trashed( int $post_id, string $previous_status ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && self::is_content_post( $post ) ) {
			self::emit( 'post.trashed', self::post_payload( $post ) + [ 'previous_status' => $previous_status ] );
		}
	}

	public static function post_restored( int $post_id, string $previous_status ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && self::is_content_post( $post ) ) {
			self::emit( 'post.restored', self::post_payload( $post ) + [ 'previous_status' => $previous_status ] );
		}
	}

	public static function post_deleted( int $post_id, \WP_Post $post ): void {
		unset( $post_id );
		if ( self::is_content_post( $post ) ) {
			self::emit( 'post.deleted', self::post_payload( $post ) );
		}
	}

	public static function comment_created( int $comment_id, \WP_Comment $comment ): void {
		unset( $comment_id );
		self::emit( 'comment.created', self::comment_payload( $comment ) );
	}

	public static function comment_updated( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			self::emit( 'comment.updated', self::comment_payload( $comment ) );
		}
	}

	public static function comment_status_changed( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( $new_status === $old_status ) {
			return;
		}
		self::emit( 'comment.status_changed', self::comment_payload( $comment ) + [
			'old_status' => $old_status,
			'new_status' => $new_status,
		] );
	}

	public static function comment_trashed( string $comment_id, \WP_Comment $comment ): void {
		unset( $comment_id );
		self::emit( 'comment.trashed', self::comment_payload( $comment ) );
	}

	public static function comment_restored( string $comment_id, \WP_Comment $comment ): void {
		unset( $comment_id );
		self::emit( 'comment.restored', self::comment_payload( $comment ) );
	}

	public static function comment_deleted( string $comment_id, \WP_Comment $comment ): void {
		unset( $comment_id );
		self::emit( 'comment.deleted', self::comment_payload( $comment ) );
	}

	public static function attachment_created( int $attachment_id ): void {
		self::emit_attachment( 'attachment.created', $attachment_id );
	}

	public static function attachment_updated( int $attachment_id ): void {
		self::emit_attachment( 'attachment.updated', $attachment_id );
	}

	public static function attachment_deleted( int $attachment_id ): void {
		self::emit_attachment( 'attachment.deleted', $attachment_id );
	}

	public static function term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		self::emit_term( 'term.created', $term_id, $taxonomy );
	}

	public static function term_updated( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		self::emit_term( 'term.updated', $term_id, $taxonomy );
	}

	public static function term_deleted( int $term_id, int $tt_id, string $taxonomy, \WP_Term $deleted_term, array $object_ids ): void {
		unset( $tt_id, $object_ids );
		self::emit( 'term.deleted', self::term_payload( $deleted_term, $taxonomy, $term_id ) );
	}

	public static function object_terms_changed( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms );
		self::emit( 'object.terms_changed', [
			'object_id' => $object_id,
			'taxonomy' => $taxonomy,
			'term_taxonomy_ids' => array_values( array_map( 'intval', $tt_ids ) ),
			'old_term_taxonomy_ids' => array_values( array_map( 'intval', $old_tt_ids ) ),
			'append' => $append,
		] );
	}

	public static function plugin_activated( string $plugin, bool $network_wide ): void {
		self::emit( 'plugin.activated', [ 'plugin' => $plugin, 'network_wide' => $network_wide ] );
	}

	public static function plugin_deactivated( string $plugin, bool $network_wide ): void {
		self::emit( 'plugin.deactivated', [ 'plugin' => $plugin, 'network_wide' => $network_wide ] );
	}

	public static function theme_switched( string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
		unset( $old_theme );
		self::emit( 'theme.switched', [
			'theme_name' => $new_name,
			'stylesheet' => (string) $new_theme->get_stylesheet(),
		] );
	}

	public static function update_completed( \WP_Upgrader $upgrader, array $hook_extra ): void {
		unset( $upgrader );
		$type = isset( $hook_extra['type'] ) && is_string( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) && is_string( $hook_extra['action'] ) ? $hook_extra['action'] : '';
		if ( '' === $type || '' === $action ) {
			return;
		}

		$items = [];
		foreach ( [ 'plugin', 'theme' ] as $single_key ) {
			if ( isset( $hook_extra[ $single_key ] ) && is_string( $hook_extra[ $single_key ] ) && '' !== $hook_extra[ $single_key ] ) {
				$items[] = $hook_extra[ $single_key ];
			}
		}
		foreach ( [ 'plugins', 'themes' ] as $list_key ) {
			if ( ! isset( $hook_extra[ $list_key ] ) || ! is_array( $hook_extra[ $list_key ] ) ) {
				continue;
			}
			foreach ( $hook_extra[ $list_key ] as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$items[] = $item;
				}
			}
		}
		if ( 'core' === $type && [] === $items ) {
			$items[] = 'wordpress-core';
		}

		$payload = [ 'type' => $type, 'action' => $action ];
		if ( [] !== $items ) {
			$payload['items'] = array_values( array_unique( $items ) );
		}
		self::emit( 'update.completed', $payload );
	}

	private static function emit_user( string $trigger_id, int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		self::emit( $trigger_id, [
			'user_id' => (int) $user->ID,
			'user_login' => (string) $user->user_login,
			'user_email' => (string) $user->user_email,
			'roles' => array_values( array_map( 'strval', $user->roles ) ),
		] );
	}

	private static function emit_attachment( string $trigger_id, int $attachment_id ): void {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}
		self::emit( $trigger_id, self::attachment_payload( $post ) );
	}

	private static function emit_term( string $trigger_id, int $term_id, string $taxonomy ): void {
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			self::emit( $trigger_id, self::term_payload( $term, $taxonomy, $term_id ) );
		}
	}

	/** @param array<string,mixed> $payload */
	private static function emit( string $trigger_id, array $payload ): void {
		try {
			Emitter::emit( CapabilityRegistrar::PROVIDER, $trigger_id, $payload );
		} catch ( \Throwable ) {
			// Native WordPress requests must never fail because automation delivery failed.
		}
	}

	private static function is_content_post( \WP_Post $post ): bool {
		return ! in_array( $post->post_type, [ 'revision', 'attachment' ], true ) && ! wp_is_post_autosave( $post );
	}

	/** @return array<string,mixed> */
	private static function post_payload( \WP_Post $post ): array {
		return [
			'post_id' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'author_id' => (int) $post->post_author,
			'post_parent' => (int) $post->post_parent,
			'post_title' => (string) $post->post_title,
		];
	}

	/** @return array<string,mixed> */
	private static function comment_payload( \WP_Comment $comment ): array {
		return [
			'comment_id' => (int) $comment->comment_ID,
			'post_id' => (int) $comment->comment_post_ID,
			'user_id' => (int) $comment->user_id,
			'status' => (string) wp_get_comment_status( $comment ),
			'type' => (string) $comment->comment_type,
			'author_email' => (string) $comment->comment_author_email,
		];
	}

	/** @return array<string,mixed> */
	private static function attachment_payload( \WP_Post $post ): array {
		return [
			'attachment_id' => (int) $post->ID,
			'parent_id' => (int) $post->post_parent,
			'author_id' => (int) $post->post_author,
			'mime_type' => (string) $post->post_mime_type,
			'title' => (string) $post->post_title,
		];
	}

	/** @return array<string,mixed> */
	private static function term_payload( \WP_Term $term, string $taxonomy, int $term_id ): array {
		return [
			'term_id' => $term_id,
			'taxonomy' => $taxonomy,
			'parent_id' => (int) $term->parent,
		];
	}
}
