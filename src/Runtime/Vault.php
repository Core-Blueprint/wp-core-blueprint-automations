<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final class Vault {
	public const VERSION = 1;
	public const CIPHER = 'xchacha20poly1305-ietf';
	private const KEY_CONTEXT = 'core-blueprint-automations/runtime-vault/v1';
	private const MAX_PLAINTEXT_BYTES = 1048576;
	private const MAX_ENVELOPE_BYTES = 2097152;
	private const AAD_PATTERN = '/^[A-Za-z0-9:._-]{1,255}$/D';

	public static function available(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' )
			&& function_exists( 'sodium_memzero' )
			&& defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' )
			&& function_exists( 'hash_hkdf' )
			&& function_exists( 'wp_salt' );
	}

	/** @param array<string,mixed> $value */
	public static function seal( array $value, string $aad ): string {
		self::assert_available();
		self::assert_aad( $aad );

		try {
			$plaintext = json_encode(
				$value,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( \JsonException ) {
			throw VaultFailure::payload();
		}

		if ( strlen( $plaintext ) > self::MAX_PLAINTEXT_BYTES ) {
			self::wipe( $plaintext );
			throw VaultFailure::payload();
		}

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		} catch ( \Throwable ) {
			self::wipe( $plaintext );
			throw VaultFailure::unavailable();
		}

		$key = self::derive_key();
		try {
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
				$plaintext,
				$aad,
				$nonce,
				$key
			);
		} finally {
			self::wipe( $plaintext );
			self::wipe( $key );
		}

		$envelope = json_encode(
			[
				'vault_version' => self::VERSION,
				'cipher'        => self::CIPHER,
				'nonce'         => base64_encode( $nonce ),
				'ciphertext'    => base64_encode( $ciphertext ),
			],
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
		);

		if ( strlen( $envelope ) > self::MAX_ENVELOPE_BYTES ) {
			throw VaultFailure::payload();
		}

		return $envelope;
	}

	/** @return array<string,mixed> */
	public static function open( string $envelope, string $aad ): array {
		self::assert_available();
		self::assert_aad( $aad );
		if ( '' === $envelope || strlen( $envelope ) > self::MAX_ENVELOPE_BYTES ) {
			throw VaultFailure::invalid_envelope();
		}

		try {
			$decoded = json_decode( $envelope, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw VaultFailure::invalid_envelope();
		}

		if ( ! is_array( $decoded ) || ! self::has_exact_keys( $decoded ) ) {
			throw VaultFailure::invalid_envelope();
		}
		if ( self::VERSION !== $decoded['vault_version'] || self::CIPHER !== $decoded['cipher'] ) {
			throw VaultFailure::invalid_envelope();
		}
		if ( ! is_string( $decoded['nonce'] ) || ! is_string( $decoded['ciphertext'] ) ) {
			throw VaultFailure::invalid_envelope();
		}

		$nonce = base64_decode( $decoded['nonce'], true );
		$ciphertext = base64_decode( $decoded['ciphertext'], true );
		if (
			false === $nonce
			|| false === $ciphertext
			|| SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== strlen( $nonce )
		) {
			throw VaultFailure::invalid_envelope();
		}

		$key = self::derive_key();
		try {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				$ciphertext,
				$aad,
				$nonce,
				$key
			);
		} finally {
			self::wipe( $key );
		}

		if ( false === $plaintext ) {
			throw VaultFailure::authentication();
		}
		if ( strlen( $plaintext ) > self::MAX_PLAINTEXT_BYTES ) {
			self::wipe( $plaintext );
			throw VaultFailure::payload();
		}

		try {
			$value = json_decode( $plaintext, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw VaultFailure::payload();
		} finally {
			self::wipe( $plaintext );
		}
		if ( ! is_array( $value ) ) {
			throw VaultFailure::payload();
		}

		return $value;
	}

	private static function assert_available(): void {
		if ( ! self::available() ) {
			throw VaultFailure::unavailable();
		}
	}

	private static function assert_aad( string $aad ): void {
		if ( 1 !== preg_match( self::AAD_PATTERN, $aad ) ) {
			throw new \InvalidArgumentException( 'Automation runtime Vault AAD is invalid.' );
		}
	}

	private static function derive_key(): string {
		$material = implode(
			"\0",
			[
				(string) wp_salt( 'auth' ),
				(string) wp_salt( 'secure_auth' ),
				(string) wp_salt( 'logged_in' ),
				(string) wp_salt( 'nonce' ),
			]
		);
		if ( '' === str_replace( "\0", '', $material ) ) {
			self::wipe( $material );
			throw VaultFailure::unavailable();
		}

		try {
			$key = hash_hkdf(
				'sha256',
				$material,
				SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
				self::KEY_CONTEXT,
				''
			);
		} catch ( \Throwable ) {
			throw VaultFailure::unavailable();
		} finally {
			self::wipe( $material );
		}

		if ( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== strlen( $key ) ) {
			self::wipe( $key );
			throw VaultFailure::unavailable();
		}
		return $key;
	}

	/** @param array<string,mixed> $value */
	private static function has_exact_keys( array $value ): bool {
		$keys = array_keys( $value );
		sort( $keys );
		$expected = [ 'cipher', 'ciphertext', 'nonce', 'vault_version' ];
		sort( $expected );
		return $keys === $expected;
	}

	private static function wipe( string &$value ): void {
		if ( '' !== $value && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
		}
		$value = '';
	}
}
