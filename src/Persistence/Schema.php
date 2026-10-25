<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

defined( 'ABSPATH' ) || exit;

/** The only Automations physical schema authority. No previous-version readers or DDL upgrades. */
final class Schema {
    public const ABSENT = 'ABSENT';
    public const PARTIAL = 'PARTIAL';
    public const DRIFTED = 'DRIFTED';
    public const READY = 'READY';
    public const UNKNOWN_UNSAFE = 'UNKNOWN_UNSAFE';
    private const MARKER = 'cb_automations_schema_v1';
    private const CLAIM = 'cb_automations_install_v1_claim';
    private const SUFFIXES = [
        'workflows' => 'cb_automations_workflows',
        'receipts' => 'cb_automations_event_receipts',
        'runs' => 'cb_automations_runs',
        'steps' => 'cb_automations_run_steps',
        'jobs' => 'cb_automations_jobs',
        'context' => 'cb_automations_run_context',
        'recoveries' => 'cb_automations_run_recoveries',
        'folders' => 'cb_automations_folders',
        'assignments' => 'cb_automations_folder_assignments',
    ];

    public static function table(): string { return self::workflows_table(); }
    public static function workflows_table(): string { return self::table_name('workflows'); }
    public static function event_receipts_table(): string { return self::table_name('receipts'); }
    public static function runs_table(): string { return self::table_name('runs'); }
    public static function run_steps_table(): string { return self::table_name('steps'); }
    public static function jobs_table(): string { return self::table_name('jobs'); }
    public static function run_context_table(): string { return self::table_name('context'); }
    public static function run_recoveries_table(): string { return self::table_name('recoveries'); }
    public static function folders_table(): string { return self::table_name('folders'); }
    public static function assignments_table(): string { return self::table_name('assignments'); }

    private static function table_name(string $key): string {
        global $wpdb;
        return (string) $wpdb->prefix . self::SUFFIXES[$key];
    }

