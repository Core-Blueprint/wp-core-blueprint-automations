<?php
declare(strict_types=1);

namespace CB\Automations\Validation;
defined( 'ABSPATH' ) || exit;

enum ValidationState: string {
	case Valid                 = 'valid';
	case NeedsReview           = 'needs_review';
	case DependencyUnavailable = 'dependency_unavailable';
	case Invalid               = 'invalid';

	public static function from_result( ValidationResult $result ): self {
		if ( $result->is_valid() ) {
			return self::Valid;
		}

		$dependency = false;
		$review     = false;

		foreach ( $result->issues() as $issue ) {
			$code = $issue->code();
			if ( str_starts_with( $code, 'dependency.' ) ) {
				$dependency = true;
				continue;
			}
			if ( in_array( $code, [ 'capability.schema_mismatch', 'capability.missing' ], true ) ) {
				$review = true;
				continue;
			}
			return self::Invalid;
		}

		if ( $review ) {
			return self::NeedsReview;
		}
		return $dependency ? self::DependencyUnavailable : self::Invalid;
	}
}
