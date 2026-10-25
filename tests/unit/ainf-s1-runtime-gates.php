<?php
declare(strict_types=1);
$r=dirname(__DIR__,2);
foreach ([
'src/Support/Requirements.php'=>['Schema::ready()', 'execution_ready'],
'src/Plugin.php'=>['Schema::ready()', 'RuntimeWorker::init()', 'TriggerIntake::init()'],
'src/Workflow/WorkflowService.php'=>['Schema::ready()'],
'src/Persistence/WorkflowRepository.php'=>['Schema::ready()'],
'src/Organisation/FolderRepository.php'=>['Schema::ready()'],
'src/Runtime/OperatorRecoveryService.php'=>['Schema::ready()'],
] as $f=>$parts){$s=file_get_contents($r.'/'.$f);foreach($parts as $p){if(!str_contains($s,$p)){throw new RuntimeException("Unfenced $f missing $p");}}}
// Direct folder mutators must independently enforce readiness, not only POST controllers.
$folder = file_get_contents($r . '/src/Organisation/FolderRepository.php');
if (!is_string($folder)) { throw new RuntimeException('Missing folder source'); }
foreach (['create','rename','delete','assign'] as $operation) {
    if (!preg_match('/public static function '. $operation .'\([^)]*\): (?:int|bool)\s*\{\s*self::ensure_ready\(\);/s', $folder)) {
        throw new RuntimeException('S1-R12 direct folder ' . $operation . ' bypasses READY');
    }
}
echo "A-INF-S1 runtime gates: PASS\n";
