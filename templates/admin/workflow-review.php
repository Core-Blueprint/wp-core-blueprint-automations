<?php
/**
 * Read-only workflow recovery view for unavailable/incompatible capability contracts.
 *
 * @var array{record:\CB\Automations\Persistence\WorkflowRecord,validation:\CB\Automations\Validation\ValidationResult,state:\CB\Automations\Validation\ValidationState} $detail
 */

defined( 'ABSPATH' ) || exit;

$record     = $detail['record'];
$validation = $detail['validation'];
$state      = $detail['state'];
?>
<div class="wrap cb-core-wrap cb-automations-admin cb-automations-review-page">
	<p><a href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url() ); ?>">← <?php esc_html_e( 'Back to Automations', 'core-blueprint-automations' ); ?></a></p>

	<div class="cb-automations-editor-heading">
		<div>
			<h1 class="cb-core-title"><?php echo esc_html( $record->name() ); ?></h1>
			<p class="cb-core-intro"><?php esc_html_e( 'Editing is paused because one or more stored capability contracts cannot be verified safely.', 'core-blueprint-automations' ); ?></p>
		</div>
		<div class="cb-automations-editor-statuses">
			<?php echo \CB\Automations\Admin\AutomationsPage::activation_status( $record->activation_state()->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
			<?php echo \CB\Automations\Admin\AutomationsPage::validation_status( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
		</div>
	</div>

	<div class="notice notice-warning"><p><?php esc_html_e( 'The stored workflow definition has been preserved unchanged. Restore or update the relevant provider first; Automations will re-evaluate the workflow automatically on the next page load.', 'core-blueprint-automations' ); ?></p></div>

	<section class="cb-core-panel cb-automations-validation-panel" aria-labelledby="cb-automations-review-title">
		<h2 id="cb-automations-review-title"><?php esc_html_e( 'Needs review', 'core-blueprint-automations' ); ?></h2>
		<ul class="cb-automations-validation-list">
			<?php foreach ( $validation->issues() as $issue ) : ?>
				<li>
					<strong><?php echo esc_html( \CB\Automations\Admin\ValidationPresenter::message( $issue ) ); ?></strong>
					<code><?php echo esc_html( $issue->path() ); ?></code>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
</div>
