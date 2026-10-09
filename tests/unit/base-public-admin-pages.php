<?php
declare(strict_types=1);

/**
 * Standalone public Base admin semantics fixture.
 *
 * Executes the actual Automations registration path against a strict snapshot
 * of the documented Base 1.2 public component vocabulary. No WordPress boot,
 * persistence, provider dispatch or runtime mutation is performed.
 */
namespace {
    define( 'ABSPATH', __DIR__ );
    function __( string $message, string $domain = '' ): string {
        unset( $domain );
        return $message;
    }
}

namespace CoreBlueprint\Core\Admin {
    interface Page {
        public function slug(): string;
        public function title(): string;
        public function menu_title(): string;
        public function capability(): string;
        public function position(): ?int;
        public function render(): void;
    }

    final class MenuGroup {
        public function __construct(
            private string $slug,
            private string $title,
            private string $menu_title,
            private string $capability,
            private string $icon = '',
            private ?int $position = null
        ) {}

        public function slug(): string {
            return $this->slug;
        }

        public function capability(): string {
            return $this->capability;
        }
    }

    final class MenuGroupRegistry {
        /** @var array{group:MenuGroup,pages:Page[],requirements:array}|null */
        public static ?array $registration = null;

        public static function register( MenuGroup $group, array $pages, array $requirements = [] ): bool {
            self::$registration = compact( 'group', 'pages', 'requirements' );
            return true;
        }
    }
}

namespace CB\Automations\Support {
    final class Requirements {
        public static function admin_ready(): bool {
            return true;
        }
    }
}

namespace {
    use CB\Automations\Admin\AutomationRunsPage;
    use CB\Automations\Admin\AutomationsPage;
    use CB\Automations\Admin\RunHistoryCapability;
    use CB\Automations\Integration\Suite;
    use CoreBlueprint\Core\Admin\MenuGroupRegistry;

    $root = dirname( __DIR__, 2 );
    require_once $root . '/src/Admin/RunHistoryCapability.php';
    require_once $root . '/src/Admin/AutomationsPage.php';
    require_once $root . '/src/Admin/AutomationRunsPage.php';
    require_once $root . '/src/Integration/Suite.php';

    $assert = static function ( bool $condition, string $message ): void {
        if ( ! $condition ) {
            fwrite( STDERR, "Public Base admin requirements: {$message}\n" );
            exit( 1 );
        }
    };

    Suite::register_admin_pages();
    $registration = MenuGroupRegistry::$registration;
    $assert( null !== $registration, 'actual Suite registration was not invoked' );

    $group = $registration['group'];
    $assert( Suite::MENU_SLUG === 'core-blueprint-automations', 'product menu slug changed' );
    $assert( $group->slug() === Suite::MENU_SLUG, 'menu identity changed' );
    $assert( $group->capability() === RunHistoryCapability::CAPABILITY, 'product menu capability changed' );

    $pages = [];
    foreach ( $registration['pages'] as $page ) {
        $assert( ! isset( $pages[ $page->slug() ] ), 'duplicate child page slug' );
        $pages[ $page->slug() ] = $page;
    }

    $assert( count( $pages ) === 2, 'expected exactly the Workflows and Runs pages' );
    $assert( isset( $pages[ AutomationsPage::SLUG ], $pages[ AutomationRunsPage::SLUG ] ), 'child page routes changed' );
    $assert( $pages[ AutomationsPage::SLUG ]->capability() === 'manage_options', 'workflow authoring authority changed' );
    $assert( $pages[ AutomationRunsPage::SLUG ]->capability() === RunHistoryCapability::CAPABILITY, 'run viewer authority changed' );
    $assert( $pages[ AutomationsPage::SLUG ]->position() === 10, 'workflow order changed' );
    $assert( $pages[ AutomationRunsPage::SLUG ]->position() === 20, 'runs order changed' );

    // This is the strict PUBLIC subset of Base 1.2 PageRegistry identifiers.
    // Base-internal "actions" and "overview" are intentionally excluded.
    $public_components = [
        'badges', 'buttons', 'cards', 'description-toggle', 'detail-rows',
        'disclosure', 'empty-state', 'fields', 'form-controls',
        'integration-grid', 'kv-table', 'master-switch', 'metric-tiles',
        'nav-tabs', 'admin-navigation', 'notices', 'panels', 'radio-cards',
        'state-badges', 'status',
    ];

    $expected = [
        AutomationsPage::SLUG => [
            'buttons', 'empty-state', 'fields', 'form-controls', 'notices', 'panels', 'status',
        ],
        AutomationRunsPage::SLUG => [ 'buttons', 'notices', 'status' ],
    ];

    $requirements = $registration['requirements'];
    $assert( count( $requirements ) === 2, 'requirements must be scoped to exactly two pages' );
    foreach ( $expected as $slug => $components ) {
        $assert( isset( $requirements[ $slug ] ), "missing requirements for {$slug}" );
        $declaration = $requirements[ $slug ];
        $assert( array_keys( $declaration ) === [ 'components' ], "unexpected requirement group on {$slug}" );
        $actual = $declaration['components'];
        $assert( is_array( $actual ) && array_is_list( $actual ), "invalid components shape on {$slug}" );
        $assert( count( $actual ) === count( array_unique( $actual ) ), "duplicate component on {$slug}" );
        $assert( [] === array_diff( $actual, $public_components ), "private or unknown Base component on {$slug}" );
        $assert( $actual === $components, "unexpected semantic component changes on {$slug}" );
    }

    fwrite( STDOUT, "Public Base 1.2 Automations Workflows/Runs requirements: PASS\n" );
}
