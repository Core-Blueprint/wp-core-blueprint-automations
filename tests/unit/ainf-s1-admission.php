<?php
declare(strict_types=1);
$root = dirname(__DIR__,2);
$s = file_get_contents($root.'/src/Persistence/Schema.php');
foreach(['ABSENT', 'PARTIAL','DRIFTED','UNKNOWN_UNSAFE','READY','inspect(', 'last_error', 'SELECT DATABASE()','get_current_blog_id','schema_v1'] as $needle) {
 if(!str_contains($s,$needle)){throw new RuntimeException('Fail-closed readiness missing: '.$needle);}
}
echo "A-INF-S1 admission source fence: PASS\n";

// Read-only WPDB fixture. No WordPress installation and no physical SQL is used.
define('ABSPATH', '/fixture/');
if (!defined('ARRAY_A')) { define('ARRAY_A','ARRAY_A'); }
function get_current_blog_id(): int { return 1; }
final class AinfCatalogWpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $dbname = 'wp_fixture';
    public string $charset = 'utf8mb4';
    public string $collate = 'utf8mb4_unicode_ci';
    public string $last_error = '';
    public array $tables = [];
    public array $optionsStore = [];
    public array $lastArgs = [];
    public int $mutations = 0;
    public array $fixtureMap = [];
    public bool $raceCreate = false;
    public int $creates = 0;
    public array $queries = [];
    public function get_charset_collate(): string {return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';}
    public function esc_like(string $s): string {return addcslashes($s,'_%\\');}
    public function prepare(string $format, mixed ...$args): string {
        $this->lastArgs=$args;
        $i=0;
        return preg_replace_callback('/%[sd]/', static function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%s' ? "'".str_replace("'","''",(string)$v)."'" : (string)(int)$v;}, $format);
    }
    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        $this->mutations++;
        if(str_starts_with($sql,'UPDATE `wp_options` SET option_value')){
            [$new,$name,$before] = $this->lastArgs;
            if(($this->optionsStore[$name]??null)!==$before){return 0;}
            $this->optionsStore[$name]=$new;
            return 1;
        }
        if(str_starts_with($sql,'CREATE TABLE `')){
            if(!preg_match('/^CREATE TABLE `([a-z0-9_]+)`/', $sql,$m)){return false;}
            $table=$m[1];
            if($this->raceCreate){$this->raceCreate=false;$this->tables[$table]=$this->fixtureMap[$table];}
            if(isset($this->tables[$table])){$this->last_error='table exists';return false;}
            $this->tables[$table]=$this->fixtureMap[$table];
            $this->creates++;
            return 1;
        }
        throw new RuntimeException('Unexpected SQL mutation '.$sql);
    }
    public function get_var(string $sql): ?string {
        if ($sql==='SELECT DATABASE()'){return $this->dbname;}
        if (str_contains($sql,'SELECT option_value')) {return $this->optionsStore[$this->lastArgs[0]??'']??null;}
        throw new RuntimeException('Unexpected catalog scalar '.$sql);
    }
    public function get_col(string $sql): array {
        if (!str_starts_with($sql,'SHOW TABLES LIKE')){throw new RuntimeException('Unexpected census');}
        return array_keys($this->tables);
    }
    private function tableInSql(string $sql): string {
        if(preg_match('/`(wp_[a-z0-9_]+)`/', $sql,$m)){return $m[1];}
        return str_replace('\\_','_',(string)($this->lastArgs[0]??''));
    }
    public function get_row(string $sql, mixed $output=null): ?array {
        $t=$this->tableInSql($sql);
        if (!isset($this->tables[$t])){return null;}
        if(str_starts_with($sql,'SHOW TABLE STATUS')){return ['Name'=>$t,'Engine'=>$this->tables[$t]['engine']??'InnoDB','Collation'=>$this->collate];}
        if(str_starts_with($sql,'SHOW CREATE TABLE')){return ['Create Table'=>$this->tables[$t]['sql']];}
        throw new RuntimeException('Unexpected catalog row '.$sql);
    }
    public function get_results(string $sql, mixed $output=null): array {
        $t=$this->tableInSql($sql);
        if (!isset($this->tables[$t])){return [];}
        $spec=$this->tables[$t];
        if(str_starts_with($sql,'SHOW FULL COLUMNS')){
            $rows=[];
            foreach($spec['columns'] as $key=>$c){$rows[]=['Field'=>$key,'Type'=>$c['type'],'Null'=>$c['null'],'Default'=>$c['default'],'Extra'=>$c['extra'],
                'Collation'=>preg_match('/^(?:varchar|char|longtext)/',$c['type']) ? $this->collate : null];}
            return $rows;
        }
        if(str_starts_with($sql,'SHOW INDEX')){
            $rows=[];
            foreach($spec['indexes'] as $name=>$index){foreach($index['columns'] as $i=>$column){$rows[]=['Key_name'=>$name,'Seq_in_index'=>$i+1,'Column_name'=>$column,
                'Non_unique'=>$index['unique']?0:1,'Index_type'=>'BTREE','Sub_part'=>null,'Collation'=>'A'];}}
            return $rows;
        }
        throw new RuntimeException('Unexpected catalog result '.$sql);
    }
}
$fake=new AinfCatalogWpdb();
$GLOBALS['wpdb']=$fake;
require_once $root.'/src/Persistence/Schema.php';
$schema='CB\\Automations\\Persistence\\Schema';
$spec=new ReflectionMethod($schema,'specification');
$fingerprint=new ReflectionMethod($schema,'fingerprint');
$definitions=$schema::definitions();
if(count($definitions)!==9){throw new RuntimeException('Expected nine canonical tables');}
$sum=0;
foreach($definitions as $name=>$sql){
    [$columns,$indexes]=$spec->invoke(null,$sql);
    $sum+=count($indexes);
    $physical='wp_cb_automations_'.(['receipts'=>'event_receipts','steps'=>'run_steps','context'=>'run_context','recoveries'=>'run_recoveries','assignments'=>'folder_assignments'][$name]??$name);
    $fixtures[$physical]=['sql'=>$sql,'columns'=>$columns,'indexes'=>$indexes];
}
if($sum!==28){throw new RuntimeException('Expected 28 canonical keys, found '.$sum);}
$assert=function(string $expected, string $case) use($schema):void{
    $actual=$schema::inspect();
    if($expected!==$actual){throw new RuntimeException("$case: expected $expected, got $actual");}
};
$assert('ABSENT','empty-site');
$fake->tables=array_slice($fixtures,0,7,true);
$assert('PARTIAL','seven-of-nine');
$fake->tables=$fixtures;
$assert('DRIFTED','nine-but-no-marker');
$fake->optionsStore['cb_automations_schema_v1']=$fingerprint->invoke(null,$definitions);
$assert('READY','exact-nine-table-metadata');
unset($fake->tables['wp_cb_automations_event_receipts']['indexes']['event_fingerprint']);
$assert('DRIFTED','missing-unique-event-fingerprint');
$fake->tables=$fixtures;
$fake->tables['wp_cb_automations_jobs']['indexes']['subject']['unique']=false;
$assert('DRIFTED','missing-unique-subject');
$fake->tables=$fixtures;
$fake->tables['wp_cb_automations_runs']['indexes']['event_workflow']['columns']=array_reverse($fake->tables['wp_cb_automations_runs']['indexes']['event_workflow']['columns']);
$assert('DRIFTED','wrong-unique-key-order');
$fake->tables=$fixtures;
$fake->tables['wp_cb_automations_workflows']['engine']='MyISAM';
$assert('DRIFTED','wrong-engine');
$fake->tables=$fixtures;
$fake->last_error='privilege refused';
$assert('UNKNOWN_UNSAFE','metadata-query-error');
$fake->last_error='';
$fake->prefix='other_';
$assert('UNKNOWN_UNSAFE','site-prefix-drift');
if($fake->mutations!==0){throw new RuntimeException('Inspector performed physical mutation');}
echo "A-INF-S1 behavioral catalog: PASS (9 tables, 28 indexes, uniqueness/identity/engine negatives)\n";
