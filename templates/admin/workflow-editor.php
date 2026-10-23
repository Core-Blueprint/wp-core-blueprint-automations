<?php
/**
 * Linear workflow editor.
 *
 * @var array{record:\CB\Automations\Persistence\WorkflowRecord,validation:\CB\Automations\Validation\ValidationResult,state:\CB\Automations\Validation\ValidationState} $detail
 * @var array<string,mixed> $editor_data
 * @var array<int,array{id:int,name:string}> $workflow_contexts
 */

defined( 'ABSPATH' ) || exit;

$record     = $detail['record'];
$validation = $detail['validation'];
$state      = $detail['state'];
$notice     = isset( $_GET['notice'] ) && is_string( $_GET['notice'] )
	? sanitize_key( wp_unslash( $_GET['notice'] ) )
	: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bounded read-only notice routing.

$principal_user_id = $record->execution_principal_user_id();
$principal_user = $principal_user_id > 0 ? get_userdata( $principal_user_id ) : false;
$principal_label = $principal_user instanceof WP_User
	? sprintf( '%s (#%d)', $principal_user->display_name, $principal_user_id )
	: ( $principal_user_id > 0
		? sprintf( __( 'Unavailable user #%d', 'core-blueprint-automations' ), $principal_user_id )
		: __( 'No execution principal assigned', 'core-blueprint-automations' ) );

