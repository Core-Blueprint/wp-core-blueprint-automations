<?php
declare(strict_types=1);
$r=dirname(__DIR__,2);
$s=file_get_contents($r.'/src/Persistence/Schema.php');
$l=file_get_contents($r.'/src/Lifecycle.php');
$p=file_get_contents($r.'/src/Plugin.php');
foreach(['PRE_DDL','DDL_MAY_HAVE_STARTED','add_option(', 'option_value = %s', 'CREATE TABLE', 'attempt','get_current_blog_id','wp_cache_delete'] as $v){if(!str_contains($s,$v)){throw new RuntimeException('Installation fencing missing: '.$v);}}
foreach(['dbDelta(', 'CREATE TABLE IF NOT EXISTS', 'ALTER TABLE ', 'DROP TABLE '] as $v){if(str_contains($s,$v)){throw new RuntimeException('Unsafe SQL path: '.$v);}}
if(!str_contains($l,'Schema::install_new()') || !str_contains($l,'Persistence setup is on HOLD.')){throw new RuntimeException('Schema HOLD is not a reachable non-deactivation path');}
if(!str_contains($p,'SchemaSetupController::init()')){throw new RuntimeException('No accessible reauthorization route');}
echo "A-INF-S1 installation boundary: PASS\n";

require_once __DIR__.'/ainf-s1-admission.php';
function add_option(string $name,mixed $value,string $deprecated='',bool $autoload=false): bool {
    $db=$GLOBALS['wpdb'];
    if(array_key_exists($name,$db->optionsStore)){return false;}
    $db->optionsStore[$name]=(string)$value;
    return true;
}
function wp_cache_delete(string $name,string $group=''): bool {return true;}

$schema='CB\\Automations\\Persistence\\Schema';
$fake->prefix='wp_';$fake->last_error='';$fake->tables=[];$fake->optionsStore=[];
$fake->fixtureMap=$fixtures;$fake->creates=0;$fake->mutations=0;$fake->queries=[];
if(!$schema::install_new()){throw new RuntimeException('Fresh WordPress activation did not reach READY');}
if($fake->creates!==9 || $schema::inspect()!=='READY'){
    throw new RuntimeException('Fresh create-only nine-table install not attested');
}
if(!$schema::install_new() || $fake->creates!==9){throw new RuntimeException('Reactivation illegally ran extra DDL');}
foreach($fake->queries as $sql){
    if(str_starts_with($sql,'CREATE ') && !preg_match('/^CREATE TABLE `[A-Za-z0-9_]+`/', $sql)){
        throw new RuntimeException('Non-create-only table statement');
    }
}
$fake->tables=[];$fake->optionsStore=[];$fake->creates=0;$fake->mutations=0;$fake->queries=[];
$claimIdentity=new ReflectionMethod($schema,'claim_identity');
$identity=new ReflectionMethod($schema,'identity');
$site=$identity->invoke(null);
$claim=['identity'=>$claimIdentity->invoke(null,$site,$definitions),'token'=>str_repeat('1',64),'attempt'=>1,'phase'=>'PRE_DDL'];
$fake->optionsStore['cb_automations_install_v1_claim']=json_encode($claim,JSON_THROW_ON_ERROR);
if(!$schema::can_reauthorize()){throw new RuntimeException('Proven PRE_DDL claim cannot reauthorize');}
if($schema::install_new(false)){throw new RuntimeException('PRE_DDL implicitly retried without authorization');}
if(!$schema::install_new(true)){throw new RuntimeException('Authorized PRE_DDL cannot proceed');}
$claim=json_decode($fake->optionsStore['cb_automations_install_v1_claim'],true);
if($claim['phase']!=='DDL_MAY_HAVE_STARTED' || $claim['attempt']!==2 || $claim['token']===str_repeat('1',64)){
    throw new RuntimeException('No durable PRE_DDL rotation/first-DDL barrier');
}
$fake->tables=[];$fake->optionsStore=[];$fake->creates=0;$fake->queries=[];
$fake->optionsStore['cb_automations_install_v1_claim']=json_encode([
    'identity'=>$claimIdentity->invoke(null,$site,$definitions),'token'=>str_repeat('2',64),
    'attempt'=>1,'phase'=>'DDL_MAY_HAVE_STARTED'
]);
if($schema::install_new(true) || $fake->creates!==0){throw new RuntimeException('DDL barrier could be reauthorized unsafely');}
$fake->tables=[];$fake->optionsStore=[];$fake->creates=0;$fake->queries=[];$fake->last_error='';
$fake->raceCreate=true;
if($schema::install_new() || $fake->creates!==0){throw new RuntimeException('Concurrent table race resumed CREATE');}
if(!isset($fake->tables['wp_cb_automations_workflows']) || count($fake->tables)!==1){
    throw new RuntimeException('Physical collision changed expected table count');
}
echo "A-INF-S1 behavioral installation: PASS (create-only, 9x, PRE_DDL CAS, barrier, race)\n";

// A valid workflow table supports disabled draft authoring, even if runtime is partial.
$fake->last_error = '';
$fake->tables = [ 'wp_cb_automations_workflows' => $fixtures['wp_cb_automations_workflows'] ];
$fake->optionsStore = [];
if (!$schema::authoring_ready() || $schema::folders_ready() || $schema::ready()) {
    throw new RuntimeException('Authoring incorrectly coupled to runtime tables');
}
if ($schema::can_repair()) { throw new RuntimeException('Unclaimed partial site must not run DDL'); }
$fake->tables['wp_cb_automations_workflows']['engine'] = 'MyISAM';
if ($schema::authoring_ready()) { throw new RuntimeException('Unsafe workflow table admitted authoring'); }

// Missing metadata on nine physically verified tables can be attested on POST.
$fake->tables = $fixtures;
$fake->optionsStore = [];
if (!$schema::authoring_ready() || !$schema::folders_ready() || $schema::ready() || !$schema::can_repair()) {
    throw new RuntimeException('Missing marker should be eligible for positive table attestation');
}
if (!$schema::repair_verified() || !$schema::ready()) {
    throw new RuntimeException('Physical attestation did not restore readiness');
}

// Interrupted first installation can add only missing tables, after positive checks.
$fake->tables = array_slice($fixtures, 0, 7, true);
$fake->optionsStore = [
    'cb_automations_install_v1_claim' => json_encode([
        'identity' => $claimIdentity->invoke(null, $site, $definitions),
        'token' => str_repeat('3', 64),
        'attempt' => 1,
        'phase' => 'DDL_MAY_HAVE_STARTED',
    ], JSON_THROW_ON_ERROR),
];
$fake->creates = 0;
$fake->last_error = '';
if (!$schema::can_repair() || !$schema::repair_verified() || $fake->creates !== 2 || !$schema::ready()) {
    throw new RuntimeException('Explicit verified first-install repair did not complete safely');
}
$fake->tables = array_slice($fixtures, 0, 7, true);
if ($schema::can_repair()) {
    throw new RuntimeException('Missing tables from READY site must never be recreated');
}
echo "P0 physical authoring isolation and verified repair fixtures: PASS\n";
