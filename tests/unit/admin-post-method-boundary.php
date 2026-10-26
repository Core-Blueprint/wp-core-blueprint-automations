<?php
declare(strict_types=1);

namespace CB\Automations\Admin {
    final class AutomationsPage {
        public const CAPABILITY = 'manage_options';
    }
    final class RecoveryCapability {
        public const CAPABILITY = 'manage_options';
    }
}

namespace {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

    final class StopAdminPost extends \RuntimeException {
        public function __construct( public int $http ) {
            parent::__construct( 'Request refused' );
        }
    }

    function esc_html__( string $message, string $domain ): string { return $message; }
    function current_user_can( string $capability ): bool {
        ++$GLOBALS['capability_checks'];
        return true;
    }
    function get_current_user_id(): int { return 31; }
    function wp_verify_nonce( string $nonce, string $action ): bool {
        ++$GLOBALS['nonce_checks'];
        return 'valid' === $nonce;
    }
    function sanitize_text_field( string $value ): string { return $value; }
    function wp_unslash( string $value ): string { return $value; }
    function wp_die( string $message, string $title = '', array $args = [] ): never {
        throw new StopAdminPost( (int) ( $args['response'] ?? 500 ) );
    }

    $root = dirname( __DIR__, 2 );
    foreach ( [
        'WorkflowController.php',
        'WorkflowTransferController.php',
        'WorkflowFolderController.php',
        'TemplateManagementController.php',
        'OperatorRecoveryController.php',
    ] as $file ) {
        require_once $root . '/src/Admin/' . $file;
    }

    // Exercise the actual guard methods, not copied source snippets. All
    // wp-admin admin_post_ handlers must reject other methods BEFORE nonce,
    // authorization, data access or any mutation.
    $guards = [
        [ \CB\Automations\Admin\WorkflowController::class, 'guard', [ 'workflow-action' ] ],
        [ \CB\Automations\Admin\WorkflowTransferController::class, 'guard', [ 'transfer-action' ] ],
        [ \CB\Automations\Admin\WorkflowFolderController::class, 'guard', [ 'create' ] ],
        [ \CB\Automations\Admin\TemplateManagementController::class, 'authorize', [ 'save', 7 ] ],
        [ \CB\Automations\Admin\OperatorRecoveryController::class, 'recover', [] ],
    ];

    foreach ( $guards as [ $class, $method, $args ] ) {
        $entry = new \ReflectionMethod( $class, $method );
        foreach ( [ 'GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', '' ] as $verb ) {
            $_SERVER['REQUEST_METHOD'] = $verb;
            $_POST = [ '_cb_automations_nonce' => 'valid' ];
            $GLOBALS['capability_checks'] = 0;
            $GLOBALS['nonce_checks'] = 0;
            $http = 0;
            try {
                $entry->invoke( null, ...$args );
            } catch ( StopAdminPost $stop ) {
                $http = $stop->http;
            }
            if ( 405 !== $http
                || 0 !== $GLOBALS['capability_checks']
                || 0 !== $GLOBALS['nonce_checks'] ) {
                throw new \RuntimeException( "{$class}::{$method} admitted non-POST {$verb}." );
            }
        }
    }

    // Normal POST requests still traverse the original capability/nonce
    // gates in all four shared-guard controllers.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [ '_cb_automations_nonce' => 'valid' ];
    foreach ( array_slice( $guards, 0, 4 ) as [ $class, $method, $args ] ) {
        $GLOBALS['capability_checks'] = 0;
        $GLOBALS['nonce_checks'] = 0;
        $result = ( new \ReflectionMethod( $class, $method ) )->invoke( null, ...$args );
        if ( 1 !== $GLOBALS['capability_checks'] || 1 !== $GLOBALS['nonce_checks'] ) {
            throw new \RuntimeException( "{$class}::{$method} bypassed POST authorization." );
        }
        if ( 'authorize' === $method && 31 !== $result ) {
            throw new \RuntimeException( 'Template authorization lost actor identity.' );
        }
    }

    echo "Admin POST method boundary: PASS\n";
}
