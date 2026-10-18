<?php
declare(strict_types=1);
namespace CB\Automations\Template;

use CB\Automations\Admin\AutomationsPage;
use CB\Automations\Integration\Suite;
use CoreBlueprint\Core\TemplateLibrary\Assets;
use CoreBlueprint\Core\TemplateLibrary\DesignerScope;
use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry;

defined( 'ABSPATH' ) || exit;

/** Additive Base T2/T3A integration; inert on older Base versions. */
final class TemplateLibraryBridge {
	public static function init(): void {
		if ( ! class_exists( TemplateSourceRegistry::class )
			|| ! interface_exists( \CoreBlueprint\Core\TemplateLibrary\TemplateManagementSourceInterface::class )
			|| ! interface_exists( \CoreBlueprint\Core\TemplateLibrary\TemplateCategorySourceInterface::class ) ) {
			return;
		}
		add_action( 'core_blueprint_register_template_sources', [ self::class, 'register' ] );
	}

	public static function register(): void {
		TemplateSourceRegistry::register( new TemplateSourceAdapter() );
	}

	public static function scope_token(): ?string {
		// Do not autoload the optional adapter unless the required public Base
		// Template Category ABI is present in the installed Foundation.
		if ( ! interface_exists( \CoreBlueprint\Core\TemplateLibrary\TemplateCategorySourceInterface::class )
			|| ! interface_exists( \CoreBlueprint\Core\TemplateLibrary\TemplateManagementSourceInterface::class ) ) {
			return null;
		}
		return class_exists( DesignerScope::class )
			? DesignerScope::issue( Suite::EXTENSION_ID, TemplateSourceAdapter::DESIGNER_ID ) : null;
	}

	public static function enqueue(): void {
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) || null === self::scope_token()
			|| ! class_exists( Assets::class ) ) {
			return;
		}
		Assets::enqueue();
		$path = 'assets/js/template-library-bridge.js';
		$file = CB_AUTOMATIONS_DIR . $path;
		wp_enqueue_script_module(
			'cb-automations-template-library-bridge',
			CB_AUTOMATIONS_URL . $path,
			[ '@cb-core/template-browser' ],
			is_file( $file ) ? (string) filemtime( $file ) : CB_AUTOMATIONS_VERSION
		);
	}
}
