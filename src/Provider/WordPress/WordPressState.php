<?php
declare(strict_types=1);

namespace CB\Automations\Provider\WordPress;

use CoreBlueprint\Core\Automation\InvocationContext;

defined( 'ABSPATH' ) || exit;

final class WordPressState {
	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function user( array $input, InvocationContext $context ): array|\WP_Error {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id < 1 || ! user_can( $context->principal_user_id(), 'list_users' ) ) {
			return self::denied();
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return self::missing( 'user' );
		}

		return [
			'user_id' => (int) $user->ID,
			'user_login' => (string) $user->user_login,
			'user_email' => (string) $user->user_email,
			'roles' => array_values( array_map( 'strval', $user->roles ) ),
			'display_name' => (string) $user->display_name,
			'registered_at' => (string) $user->user_registered,
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function post( array $input, InvocationContext $context ): array|\WP_Error {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || in_array( $post->post_type, [ 'revision', 'attachment' ], true ) ) {
			return self::missing( 'post' );
		}
		if ( ! user_can( $context->principal_user_id(), 'read_post', $post_id ) ) {
			return self::denied();
		}
		$author = get_userdata( (int) $post->post_author );

		return [
			'post_id' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'author_id' => (int) $post->post_author,
			'post_parent' => (int) $post->post_parent,
			'post_title' => (string) $post->post_title,
			'post_slug' => (string) $post->post_name,
			'author_email' => $author instanceof \WP_User ? (string) $author->user_email : '',
			'published_at' => (string) get_post_time( 'c', true, $post ),
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function comment( array $input, InvocationContext $context ): array|\WP_Error {
		$comment_id = (int) ( $input['comment_id'] ?? 0 );
		$comment = $comment_id > 0 ? get_comment( $comment_id ) : null;
		if ( ! $comment instanceof \WP_Comment ) {
			return self::missing( 'comment' );
		}
		if ( ! user_can( $context->principal_user_id(), 'edit_comment', $comment_id ) ) {
			return self::denied();
		}

		return [
			'comment_id' => (int) $comment->comment_ID,
			'post_id' => (int) $comment->comment_post_ID,
			'user_id' => (int) $comment->user_id,
			'status' => (string) wp_get_comment_status( $comment ),
			'type' => (string) $comment->comment_type,
			'author_email' => (string) $comment->comment_author_email,
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function attachment( array $input, InvocationContext $context ): array|\WP_Error {
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$post = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return self::missing( 'attachment' );
		}
		if ( ! user_can( $context->principal_user_id(), 'read_post', $attachment_id ) ) {
			return self::denied();
		}

		return [
			'attachment_id' => (int) $post->ID,
			'parent_id' => (int) $post->post_parent,
			'author_id' => (int) $post->post_author,
			'mime_type' => (string) $post->post_mime_type,
			'title' => (string) $post->post_title,
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|\WP_Error */
	public static function term( array $input, InvocationContext $context ): array|\WP_Error {
		$term_id = (int) ( $input['term_id'] ?? 0 );
		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return self::missing( 'taxonomy' );
		}
		if ( ! user_can( $context->principal_user_id(), 'read' ) ) {
			return self::denied();
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return self::missing( 'term' );
		}

		return [
			'term_id' => (int) $term->term_id,
			'taxonomy' => $taxonomy,
			'parent_id' => (int) $term->parent,
			'name' => (string) $term->name,
			'slug' => (string) $term->slug,
		];
	}

	private static function denied(): \WP_Error {
		return new \WP_Error( 'cb_automations_wordpress_permission_denied', 'WordPress object access is not permitted for the execution principal.' );
	}

	private static function missing( string $object ): \WP_Error {
		return new \WP_Error( 'cb_automations_wordpress_not_found', 'The requested WordPress ' . $object . ' is unavailable.' );
	}
}
