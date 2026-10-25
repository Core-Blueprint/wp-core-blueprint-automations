<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$files = [
 'src/Persistence/Schema.php', 'src/Runtime/TriggerKey.php',
 'src/Organisation/FolderRepository.php', 'src/Plugin.php',
 'src/Support/Requirements.php', 'core-blueprint-automations.php',
];
$source = [];
foreach ($files as $p) { $source[$p] = file_get_contents($root . '/' . $p); if (!is_string($source[$p])) { throw new RuntimeException('Missing ' . $p); } }
$all = implode("\n", $source);
foreach (['function upgrade_from(', 'function migrate_pre_v2_to_v2(', 'function maybe_upgrade(', 'function from_encoded_json(', 'function bootstrap_issues(', 'function bootstrap_ready(', 'function runtime_issues(', 'cb_automations_folders_schema_version', "CB_AUTOMATIONS_DB_VERSION', '2'", 'dbDelta('] as $bad) {
 if (str_contains($all, $bad)) { throw new RuntimeException('Historical v1 code remains: ' . $bad); }
}
if (!str_contains(file_get_contents($root.'/src/Workflow/Recovery/WorkflowRecoveryPlanner.php'), 'unsafe_trigger_bindings(')) {throw new RuntimeException('Unsafe trigger privacy fence lost');}
echo "A-INF-S1 no legacy: PASS\n";
