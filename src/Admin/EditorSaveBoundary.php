<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * AU-2: non-destructive server-side save boundary for browser-redacted workflows.
 *
 * The editor receives a sanitized DefinitionCodec projection, never the private
 * stored definition. Absent redacted fields are NOT a deletion instruction.
 * Restoring historical sensitive literal values into a new Definition would
 * violate PersistencePolicy and leak ownership of hidden data into history.
 *
 * Therefore this first boundary accepts only a complete, verifiably authorable
 * stored projection. An unavailable/drifted provider or any redaction blocks the
 * FULL save, including name/principal/activation changes, without modifying
 * the stored workflow. Explicit safe remediation must be a separate operation.
 */
final class EditorSaveBoundary {
    /**
     * @return ?Definition The submitted canonical definition, or null to deny
     *                     destructive/ambiguous replacement without side effects.
     */
    public static function reconcile(
        Definition $stored,
        Definition $submitted,
        CapabilitySource $source
    ): ?Definition {
        $definitions = [];

        try {
            foreach ( $stored->steps() as $step ) {
                $reference = $step->capability();
                if ( ! $source->provider_status( $reference->provider() )->is_available() ) {
                    return null;
                }

                $current = $source->current( $reference );
                if (
                    ! $current instanceof CapabilityDefinition
                    || $current->reference()->key() !== $reference->key()
                ) {
                    return null;
                }

                $definitions[
                    $reference->kind() . ':' . $reference->provider() . ':' . $reference->id()
                ] = $current;
            }

            // The stored definition remains server-side. Projection is computed
            // against current authoritative provider schemas, not client metadata.
            $projection = EditorDefinitionProjection::project(
                DefinitionCodec::encode( $stored ),
                $definitions
            );
            if ( 0 !== $projection['redacted'] ) {
                // No silent deletion, and no re-persistence of prohibited literals.
                return null;
            }
        } catch ( \Throwable ) {
            // Capability providers are untrusted extension boundaries. Fail closed
            // and never expose exception messages, workflow values, or secrets.
            return null;
        }

        return $submitted;
    }
}
