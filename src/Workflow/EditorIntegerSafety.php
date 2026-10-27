<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

defined( 'ABSPATH' ) || exit;

/**
 * Browser/Save numeric precision boundary for canonical DefinitionCodec data.
 *
 * PHP 8.4 on 64-bit can store signed integers that a JavaScript Number cannot
 * distinguish. A hidden field or condition must NEVER be silently rounded by
 * JSON.parse()/JSON.stringify() in the workflow editor. Numeric strings are
 * intentionally not parsed or converted.
 *
 * Safe check only: does not alter the stored Definition, cast numbers, record
 * history, perform I/O, or grant execution authority.
 */
final class EditorIntegerSafety {
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public static function is_browser_safe( Definition|GraphDefinition $definition ): bool {
        return self::value_safe( DefinitionDocumentCodec::encode( $definition ) );
    }

    private static function value_safe( mixed $value ): bool {
        if ( is_int( $value ) ) {
            return $value <= self::MAX_SAFE_INTEGER && $value >= -self::MAX_SAFE_INTEGER;
        }
        if ( is_float( $value ) ) {
            // Integral PHP floats are not type-stable through the current
            // JSON codec (e.g. 1.0 encodes as 1 without PRESERVE_ZERO_FRACTION).
            // This also rejects overflowed JSON integers decoded as floats.
            return is_finite( $value ) && floor( $value ) !== $value;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( ! self::value_safe( $item ) ) {
                    return false;
                }
            }
        }
        return true;
    }
}
