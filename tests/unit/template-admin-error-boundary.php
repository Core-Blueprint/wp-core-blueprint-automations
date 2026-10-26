<?php
declare(strict_types=1);

namespace CB\Automations\Admin {
    final class AutomationsPage {
        public const CAPABILITY = 'manage_options';
        public static function url( array $query = [] ): string {
            return 'admin.php?' . http_build_query( $query );
        }
    }
}
namespace CB\Automations\Integration {
    final class Suite { public const EXTENSION_ID = 'core-blueprint-automations'; }
}
namespace CB\Automations\Template {
    final class StoredTemplateRepository {
        public const SOURCE_ID = 'core-blueprint-automations-workflows';
        public static string $mode = 'normal';

        public static function saved_id( string $id ): ?int {
            return 'saved-7' === $id ? 7 : null;
        }
        public static function get( int $id, int $actor ): ?object {
            if ( 'get_throws' === self::$mode ) {
                throw new \RuntimeException( 'Injected WordPress template-read exception' );
            }
            return (object) [ 'post_title' => 'Sample template' ];
        }
        public static function definition( int $id, int $actor ): ?object {
            if ( 'definition_throws' === self::$mode ) {
                throw new \RuntimeException( 'Injected template definition-read exception' );
            }
            return (object) [ 'safe' => true ];
        }
        public static function remove( int $id, int $actor ): bool {
            if ( 'delete_throws' === self::$mode ) {
                throw new \RuntimeException( 'Injected WordPress template-delete exception' );
            }
            return 'delete_false' !== self::$mode;
        }
    }
    final class WorkflowTemplateRegistry {
        public static function get( string $provider, string $id ): ?object {
            if ( 'provider_throws' === StoredTemplateRepository::$mode ) {
                throw new \RuntimeException( 'Injected provider registry exception' );
            }
            return (object) [ 'definition' => null ];
        }
    }
}
namespace {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
    final class StopTemplateAction extends \RuntimeException {
        public function __construct( public string $url ) { parent::__construct( $url ); }
    }
    function current_user_can( string $capability ): bool { return true; }
    function get_current_user_id(): int { return 23; }
    function wp_verify_nonce( string $nonce, string $action ): bool { return 'valid' === $nonce; }
    function sanitize_key( string $s ): string { return $s; }
    function sanitize_text_field( string $s ): string { return $s; }
    function wp_unslash( string $s ): string { return $s; }
    function absint( mixed $v ): int { return abs( (int) $v ); }
    function wp_safe_redirect( string $url ): never { throw new StopTemplateAction( $url ); }
    function esc_html__( string $message, string $domain ): string { return $message; }

    require_once dirname( __DIR__, 2 ) . '/src/Admin/TemplateManagementController.php';
    require_once dirname( __DIR__, 2 ) . '/src/Admin/WorkflowController.php';

    $run = static function ( callable $method, string $scenario, array $fields ): string {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [ '_cb_automations_nonce' => 'valid' ] + $fields;
        \CB\Automations\Template\StoredTemplateRepository::$mode = $scenario;
        try {
            $method();
        } catch ( StopTemplateAction $stop ) {
            return $stop->url;
        }
        throw new \RuntimeException( 'Template action returned without a redirect.' );
    };

    foreach ( [ 'delete_throws', 'delete_false' ] as $scenario ) {
        $url = $run(
            [ \CB\Automations\Admin\TemplateManagementController::class, 'delete' ],
            $scenario,
            [ 'template_id' => '7' ]
        );
        if ( ! str_contains( $url, 'notice=template_unavailable' ) ) {
            throw new \RuntimeException( 'Failed template deletion did not return an availability notice.' );
        }
    }
    $success = $run(
        [ \CB\Automations\Admin\TemplateManagementController::class, 'delete' ],
        'normal',
        [ 'template_id' => '7' ]
    );
    if ( ! str_contains( $success, 'notice=template_removed' ) ) {
        throw new \RuntimeException( 'Successful deletion was no longer reported.' );
    }

    foreach ( [ 'get_throws', 'definition_throws' ] as $scenario ) {
        $export = $run(
            [ \CB\Automations\Admin\TemplateManagementController::class, 'export' ],
            $scenario,
            [ 'template_id' => '7' ]
        );
        if ( ! str_contains( $export, 'notice=template_unavailable' ) ) {
            throw new \RuntimeException( 'Failed template export was not blocked before output.' );
        }
        $create = $run(
            [ \CB\Automations\Admin\WorkflowController::class, 'create_from_template' ],
            $scenario,
            [
                'template_provider' => 'core-blueprint-automations',
                'template_id' => 'saved-7',
                'template_source' => \CB\Automations\Template\StoredTemplateRepository::SOURCE_ID,
            ]
        );
        if ( ! str_contains( $create, 'notice=template_unavailable' ) ) {
            throw new \RuntimeException( 'Failed template source read was not handled safely.' );
        }
    }
    $provider = $run(
        [ \CB\Automations\Admin\WorkflowController::class, 'create_from_template' ],
        'provider_throws',
        [
            'template_provider' => 'core-blueprint-automations',
            'template_id' => 'wordpress.welcome.user',
        ]
    );
    if ( ! str_contains( $provider, 'notice=template_unavailable' ) ) {
        throw new \RuntimeException( 'Failed provider-template lookup was not handled safely.' );
    }

    echo "WordPress template action read/delete failure handling: PASS\n";
}
