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
	'created' => [ 'success', __( 'Automation draft created.', 'core-blueprint-automations' ) ],
	'failed'  => [ 'error', __( 'The automation could not be created. Check the name and try again.', 'core-blueprint-automations' ) ],
];
?>
<div class="wrap cb-core-wrap cb-automations-admin">
	<h1 class="cb-core-title"><?php esc_html_e( 'Automations', 'core-blueprint-automations' ); ?></h1>
	<p class="cb-core-intro"><?php esc_html_e( 'Build linear workflows from capabilities registered by Core Blueprint extensions.', 'core-blueprint-automations' ); ?></p>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>

	<section class="cb-core-panel">
		<h2><?php esc_html_e( 'Create automation', 'core-blueprint-automations' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-form-row">
			<input type="hidden" name="action" value="cb_automations_create_workflow" />
			<?php wp_nonce_field( 'cb_automations_create_workflow', '_cb_automations_nonce' ); ?>
			<label for="cb-automation-name" class="screen-reader-text"><?php esc_html_e( 'Automation name', 'core-blueprint-automations' ); ?></label>
			<input id="cb-automation-name" name="name" type="text" class="regular-text" maxlength="191" required placeholder="<?php echo esc_attr__( 'Automation name', 'core-blueprint-automations' ); ?>" />
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Create automation', 'core-blueprint-automations' ); ?></button>
		</form>
	</section>

	<section class="cb-core-panel">
		<h2><?php esc_html_e( 'Workflows', 'core-blueprint-automations' ); ?></h2>

		<?php if ( empty( $listing['items'] ) ) : ?>
			<div class="cb-core-empty-state">
				<p><?php esc_html_e( 'No automations have been created yet.', 'core-blueprint-automations' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped cb-automations-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Automation', 'core-blueprint-automations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Activation', 'core-blueprint-automations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Validation', 'core-blueprint-automations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', 'core-blueprint-automations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $listing['items'] as $item ) : ?>
						<?php
						$record = $item['record'];
						$edit_url = \CB\Automations\Admin\AutomationsPage::url( [ 'workflow' => $record->id() ] );
						$updated = '' !== $record->updated_at()
							? get_date_from_gmt( preg_replace( '/\.\d+$/', '', $record->updated_at() ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
							: '—';
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $record->name() ); ?></a></strong>
								<div class="row-actions"><span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'core-blueprint-automations' ); ?></a></span></div>
							</td>
							<td><?php echo \CB\Automations\Admin\AutomationsPage::activation_status( $record->activation_state()->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?></td>
							<td>
								<?php echo \CB\Automations\Admin\AutomationsPage::validation_status( $item['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Base Status::render() returns escaped markup. ?>
								<?php if ( ! $item['validation']->is_valid() ) : ?>
									<span class="description"><?php echo esc_html( sprintf( _n( '%d issue', '%d issues', count( $item['validation']->issues() ), 'core-blueprint-automations' ), count( $item['validation']->issues() ) ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $updated ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $listing['pages'] > 1 ) : ?>
				<div class="tablenav bottom">
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
