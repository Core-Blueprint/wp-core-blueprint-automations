<?php
/**
 * Automations admin index.
 *
 * @var array{items:array,total:int,page:int,per_page:int,pages:int} $listing
 */

defined( 'ABSPATH' ) || exit;

$notice = isset( $_GET['notice'] ) && is_string( $_GET['notice'] )
	? sanitize_key( wp_unslash( $_GET['notice'] ) )
	: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bounded read-only notice routing.

$notices = [
	'created'        => [ 'success', __( 'Automation draft created.', 'core-blueprint-automations' ) ],
	'invalid_name'   => [ 'error', __( 'Enter an automation name between 1 and 191 characters.', 'core-blueprint-automations' ) ],
	'storage_failed' => [ 'error', __( 'The automation could not be created because workflow storage is unavailable. No workflow was saved.', 'core-blueprint-automations' ) ],
	'failed'         => [ 'error', __( 'The automation could not be created. No workflow was saved.', 'core-blueprint-automations' ) ],
];
?>
<div class="wrap cb-core-wrap cb-automations-admin cb-automations-index-page">
	<h1 class="cb-core-title"><?php esc_html_e( 'Automations', 'core-blueprint-automations' ); ?></h1>
	<p class="cb-core-intro"><?php esc_html_e( 'Build linear workflows from capabilities registered by Core Blueprint extensions.', 'core-blueprint-automations' ); ?></p>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<section class="cb-core-panel cb-automations-create-panel">
		<span class="cb-automations-panel-eyebrow"><?php esc_html_e( 'Automation designer', 'core-blueprint-automations' ); ?></span>
		<h2><?php esc_html_e( 'Create automation', 'core-blueprint-automations' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Start with a name, then build a clear When → Get data → Only if → Then workflow.', 'core-blueprint-automations' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-row cb-automations-create-form">
			<input type="hidden" name="action" value="cb_automations_create_workflow" />
			<?php wp_nonce_field( 'cb_automations_create_workflow', '_cb_automations_nonce' ); ?>
			<label for="cb-automation-name" class="screen-reader-text"><?php esc_html_e( 'Automation name', 'core-blueprint-automations' ); ?></label>
			<input id="cb-automation-name" name="name" type="text" class="regular-text" maxlength="191" required placeholder="<?php echo esc_attr__( 'Automation name', 'core-blueprint-automations' ); ?>" />
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Create automation', 'core-blueprint-automations' ); ?></button>
		</form>
	</section>

	<section class="cb-core-panel cb-automations-workflows-panel">
		<div class="cb-automations-overview-heading">
			<div>
				<span class="cb-automations-panel-eyebrow"><?php esc_html_e( 'Workflow library', 'core-blueprint-automations' ); ?></span>
				<h2><?php esc_html_e( 'Workflows', 'core-blueprint-automations' ); ?></h2>
			</div>
			<span class="cb-automations-workflow-count">
				<?php echo esc_html( sprintf( _n( '%d workflow', '%d workflows', $listing['total'], 'core-blueprint-automations' ), $listing['total'] ) ); ?>
			</span>
		</div>

		<?php if ( empty( $listing['items'] ) ) : ?>
			<div class="cb-core-empty-state">
				<p><?php esc_html_e( 'No automations have been created yet.', 'core-blueprint-automations' ); ?></p>
			</div>
		<?php else : ?>
			<div class="cb-automations-workflow-list">
				<?php foreach ( $listing['items'] as $item ) : ?>
					<?php
					$record          = $item['record'];
					$definition      = $record->definition();
					$edit_url        = \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $record->id() ] );
					$issue_count     = count( $item['validation']->issues() );
					$is_valid        = $item['validation']->is_valid();
					$is_enabled      = 'enabled' === $record->activation_state()->value;
					$activation_label = $is_enabled
						? __( 'Enabled', 'core-blueprint-automations' )
						: __( 'Disabled', 'core-blueprint-automations' );
					$updated         = '' !== $record->updated_at()
						? get_date_from_gmt( preg_replace( '/\.\d+$/', '', $record->updated_at() ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
						: '—';
					$trigger_count   = null === $definition->trigger() ? 0 : 1;
					$state_count     = count( $definition->states() );
					$condition_count = count( $definition->conditions() );
					$action_count    = count( $definition->actions() );

					if ( ! $is_valid ) {
						$card_state     = 'has-error';
						$status_variant = 'error';
						$status_label   = __( 'Needs attention', 'core-blueprint-automations' );
						$status_detail  = sprintf(
							/* translators: 1: activation state, 2: validation state, 3: issue count. */
							__( '%1$s · %2$s · %3$s', 'core-blueprint-automations' ),
							$activation_label,
							\CB\Automations\Admin\AutomationsPage::validation_label( $item['state'] ),
							sprintf( _n( '%d issue', '%d issues', $issue_count, 'core-blueprint-automations' ), $issue_count )
						);
					} elseif ( $is_enabled ) {
						$card_state     = 'is-active';
						$status_variant = 'active';
						$status_label   = __( 'Enabled', 'core-blueprint-automations' );
						$status_detail  = __( 'Valid · ready to run', 'core-blueprint-automations' );
					} else {
						$card_state     = 'is-inactive';
						$status_variant = 'idle';
						$status_label   = __( 'Disabled', 'core-blueprint-automations' );
						$status_detail  = __( 'Valid · will not run', 'core-blueprint-automations' );
					}
					?>
					<a class="cb-automations-workflow-card <?php echo esc_attr( $card_state ); ?>" href="<?php echo esc_url( $edit_url ); ?>">
						<span class="cb-automations-workflow-card__accent" aria-hidden="true"></span>
						<div class="cb-automations-workflow-card__main">
							<div class="cb-automations-workflow-card__title-row">
								<div>
									<span class="cb-automations-workflow-card__eyebrow"><?php esc_html_e( 'Automation', 'core-blueprint-automations' ); ?></span>
									<h3><?php echo esc_html( $record->name() ); ?></h3>
								</div>
								<div class="cb-automations-workflow-card__status">
									<?php if ( 'error' === $status_variant ) : ?>
										<span class="cb-automations-workflow-card__health cb-automations-workflow-card__health--error"><span aria-hidden="true"></span><?php echo esc_html( $status_label ); ?></span>
									<?php else : ?>
										<?php echo \CB\Automations\Admin\AutomationsPage::activation_status( $record->activation_state()->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
									<?php endif; ?>
									<small><?php echo esc_html( $status_detail ); ?></small>
								</div>
							</div>

							<div class="cb-automations-workflow-card__steps" aria-label="<?php esc_attr_e( 'Workflow structure', 'core-blueprint-automations' ); ?>">
								<span><strong><?php echo esc_html( (string) $trigger_count ); ?></strong> <?php esc_html_e( 'Trigger', 'core-blueprint-automations' ); ?></span>
								<span><strong><?php echo esc_html( (string) $state_count ); ?></strong> <?php echo esc_html( _n( 'Data lookup', 'Data lookups', $state_count, 'core-blueprint-automations' ) ); ?></span>
								<span><strong><?php echo esc_html( (string) $condition_count ); ?></strong> <?php echo esc_html( _n( 'Condition', 'Conditions', $condition_count, 'core-blueprint-automations' ) ); ?></span>
								<span><strong><?php echo esc_html( (string) $action_count ); ?></strong> <?php echo esc_html( _n( 'Action', 'Actions', $action_count, 'core-blueprint-automations' ) ); ?></span>
							</div>

							<div class="cb-automations-workflow-card__footer">
								<span><?php printf( esc_html__( 'Updated %s', 'core-blueprint-automations' ), esc_html( $updated ) ); ?></span>
								<span class="cb-automations-workflow-card__open"><?php esc_html_e( 'Open designer', 'core-blueprint-automations' ); ?> →</span>
							</div>
						</div>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( $listing['pages'] > 1 ) : ?>
				<div class="tablenav bottom cb-automations-pagination">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links( [
								'base'      => add_query_arg( 'paged', '%#%', \CB\Automations\Admin\AutomationsPage::url() ),
								'format'    => '',
								'current'   => $listing['page'],
								'total'     => $listing['pages'],
								'prev_text' => __( '‹ Previous', 'core-blueprint-automations' ),
								'next_text' => __( 'Next ›', 'core-blueprint-automations' ),
							] )
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
