<?php
declare(strict_types=1);

namespace CB\Automations\Workflow\Recovery;

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Condition\Condition;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\EditorIntegerSafety;
use CB\Automations\Workflow\PersistencePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * AU-R1A: pure, server-private recovery planning. NEVER render its ready
 * candidate, intent objects or trusted provider snapshot to the browser.
 *
 * The caller must supply independently authorized intents and an authoritative
 * server-sourced, revision-bound capability snapshot. This class performs no
 * I/O, authorization, nonce checks, provider lookups, DB writes or execution.
 * Those guarantees are enforced by the separately gated AU-R1B service.
 */
final class WorkflowRecoveryPlanner {
    private const READ_FAILURES = [
        'malformed_json', 'unsupported_schema', 'oversized_definition',
        'trigger_key_mismatch',
    ];
    private const OPERATIONS = [
        'remove_sensitive_binding', 'replace_sensitive_binding',
        'remove_sensitive_condition', 'replace_sensitive_condition',
        'replace_unsafe_integer', 'replace_unsafe_integer_binding',
    ];
    private const MAX_INTENTS = 64;
    private const MAX_SAFE = 9007199254740991;

    /**
     * @param array<string,mixed> $capabilitySnapshot Server-trusted, never browser-provided.
     * @return array{status:string,codes:list<string>}
     */
    public static function classify(
        ?Definition $stored,
        array $capabilitySnapshot,
        ?string $readFailureCode = null
    ): array {
        if ( null === $stored || null !== $readFailureCode ) {
            return [
                'status' => 'unreadable_definition',
                'codes' => [ in_array( $readFailureCode, self::READ_FAILURES, true )
                    ? $readFailureCode : 'read_failure_unknown' ],
            ];
        }
        $snapshot = self::validated_snapshot( $stored, $capabilitySnapshot );
        if ( null !== $snapshot['error'] ) {
            return [ 'status' => 'needs_provider_recovery', 'codes' => [ $snapshot['error'] ] ];
        }
        try {
            $validation = ( new WorkflowValidator( $snapshot['source'] ) )->validate( $stored );
            $privacy = $validation->has_code( 'privacy.sensitive_literal' )
                || self::unsafe_trigger_bindings( $stored );
            $number = ! EditorIntegerSafety::is_browser_safe( $stored );
            $codes = [];
            if ( $privacy ) {
                $codes[] = 'needs_secret_remediation';
            }
            if ( $number ) {
                $codes[] = 'needs_integer_remediation';
            }
            if ( [] !== $codes ) {
                return [ 'status' => $codes[0], 'codes' => $codes ];
            }
            if ( ! $validation->is_valid() ) {
                return [ 'status' => 'blocked_invalid_definition', 'codes' => [ 'invalid_definition' ] ];
            }
            return [ 'status' => 'ready', 'codes' => [] ];
        } catch ( \Throwable ) {
            return [ 'status' => 'blocked_invalid_definition', 'codes' => [ 'invalid_definition' ] ];
        }
    }

