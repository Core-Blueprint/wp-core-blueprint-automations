<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Validation\ValidationState;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Validation\GraphValidator;
use CB\Automations\Validation\ValidationIssue;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

final class WorkflowAdminReadService {
	private WorkflowValidator $validator;
	private GraphValidator $graph_validator;

	public function __construct( ?CapabilityCatalog $catalog = null ) {
		$source = $catalog ?? new CapabilityCatalog();
		$this->validator = new WorkflowValidator( $source );
		$this->graph_validator = new GraphValidator( $source );
	}

	/**
	 * @return array{
	 *   items:array<int,array{record:WorkflowRecord,validation:ValidationResult,state:ValidationState}>,
	 *   total:int,page:int,per_page:int,pages:int
	 * }
	 */
	public function index( int $page = 1, int $per_page = 25, string $search = '', string $status = 'all', string $sort = 'updated_desc', ?int $folder_id = null ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$total    = WorkflowRepository::count_index( $search, $status, $folder_id );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $page, $pages );
		$offset   = ( $page - 1 ) * $per_page;

		$items = [];
		foreach ( WorkflowRepository::query_index( $per_page, $offset, $search, $status, $sort, $folder_id ) as $record ) {
			$validation = $this->validate_record( $record );
			$items[]    = [
				'record'     => $record,
				'validation' => $validation,
				'state'      => ValidationState::from_result( $validation ),
			];
		}

		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		];
	}

	/**
	 * Return the complete lightweight workflow list for Designer context switching.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	public function context_items(): array {
		$total  = WorkflowRepository::count();
		$offset = 0;
		$items  = [];

		while ( $offset < $total ) {
			$batch = WorkflowRepository::list_context_metadata( 100, $offset );
			if ( [] === $batch ) {
				break;
			}
			foreach ( $batch as $metadata ) {
				$items[] = $metadata;
			}

			$count = count( $batch );
			$offset += $count;
			if ( $count < 100 ) {
				break;
			}
		}

		return $items;
	}

	/** @return array{record:WorkflowRecord,validation:ValidationResult,state:ValidationState}|null */
	public function detail( int $id ): ?array {
		$record = WorkflowRepository::find( $id );
		if ( null === $record ) {
			return null;
		}

		$validation = $this->validate_record( $record );
		return [
			'record'     => $record,
			'validation' => $validation,
			'state'      => ValidationState::from_result( $validation ),
		];
	}
	private function validate_record( WorkflowRecord $record ): ValidationResult {
		if ( $record->definition() instanceof GraphDefinition ) {
			$result = $this->graph_validator->validate( $record->definition() );
			return new ValidationResult( [ ...$result->issues(), new ValidationIssue( 'graph.execution_unavailable', 'definition_version' ) ] );
		}
		return $this->validator->validate( $record->definition() );
	}
}
