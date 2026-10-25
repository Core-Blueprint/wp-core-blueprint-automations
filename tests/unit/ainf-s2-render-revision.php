<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$template = file_get_contents($root . '/templates/admin/automations.php');
$controller = file_get_contents($root . '/src/Admin/WorkflowActivationController.php');
if (!is_string($template) || !is_string($controller)) {
    throw new RuntimeException('A-INF-S2 source files unavailable.');
}
if (!preg_match('/name="expected_revision"\s+value="<\?php echo esc_attr\(\s*\(string\)\s*\$record->revision\(\)\s*\); \?>"/', $template)) {
    throw new RuntimeException('S2-R01: overview row does not emit exact escaped persisted render-time revision.');
}
if (!str_contains($controller, "\$_SERVER['REQUEST_METHOD']") || !str_contains($controller, "'POST'")) {
    throw new RuntimeException('S2-R03a: activation handler lacks explicit POST method guard.');
}
if (!str_contains($controller, "\$_POST['expected_revision']")) {
    throw new RuntimeException('S2-R01: activation handler does not parse submitted expected revision.');
}
if (str_contains($controller, "\$record->revision(),\n") && str_contains($controller, '->save(')) {
    throw new RuntimeException('S2-R04: service still uses fresh server revision in place of submitted R.');
}
echo "A-INF-S2 render revision source: PASS\n";
