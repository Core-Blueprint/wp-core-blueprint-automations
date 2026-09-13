<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\Page;

defined( 'ABSPATH' ) || exit;

final class AutomationRunsPage implements Page {
	public const SLUG = 'core-blueprint-automation-runs';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Automation Runs', 'core-blueprint-automations' );
	}

	public function menu_title(): string {
		return __( 'Runs', 'core-blueprint-automations' );
	}

	public function capability(): string {
		return RunHistoryCapability::CAPABILITY;
	}

	public function position(): ?int {
		return 20;
	}

	public function render(): void {
		if ( ! current_user_can( RunHistoryCapability::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view automation runs.', 'core-blueprint-automations' ),
				esc_html__( 'Forbidden', 'core-blueprint-automations' ),
				[ 'response' => 403 ]
			);
		}

		try {
			$reader = new RunHistoryReadService();
			$run_id = isset( $_GET['run'] ) && is_scalar( $_GET['run'] )
				? absint( wp_unslash( (string) $_GET['run'] ) )
				: 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			if ( $run_id > 0 ) {
				$detail = $reader->detail( $run_id );
				if ( null === $detail ) {
					$this->render_not_found();
					return;
				}
				$this->template( 'run-detail.php', [ 'detail' => $detail ] );
				return;
			}

			$page = isset( $_GET['run_page'] ) && is_scalar( $_GET['run_page'] )
				? max( 1, absint( wp_unslash( (string) $_GET['run_page'] ) ) )
				: 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
			$workflow_id = isset( $_GET['workflow_id'] ) && is_scalar( $_GET['workflow_id'] )
				? absint( wp_unslash( (string) $_GET['workflow_id'] ) )
				: 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering.
			$this->template( 'runs.php', [ 'listing' => $reader->index( $page, $workflow_id ) ] );
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Run history page read failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded operational diagnostic.
			$this->render_unavailable();
		}
	}

	/** @param array<string,scalar> $query */
	public static function url( array $query = [] ): string {
		$args = [ 'page' => self::SLUG ];
		foreach ( $query as $key => $value ) {
			if ( ! is_string( $key ) || 'page' === $key || sanitize_key( $key ) !== $key || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ $key ] = (string) $value;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/** @param array<string,mixed> $vars */
	private function template( string $name, array $vars ): void {
		$path = CB_AUTOMATIONS_DIR . 'templates/admin/' . $name;
		if ( ! is_file( $path ) ) {
			$this->render_unavailable();
			return;
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- bounded internal presentation data.
		require $path;
	}

	private function render_not_found(): void {
		echo '<div class="wrap cb-core-wrap"><h1 class="cb-core-title">' . esc_html__( 'Automation run not found', 'core-blueprint-automations' ) . '</h1><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Runs', 'core-blueprint-automations' ) . '</a></p></div>';
	}

	private function render_unavailable(): void {
		echo '<div class="wrap cb-core-wrap"><h1 class="cb-core-title">' . esc_html__( 'Automation Runs', 'core-blueprint-automations' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'Automation run history is temporarily unavailable. No workflow or run data was changed.', 'core-blueprint-automations' ) . '</p></div></div>';
	}
}
