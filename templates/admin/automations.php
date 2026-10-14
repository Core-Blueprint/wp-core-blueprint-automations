<?php
/**
 * Automations Workspace: compact server-filtered workflow management.
 *
 * @var string $view
 * @var string $search
 * @var string $status
 * @var string $sort
 * @var array{items:array,total:int,page:int,per_page:int,pages:int}|null $listing
 * @var \CB\Automations\Template\WorkflowTemplate[] $templates
 */
defined( 'ABSPATH' ) || exit;

$base_url = \CB\Automations\Admin\AutomationsPage::url();
$notice = isset( $_GET['notice'] ) && is_scalar( $_GET['notice'] )
	? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
$notices = [
	'created' => [ 'success', __( 'Automation draft created.', 'core-blueprint-automations' ) ],
	'created_template' => [ 'success', __( 'Automation created from template. Review and assign execution authority before enabling.', 'core-blueprint-automations' ) ],
	'template_unavailable' => [ 'error', __( 'That workflow template is no longer available.', 'core-blueprint-automations' ) ],
	'enabled' => [ 'success', __( 'Automation enabled.', 'core-blueprint-automations' ) ],
	'disabled' => [ 'success', __( 'Automation disabled.', 'core-blueprint-automations' ) ],
	'saved_disabled' => [ 'warning', __( 'Workflow was saved but remains disabled because it is not valid.', 'core-blueprint-automations' ) ],
	'saved_disabled_execution_unavailable' => [ 'warning', __( 'Secure runtime encryption is unavailable.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_missing' => [ 'warning', __( 'Assign execution authority in the Builder before enabling.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_invalid' => [ 'warning', __( 'Stored execution principal no longer exists.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_denied' => [ 'warning', __( 'Execution principal does not have the required permissions.', 'core-blueprint-automations' ) ],
	'saved_disabled_operator_denied' => [ 'warning', __( 'Your account does not have the required permissions.', 'core-blueprint-automations' ) ],
	'saved_disabled_capability_unavailable' => [ 'warning', __( 'Workflow capability contracts cannot be verified.', 'core-blueprint-automations' ) ],
	'persistence_blocked' => [ 'error', __( 'The workflow contains data that cannot be persisted safely.', 'core-blueprint-automations' ) ],
	'conflict' => [ 'warning', __( 'The workflow changed in another request. Reload and retry.', 'core-blueprint-automations' ) ],
	'not_found' => [ 'error', __( 'Workflow not found.', 'core-blueprint-automations' ) ],
	'invalid' => [ 'error', __( 'Invalid workflow configuration.', 'core-blueprint-automations' ) ],
	'invalid_name' => [ 'error', __( 'Enter an automation name between 1 and 191 characters.', 'core-blueprint-automations' ) ],
	'storage_failed' => [ 'error', __( 'Workflow storage is unavailable.', 'core-blueprint-automations' ) ],
	'failed' => [ 'error', __( 'The workflow could not be updated.', 'core-blueprint-automations' ) ],
];
?>
<div class="wrap cb-core-wrap cb-automations-admin cb-automations-index-page cb-automations-workspace">
	<header class="cb-automations-workspace__header">
		<div>
			<span class="cb-automations-panel-eyebrow"><?php esc_html_e( 'Automation Builder', 'core-blueprint-automations' ); ?></span>
			<h1 class="cb-core-title"><?php esc_html_e( 'Automations', 'core-blueprint-automations' ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Find, manage and create your WordPress automations.', 'core-blueprint-automations' ); ?></p>
		</div>
		<a class="button button-primary cb-core-button cb-core-button--primary" href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'view' => 'create' ] ) ); ?>"><?php esc_html_e( '+ New automation', 'core-blueprint-automations' ); ?></a>
	</header>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<nav class="cb-automations-workspace__tabs" aria-label="<?php esc_attr_e( 'Automations workspace', 'core-blueprint-automations' ); ?>">
		<?php foreach ( [ 'workflows' => __( 'Workflows', 'core-blueprint-automations' ), 'templates' => __( 'Templates', 'core-blueprint-automations' ), 'create' => __( 'New automation', 'core-blueprint-automations' ) ] as $key => $title ) : ?>
			<a class="<?php echo $view === $key ? 'is-current' : ''; ?>" <?php if ( $view === $key ) : ?>aria-current="page"<?php endif; ?> href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'view' => $key ] ) ); ?>"><?php echo esc_html( $title ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'create' === $view ) : ?>
		<section class="cb-core-panel cb-automations-workspace__panel">
			<h2><?php esc_html_e( 'Create automation', 'core-blueprint-automations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Create a disabled draft, then configure it securely in the Builder.', 'core-blueprint-automations' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-row cb-automations-create-form">
				<input type="hidden" name="action" value="cb_automations_create_workflow" />
				<?php wp_nonce_field( 'cb_automations_create_workflow', '_cb_automations_nonce' ); ?>
				<label for="cb-automation-name"><?php esc_html_e( 'Automation name', 'core-blueprint-automations' ); ?></label>
				<input id="cb-automation-name" name="name" type="text" class="regular-text" maxlength="191" required />
				<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Create automation', 'core-blueprint-automations' ); ?></button>
			</form>
		</section>
	<?php elseif ( 'templates' === $view ) : ?>
		<section class="cb-core-panel cb-automations-workspace__panel">
			<h2><?php esc_html_e( 'Start from a template', 'core-blueprint-automations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Create a normal editable workflow from a provider template. The new workflow is not linked to the original.', 'core-blueprint-automations' ); ?></p>
			<?php if ( [] === $templates ) : ?>
				<p><?php esc_html_e( 'No starter templates available.', 'core-blueprint-automations' ); ?></p>
			<?php else : ?>
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
			<?php endif; ?>
		</section>
	<?php else : ?>
		<section class="cb-core-panel cb-automations-workspace__panel">
			<div class="cb-automations-workspace__panel-heading">
				<div>
					<h2><?php esc_html_e( 'Workflows', 'core-blueprint-automations' ); ?></h2>
					<p class="description"><?php printf( esc_html( _n( '%d matching workflow', '%d matching workflows', $listing['total'], 'core-blueprint-automations' ) ), (int) $listing['total'] ); ?></p>
				</div>
			</div>
			<form class="cb-automations-workspace__filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( \CB\Automations\Admin\AutomationsPage::SLUG ); ?>" />
				<label for="cb-automations-search"><?php esc_html_e( 'Search workflows', 'core-blueprint-automations' ); ?></label>
				<input id="cb-automations-search" type="search" name="s" maxlength="100" placeholder="<?php esc_attr_e( 'Search by name...', 'core-blueprint-automations' ); ?>" value="<?php echo esc_attr( $search ); ?>" />
				<label for="cb-automations-status"><?php esc_html_e( 'Status', 'core-blueprint-automations' ); ?></label>
				<select name="status" id="cb-automations-status">
					<?php foreach ( [ 'all' => __( 'All statuses', 'core-blueprint-automations' ), 'enabled' => __( 'Enabled', 'core-blueprint-automations' ), 'disabled' => __( 'Disabled', 'core-blueprint-automations' ) ] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="cb-automations-sort"><?php esc_html_e( 'Sort', 'core-blueprint-automations' ); ?></label>
				<select name="sort" id="cb-automations-sort">
					<?php foreach ( [ 'updated_desc' => __( 'Recently updated', 'core-blueprint-automations' ), 'updated_asc' => __( 'Oldest updated', 'core-blueprint-automations' ), 'name_asc' => __( 'Name A–Z', 'core-blueprint-automations' ), 'name_desc' => __( 'Name Z–A', 'core-blueprint-automations' ) ] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button cb-core-button cb-core-button--secondary"><?php esc_html_e( 'Apply', 'core-blueprint-automations' ); ?></button>
				<a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'core-blueprint-automations' ); ?></a>
			</form>
			<?php if ( [] === $listing['items'] ) : ?>
				<p class="cb-automations-workspace__empty"><?php esc_html_e( 'No workflows match your filters.', 'core-blueprint-automations' ); ?></p>
			<?php else : ?>
				<div class="cb-automations-workspace__table-scroll">
					<table class="widefat striped cb-automations-workspace__table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Automation', 'core-blueprint-automations' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Trigger', 'core-blueprint-automations' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint-automations' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Health', 'core-blueprint-automations' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Updated', 'core-blueprint-automations' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'core-blueprint-automations' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $listing['items'] as $item ) : ?>
							<?php
							$record = $item['record'];
							$trigger = $record->definition()->trigger();
							$ref = null !== $trigger ? $trigger->capability() : null;
							$valid = $item['validation']->is_valid();
							$enabled = 'enabled' === $record->activation_state()->value;
							$target = $enabled ? 'disabled' : 'enabled';
							$updated = get_date_from_gmt( preg_replace( '/\.\d+$/', '', $record->updated_at() ), get_option( 'date_format' ) );
							?>
							<tr>
								<td><a class="cb-automations-workspace__name" href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $record->id() ] ) ); ?>"><?php echo esc_html( $record->name() ); ?></a><span class="description">#<?php echo esc_html( (string) $record->id() ); ?> · r<?php echo esc_html( (string) $record->revision() ); ?></span></td>
								<td><?php echo null !== $ref ? esc_html( $ref->id() ) : '&mdash;'; ?></td>
								<td><span class="cb-automations-workspace__state <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>"><?php echo esc_html( $enabled ? __( 'Enabled', 'core-blueprint-automations' ) : __( 'Disabled', 'core-blueprint-automations' ) ); ?></span></td>
								<td><span class="cb-automations-workspace__health <?php echo $valid ? 'is-ready' : 'has-issues'; ?>"><?php echo esc_html( $valid ? __( 'Ready', 'core-blueprint-automations' ) : sprintf( _n( '%d issue', '%d issues', count( $item['validation']->issues() ), 'core-blueprint-automations' ), count( $item['validation']->issues() ) ) ); ?></span></td>
								<td><?php echo esc_html( $updated ); ?></td>
								<td>
									<div class="cb-automations-workspace__actions">
										<a class="button button-small" href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $record->id() ] ) ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint-automations' ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="cb_automations_toggle_workflow" />
											<input type="hidden" name="workflow_id" value="<?php echo esc_attr( (string) $record->id() ); ?>" />
											<input type="hidden" name="activation_state" value="<?php echo esc_attr( $target ); ?>" />
											<input type="hidden" name="_cb_automations_nonce" value="<?php echo esc_attr( wp_create_nonce( \CB\Automations\Admin\WorkflowActivationController::nonce_action( $record->id() ) ) ); ?>" />
											<button type="submit" class="button button-small"><?php echo esc_html( $enabled ? __( 'Disable', 'core-blueprint-automations' ) : __( 'Enable', 'core-blueprint-automations' ) ); ?></button>
										</form>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $listing['pages'] > 1 ) : ?>
					<nav class="tablenav bottom cb-automations-pagination" aria-label="<?php esc_attr_e( 'Workflow pages', 'core-blueprint-automations' ); ?>">
						<div class="tablenav-pages">
							<?php echo wp_kses_post( paginate_links( [
								'base' => add_query_arg( 'paged', '%#%', \CB\Automations\Admin\AutomationsPage::url( [ 's' => $search, 'status' => $status, 'sort' => $sort ] ) ),
								'format' => '', 'current' => $listing['page'], 'total' => $listing['pages'],
								'prev_text' => __( '‹ Previous', 'core-blueprint-automations' ),
								'next_text' => __( 'Next ›', 'core-blueprint-automations' ),
							] ) ); ?>
						</div>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</div>
