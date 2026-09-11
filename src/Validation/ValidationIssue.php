<?php
declare(strict_types=1);

namespace CB\Automations\Validation;
defined( 'ABSPATH' ) || exit;

final readonly class ValidationIssue {
	/** @param array<string,string|int|float|bool> $context */
	public function __construct(
		private string $code,
		private string $path,
		private array $context = []
	) {}

	public function code(): string {
		return $this->code;
	}

	public function path(): string {
		return $this->path;
	}

	/** @return array<string,string|int|float|bool> */
	public function context(): array {
		return $this->context;
	}

	/** @return array{code:string,path:string,context:array<string,string|int|float|bool>} */
	public function to_array(): array {
		return [
			'code'    => $this->code,
			'path'    => $this->path,
			'context' => $this->context,
		];
	}
}
