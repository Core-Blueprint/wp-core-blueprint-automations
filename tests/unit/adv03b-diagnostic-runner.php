<?php
declare(strict_types=1);
// Reuse the real pre-existing validated graph fixture and route authority.
require __DIR__ . '/adv02b-graph-route-planner.php';

$yes = $planner->inspect( $graph, [ 'state' => [ 'decision' => true ] ] );
route_check( $yes['complete'] && null === $yes['blocked'], 'True route incomplete.' );
route_check( 'simulated' === $yes['results']['true_action']['status'], 'Chosen action not simulated.' );
route_check( 'skipped' === $yes['results']['false_action']['status'], 'Other branch not skipped.' );
route_check( true === $yes['results']['state']['fixture'], 'Provided fixture flag missing.' );
route_check( false === $yes['results']['true_action']['fixture'], 'Action fixture invented.' );
$no = $planner->inspect( $graph, [ 'state' => [ 'decision' => false ] ] );
route_check( $no['complete'] && 'simulated' === $no['results']['false_action']['status'], 'False route incomplete.' );

$blocked = $planner->inspect( $graph, [] );
route_check( ! $blocked['complete'] && 'choice' === $blocked['blocked'], 'Missing fixture did not block condition.' );
route_check( 'blocked' === $blocked['results']['choice']['status']
	&& 'sample_required' === $blocked['reason'], 'Missing fixture incorrectly classified.' );
route_check( 'skipped' === $blocked['results']['end']['status']
	&& [ 'trigger', 'state', 'choice' ] === $blocked['nodes'], 'Downstream falsely simulated.' );

$failed = $planner->inspect( $graph, [ 'state' => [ 'decision' => 'invalid' ] ] );
route_check( ! $failed['complete'] && 'failed' === $failed['results']['choice']['status']
	&& 'condition_invalid' === $failed['reason'], 'Bad condition not classified.' );
route_check( ! isset( $yes['results']['state']['value'] )
	&& ! isset( $yes['results']['state']['output'] ), 'Raw values leaked.' );
echo "ADV-03B-R2 read-only diagnostic runner: PASS\n";
