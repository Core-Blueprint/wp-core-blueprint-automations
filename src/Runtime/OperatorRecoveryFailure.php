<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final class OperatorRecoveryFailure extends \RuntimeException {
	public function __construct( private readonly string $machine_code ) {
		parent::__construct( $machine_code );
	}

	public function machine_code(): string {
		return $this->machine_code;
	}
}
