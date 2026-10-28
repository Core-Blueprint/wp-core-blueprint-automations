<?php
declare(strict_types=1);

// Reuse the canonical B3 catalog and graph fixture, including the existing B3
// regression checks, rather than inventing a second provider model.
require_once __DIR__ . '/adv02b-checkpoint-planner.php';

use CB\Automations\Runtime\GraphCheckpointJournal;
use CB\Automations\Runtime\GraphCheckpointPlanner;
use CB\Automations\Workflow\GraphDefinitionCodec;

function journal_expect( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
}
function journal_reject( callable $callback, string $label ): void {
	try {
		$callback();
	} catch ( UnexpectedValueException ) {
		return;
	}
	throw new RuntimeException( $label );
}

$definition = GraphDefinitionCodec::decode( checkpoint_fixture() );
$planner = new GraphCheckpointPlanner( new Adv02bCheckpointSource() );
$empty = GraphCheckpointJournal::empty();
$initial = $empty->replay( $definition, $planner );
journal_expect(
	'pending' === $initial['status']
	&& 'state' === $initial['intent']['node_id']
	&& null === $initial['pending_branch'],
	'Empty journal could not project the first State.'
);

$true_state = [ 'decision' => true, 'label' => 'Approved' ];
$state = $empty->record_completion( $definition, $planner, 'state', 'state', $true_state );
$state_projection = $state->replay( $definition, $planner );
journal_expect(
	[ 'node_id' => 'condition', 'decision' => true ] === $state_projection['pending_branch']
	&& 'action_true' === $state_projection['intent']['node_id'],
	'State evidence did not yield a derived true-branch choice.'
);
journal_reject(
	static fn () => $state->record_completion( $definition, $planner, 'action_true', 'action', [] ),
	'Action bypassed durable branch-choice evidence.'
);
$with_branch = $state->record_next_branch( $definition, $planner );
journal_expect(
	null === $with_branch->replay( $definition, $planner )['pending_branch'],
	'Derived true branch was not acknowledged.'
);
journal_reject(
	static fn () => $with_branch->record_next_branch( $definition, $planner ),
	'Branch evidence could be appended twice.'
);

$true_complete = $with_branch->record_completion( $definition, $planner, 'action_true', 'action', [] );
journal_expect(
	'complete' === $true_complete->replay( $definition, $planner )['status'],
	'True branch did not reach End.'
);
journal_expect(
	$true_complete->to_array() === GraphCheckpointJournal::from_array( $true_complete->to_array() )->to_array(),
	'Checkpoint document did not round-trip canonically.'
);
journal_expect(
	[] === $empty->to_array()['entries'] && 1 === count( $state->to_array()['entries'] ),
	'Checkpoint append mutated an earlier immutable value.'
);
journal_reject(
	static fn () => $true_complete->record_completion( $definition, $planner, 'action_false', 'action', [] ),
	'Completed checkpoint accepted an unselected Action.'
);

$false_state = $empty->record_completion(
	$definition,
	$planner,
	'state',
	'state',
	[ 'decision' => false, 'label' => 'Declined' ]
);
$false_branch = $false_state->record_next_branch( $definition, $planner );
$false_projection = $false_branch->replay( $definition, $planner );
journal_expect(
	null === $false_projection['pending_branch']
	&& 'pending' === $false_projection['status']
	&& 'action_false' === $false_projection['intent']['node_id']
	&& false === $false_projection['decisions']['condition'],
	'False branch was not acknowledged before the matching Action.'
);
journal_expect(
	'complete' === $false_branch->record_completion(
		$definition, $planner, 'action_false', 'action', []
	)->replay( $definition, $planner )['status'],
	'False branch did not complete.'
);

journal_reject(
	static fn () => $empty->record_completion( $definition, $planner, 'action_true', 'action', [] ),
	'Journal skipped the first pending State.'
);
journal_reject(
	static fn () => $empty->record_next_branch( $definition, $planner ),
	'Journal selected a branch before the State output existed.'
);
journal_reject(
	static fn () => $empty->record_completion( $definition, $planner, 'state', 'action', [] ),
	'Journal accepted a mismatched node kind.'
);
journal_reject(
	static fn () => $state->record_completion( $definition, $planner, 'action_false', 'action', [] ),
	'Journal selected an incorrect branch without an acknowledgement.'
);
journal_reject(
	static fn () => $empty->replay( $definition, $planner, [
		'action_true' => [ 'name' => 'Injected' ],
	] ),
	'Journal accepted untrusted trigger-output identities.'
);

$document = $true_complete->to_array();
$document['entries'][1]['decision'] = false;
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document )->replay( $definition, $planner ),
	'Journal accepted a forged branch decision.'
);
$document = $true_complete->to_array();
$document['entries'][2]['node_id'] = 'action_false';
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document )->replay( $definition, $planner ),
	'Journal accepted a forged off-branch completion.'
);
$document = $true_complete->to_array();
$document['entries'][2] = $document['entries'][0];
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document ),
	'Journal accepted a duplicate node acknowledgement.'
);
$document = $true_complete->to_array();
$document['extra'] = true;
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document ),
	'Journal accepted an unrecognized field.'
);
$document = $true_complete->to_array();
$document['entries'][1]['decision'] = 'true';
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document ),
	'Journal silently coerced a branch decision.'
);
$document = $true_complete->to_array();
$document['entries'][2]['output'] = [ 'invalid' => INF ];
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document ),
	'Journal accepted non-JSON output.'
);
$document = $true_complete->to_array();
$document['entries'][2]['output'] = [ 'payload' => str_repeat( 'x', 300000 ) ];
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document ),
	'Journal accepted an oversized output.'
);
$document = $true_complete->to_array();
$document['entries'] = [ $document['entries'][1], $document['entries'][0], $document['entries'][2] ];
journal_reject(
	static fn () => GraphCheckpointJournal::from_array( $document )->replay( $definition, $planner ),
	'Journal accepted out-of-order evidence.'
);

// C1 must not grant v2 execution or modify any Standard v1 dispatch boundary.
$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/GraphCheckpointJournal.php' );
journal_expect(
	is_string( $source )
	&& ! str_contains( $source, 'RunContextRepository' )
	&& ! str_contains( $source, 'RunRepository' )
	&& ! str_contains( $source, 'StateInvoker' )
	&& ! str_contains( $source, 'ActionInvoker' )
	&& ! str_contains( $source, 'wpdb' ),
	'C1 journal unexpectedly gained persistence or provider authority.'
);
foreach ( [ 'RunPipeline.php', 'RuntimeWorker.php', 'TriggerIntake.php' ] as $runtime ) {
	$live = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/' . $runtime );
	journal_expect(
		is_string( $live ) && ! str_contains( $live, 'GraphCheckpointJournal' ),
		'Standard runtime started depending on the disabled Advanced journal.'
	);
}

echo "ADV-02C C1 replay-verified checkpoint journal: PASS\n";
