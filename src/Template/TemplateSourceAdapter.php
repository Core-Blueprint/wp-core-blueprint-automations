<?php
declare(strict_types=1);
namespace CB\Automations\Template;

use CB\Automations\Integration\Suite;
use CoreBlueprint\Core\TemplateLibrary\TemplateManagementSourceInterface;
use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;
use CoreBlueprint\Core\TemplateLibrary\TemplateNavigation;
use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;

defined( 'ABSPATH' ) || exit;

/**
 * One registered workflow Designer type; no other extension or Designer can
 * enumerate workflow definitions. Base receives metadata, never documents.
 */
final class TemplateSourceAdapter implements TemplateManagementSourceInterface {
	public const SOURCE_ID = 'core-blueprint-automations-workflows';
	public const DESIGNER_ID = 'workflow';

	public function source_id(): string { return self::SOURCE_ID; }
	public function extension_id(): string { return Suite::EXTENSION_ID; }
	public function designer_type(): string { return self::DESIGNER_ID; }

	public function can_browse( int $user_id ): bool {
		return StoredTemplateRepository::authorized( $user_id );
	}

	public function can_view( string $template_id, int $user_id ): bool {
		if ( ! $this->can_browse( $user_id ) ) {
			return false;
		}
		$id = self::saved_id( $template_id );
		if ( null !== $id ) {
			return null !== StoredTemplateRepository::get( $id, $user_id );
		}
		return null !== WorkflowTemplateRegistry::get( Suite::EXTENSION_ID, $template_id );
	}

	public function can_manage( string $template_id, int $user_id ): bool {
		$id = self::saved_id( $template_id );
		return null !== $id && StoredTemplateRepository::can_manage( $id, $user_id );
	}

	public function navigation( TemplateMetadata $item, int $user_id ): TemplateNavigation {
		if ( ! $this->can_view( $item->id, $user_id ) ) {
			return new TemplateNavigation();
		}
		$preview = [
			'url' => \CB\Automations\Admin\AutomationsPage::url( [ 'template_preview' => $item->id ] ),
			'label' => __( 'Preview template', 'core-blueprint-automations' ),
		];
		$manage = $this->can_manage( $item->id, $user_id )
			? [
				'url' => \CB\Automations\Admin\AutomationsPage::url( [ 'template_manage' => $item->id ] ),
				'label' => __( 'Manage template', 'core-blueprint-automations' ),
			] : null;
		return new TemplateNavigation( $preview, $manage );
	}

	public function query( TemplateQuery $query, ?string $cursor ): TemplatePage {
		$user_id = get_current_user_id();
		if ( ! $this->can_browse( $user_id ) ) {
			return new TemplatePage( [] );
		}
		$offset = null === $cursor ? 0 : ( ctype_digit( $cursor ) ? (int) $cursor : 100001 );
		if ( $offset > 100000 ) {
			return new TemplatePage( [] );
		}

		$builtins = [];
		if ( ( '' === $query->origin || 'built-in' === $query->origin )
			&& ( '' === $query->locale || 'en' === $query->locale ) ) {
			foreach ( WorkflowTemplateRegistry::all() as $template ) {
				if ( '' !== $query->search && false === stripos( $template->title() . ' ' . $template->description(), $query->search ) ) {
					continue;
				}
				if ( '' !== $query->category && $template->category() !== $query->category ) {
					continue;
				}
				$builtins[] = new TemplateMetadata(
					self::SOURCE_ID, Suite::EXTENSION_ID, self::DESIGNER_ID,
					$template->id(), $template->title(), $template->description(),
					'built-in', $template->category(), 'en', $template->version()
				);
			}
		}
		$builtin_count = count( $builtins );
		$items = array_slice( $builtins, $offset, $query->limit );
		$remaining = $query->limit - count( $items );
		// Skip saved templates only when filters cannot match them. The query
		// predicate is applied by SQL BEFORE bounded per-page retrieval.
		$saved_possible = ( '' === $query->origin || in_array( $query->origin, [ 'personal', 'site' ], true ) )
			&& ( '' === $query->category || 'custom' === $query->category )
			&& ( '' === $query->locale || 'en' === $query->locale );
		$saved_more = false;
		if ( $remaining > 0 && $saved_possible ) {
			$page = StoredTemplateRepository::search(
				$user_id, $query->origin, $query->search, $remaining,
				(string) max( 0, $offset - $builtin_count )
			);
			foreach ( $page['items'] as $post ) {
				$items[] = new TemplateMetadata(
					self::SOURCE_ID, Suite::EXTENSION_ID, self::DESIGNER_ID,
					StoredTemplateRepository::template_id( (int) $post->ID ),
					$post->post_title, $post->post_excerpt,
					StoredTemplateRepository::scope( (int) $post->ID ),
					'custom', 'en', '1'
				);
			}
			$saved_more = null !== $page['next'];
		}
		// If built-in results fill the page, still probe for saved templates.
		if ( 0 === $remaining && $saved_possible ) {
			$probe = StoredTemplateRepository::search( $user_id, $query->origin, $query->search, 1, '0' );
			$saved_more = [] !== $probe['items'];
		}
		$next = ( $offset + count( $items ) < $builtin_count || $saved_more )
			? (string) ( $offset + count( $items ) ) : null;
		return new TemplatePage( $items, $next );
	}

	public static function saved_id( string $id ): ?int {
		if ( ! preg_match( '/^saved-([1-9][0-9]{0,9})$/D', $id, $match ) ) {
			return null;
		}
		return (int) $match[1];
	}
}
