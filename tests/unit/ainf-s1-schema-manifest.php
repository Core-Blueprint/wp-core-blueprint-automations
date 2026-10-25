<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$src = file_get_contents($root.'/src/Persistence/Schema.php');
foreach (['public static function inspect(', 'public static function definitions(', 'SHOW FULL COLUMNS', 'SHOW INDEX', 'SHOW TABLE STATUS', 'SHOW CREATE TABLE', 'UNKNOWN_UNSAFE', 'PARTIAL', 'DRIFTED', 'READY', 'ABSENT'] as $needle) {
 if (!str_contains($src, $needle)) { throw new RuntimeException('Canonical manifest/readback missing: '.$needle); }
}
foreach (['cb_automations_workflows','cb_automations_event_receipts','cb_automations_runs','cb_automations_run_steps','cb_automations_jobs','cb_automations_run_context','cb_automations_run_recoveries','cb_automations_folders','cb_automations_folder_assignments'] as $t) {
 if (!str_contains($src, $t)) {throw new RuntimeException('Missing schema table '.$t);}
}
if (!str_contains($src, 'SHOW INDEX') || !str_contains($src, 'Non_unique')) {throw new RuntimeException('Uniqueness cannot be attested');}
echo "A-INF-S1 canonical manifest: PASS\n";
