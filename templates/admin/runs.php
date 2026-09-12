<?php
/** @var array{items:array,total:int,page:int,per_page:int,pages:int,workflow_id:int} $listing */
defined( 'ABSPATH' ) || exit;
$format_time = static function ( ?string $value ): string {
	return null === $value ? '—' : get_date_from_gmt( $value, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
};
$can_author = current_user_can( \CB\Automations\Admin\AutomationsPage::CAPABILITY );
?>
<div class="wrap cb-core-wrap cb-automations-admin">
	<h1 class="cb-core-title"><?php esc_html_e( 'Automation runs', 'core-blueprint-automations' ); ?></h1>
	<p class="cb-core-intro"><?php esc_html_e( 'Operational history shows execution metadata only. Runtime inputs and outputs are never displayed here.', 'core-blueprint-automations' ); ?></p>
	<?php if ( $can_author ) : ?><p><a class="button" href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url() ); ?>"><?php esc_html_e( 'Back to Automations', 'core-blueprint-automations' ); ?></a></p><?php endif; ?>

	<?php if ( $listing['workflow_id'] > 0 ) : ?>
		<p><?php echo esc_html( sprintf( __( 'Filtered to workflow #%d.', 'core-blueprint-automations' ), $listing['workflow_id'] ) ); ?> <a href="<?php echo esc_url( \CB\Automations\Admin\AutomationRunsPage::url() ); ?>"><?php esc_html_e( 'Clear filter', 'core-blueprint-automations' ); ?></a></p>
	<?php endif; ?>

	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Run', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Status', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Workflow', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Principal', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Created', 'core-blueprint-automations' ); ?></th><th><?php esc_html_e( 'Finished', 'core-blueprint-automations' ); ?></th></tr></thead>
		<tbody>
		<?php if ( empty( $listing['items'] ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No automation runs have been recorded yet.', 'core-blueprint-automations' ); ?></td></tr>
		<?php else : foreach ( $listing['items'] as $run ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( \CB\Automations\Admin\AutomationRunsPage::url( [ 'run' => $run['id'] ] ) ); ?>">#<?php echo esc_html( (string) $run['id'] ); ?></a></td>
				<td><?php echo wp_kses_post( \CB\Automations\Admin\AutomationsPage::run_status( $run['status'] ) ); ?></td>
				<td><?php if ( $can_author ) : ?><a href="<?php echo esc_url( \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $run['workflow_id'] ] ) ); ?>">#<?php echo esc_html( (string) $run['workflow_id'] ); ?></a><?php else : ?>#<?php echo esc_html( (string) $run['workflow_id'] ); ?><?php endif; ?> · r<?php echo esc_html( (string) $run['workflow_revision'] ); ?></td>
				<td>#<?php echo esc_html( (string) $run['execution_principal_user_id'] ); ?></td>
				<td><?php echo esc_html( $format_time( $run['created_at'] ) ); ?></td>
				<td><?php echo esc_html( $format_time( $run['finished_at'] ) ); ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<?php if ( $listing['pages'] > 1 ) : ?>
		<p class="tablenav-pages">
			<?php if ( $listing['page'] > 1 ) : ?><a class="button" href="<?php echo esc_url( \CB\Automations\Admin\AutomationRunsPage::url( [ 'run_page' => $listing['page'] - 1, 'workflow_id' => $listing['workflow_id'] ] ) ); ?>"><?php esc_html_e( 'Previous', 'core-blueprint-automations' ); ?></a><?php endif; ?>
			<span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'core-blueprint-automations' ), $listing['page'], $listing['pages'] ) ); ?></span>
			<?php if ( $listing['page'] < $listing['pages'] ) : ?><a class="button" href="<?php echo esc_url( \CB\Automations\Admin\AutomationRunsPage::url( [ 'run_page' => $listing['page'] + 1, 'workflow_id' => $listing['workflow_id'] ] ) ); ?>"><?php esc_html_e( 'Next', 'core-blueprint-automations' ); ?></a><?php endif; ?>
		</p>
	<?php endif; ?>
</div>
