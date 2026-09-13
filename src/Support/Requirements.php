<?php
declare(strict_types=1);

namespace CB\Automations\Support;

use CB\Automations\Runtime\Vault;

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

	/** @return string[] Canonical Bootstrap v1 issue IDs. */
	public static function issues(): array {
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

	/** Canonical Bootstrap v1 readiness: PHP + Base marker + compatible Core API only. */
	public static function runtime_ready(): bool {
		return [] === self::issues();
	}

	/** Compatibility aliases retained for the existing activation lifecycle/tests. */
	public static function bootstrap_issues(): array {
		return self::issues();
	}

	public static function bootstrap_ready(): bool {
		return self::runtime_ready();
	}

	/** @return string[] Automations product-runtime issue IDs. */
	public static function product_issues(): array {
		$issues = self::issues();
		if ( [] !== $issues ) {
			return $issues;
		}

		$required_contracts = [
			'\\CB\\Core\\ExtensionRegistry',
			'\\CB\\Core\\Automation\\TriggerRegistry',
			'\\CB\\Core\\Automation\\ActionRegistry',
			'\\CB\\Core\\Automation\\StateRegistry',
			'\\CB\\Core\\Automation\\TriggerEvent',
			'\\CB\\Core\\Automation\\InvocationContext',
			'\\CB\\Core\\Automation\\ActionInvoker',
			'\\CB\\Core\\Automation\\StateInvoker',
		];

		foreach ( $required_contracts as $contract ) {
			if ( ! class_exists( $contract ) && ! interface_exists( $contract ) ) {
				$issues[] = 'automation-foundation-unavailable';
				break;
			}
		}

		return array_values( array_unique( $issues ) );
	}

	public static function product_ready(): bool {
		return [] === self::product_issues();
	}

	/** Legacy diagnostic name retained; this still reports product-runtime issues. */
	public static function runtime_issues(): array {
		return self::product_issues();
	}

	/** @return string[] Stable machine-readable execution issue IDs. */
	public static function execution_issues(): array {
		$issues = self::product_issues();
		if ( [] !== $issues ) {
			return $issues;
		}
		if ( ! Vault::available() ) {
			$issues[] = 'runtime-crypto-unavailable';
		}
		return array_values( array_unique( $issues ) );
	}

	public static function execution_ready(): bool {
		return [] === self::execution_issues();
	}

	/** @return string[] Stable machine-readable admin issue IDs. */
	public static function admin_issues(): array {
		$issues = self::product_issues();
		if ( [] !== $issues ) {
			return $issues;
		}

		$required_contracts = [
			'\\CB\\Core\\Admin\\MenuGroup',
			'\\CB\\Core\\Admin\\MenuGroupRegistry',
			'\\CB\\Core\\Admin\\Page',
			'\\CB\\Core\\UI\\Status',
		];

		foreach ( $required_contracts as $contract ) {
			if ( ! class_exists( $contract ) && ! interface_exists( $contract ) ) {
				$issues[] = 'core-admin-unavailable';
				break;
			}
		}

		return array_values( array_unique( $issues ) );
	}

	public static function admin_ready(): bool {
		return [] === self::admin_issues();
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
			'automation-foundation-unavailable' => __( 'Required public Core Blueprint Base Automation Foundation contracts are unavailable.', 'core-blueprint-automations' ),
			'core-admin-unavailable' => __( 'Required public Core Blueprint Base admin contracts are unavailable.', 'core-blueprint-automations' ),
			default => __( 'Ready', 'core-blueprint-automations' ),
		};
	}

	private static function primary_issue(): string {
		$issues = self::admin_issues();
		return (string) ( $issues[0] ?? '' );
	}
}
