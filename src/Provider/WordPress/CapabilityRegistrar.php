<?php
declare(strict_types=1);

namespace CB\Automations\Provider\WordPress;

use CB\Core\Automation\ActionRegistry;
use CB\Core\Automation\StateRegistry;
use CB\Core\Automation\TriggerRegistry;

defined( 'ABSPATH' ) || exit;

final class CapabilityRegistrar {
	public const PROVIDER = 'core-blueprint-automations';
	private const VERSION = '1';

	public static function register(): void {
		self::register_triggers();
		self::register_states();
		self::register_actions();
	}

	private static function register_triggers(): void {
		foreach ( self::trigger_definitions() as $definition ) {
			TriggerRegistry::register( $definition );
		}
	}

	private static function register_states(): void {
		foreach ( self::state_definitions() as $definition ) {
			StateRegistry::register( $definition );
		}
	}

	private static function register_actions(): void {
		foreach ( self::action_definitions() as $definition ) {
			ActionRegistry::register( $definition );
		}
	}

	/** @return array<int,array<string,mixed>> */
	private static function trigger_definitions(): array {
		$user = self::user_schema();
		$post = self::post_schema();
		$comment = self::comment_schema();
		$attachment = self::attachment_schema();
		$term = self::term_schema();

		return [
			self::trigger( 'user.registered', __( 'User registered', 'core-blueprint-automations' ), __( 'A WordPress user account was created.', 'core-blueprint-automations' ), $user ),
			self::trigger( 'user.updated', __( 'User updated', 'core-blueprint-automations' ), __( 'A WordPress user account was updated.', 'core-blueprint-automations' ), $user ),
			self::trigger( 'user.deleted', __( 'User deleted', 'core-blueprint-automations' ), __( 'A WordPress user account was deleted.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'reassign_to' => self::field( 'integer', false, false, 'wp.user_id' ),
			] ),
			self::trigger( 'user.role_changed', __( 'User role changed', 'core-blueprint-automations' ), __( 'The primary WordPress role for a user changed.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'new_role' => self::field( 'string', true, false, 'wp.user_role' ),
				'old_roles' => self::array_field( 'string', true, false, 'wp.user_role' ),
			] ),
			self::trigger( 'user.role_added', __( 'User role added', 'core-blueprint-automations' ), __( 'A WordPress role was added to a user.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			] ),
			self::trigger( 'user.role_removed', __( 'User role removed', 'core-blueprint-automations' ), __( 'A WordPress role was removed from a user.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			] ),
			self::trigger( 'user.logged_in', __( 'User logged in', 'core-blueprint-automations' ), __( 'A WordPress user successfully logged in.', 'core-blueprint-automations' ), $user ),
			self::trigger( 'user.logged_out', __( 'User logged out', 'core-blueprint-automations' ), __( 'A WordPress user logged out.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			] ),
			self::trigger( 'user.login_failed', __( 'User login failed', 'core-blueprint-automations' ), __( 'A WordPress login attempt failed.', 'core-blueprint-automations' ), [
				'login' => self::field( 'string', true, true ),
				'error_code' => self::field( 'string', true ),
			] ),
			self::trigger( 'user.password_reset', __( 'User password reset', 'core-blueprint-automations' ), __( 'A WordPress user password was reset. Password values are never exposed.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			] ),

			self::trigger( 'post.created', __( 'Post created', 'core-blueprint-automations' ), __( 'A post, page or custom post type record was created.', 'core-blueprint-automations' ), $post ),
			self::trigger( 'post.updated', __( 'Post updated', 'core-blueprint-automations' ), __( 'A post, page or custom post type record was updated.', 'core-blueprint-automations' ), $post ),
			self::trigger( 'post.status_changed', __( 'Post status changed', 'core-blueprint-automations' ), __( 'A post status changed.', 'core-blueprint-automations' ), $post + [
				'old_status' => self::field( 'string', true, false, 'wp.post_status' ),
				'new_status' => self::field( 'string', true, false, 'wp.post_status' ),
			] ),
			self::trigger( 'post.published', __( 'Post published', 'core-blueprint-automations' ), __( 'A post entered the publish status.', 'core-blueprint-automations' ), $post ),
			self::trigger( 'post.trashed', __( 'Post trashed', 'core-blueprint-automations' ), __( 'A post was moved to Trash.', 'core-blueprint-automations' ), $post + [
				'previous_status' => self::field( 'string', true, false, 'wp.post_status' ),
			] ),
			self::trigger( 'post.restored', __( 'Post restored', 'core-blueprint-automations' ), __( 'A post was restored from Trash.', 'core-blueprint-automations' ), $post + [
				'previous_status' => self::field( 'string', true, false, 'wp.post_status' ),
			] ),
			self::trigger( 'post.deleted', __( 'Post deleted', 'core-blueprint-automations' ), __( 'A post was permanently deleted.', 'core-blueprint-automations' ), $post ),

			self::trigger( 'comment.created', __( 'Comment created', 'core-blueprint-automations' ), __( 'A WordPress comment was created.', 'core-blueprint-automations' ), $comment ),
			self::trigger( 'comment.updated', __( 'Comment updated', 'core-blueprint-automations' ), __( 'A WordPress comment was updated.', 'core-blueprint-automations' ), $comment ),
			self::trigger( 'comment.status_changed', __( 'Comment status changed', 'core-blueprint-automations' ), __( 'A WordPress comment status changed.', 'core-blueprint-automations' ), $comment + [
				'old_status' => self::field( 'string', true, false, 'wp.comment_status' ),
				'new_status' => self::field( 'string', true, false, 'wp.comment_status' ),
			] ),
			self::trigger( 'comment.trashed', __( 'Comment trashed', 'core-blueprint-automations' ), __( 'A WordPress comment was moved to Trash.', 'core-blueprint-automations' ), $comment ),
			self::trigger( 'comment.restored', __( 'Comment restored', 'core-blueprint-automations' ), __( 'A WordPress comment was restored from Trash.', 'core-blueprint-automations' ), $comment ),
			self::trigger( 'comment.deleted', __( 'Comment deleted', 'core-blueprint-automations' ), __( 'A WordPress comment was permanently deleted.', 'core-blueprint-automations' ), $comment ),

			self::trigger( 'attachment.created', __( 'Media uploaded', 'core-blueprint-automations' ), __( 'A WordPress attachment was created.', 'core-blueprint-automations' ), $attachment ),
			self::trigger( 'attachment.updated', __( 'Media updated', 'core-blueprint-automations' ), __( 'A WordPress attachment was updated.', 'core-blueprint-automations' ), $attachment ),
			self::trigger( 'attachment.deleted', __( 'Media deleted', 'core-blueprint-automations' ), __( 'A WordPress attachment was deleted.', 'core-blueprint-automations' ), $attachment ),

			self::trigger( 'term.created', __( 'Term created', 'core-blueprint-automations' ), __( 'A taxonomy term was created.', 'core-blueprint-automations' ), $term ),
			self::trigger( 'term.updated', __( 'Term updated', 'core-blueprint-automations' ), __( 'A taxonomy term was updated.', 'core-blueprint-automations' ), $term ),
			self::trigger( 'term.deleted', __( 'Term deleted', 'core-blueprint-automations' ), __( 'A taxonomy term was deleted.', 'core-blueprint-automations' ), $term ),
			self::trigger( 'object.terms_changed', __( 'Object terms changed', 'core-blueprint-automations' ), __( 'Taxonomy terms assigned to an object changed.', 'core-blueprint-automations' ), [
				'object_id' => self::field( 'integer', true ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'term_taxonomy_ids' => self::array_field( 'integer', true, false, 'wp.term_taxonomy_id' ),
				'old_term_taxonomy_ids' => self::array_field( 'integer', true, false, 'wp.term_taxonomy_id' ),
				'append' => self::field( 'boolean', true ),
			] ),

			self::trigger( 'plugin.activated', __( 'Plugin activated', 'core-blueprint-automations' ), __( 'A WordPress plugin was activated.', 'core-blueprint-automations' ), [
				'plugin' => self::field( 'string', true, false, 'wp.plugin_basename' ),
				'network_wide' => self::field( 'boolean', true ),
			] ),
			self::trigger( 'plugin.deactivated', __( 'Plugin deactivated', 'core-blueprint-automations' ), __( 'A WordPress plugin was deactivated.', 'core-blueprint-automations' ), [
				'plugin' => self::field( 'string', true, false, 'wp.plugin_basename' ),
				'network_wide' => self::field( 'boolean', true ),
			] ),
			self::trigger( 'theme.switched', __( 'Theme switched', 'core-blueprint-automations' ), __( 'The active WordPress theme changed.', 'core-blueprint-automations' ), [
				'theme_name' => self::field( 'string', true ),
				'stylesheet' => self::field( 'string', true, false, 'wp.theme_stylesheet' ),
			] ),
			self::trigger( 'update.completed', __( 'WordPress update completed', 'core-blueprint-automations' ), __( 'A WordPress upgrader process completed.', 'core-blueprint-automations' ), [
				'type' => self::field( 'string', true ),
				'action' => self::field( 'string', true ),
			] ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	private static function state_definitions(): array {
		return [
			self::state( 'user.current', __( 'Current user', 'core-blueprint-automations' ), __( 'Reads bounded current facts for one WordPress user.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			], self::user_schema() + [
				'display_name' => self::field( 'string', true ),
				'registered_at' => self::field( 'string', true, false, 'wp.datetime' ),
			], 'list_users', [ WordPressState::class, 'user' ] ),
			self::state( 'post.current', __( 'Current post', 'core-blueprint-automations' ), __( 'Reads bounded current facts for one post, page or custom post type record.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], self::post_schema() + [
				'post_slug' => self::field( 'string', true ),
				'author_email' => self::field( 'string', true, true ),
				'published_at' => self::field( 'string', true, false, 'wp.datetime' ),
			], 'read', [ WordPressState::class, 'post' ] ),
			self::state( 'comment.current', __( 'Current comment', 'core-blueprint-automations' ), __( 'Reads bounded current facts for one WordPress comment.', 'core-blueprint-automations' ), [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], self::comment_schema(), 'moderate_comments', [ WordPressState::class, 'comment' ] ),
			self::state( 'attachment.current', __( 'Current media item', 'core-blueprint-automations' ), __( 'Reads bounded current facts for one WordPress attachment.', 'core-blueprint-automations' ), [
				'attachment_id' => self::field( 'integer', true, false, 'wp.attachment_id' ),
			], self::attachment_schema(), 'read', [ WordPressState::class, 'attachment' ] ),
			self::state( 'term.current', __( 'Current term', 'core-blueprint-automations' ), __( 'Reads bounded current facts for one taxonomy term.', 'core-blueprint-automations' ), [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
			], self::term_schema() + [
				'name' => self::field( 'string', true ),
				'slug' => self::field( 'string', true ),
			], 'read', [ WordPressState::class, 'term' ] ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	private static function action_definitions(): array {
		return [
			self::action( 'user.update', __( 'Update user', 'core-blueprint-automations' ), __( 'Updates selected safe WordPress user fields.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'display_name' => self::field( 'string' ),
				'first_name' => self::field( 'string' ),
				'last_name' => self::field( 'string' ),
				'user_email' => self::field( 'string', false, true ),
			], [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			], 'edit_users', [ WordPressAction::class, 'update_user' ] ),
			self::action( 'user.add_role', __( 'Add user role', 'core-blueprint-automations' ), __( 'Adds an existing WordPress role to a user.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			], [], 'promote_users', [ WordPressAction::class, 'add_user_role' ] ),
			self::action( 'user.remove_role', __( 'Remove user role', 'core-blueprint-automations' ), __( 'Removes a WordPress role from a user.', 'core-blueprint-automations' ), [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			], [], 'promote_users', [ WordPressAction::class, 'remove_user_role' ] ),

			self::action( 'post.create', __( 'Create post', 'core-blueprint-automations' ), __( 'Creates a post, page or custom post type record.', 'core-blueprint-automations' ), [
				'post_type' => self::field( 'string', true, false, 'wp.post_type' ),
				'post_title' => self::field( 'string', true ),
				'post_content' => self::field( 'string' ),
				'post_excerpt' => self::field( 'string' ),
				'post_status' => self::field( 'string', false, false, 'wp.post_status' ),
			], [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], 'edit_posts', [ WordPressAction::class, 'create_post' ] ),
			self::action( 'post.update', __( 'Update post', 'core-blueprint-automations' ), __( 'Updates selected fields on an existing WordPress post.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
				'post_title' => self::field( 'string' ),
				'post_content' => self::field( 'string' ),
				'post_excerpt' => self::field( 'string' ),
			], [], 'edit_posts', [ WordPressAction::class, 'update_post' ] ),
			self::action( 'post.change_status', __( 'Change post status', 'core-blueprint-automations' ), __( 'Changes the status of an existing WordPress post.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
				'post_status' => self::field( 'string', true, false, 'wp.post_status' ),
			], [], 'edit_posts', [ WordPressAction::class, 'change_post_status' ] ),
			self::action( 'post.trash', __( 'Trash post', 'core-blueprint-automations' ), __( 'Moves an existing WordPress post to Trash.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], [], 'delete_posts', [ WordPressAction::class, 'trash_post' ] ),
			self::action( 'post.restore', __( 'Restore post', 'core-blueprint-automations' ), __( 'Restores a WordPress post from Trash.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], [], 'delete_posts', [ WordPressAction::class, 'restore_post' ] ),
			self::action( 'post.delete', __( 'Delete post permanently', 'core-blueprint-automations' ), __( 'Permanently deletes an existing WordPress post.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], [], 'delete_posts', [ WordPressAction::class, 'delete_post' ] ),

			self::action( 'comment.change_status', __( 'Change comment status', 'core-blueprint-automations' ), __( 'Changes a WordPress comment status.', 'core-blueprint-automations' ), [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
				'status' => self::field( 'string', true, false, 'wp.comment_status' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'change_comment_status' ] ),
			self::action( 'comment.trash', __( 'Trash comment', 'core-blueprint-automations' ), __( 'Moves a WordPress comment to Trash.', 'core-blueprint-automations' ), [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'trash_comment' ] ),
			self::action( 'comment.restore', __( 'Restore comment', 'core-blueprint-automations' ), __( 'Restores a WordPress comment from Trash.', 'core-blueprint-automations' ), [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'restore_comment' ] ),
			self::action( 'comment.delete', __( 'Delete comment permanently', 'core-blueprint-automations' ), __( 'Permanently deletes a WordPress comment.', 'core-blueprint-automations' ), [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'delete_comment' ] ),

			self::action( 'term.create', __( 'Create term', 'core-blueprint-automations' ), __( 'Creates a taxonomy term.', 'core-blueprint-automations' ), [
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'name' => self::field( 'string', true ),
				'slug' => self::field( 'string' ),
				'parent_id' => self::field( 'integer', false, false, 'wp.term_id' ),
			], [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
			], 'manage_categories', [ WordPressAction::class, 'create_term' ] ),
			self::action( 'term.update', __( 'Update term', 'core-blueprint-automations' ), __( 'Updates a taxonomy term.', 'core-blueprint-automations' ), [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'name' => self::field( 'string' ),
				'slug' => self::field( 'string' ),
				'parent_id' => self::field( 'integer', false, false, 'wp.term_id' ),
			], [], 'manage_categories', [ WordPressAction::class, 'update_term' ] ),
			self::action( 'term.delete', __( 'Delete term', 'core-blueprint-automations' ), __( 'Deletes a taxonomy term.', 'core-blueprint-automations' ), [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
			], [], 'manage_categories', [ WordPressAction::class, 'delete_term' ] ),
			self::action( 'post.set_terms', __( 'Set post terms', 'core-blueprint-automations' ), __( 'Sets taxonomy terms on a WordPress post.', 'core-blueprint-automations' ), [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'term_ids' => self::array_field( 'integer', true, false, 'wp.term_id' ),
				'append' => self::field( 'boolean' ),
			], [], 'edit_posts', [ WordPressAction::class, 'set_post_terms' ] ),

			self::action( 'mail.send', __( 'Send email', 'core-blueprint-automations' ), __( 'Sends an email through the WordPress mail boundary.', 'core-blueprint-automations' ), [
				'to' => self::field( 'string', true, true ),
				'subject' => self::field( 'string', true ),
				'message' => self::field( 'string', true ),
			], [
				'sent' => self::field( 'boolean', true ),
			], 'read', [ WordPressAction::class, 'send_mail' ] ),
		];
	}

	/** @param array<string,mixed> $schema */
	private static function trigger( string $id, string $label, string $description, array $schema ): array {
		return [
			'provider' => self::PROVIDER,
			'id' => $id,
			'label' => $label,
			'description' => $description,
			'schema_version' => self::VERSION,
			'payload_schema' => $schema,
		];
	}

	/** @param array<string,mixed> $input @param array<string,mixed> $output */
	private static function state( string $id, string $label, string $description, array $input, array $output, string $capability, callable $resolver ): array {
		return [
			'provider' => self::PROVIDER,
			'id' => $id,
			'label' => __( $label, 'core-blueprint-automations' ),
			'description' => __( $description, 'core-blueprint-automations' ),
			'schema_version' => self::VERSION,
			'input_schema' => $input,
			'output_schema' => $output,
			'required_capability' => $capability,
			'resolver' => $resolver,
		];
	}

	/** @param array<string,mixed> $input @param array<string,mixed> $output */
	private static function action( string $id, string $label, string $description, array $input, array $output, string $capability, callable $executor ): array {
		return [
			'provider' => self::PROVIDER,
			'id' => $id,
			'label' => __( $label, 'core-blueprint-automations' ),
			'description' => __( $description, 'core-blueprint-automations' ),
			'schema_version' => self::VERSION,
			'input_schema' => $input,
			'output_schema' => $output,
			'required_capability' => $capability,
			'executor' => $executor,
		];
	}

	private static function field( string $type, bool $required = false, bool $sensitive = false, ?string $semantic = null ): array {
		$field = [ 'type' => $type, 'required' => $required, 'sensitive' => $sensitive ];
		if ( null !== $semantic ) {
			$field['semantic_type'] = $semantic;
		}
		return $field;
	}

	private static function array_field( string $items, bool $required = false, bool $sensitive = false, ?string $semantic = null ): array {
		$field = [ 'type' => 'array', 'items' => $items, 'required' => $required, 'sensitive' => $sensitive ];
		if ( null !== $semantic ) {
			$field['semantic_type'] = $semantic;
		}
		return $field;
	}

	private static function user_schema(): array {
		return [
			'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			'user_login' => self::field( 'string', true ),
			'user_email' => self::field( 'string', true, true ),
			'roles' => self::array_field( 'string', true, false, 'wp.user_role' ),
		];
	}

	private static function post_schema(): array {
		return [
			'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			'post_type' => self::field( 'string', true, false, 'wp.post_type' ),
			'post_status' => self::field( 'string', true, false, 'wp.post_status' ),
			'author_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			'post_parent' => self::field( 'integer', true, false, 'wp.post_id' ),
			'post_title' => self::field( 'string', true ),
		];
	}

	private static function comment_schema(): array {
		return [
			'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			'status' => self::field( 'string', true, false, 'wp.comment_status' ),
			'type' => self::field( 'string', true ),
			'author_email' => self::field( 'string', true, true ),
		];
	}

	private static function attachment_schema(): array {
		return [
			'attachment_id' => self::field( 'integer', true, false, 'wp.attachment_id' ),
			'parent_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			'author_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			'mime_type' => self::field( 'string', true ),
			'title' => self::field( 'string', true ),
		];
	}

	private static function term_schema(): array {
		return [
			'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
			'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
			'parent_id' => self::field( 'integer', true, false, 'wp.term_id' ),
		];
	}
}
