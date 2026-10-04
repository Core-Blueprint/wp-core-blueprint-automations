<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$listeners = file_get_contents( $root . '/src/Provider/WordPress/TriggerListeners.php' );
$actions = file_get_contents( $root . '/src/Provider/WordPress/WordPressAction.php' );
$states = file_get_contents( $root . '/src/Provider/WordPress/WordPressState.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );

if ( false === $listeners || false === $actions || false === $states || false === $plugin ) {
	fwrite( STDERR, "Could not read WordPress provider sources.\n" );
	exit( 1 );
}

$failures = [];
$hooks = [
	'user_register','profile_update','deleted_user','set_user_role','add_user_role','remove_user_role',
	'wp_login','wp_logout','wp_login_failed','password_reset',
	'wp_after_insert_post','post_updated','transition_post_status','trashed_post','untrashed_post','deleted_post',
	'wp_insert_comment','edit_comment','transition_comment_status','trashed_comment','untrashed_comment','deleted_comment',
	'add_attachment','edit_attachment','delete_attachment',
	'created_term','edited_term','delete_term','set_object_terms',
	'activated_plugin','deactivated_plugin','switch_theme','upgrader_process_complete',
];
foreach ( $hooks as $hook ) {
	if ( ! str_contains( $listeners, "'{$hook}'" ) ) {
		$failures[] = 'Missing native WordPress hook bridge: ' . $hook;
	}
}

foreach ( [
	'user_pass' => "'user_pass'",
	'user_activation_key' => "'user_activation_key'",
	'meta_input' => "'meta_input'",
	'auth_cookie' => "'auth_cookie'",
] as $label => $token ) {
	if ( str_contains( $listeners, $token ) ) {
		$failures[] = 'Forbidden raw authentication/user payload token found in trigger adapter: ' . $label;
	}
}

if ( ! str_contains( $listeners, 'unset( $new_pass );' ) ) {
	$failures[] = 'Password reset adapter does not explicitly discard the plaintext password.';
}
if ( ! str_contains( $listeners, "in_array( \$post->post_type, [ 'revision', 'attachment' ], true )" ) ) {
	$failures[] = 'Generic post trigger adapter no longer excludes revision/attachment noise.';
}
if ( ! str_contains( $listeners, 'wp_is_post_autosave( $post )' ) ) {
	$failures[] = 'Generic post trigger adapter no longer suppresses autosave noise.';
}
if ( ! str_contains( $listeners, "'auto-draft' === \$post_before->post_status" ) ) {
	$failures[] = 'First persisted Block Editor auto-draft transition must remain a post.created event.';
}
if ( ! str_contains( $listeners, 'Emitter::emit( CapabilityRegistrar::PROVIDER' ) ) {
	$failures[] = 'WordPress events do not flow through the public Base Emitter.';
}

foreach ( [ "'edit_user'", "'promote_user'", "'edit_post'", "'delete_post'", "'edit_comment'" ] as $object_cap ) {
	if ( ! str_contains( $actions, $object_cap ) ) {
		$failures[] = 'Missing object-level WordPress action authority check: ' . $object_cap;
	}
}
foreach ( [ "'read_post'", "'edit_comment'" ] as $object_cap ) {
	if ( ! str_contains( $states, $object_cap ) ) {
		$failures[] = 'Missing object-level WordPress state authority check: ' . $object_cap;
	}
}
if ( ! str_contains( $plugin, 'WordPressProvider::init();' ) ) {
	$failures[] = 'Plugin runtime does not boot the native WordPress provider.';
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "WordPress provider hooks/security: PASS\n" );
