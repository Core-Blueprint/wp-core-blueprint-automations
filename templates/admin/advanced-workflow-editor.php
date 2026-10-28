<?php
/**
 * Advanced v2 disabled-draft authoring in the native WordPress Automations workspace.
 *
 * @var array{record:\CB\Automations\Persistence\WorkflowRecord,validation:\CB\Automations\Validation\ValidationResult,state:\CB\Automations\Validation\ValidationState} $detail
 * @var array<string,mixed> $editor_data
 */
defined( 'ABSPATH' ) || exit;

$record = $detail['record'];
$validation = $detail['validation'];
$notice = isset( $_GET['notice'] ) && is_scalar( $_GET['notice'] )
	? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
$messages = [
	'created' => __( 'Automation draft created. Configure the workflow below.', 'core-blueprint-automations' ),
	'saved' => __( 'Automation saved.', 'core-blueprint-automations' ),
	'conflict' => __( 'This automation changed in another request. Reload the page before saving again.', 'core-blueprint-automations' ),
	'invalid' => __( 'The submitted workflow could not be decoded safely. No changes were saved.', 'core-blueprint-automations' ),
	'failed' => __( 'The automation could not be saved. Reload the page before trying again.', 'core-blueprint-automations' ),
	'storage_failed' => __( 'The automation could not be saved because workflow storage is unavailable.', 'core-blueprint-automations' ),
	'persistence_blocked' => __( 'The automation was not saved because a sensitive input contains a literal value.', 'core-blueprint-automations' ),
	'precision_blocked' => __( 'The workflow contains a number that cannot be represented safely in the editor. No changes were saved.', 'core-blueprint-automations' ),
];
$payload = wp_json_encode( $editor_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
if ( ! is_string( $payload ) ) {
	throw new \UnexpectedValueException( 'Advanced editor data could not be safely encoded.' );
}
?>
<div class="wrap cb-automations-admin cb-advanced-editor-page" data-cb-design-launch-root data-cb-design-title="<?php echo esc_attr__( 'Automation Builder', 'core-blueprint-automations' ); ?>">
	<p><a href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url() ); ?>">&larr; <?php esc_html_e( 'Back to Automations', 'core-blueprint-automations' ); ?></a></p>
	<header class="cb-advanced-editor-header" data-cb-design-launch-context>
		<div>
			<span class="cb-advanced-editor-eyebrow">Advanced</span>
			<h1 class="cb-core-title"><?php echo esc_html( $record->name() ); ?></h1>
			<p class="description"><?php esc_html_e( 'Create a disabled draft, then configure it securely in the Builder.', 'core-blueprint-automations' ); ?></p>
		</div>
		<div class="cb-advanced-editor-status">
			<?php echo \CB\Automations\Admin\AutomationsPage::activation_status( $record->activation_state()->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status markup. ?>
			<?php echo \CB\Automations\Admin\AutomationsPage::validation_status( $detail['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status markup. ?>
		</div>
	</header>
	<?php if ( isset( $messages[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'saved' === $notice || 'created' === $notice ? 'success' : 'warning' ); ?>"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
	<?php endif; ?>
	<div class="notice notice-info inline"><p>Advanced draft editing is available. Workflow execution and activation remain disabled until the controlled test mode is approved.</p></div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-advanced-form>
		<input type="hidden" name="action" value="cb_automations_save_workflow" />
		<input type="hidden" name="workflow_id" value="<?php echo esc_attr( (string) $record->id() ); ?>" />
		<input type="hidden" name="revision" value="<?php echo esc_attr( (string) $record->revision() ); ?>" />
		<input type="hidden" name="activation_state" value="disabled" />
		<input type="hidden" name="definition_json" value="" data-cb-advanced-definition />
		<?php wp_nonce_field( 'cb_automations_save_workflow', '_cb_automations_nonce' ); ?>
		<div class="cb-core-design-shell cb-advanced-editor-shell" data-cb-design-shell data-cb-advanced-root hidden>
			<div class="cb-core-design-shell__toolbar cb-advanced-editor-toolbar">
			<button type="button" class="button cb-core-button" data-cb-design-shell-fullscreen data-cb-design-shell-fullscreen-enter-label="<?php esc_attr_e( 'Open focus mode', 'core-blueprint-automations' ); ?>" data-cb-design-shell-fullscreen-exit-label="<?php esc_attr_e( 'Exit focus mode', 'core-blueprint-automations' ); ?>" aria-pressed="false"><span data-cb-design-shell-fullscreen-label><?php esc_html_e( 'Open focus mode', 'core-blueprint-automations' ); ?></span></button>
				<label class="cb-advanced-editor-name"><?php esc_html_e( 'Automation name', 'core-blueprint-automations' ); ?>
					<input type="text" name="name" value="<?php echo esc_attr( $record->name() ); ?>" maxlength="191" required />
				</label>
				<div class="cb-advanced-editor-toolbar-actions">
					<span data-cb-advanced-dirty aria-live="polite"></span>
					<button type="submit" class="button button-primary cb-core-button cb-core-button--primary" data-cb-design-shell-primary-action data-cb-advanced-save><?php esc_html_e( 'Save automation', 'core-blueprint-automations' ); ?></button>
				</div>
			</div>
			<div class="cb-core-design-shell__workspace cb-core-design-shell__workspace--collapsible cb-advanced-editor-workspace">
				<aside class="cb-core-design-shell__palette cb-core-design-shell__palette--composed cb-advanced-editor-palette" aria-label="Advanced nodes">
					<h2>Nodes</h2>
					<p class="description">Select a node type to place it on the canvas.</p>
					<button type="button" class="button" data-cb-advanced-add="trigger"><?php esc_html_e( 'Trigger', 'core-blueprint-automations' ); ?></button>
					<button type="button" class="button" data-cb-advanced-add="state"><?php esc_html_e( 'Add data lookup', 'core-blueprint-automations' ); ?></button>
					<button type="button" class="button" data-cb-advanced-add="if_else">IF / ELSE</button>
					<button type="button" class="button" data-cb-advanced-add="action"><?php esc_html_e( 'Add action', 'core-blueprint-automations' ); ?></button>
					<button type="button" class="button" data-cb-advanced-add="exclusive_merge">Merge</button>
					<button type="button" class="button" data-cb-advanced-add="end">End</button>
					<p class="description">Drag nodes to arrange them. Select a node to edit its capability or connect its output to another node.</p>
				</aside>
				<main class="cb-core-design-shell__canvas cb-advanced-editor-canvas" aria-label="Advanced workflow canvas">
					<div class="cb-advanced-editor-canvas-scroll" tabindex="0">
						<div class="cb-advanced-editor-scene" data-cb-advanced-scene>
							<svg aria-hidden="true" data-cb-advanced-wires viewBox="0 0 1400 900" preserveAspectRatio="xMinYMin meet"></svg>
							<div data-cb-advanced-nodes></div>
						</div>
					</div>
				</main>
				<aside class="cb-core-design-shell__sidebar cb-core-design-shell__sidebar--composed cb-advanced-editor-inspector" aria-label="Node Inspector">
					<h2><?php esc_html_e( 'Inspector', 'core-blueprint-automations' ); ?></h2>
					<div data-cb-advanced-inspector><p class="description"><?php esc_html_e( 'Select a workflow step to inspect its context.', 'core-blueprint-automations' ); ?></p></div>
					<section class="cb-advanced-editor-validation" aria-live="polite">
						<h2><?php esc_html_e( 'Workflow health', 'core-blueprint-automations' ); ?></h2>
						<p class="description">Checks refresh after saving and reopening the draft. Activation remains disabled.</p>
						<?php $issues = array_filter( $validation->issues(), static fn( $issue ): bool => 'graph.execution_unavailable' !== $issue->code() ); ?>
						<?php if ( [] === $issues ) : ?>
							<p><?php esc_html_e( 'Valid', 'core-blueprint-automations' ); ?></p>
						<?php else : ?>
							<ul>
								<?php foreach ( $issues as $issue ) : ?>
									<li><?php echo esc_html( \CB\Automations\Admin\ValidationPresenter::message( $issue ) ); ?> <code><?php echo esc_html( $issue->code() . ' · ' . $issue->path() ); ?></code></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				</aside>
			</div>
		</div>
	</form>

	<script id="cb-advanced-editor-data" type="application/json"><?php echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with all JSON_HEX flags. ?></script>
</div>