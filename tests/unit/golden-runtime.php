<?php
declare(strict_types=1);

require __DIR__ . '/golden-provider-set.php';

use CB\Automations\Runtime\BindingResolver;
use CB\Automations\Condition\OperatorCatalog;

$runtime_context = [
	'outputs' => [
		'trigger_1' => [
			'user_id'   => 13,
			'course_id' => 42,
		],
		'state_1' => [
			'status'                 => 'completed',
			'percentage'             => 100,
			'completed'              => true,
			'certificate_profile_id' => 77,
			'completion_date'        => '2026-09-12',
			'course_title'           => 'Golden Course',
			'completion_key'         => 'course-42-user-13-completion-1',
		],
	],
];

$condition = $definition->conditions()[0] ?? null;
$action = $definition->actions()[0] ?? null;
if ( null === $condition || null === $action ) {
	throw new RuntimeException( 'Golden runtime workflow fixture is incomplete.' );
}

$left = BindingResolver::resolve( $condition->left(), $runtime_context );
$right_binding = $condition->right();
$right = null === $right_binding ? null : BindingResolver::resolve( $right_binding, $runtime_context );
if ( ! OperatorCatalog::evaluate( $condition->operator(), $left, $right ) ) {
	throw new RuntimeException( 'Golden certificate eligibility condition did not match runtime state output.' );
}

$inputs = BindingResolver::resolve_all( $action->bindings(), $runtime_context );
$expected = [
	'profile_id'      => 77,
	'user_id'         => 13,
	'completion_date' => '2026-09-12',
	'title'           => 'Golden Course',
	'source_type'     => 'cb_lms',
	'source_id'       => 'course-42-user-13-completion-1',
];
if ( $expected !== $inputs ) {
	throw new RuntimeException( 'Golden LMS → Certificates runtime bindings drifted.' );
}

$root = dirname( __DIR__, 2 );
$pipeline = file_get_contents( $root . '/src/Runtime/RunPipeline.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
if ( false === $pipeline || false === $worker ) {
	throw new RuntimeException( 'Could not read Golden runtime orchestration sources.' );
}
if ( ! str_contains( $pipeline, 'StatePhaseRunner::run(' ) || ! str_contains( $pipeline, 'ConditionPhaseRunner::run(' ) || ! str_contains( $pipeline, 'ActionPhaseRunner::run(' ) ) {
	throw new RuntimeException( 'Golden runtime does not pass through all canonical execution phases.' );
}
if ( ! str_contains( $worker, 'JobSubjectType::Run' ) || ! str_contains( $worker, 'RunPipeline::execute(' ) ) {
	throw new RuntimeException( 'Durable run jobs are not connected to the Golden runtime pipeline.' );
}

fwrite( STDOUT, "Golden runtime bindings: PASS\n" );
