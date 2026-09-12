<?php
/** @var array{run:array<string,mixed>,steps:array<int,array<string,mixed>>,recoveries:array<int,array<string,mixed>>} $detail */
defined( 'ABSPATH' ) || exit;
$run = $detail['run'];
$steps = $detail['steps'];
$recoveries = $detail['recoveries'];
$format_time = static function ( ?string $value ): string {
	return null === $value ? '—' : get_date_from_gmt( $value, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
};
$recovery_notice = isset( $_GET['recovery_notice'] ) && is_scalar( $_GET['recovery_notice'] )
	? sanitize_key( wp_unslash( (string) $_GET['recovery_notice'] ) )
	: ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect notice.
$notice_message = match ( $recovery_notice ) {
	'confirmed_succeeded' => __( 'Recovery recorded: the Action was confirmed successful.', 'core-blueprint-automations' ),
	'confirmed_did_not_occur' => __( 'Recovery recorded: the Action was confirmed not to have occurred and was safely queued as a new attempt.', 'core-blueprint-automations' ),
	'abandon_unresolved' => __( 'Recovery recorded: the unresolved run was cancelled without replaying the Action.', 'core-blueprint-automations' ),
	'recovery_output_required_downstream' => __( 'This run cannot continue after a confirmed success because a later Action requires output that was not durably recorded. The run remains unresolved.', 'core-blueprint-automations' ),
	'recovery_job_active' => __( 'Recovery is temporarily unavailable because the run worker still owns the durable job. Reload the run after the worker settles.', 'core-blueprint-automations' ),
	'recovery_already_resolved', 'recovery_run_conflict', 'recovery_attempt_conflict' => __( 'This recovery request is stale because the run was already resolved or changed. Reload the run history.', 'core-blueprint-automations' ),
	'storage_failed' => __( 'Recovery could not be recorded because storage is unavailable. No recovery decision was committed.', 'core-blueprint-automations' ),
	'invalid' => __( 'The recovery request was incomplete. No changes were made.', 'core-blueprint-automations' ),
	'failed' => __( 'The recovery request could not be completed. Reload the run before trying again.', 'core-blueprint-automations' ),
	default => '',
};
$indeterminate_action = null;
if ( \CB\Automations\Runtime\RunStatus::Indeterminate === $run['status'] ) {
	for ( $index = count( $steps ) - 1; $index >= 0; $index-- ) {
		$step = $steps[ $index ];
		if (
			\CB\Automations\Runtime\NodeType::Action === $step['node_type']
			&& \CB\Automations\Runtime\StepStatus::Indeterminate === $step['status']
		) {
			$indeterminate_action = $step;
			break;
		}
	}
}
$can_author = current_user_can( \CB\Automations\Admin\AutomationsPage::CAPABILITY );
$can_recover = current_user_can( \CB\Automations\Admin\RecoveryCapability::CAPABILITY );
$decision_label = static function ( \CB\Automations\Runtime\OperatorRecoveryDecision $decision ): string {
	return match ( $decision ) {
		\CB\Automations\Runtime\OperatorRecoveryDecision::ConfirmedSucceeded => __( 'Confirmed succeeded', 'core-blueprint-automations' ),
		\CB\Automations\Runtime\OperatorRecoveryDecision::ConfirmedDidNotOccur => __( 'Confirmed did not occur', 'core-blueprint-automations' ),
		\CB\Automations\Runtime\OperatorRecoveryDecision::AbandonUnresolved => __( 'Abandoned unresolved', 'core-blueprint-automations' ),
	};
};
?>
<div class="wrap cb-core-wrap cb-automations-admin">
	<h1 class="cb-core-title"><?php echo esc_html( sprintf( __( 'Automation run #%d', 'core-blueprint-automations' ), $run['id'] ) ); ?></h1>
	<p><a class="button" href="<?php echo esc_url( \CB\Automations\Admin\AutomationRunsPage::url() ); ?>"><?php esc_html_e( 'Back to Runs', 'core-blueprint-automations' ); ?></a><?php if ( $can_author ) : ?> <a class="button" href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $run['workflow_id'] ] ) ); ?>"><?php esc_html_e( 'Open workflow', 'core-blueprint-automations' ); ?></a><?php endif; ?></p>

	<?php if ( '' !== $notice_message ) : ?>
		<div class="notice notice-info"><p><?php echo esc_html( $notice_message ); ?></p></div>
	<?php endif; ?>

	<?php if ( \CB\Automations\Runtime\RunStatus::Indeterminate === $run['status'] ) : ?>
		<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Action outcome unknown.', 'core-blueprint-automations' ); ?></strong> <?php esc_html_e( 'Execution stopped after a mutating action began but before Core Blueprint could safely record its outcome. The action was not retried automatically.', 'core-blueprint-automations' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped">
		<tbody>
			<tr><th><?php esc_html_e( 'Status', 'core-blueprint-automations' ); ?></th><td><?php echo wp_kses_post( \CB\Automations\Admin\AutomationsPage::run_status( $run['status'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Workflow snapshot', 'core-blueprint-automations' ); ?></th><td>#<?php echo esc_html( (string) $run['workflow_id'] ); ?> · revision <?php echo esc_html( (string) $run['workflow_revision'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Execution principal', 'core-blueprint-automations' ); ?></th><td>#<?php echo esc_html( (string) $run['execution_principal_user_id'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Run UUID', 'core-blueprint-automations' ); ?></th><td><code><?php echo esc_html( $run['run_uuid'] ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Correlation ID', 'core-blueprint-automations' ); ?></th><td><code><?php echo esc_html( $run['correlation_id'] ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Created', 'core-blueprint-automations' ); ?></th><td><?php echo esc_html( $format_time( $run['created_at'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Started', 'core-blueprint-automations' ); ?></th><td><?php echo esc_html( $format_time( $run['started_at'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Finished', 'core-blueprint-automations' ); ?></th><td><?php echo esc_html( $format_time( $run['finished_at'] ) ); ?></td></tr>
			<?php if ( '' !== $run['failure_code'] ) : ?><tr><th><?php esc_html_e( 'Machine code', 'core-blueprint-automations' ); ?></th><td><code><?php echo esc_html( $run['failure_code'] ); ?></code></td></tr><?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Execution timeline', 'core-blueprint-automations' ); ?></h2>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Step', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Capability', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Attempt', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Status', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Started', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Finished', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Code', 'core-blueprint-automations' ); ?></th></tr></thead>
		<tbody>
		<?php if ( [] === $steps ) : ?><tr><td colspan="7"><?php esc_html_e( 'No execution steps were recorded.', 'core-blueprint-automations' ); ?></td></tr><?php else : foreach ( $steps as $step ) : ?>
			<tr>
				<td><?php echo esc_html( ucfirst( $step['node_type']->value ) . ' · ' . $step['node_id'] ); ?></td>
				<td><?php echo '' === $step['provider'] ? '—' : '<code>' . esc_html( $step['provider'] . ' / ' . $step['capability_id'] . ' @' . $step['schema_version'] ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- code contents escaped above. ?></td>
				<td><?php echo esc_html( (string) $step['attempt'] ); ?></td>
				<td><?php echo wp_kses_post( \CB\Automations\Admin\AutomationsPage::step_status( $step['status'] ) ); ?></td>
				<td><?php echo esc_html( $format_time( $step['started_at'] ) ); ?></td>
				<td><?php echo esc_html( $format_time( $step['finished_at'] ) ); ?></td>
				<td><?php if ( '' !== $step['error_code'] ) : ?><code><?php echo esc_html( $step['error_code'] ); ?></code><?php endif; ?><?php if ( '' !== $step['provider_error_code'] ) : ?> <code><?php echo esc_html( $step['provider_error_code'] ); ?></code><?php endif; ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<?php if ( [] !== $recoveries ) : ?>
		<h2><?php esc_html_e( 'Recovery history', 'core-blueprint-automations' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Action', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Attempt', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Decision', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Operator', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Result', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Recorded', 'core-blueprint-automations' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $recoveries as $recovery ) : ?>
				<tr>
					<td><code><?php echo esc_html( $recovery['node_id'] ); ?></code></td>
					<td><?php echo esc_html( (string) $recovery['attempt'] ); ?></td>
					<td><?php echo esc_html( $decision_label( $recovery['decision'] ) ); ?></td>
					<td>#<?php echo esc_html( (string) $recovery['operator_user_id'] ); ?></td>
					<td><?php echo wp_kses_post( \CB\Automations\Admin\AutomationsPage::run_status( $recovery['resulting_status'] ) ); ?></td>
					<td><?php echo esc_html( $format_time( $recovery['created_at'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( \CB\Automations\Runtime\RunStatus::Indeterminate === $run['status'] ) : ?>
		<h2><?php esc_html_e( 'Operator recovery', 'core-blueprint-automations' ); ?></h2>
		<?php if ( ! $can_recover ) : ?>
			<p><?php esc_html_e( 'Your account may review this run but does not have recovery authority.', 'core-blueprint-automations' ); ?></p>
		<?php elseif ( null === $indeterminate_action ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The unresolved Action attempt could not be identified from safe run metadata. Recovery is unavailable.', 'core-blueprint-automations' ); ?></p></div>
		<?php else : ?>
			<p><?php esc_html_e( 'Choose a resolution only after independently verifying what happened. Core Blueprint will never replay an unknown mutation automatically.', 'core-blueprint-automations' ); ?></p>
			<?php
			$recovery_form = static function ( \CB\Automations\Runtime\OperatorRecoveryDecision $decision, string $button, string $confirmation, string $class = 'button' ) use ( $run, $indeterminate_action ): void {
				?>
				<form class="cb-automations-recovery-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_automations_recover_run">
					<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run['id'] ); ?>">
					<input type="hidden" name="node_id" value="<?php echo esc_attr( $indeterminate_action['node_id'] ); ?>">
					<input type="hidden" name="attempt" value="<?php echo esc_attr( (string) $indeterminate_action['attempt'] ); ?>">
					<input type="hidden" name="decision" value="<?php echo esc_attr( $decision->value ); ?>">
					<?php wp_nonce_field( 'cb_automations_recover_run_' . $run['id'], '_cb_automations_nonce' ); ?>
					<label><input type="checkbox" name="recovery_confirm" value="1" required> <?php echo esc_html( $confirmation ); ?></label><br>
					<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $button ); ?></button>
				</form>
				<?php
			};
			$recovery_form(
				\CB\Automations\Runtime\OperatorRecoveryDecision::ConfirmedSucceeded,
				__( 'Confirm succeeded', 'core-blueprint-automations' ),
				__( 'I verified that this mutation completed successfully. Do not execute it again.', 'core-blueprint-automations' )
			);
			$recovery_form(
				\CB\Automations\Runtime\OperatorRecoveryDecision::ConfirmedDidNotOccur,
				__( 'Confirm did not occur', 'core-blueprint-automations' ),
				__( 'I verified that this mutation did not occur. A new Action attempt may be executed.', 'core-blueprint-automations' )
			);
			$recovery_form(
				\CB\Automations\Runtime\OperatorRecoveryDecision::AbandonUnresolved,
				__( 'Abandon unresolved run', 'core-blueprint-automations' ),
				__( 'I cannot safely determine the mutation outcome and want to cancel this run without replaying it.', 'core-blueprint-automations' ),
				'button button-secondary'
			);
			?>
		<?php endif; ?>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'Runtime values are intentionally excluded from run history.', 'core-blueprint-automations' ); ?></p>
</div>