    /**
     * Intents are privileged, server-internal objects. They carry *typed*
     * expected originals; NEVER serialize them to browser, audit or logs.
     *
     * @param list<array<string,mixed>> $approvedIntents
     * @param array<string,mixed> $capabilitySnapshot
     * @return array{status:string,candidate:?Definition,intent_ids:list<string>,codes:list<string>}
     */
    public static function plan(
        Definition $stored,
        array $approvedIntents,
        array $capabilitySnapshot
    ): array {
        $snapshot = self::validated_snapshot( $stored, $capabilitySnapshot );
        if ( null !== $snapshot['error'] ) {
            return self::blocked( $snapshot['error'] );
        }
        if ( ! array_is_list( $approvedIntents )
            || [] === $approvedIntents || count( $approvedIntents ) > self::MAX_INTENTS ) {
            return self::blocked( 'blocked_invalid_intent' );
        }

        // Mutable *private working copy* derived from the canonical immutable
        // Definition. Never return intermediate records or incomplete edits.
        $work = DefinitionCodec::encode( $stored );
        $ids = [];
        $targets = [];
        try {
            foreach ( $approvedIntents as $intent ) {
                if ( ! is_array( $intent )
                    || ! self::exact_keys( $intent, [ 'intent_id', 'operation', 'target', 'expected', 'replacement', 'confirm' ] )
                    || true !== $intent['confirm']
                    || ! self::identifier( $intent['intent_id'] )
                    || ! in_array( $intent['operation'], self::OPERATIONS, true )
                    || ! is_array( $intent['target'] ) ) {
                    return self::blocked( 'blocked_invalid_intent' );
                }
                $selector = self::selector( $intent['operation'], $intent['target'] );
                if ( null === $selector ) {
                    return self::blocked( 'blocked_invalid_intent' );
                }
                $target_key = json_encode( $selector, JSON_THROW_ON_ERROR );
                if ( isset( $ids[ $intent['intent_id'] ] ) || isset( $targets[ $target_key ] ) ) {
                    return self::blocked( 'blocked_invalid_intent' );
                }
                $ids[ $intent['intent_id'] ] = true;
                $targets[ $target_key ] = true;
                $status = self::apply( $work, $selector, $intent, $snapshot['definitions'] );
                if ( null !== $status ) {
                    return self::blocked( $status );
                }
            }

            // Check canonical shape, size, round-trip and all provider-derived
            // validation AFTER the complete batch; never persist in between.
            $json = json_encode( $work, JSON_THROW_ON_ERROR );
            if ( ! PersistencePolicy::allows_encoded_definition( $json ) ) {
                return self::blocked( 'blocked_invalid_candidate' );
            }
            $candidate = DefinitionCodec::decode( $work );
            if ( DefinitionCodec::encode( $candidate ) !== $work ) {
                return self::blocked( 'blocked_invalid_candidate' );
            }

            $validation = ( new WorkflowValidator( $snapshot['source'] ) )->validate( $candidate );
            $pending_privacy = ! PersistencePolicy::allows( $validation )
                || self::unsafe_trigger_bindings( $candidate );
            $pending_integer = ! EditorIntegerSafety::is_browser_safe( $candidate );
            $pending_codes = [];
            if ( $pending_privacy ) {
                $pending_codes[] = 'needs_secret_remediation';
            }
            if ( $pending_integer ) {
                $pending_codes[] = 'needs_integer_remediation';
            }
            // Non-remediable defects must not be reported as a safe pending
            // batch: notably required-field removals and changed schemas.
            if ( ! self::only_remediable_issues( $validation->issues(), $candidate ) ) {
                return self::blocked( 'blocked_invalid_candidate' );
            }
            if ( [] !== $pending_codes ) {
                return [
                    'status' => 'pending_remediation',
                    'candidate' => null,
                    'intent_ids' => array_keys( $ids ),
                    'codes' => $pending_codes,
                ];
            }
            if ( ! $validation->is_valid() ) {
                return self::blocked( 'blocked_invalid_candidate' );
            }

            return [
                'status' => 'ready',
                'candidate' => $candidate,
                'intent_ids' => array_keys( $ids ),
                'codes' => [],
            ];
        } catch ( \Throwable ) {
            // Never return exception strings, stored values, or source records.
            return self::blocked( 'blocked_invalid_candidate' );
        }
    }

    private static function blocked( string $code ): array {
        return [ 'status' => $code, 'candidate' => null, 'intent_ids' => [], 'codes' => [ $code ] ];
    }

    private static function only_remediable_issues( array $issues, Definition $candidate ): bool {
        $encoded = DefinitionCodec::encode( $candidate );
        foreach ( $issues as $issue ) {
            if ( in_array( $issue->code(), [ 'privacy.sensitive_literal', 'binding.trigger_has_bindings' ], true ) ) {
                continue;
            }
            // Historical oversized integers can also produce a binding
            // schema mismatch (e.g. a stored integer in a string-only field).
            // It may remain only in a NONPERSISTABLE pending batch and only
            // while the exact offending literal is still unsafe.
            if ( 'binding.type_mismatch' === $issue->code()
                && 1 === preg_match( '/^(states|actions)\.([0-9]+)\.bindings\.([a-z][a-z0-9_]*)$/D', $issue->path(), $m ) ) {
                $binding = $encoded[ $m[1] ][ (int) $m[2] ]['bindings'][ $m[3] ] ?? null;
                if ( is_array( $binding ) && 'literal' === ( $binding['source'] ?? null )
                    && ! self::safe_value( $binding['value'] ) ) {
                    continue;
                }
            }
            return false;
        }
        return true;
    }

