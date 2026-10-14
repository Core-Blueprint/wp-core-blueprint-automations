<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );
	function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	function get_post_stati( array $args, string $output ): array {
		if ( [ 'internal' => false ] !== $args || 'objects' !== $output ) {
			throw new \RuntimeException( 'Status picker must request the live, non-internal WordPress statuses.' );
		}
		return [
			'draft' => (object) [ 'label' => 'Draft' ],
			'publish' => (object) [ 'label' => 'Published' ],
			'pending' => (object) [ 'label' => 'Pending review' ],
			'future' => (object) [ 'label' => 'Scheduled' ],
			'private' => (object) [ 'label' => 'Private' ],
			'company_review' => (object) [ 'label' => '<b>Company review</b>' ],
			'bad status' => (object) [ 'label' => 'Ignore' ],
		];
	}
	require_once dirname( __DIR__, 2 ) . '/src/Admin/WorkflowEditorData.php';
	$method = new \ReflectionMethod( \CB\Automations\Admin\WorkflowEditorData::class, 'post_status_options' );
	$rows = $method->invoke( null );
	if ( [ 'draft', 'publish', 'pending', 'future', 'private', 'company_review' ] !== array_column( $rows, 'value' ) ) {
		throw new \RuntimeException( 'Missing or unsafe site-registered post statuses.' );
	}
	if ( 'Company review' !== $rows[5]['label'] ) {
		throw new \RuntimeException( 'Post status labels were not normalized.' );
	}
	fwrite( STDOUT, "WordPress post status choices projection: PASS\n" );
}
