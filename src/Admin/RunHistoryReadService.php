<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Persistence\OperatorRecoveryRepository;
use CB\Automations\Persistence\RunHistoryRepository;
defined( 'ABSPATH' ) || exit;

final class RunHistoryReadService {
	private const PER_PAGE = 30;

	/** @return array{items:array,total:int,page:int,per_page:int,pages:int,workflow_id:int} */
	public function index( int $page = 1, int $workflow_id = 0 ): array {
		$page = max( 1, $page );
		$workflow_id = max( 0, $workflow_id );
		$total = RunHistoryRepository::count( $workflow_id );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page = min( $page, $pages );
		return [
			'items' => RunHistoryRepository::list( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE, $workflow_id ),
			'total' => $total,
			'page' => $page,
			'per_page' => self::PER_PAGE,
			'pages' => $pages,
			'workflow_id' => $workflow_id,
		];
	}

	/** @return array{run:array<string,mixed>,steps:array<int,array<string,mixed>>,recoveries:array<int,array<string,mixed>>}|null */
	public function detail( int $run_id ): ?array {
		$run = RunHistoryRepository::detail( $run_id );
		if ( null === $run ) {
			return null;
		}
		return [
			'run' => $run,
			'steps' => RunHistoryRepository::steps( $run_id ),
			'recoveries' => OperatorRecoveryRepository::history( $run_id ),
		];
	}
}
