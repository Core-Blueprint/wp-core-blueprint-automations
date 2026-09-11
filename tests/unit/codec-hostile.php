<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

/** @param array<string,mixed> $data */
function expect_decode_failure( array $data, string $message ): void {
	try {
		\CB\Automations\Workflow\DefinitionCodec::decode( $data );
		throw new RuntimeException( $message );
	} catch ( UnexpectedValueException ) {
		/* expected */
	}
}

$future_version = $encoded;
$future_version['definition_version'] = 2;
expect_decode_failure( $future_version, 'Codec accepted an unsupported definition version.' );

$duplicate_step = $encoded;
$duplicate_step['actions'][0]['step_id'] = 'state_1';
expect_decode_failure( $duplicate_step, 'Codec accepted a duplicate step id.' );

$duplicate_condition = $encoded;
$duplicate_condition['conditions'][] = $duplicate_condition['conditions'][0];
expect_decode_failure( $duplicate_condition, 'Codec accepted a duplicate condition id.' );

$malformed_reference = $encoded;
$malformed_reference['actions'][0]['capability']['id'] = 'Project.Create';
expect_decode_failure( $malformed_reference, 'Codec accepted a malformed capability id.' );

$unexpected_binding_field = $encoded;
$unexpected_binding_field['actions'][0]['bindings']['project_id']['future_field'] = true;
expect_decode_failure( $unexpected_binding_field, 'Codec accepted an unknown binding field.' );

fwrite( STDOUT, "codec-hostile: PASS\n" );
