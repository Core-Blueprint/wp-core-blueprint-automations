<?php
/**
 * Automations admin index.
 *
 * @var array{items:array,total:int,page:int,per_page:int,pages:int} $listing
 * @var \CB\Automations\Template\WorkflowTemplate[] $templates
 */

defined( 'ABSPATH' ) || exit;

$notice = isset( $_GET['notice'] ) && is_string( $_GET['notice'] )
	? sanitize_key( wp_unslash( $_GET['notice'] ) )
	: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bounded read-only notice routing.

$notices = [
	'created'                                => [ 'success', __( 'Automation draft created.', 'core-blueprint-automations' ) ],
	'created_template'                       => [ 'success', __( 'Automation created from template. Review it, assign execution authority and enable it when ready.', 'core-blueprint-automations' ) ],
	'template_unavailable'                   => [ 'error', __( 'That workflow template is no longer available. No automation was created.', 'core-blueprint-automations' ) ],
	'enabled'                                => [ 'success', __( 'Automation enabled.', 'core-blueprint-automations' ) ],
	'disabled'                               => [ 'success', __( 'Automation disabled.', 'core-blueprint-automations' ) ],
	'saved_disabled'                         => [ 'warning', __( 'The automation remains disabled because the workflow is not valid yet.', 'core-blueprint-automations' ) ],
	'saved_disabled_execution_unavailable'   => [ 'warning', __( 'The automation remains disabled because secure runtime encryption is unavailable on this site.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_missing'       => [ 'warning', __( 'The automation remains disabled. Open the builder and assign your account as execution authority first.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_invalid'       => [ 'warning', __( 'The automation remains disabled because its stored execution authority is no longer a valid WordPress user.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_denied'        => [ 'warning', __( 'The automation remains disabled because its execution principal no longer has all required permissions.', 'core-blueprint-automations' ) ],
	'saved_disabled_operator_denied'         => [ 'warning', __( 'The automation remains disabled because your account does not have all permissions required to enable it.', 'core-blueprint-automations' ) ],
	'saved_disabled_capability_unavailable'  => [ 'warning', __( 'The automation remains disabled because execution authority could not be verified against the current capability contracts.', 'core-blueprint-automations' ) ],
	'persistence_blocked'                    => [ 'error', __( 'The automation state was not changed because the stored workflow contains data that cannot be persisted safely.', 'core-blueprint-automations' ) ],
	'conflict'                               => [ 'warning', __( 'This automation changed in another request. Reload the page before changing its state.', 'core-blueprint-automations' ) ],
	'not_found'                              => [ 'error', __( 'The automation could not be found. No state was changed.', 'core-blueprint-automations' ) ],
	'invalid'                                => [ 'error', __( 'The requested automation state was invalid. No state was changed.', 'core-blueprint-automations' ) ],
	'invalid_name'                           => [ 'error', __( 'Enter an automation name between 1 and 191 characters.', 'core-blueprint-automations' ) ],
	'storage_failed'                         => [ 'error', __( 'The automation could not be saved because workflow storage is unavailable. No workflow state was changed.', 'core-blueprint-automations' ) ],
	'failed'                                 => [ 'error', __( 'The automation could not be updated. Reload the page before trying again.', 'core-blueprint-automations' ) ],
];
?>
<div class="wrap cb-core-wrap cb-automations-admin cb-automations-index-page">
	<h1 class="cb-core-title"><?php esc_html_e( 'Automations', 'core-blueprint-automations' ); ?></h1>
	<p class="cb-core-intro"><?php esc_html_e( 'Build linear workflows from capabilities registered by Core Blueprint extensions.', 'core-blueprint-automations' ); ?></p>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<section class="cb-core-panel cb-automations-create-panel">
		<span class="cb-automations-panel-eyebrow"><?php esc_html_e( 'Automation Builder', 'core-blueprint-automations' ); ?></span>
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

	<?php if ( ! empty( $templates ) ) : ?>
		<section class="cb-core-panel cb-automations-templates-panel">
			<span class="cb-automations-panel-eyebrow"><?php esc_html_e( 'Starter workflows', 'core-blueprint-automations' ); ?></span>
			<h2><?php esc_html_e( 'Start from a template', 'core-blueprint-automations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Create a normal editable workflow from a provider template. The new workflow is yours and is not kept linked to the template.', 'core-blueprint-automations' ); ?></p>
			<div class="cb-automations-template-grid">
				<?php foreach ( $templates as $workflow_template ) : ?>
					<article class="cb-automations-template-card">
						<div>
							<span class="cb-automations-template-card__category"><?php echo esc_html( $workflow_template->category() ); ?></span>
							<h3><?php echo esc_html( $workflow_template->title() ); ?></h3>
							<p><?php echo esc_html( $workflow_template->description() ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="cb_automations_create_from_template" />
							<input type="hidden" name="template_provider" value="<?php echo esc_attr( $workflow_template->provider() ); ?>" />
							<input type="hidden" name="template_id" value="<?php echo esc_attr( $workflow_template->id() ); ?>" />
							<?php wp_nonce_field( 'cb_automations_create_from_template', '_cb_automations_nonce' ); ?>
							<button type="submit" class="button cb-core-button cb-core-button--secondary"><?php esc_html_e( 'Use template', 'core-blueprint-automations' ); ?></button>
						</form>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

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
			<div class="cb-core-empty-state"><p><?php esc_html_e( 'No automations have been created yet.', 'core-blueprint-automations' ); ?></p></div>
		<?php else : ?>
			<div class="cb-automations-workflow-list">
				<?php foreach ( $listing['items'] as $item ) : ?>
					<?php
					$record           = $item['record'];
					$definition       = $record->definition();
					$edit_url         = \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $record->id() ] );
					$issue_count      = count( $item['validation']->issues() );
					$is_valid         = $item['validation']->is_valid();
					$is_enabled       = 'enabled' === $record->activation_state()->value;
					$activation_label = $is_enabled ? __( 'Enabled', 'core-blueprint-automations' ) : __( 'Disabled', 'core-blueprint-automations' );
					$updated          = '' !== $record->updated_at() ? get_date_from_gmt( preg_replace( '/\.\d+$/', '', $record->updated_at() ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
					$trigger_count    = null === $definition->trigger() ? 0 : 1;
					$state_count      = count( $definition->states() );
					$condition_count  = count( $definition->conditions() );
					$action_count     = count( $definition->actions() );
					$target_state     = $is_enabled ? 'disabled' : 'enabled';
					$toggle_label     = $is_enabled ? __( 'Disable', 'core-blueprint-automations' ) : __( 'Enable', 'core-blueprint-automations' );

					if ( ! $is_valid ) {
						$card_state = 'has-error';
						$status_variant = 'error';
						$status_label = __( 'Needs attention', 'core-blueprint-automations' );
						$status_detail = sprintf(
							/* translators: 1: activation state, 2: validation state, 3: issue count. */
							__( '%1$s · %2$s · %3$s', 'core-blueprint-automations' ),
							$activation_label,
							\CB\Automations\Admin\AutomationsPage::validation_label( $item['state'] ),
							sprintf( _n( '%d issue', '%d issues', $issue_count, 'core-blueprint-automations' ), $issue_count )
						);
					} elseif ( $is_enabled ) {
						$card_state = 'is-active';
						$status_variant = 'active';
						$status_label = __( 'Enabled', 'core-blueprint-automations' );
						$status_detail = __( 'Valid · ready to run', 'core-blueprint-automations' );
					} else {
						$card_state = 'is-inactive';
						$status_variant = 'idle';
						$status_label = __( 'Disabled', 'core-blueprint-automations' );
						$status_detail = __( 'Valid · will not run', 'core-blueprint-automations' );
					}
					?>
					<article class="cb-automations-workflow-card <?php echo esc_attr( $card_state ); ?>">
						<span class="cb-automations-workflow-card__accent" aria-hidden="true"></span>
						<div class="cb-automations-workflow-card__main">
							<div class="cb-automations-workflow-card__title-row">
								<div><span class="cb-automations-workflow-card__eyebrow"><?php esc_html_e( 'Automation', 'core-blueprint-automations' ); ?></span><h3><a class="cb-automations-workflow-card__title-link" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $record->name() ); ?></a></h3></div>
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
								<div class="cb-automations-workflow-card__actions">
									<a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Open builder', 'core-blueprint-automations' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-automations-workflow-card__toggle">
										<input type="hidden" name="action" value="cb_automations_toggle_workflow" />
										<input type="hidden" name="workflow_id" value="<?php echo esc_attr( (string) $record->id() ); ?>" />
										<input type="hidden" name="activation_state" value="<?php echo esc_attr( $target_state ); ?>" />
										<input type="hidden" name="_cb_automations_nonce" value="<?php echo esc_attr( wp_create_nonce( \CB\Automations\Admin\WorkflowActivationController::nonce_action( $record->id() ) ) ); ?>" />
										<button type="submit" class="button cb-core-button cb-core-button--secondary" aria-label="<?php echo esc_attr( sprintf( '%s: %s', $toggle_label, $record->name() ) ); ?>"><?php echo esc_html( $toggle_label ); ?></button>
									</form>
								</div>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<?php if ( $listing['pages'] > 1 ) : ?>
				<div class="tablenav bottom cb-automations-pagination"><div class="tablenav-pages">
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
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
