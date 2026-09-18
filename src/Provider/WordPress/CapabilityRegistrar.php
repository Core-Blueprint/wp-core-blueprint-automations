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
			self::trigger( 'user.registered', 'User registered', 'A WordPress user account was created.', $user ),
			self::trigger( 'user.updated', 'User updated', 'A WordPress user account was updated.', $user ),
			self::trigger( 'user.deleted', 'User deleted', 'A WordPress user account was deleted.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'reassign_to' => self::field( 'integer', false, false, 'wp.user_id' ),
			] ),
			self::trigger( 'user.role_changed', 'User role changed', 'The primary WordPress role for a user changed.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'new_role' => self::field( 'string', true, false, 'wp.user_role' ),
				'old_roles' => self::array_field( 'string', true, false, 'wp.user_role' ),
			] ),
			self::trigger( 'user.role_added', 'User role added', 'A WordPress role was added to a user.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			] ),
			self::trigger( 'user.role_removed', 'User role removed', 'A WordPress role was removed from a user.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			] ),
			self::trigger( 'user.logged_in', 'User logged in', 'A WordPress user successfully logged in.', $user ),
			self::trigger( 'user.logged_out', 'User logged out', 'A WordPress user logged out.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			] ),
			self::trigger( 'user.login_failed', 'User login failed', 'A WordPress login attempt failed.', [
				'login' => self::field( 'string', true, true ),
				'error_code' => self::field( 'string', true ),
			] ),
			self::trigger( 'user.password_reset', 'User password reset', 'A WordPress user password was reset. Password values are never exposed.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			] ),

			self::trigger( 'post.created', 'Post created', 'A post, page or custom post type record was created.', $post ),
			self::trigger( 'post.updated', 'Post updated', 'A post, page or custom post type record was updated.', $post ),
			self::trigger( 'post.status_changed', 'Post status changed', 'A post status changed.', $post + [
				'old_status' => self::field( 'string', true, false, 'wp.post_status' ),
				'new_status' => self::field( 'string', true, false, 'wp.post_status' ),
			] ),
			self::trigger( 'post.published', 'Post published', 'A post entered the publish status.', $post ),
			self::trigger( 'post.trashed', 'Post trashed', 'A post was moved to Trash.', $post + [
				'previous_status' => self::field( 'string', true, false, 'wp.post_status' ),
			] ),
			self::trigger( 'post.restored', 'Post restored', 'A post was restored from Trash.', $post + [
				'previous_status' => self::field( 'string', true, false, 'wp.post_status' ),
			] ),
			self::trigger( 'post.deleted', 'Post deleted', 'A post was permanently deleted.', $post ),

			self::trigger( 'comment.created', 'Comment created', 'A WordPress comment was created.', $comment ),
			self::trigger( 'comment.updated', 'Comment updated', 'A WordPress comment was updated.', $comment ),
			self::trigger( 'comment.status_changed', 'Comment status changed', 'A WordPress comment status changed.', $comment + [
				'old_status' => self::field( 'string', true, false, 'wp.comment_status' ),
				'new_status' => self::field( 'string', true, false, 'wp.comment_status' ),
			] ),
			self::trigger( 'comment.trashed', 'Comment trashed', 'A WordPress comment was moved to Trash.', $comment ),
			self::trigger( 'comment.restored', 'Comment restored', 'A WordPress comment was restored from Trash.', $comment ),
			self::trigger( 'comment.deleted', 'Comment deleted', 'A WordPress comment was permanently deleted.', $comment ),

			self::trigger( 'attachment.created', 'Media uploaded', 'A WordPress attachment was created.', $attachment ),
			self::trigger( 'attachment.updated', 'Media updated', 'A WordPress attachment was updated.', $attachment ),
			self::trigger( 'attachment.deleted', 'Media deleted', 'A WordPress attachment was deleted.', $attachment ),

			self::trigger( 'term.created', 'Term created', 'A taxonomy term was created.', $term ),
			self::trigger( 'term.updated', 'Term updated', 'A taxonomy term was updated.', $term ),
			self::trigger( 'term.deleted', 'Term deleted', 'A taxonomy term was deleted.', $term ),
			self::trigger( 'object.terms_changed', 'Object terms changed', 'Taxonomy terms assigned to an object changed.', [
				'object_id' => self::field( 'integer', true ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'term_taxonomy_ids' => self::array_field( 'integer', true, false, 'wp.term_taxonomy_id' ),
				'old_term_taxonomy_ids' => self::array_field( 'integer', true, false, 'wp.term_taxonomy_id' ),
				'append' => self::field( 'boolean', true ),
			] ),

			self::trigger( 'plugin.activated', 'Plugin activated', 'A WordPress plugin was activated.', [
				'plugin' => self::field( 'string', true, false, 'wp.plugin_basename' ),
				'network_wide' => self::field( 'boolean', true ),
			] ),
			self::trigger( 'plugin.deactivated', 'Plugin deactivated', 'A WordPress plugin was deactivated.', [
				'plugin' => self::field( 'string', true, false, 'wp.plugin_basename' ),
				'network_wide' => self::field( 'boolean', true ),
			] ),
			self::trigger( 'theme.switched', 'Theme switched', 'The active WordPress theme changed.', [
				'theme_name' => self::field( 'string', true ),
				'stylesheet' => self::field( 'string', true, false, 'wp.theme_stylesheet' ),
			] ),
			self::trigger( 'update.completed', 'WordPress update completed', 'A WordPress upgrader process completed.', [
				'type' => self::field( 'string', true ),
				'action' => self::field( 'string', true ),
			] ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	private static function state_definitions(): array {
		return [
			self::state( 'user.current', 'Current user', 'Reads bounded current facts for one WordPress user.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			], self::user_schema() + [
				'display_name' => self::field( 'string', true ),
				'registered_at' => self::field( 'string', true, false, 'wp.datetime' ),
			], 'list_users', [ WordPressState::class, 'user' ] ),
			self::state( 'post.current', 'Current post', 'Reads bounded current facts for one post, page or custom post type record.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], self::post_schema() + [
				'post_slug' => self::field( 'string', true ),
				'author_email' => self::field( 'string', true, true ),
				'published_at' => self::field( 'string', true, false, 'wp.datetime' ),
			], 'read', [ WordPressState::class, 'post' ] ),
			self::state( 'comment.current', 'Current comment', 'Reads bounded current facts for one WordPress comment.', [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], self::comment_schema(), 'read', [ WordPressState::class, 'comment' ] ),
			self::state( 'attachment.current', 'Current media item', 'Reads bounded current facts for one WordPress attachment.', [
				'attachment_id' => self::field( 'integer', true, false, 'wp.attachment_id' ),
			], self::attachment_schema(), 'read', [ WordPressState::class, 'attachment' ] ),
			self::state( 'term.current', 'Current term', 'Reads bounded current facts for one taxonomy term.', [
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
			self::action( 'user.update', 'Update user', 'Updates selected safe WordPress user fields.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'display_name' => self::field( 'string' ),
				'first_name' => self::field( 'string' ),
				'last_name' => self::field( 'string' ),
				'user_email' => self::field( 'string', false, true ),
			], [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
			], 'edit_users', [ WordPressAction::class, 'update_user' ] ),
			self::action( 'user.add_role', 'Add user role', 'Adds an existing WordPress role to a user.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			], [], 'promote_users', [ WordPressAction::class, 'add_user_role' ] ),
			self::action( 'user.remove_role', 'Remove user role', 'Removes a WordPress role from a user.', [
				'user_id' => self::field( 'integer', true, false, 'wp.user_id' ),
				'role' => self::field( 'string', true, false, 'wp.user_role' ),
			], [], 'promote_users', [ WordPressAction::class, 'remove_user_role' ] ),

			self::action( 'post.create', 'Create post', 'Creates a post, page or custom post type record.', [
				'post_type' => self::field( 'string', true, false, 'wp.post_type' ),
				'post_title' => self::field( 'string', true ),
				'post_content' => self::field( 'string' ),
				'post_excerpt' => self::field( 'string' ),
				'post_status' => self::field( 'string', false, false, 'wp.post_status' ),
			], [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], 'edit_posts', [ WordPressAction::class, 'create_post' ] ),
			self::action( 'post.update', 'Update post', 'Updates selected fields on an existing WordPress post.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
				'post_title' => self::field( 'string' ),
				'post_content' => self::field( 'string' ),
				'post_excerpt' => self::field( 'string' ),
			], [], 'edit_posts', [ WordPressAction::class, 'update_post' ] ),
			self::action( 'post.change_status', 'Change post status', 'Changes the status of an existing WordPress post.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
				'post_status' => self::field( 'string', true, false, 'wp.post_status' ),
			], [], 'edit_posts', [ WordPressAction::class, 'change_post_status' ] ),
			self::action( 'post.trash', 'Trash post', 'Moves an existing WordPress post to Trash.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], [], 'delete_posts', [ WordPressAction::class, 'trash_post' ] ),
			self::action( 'post.restore', 'Restore post', 'Restores a WordPress post from Trash.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], [], 'delete_posts', [ WordPressAction::class, 'restore_post' ] ),
			self::action( 'post.delete', 'Delete post permanently', 'Permanently deletes an existing WordPress post.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
			], [], 'delete_posts', [ WordPressAction::class, 'delete_post' ] ),

			self::action( 'comment.change_status', 'Change comment status', 'Changes a WordPress comment status.', [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
				'status' => self::field( 'string', true, false, 'wp.comment_status' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'change_comment_status' ] ),
			self::action( 'comment.trash', 'Trash comment', 'Moves a WordPress comment to Trash.', [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'trash_comment' ] ),
			self::action( 'comment.restore', 'Restore comment', 'Restores a WordPress comment from Trash.', [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'restore_comment' ] ),
			self::action( 'comment.delete', 'Delete comment permanently', 'Permanently deletes a WordPress comment.', [
				'comment_id' => self::field( 'integer', true, false, 'wp.comment_id' ),
			], [], 'moderate_comments', [ WordPressAction::class, 'delete_comment' ] ),

			self::action( 'term.create', 'Create term', 'Creates a taxonomy term.', [
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'name' => self::field( 'string', true ),
				'slug' => self::field( 'string' ),
				'parent_id' => self::field( 'integer', false, false, 'wp.term_id' ),
			], [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
			], 'manage_categories', [ WordPressAction::class, 'create_term' ] ),
			self::action( 'term.update', 'Update term', 'Updates a taxonomy term.', [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'name' => self::field( 'string' ),
				'slug' => self::field( 'string' ),
				'parent_id' => self::field( 'integer', false, false, 'wp.term_id' ),
			], [], 'manage_categories', [ WordPressAction::class, 'update_term' ] ),
			self::action( 'term.delete', 'Delete term', 'Deletes a taxonomy term.', [
				'term_id' => self::field( 'integer', true, false, 'wp.term_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
			], [], 'manage_categories', [ WordPressAction::class, 'delete_term' ] ),
			self::action( 'post.set_terms', 'Set post terms', 'Sets taxonomy terms on a WordPress post.', [
				'post_id' => self::field( 'integer', true, false, 'wp.post_id' ),
				'taxonomy' => self::field( 'string', true, false, 'wp.taxonomy' ),
				'term_ids' => self::array_field( 'integer', true, false, 'wp.term_id' ),
				'append' => self::field( 'boolean' ),
			], [], 'edit_posts', [ WordPressAction::class, 'set_post_terms' ] ),

			self::action( 'mail.send', 'Send email', 'Sends an email through the WordPress mail boundary.', [
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
			'label' => __( $label, 'core-blueprint-automations' ),
			'description' => __( $description, 'core-blueprint-automations' ),
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
