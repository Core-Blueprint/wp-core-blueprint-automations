<?php
/**
 * Linear workflow editor.
 *
 * @var array{record:\CB\Automations\Persistence\WorkflowRecord,validation:\CB\Automations\Validation\ValidationResult,state:\CB\Automations\Validation\ValidationState} $detail
 * @var array<string,mixed> $editor_data
 */

defined( 'ABSPATH' ) || exit;

$record     = $detail['record'];
$validation = $detail['validation'];
$state      = $detail['state'];
$notice     = isset( $_GET['notice'] ) && is_string( $_GET['notice'] )
	? sanitize_key( wp_unslash( $_GET['notice'] ) )
	: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bounded read-only notice routing.

$notices = [
	'created'             => [ 'success', __( 'Automation draft created. Configure the workflow below.', 'core-blueprint-automations' ) ],
	'saved'               => [ 'success', __( 'Automation saved.', 'core-blueprint-automations' ) ],
	'saved_disabled'      => [ 'warning', __( 'Your changes were saved, but the automation remains disabled because the current workflow is not valid yet.', 'core-blueprint-automations' ) ],
	'persistence_blocked' => [ 'error', __( 'The automation was not saved because a sensitive input contains a literal value. Connect sensitive inputs to a prior workflow output instead.', 'core-blueprint-automations' ) ],
	'conflict'            => [ 'warning', __( 'This automation changed in another request. Reload the page before saving again.', 'core-blueprint-automations' ) ],
	'invalid_name'        => [ 'error', __( 'Enter an automation name between 1 and 191 characters.', 'core-blueprint-automations' ) ],
	'invalid'             => [ 'error', __( 'The submitted workflow could not be decoded safely. No changes were saved.', 'core-blueprint-automations' ) ],
	'storage_failed'      => [ 'error', __( 'The automation could not be saved because workflow storage is unavailable. No changes were saved.', 'core-blueprint-automations' ) ],
	'failed'              => [ 'error', __( 'The automation could not be saved. Reload the page before trying again.', 'core-blueprint-automations' ) ],
];