$notices = [
	'created'                                => [ 'success', __( 'Automation draft created. Configure the workflow below.', 'core-blueprint-automations' ) ],
	'created_template'                       => [ 'success', __( 'Automation created from a starter template. Review the workflow, assign execution authority and enable it when ready.', 'core-blueprint-automations' ) ],
	'saved'                                  => [ 'success', __( 'Automation saved.', 'core-blueprint-automations' ) ],
	'saved_disabled'                         => [ 'warning', __( 'Your changes were saved, but the automation remains disabled because the current workflow is not valid yet.', 'core-blueprint-automations' ) ],
	'saved_disabled_execution_unavailable'   => [ 'warning', __( 'Changes saved. The automation remains disabled because secure runtime encryption is unavailable on this site.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_missing'       => [ 'warning', __( 'Changes saved. Assign your account as execution authority before enabling this automation.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_invalid'       => [ 'warning', __( 'Changes saved. The stored execution authority is no longer a valid WordPress user.', 'core-blueprint-automations' ) ],
	'saved_disabled_principal_denied'        => [ 'warning', __( 'Changes saved. The execution principal no longer has all permissions required by this workflow.', 'core-blueprint-automations' ) ],
	'saved_disabled_operator_denied'         => [ 'warning', __( 'Changes saved. Your account does not have all permissions required to enable this workflow.', 'core-blueprint-automations' ) ],
	'saved_disabled_capability_unavailable'  => [ 'warning', __( 'Changes saved. Execution authority could not be verified against the current capability contracts.', 'core-blueprint-automations' ) ],
	'persistence_blocked'                    => [ 'error', __( 'The automation was not saved because a sensitive input contains a literal value. Connect sensitive inputs to a prior workflow output instead.', 'core-blueprint-automations' ) ],
	'precision_blocked'                      => [ 'error', __( 'The workflow contains a number that cannot be represented safely in the editor. No changes were saved.', 'core-blueprint-automations' ) ],
	'conflict'                               => [ 'warning', __( 'This automation changed in another request. Reload the page before saving again.', 'core-blueprint-automations' ) ],
	'invalid_name'                           => [ 'error', __( 'Enter an automation name between 1 and 191 characters.', 'core-blueprint-automations' ) ],
	'invalid'                                => [ 'error', __( 'The submitted workflow could not be decoded safely. No changes were saved.', 'core-blueprint-automations' ) ],
	'storage_failed'                         => [ 'error', __( 'The automation could not be saved because workflow storage is unavailable. No changes were saved.', 'core-blueprint-automations' ) ],
	'failed'                                 => [ 'error', __( 'The automation could not be saved. Reload the page before trying again.', 'core-blueprint-automations' ) ],
];

$editor_data['strings'] = [
	'choose_capability'  => __( 'Choose a capability…', 'core-blueprint-automations' ),
	'choose_source'      => __( 'Choose a source…', 'core-blueprint-automations' ),
	'unavailable'        => __( 'Unavailable', 'core-blueprint-automations' ),
	'provider'           => __( 'Provider', 'core-blueprint-automations' ),
	'inputs'             => __( 'Inputs', 'core-blueprint-automations' ),
	'no_inputs'          => __( 'No inputs required.', 'core-blueprint-automations' ),
	'add_state'          => __( 'Add data lookup', 'core-blueprint-automations' ),
	'add_condition'      => __( 'Add condition', 'core-blueprint-automations' ),
	'add_action'         => __( 'Add action', 'core-blueprint-automations' ),
	'remove'             => __( 'Remove', 'core-blueprint-automations' ),
	'move_up'            => __( 'Move up', 'core-blueprint-automations' ),
	'move_down'          => __( 'Move down', 'core-blueprint-automations' ),
	'literal'            => __( 'Literal value', 'core-blueprint-automations' ),
	'true'               => __( 'True', 'core-blueprint-automations' ),
	'false'              => __( 'False', 'core-blueprint-automations' ),
	'empty_array'        => __( 'Leave empty for an empty list; otherwise use one value per line.', 'core-blueprint-automations' ),
	'source_unavailable' => __( 'Stored source is unavailable', 'core-blueprint-automations' ),
	'field_required'     => __( 'Required', 'core-blueprint-automations' ),
	'field_optional'     => __( 'Optional', 'core-blueprint-automations' ),
	'invalid_number'     => __( 'Enter a number within the supported precision range.', 'core-blueprint-automations' ),
	'sensitive'          => __( 'Sensitive', 'core-blueprint-automations' ),
	'condition_left'     => __( 'Value', 'core-blueprint-automations' ),
	'condition_operator' => __( 'Operator', 'core-blueprint-automations' ),
	'condition_right'    => __( 'Compare with', 'core-blueprint-automations' ),
];

$operator_labels = [
	'equals'                => __( 'Equals', 'core-blueprint-automations' ),
	'not_equals'            => __( 'Does not equal', 'core-blueprint-automations' ),
	'contains'              => __( 'Contains', 'core-blueprint-automations' ),
	'not_contains'          => __( 'Does not contain', 'core-blueprint-automations' ),
	'greater_than'          => __( 'Greater than', 'core-blueprint-automations' ),
	'greater_than_or_equal' => __( 'Greater than or equal', 'core-blueprint-automations' ),
	'less_than'             => __( 'Less than', 'core-blueprint-automations' ),
	'less_than_or_equal'    => __( 'Less than or equal', 'core-blueprint-automations' ),
	'is_empty'              => __( 'Is empty', 'core-blueprint-automations' ),
	'is_not_empty'          => __( 'Is not empty', 'core-blueprint-automations' ),
];
$editor_data['operator_labels'] = $operator_labels;

$definition_json = wp_json_encode( $editor_data['workflow']['definition'] );
if ( ! is_string( $definition_json ) ) {
	$definition_json = '{}';
}
?>
<div class="wrap cb-automations-admin cb-automations-editor-page" data-cb-design-launch-root data-cb-design-launch-mode="direct" data-cb-design-title="<?php echo esc_attr__( 'Automation Builder', 'core-blueprint-automations' ); ?>" data-cb-design-exit-url="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url() ); ?>">
	<p><a href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url() ); ?>">← <?php esc_html_e( 'Back to Automations', 'core-blueprint-automations' ); ?></a></p>

	<div class="cb-automations-editor-heading" data-cb-design-launch-context>
		<div>
			<h1 class="cb-core-title"><?php echo esc_html( $record->name() ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Build a clear WHEN → GET DATA → ONLY IF → THEN workflow without leaving the automation context.', 'core-blueprint-automations' ); ?></p>
		</div>
		<div class="cb-automations-editor-statuses">
			<?php echo \CB\Automations\Admin\AutomationsPage::activation_status( $record->activation_state()->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
			<?php echo \CB\Automations\Admin\AutomationsPage::validation_status( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
		</div>
	</div>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<?php $template_scope = \CB\Automations\Template\TemplateLibraryBridge::scope_token(); ?>
	<section class="cb-core-panel cb-automations-template-tools" aria-label="<?php echo esc_attr__( 'Start from a template', 'core-blueprint-automations' ); ?>">
		<?php if ( null !== $template_scope ) : ?>
			<button type="button" class="button" data-cb-automations-browse-templates
				data-cb-template-scope="<?php echo esc_attr( $template_scope ); ?>"
				data-cb-template-nonce="<?php echo esc_attr( wp_create_nonce( 'cb_automations_create_from_template' ) ); ?>"
				data-cb-template-action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php esc_html_e( 'Browse templates', 'core-blueprint-automations' ); ?></button>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-automations-template-save-form">
			<input type="hidden" name="action" value="cb_automations_template_save">
			<input type="hidden" name="workflow_id" value="<?php echo esc_attr( (string) $record->id() ); ?>">
			<?php wp_nonce_field( \CB\Automations\Admin\TemplateManagementController::nonce( 'save', $record->id() ), '_cb_automations_nonce' ); ?>
			<label><?php esc_html_e( 'Automation name', 'core-blueprint-automations' ); ?>
				<input type="text" name="template_name" maxlength="191" value="<?php echo esc_attr( $record->name() ); ?>" required>
			</label>
			<label><?php esc_html_e( 'Templates', 'core-blueprint-automations' ); ?>
				<select name="template_scope">
					<option value="personal"><?php esc_html_e( 'Personal', 'core-blueprint-automations' ); ?></option>
					<option value="site"><?php esc_html_e( 'Site', 'core-blueprint-automations' ); ?></option>
				</select>
			</label>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save as template', 'core-blueprint-automations' ); ?></button>
		</form>
	</section>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-automations-editor-form>
		<input type="hidden" name="action" value="cb_automations_save_workflow" />
		<input type="hidden" name="workflow_id" value="<?php echo esc_attr( (string) $record->id() ); ?>" />
		<input type="hidden" name="revision" value="<?php echo esc_attr( (string) $record->revision() ); ?>" />
		<input type="hidden" name="definition_json" value="<?php echo esc_attr( $definition_json ); ?>" data-cb-automations-definition />
		<?php wp_nonce_field( 'cb_automations_save_workflow', '_cb_automations_nonce' ); ?>

		<div class="cb-core-design-shell cb-automations-design-shell" data-cb-design-shell data-cb-automations-designer-shell>
			<div class="cb-core-design-shell__toolbar">
				<label class="cb-core-field" data-cb-design-shell-context hidden aria-hidden="true">
					<span class="cb-core-field__label"><?php esc_html_e( 'Workflow', 'core-blueprint-automations' ); ?></span>
					<select data-cb-automations-workflow-switcher aria-label="<?php esc_attr_e( 'Workflow', 'core-blueprint-automations' ); ?>">
						<?php foreach ( $workflow_contexts as $workflow_context ) : ?>
							<option
								value="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $workflow_context['id'] ] ) ); ?>"
								<?php selected( $workflow_context['id'], $record->id() ); ?>
							><?php echo esc_html( $workflow_context['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="cb-core-design-shell__toolbar-group cb-automations-builder-history-controls">
					<span data-cb-design-shell-group-label><?php esc_html_e( 'History', 'core-blueprint-automations' ); ?></span>
					<button type="button" class="button cb-core-button" data-cb-design-shell-undo disabled><?php esc_html_e( 'Undo', 'core-blueprint-automations' ); ?></button>
					<button type="button" class="button cb-core-button" data-cb-design-shell-redo disabled><?php esc_html_e( 'Redo', 'core-blueprint-automations' ); ?></button>
				</div>
				<div class="cb-core-design-shell__toolbar-group">
					<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-design-shell-fullscreen data-cb-design-shell-fullscreen-enter-label="<?php esc_attr_e( 'Open focus mode', 'core-blueprint-automations' ); ?>" data-cb-design-shell-fullscreen-exit-label="<?php esc_attr_e( 'Exit focus mode', 'core-blueprint-automations' ); ?>" aria-pressed="false"><span data-cb-design-shell-fullscreen-label><?php esc_html_e( 'Open focus mode', 'core-blueprint-automations' ); ?></span></button>
					<button type="submit" class="button button-primary cb-core-button cb-core-button--primary" data-cb-design-shell-primary-action><?php esc_html_e( 'Save automation', 'core-blueprint-automations' ); ?></button>
				</div>
			</div>

			<div class="cb-core-design-shell__workspace">
				<aside class="cb-core-design-shell__palette cb-core-design-shell__palette--tabbed cb-core-design-shell__palette--composed cb-automations-stage-nav" aria-label="<?php esc_attr_e( 'Workflow stages', 'core-blueprint-automations' ); ?>">
					<div class="cb-core-design-shell__tabs cb-core-design-shell__palette-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Workflow', 'core-blueprint-automations' ); ?>">
						<button type="button" class="cb-core-design-shell__tab is-active" role="tab" aria-selected="true" data-cb-design-shell-tab="elements" data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="elements"><?php esc_html_e( 'Elements', 'core-blueprint' ); ?></button>
						<button type="button" class="cb-core-design-shell__tab" role="tab" aria-selected="false" data-cb-design-shell-tab="layers" data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="layers"><?php esc_html_e( 'Layers', 'core-blueprint' ); ?></button>
					</div>
					<section class="cb-core-design-shell__panel cb-core-design-shell__palette-panel" role="tabpanel" data-cb-design-shell-panel="elements" data-cb-design-shell-group="palette">
						<div data-cb-automations-elements-panel></div>
					</section>
					<section class="cb-core-design-shell__panel cb-core-design-shell__palette-panel" role="tabpanel" data-cb-design-shell-panel="layers" data-cb-design-shell-group="palette" hidden>
						<div data-cb-automations-layers-host></div>
					</section>
				</aside>

				<main class="cb-core-design-shell__canvas cb-core-design-shell__canvas--composed cb-automations-design-shell__canvas">
					<div class="cb-core-design-shell__canvas-workarea"><div class="cb-automations-linear-editor" data-cb-automations-editor>
						<section id="cb-automations-stage-trigger" class="cb-core-panel cb-automations-stage" data-stage="trigger" tabindex="-1">
							<div class="cb-automations-stage-heading"><div class="cb-automations-stage-heading__main"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'WHEN', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Trigger', 'core-blueprint-automations' ); ?></h2></div><span class="cb-automations-stage-requirement is-required"><?php esc_html_e( 'Required', 'core-blueprint-automations' ); ?></span></div>
							<p class="cb-automations-stage-description"><?php esc_html_e( 'Choose the event that starts this automation.', 'core-blueprint-automations' ); ?></p>
							<div data-cb-automations-trigger></div>
						</section>

						<section id="cb-automations-stage-states" class="cb-core-panel cb-automations-stage" data-stage="states" tabindex="-1">
							<div class="cb-automations-stage-heading"><div class="cb-automations-stage-heading__main"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'GET DATA', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Live state', 'core-blueprint-automations' ); ?></h2></div><span class="cb-automations-stage-requirement"><?php esc_html_e( 'Optional', 'core-blueprint-automations' ); ?></span></div>
							<p class="cb-automations-stage-description"><?php esc_html_e( 'Look up additional live data and make typed values available to later steps.', 'core-blueprint-automations' ); ?></p>
							<div data-cb-automations-states></div>
							<div class="cb-core-actions cb-automations-stage-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-add-state><?php esc_html_e( 'Add data lookup', 'core-blueprint-automations' ); ?></button></div>
						</section>

						<section id="cb-automations-stage-conditions" class="cb-core-panel cb-automations-stage" data-stage="conditions" tabindex="-1">
							<div class="cb-automations-stage-heading"><div class="cb-automations-stage-heading__main"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'ONLY IF', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Conditions', 'core-blueprint-automations' ); ?></h2></div><span class="cb-automations-stage-requirement"><?php esc_html_e( 'Optional', 'core-blueprint-automations' ); ?></span></div>
							<p class="cb-automations-stage-description"><?php esc_html_e( 'Add rules that must match before any actions are allowed to continue.', 'core-blueprint-automations' ); ?></p>
							<div data-cb-automations-conditions></div>
							<div class="cb-core-actions cb-automations-stage-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-add-condition><?php esc_html_e( 'Add condition', 'core-blueprint-automations' ); ?></button></div>
							<p class="cb-core-design-shell__panel-section-description" data-cb-automations-condition-hint hidden><?php esc_html_e( 'Conditions become available when earlier steps provide output data.', 'core-blueprint-automations' ); ?></p>
						</section>

						<section id="cb-automations-stage-actions" class="cb-core-panel cb-automations-stage" data-stage="actions" tabindex="-1">
							<div class="cb-automations-stage-heading"><div class="cb-automations-stage-heading__main"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'THEN', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Actions', 'core-blueprint-automations' ); ?></h2></div><span class="cb-automations-stage-requirement is-required"><?php esc_html_e( 'Required', 'core-blueprint-automations' ); ?></span></div>
							<p class="cb-automations-stage-description"><?php esc_html_e( 'Choose what Core Blueprint should do after the workflow passes its conditions.', 'core-blueprint-automations' ); ?></p>
							<div data-cb-automations-actions></div>
							<div class="cb-core-actions cb-automations-stage-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-add-action><?php esc_html_e( 'Add action', 'core-blueprint-automations' ); ?></button></div>
						</section>
					</div></div>
				</main>

				<aside class="cb-core-design-shell__sidebar cb-automations-design-shell__sidebar">
					<div class="cb-core-design-shell__tabs cb-core-design-shell__sidebar-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Automation details', 'core-blueprint-automations' ); ?>">
						<button type="button" class="cb-core-design-shell__tab cb-core-design-shell__sidebar-tab" role="tab" aria-selected="false" data-cb-design-shell-tab="inspector" data-cb-design-shell-sidebar-role="inspector"><?php esc_html_e( 'Inspector', 'core-blueprint-automations' ); ?></button>
						<button type="button" class="cb-core-design-shell__tab cb-core-design-shell__sidebar-tab is-active" role="tab" aria-selected="true" data-cb-design-shell-tab="settings" data-cb-design-shell-sidebar-role="settings"><?php esc_html_e( 'Settings', 'core-blueprint-automations' ); ?></button>
					</div>

					<section class="cb-core-design-shell__panel cb-core-design-shell__sidebar-panel" role="tabpanel" data-cb-design-shell-panel="inspector" data-cb-design-shell-sidebar-role="inspector" hidden>
						<div class="cb-core-design-shell__panel-body">
							<section class="cb-core-design-shell__panel-section">
								<p class="cb-core-design-shell__panel-section-description"><?php esc_html_e( 'Select a workflow step to inspect its context.', 'core-blueprint-automations' ); ?></p>
							</section>
						</div>
					</section>

					<section class="cb-core-design-shell__panel cb-core-design-shell__sidebar-panel" role="tabpanel" data-cb-design-shell-panel="settings" data-cb-design-shell-sidebar-role="settings">
						<div class="cb-core-design-shell__panel-body">
							<section class="cb-core-design-shell__panel-section">
								<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Workflow settings', 'core-blueprint-automations' ); ?></h3>
								<label class="cb-core-design-shell__field" for="cb-automation-name">
									<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Name', 'core-blueprint-automations' ); ?></span>
									<input id="cb-automation-name" name="name" type="text" maxlength="191" required value="<?php echo esc_attr( $record->name() ); ?>" />
								</label>
								<label class="cb-core-design-shell__field" for="cb-automation-activation">
									<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Activation', 'core-blueprint-automations' ); ?></span>
									<select id="cb-automation-activation" name="activation_state">
										<option value="disabled" <?php selected( 'disabled', $record->activation_state()->value ); ?>><?php esc_html_e( 'Disabled', 'core-blueprint-automations' ); ?></option>
										<option value="enabled" <?php selected( 'enabled', $record->activation_state()->value ); ?>><?php esc_html_e( 'Enabled', 'core-blueprint-automations' ); ?></option>
									</select>
									<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Enabled workflows must be valid and both the execution principal and current operator must hold every provider-required capability.', 'core-blueprint-automations' ); ?></span>
								</label>
							</section>

							<section class="cb-core-design-shell__panel-section">
								<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Execution authority', 'core-blueprint-automations' ); ?></h3>
								<p class="cb-core-design-shell__panel-section-description" data-cb-automations-principal-label><?php echo esc_html( $principal_label ); ?></p>
								<label><input type="checkbox" name="rebind_execution_principal" value="1" data-cb-automations-rebind-principal /> <?php esc_html_e( 'Use my account as execution principal when saving', 'core-blueprint-automations' ); ?></label>
								<p class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Core Blueprint never accepts an arbitrary Run as user ID from the browser. The server binds your current WordPress account and re-checks its permissions on every execution.', 'core-blueprint-automations' ); ?></p>
							</section>
							<section class="cb-core-design-shell__panel-section" data-cb-automations-save-recovery hidden aria-label="<?php esc_attr_e( 'Review latest version in a new tab', 'core-blueprint-automations' ); ?>"></section>
						<section class="cb-core-design-shell__panel-section" data-cb-automations-history-warning hidden role="alert"></section>
						<section class="cb-core-design-shell__panel-section cb-automations-validation-panel" aria-label="<?php esc_attr_e( 'Workflow health', 'core-blueprint-automations' ); ?>">
							<section class="cb-core-design-shell__panel-section">
								<h3 class="cb-core-design-shell__panel-section-title"><?php echo esc_html( $validation->is_valid() ? __( 'Ready', 'core-blueprint-automations' ) : __( 'Needs attention', 'core-blueprint-automations' ) ); ?></h3>
								<?php if ( $validation->is_valid() ) : ?>
									<p class="cb-core-design-shell__panel-section-description"><?php esc_html_e( 'The current definition is valid against the live capability catalog.', 'core-blueprint-automations' ); ?></p>
								<?php else : ?>
									<p class="cb-core-design-shell__panel-section-description"><?php esc_html_e( 'Resolve these items before the automation can be enabled.', 'core-blueprint-automations' ); ?></p>
								<?php endif; ?>
							</section>

							<?php if ( ! $validation->is_valid() ) : ?>
								<section class="cb-core-design-shell__panel-section">
									<ul class="cb-automations-validation-list">
										<?php foreach ( $validation->issues() as $issue ) : ?>
											<li><strong><?php echo esc_html( \CB\Automations\Admin\ValidationPresenter::message( $issue ) ); ?></strong><code><?php echo esc_html( $issue->path() ); ?></code></li>
										<?php endforeach; ?>
									</ul>
								</section>
							<?php endif; ?>
							</section>
						</div>
					</section>


				</aside>
			</div>
		</div>

		<p class="submit cb-core-actions cb-automations-save-actions"><button type="submit" class="button button-primary cb-core-button cb-core-button--primary" data-cb-automations-save-alias><?php esc_html_e( 'Save automation', 'core-blueprint-automations' ); ?></button></p>
	</form>

	<noscript><div class="notice notice-warning"><p><?php esc_html_e( 'The visual workflow editor requires JavaScript. Name, activation and execution-authority intent can still be submitted using the currently stored definition.', 'core-blueprint-automations' ); ?></p></div></noscript>

	<script type="application/json" id="cb-automations-editor-data"><?php echo wp_json_encode( $editor_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with all HTML-sensitive characters hex-escaped. ?></script>
</div>
