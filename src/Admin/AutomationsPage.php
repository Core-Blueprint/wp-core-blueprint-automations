<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Runtime\RunStatus;
use CB\Automations\Organisation\FolderRepository;
use CB\Automations\Runtime\StepStatus;
use CB\Automations\Template\WorkflowTemplateRegistry;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Validation\ValidationState;
use CoreBlueprint\Core\Admin\Page;
use CoreBlueprint\Core\UI\Status;

defined( 'ABSPATH' ) || exit;

final class AutomationsPage implements Page {
	public const SLUG       = 'core-blueprint-automations-workflows';
	public const CAPABILITY = 'manage_options';

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Automations', 'core-blueprint-automations' );
	}

	public function menu_title(): string {
		return __( 'Workflows', 'core-blueprint-automations' );
	}

	public function capability(): string {
		return self::CAPABILITY;
	}

	public function position(): ?int {
		return 10;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access Automations.', 'core-blueprint-automations' ),
				esc_html__( 'Forbidden', 'core-blueprint-automations' ),
				[ 'response' => 403 ]
			);
		}

		try {
			$workflow_id = self::requested_workflow_id();
			$reader = new WorkflowAdminReadService();
			if ( $workflow_id > 0 ) {
				$detail = $reader->detail( $workflow_id );
				if ( null === $detail ) {
					$this->render_not_found();
					return;
				}

				if ( self::requires_contract_recovery( $detail['validation'] ) ) {
					$this->template( 'workflow-review.php', [ 'detail' => $detail ] );
					return;
				}

				// Never serialize 64-bit PHP integers into a JavaScript Number.
				// This read-only recovery route leaves the stored definition intact.
				if ( ! \CB\Automations\Workflow\EditorIntegerSafety::is_browser_safe( $detail['record']->definition() ) ) {
					$this->render_unavailable();
					return;
				}

				$this->template(
					'workflow-editor.php',
					[
						'detail'            => $detail,
						'editor_data'       => WorkflowEditorData::build( $detail['record'], $detail['validation'] ),
						'workflow_contexts' => $reader->context_items(),
					]
				);
				return;
			}

			// Read-only workspace filters are never used as executable SQL fragments.
			$page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] )
				? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) )
				: 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
			$view = isset( $_GET['view'] ) && is_scalar( $_GET['view'] )
				? sanitize_key( wp_unslash( (string) $_GET['view'] ) )
				: 'workflows';
			if ( ! in_array( $view, [ 'workflows', 'templates', 'create', 'import' ], true ) ) {
				$view = 'workflows';
			}
			$search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] )
				? substr( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ), 0, 100 )
				: '';
			$status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] )
				? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : 'all';
			$sort = isset( $_GET['sort'] ) && is_scalar( $_GET['sort'] )
				? sanitize_key( wp_unslash( (string) $_GET['sort'] ) ) : 'updated_desc';
			$status = in_array( $status, [ 'all', 'enabled', 'disabled' ], true ) ? $status : 'all';
			$folders = [];
			$folder_filter = null;
			$assignments = [];
			if ( 'workflows' === $view ) {
				FolderRepository::ensure_ready();
				$folders = FolderRepository::all();
				$requested_folder = isset( $_GET['folder'] ) && is_scalar( $_GET['folder'] )
					? sanitize_text_field( wp_unslash( (string) $_GET['folder'] ) ) : 'all';
				if ( 'unfiled' === $requested_folder ) {
					$folder_filter = 0;
				} elseif ( ctype_digit( $requested_folder ) && (int) $requested_folder > 0 ) {
					$folder_filter = (int) $requested_folder;
				}
			}
			$sort = in_array( $sort, [ 'updated_desc', 'updated_asc', 'name_asc', 'name_desc' ], true ) ? $sort : 'updated_desc';
			$listing = 'workflows' === $view ? $reader->index( $page, 25, $search, $status, $sort, $folder_filter ) : null;
			if ( null !== $listing ) {
				$assignments = FolderRepository::assignments(
					array_map( static fn( array $item ): int => $item['record']->id(), $listing['items'] )
				);
			}
			$this->template(
				'automations.php',
				[
					'view'      => $view,
					'search'    => $search,
					'status'    => $status,
					'sort'      => $sort,
					'listing'   => $listing,
					'folders' => $folders,
					'folder_filter' => $folder_filter,
					'assignments' => $assignments,
					'templates' => 'templates' === $view ? WorkflowTemplateRegistry::all() : [],
					'import_preview' => 'import' === $view && isset( $_GET['token'] ) && is_scalar( $_GET['token'] )
						? WorkflowTransferController::preview( sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) ) : null,
					'import_token' => 'import' === $view && isset( $_GET['token'] ) && is_scalar( $_GET['token'] )
						? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '',
				]
			);
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Admin page read failed: ' . $error->getMessage() );
			$this->render_unavailable();
		}
	}

	/** Read-only workflow route selector shared by rendering and asset gates. */
	public static function requested_workflow_id(): int {
		return isset( $_GET['workflow'] ) && is_scalar( $_GET['workflow'] )
			? absint( wp_unslash( (string) $_GET['workflow'] ) )
			: 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bounded read-only routing.
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

	public static function validation_status( ValidationState $state ): string {
		$variant = match ( $state ) {
			ValidationState::Valid                 => 'active',
			ValidationState::NeedsReview           => 'warning',
			ValidationState::DependencyUnavailable => 'warning',
			ValidationState::Invalid               => 'error',
		};
		return Status::render( $variant, self::validation_label( $state ) );
	}

	public static function validation_label( ValidationState $state ): string {
		return match ( $state ) {
			ValidationState::Valid                 => __( 'Valid', 'core-blueprint-automations' ),
			ValidationState::NeedsReview           => __( 'Needs review', 'core-blueprint-automations' ),
			ValidationState::DependencyUnavailable => __( 'Dependency unavailable', 'core-blueprint-automations' ),
			ValidationState::Invalid               => __( 'Invalid', 'core-blueprint-automations' ),
		};
	}

	public static function activation_status( string $state ): string {
		return Status::render(
			'enabled' === $state ? 'active' : 'idle',
			'enabled' === $state
				? __( 'Enabled', 'core-blueprint-automations' )
				: __( 'Disabled', 'core-blueprint-automations' )
		);
	}

	public static function run_status( RunStatus $status ): string {
		$variant = match ( $status ) {
			RunStatus::Succeeded => 'active',
			RunStatus::Failed => 'error',
			RunStatus::Running,
			RunStatus::RetryWait,
			RunStatus::Blocked,
			RunStatus::Indeterminate => 'warning',
			default => 'idle',
		};
		return Status::render( $variant, self::run_status_label( $status ) );
	}

	public static function run_status_label( RunStatus $status ): string {
		return match ( $status ) {
			RunStatus::Queued => __( 'Queued', 'core-blueprint-automations' ),
			RunStatus::Running => __( 'Running', 'core-blueprint-automations' ),
			RunStatus::RetryWait => __( 'Waiting to retry', 'core-blueprint-automations' ),
			RunStatus::Blocked => __( 'Blocked', 'core-blueprint-automations' ),
			RunStatus::Succeeded => __( 'Succeeded', 'core-blueprint-automations' ),
			RunStatus::Skipped => __( 'Skipped', 'core-blueprint-automations' ),
			RunStatus::Failed => __( 'Failed', 'core-blueprint-automations' ),
			RunStatus::Cancelled => __( 'Cancelled', 'core-blueprint-automations' ),
			RunStatus::Indeterminate => __( 'Outcome unknown', 'core-blueprint-automations' ),
		};
	}

	public static function step_status( StepStatus $status ): string {
		$variant = match ( $status ) {
			StepStatus::Succeeded => 'active',
			StepStatus::Failed => 'error',
			StepStatus::Blocked,
			StepStatus::Indeterminate,
			StepStatus::Interrupted => 'warning',
			default => 'idle',
		};
		$label = match ( $status ) {
			StepStatus::Running => __( 'Running', 'core-blueprint-automations' ),
			StepStatus::Succeeded => __( 'Succeeded', 'core-blueprint-automations' ),
			StepStatus::Skipped => __( 'Skipped', 'core-blueprint-automations' ),
			StepStatus::Failed => __( 'Failed', 'core-blueprint-automations' ),
			StepStatus::Blocked => __( 'Blocked', 'core-blueprint-automations' ),
			StepStatus::Cancelled => __( 'Cancelled', 'core-blueprint-automations' ),
			StepStatus::Indeterminate => __( 'Outcome unknown', 'core-blueprint-automations' ),
			StepStatus::Interrupted => __( 'Interrupted', 'core-blueprint-automations' ),
		};
		return Status::render( $variant, $label );
	}

	private static function requires_contract_recovery( ValidationResult $validation ): bool {
		foreach ( $validation->issues() as $issue ) {
			$code = $issue->code();
			if (
				str_starts_with( $code, 'dependency.' )
				|| in_array( $code, [ 'capability.missing', 'capability.schema_mismatch' ], true )
			) {
				return true;
			}
		}
		return false;
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
		echo '<div class="wrap cb-core-wrap"><h1 class="cb-core-title">' . esc_html__( 'Automation not found', 'core-blueprint-automations' ) . '</h1><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Automations', 'core-blueprint-automations' ) . '</a></p></div>';
	}

	private function render_unavailable(): void {
		echo '<div class="wrap cb-core-wrap"><h1 class="cb-core-title">' . esc_html__( 'Automations', 'core-blueprint-automations' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'Automation information is temporarily unavailable. No workflow data was changed.', 'core-blueprint-automations' ) . '</p></div></div>';
	}
}
