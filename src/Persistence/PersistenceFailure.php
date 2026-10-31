<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceFailure extends RuntimeException {
	private const DEFINITION_FAILURE_CODE = 1001;
	private const DEFINITION_METADATA_CODE = 1002;
	private const DEFINITION_SIZE_CODE = 1003;
	private const DEFINITION_JSON_CODE = 1004;
	private const DEFINITION_CODEC_CODE = 1005;
	private const DEFINITION_TRIGGER_CODE = 1006;
	private const DEFINITION_ROW_ID_CODE = 1007;
	private const DEFINITION_ROW_REVISION_CODE = 1008;
	private const DEFINITION_ROW_PRINCIPAL_CODE = 1009;
	private const DEFINITION_ROW_TRIGGER_KEY_CODE = 1010;
	private const DEFINITION_ROW_ACTIVATION_CODE = 1011;
	private const DEFINITION_ROW_VERSION_CODE = 1012;

	/** Stable internal classification. Never inspect exception prose to identify stored-definition failure. */
	public function is_definition_failure(): bool {
		return in_array( $this->getCode(), [
			self::DEFINITION_FAILURE_CODE,
			self::DEFINITION_METADATA_CODE,
			self::DEFINITION_SIZE_CODE,
			self::DEFINITION_JSON_CODE,
			self::DEFINITION_CODEC_CODE,
			self::DEFINITION_TRIGGER_CODE,
			self::DEFINITION_ROW_ID_CODE,
			self::DEFINITION_ROW_REVISION_CODE,
			self::DEFINITION_ROW_PRINCIPAL_CODE,
			self::DEFINITION_ROW_TRIGGER_KEY_CODE,
			self::DEFINITION_ROW_ACTIVATION_CODE,
			self::DEFINITION_ROW_VERSION_CODE,
		], true );
	}

	/** Allowlisted, data-free reason for administrators; the generic case remains unchanged. */
	public function definition_reason(): string {
		return match ( $this->getCode() ) {
			self::DEFINITION_METADATA_CODE => 'ROW_METADATA_INVALID',
			self::DEFINITION_SIZE_CODE => 'ENCODED_LIMIT_EXCEEDED',
			self::DEFINITION_JSON_CODE => 'JSON_MALFORMED',
			self::DEFINITION_CODEC_CODE => 'CODEC_REJECTED',
			self::DEFINITION_TRIGGER_CODE => 'TRIGGER_KEY_MISMATCH',
			self::DEFINITION_ROW_ID_CODE => 'ROW_ID_INVALID',
			self::DEFINITION_ROW_REVISION_CODE => 'ROW_REVISION_INVALID',
			self::DEFINITION_ROW_PRINCIPAL_CODE => 'ROW_PRINCIPAL_INVALID',
			self::DEFINITION_ROW_TRIGGER_KEY_CODE => 'ROW_TRIGGER_KEY_INVALID',
			self::DEFINITION_ROW_ACTIVATION_CODE => 'ROW_ACTIVATION_INVALID',
			self::DEFINITION_ROW_VERSION_CODE => 'ROW_DEFINITION_VERSION_INVALID',
			default => '',
		};
	}

	public static function schema(): self {
		return new self( 'Automations persistence schema is unavailable.' );
	}

	public static function database( string $operation ): self {
		return new self( sprintf( 'Automations persistence failed to %s.', $operation ) );
	}

	public static function definition( string $reason = '' ): self {
		$code = match ( $reason ) {
			'ROW_METADATA_INVALID' => self::DEFINITION_METADATA_CODE,
			'ENCODED_LIMIT_EXCEEDED' => self::DEFINITION_SIZE_CODE,
			'JSON_MALFORMED' => self::DEFINITION_JSON_CODE,
			'CODEC_REJECTED' => self::DEFINITION_CODEC_CODE,
			'TRIGGER_KEY_MISMATCH' => self::DEFINITION_TRIGGER_CODE,
			'ROW_ID_INVALID' => self::DEFINITION_ROW_ID_CODE,
			'ROW_REVISION_INVALID' => self::DEFINITION_ROW_REVISION_CODE,
			'ROW_PRINCIPAL_INVALID' => self::DEFINITION_ROW_PRINCIPAL_CODE,
			'ROW_TRIGGER_KEY_INVALID' => self::DEFINITION_ROW_TRIGGER_KEY_CODE,
			'ROW_ACTIVATION_INVALID' => self::DEFINITION_ROW_ACTIVATION_CODE,
			'ROW_DEFINITION_VERSION_INVALID' => self::DEFINITION_ROW_VERSION_CODE,
			default => self::DEFINITION_FAILURE_CODE,
		};
		return new self( 'Stored workflow definition is malformed or unsupported.', $code );
	}
}