    private static function unsafe_trigger_bindings( Definition $stored ): bool {
        return null !== $stored->trigger() && [] !== $stored->trigger()->bindings();
    }

    private static function identifier( mixed $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $value );
    }

    private static function field( mixed $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]*$/D', $value );
    }

    private static function exact_keys( array $data, array $keys ): bool {
        $a = array_keys( $data );
        sort( $a );
        sort( $keys );
        return $a === $keys;
    }

    /**
     * Typed, bounded selector. No arbitrary paths or numeric array keys from
     * the browser; R1B still has to approve and bind the target to an actor.
     */
    private static function selector( string $operation, array $target ): ?array {
        $collection = $target['collection'] ?? null;
        if ( 'conditions' === $collection ) {
            if ( ! self::identifier( $target['condition_id'] ?? null ) ) {
                return null;
            }
            $whole = in_array( $operation, [ 'remove_sensitive_condition', 'replace_sensitive_condition' ], true );
            if ( $whole ) {
                return self::exact_keys( $target, [ 'collection', 'condition_id' ] ) ? $target : null;
            }
            if ( ! in_array( $operation, [ 'replace_unsafe_integer', 'replace_unsafe_integer_binding' ], true )
                || ! in_array( $target['operand'] ?? null, [ 'left', 'right' ], true ) ) {
                return null;
            }
            $keys = [ 'collection', 'condition_id', 'operand' ];
        } elseif ( in_array( $collection, [ 'trigger', 'states', 'actions' ], true ) ) {
            if ( ! self::identifier( $target['step_id'] ?? null ) || ! self::field( $target['field'] ?? null )
                || in_array( $operation, [ 'remove_sensitive_condition', 'replace_sensitive_condition' ], true ) ) {
                return null;
            }
            $keys = [ 'collection', 'step_id', 'field' ];
        } else {
            return null;
        }
        if ( array_key_exists( 'array_index', $target ) ) {
            if ( 'replace_unsafe_integer' !== $operation || ! is_int( $target['array_index'] )
                || $target['array_index'] < 0 ) {
                return null;
            }
            $keys[] = 'array_index';
        }
        return self::exact_keys( $target, $keys ) ? $target : null;
    }

    /** @param array<string,mixed> $work */
    private static function apply( array &$work, array $selector, array $intent, array $definitions ): ?string {
        $collection = $selector['collection'];
        $condition = 'conditions' === $collection;
        $index = null;
        if ( $condition ) {
            foreach ( $work['conditions'] as $at => $entry ) {
                if ( $entry['condition_id'] === $selector['condition_id'] ) {
                    $index = $at;
                    break;
                }
            }
        } elseif ( 'trigger' === $collection ) {
            if ( is_array( $work['trigger'] ) && $work['trigger']['step_id'] === $selector['step_id'] ) {
                $index = 0;
            }
        } else {
            foreach ( $work[ $collection ] as $at => $entry ) {
                if ( $entry['step_id'] === $selector['step_id'] ) {
                    $index = $at;
                    break;
                }
            }
        }
        if ( null === $index ) {
            return 'blocked_invalid_intent';
        }

        $old_entry = $condition ? $work['conditions'][ $index ]
            : ( 'trigger' === $collection ? $work['trigger'] : $work[ $collection ][ $index ] );
        if ( $condition && ! isset( $selector['operand'] ) ) {
            if ( ! $intent['expected'] instanceof Condition
                || $old_entry !== $intent['expected']->to_array()
                || ! self::sensitive_condition( $old_entry, $work, $definitions ) ) {
                return 'blocked_invalid_intent';
            }
            if ( 'remove_sensitive_condition' === $intent['operation'] ) {
                if ( null !== $intent['replacement'] ) {
                    return 'blocked_invalid_intent';
                }
                array_splice( $work['conditions'], $index, 1 );
                return null;
            }
            if ( ! $intent['replacement'] instanceof Condition
                || $intent['replacement']->condition_id() !== $selector['condition_id']
                || $intent['replacement']->to_array() === $old_entry ) {
                return 'blocked_invalid_intent';
            }
            $work['conditions'][ $index ] = $intent['replacement']->to_array();
            return null;
        }

        $binding_array = $condition
            ? ( $old_entry[ $selector['operand'] ] ?? null )
            : ( $old_entry['bindings'][ $selector['field'] ] ?? null );
        if ( ! is_array( $binding_array ) || ! $intent['expected'] instanceof Binding
            || $binding_array !== $intent['expected']->to_array() ) {
            return 'blocked_invalid_intent';
        }

        $operation = $intent['operation'];
        $sensitive = $condition ? false
            : self::sensitive_field( $old_entry, $selector['field'], $definitions );
        if ( in_array( $operation, [ 'remove_sensitive_binding', 'replace_sensitive_binding' ], true ) ) {
            if ( $condition || ! ( 'trigger' === $collection || $sensitive )
                || 'literal' !== $binding_array['source'] ) {
                return 'blocked_invalid_intent';
            }
            if ( 'remove_sensitive_binding' === $operation ) {
                if ( null !== $intent['replacement'] ) {
                    return 'blocked_invalid_intent';
                }
                if ( 'trigger' === $collection ) {
                    unset( $work['trigger']['bindings'][ $selector['field'] ] );
                } else {
                    unset( $work[ $collection ][ $index ]['bindings'][ $selector['field'] ] );
                }
                return null;
            }
            if ( ! $intent['replacement'] instanceof Binding
                || 'step_output' !== $intent['replacement']->source()
                || $intent['replacement']->to_array() === $binding_array ) {
                return 'blocked_invalid_intent';
            }
        } elseif ( in_array( $operation, [ 'replace_unsafe_integer', 'replace_unsafe_integer_binding' ], true ) ) {
            if ( 'literal' !== $binding_array['source'] || self::safe_value( $binding_array['value'] ) ) {
                return 'blocked_invalid_intent';
            }
            if ( ! $intent['replacement'] instanceof Binding ) {
                return 'blocked_invalid_intent';
            }
            if ( 'replace_unsafe_integer_binding' === $operation ) {
                if ( 'step_output' !== $intent['replacement']->source() ) {
                    return 'blocked_invalid_intent';
                }
            } else {
                $original = $binding_array['value'];
                $new = $intent['replacement']->to_array();
                if ( 'literal' !== ( $new['source'] ?? null ) || ! is_string( $new['value'] ?? null ) ) {
                    return 'blocked_precision_or_semantics';
                }
                $field_schema = self::target_schema( $old_entry, $selector, $work, $definitions );
                if ( null === $field_schema ) {
                    return 'blocked_precision_or_semantics';
                }
                if ( isset( $selector['array_index'] ) ) {
                    $item = $selector['array_index'];
                    if ( ! is_array( $original ) || ! array_is_list( $original )
                        || ! array_key_exists( $item, $original ) || ! self::unsafe_integer( $original[ $item ] )
                        || 'array' !== $field_schema['type'] || 'string' !== $field_schema['items']
                        || (string) $original[ $item ] !== $new['value'] ) {
                        return 'blocked_precision_or_semantics';
                    }
                    $original[ $item ] = $new['value'];
                    $replaced = Binding::literal( $original );
                    if ( null === $replaced ) {
                        return 'blocked_precision_or_semantics';
                    }
                    $new = $replaced->to_array();
                } elseif ( ! self::unsafe_integer( $original )
                    || 'string' !== $field_schema['type']
                    || (string) $original !== $new['value'] ) {
                    return 'blocked_precision_or_semantics';
                }
                self::set_binding( $work, $selector, $index, $new );
                return null;
            }
        } else {
            return 'blocked_invalid_intent';
        }

        self::set_binding( $work, $selector, $index, $intent['replacement']->to_array() );
        return null;
    }

    private static function set_binding( array &$work, array $selector, int $index, array $value ): void {
        if ( 'conditions' === $selector['collection'] ) {
            $work['conditions'][ $index ][ $selector['operand'] ] = $value;
        } elseif ( 'trigger' === $selector['collection'] ) {
            $work['trigger']['bindings'][ $selector['field'] ] = $value;
        } else {
            $work[ $selector['collection'] ][ $index ]['bindings'][ $selector['field'] ] = $value;
        }
    }

    private static function sensitive_field( array $entry, string $field, array $definitions ): bool {
        $ref = $entry['capability'];
        $key = implode( ':', [ $ref['kind'], $ref['provider'], $ref['id'], $ref['schema_version'] ] );
        return true === ( $definitions[ $key ]->input_schema()[ $field ]['sensitive'] ?? false );
    }

    private static function sensitive_condition( array $condition, array $work, array $definitions ): bool {
        $left = $condition['left'];
        $right = $condition['right'];
        return ( is_array( $right ) && 'literal' === $right['source']
                && self::sensitive_output_binding( $left, $work, $definitions ) )
            || ( is_array( $right ) && 'literal' === $left['source']
                && self::sensitive_output_binding( $right, $work, $definitions ) );
    }

    private static function sensitive_output_binding( array $binding, array $work, array $definitions ): bool {
        if ( 'step_output' !== $binding['source'] ) {
            return false;
        }
        foreach ( array_merge(
            null !== $work['trigger'] ? [ $work['trigger'] ] : [],
            $work['states'], $work['actions']
        ) as $step ) {
            if ( $step['step_id'] !== $binding['step_id'] ) {
                continue;
            }
            $ref = $step['capability'];
            $key = implode( ':', [ $ref['kind'], $ref['provider'], $ref['id'], $ref['schema_version'] ] );
            return true === ( $definitions[ $key ]->output_schema()[ $binding['field'] ]['sensitive'] ?? false );
        }
        return false;
    }

    private static function target_schema( array $entry, array $selector, array $work, array $definitions ): ?array {
        if ( 'conditions' === $selector['collection'] ) {
            // A condition literal can only be changed to another binding in
            // AU-R1A; its scalar type has no independent provider input schema.
            return null;
        }
        $ref = $entry['capability'];
        $key = implode( ':', [ $ref['kind'], $ref['provider'], $ref['id'], $ref['schema_version'] ] );
        return $definitions[ $key ]->input_schema()[ $selector['field'] ] ?? null;
    }

    private static function unsafe_integer( mixed $value ): bool {
        return is_int( $value ) && ( $value > self::MAX_SAFE || $value < -self::MAX_SAFE );
    }

    private static function safe_value( mixed $value ): bool {
        if ( is_int( $value ) ) {
            return ! self::unsafe_integer( $value );
        }
        if ( is_float( $value ) ) {
            return is_finite( $value ) && floor( $value ) !== $value;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( ! self::safe_value( $item ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Build a callback-free CapabilitySource over validated, by-value evidence.
     * The snapshot ID is an opaque integrity handle to be authenticated and
     * rechecked by R1B. R1A does not treat a caller-supplied ID as permission.
     */
    private static function validated_snapshot( Definition $stored, array $snapshot ): array {
        $error = static fn ( string $code ): array => [
            'source' => null, 'definitions' => [], 'error' => $code,
        ];
        if ( ! self::exact_keys( $snapshot, [ 'snapshot_id', 'providers', 'capabilities' ] )
            || ! is_string( $snapshot['snapshot_id'] )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['snapshot_id'] )
            || ! is_array( $snapshot['providers'] ) || ! is_array( $snapshot['capabilities'] ) ) {
            return $error( 'blocked_provider_unavailable' );
        }
        $statuses = [];
        $definitions = [];
        foreach ( $stored->steps() as $step ) {
            $ref = $step->capability();
            $provider = $ref->provider();
            $provider_data = $snapshot['providers'][ $provider ] ?? null;
            if ( ! is_array( $provider_data )
                || ! self::exact_keys( $provider_data, [ 'available' ] )
                || true !== ( $provider_data['available'] ?? null ) ) {
                return $error( 'blocked_provider_unavailable' );
            }
            $statuses[ $provider ] = ProviderStatus::from_inventory(
                $provider,
                [ 'name' => $provider, 'installed' => true, 'active' => true,
                    'registered' => true, 'compatible' => true ]
            );
            $key = $ref->key();
            $cap = $snapshot['capabilities'][ $key ] ?? null;
            if ( ! is_array( $cap ) ) {
                $prefix = $ref->kind() . ':' . $ref->provider() . ':' . $ref->id() . ':';
                foreach ( array_keys( $snapshot['capabilities'] ) as $candidate_key ) {
                    if ( is_string( $candidate_key ) && str_starts_with( $candidate_key, $prefix ) ) {
                        return $error( 'blocked_schema_mismatch' );
                    }
                }
                return $error( 'blocked_provider_unavailable' );
            }
            if ( ! self::exact_keys( $cap, [ 'reference', 'input_schema', 'output_schema', 'required_capability' ] )
                || ! is_array( $cap['reference'] )
                || $cap['reference'] !== $ref->to_array()
                || ! self::schema( $cap['input_schema'] ) || ! self::schema( $cap['output_schema'] )
                || ( null !== $cap['required_capability']
                    && ( ! is_string( $cap['required_capability'] )
                        || '' === $cap['required_capability'] ) )
                || ( 'trigger' === $ref->kind() && [] !== $cap['input_schema'] ) ) {
                return $error( 'blocked_schema_mismatch' );
            }
            $raw = [
                'provider' => $provider,
                'id' => $ref->id(),
                'schema_version' => $ref->schema_version(),
                'label' => $ref->id(),
                'description' => '',
            ];
            if ( 'trigger' === $ref->kind() ) {
                $raw['payload_schema'] = $cap['output_schema'];
            } else {
                $raw['input_schema'] = $cap['input_schema'];
                $raw['output_schema'] = $cap['output_schema'];
                $raw['required_capability'] = $cap['required_capability'];
            }
            $definition = CapabilityDefinition::from_base_definition( $ref->kind(), $raw );
            if ( null === $definition || $definition->reference()->key() !== $key ) {
                return $error( 'blocked_schema_mismatch' );
            }
            $definitions[ $key ] = $definition;
        }

        $source = new class( $definitions, $statuses ) implements CapabilitySource {
            public function __construct(
                private readonly array $definitions,
                private readonly array $statuses
            ) {}
            public function current( CapabilityReference $reference ): ?CapabilityDefinition {
                return $this->definitions[ $reference->key() ] ?? null;
            }
            public function provider_status( string $provider ): ProviderStatus {
                return $this->statuses[ $provider ]
                    ?? ProviderStatus::from_inventory( $provider, null );
            }
        };
        return [ 'source' => $source, 'definitions' => $definitions, 'error' => null ];
    }

    private static function schema( mixed $fields ): bool {
        if ( ! is_array( $fields ) ) {
            return false;
        }
        foreach ( $fields as $name => $field ) {
            if ( ! self::field( $name ) || ! is_array( $field ) ) {
                return false;
            }
            $keys = array_keys( $field );
            foreach ( $keys as $key ) {
                if ( ! in_array( $key, [ 'type', 'required', 'sensitive', 'items', 'semantic_type' ], true ) ) {
                    return false;
                }
            }
            if ( ! in_array( $field['type'] ?? null, [ 'string', 'integer', 'number', 'boolean', 'array' ], true )
                || ! is_bool( $field['required'] ?? null ) || ! is_bool( $field['sensitive'] ?? null )
                || ( isset( $field['items'] ) && ! in_array( $field['items'], [ 'string', 'integer', 'number', 'boolean' ], true ) )
                || ( 'array' === $field['type'] && ! in_array( $field['items'] ?? null, [ 'string', 'integer', 'number', 'boolean' ], true ) )
                || ( 'array' !== $field['type'] && null !== ( $field['items'] ?? null ) )
                || ( isset( $field['semantic_type'] ) && ( ! is_string( $field['semantic_type'] )
                    || '' === $field['semantic_type'] ) ) ) {
                return false;
            }
        }
        return true;
    }
}
