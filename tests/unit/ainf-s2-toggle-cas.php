<?php
declare(strict_types=1);

namespace CB\Automations\Persistence {
    class PersistenceFailure extends \RuntimeException {}
}
namespace CB\Automations\Workflow {
    enum ActivationState: string { case Enabled = 'enabled'; case Disabled = 'disabled'; }
    final class ExecutionAuthorityDecision {
        public const PRINCIPAL_MISSING = 'principal_missing';
        public const PRINCIPAL_INVALID = 'principal_invalid';
        public const PRINCIPAL_PERMISSION_DENIED = 'principal_permission_denied';
        public const OPERATOR_PERMISSION_DENIED = 'operator_permission_denied';
        public const CAPABILITY_UNAVAILABLE = 'capability_unavailable';
    }
    final class WorkflowSaveResult {
        public const SAVED = 'saved';
        public const SAVED_DISABLED = 'saved_disabled';
        public const CONFLICT = 'conflict';
        public const READBACK_UNVERIFIED = 'readback_unverified';
        public const PERSISTENCE_BLOCKED = 'persistence_blocked';
        public const EXECUTION_UNAVAILABLE = 'execution_unavailable';
        public function __construct(private string $status) {}
        public function status(): string { return $this->status; }
        public function activation_block_reason(): ?string { return null; }
    }
    final class FakeRecord {
        public function __construct(public int $currentRevision, public ActivationState $currentState) {}
        public function id(): int { return 7; }
        public function revision(): int { return $this->currentRevision; }
        public function name(): string { return 'Verified workflow'; }
        public function definition(): object { return (object)['current'=>true]; }
        public function activation_state(): ActivationState { return $this->currentState; }
    }
    final class WorkflowService {
        public function find(int $id): ?FakeRecord {
            ++$GLOBALS['service_reads'];
            return $GLOBALS['record'];
        }
        public function save(int $id, int $revision, string $name, ActivationState $state,
            object $definition, int $user_id, bool $rebind = false): WorkflowSaveResult {
            $GLOBALS['service_saves'][] = [$id,$revision,$state->value,$user_id,$rebind];
            if ($GLOBALS['result_status'] === 'throw_after_possible_commit') {
                throw new \RuntimeException('simulated lost database ACK after attempted SQL CAS');
            }
            return new WorkflowSaveResult($GLOBALS['result_status']);
        }
    }
}
namespace CB\Automations\Admin {
    class AutomationsPage {
        public const CAPABILITY = 'manage_options';
        public static function url(array $params=[]): string { return '/wp-admin/admin.php?notice=' . ($params['notice'] ?? ''); }
    }
}
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    class TestStop extends \RuntimeException {
        public function __construct(public int $http, public string $path='') { parent::__construct($path); }
    }
    function current_user_can(string $cap): bool { return $GLOBALS['allow']; }
    function get_current_user_id(): int { return 31; }
    function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid' && $action === 'cb_automations_toggle_workflow_7'; }
    function wp_unslash(string $s): string { return $s; }
    function absint(mixed $v): int { return abs((int)$v); }
    function sanitize_key(string $s): string { return strtolower($s); }
    function sanitize_text_field(string $s): string { return $s; }
    function esc_html__(string $s, string $domain): string { return $s; }
    function wp_die(string $message, string $title='', array $args=[]): never { throw new TestStop((int)($args['response'] ?? 500), 'die'); }
    function wp_safe_redirect(string $url): never { $GLOBALS['redirects'][] = $url; throw new TestStop(302, $url); }

    require_once dirname(__DIR__, 2) . '/src/Admin/WorkflowActivationController.php';
    $controller = \CB\Automations\Admin\WorkflowActivationController::class;
    $defaults = [
        'workflow_id' => '7', 'activation_state' => 'enabled', 'expected_revision' => '8',
        '_cb_automations_nonce' => 'valid'
    ];
    $run = static function (string $method, array $fields, int $current_revision=8,
        \CB\Automations\Workflow\ActivationState $state=\CB\Automations\Workflow\ActivationState::Disabled,
        string $result='saved', bool $allow=true) use ($controller): array {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $method === 'GET' ? $fields : [];
        $_POST = $fields;
        $GLOBALS['allow'] = $allow;
        $GLOBALS['record'] = new \CB\Automations\Workflow\FakeRecord($current_revision,$state);
        $GLOBALS['service_reads'] = 0;
        $GLOBALS['service_saves'] = [];
        $GLOBALS['result_status'] = $result;
        $GLOBALS['redirects'] = [];
        $code = null;
        try { $controller::toggle(); }
        catch (TestStop $e) { $code = $e->http; }
        return [$code,$GLOBALS['service_reads'],$GLOBALS['service_saves'],$GLOBALS['redirects']];
    };
    $assert = static function(bool $condition, string $why): void { if (!$condition) { throw new \RuntimeException($why); } };
    foreach (['GET','HEAD','OPTIONS','PUT','DELETE',''] as $method) {
        [$http,$reads,$saves] = $run($method, $defaults);
        $assert($http === 405 && $reads === 0 && count($saves) === 0,
            'S2-R03a: non-POST ' . $method . ' reached workflow data or failed to return 405');
    }
    foreach (['', '0', '-1', '08', '8.0', '8e0', '9223372036854775807','9999999999999999999999999'] as $bad) {
        [$http,$reads,$saves,$redirects] = $run('POST', array_replace($defaults,['expected_revision'=>$bad]));
        $assert($reads === 0 && count($saves)===0 && str_contains($redirects[0] ?? '', 'invalid'),
            'S2-R02 invalid decimal revision was accepted: '.$bad);
    }
    foreach ([null, [], ['8']] as $bad) {
        $fields=$defaults;
        if ($bad===null) {unset($fields['expected_revision']);} else {$fields['expected_revision']=$bad;}
        [$http,$reads,$saves,$redirects] = $run('POST',$fields);
        $assert($reads===0 && count($saves)===0 && str_contains($redirects[0] ?? '', 'invalid'),
            'S2-R02 missing/array revision reached persistence');
    }
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults,9);
    $assert($reads===1 && !$saves && str_contains($redirects[0]??'','conflict'),
        'S2-R04 stale Enable was retried or accepted');
    [$http,$reads,$saves,$redirects] = $run('POST',array_replace($defaults,['activation_state'=>'disabled']),9,
        \CB\Automations\Workflow\ActivationState::Enabled);
    $assert($reads===1 && !$saves && str_contains($redirects[0]??'','conflict'),
        'S2-R05 stale Disable was retried or accepted');
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults,8,
        \CB\Automations\Workflow\ActivationState::Enabled);
    $assert($reads===1 && !$saves && str_contains($redirects[0]??'','invalid'),
        'S2-R08 no-op state was written');
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults);
    $assert($reads===1 && count($saves)===1 && $saves[0]===[7,8,'enabled',31,false]
        && str_contains($redirects[0]??'','enabled'),'S2-R09 exact approved R was not forwarded to canonical service');
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults,8,
        \CB\Automations\Workflow\ActivationState::Disabled,'readback_unverified');
    $assert(count($saves)===1 && str_contains($redirects[0]??'','activation_unverified'),
        'S2-R11 uncertain postwrite readback misreported as final failure/success');
    // SQL CAS may reject a concurrent writer after the precheck. Never retry with a newer R.
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults,8,
        \CB\Automations\Workflow\ActivationState::Disabled,'conflict');
    $assert(count($saves)===1 && $saves[0][1]===8 && str_contains($redirects[0]??'','conflict'),
        'S2-R07 service CAS conflict replaced submitted revision or retried');
    // A postwrite exception is not proof that SQL did not commit.
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults,8,
        \CB\Automations\Workflow\ActivationState::Disabled,'throw_after_possible_commit');
    $assert(count($saves)===1 && str_contains($redirects[0]??'','activation_unverified'),
        'S2-R11 ambiguous SQL outcome misreported as failed/saved');
    [$http,$reads,$saves,$redirects] = $run('POST',$defaults,8,
        \CB\Automations\Workflow\ActivationState::Disabled,'saved_disabled');
    $assert(count($saves)===1 && !str_contains($redirects[0]??'','enabled'),
        'S2-R12 saved-disabled falsely reported as enabled');
    [$http,$reads,$saves] = $run('POST',$defaults,8,
        \CB\Automations\Workflow\ActivationState::Disabled,'saved',false);
    $assert($http===403 && $reads===0 && count($saves)===0,'S2-R03 unauthorized actor reached workflow');
    [$http,$reads,$saves] = $run('POST',array_replace($defaults,['_cb_automations_nonce'=>'bad']));
    $assert($http===403 && $reads===0 && count($saves)===0,'S2-R03 invalid nonce reached workflow');
    echo "A-INF-S2 controller method/CAS behavioral tests: PASS\n";
}
