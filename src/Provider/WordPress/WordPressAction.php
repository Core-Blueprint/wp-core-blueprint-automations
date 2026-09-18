<?php
declare(strict_types=1);

namespace CB\Automations\Provider\WordPress;

use CB\Core\Automation\InvocationContext;

defined( 'ABSPATH' ) || exit;

final class WordPressAction {
	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function create_user( array $input, InvocationContext $context ): array|\WP_Error {
		if ( ! user_can( $context->principal_user_id(), 'create_users' ) ) {
			return self::denied();
		}
		$login = sanitize_user( (string) ( $input['user_login'] ?? '' ), true );
		$email = sanitize_email( (string) ( $input['user_email'] ?? '' ) );
		if ( '' === $login || '' === $email || ! is_email( $email ) || username_exists( $login ) || email_exists( $email ) ) {
			return self::invalid( 'user' );
		}

		$data = [
			'user_login' => $login,
			'user_email' => $email,
			'user_pass' => wp_generate_password( 24, true, true ),
		];
		if ( array_key_exists( 'display_name', $input ) ) {
			$data['display_name'] = (string) $input['display_name'];
		}
		if ( array_key_exists( 'role', $input ) ) {
			$role = sanitize_key( (string) $input['role'] );
			if ( '' === $role || ! wp_roles()->is_role( $role ) || ! user_can( $context->principal_user_id(), 'promote_users' ) ) {
				return self::denied();
			}
			$data['role'] = $role;
		}

		$result = wp_insert_user( $data );
		if ( is_wp_error( $result ) || $result < 1 ) {
			return self::failed( 'user_create' );
		}
		if ( true === ( $input['send_notification'] ?? false ) ) {
			wp_new_user_notification( (int) $result, null, 'user' );
		}
		return [ 'user_id' => (int) $result ];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function update_user( array $input, InvocationContext $context ): array|\WP_Error {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id < 1 || ! get_userdata( $user_id ) instanceof \WP_User ) {
			return self::missing( 'user' );
		}
		if ( ! user_can( $context->principal_user_id(), 'edit_user', $user_id ) ) {
			return self::denied();
		}

		$data = [ 'ID' => $user_id ];
		foreach ( [ 'display_name', 'first_name', 'last_name', 'user_email' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = (string) $input[ $field ];
			}
		}
		if ( isset( $data['user_email'] ) && ! is_email( $data['user_email'] ) ) {
			return self::invalid( 'email' );
		}

		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			return self::failed( 'user_update' );
		}
		return [ 'user_id' => (int) $result ];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function add_user_role( array $input, InvocationContext $context ): array|\WP_Error {
		return self::change_user_role( $input, $context, true );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function remove_user_role( array $input, InvocationContext $context ): array|\WP_Error {
		return self::change_user_role( $input, $context, false );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private static function change_user_role( array $input, InvocationContext $context, bool $add ): array|\WP_Error {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		$role = sanitize_key( (string) ( $input['role'] ?? '' ) );
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			return self::missing( 'user' );
		}
		if ( '' === $role || ! wp_roles()->is_role( $role ) ) {
			return self::invalid( 'role' );
		}
		if ( ! user_can( $context->principal_user_id(), 'promote_user', $user_id ) ) {
			return self::denied();
		}

		if ( $add ) {
			$user->add_role( $role );
		} else {
			$user->remove_role( $role );
		}
		return [];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function create_post( array $input, InvocationContext $context ): array|\WP_Error {
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$type = get_post_type_object( $post_type );
		if ( ! $type || in_array( $post_type, [ 'attachment', 'revision' ], true ) ) {
			return self::invalid( 'post_type' );
		}
		$principal = $context->principal_user_id();
		$create_cap = isset( $type->cap->create_posts ) ? (string) $type->cap->create_posts : 'edit_posts';
		if ( ! user_can( $principal, $create_cap ) ) {
			return self::denied();
		}
		$status = isset( $input['post_status'] ) ? sanitize_key( (string) $input['post_status'] ) : 'draft';
		if ( 'publish' === $status ) {
			$publish_cap = isset( $type->cap->publish_posts ) ? (string) $type->cap->publish_posts : 'publish_posts';
			if ( ! user_can( $principal, $publish_cap ) ) {
				return self::denied();
			}
		}

		$data = [
			'post_type' => $post_type,
			'post_title' => (string) ( $input['post_title'] ?? '' ),
			'post_status' => $status,
			'post_author' => $principal,
		];
		foreach ( [ 'post_content', 'post_excerpt' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = (string) $input[ $field ];
			}
		}
		$result = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) || $result < 1 ) {
			return self::failed( 'post_create' );
		}
		return [ 'post_id' => (int) $result ];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function update_post( array $input, InvocationContext $context ): array|\WP_Error {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return self::missing( 'post' );
		}
		if ( ! user_can( $context->principal_user_id(), 'edit_post', $post_id ) ) {
			return self::denied();
		}
		$data = [ 'ID' => $post_id ];
		foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = (string) $input[ $field ];
			}
		}
		$result = wp_update_post( wp_slash( $data ), true );
		return is_wp_error( $result ) ? self::failed( 'post_update' ) : [];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function change_post_status( array $input, InvocationContext $context ): array|\WP_Error {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return self::missing( 'post' );
		}
		if ( ! user_can( $context->principal_user_id(), 'edit_post', $post_id ) ) {
			return self::denied();
		}
		$status = sanitize_key( (string) ( $input['post_status'] ?? '' ) );
		if ( '' === $status || null === get_post_status_object( $status ) ) {
			return self::invalid( 'post_status' );
		}
		if ( 'publish' === $status ) {
			$type = get_post_type_object( $post->post_type );
			$cap = $type && isset( $type->cap->publish_posts ) ? (string) $type->cap->publish_posts : 'publish_posts';
			if ( ! user_can( $context->principal_user_id(), $cap ) ) {
				return self::denied();
			}
		}
		$result = wp_update_post( [ 'ID' => $post_id, 'post_status' => $status ], true );
		return is_wp_error( $result ) ? self::failed( 'post_status' ) : [];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function trash_post( array $input, InvocationContext $context ): array|\WP_Error {
		return self::delete_post_operation( $input, $context, 'trash' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function restore_post( array $input, InvocationContext $context ): array|\WP_Error {
		return self::delete_post_operation( $input, $context, 'restore' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function delete_post( array $input, InvocationContext $context ): array|\WP_Error {
		return self::delete_post_operation( $input, $context, 'delete' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private static function delete_post_operation( array $input, InvocationContext $context, string $operation ): array|\WP_Error {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return self::missing( 'post' );
		}
		if ( ! user_can( $context->principal_user_id(), 'delete_post', $post_id ) ) {
			return self::denied();
		}
		$result = match ( $operation ) {
			'trash' => wp_trash_post( $post_id ),
			'restore' => wp_untrash_post( $post_id ),
			'delete' => wp_delete_post( $post_id, true ),
			default => false,
		};
		return $result instanceof \WP_Post ? [] : self::failed( 'post_' . $operation );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function change_comment_status( array $input, InvocationContext $context ): array|\WP_Error {
		$comment_id = (int) ( $input['comment_id'] ?? 0 );
		if ( ! get_comment( $comment_id ) instanceof \WP_Comment ) {
			return self::missing( 'comment' );
		}
		if ( ! user_can( $context->principal_user_id(), 'edit_comment', $comment_id ) ) {
			return self::denied();
		}
		$status = sanitize_key( (string) ( $input['status'] ?? '' ) );
		if ( ! in_array( $status, [ 'hold', 'approve', 'spam', 'trash' ], true ) ) {
			return self::invalid( 'comment_status' );
		}
		return wp_set_comment_status( $comment_id, $status, true ) ? [] : self::failed( 'comment_status' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function trash_comment( array $input, InvocationContext $context ): array|\WP_Error {
		return self::comment_operation( $input, $context, 'trash' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function restore_comment( array $input, InvocationContext $context ): array|\WP_Error {
		return self::comment_operation( $input, $context, 'restore' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function delete_comment( array $input, InvocationContext $context ): array|\WP_Error {
		return self::comment_operation( $input, $context, 'delete' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	private static function comment_operation( array $input, InvocationContext $context, string $operation ): array|\WP_Error {
		$comment_id = (int) ( $input['comment_id'] ?? 0 );
		if ( ! get_comment( $comment_id ) instanceof \WP_Comment ) {
			return self::missing( 'comment' );
		}
		if ( ! user_can( $context->principal_user_id(), 'edit_comment', $comment_id ) ) {
			return self::denied();
		}
		$result = match ( $operation ) {
			'trash' => wp_trash_comment( $comment_id ),
			'restore' => wp_untrash_comment( $comment_id ),
			'delete' => wp_delete_comment( $comment_id, true ),
			default => false,
		};
		return $result ? [] : self::failed( 'comment_' . $operation );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function create_term( array $input, InvocationContext $context ): array|\WP_Error {
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		if ( ! self::can_manage_taxonomy( $taxonomy, $context ) ) {
			return taxonomy_exists( $taxonomy ) ? self::denied() : self::invalid( 'taxonomy' );
		}
		$args = [];
		foreach ( [ 'slug', 'parent_id' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$args[ 'parent_id' === $field ? 'parent' : $field ] = 'parent_id' === $field ? (int) $input[ $field ] : (string) $input[ $field ];
			}
		}
		$result = wp_insert_term( (string) ( $input['name'] ?? '' ), $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return self::failed( 'term_create' );
		}
		return [ 'term_id' => (int) $result['term_id'] ];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function update_term( array $input, InvocationContext $context ): array|\WP_Error {
		$term_id = (int) ( $input['term_id'] ?? 0 );
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		if ( ! get_term( $term_id, $taxonomy ) instanceof \WP_Term ) {
			return self::missing( 'term' );
		}
		if ( ! self::can_manage_taxonomy( $taxonomy, $context ) ) {
			return self::denied();
		}
		$args = [];
		foreach ( [ 'name', 'slug', 'parent_id' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$args[ 'parent_id' === $field ? 'parent' : $field ] = 'parent_id' === $field ? (int) $input[ $field ] : (string) $input[ $field ];
			}
		}
		$result = wp_update_term( $term_id, $taxonomy, $args );
		return is_wp_error( $result ) ? self::failed( 'term_update' ) : [];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function delete_term( array $input, InvocationContext $context ): array|\WP_Error {
		$term_id = (int) ( $input['term_id'] ?? 0 );
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		if ( ! get_term( $term_id, $taxonomy ) instanceof \WP_Term ) {
			return self::missing( 'term' );
		}
		if ( ! self::can_manage_taxonomy( $taxonomy, $context ) ) {
			return self::denied();
		}
		$result = wp_delete_term( $term_id, $taxonomy );
		return is_wp_error( $result ) || false === $result ? self::failed( 'term_delete' ) : [];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function set_post_terms( array $input, InvocationContext $context ): array|\WP_Error {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return self::missing( 'post' );
		}
		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_object ) {
			return self::invalid( 'taxonomy' );
		}
		if (
			! user_can( $context->principal_user_id(), 'edit_post', $post_id )
			|| ! user_can( $context->principal_user_id(), (string) $taxonomy_object->cap->assign_terms )
		) {
			return self::denied();
		}
		$terms = array_values( array_map( 'intval', (array) ( $input['term_ids'] ?? [] ) ) );
		$append = (bool) ( $input['append'] ?? false );
		$result = wp_set_object_terms( $post_id, $terms, $taxonomy, $append );
		return is_wp_error( $result ) ? self::failed( 'set_terms' ) : [];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function send_mail( array $input, InvocationContext $context ): array|\WP_Error {
		unset( $context );
		$to = sanitize_email( (string) ( $input['to'] ?? '' ) );
		$subject = (string) ( $input['subject'] ?? '' );
		$message = (string) ( $input['message'] ?? '' );
		if ( '' === $to || ! is_email( $to ) || strlen( $to ) > 254 || strlen( $subject ) > 998 || strlen( $message ) > 100000 ) {
			return self::invalid( 'mail' );
		}
		return [ 'sent' => (bool) wp_mail( $to, $subject, $message ) ];
	}

	private static function can_manage_taxonomy( string $taxonomy, InvocationContext $context ): bool {
		$object = get_taxonomy( $taxonomy );
		return $object && user_can( $context->principal_user_id(), (string) $object->cap->manage_terms );
	}

	private static function denied(): \WP_Error {
		return new \WP_Error( 'cb_automations_wordpress_permission_denied', 'The execution principal cannot perform this WordPress operation.' );
	}

	private static function missing( string $object ): \WP_Error {
		return new \WP_Error( 'cb_automations_wordpress_not_found', 'The requested WordPress ' . $object . ' is unavailable.' );
	}

	private static function invalid( string $field ): \WP_Error {
		return new \WP_Error( 'cb_automations_wordpress_invalid_input', 'The WordPress automation input is invalid: ' . $field . '.' );
	}

	private static function failed( string $operation ): \WP_Error {
		return new \WP_Error( 'cb_automations_wordpress_operation_failed', 'The WordPress operation did not complete: ' . $operation . '.' );
	}
}