    /** Exact, sole CREATE declarations for all nine canonical v1 tables. */
    public static function definitions(): array {
        global $wpdb;
        $charset = (string) $wpdb->get_charset_collate();
        if ( !preg_match('/^\s*DEFAULT CHARACTER SET [a-z0-9_]+(?: COLLATE [a-z0-9_]+)?\s*$/D', trim($charset)) ) {
            throw new \RuntimeException('Unsafe site charset definition.');
        }
        foreach (self::SUFFIXES as $key => $suffix) {
            $name = (string) $wpdb->prefix . $suffix;
            if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
                throw new \RuntimeException('Unsafe site table identity.');
            }
            ${$key} = '`' . $name . '`';
        }
        return [
            'workflows' => "CREATE TABLE {$workflows} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				activation_state varchar(16) NOT NULL DEFAULT 'disabled',
				definition_version int unsigned NOT NULL DEFAULT 1,
				definition_json longtext NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				execution_principal_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				trigger_key char(64) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				KEY activation_state (activation_state),
				KEY trigger_lookup (activation_state, trigger_key),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB {$charset};",
            'receipts' => "CREATE TABLE {$receipts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_fingerprint char(64) NOT NULL,
				event_id varchar(128) NOT NULL,
				provider varchar(191) NOT NULL,
				trigger_id varchar(191) NOT NULL,
				schema_version varchar(20) NOT NULL,
				occurred_at datetime(6) NOT NULL,
				payload_envelope longtext NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'received',
				created_at datetime(6) NOT NULL,
				materialized_at datetime(6) NULL DEFAULT NULL,
				purged_at datetime(6) NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_fingerprint (event_fingerprint),
				KEY status_created (status, created_at)
			) ENGINE=InnoDB {$charset};",
            'runs' => "CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_uuid char(36) NOT NULL,
				correlation_id char(36) NOT NULL,
				event_receipt_id bigint(20) unsigned NOT NULL,
				workflow_id bigint(20) unsigned NOT NULL,
				workflow_revision bigint(20) unsigned NOT NULL,
				execution_principal_user_id bigint(20) unsigned NOT NULL,
				definition_version int unsigned NOT NULL,
				definition_json longtext NOT NULL,
				definition_hash char(64) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'queued',
				run_cursor varchar(128) NOT NULL DEFAULT '',
				failure_code varchar(191) NOT NULL DEFAULT '',
				created_at datetime(6) NOT NULL,
				started_at datetime(6) NULL DEFAULT NULL,
				finished_at datetime(6) NULL DEFAULT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_uuid (run_uuid),
				UNIQUE KEY event_workflow (event_receipt_id, workflow_id),
				KEY workflow_created (workflow_id, created_at),
				KEY status_updated (status, updated_at)
			) ENGINE=InnoDB {$charset};",
            'steps' => "CREATE TABLE {$steps} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				node_id varchar(64) NOT NULL,
				node_type varchar(16) NOT NULL,
				provider varchar(191) NOT NULL DEFAULT '',
				capability_id varchar(191) NOT NULL DEFAULT '',
				schema_version varchar(20) NOT NULL DEFAULT '',
				attempt int unsigned NOT NULL DEFAULT 1,
				status varchar(24) NOT NULL,
				error_code varchar(191) NOT NULL DEFAULT '',
				provider_error_code varchar(191) NOT NULL DEFAULT '',
				started_at datetime(6) NULL DEFAULT NULL,
				finished_at datetime(6) NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY node_attempt (run_id, node_type, node_id, attempt),
				KEY run_status (run_id, status)
			) ENGINE=InnoDB {$charset};",
            'jobs' => "CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subject_type varchar(16) NOT NULL,
				subject_id bigint(20) unsigned NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'queued',
				available_at datetime(6) NOT NULL,
				lease_token char(64) NOT NULL DEFAULT '',
				lease_expires_at datetime(6) NULL DEFAULT NULL,
				worker_attempts int unsigned NOT NULL DEFAULT 0,
				last_error_code varchar(191) NOT NULL DEFAULT '',
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY subject (subject_type, subject_id),
				KEY claimable (status, available_at),
				KEY lease_expires_at (lease_expires_at)
			) ENGINE=InnoDB {$charset};",
            'context' => "CREATE TABLE {$context} (
				run_id bigint(20) unsigned NOT NULL,
				context_envelope longtext NOT NULL,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (run_id)
			) ENGINE=InnoDB {$charset};",
            'recoveries' => "CREATE TABLE {$recoveries} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				node_id varchar(64) NOT NULL,
				attempt int unsigned NOT NULL,
				decision varchar(32) NOT NULL,
				operator_user_id bigint(20) unsigned NOT NULL,
				previous_status varchar(24) NOT NULL,
				resulting_status varchar(24) NOT NULL,
				previous_cursor varchar(128) NOT NULL,
				resulting_cursor varchar(128) NOT NULL,
				created_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_node_attempt (run_id, node_id, attempt),
				KEY run_created (run_id, created_at),
				KEY operator_created (operator_user_id, created_at)
			) ENGINE=InnoDB {$charset};",
            'folders' => "CREATE TABLE {$folders} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name (name)
			) ENGINE=InnoDB {$charset};",
            'assignments' => "CREATE TABLE {$assignments} (
				workflow_id bigint(20) unsigned NOT NULL,
				folder_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (workflow_id),
				KEY folder_id (folder_id)
			) ENGINE=InnoDB {$charset};"
        ];
    }

    private static function identity(): array {
        global $wpdb;
        $prefix = (string) ($wpdb->prefix ?? '');
        $options = (string) ($wpdb->options ?? '');
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $prefix) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $options)
            || !function_exists('get_current_blog_id')) { throw new \RuntimeException('Unknown WordPress site identity.'); }
        $blog = get_current_blog_id();
        $database = $wpdb->get_var('SELECT DATABASE()');
        if (!is_int($blog) || $blog < 1 || !is_string($database) || $database === ''
            || !empty($wpdb->last_error) || (isset($wpdb->dbname) && $wpdb->dbname !== $database)) {
            throw new \RuntimeException('Unverifiable database identity.');
        }
        return ['blog'=>$blog,'database'=>$database,'prefix'=>$prefix,'options'=>$options];
    }

    private static function option_raw(string $name, string $options): ?string {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$options}` WHERE option_name = %s", $name));
        if (!empty($wpdb->last_error) || (null !== $value && !is_string($value))) {
            throw new \RuntimeException('Unverifiable setup record.');
        }
        return $value;
    }

    private static function fingerprint(array $definitions): string {
        // Names/charset are tied to this specific site's canonical physical catalog.
        return 'clean-v1:' . hash('sha256', implode("\n", array_values($definitions)));
    }

    /** Non-mutating, independent of version options; all errors fail closed. */
    public static function inspect(): string {
        try { return self::inspect_strict(); }
        catch (\Throwable) { return self::UNKNOWN_UNSAFE; }
    }
    public static function ready(): bool { return self::READY === self::inspect(); }

    /** Disabled drafts require only a verified physical workflow table. */
    public static function authoring_ready(): bool {
        try {
            $id = self::identity();
            $defs = self::definitions();
            return self::matches_table(self::workflows_table(), $defs['workflows'], $id);
        } catch (\Throwable) { return false; }
    }

    /** Folder organisation is optional and isolated from draft persistence. */
    public static function folders_ready(): bool {
        try {
            $id = self::identity();
            $defs = self::definitions();
            return self::matches_table(self::folders_table(), $defs['folders'], $id)
                && self::matches_table(self::assignments_table(), $defs['assignments'], $id);
        } catch (\Throwable) { return false; }
    }

    /** Explicit installation also works after a ZIP update without activation. */
    public static function can_install_new(): bool {
        try {
            return self::ABSENT === self::inspect() && null === self::claim_raw(self::identity());
        } catch (\Throwable) { return false; }
    }

    private static function inspect_strict(): string {
        global $wpdb;
        $identity = self::identity();
        $definitions = self::definitions();
        $existing = self::existing_tables($identity);
        $count = count($existing);
        $marker = self::option_raw(self::MARKER, $identity['options']);
        $claim = self::option_raw(self::CLAIM, $identity['options']);
        if (null !== $claim) {
            $record = self::decode_claim($claim);
            if ($record['identity'] !== self::claim_identity($identity, $definitions)) { return self::UNKNOWN_UNSAFE; }
        }
        if (0 === $count) {
            if (null !== $marker) { return self::DRIFTED; }
            return null !== $claim && 'PRE_DDL' !== $record['phase'] ? self::UNKNOWN_UNSAFE : self::ABSENT;
        }
        if ($count < count($definitions)) { return self::PARTIAL; }
        if ($count !== count($definitions) || $marker !== self::fingerprint($definitions)) { return self::DRIFTED; }
        foreach ($definitions as $key => $sql) {
            if (!self::matches_table(self::table_name($key), $sql, $identity)) { return self::DRIFTED; }
        }
        return self::READY;
    }

    /** Positive census also detects unexpected product-owned/old tables. */
    private static function existing_tables(array $identity): array {
        global $wpdb;
        $pattern = $wpdb->esc_like($identity['prefix'] . 'cb_automations_') . '%';
        $rows = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
        if (!is_array($rows) || !empty($wpdb->last_error)) { throw new \RuntimeException('Table census unavailable.'); }
        $all = array_values(array_unique(array_map('strval', $rows)));
        $expected = array_map(fn(string $s): string => $identity['prefix'] . $s, self::SUFFIXES);
        foreach ($all as $table) {
            if (!in_array($table, $expected, true)) { throw new \RuntimeException('Unknown product-owned table.'); }
        }
        return $all;
    }

    /** Parse current canonical declaration, not a second hardcoded manifest. */
    private static function specification(string $sql): array {
        if (!preg_match('/CREATE TABLE\s+`[A-Za-z0-9_]+`\s*\((.*)\) ENGINE=InnoDB/s', $sql, $m)) {
            throw new \RuntimeException('Malformed internal schema definition.');
        }
        $columns = []; $indexes = [];
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            $line = rtrim(trim($line), ',');
            if ('' === $line) { continue; }
            if (preg_match('/^(?<name>[a-z][a-z0-9_]*)\s+(?<type>(?:bigint\(\d+\)|int|varchar\(\d+\)|char\(\d+\)|datetime\(\d+\)|longtext)(?: unsigned)?)\s+(?<nullable>NOT NULL|NULL)(?:\s+DEFAULT\s+(?<default>NULL|\x27[^\x27]*\x27|\d+))?(?<extra>\s+AUTO_INCREMENT)?$/i', $line, $c)) {
                $default = array_key_exists('default',$c) && $c['default'] !== '' ? $c['default'] : null;
                if (null !== $default && str_starts_with($default,"'")) { $default = substr($default,1,-1); }
                if ('NULL' === $default) { $default = null; }
                $columns[$c['name']] = ['type'=>self::normalize_type($c['type']),'null'=>strtoupper($c['nullable']) === 'NULL' ? 'YES' : 'NO',
                    'default'=>$default,'extra'=>str_contains(strtoupper($c['extra'] ?? ''),'AUTO_INCREMENT') ? 'auto_increment' : ''];
                continue;
            }
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY\s+([a-z_]+)|KEY\s+([a-z_]+))\s*\(([^)]+)\)$/i', $line, $k)) {
                $name = strtoupper($k[1]) === 'PRIMARY KEY' ? 'PRIMARY' : ($k[2] ?: $k[3]);
                $indexes[$name] = ['unique'=> $name === 'PRIMARY' || str_starts_with($k[1],'UNIQUE'),
                    'columns'=>array_map('trim', explode(',', $k[4]))];
                continue;
            }
            throw new \RuntimeException('Unrecognized canonical schema declaration.');
        }
        return [$columns,$indexes];
    }

    private static function normalize_type(string $type): string {
        return strtolower(preg_replace('/\b(bigint|int)\(\d+\)/i', '$1', trim($type)));
    }

    private static function matches_table(string $name, string $sql, array $identity): bool {
        global $wpdb;
        $like = $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($name));
        $status = $wpdb->get_row($like, ARRAY_A);
        if (!is_array($status) || !empty($wpdb->last_error) || strtolower((string)($status['Engine'] ?? '')) !== 'innodb'
            || ($status['Name'] ?? $name) !== $name) { return false; }
        $collation = (string)($status['Collation'] ?? '');
        if ($collation === '' || (!empty($wpdb->collate) && $collation !== (string)$wpdb->collate)
            || (empty($wpdb->collate) && !str_starts_with($collation,(string)$wpdb->charset . '_'))) { return false; }
        [$columns,$indexes] = self::specification($sql);
        $rows = $wpdb->get_results("SHOW FULL COLUMNS FROM `{$name}`", ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error) || count($rows) !== count($columns)) { return false; }
        if (array_column($rows,'Field') !== array_keys($columns)) { return false; }
        foreach ($rows as $row) {
            $want = $columns[$row['Field']];
            if (self::normalize_type((string)($row['Type'] ?? '')) !== $want['type']
                || ($row['Null'] ?? null) !== $want['null']
                || ($row['Default'] ?? null) !== $want['default']
                || strtolower(trim((string)($row['Extra'] ?? ''))) !== $want['extra']) { return false; }
            // Character fields must use this table's actual declared collation.
            $textual = preg_match('/^(?:varchar|char|longtext)/', $want['type']) === 1;
            if ($textual && ($row['Collation'] ?? null) !== $collation) { return false; }
            if (!$textual && null !== ($row['Collation'] ?? null)) { return false; }
        }
        $keys = $wpdb->get_results("SHOW INDEX FROM `{$name}`", ARRAY_A);
        if (!is_array($keys) || !empty($wpdb->last_error)) { return false; }
        $actual = [];
        foreach ($keys as $key) {
            $keyname = (string)($key['Key_name'] ?? '');
            $seq = (int)($key['Seq_in_index'] ?? 0);
            if ($keyname === '' || $seq < 1 || isset($actual[$keyname]['columns'][$seq])
                || null !== ($key['Sub_part'] ?? null) || ($key['Collation'] ?? 'A') !== 'A'
                || strtoupper((string)($key['Index_type'] ?? 'BTREE')) !== 'BTREE') { return false; }
            $actual[$keyname]['unique'] = ((int)($key['Non_unique'] ?? -1) === 0);
            $actual[$keyname]['columns'][$seq] = (string)($key['Column_name'] ?? '');
        }
        if (count($actual) !== count($indexes)) { return false; }
        foreach ($indexes as $keyname=>$wanted) {
            if (!isset($actual[$keyname]) || $actual[$keyname]['unique'] !== $wanted['unique']) { return false; }
            ksort($actual[$keyname]['columns']);
            if (array_keys($actual[$keyname]['columns']) !== range(1,count($wanted['columns']))
                || array_values($actual[$keyname]['columns']) !== $wanted['columns']) { return false; }
        }
        $create = $wpdb->get_row("SHOW CREATE TABLE `{$name}`", ARRAY_A);
        if (!is_array($create) || !empty($wpdb->last_error) || !is_string($create['Create Table'] ?? null)
            || preg_match('/\b(?:FOREIGN\s+KEY|CONSTRAINT|CHECK\s*\()/i',$create['Create Table'])) { return false; }
        return true;
    }

    private static function claim_identity(array $identity,array $definitions): string {
        return hash('sha256', $identity['blog'] . "\0" . $identity['database'] . "\0" . $identity['prefix'] . "\0" . self::fingerprint($definitions));
    }
    private static function decode_claim(string $raw): array {
        $data = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['identity','token','attempt','phase']
            || !is_string($data['identity']) || !preg_match('/^[a-f0-9]{64}$/D',$data['identity'])
            || !is_string($data['token']) || !preg_match('/^[a-f0-9]{64}$/D',$data['token'])
            || !is_int($data['attempt']) || $data['attempt'] < 1 || $data['attempt'] > 3
            || !in_array($data['phase'],['PRE_DDL','DDL_MAY_HAVE_STARTED'],true)) {
            throw new \RuntimeException('Unknown installation claim.');
        }
        return $data;
    }
    private static function claim_raw(array $identity): ?string { return self::option_raw(self::CLAIM,$identity['options']); }
    private static function flush_claim_cache(): void {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::CLAIM,'options'); wp_cache_delete('alloptions','options');
        }
    }
    private static function cas_claim(array $identity,string $before,array $updated): string {
        global $wpdb;
        $after = json_encode($updated,JSON_THROW_ON_ERROR);
        $options = $identity['options'];
        $result = $wpdb->query($wpdb->prepare("UPDATE `{$options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",$after,self::CLAIM,$before));
        self::flush_claim_cache();
        if (1 !== $result || !empty($wpdb->last_error) || self::claim_raw($identity) !== $after) {
            throw new \RuntimeException('Installation claim was not uniquely verified.');
        }
        return $after;
    }

    /**
     * Existing physical tables must match exactly. Partial repair is only
     * possible for a positively identified interrupted FIRST installation.
     * Missing tables after a previously complete install may contain lost data.
     */
    public static function can_repair(): bool {
        try {
            if (!in_array(self::inspect(), [self::PARTIAL, self::DRIFTED], true)) { return false; }
            $id = self::identity();
            $defs = self::definitions();
            $existing = self::existing_tables($id);
            if ([] === $existing) { return false; }
            $marker = self::option_raw(self::MARKER, $id['options']);
            $claimRaw = self::claim_raw($id);
            if (null !== $claimRaw) {
                $claim = self::decode_claim($claimRaw);
                if ($claim['identity'] !== self::claim_identity($id, $defs)
                    || $claim['phase'] !== 'DDL_MAY_HAVE_STARTED') { return false; }
            }
            if (count($existing) < count($defs) && (null !== $marker || null === $claimRaw)) {
                return false;
            }
            foreach ($defs as $key => $sql) {
                if (in_array(self::table_name($key), $existing, true)
                    && !self::matches_table(self::table_name($key), $sql, $id)) { return false; }
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Authorized nonce POST only. No DROP, ALTER or unverified DDL retry. */
    public static function repair_verified(): bool {
        global $wpdb;
        if (!self::can_repair()) { return false; }
        try {
            $id = self::identity();
            $defs = self::definitions();
            foreach ($defs as $key => $sql) {
                if (in_array(self::table_name($key), self::existing_tables($id), true)) { continue; }
                if (self::identity() !== $id || !self::can_repair()) { return false; }
                if (false === $wpdb->query($sql) || !empty($wpdb->last_error)) { return false; }
                if (!self::matches_table(self::table_name($key), $sql, $id)) { return false; }
            }
            if (count(self::existing_tables($id)) !== count($defs)) { return false; }
            foreach ($defs as $key => $sql) {
                if (!self::matches_table(self::table_name($key), $sql, $id)) { return false; }
            }
            $wanted = self::fingerprint($defs);
            $before = self::option_raw(self::MARKER, $id['options']);
            if ($before === $wanted) { return self::ready(); }
            if (null === $before) {
                if (true !== add_option(self::MARKER, $wanted, '', false)) { return self::ready(); }
            } else {
                $options = $id['options'];
                $changed = $wpdb->query($wpdb->prepare(
                    "UPDATE `{$options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    $wanted, self::MARKER, $before
                ));
                if (1 !== $changed || !empty($wpdb->last_error)) { return false; }
            }
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::MARKER, 'options');
                wp_cache_delete('alloptions', 'options');
            }
            return self::option_raw(self::MARKER, $id['options']) === $wanted && self::ready();
        } catch (\Throwable) { return false; }
    }

    /** Only expose POST reauthorization for verified, exclusively PRE_DDL claims. */
    public static function can_reauthorize(): bool {
        try {
            if (self::ABSENT !== self::inspect()) { return false; }
            $id = self::identity(); $raw=self::claim_raw($id);
            if (null === $raw) {return false;}
            $c=self::decode_claim($raw);
            return $c['phase']==='PRE_DDL' && $c['attempt']<3 && $c['identity']===self::claim_identity($id,self::definitions());
        } catch(\Throwable) {return false;}
    }

    /** Only call on explicitly authorized normal WP activation or privileged nonce POST. */
    public static function install_new(bool $reauthorize = false): bool {
        global $wpdb;
        if (self::READY === self::inspect()) {return true;}
        if (self::ABSENT !== self::inspect()) {return false;}
        $identity=self::identity(); $defs=self::definitions();
        $expected=self::claim_identity($identity,$defs);
        $raw=self::claim_raw($identity);
        if (null === $raw) {
            if ($reauthorize) {return false;}
            $claim=['identity'=>$expected,'token'=>bin2hex(random_bytes(32)),'attempt'=>1,'phase'=>'PRE_DDL'];
            $raw=json_encode($claim,JSON_THROW_ON_ERROR);
            if (true !== add_option(self::CLAIM,$raw,'',false)) {return false;}
            self::flush_claim_cache();
            if (self::claim_raw($identity) !== $raw) {return false;}
        } else {
            if (!$reauthorize) {return false;}
            $claim=self::decode_claim($raw);
            if ($claim['identity']!==$expected || $claim['phase']!=='PRE_DDL' || $claim['attempt']>=3) {return false;}
            $claim['attempt']++;
            $claim['token']=bin2hex(random_bytes(32));
            $raw=self::cas_claim($identity,$raw,$claim);
        }
        if (self::ABSENT !== self::inspect() || self::identity() !== $identity) {return false;}
        $claim['phase']='DDL_MAY_HAVE_STARTED';
        $raw=self::cas_claim($identity,$raw,$claim);
        if (self::UNKNOWN_UNSAFE !== self::inspect() || self::identity() !== $identity
            || [] !== self::existing_tables($identity)) {return false;}
        // A persisted, monotonic first-DDL barrier precedes every CREATE.
        if (self::claim_raw($identity) !== $raw) {return false;}
        foreach ($defs as $sql) {
            if (self::identity() !== $identity || self::claim_raw($identity) !== $raw) {return false;}
            $result=$wpdb->query($sql);
            if (false === $result || !empty($wpdb->last_error)) {return false;}
        }
        // Independently verify full manifest before marking the product READY.
        foreach ($defs as $key=>$sql) {
            if (!self::matches_table(self::table_name($key),$sql,$identity)) {return false;}
        }
        if (count(self::existing_tables($identity))!==9) {return false;}
        $marker=self::fingerprint($defs);
        if (true !== add_option(self::MARKER,$marker,'',false)
            || self::option_raw(self::MARKER,$identity['options']) !== $marker) {return false;}
        return self::READY === self::inspect();
    }
}
