<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function wp_salt( string $scheme = 'auth' ): string {
	return 'core-blueprint-test-salt-' . $scheme . '-4f0d5f5a6c2d41d8';
}

require_once dirname( __DIR__, 2 ) . '/src/Runtime/VaultFailure.php';
require_once dirname( __DIR__, 2 ) . '/src/Runtime/Vault.php';

use CB\Automations\Runtime\Vault;
use CB\Automations\Runtime\VaultFailure;

if ( ! Vault::available() ) {
	throw new RuntimeException( 'CI runtime must provide Sodium XChaCha20-Poly1305 for AU2 Vault proof.' );
}

$payload = [
	'user_id' => 42,
	'secret'  => 'patient-private-runtime-value',
	'flags'   => [ true, false ],
];
$aad = 'event:fixture-123';
$envelope = Vault::seal( $payload, $aad );

if ( str_contains( $envelope, 'patient-private-runtime-value' ) ) {
	throw new RuntimeException( 'Runtime Vault envelope leaked plaintext.' );
}

$metadata = json_decode( $envelope, true, 8, JSON_THROW_ON_ERROR );
if ( ! is_array( $metadata ) ) {
	throw new RuntimeException( 'Runtime Vault envelope is not structured JSON metadata.' );
}
if ( 1 !== ( $metadata['vault_version'] ?? null ) || 'xchacha20poly1305-ietf' !== ( $metadata['cipher'] ?? null ) ) {
	throw new RuntimeException( 'Runtime Vault envelope is not explicitly versioned/cipher-labelled.' );
}
if ( $payload !== Vault::open( $envelope, $aad ) ) {
	throw new RuntimeException( 'Runtime Vault authenticated round-trip drifted.' );
}

$wrong_aad_failed = false;
try {
	Vault::open( $envelope, 'event:different-subject' );
} catch ( VaultFailure $error ) {
	$wrong_aad_failed = true;
}
if ( ! $wrong_aad_failed ) {
	throw new RuntimeException( 'Runtime Vault ciphertext was not bound to its AAD subject.' );
}

$ciphertext = base64_decode( (string) $metadata['ciphertext'], true );
if ( false === $ciphertext || '' === $ciphertext ) {
	throw new RuntimeException( 'Could not decode Vault ciphertext fixture.' );
}
$ciphertext[0] = chr( ord( $ciphertext[0] ) ^ 1 );
$metadata['ciphertext'] = base64_encode( $ciphertext );
$tampered = json_encode( $metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
$tamper_failed = false;
try {
	Vault::open( $tampered, $aad );
} catch ( VaultFailure $error ) {
	$tamper_failed = true;
}
if ( ! $tamper_failed ) {
	throw new RuntimeException( 'Runtime Vault accepted tampered ciphertext.' );
}

$metadata = json_decode( $envelope, true, 8, JSON_THROW_ON_ERROR );
$metadata['vault_version'] = 2;
$future = json_encode( $metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
$version_failed = false;
try {
	Vault::open( $future, $aad );
} catch ( VaultFailure $error ) {
	$version_failed = true;
}
if ( ! $version_failed ) {
	throw new RuntimeException( 'Runtime Vault silently accepted an unknown envelope version.' );
}

$root = dirname( __DIR__, 2 );
$repository = file_get_contents( $root . '/src/Persistence/RunContextRepository.php' );
$run_repository = file_get_contents( $root . '/src/Persistence/RunRepository.php' );
$recovery_repository = file_get_contents( $root . '/src/Persistence/OperatorRecoveryRepository.php' );
$requirements = file_get_contents( $root . '/src/Support/Requirements.php' );
$service = file_get_contents( $root . '/src/Workflow/WorkflowService.php' );
if ( false === $repository || false === $run_repository || false === $recovery_repository || false === $requirements || false === $service ) {
	throw new RuntimeException( 'Could not read Runtime Vault boundary sources.' );
}
if ( ! str_contains( $repository, 'Vault::seal' ) || ! str_contains( $repository, 'Vault::open' ) ) {
	throw new RuntimeException( 'Run context persistence can bypass the Runtime Vault.' );
}
if ( str_contains( $repository, 'json_encode( $context' ) || str_contains( $repository, 'wp_json_encode( $context' ) ) {
	throw new RuntimeException( 'Run context repository contains a plaintext JSON persistence path.' );
}
if ( ! str_contains( $run_repository, '$to->is_terminal()' ) || ! str_contains( $run_repository, 'RunContextRepository::purge( $run_id )' ) ) {
	throw new RuntimeException( 'Terminal runtime status transitions no longer purge encrypted working context.' );
}
if ( ! str_contains( $recovery_repository, '$target->is_terminal()' ) || ! str_contains( $recovery_repository, 'RunContextRepository::purge( $run_id )' ) ) {
	throw new RuntimeException( 'Terminal operator recovery resolutions no longer purge encrypted working context.' );
}
if ( ! str_contains( $requirements, 'public static function execution_ready(): bool' ) || ! str_contains( $requirements, 'Vault::available()' ) ) {
	throw new RuntimeException( 'Secure execution readiness is not gated on Runtime Vault availability.' );
}
if ( ! str_contains( $service, 'Requirements::execution_ready()' ) ) {
	throw new RuntimeException( 'Workflow activation does not fail closed when secure execution is unavailable.' );
}

fwrite( STDOUT, "Runtime Vault: PASS\n" );
