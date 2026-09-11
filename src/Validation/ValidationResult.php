<?php
declare(strict_types=1);

namespace CB\Automations\Validation;
defined( 'ABSPATH' ) || exit;

final readonly class ValidationResult {
	/** @param ValidationIssue[] $issues */
	public function __construct( private array $issues ) {}

	public function is_valid(): bool {
		return [] === $this->issues;
	}

	/** @return ValidationIssue[] */
	public function issues(): array {
		return $this->issues;
	}

	public function has_code( string $code ): bool {
		foreach ( $this->issues as $issue ) {
			if ( $issue->code() === $code ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array{code:string,path:string,context:array<string,string|int|float|bool>}> */
	public function to_array(): array {
		return array_map( static fn ( ValidationIssue $issue ): array => $issue->to_array(), $this->issues );
	}
}
