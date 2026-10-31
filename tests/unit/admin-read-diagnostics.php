<?php
declare(strict_types=1);

/**
 * P1 diagnostics: read-only WordPress admin fixture, no database or WordPress installation.
 * Exceptions deliberately contain private-looking text to prove it never reaches the UI/log.
 */
namespace CoreBlueprint\Core\Admin {
	interface Page {
		public function slug(): string;
		public function title(): string;
		public function menu_title(): string;
		public function capability(): string;
		public function position(): ?int;
		public function render(): void;
	}
}

namespace CB\Automations\Persistence {
	final class Schema {
		public static bool $authoring = true;
		public static function authoring_ready(): bool { return self::$authoring; }
	}
	final class WorkflowRepository {
		public static bool $fail_count = false;
		public static function count_index(string $search = '', string $status = 'all', ?int $folder_id = null): int {
			if (self::$fail_count) { throw new \RuntimeException('PRIVATE_DATABASE_SQL_MESSAGE'); }
			return 0;
		}
	}
}

namespace CB\Automations\Organisation {
	final class FolderRepository {
		public static bool $ready = false;
		public static string $failure = '';
		public static function is_ready(): bool { return self::$ready; }
		public static function all(): array {
			if ('all' === self::$failure) { throw new \RuntimeException('PRIVATE_FOLDER_QUERY_MESSAGE'); }
			return [];
		}
		public static function assignments(array $ids): array {
			if ('assignments' === self::$failure) { throw new \RuntimeException('PRIVATE_FOLDER_ASSIGNMENT_MESSAGE'); }
			return [];
		}
	}
}

namespace CB\Automations\Template {
	final class WorkflowTemplateRegistry {
		public static function all(): array { throw new \RuntimeException('PRIVATE_TEMPLATE_MESSAGE'); }
	}
}

namespace CB\Automations\Admin {
	final class WorkflowAdminReadService {
		public static string $failure = '';
		public function index(int $page = 1, int $per_page = 25, string $search = '', string $status = 'all', string $sort = 'updated_desc', ?int $folder_id = null): array {
			if ('index' === self::$failure) { throw new \RuntimeException('PRIVATE_WORKFLOW_QUERY_MESSAGE'); }
			if ('definition' === self::$failure) { throw \CB\Automations\Persistence\PersistenceFailure::definition(); }
			return [ 'items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 1 ];
		}
		public function detail(int $id): ?array { throw new \RuntimeException('PRIVATE_WORKFLOW_DETAIL_MESSAGE'); }
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	function current_user_can(string $cap): bool { return true; }
	function esc_html__(string $text, string $domain = ''): string { return $text; }
	function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
	function absint(mixed $value): int { return abs((int)$value); }
	function wp_unslash(string $value): string { return $value; }
	function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_-]/', '', $value)); }
	function sanitize_text_field(string $value): string { return $value; }

	$root = dirname(__DIR__, 2);
	require_once $root . '/src/Persistence/PersistenceFailure.php';
	require_once $root . '/src/Admin/AutomationsPage.php';

	$definition_failure = \CB\Automations\Persistence\PersistenceFailure::definition();
	if (!$definition_failure->is_definition_failure() || \CB\Automations\Persistence\PersistenceFailure::schema()->is_definition_failure()) {
		throw new \RuntimeException('Stored-definition discriminator is not exclusive.');
	}

	$log = tempnam(sys_get_temp_dir(), 'cb-auto-read-diagnostic-');
	if (false === $log) { throw new \RuntimeException('Cannot create temporary diagnostic log.'); }
	$original_log = ini_get('error_log');
	ini_set('log_errors', '1');
	ini_set('error_log', $log);

	$cases = [
		'SCHEMA_NOT_READY' => static function(): void {
			\CB\Automations\Persistence\Schema::$authoring = false;
		},
		'WORKFLOW_INDEX_FAILED' => static function(): void {
			\CB\Automations\Admin\WorkflowAdminReadService::$failure = 'index';
		},
		'WORKFLOW_COUNT_FAILED' => static function(): void {
			\CB\Automations\Persistence\WorkflowRepository::$fail_count = true;
		},
		'WORKFLOW_HYDRATION_FAILED' => static function(): void {
			\CB\Automations\Admin\WorkflowAdminReadService::$failure = 'definition';
		},
		'FOLDER_READ_FAILED' => static function(): void {
			\CB\Automations\Organisation\FolderRepository::$ready = true;
			\CB\Automations\Organisation\FolderRepository::$failure = 'all';
		},
		'FOLDER_ASSIGNMENTS_FAILED' => static function(): void {
			\CB\Automations\Organisation\FolderRepository::$ready = true;
			\CB\Automations\Organisation\FolderRepository::$failure = 'assignments';
		},
		'WORKFLOW_DETAIL_FAILED' => static function(): void {
			$_GET['workflow'] = '12';
		},
		'ADMIN_RENDER_FAILED' => static function(): void {
			$_GET['view'] = 'templates';
		},
	];

	try {
		foreach ($cases as $expected => $setup) {
			$_GET = [];
			\CB\Automations\Persistence\Schema::$authoring = true;
			\CB\Automations\Persistence\WorkflowRepository::$fail_count = false;
			\CB\Automations\Organisation\FolderRepository::$ready = false;
			\CB\Automations\Organisation\FolderRepository::$failure = '';
			\CB\Automations\Admin\WorkflowAdminReadService::$failure = '';
			file_put_contents($log, '');
			$setup();
			ob_start();
			(new \CB\Automations\Admin\AutomationsPage())->render();
			$html = (string)ob_get_clean();
			$actual = match ($expected) {
				'FOLDER_ASSIGNMENTS_FAILED' => 'FOLDER_READ_FAILED',
				'WORKFLOW_COUNT_FAILED' => 'WORKFLOW_INDEX_FAILED',
				default => $expected,
			};
			$marker = '<code data-cb-automations-diagnostic-code>' . $actual . '</code>';
			$log_content = (string)file_get_contents($log);
			if (!str_contains($html, $marker) || !str_contains($log_content, 'Admin page diagnostic: ' . $actual)) {
				throw new \RuntimeException('Unexpected diagnostic UI/log for ' . $expected);
			}
			if (!str_contains($html, 'No workflow data was changed.')) {
				throw new \RuntimeException('Privacy-safe fail-closed notice missing for ' . $expected);
			}
			if (str_contains($html . $log_content, 'PRIVATE_') || str_contains($html . $log_content, 'Stored workflow definition is malformed')) {
				throw new \RuntimeException('Exception contents leaked for ' . $expected);
			}
		}
	} finally {
		ini_set('error_log', (string)$original_log);
		unlink($log);
	}
	echo "Automations P1 diagnostic routes: PASS (8 simulated admin failure paths, private exception content excluded)\n";
}
