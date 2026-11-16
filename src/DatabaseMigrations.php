<?php
declare(strict_types=1);
namespace CajeerBook;

use PDO;

/** Database-wide schema lifecycle. One schema is shared by all installation namespaces.
 * SQL files are immutable after release. Runtime connections pin the supported schema
 * with a session advisory lock; an offline migrator must acquire the exclusive lock.
 */
final class DatabaseMigrations
{
    public const VERSION = 3;
    private const LOCK = '1128416305, 1'; // same database-wide lock as pre-0.5.2 schema installers
    private const SCHEMA = 'cajeer_book';
    private const BASELINE = ['postgres-content.sql','postgres-identity.sql','postgres-audit.sql'];
    private const LEDGER = 'cajeer_book.schema_migrations';
    private const HISTORY = 'cajeer_book.schema_migration_history';

    private static function sql(string $name): string
    {
        $path=dirname(__DIR__).'/sql/'.$name;
        $sql=file_get_contents($path);
        if($sql===false||trim($sql)==='')throw new \RuntimeException('Missing migration SQL: '.$name);
        return $sql;
    }
    /** Hash the exact SQL bytes, including both directions, and the ordered filenames. */
    public static function catalog(): array
    {
        $definitions=[
            1=>['name'=>'baseline-0.5.1','release'=>'0.5.1','up'=>self::BASELINE,'down'=>null],
            2=>['name'=>'audit-outcome-index','release'=>'0.5.2','up'=>['migrations/0002-audit-outcome.up.sql'],'down'=>'migrations/0002-audit-outcome.down.sql'],
            3=>['name'=>'published-read-indexes','release'=>'0.6.0','up'=>['migrations/0003-published-read.up.sql'],'down'=>'migrations/0003-published-read.down.sql'],
        ];
        $out=[];$next=1;
        foreach($definitions as $version=>$definition){
            if($version!==$next++)throw new \LogicException('Noncontiguous shipped migrations.');
            $names=$definition['up'];if($definition['down']!==null)$names[]=$definition['down'];
            $parts=[];foreach($names as $name)$parts[]=$name.'='.hash('sha256',self::sql($name));
            $out[$version]=['version'=>$version,'name'=>$definition['name'],'release'=>$definition['release'],'checksum'=>hash('sha256',implode("\n",$parts)), 'reversible'=>$definition['down']!==null,'files'=>$names,'up'=>$definition['up'],'down'=>$definition['down']];
        }
        if(count($out)!==self::VERSION)throw new \LogicException('Shipped migration catalog version mismatch.');
        return $out;
    }
    private static function q(PDO $pdo,string $sql,array $args=[]): \PDOStatement
    { $q=$pdo->prepare($sql);$q->execute($args);return $q; }
    private static function exists(PDO $pdo,string $name): bool
    { return self::q($pdo,'SELECT to_regclass(?) IS NOT NULL',[$name])->fetchColumn()===true; }
    /** A ledger must be the exact contiguous prefix of the shipped catalog. */
    public static function validateLedger(array $rows,array $catalog): int
    {
        $version=0;
        foreach($rows as $row){
            $n=++$version;$expected=$catalog[$n]??null;
            if(!$expected||(string)($row['version']??'')!==(string)$n||($row['name']??null)!==$expected['name']||($row['release']??null)!==$expected['release']||!is_string($row['checksum']??null)||!hash_equals($expected['checksum'],$row['checksum']))
                throw new \RuntimeException('Unknown, missing or modified schema migration at version '.$n.'. Restore the matching release and database; do not edit the ledger.');
        }
        return $version;
    }
    private static function rows(PDO $pdo): array
    {
        $ledger=self::exists($pdo,self::LEDGER);$history=self::exists($pdo,self::HISTORY);
        if($ledger!==$history)throw new \RuntimeException('Incomplete schema migration registry.');
        return $ledger?$pdo->query('SELECT version,name,release,checksum,applied_at,mode,duration_ms FROM '.self::LEDGER.' ORDER BY version')->fetchAll(PDO::FETCH_ASSOC):[];
    }
    /** An older runtime can verify a fully rolled-back future migration as an opaque
     * balanced history pair. Unknown applied ledger entries are NEVER accepted. The
     * newer migrator verifies the actual SQL/checksum before performing its down SQL. */
    public static function validateHistory(iterable $rows,array $catalog,int $version): void
    {
        $head=0;$future=[];
        foreach($rows as $row){
            $n=(int)$row['version'];$m=$catalog[$n]??null;$op=$row['operation'];
            if((string)$row['version']!==(string)$n||$n<1)throw new \RuntimeException('Invalid migration history version.');
            if($m){
                if($row['name']!==$m['name']||$row['release']!==$m['release']||!hash_equals($m['checksum'],$row['checksum']))throw new \RuntimeException('Migration history checksum mismatch.');
            }else{
                if($n<=count($catalog)||!is_string($row['name'])||!preg_match('/\A[a-z0-9][a-z0-9-]{0,127}\z/D',$row['name'])||!is_string($row['release'])||!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D',$row['release'])||!is_string($row['checksum'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['checksum']))throw new \RuntimeException('Unknown migration history metadata.');
                $identity=[$row['name'],$row['release'],$row['checksum']];
                if($op==='apply'){$future[$n]=$identity;}
                elseif($op==='rollback'){if(($future[$n]??null)!==$identity)throw new \RuntimeException('Unknown rollback history checksum mismatch.');unset($future[$n]);}
                else throw new \RuntimeException('Unknown migration history operation.');
            }
            if($op==='rollback'){
                if(($m&&!$m['reversible'])||$n!==$head)throw new \RuntimeException('Invalid migration rollback history.');$head--;
            }else{
                if($n!==$head+1||($op==='adopt'&&$n!==1)||!in_array($op,['apply','adopt'],true))throw new \RuntimeException('Invalid migration apply history.');$head++;
            }
        }
        if($future||$head!==$version)throw new \RuntimeException('Migration history does not match current ledger.');
    }
    private static function verifyHistory(PDO $pdo,array $catalog,int $version): void
    {
        if(!self::exists($pdo,self::HISTORY)){if($version!==0)throw new \RuntimeException('Migration history missing.');return;}
        self::validateHistory($pdo->query('SELECT version,name,release,checksum,operation FROM '.self::HISTORY.' ORDER BY id'),$catalog,$version);
    }
    private static function lock(PDO $pdo,bool $shared): void
    {
        $function=$shared?'pg_try_advisory_lock_shared':'pg_try_advisory_lock';
        if((int)$pdo->query('SELECT CASE WHEN '.$function.'('.self::LOCK.') THEN 1 ELSE 0 END')->fetchColumn()!==1)
            throw new \RuntimeException('Database schema is busy. Stop all Book nodes, workers and backup processes sharing this database; retry offline.');
    }
    private static function unlock(PDO $pdo,bool $shared): void
    { $pdo->query('SELECT '.($shared?'pg_advisory_unlock_shared':'pg_advisory_unlock').'('.self::LOCK.')')->fetchColumn(); }
    /** On success the lock belongs to the non-persistent PDO session until it closes. */
    public static function pin(PDO $pdo): void
    {
        self::lock($pdo,true);
        try{
            $v=self::validateLedger(self::rows($pdo),self::catalog());
            if($v!==self::VERSION)throw new \RuntimeException('Database schema '.$v.' is incompatible with this release (requires '.self::VERSION.'). Run bin/migrations.php apply --offline.');
            self::verifyIndex($pdo,true);self::verifyReadIndexes($pdo,true);
        }catch(\Throwable $e){self::unlock($pdo,true);throw $e;}
    }
    /** Fresh portable imports and restore drills use the same registry. No DDL on a current database. */
    public static function prepareInstallation(PDO $pdo,bool $boundLegacy=false): void
    {
        self::lock($pdo,true);
        try{$v=self::validateLedger(self::rows($pdo),self::catalog());if($v===self::VERSION){self::verifyIndex($pdo,true);self::verifyReadIndexes($pdo,true);return;}
            if(!$boundLegacy&&self::exists($pdo,'cajeer_book.content_installation')&&$pdo->query('SELECT 1 FROM cajeer_book.content_installation LIMIT 1')->fetchColumn()!==false)throw new \RuntimeException('A populated database needs an explicit offline schema upgrade with its matching installation before importing a new namespace.');}
        catch(\Throwable $e){self::unlock($pdo,true);throw $e;}
        self::unlock($pdo,true);
        self::apply($pdo);
        self::pin($pdo);
    }
    private static function server(PDO $pdo): array
    {
        $v=(int)$pdo->query('SHOW server_version_num')->fetchColumn();
        if($v<160000)throw new \RuntimeException('PostgreSQL 16 or newer is required.');
        if($pdo->query('SHOW transaction_read_only')->fetchColumn()!=='off'||$pdo->query('SELECT pg_is_in_recovery()')->fetchColumn()===true)throw new \RuntimeException('Use a writable PostgreSQL primary.');
        return ['server_version_num'=>$v,'database'=>(string)$pdo->query('SELECT current_database()')->fetchColumn(),'database_bytes'=>(int)$pdo->query('SELECT pg_database_size(current_database())')->fetchColumn()];
    }
    private static function verifyIndex(PDO $pdo,bool $present): void
    {
        $row=self::q($pdo,"SELECT i.indisvalid,i.indisready,i.indisunique,pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='cajeer_book' AND c.relname='audit_event_outcome'")->fetch(PDO::FETCH_ASSOC);
        if(!$present){if($row||self::exists($pdo,'cajeer_book.audit_event_outcome'))throw new \RuntimeException('Unexpected audit outcome index; investigate schema drift.');return;}
        if(!$row||!$row['indisvalid']||!$row['indisready']||$row['indisunique']||$row['definition']!=='CREATE INDEX audit_event_outcome ON cajeer_book.audit_event USING btree (installation, outcome, at_epoch DESC, event_id DESC)')throw new \RuntimeException('Audit outcome index missing or inconsistent; schema verification failed.');
    }
    private static function verifyReadIndexes(PDO $pdo,bool $present):void
    {
        // Compare the actual index definitions with the immutable migration SQL.
        // PostgreSQL adds redundant parentheses and explicit text casts when deparsing.
        $normalize=static fn(string $s)=>strtolower(preg_replace('/[\s();]+/','',str_replace('::text','',$s)));
        $statements=array_values(array_filter(explode(';',trim(self::sql('migrations/0003-published-read.up.sql'))),fn($sql)=>str_starts_with(ltrim($sql),'CREATE INDEX ')));
        $function=$pdo->query("SELECT p.prosrc,p.provolatile,p.proisstrict,p.proparallel,p.prosecdef,p.proconfig::text AS settings,l.lanname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_language l ON l.oid=p.prolang WHERE n.nspname='cajeer_book' AND p.proname='reader_json' AND p.proargtypes='114'::oidvector AND p.prorettype=114")->fetch(PDO::FETCH_ASSOC);
        if($present){if(!$function||trim($function['prosrc'])!=="SELECT replace(value::text, chr(92)||'u0000', chr(92)||'u0001')::json"||$function['provolatile']!=='i'||!$function['proisstrict']||$function['proparallel']!=='s'||$function['prosecdef']||$function['settings']!=='{search_path=pg_catalog}'||$function['lanname']!=='sql')throw new \RuntimeException('Published routing JSON function missing or inconsistent.');}
        elseif($function||$pdo->query("SELECT to_regprocedure('cajeer_book.reader_json(json)') IS NOT NULL")->fetchColumn())throw new \RuntimeException('Unexpected published routing JSON function.');
        $names=['pages_public_key_idx','pages_public_id_idx','pages_public_media_idx'];$rows=[];
        foreach($pdo->query("SELECT c.relname,i.indisvalid,i.indisready,i.indisunique,i.indrelid='cajeer_book.pages'::regclass AS correct_table,pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='cajeer_book' AND c.relname IN ('pages_public_key_idx','pages_public_id_idx','pages_public_media_idx')") as $row)$rows[$row['relname']]=$row;
        foreach($names as $i=>$name){
            $r=$rows[$name]??null;
            if(!$present){if($r||self::exists($pdo,'cajeer_book.'.$name))throw new \RuntimeException('Unexpected published read index: '.$name);continue;}
            if(!$r||!$r['correct_table']||!$r['indisvalid']||!$r['indisready']||$r['indisunique']||$normalize($r['definition'])!==$normalize($statements[$i]))throw new \RuntimeException('Published read index missing or inconsistent: '.$name);
        }
    }
    /** Compare baseline objects against the SQL definitions in a transactional shadow schema.
     * Existing extra operator indexes are allowed; required objects, definitions and columns
     * must match. No DDL touches existing tables during adoption. */
    private static function inventory(PDO $pdo,string $schema): array
    {
        $queries=[
            'relation'=>"SELECT c.relname AS key, c.relkind::text||':'||coalesce(pg_get_expr(c.relpartbound,c.oid),'')||':'||coalesce(pg_get_partkeydef(c.oid),'') AS def FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND c.relkind IN ('r','p','S')",
            'column'=>"SELECT c.relname||'.'||a.attname AS key, a.attnum::text||':'||format_type(a.atttypid,a.atttypmod)||':'||a.attnotnull::text||':'||a.attgenerated::text||':'||a.attidentity::text||':'||coalesce(pg_get_expr(d.adbin,d.adrelid),'') AS def FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_attribute a ON a.attrelid=c.oid LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE n.nspname=? AND c.relkind IN ('r','p') AND a.attnum>0 AND NOT a.attisdropped",
            'constraint'=>"SELECT c.relname||'.'||k.conname AS key, pg_get_constraintdef(k.oid)||':'||k.convalidated::text AS def FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=?",
            'index'=>"SELECT c.relname AS key, pg_get_indexdef(c.oid)||':'||i.indisvalid::text||':'||i.indisready::text AS def FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=?",
            'trigger'=>"SELECT c.relname||'.'||t.tgname AS key, pg_get_triggerdef(t.oid)||':'||t.tgenabled::text AS def FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND NOT t.tgisinternal",
            'function'=>"SELECT p.proname||'('||pg_get_function_identity_arguments(p.oid)||')' AS key, pg_get_functiondef(p.oid) AS def FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=? AND p.prokind='f' AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid='pg_proc'::regclass AND d.objid=p.oid AND d.deptype='e')",
        ];
        $out=[];foreach($queries as $kind=>$sql){$out[$kind]=[];foreach(self::q($pdo,$sql,[$schema]) as $row)$out[$kind][$row['key']]=str_replace([$schema.'.',self::SCHEMA.'.'],['@schema.','@schema.'],$row['def']);ksort($out[$kind]);}return $out;
    }
    private static function baselineData(PDO $pdo): void
    {
        foreach(['content_format','audit_format'] as $table){if((int)$pdo->query('SELECT version FROM cajeer_book.'.$table.' WHERE singleton=true')->fetchColumn()!==1)throw new \RuntimeException('Unsupported legacy '.$table.'; restore or upgrade with the matching previous release.');}
        if($pdo->query("SELECT 1 FROM cajeer_book.identity_state WHERE format<>1 LIMIT 1")->fetchColumn()!==false)throw new \RuntimeException('Unsupported identity format.');
    }
    private static function adopt(PDO $pdo): array
    {
        // An extension missing from the old schema is damage, not a baseline to stamp.
        if($pdo->query("SELECT 1 FROM pg_extension WHERE extname='pg_trgm'")->fetchColumn()===false)throw new \RuntimeException('Legacy pg_trgm extension missing.');
        $shadow='book_migration_check_'.bin2hex(random_bytes(8));
        foreach(self::BASELINE as $file)$pdo->exec(str_replace(self::SCHEMA,$shadow,self::sql($file)));
        $expected=self::inventory($pdo,$shadow);$actual=self::inventory($pdo,self::SCHEMA);
        self::compareInventory($expected,$actual);
        $pdo->exec('DROP SCHEMA '.$shadow.' CASCADE');
        self::baselineData($pdo);self::verifyIndex($pdo,false);self::verifyReadIndexes($pdo,false);return $expected;
    }
    private static function compareInventory(array $expected,array $actual): void
    {
        foreach($expected as $kind=>$objects)foreach($objects as $name=>$def)if(($actual[$kind][$name]??null)!==$def)throw new \RuntimeException('Schema drift: '.$kind.' '.$name.'. Restore or repair with the matching release.');
        foreach(['column','constraint','trigger'] as $kind)foreach($actual[$kind] as $name=>$def){$table=explode('.',$name,2)[0];if(isset($expected['relation'][$table])&&!isset($expected[$kind][$name]))throw new \RuntimeException('Unexpected schema object: '.$kind.' '.$name);}
    }
    private static function verifyBaseline(PDO $pdo): void
    {
        $raw=$pdo->query('SELECT baseline_inventory FROM '.self::LEDGER.' WHERE version=1')->fetchColumn();
        if(!is_string($raw))throw new \RuntimeException('Baseline inventory missing.');
        $expected=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($expected)||array_keys($expected)!==['relation','column','constraint','index','trigger','function']||!isset($expected['relation']['content_store'],$expected['relation']['identity_state'],$expected['relation']['audit_event']))throw new \RuntimeException('Invalid baseline inventory.');
        self::compareInventory($expected,self::inventory($pdo,self::SCHEMA));
        self::baselineData($pdo);
    }
    private static function registry(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE cajeer_book.schema_migrations (
            version integer PRIMARY KEY CHECK(version>0), name text NOT NULL UNIQUE, release text NOT NULL,
            checksum text NOT NULL CHECK(checksum ~ '^[0-9a-f]{64}$'), applied_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            mode text NOT NULL CHECK(mode IN ('apply','adopt')), duration_ms bigint NOT NULL CHECK(duration_ms>=0),
            baseline_inventory text CHECK ((version=1 AND baseline_inventory IS NOT NULL) OR (version<>1 AND baseline_inventory IS NULL)));
            CREATE TABLE cajeer_book.schema_migration_history (
            id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, version integer NOT NULL CHECK(version>0),
            name text NOT NULL, release text NOT NULL, checksum text NOT NULL CHECK(checksum ~ '^[0-9a-f]{64}$'),
            operation text NOT NULL CHECK(operation IN ('apply','adopt','rollback')), at timestamptz NOT NULL DEFAULT clock_timestamp(),
            duration_ms bigint NOT NULL CHECK(duration_ms>=0));");
    }
    private static function record(PDO $pdo,array $m,string $mode,int $ms,?array $inventory=null): void
    {
        $args=[$m['version'],$m['name'],$m['release'],$m['checksum'],$mode,$ms];
        if($mode==='rollback')self::q($pdo,'DELETE FROM '.self::LEDGER.' WHERE version=?',[$m['version']]);
        else self::q($pdo,'INSERT INTO '.self::LEDGER.'(version,name,release,checksum,mode,duration_ms,baseline_inventory) VALUES(?,?,?,?,?,?,?)',[...$args,$inventory===null?null:json_encode($inventory,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
        self::q($pdo,'INSERT INTO '.self::HISTORY.'(version,name,release,checksum,operation,duration_ms) VALUES(?,?,?,?,?,?)',$args);
    }
    private static function legacy(PDO $pdo): bool
    { return self::q($pdo,'SELECT EXISTS(SELECT 1 FROM pg_namespace WHERE nspname=?)',[self::SCHEMA])->fetchColumn()===true; }
    /** Read-only preflight. The exclusive lock and adoption validation are repeated on apply. */
    public static function status(PDO $pdo): array
    {
        self::lock($pdo,true);
        try{
            $db=self::server($pdo);$catalog=self::catalog();$rows=self::rows($pdo);$v=self::validateLedger($rows,$catalog);
            self::verifyHistory($pdo,$catalog,$v);$legacy=self::legacy($pdo);if($v===0&&self::exists($pdo,self::LEDGER))throw new \RuntimeException('Empty migration registry.');$pending=array_values(array_filter($catalog,fn($m)=>$m['version']>$v));
            if($v>0){self::verifyBaseline($pdo);self::verifyIndex($pdo,$v>=2);self::verifyReadIndexes($pdo,$v>=3);}
            $bytes=self::exists($pdo,'cajeer_book.audit_event')?(int)$pdo->query("SELECT pg_total_relation_size('cajeer_book.audit_event'::regclass)")->fetchColumn():0;
            $exclusive=(int)$pdo->query('SELECT CASE WHEN pg_try_advisory_lock('.self::LOCK.') THEN 1 ELSE 0 END')->fetchColumn()===1;if($exclusive)self::unlock($pdo,false);
            $canCreate=$pdo->query("SELECT has_database_privilege(current_user,current_database(),'CREATE')")->fetchColumn()===true;
            return $db+['exclusive_available'=>$exclusive,'database_create_privilege'=>$canCreate,'current'=>$v,'required'=>self::VERSION,'legacy_candidate'=>$v===0&&$legacy,'applied'=>$rows,'pending'=>$pending,'audit_table_bytes'=>$bytes,'offline_required'=>true,'compatible'=>$v===self::VERSION];
        }finally{self::unlock($pdo,true);}
    }
    public static function preflight(PDO $pdo): array
    {
        $report=self::status($pdo);
        if(!$report['exclusive_available'])throw new \RuntimeException('Active runtime connections prevent offline migration.');
        if($report['current']===0&&!$report['database_create_privilege'])throw new \RuntimeException('Migration role requires CREATE on database for baseline initialization/validation.');
        // Exercise the exact upgrade path including ledger writes; rollback everything.
        $result=self::change($pdo,self::VERSION,false,true);
        if($result['from']!==$report['current'])throw new \RuntimeException('Schema changed during preflight; retry.');
        return $report+['preflight'=>'passed','dry_run_rolled_back'=>true,'simulated_changes'=>$result['changes']];
    }
    /** Repair only derived indexes; never use a historical schema installer as a repair command. */
    public static function repairSearchIndexes(PDO $pdo): void
    {
        $names=['search_simple_gin'=>'USING gin(simple_vector)','search_english_gin'=>'USING gin(english_vector)','search_russian_gin'=>'USING gin(russian_vector)','search_title_trgm'=>null,'search_path_trgm'=>null,'search_scope_locale'=>'(installation,scope,locale)','search_scope_filter'=>'(installation,scope,book,version,is_public)'];
        $broken=function()use($pdo,$names){$out=[];foreach($names as $name=>$definition){
            $r=self::q($pdo,"SELECT i.indisvalid AND i.indisready FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='cajeer_book' AND c.relname=? AND i.indrelid='cajeer_book.search_document'::regclass",[$name])->fetchColumn();
            if($r!==true){if(!self::exists($pdo,'cajeer_book.'.$name))$out[$name]='missing';else{$q=self::q($pdo,"SELECT 1 FROM pg_index WHERE indexrelid=to_regclass(?) AND indrelid='cajeer_book.search_document'::regclass",['cajeer_book.'.$name]);if($q->fetchColumn()===false)throw new \RuntimeException('Search index name belongs to an unexpected object: '.$name);$out[$name]='invalid';}}
        }return $out;};
        self::pin($pdo);try{$bad=$broken();}finally{self::unlock($pdo,true);}if(!$bad)return;
        self::lock($pdo,false);try{
            if(self::validateLedger(self::rows($pdo),self::catalog())!==self::VERSION)throw new \RuntimeException('Schema migration required before search repair.');
            $pdo->beginTransaction();$pdo->exec("SET LOCAL lock_timeout='30s'; SET LOCAL statement_timeout='30min'");
            foreach($broken() as $name=>$state){
                if($state==='invalid'){$pdo->exec('REINDEX INDEX cajeer_book.'.$name);continue;}
                $definition=$names[$name];if($definition===null){$ext=$pdo->query("SELECT quote_ident(n.nspname) FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='pg_trgm'")->fetchColumn();if(!$ext)throw new \RuntimeException('pg_trgm extension missing.');$definition='USING gin('.($name==='search_title_trgm'?'title':'page_key').' '.$ext.'.gin_trgm_ops)';}
                $pdo->exec('CREATE INDEX '.$name.' ON cajeer_book.search_document '.$definition);
            }
            if($broken())throw new \RuntimeException('Search index repair failed.');$pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{self::unlock($pdo,false);}
    }
    /** Only shipped SQL; no arbitrary filenames or destructive automatic down migrations. */
    public static function apply(PDO $pdo): array {return self::change($pdo,self::VERSION,false);}
    public static function rollback(PDO $pdo,int $target): array
    {
        if($target<1||$target>=self::VERSION)throw new \InvalidArgumentException('Rollback target must be a supported version below the current release; baseline cannot be removed.');
        return self::change($pdo,$target,true);
    }
    private static function change(PDO $pdo,int $target,bool $down,bool $dryRun=false): array
    {
        if($pdo->inTransaction())throw new \LogicException('Migration requires a dedicated autocommit connection.');
        self::lock($pdo,false);
        try{
            self::server($pdo);$catalog=self::catalog();$pdo->beginTransaction();
            // Bound locks; a large index build is allowed more time than an HTTP query.
            $pdo->exec("SET LOCAL lock_timeout='30s'; SET LOCAL statement_timeout='30min'");
            $v=self::validateLedger(self::rows($pdo),$catalog);$before=$v;$changed=[];
            self::verifyHistory($pdo,$catalog,$v);
            if($v>0){self::verifyBaseline($pdo);self::verifyIndex($pdo,$v>=2);self::verifyReadIndexes($pdo,$v>=3);}
            if($down){
                if($v<=$target)throw new \RuntimeException('Rollback target must be below the applied schema version.');
                // Validate the whole down path before executing any DDL.
                for($n=$v;$n>$target;$n--)if(!$catalog[$n]['reversible'])throw new \RuntimeException('Migration '.$n.' requires restoring the matched backup.');
                while($v>$target){$m=$catalog[$v];$start=hrtime(true);$pdo->exec(self::sql($m['down']));
                    self::record($pdo,$m,'rollback',(int)((hrtime(true)-$start)/1000000));$changed[]=['version'=>$v,'operation'=>'rollback'];$v--;
                    self::verifyIndex($pdo,$v>=2);self::verifyReadIndexes($pdo,$v>=3);
                }
            }else{
                if($v===0){
                    if(self::exists($pdo,self::LEDGER))throw new \RuntimeException('Empty migration registry; refusing to adopt again.');
                    $start=hrtime(true);$mode=self::legacy($pdo)?'adopt':'apply';
                    if($mode==='adopt')$inventory=self::adopt($pdo);else{foreach($catalog[1]['up'] as $file)$pdo->exec(self::sql($file));self::baselineData($pdo);$inventory=self::inventory($pdo,self::SCHEMA);}
                    self::registry($pdo);self::record($pdo,$catalog[1],$mode,(int)((hrtime(true)-$start)/1000000),$inventory);$v=1;$changed[]=['version'=>1,'operation'=>$mode];
                }
                while($v<$target){$m=$catalog[$v+1];$start=hrtime(true);foreach($m['up'] as $file)$pdo->exec(self::sql($file));
                    $v++;self::verifyIndex($pdo,$v>=2);self::verifyReadIndexes($pdo,$v>=3);self::record($pdo,$m,'apply',(int)((hrtime(true)-$start)/1000000));$changed[]=['version'=>$v,'operation'=>'apply'];
                }
            }
            if($v!==$target||self::validateLedger(self::rows($pdo),$catalog)!==$target)throw new \RuntimeException('Migration target verification failed.');
            self::verifyHistory($pdo,$catalog,$v);self::verifyBaseline($pdo);
            if($dryRun)$pdo->rollBack();else $pdo->commit();
            return ['from'=>$before,'to'=>$v,'changes'=>$changed];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{self::unlock($pdo,false);}
    }
}
