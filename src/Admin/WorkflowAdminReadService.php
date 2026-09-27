<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Validation\ValidationState;
use CB\Automations\Validation\WorkflowValidator;

defined( 'ABSPATH' ) || exit;

final class WorkflowAdminReadService {
	private WorkflowValidator $validator;

	public function __construct( ?CapabilityCatalog $catalog = null ) {
		$this->validator = new WorkflowValidator( $catalog ?? new CapabilityCatalog() );
	}

	/**
	 * @return array{
	 *   items:array<int,array{record:WorkflowRecord,validation:ValidationResult,state:ValidationState}>,
	 *   total:int,page:int,per_page:int,pages:int
	 * }
	 */
	public function index( int $page = 1, int $per_page = 25 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$total    = WorkflowRepository::count();
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $page, $pages );
		$offset   = ( $page - 1 ) * $per_page;

		$items = [];
		foreach ( WorkflowRepository::list( $per_page, $offset ) as $record ) {
			$validation = $this->validator->validate( $record->definition() );
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
			$batch = WorkflowRepository::list( 100, $offset );
			if ( [] === $batch ) {
				break;
			}

			foreach ( $batch as $record ) {
				$items[] = [
					'id'   => $record->id(),
					'name' => $record->name(),
				];
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

		$validation = $this->validator->validate( $record->definition() );
		return [
			'record'     => $record,
			'validation' => $validation,
			'state'      => ValidationState::from_result( $validation ),
		];
	}
}