$editor_data['strings'] = [
	'choose_capability' => __( 'Choose a capability…', 'core-blueprint-automations' ),
	'choose_source'     => __( 'Choose a source…', 'core-blueprint-automations' ),
	'unavailable'       => __( 'Unavailable', 'core-blueprint-automations' ),
	'provider'          => __( 'Provider', 'core-blueprint-automations' ),
	'inputs'            => __( 'Inputs', 'core-blueprint-automations' ),
	'no_inputs'         => __( 'No inputs required.', 'core-blueprint-automations' ),
	'add_state'         => __( 'Add data lookup', 'core-blueprint-automations' ),
	'add_condition'     => __( 'Add condition', 'core-blueprint-automations' ),
	'add_action'        => __( 'Add action', 'core-blueprint-automations' ),
	'remove'            => __( 'Remove', 'core-blueprint-automations' ),
	'move_up'           => __( 'Move up', 'core-blueprint-automations' ),
	'move_down'         => __( 'Move down', 'core-blueprint-automations' ),
	'literal'           => __( 'Literal value', 'core-blueprint-automations' ),
	'true'              => __( 'True', 'core-blueprint-automations' ),
	'false'             => __( 'False', 'core-blueprint-automations' ),
	'empty_array'       => __( 'Leave empty for an empty list; otherwise use one value per line.', 'core-blueprint-automations' ),
	'source_unavailable'=> __( 'Stored source is unavailable', 'core-blueprint-automations' ),
	'field_required'    => __( 'Required', 'core-blueprint-automations' ),
	'field_optional'    => __( 'Optional', 'core-blueprint-automations' ),
	'sensitive'         => __( 'Sensitive', 'core-blueprint-automations' ),
	'condition_left'    => __( 'Value', 'core-blueprint-automations' ),
	'condition_operator'=> __( 'Operator', 'core-blueprint-automations' ),
	'condition_right'   => __( 'Compare with', 'core-blueprint-automations' ),
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
<div class="wrap cb-core-wrap cb-automations-admin cb-automations-editor-page">
	<p><a href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url() ); ?>">← <?php esc_html_e( 'Back to Automations', 'core-blueprint-automations' ); ?></a></p>

	<div class="cb-automations-editor-heading">
		<div>
			<h1 class="cb-core-title"><?php echo esc_html( $record->name() ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Build a linear workflow. Definitions can be saved while disabled; enabling always runs the live validator first.', 'core-blueprint-automations' ); ?></p>
		</div>
		<div class="cb-automations-editor-statuses">
			<?php echo \CB\Automations\Admin\AutomationsPage::activation_status( $record->activation_state()->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
			<?php echo \CB\Automations\Admin\AutomationsPage::validation_status( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
		</div>
	</div>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $validation->is_valid() ) : ?>
		<section class="cb-core-panel cb-automations-validation-panel" aria-labelledby="cb-automations-validation-title">
			<h2 id="cb-automations-validation-title"><?php esc_html_e( 'Needs attention', 'core-blueprint-automations' ); ?></h2>
			<ul class="cb-automations-validation-list">
				<?php foreach ( $validation->issues() as $issue ) : ?>
					<li>
						<strong><?php echo esc_html( \CB\Automations\Admin\ValidationPresenter::message( $issue ) ); ?></strong>
						<code><?php echo esc_html( $issue->path() ); ?></code>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-automations-editor-form>
		<input type="hidden" name="action" value="cb_automations_save_workflow" />
		<input type="hidden" name="workflow_id" value="<?php echo esc_attr( (string) $record->id() ); ?>" />
		<input type="hidden" name="revision" value="<?php echo esc_attr( (string) $record->revision() ); ?>" />
		<input type="hidden" name="definition_json" value="<?php echo esc_attr( $definition_json ); ?>" data-cb-automations-definition />
		<?php wp_nonce_field( 'cb_automations_save_workflow', '_cb_automations_nonce' ); ?>

		<section class="cb-core-panel cb-automations-settings-panel">
			<div class="cb-automations-settings-grid">
				<div>
					<label for="cb-automation-name"><strong><?php esc_html_e( 'Name', 'core-blueprint-automations' ); ?></strong></label>
					<input id="cb-automation-name" name="name" type="text" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $record->name() ); ?>" />
				</div>
				<div>
					<label for="cb-automation-activation"><strong><?php esc_html_e( 'Activation', 'core-blueprint-automations' ); ?></strong></label>
					<select id="cb-automation-activation" name="activation_state">
						<option value="disabled" <?php selected( 'disabled', $record->activation_state()->value ); ?>><?php esc_html_e( 'Disabled', 'core-blueprint-automations' ); ?></option>
						<option value="enabled" <?php selected( 'enabled', $record->activation_state()->value ); ?>><?php esc_html_e( 'Enabled', 'core-blueprint-automations' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Enabled workflows must be fully valid against the current capability catalog.', 'core-blueprint-automations' ); ?></p>
				</div>
			</div>
		</section>

		<div class="cb-automations-linear-editor" data-cb-automations-editor>
			<section class="cb-core-panel cb-automations-stage" data-stage="trigger">
				<div class="cb-automations-stage-heading"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'WHEN', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Trigger', 'core-blueprint-automations' ); ?></h2></div>
				<div data-cb-automations-trigger></div>
			</section>

			<section class="cb-core-panel cb-automations-stage" data-stage="states">
				<div class="cb-automations-stage-heading"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'GET DATA', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Live state', 'core-blueprint-automations' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Optional read-only lookups that make additional typed values available to conditions and actions.', 'core-blueprint-automations' ); ?></p>
				<div data-cb-automations-states></div>
				<p class="cb-core-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-add-state><?php esc_html_e( 'Add data lookup', 'core-blueprint-automations' ); ?></button></p>
			</section>

			<section class="cb-core-panel cb-automations-stage" data-stage="conditions">
				<div class="cb-automations-stage-heading"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'ONLY IF', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Conditions', 'core-blueprint-automations' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Optional gates evaluated after the trigger and live state lookups.', 'core-blueprint-automations' ); ?></p>
				<div data-cb-automations-conditions></div>
				<p class="cb-core-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-add-condition><?php esc_html_e( 'Add condition', 'core-blueprint-automations' ); ?></button></p>
			</section>

			<section class="cb-core-panel cb-automations-stage" data-stage="actions">
				<div class="cb-automations-stage-heading"><span class="cb-automations-stage-kicker"><?php esc_html_e( 'THEN', 'core-blueprint-automations' ); ?></span><h2><?php esc_html_e( 'Actions', 'core-blueprint-automations' ); ?></h2></div>
				<div data-cb-automations-actions></div>
				<p class="cb-core-actions"><button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-add-action><?php esc_html_e( 'Add action', 'core-blueprint-automations' ); ?></button></p>
			</section>
		</div>

		<p class="submit cb-core-actions">
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save automation', 'core-blueprint-automations' ); ?></button>
		</p>
	</form>

	<noscript><div class="notice notice-warning"><p><?php esc_html_e( 'The visual workflow editor requires JavaScript. Name and activation can still be submitted using the currently stored definition.', 'core-blueprint-automations' ); ?></p></div></noscript>

	<script type="application/json" id="cb-automations-editor-data"><?php echo wp_json_encode( $editor_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with all HTML-sensitive characters hex-escaped. ?></script>
</div>
