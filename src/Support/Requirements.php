<?php
declare(strict_types=1);

namespace CB\Automations\Support;
defined( 'ABSPATH' ) || exit;

final class Requirements {
	public static function api_compatible( string $available, string $required ): bool {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $available, $available_match ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $required, $required_match ) ) {
			return false;
		}

		return (int) $available_match[1] === (int) $required_match[1]
			&& (int) $available_match[2] >= (int) $required_match[2];
	}

	/**
	 * Bootstrap-safe checks used by the activation lifecycle.
	 *
	 * Do not autoload Base service classes here. Plugin activation can run before
	 * the normal `init` lifecycle, and touching translated Base services at that
	 * point can trigger WordPress' early text-domain warning.
	 *
	 * @return string[] Stable machine-readable bootstrap issue IDs.
	 */
	public static function bootstrap_issues(): array {
		$issues = [];

		if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
			$issues[] = 'php-version';
		}

		if ( ! defined( 'CB_CORE_API_VERSION' ) ) {
			$issues[] = 'base-missing';
			return $issues;
		}

		if ( ! self::api_compatible( (string) CB_CORE_API_VERSION, CB_AUTOMATIONS_REQUIRED_API ) ) {
			$issues[] = 'base-api-incompatible';
		}

		return array_values( array_unique( $issues ) );
	}

	public static function bootstrap_ready(): bool {
		return [] === self::bootstrap_issues();
	}

	/** @return string[] Stable machine-readable runtime issue IDs. */
	public static function issues(): array {
		$issues = self::bootstrap_issues();
		if ( [] !== $issues ) {
			return $issues;
		}

		$required_contracts = [
			'\\CB\\Core\\ExtensionRegistry',
			'\\CB\\Core\\Automation\\TriggerRegistry',
			'\\CB\\Core\\Automation\\ActionRegistry',
			'\\CB\\Core\\Automation\\StateRegistry',
			'\\CB\\Core\\Admin\\PageRegistry',
			'\\CB\\Core\\Admin\\Page',
			'\\CB\\Core\\UI\\Status',
		];

		foreach ( $required_contracts as $contract ) {
			if ( ! class_exists( $contract ) && ! interface_exists( $contract ) ) {
				$issues[] = 'automation-foundation-unavailable';
				break;
			}
		}

		return array_values( array_unique( $issues ) );
	}

	public static function runtime_ready(): bool {
		return [] === self::issues();
	}

	public static function operator_message(): string {
		return match ( self::primary_issue() ) {
			'php-version' => sprintf(
				/* translators: %s: current PHP version. */
				__( 'PHP 8.4 or newer is required. This server runs PHP %s.', 'core-blueprint-automations' ),
				PHP_VERSION
			),
			'base-missing' => __( 'An active Core Blueprint Base installation is required.', 'core-blueprint-automations' ),
			'base-api-incompatible' => sprintf(
				/* translators: 1: required Core API version, 2: available Core API version. */
				__( 'Core API %1$s or a newer compatible minor version is required. This site provides %2$s.', 'core-blueprint-automations' ),
				CB_AUTOMATIONS_REQUIRED_API,
				defined( 'CB_CORE_API_VERSION' ) ? (string) CB_CORE_API_VERSION : __( 'none', 'core-blueprint-automations' )
			),
			'automation-foundation-unavailable' => __( 'Required public Core Blueprint Base contracts are unavailable. Install a Base build that provides the Automation Foundation and Core Admin page contracts.', 'core-blueprint-automations' ),
			default => __( 'Ready', 'core-blueprint-automations' ),
		};
	}

	private static function primary_issue(): string {
		$issues = self::issues();
		return (string) ( $issues[0] ?? '' );
	}
}
