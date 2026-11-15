<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
use PDO;
require_once __DIR__.'/PostgresSearch.php';
require_once __DIR__.'/Search.php';
require_once __DIR__.'/RedisRuntime.php';

/** PostgreSQL content storage. Immutable media bytes remain on disk; identity shares the installation binding. */
final class PostgresContentStore
{
    private const MARKER='postgres.required';
    private const PENDING='postgres.migrating';
    private const IDENTITY_MARKER='postgres.identity.required';
    private const CHILDREN=['ci'=>['tokens','builds'],'automation'=>['jobs','receipts']];
    private ?PDO $connection=null;
    private bool $wake=false;
    public function __construct(private readonly string $namespace,private readonly string $scope,private readonly string $binding) {}

    private static function rootOf(string $dir):string
    {
        $dir=rtrim($dir,'/');
        if(preg_match('~\A(.+)/workspaces/[a-f0-9]{32}/[a-f0-9]{32}\z~D',$dir,$m))return $m[1];
        return $dir;
    }
    public static function namespace():string
    {
        $id=getenv('BOOK_PG_NAMESPACE')?:'';
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \RuntimeException('BOOK_PG_NAMESPACE must be a unique 32-character lowercase hexadecimal installation ID.');
        return $id;
    }
    public static function configuredRoot():string
    {
        return DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:dirname(__DIR__).'/var',dirname(__DIR__).'/public');
    }
    /** The random binding makes a reused configured namespace fail closed even across databases. */
    private static function markerData(string $root,string $file=self::MARKER):?array
    {
        $path=Files::path($root,$file);clearstatcache(true,$path);
        if(!file_exists($path))return null;
        if(!is_file($path)||filesize($path)!==66||!preg_match('/\A([a-f0-9]{32}):([a-f0-9]{32})\n\z/D',(string)file_get_contents($path),$m))throw new \RuntimeException('Invalid PostgreSQL installation marker.');
        return [$m[1],$m[2]];
    }
    public static function marker(string $root):?string{return self::markerData($root)[0]??null;}
    public static function installationBinding(string $root):?array
    {
        $root=rtrim($root,'/');
        if(!getenv('BOOK_PG_DSN')){
            if(self::markerData($root)!==null||self::markerData($root,self::PENDING)!==null||self::markerData($root,self::IDENTITY_MARKER)!==null)throw new \RuntimeException('PostgreSQL is required or migration is incomplete; configure BOOK_PG_DSN.');
            return null;
        }
        if($root!==self::configuredRoot())throw new \RuntimeException('PostgreSQL root must match BOOK_DATA_DIR.');
        $marker=self::markerData($root);
        if($marker===null||$marker[0]!==self::namespace())throw new \RuntimeException('PostgreSQL installation not initialized; run bin/postgres-content.php migrate --offline.');
        return $marker;
    }
    /** Separate cutover marker distinguishes 0.4.1 from a damaged 0.4.2 identity database. */
    public static function identityBinding(string $root):?array
    {
        $binding=self::installationBinding($root);if($binding===null)return null;
        if(self::markerData($root,self::IDENTITY_MARKER)!==$binding)throw new \RuntimeException('PostgreSQL identity cutover incomplete or binding mismatch; run offline migration or restore the matched backup.');
        return $binding;
    }
    public static function forDirectory(string $dir):?self
    {
        $dir=rtrim($dir,'/');
        if(!getenv('BOOK_PG_DSN')){
            self::installationBinding($dir);self::installationBinding(self::rootOf($dir));return null;
        }
        $root=self::configuredRoot();$scope=self::scope($root,$dir);$marker=self::installationBinding($root);
        return new self($marker[0],$scope,$marker[1]);
    }
    private static function scope(string $root,string $dir):string
    {
        if($dir===$root)return 'legacy';
        $prefix=$root.'/workspaces/';
        if(!str_starts_with($dir,$prefix)||!preg_match('~\A[a-f0-9]{32}/[a-f0-9]{32}\z~D',substr($dir,strlen($prefix))))throw new \RuntimeException('Invalid PostgreSQL workspace scope.');
        return substr($dir,strlen($prefix));
    }
    public static function connect():PDO
    {
        $dsn=getenv('BOOK_PG_DSN')?:'';
        if(!str_starts_with($dsn,'pgsql:'))throw new \RuntimeException('BOOK_PG_DSN must be a PostgreSQL PDO DSN.');
        if(!in_array('pgsql',PDO::getAvailableDrivers(),true))throw new \RuntimeException('PostgreSQL requires the PHP pdo_pgsql extension.');
        try{
            $pdo=new PDO($dsn,getenv('BOOK_PG_USER')?:null,getenv('BOOK_PG_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
            $pdo->exec("SET search_path TO pg_catalog; SET lock_timeout TO '30s'; SET statement_timeout TO '120s'; SET client_encoding TO 'UTF8'");
            return $pdo;
        }catch(\PDOException $e){throw new \RuntimeException('PostgreSQL content connection failed.',0,$e);}
    }
    private function db():PDO{
        if($this->connection===null){
            $pdo=self::connect();
            if((int)$pdo->query('SELECT version FROM cajeer_book.content_format WHERE singleton=true')->fetchColumn()!==1)throw new \RuntimeException('Unsupported PostgreSQL content format.');
            $q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$this->namespace]);
            if($q->fetchColumn()!==$this->binding)throw new \RuntimeException('PostgreSQL installation binding mismatch; verify database and data directory.');
            $this->connection=$pdo;
        }
        return $this->connection;
    }
    private function query(PDO $pdo,string $sql,array $args=[]):\PDOStatement
    {
        $s=$pdo->prepare($sql);$s->execute($args);return $s;
    }
    private function params():array{return [$this->namespace,$this->scope];}
    private static function json(mixed $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $v):mixed{return json_decode($v,true,512,JSON_THROW_ON_ERROR);}
    private function lock(PDO $pdo,bool $write):void
    {
        $r=$this->query($pdo,'SELECT revision FROM cajeer_book.content_store WHERE installation=? AND scope=? '.($write?'FOR UPDATE':'FOR SHARE'),$this->params())->fetchColumn();
        if($r===false)throw new \RuntimeException('PostgreSQL workspace content missing; restore or migrate it explicitly.');
    }
    /** A single row lock pins all sections/records and the media lifecycle for the workspace. */
    public function transaction(bool $write,callable $fn):mixed
    {
        $pdo=$this->db();$pdo->beginTransaction();$this->wake=false;
        try{
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->lock($pdo,$write);$result=$fn($this->load($pdo));$pdo->commit();
            if($this->wake)RedisRuntime::forBinding($this->namespace,$this->binding)?->notify($this->scope);
            return $result;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function load(PDO $pdo):array
    {
        $data=[];
        $q=$this->query($pdo,'SELECT name,position,kind,value::text AS value FROM cajeer_book.content_section WHERE installation=? AND scope=? ORDER BY position',$this->params());
        foreach($q as $r){
            if((int)$r['position']!==count($data))throw new \RuntimeException('Invalid PostgreSQL section order.');
            $value=$r['kind']==='array'?[]:self::decode($r['value']);
            if($r['kind']==='scalar'&&is_array($value))throw new \RuntimeException('Invalid PostgreSQL scalar section.');
            $data[$r['name']]=$value;
        }
        $q=$this->query($pdo,'SELECT section,record_key,position,value::text AS value FROM cajeer_book.content_record WHERE installation=? AND scope=? ORDER BY section,position',$this->params());
        foreach($q as $r){if(!isset($data[$r['section']])||!is_array($data[$r['section']])||(int)$r['position']!==count($data[$r['section']]))throw new \RuntimeException('Invalid PostgreSQL content section.');$data[$r['section']][$r['record_key']]=self::decode($r['value']);}
        return self::assemble($data);
    }
    /** Separate large nested collections into one SQL record per build, token, job and receipt.
     *  Prefixes prevent collisions with unknown fields in older portable stores. */
    private static function sections(array $db):array
    {
        $root=[];$children=[];
        foreach($db as $name=>$value){
            if(str_contains((string)$name,"\0"))throw new \DomainException('Invalid PostgreSQL section name.');
            if(is_array($value))foreach(self::CHILDREN[$name]??[] as $field)if(isset($value[$field])&&is_array($value[$field])){
                $children['child:'.$name.':'.$field]=$value[$field];$value[$field]=[];
            }
            $root['root:'.$name]=$value;
        }
        return $root+$children;
    }
    private static function assemble(array $sections):array
    {
        $db=[];$children=[];
        foreach($sections as $name=>$value){
            if(str_starts_with($name,'root:'))$db[substr($name,5)]=$value;
            elseif(str_starts_with($name,'child:'))$children[$name]=$value;
            else throw new \RuntimeException('Invalid PostgreSQL content section name.');
        }
        foreach(self::CHILDREN as $name=>$fields)foreach($fields as $field){
            $child='child:'.$name.':'.$field;
            if(isset($db[$name][$field])&&is_array($db[$name][$field])){
                if($db[$name][$field]!==[]||!isset($children[$child])||!is_array($children[$child]))throw new \RuntimeException('Missing or invalid PostgreSQL child section.');
                $db[$name][$field]=$children[$child];unset($children[$child]);
            }
        }
        if($children)throw new \RuntimeException('Unexpected PostgreSQL child section.');
        return $db;
    }
    /** Keep PHP array ordering and scalar types: JSON (not JSONB) preserves snapshot/digest semantics. */
    private function persist(PDO $pdo,array $before,array $after):void
    {
        $previous=$before;$next=$after;
        if($before!==[])PostgresSearch::assertReady($pdo,$this->params());
        $before=self::sections($before);$after=self::sections($after);$p=$this->params();
        $oldPositions=array_flip(array_keys($before));$position=0;
        foreach($before as $name=>$value)if(!array_key_exists($name,$after))$this->query($pdo,'DELETE FROM cajeer_book.content_section WHERE installation=? AND scope=? AND name=?',[...$p,(string)$name]);
        foreach($after as $name=>$value){
            $name=(string)$name;$oldExists=array_key_exists($name,$before);$old=$before[$name]??null;$array=is_array($value);
            if(!$oldExists||$oldPositions[$name]!==$position||is_array($old)!==$array||(!$array&&$old!==$value)){
                // Remove old child rows before switching an array into a scalar.
                if(!$array&&is_array($old))$this->query($pdo,'DELETE FROM cajeer_book.content_record WHERE installation=? AND scope=? AND section=?',[...$p,$name]);
                $this->query($pdo,'INSERT INTO cajeer_book.content_section(installation,scope,name,position,kind,value) VALUES(?,?,?,?,?,CAST(? AS json)) ON CONFLICT(installation,scope,name) DO UPDATE SET position=EXCLUDED.position,kind=EXCLUDED.kind,value=EXCLUDED.value',[...$p,$name,$position,$array?'array':'scalar',$array?null:self::json($value)]);
            }
            $position++;
            if(!$array)continue;
            $old=is_array($old)?$old:[];$positions=array_flip(array_keys($old));$i=0;
            foreach($old as $key=>$unused)if(!array_key_exists($key,$value))$this->query($pdo,'DELETE FROM cajeer_book.content_record WHERE installation=? AND scope=? AND section=? AND record_key=?',[...$p,$name,(string)$key]);
            foreach($value as $key=>$item){
                if(str_contains((string)$key,"\0"))throw new \DomainException('Invalid PostgreSQL record key.');
                if(!array_key_exists($key,$old)||$positions[$key]!==$i||$old[$key]!==$item)$this->query($pdo,'INSERT INTO cajeer_book.content_record(installation,scope,section,record_key,position,value) VALUES(?,?,?,?,?,CAST(? AS json)) ON CONFLICT(installation,scope,section,record_key) DO UPDATE SET position=EXCLUDED.position,value=EXCLUDED.value',[...$p,$name,(string)$key,$i,self::json($item)]);
                $i++;
            }
        }
        $this->query($pdo,'UPDATE cajeer_book.content_store SET revision=revision+1 WHERE installation=? AND scope=?',$p);
        PostgresSearch::sync($pdo,$p,$previous,$next);
    }
    public function search(string $visibility,string $role,array $options,bool $navigation):array
    {
        $pdo=$this->db();$pdo->beginTransaction();
        try{$this->lock($pdo,false);PostgresSearch::assertReady($pdo,$this->params());
            $compute=fn()=>PostgresSearch::find($pdo,$this->params(),$visibility,$role,$options,$navigation);
            $redis=$visibility==='public'&&!Permissions::allows($role,'read')?RedisRuntime::forBinding($this->namespace,$this->binding):null;
            if($redis){
                // The row lock pins the revision; search-state xmin changes on rebuild as well as publication.
                $revision=$this->query($pdo,'SELECT revision::text || chr(58) || xmin::text FROM cajeer_book.search_state WHERE installation=? AND scope=?',$this->params())->fetchColumn();
                $result=$redis->remember('search',['0.4.6',$this->scope,$revision,$options,$navigation],$compute);
            }else $result=$compute();
            $pdo->commit();return $result;}
        catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function checkSearch():void{$this->searchInfo();}
    /** Offline schema upgrade/rebuild; all scopes commit together, including archived ones. */
    public static function reindex(string $root):array
    {
        $binding=self::identityBinding($root);if($binding===null)throw new \RuntimeException('PostgreSQL required.');
        return (new IdentityStore($root))->installationSnapshot(function(array $identity)use($root,$binding){
            Workspaces::assertRegistryDirectories($root,$identity);self::assertRegistry($root,$identity);
            $pdo=self::connect();$pdo->beginTransaction();
            try{
                $pdo->exec('SELECT pg_advisory_xact_lock(1128416305, 1)');
                $sql=file_get_contents(dirname(__DIR__).'/sql/postgres-content.sql');if($sql===false)throw new \RuntimeException('Missing PostgreSQL schema.');$pdo->exec($sql);
                foreach(['search_simple_gin','search_english_gin','search_russian_gin','search_title_trgm','search_path_trgm','search_scope_locale'] as $index){
                    $valid=$pdo->query("SELECT indisvalid FROM pg_index WHERE indexrelid='cajeer_book.".$index."'::regclass")->fetchColumn();
                    if(!in_array($valid,[true,1,'1','t'],true))$pdo->exec('REINDEX INDEX cajeer_book.'.$index);
                }
                $report=self::rebuildInstallation($pdo,$binding[0],$binding[1]);$pdo->commit();return $report;
            }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        });
    }
    public function searchInfo():array
    {
        $pdo=$this->db();$pdo->beginTransaction();try{
            $this->lock($pdo,false);PostgresSearch::assertReady($pdo,$this->params());
            $count=(int)$this->query($pdo,'SELECT count(*) FROM cajeer_book.search_document WHERE installation=? AND scope=?',$this->params())->fetchColumn();
            $expected=(int)$this->query($pdo,"SELECT count(*) FROM cajeer_book.pages WHERE installation=? AND scope=? AND value->>'published' IS NOT NULL",$this->params())->fetchColumn();
            if($count!==$expected)throw new \RuntimeException('Search document count mismatch; reindex required.');
            $indexes=(int)$pdo->query("SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE i.indrelid='cajeer_book.search_document'::regclass AND i.indisvalid AND c.relname IN ('search_simple_gin','search_english_gin','search_russian_gin','search_title_trgm','search_path_trgm','search_scope_locale')")->fetchColumn();
            if($indexes!==6)throw new \RuntimeException('Search indexes missing or invalid; reindex required.');
            $revision=(int)$this->query($pdo,'SELECT revision FROM cajeer_book.search_state WHERE installation=? AND scope=?',$this->params())->fetchColumn();
            $pdo->commit();return ['engine'=>'postgresql','documents'=>$count,'revision'=>$revision];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public static function searchStatus(string $root):array
    {
        if(self::identityBinding($root)===null)throw new \RuntimeException('PostgreSQL required.');
        return (new IdentityStore($root))->installationSnapshot(function(array $identity)use($root){
            self::assertRegistry($root,$identity);$dirs=[$root];foreach($identity['workspaces'] as $w)if(!$w['legacy'])$dirs[]=Workspaces::directory($root,$w);
            $result=[];foreach($dirs as $dir){$store=self::forDirectory($dir);$result[$store->scope]=$store->searchInfo()['documents'];}ksort($result);return $result;
        });
    }
    private static function rebuildInstallation(PDO $pdo,string $id,string $binding):array
    {
        $q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$id]);if($q->fetchColumn()!==$binding)throw new \RuntimeException('PostgreSQL installation binding mismatch.');
        $q=$pdo->prepare('SELECT scope FROM cajeer_book.content_store WHERE installation=? ORDER BY scope FOR UPDATE');$q->execute([$id]);$scopes=$q->fetchAll(PDO::FETCH_COLUMN);if(!in_array('legacy',$scopes,true))throw new \RuntimeException('Missing legacy scope.');
        $report=[];foreach($scopes as $scope){$store=new self($id,$scope,$binding);$data=$store->load($pdo);BookStore::validateDatabase($data);PostgresSearch::sync($pdo,[$id,$scope],[],$data,true);$report[$scope]=count(BookStore::published($data));}ksort($report);return $report;
    }
    public function write(array $before,array $after,array $events=[]):void
    {
        $pdo=$this->db();if(!$pdo->inTransaction())throw new \LogicException('Content write outside transaction.');
        if($events)AuditLog::append($pdo,$this->namespace,$this->scope,$events);
        if($before!==$after){$this->persist($pdo,$before,$after);
            foreach($after['automation']['jobs']??[] as $id=>$job){$old=$before['automation']['jobs'][$id]??null;
                if($job['state']==='queued'&&(($old['state']??null)!=='queued'||($old['due']??null)!==$job['due'])){$this->wake=true;break;}}
        }
    }
    public function audit(array $events):void {AuditLog::append($this->db(),$this->namespace,$this->scope,$events);}
    private function insert(PDO $pdo,array $data,bool $verifyExisting):void
    {
        $n=$this->query($pdo,'INSERT INTO cajeer_book.content_store(installation,scope) VALUES(?,?) ON CONFLICT DO NOTHING',$this->params())->rowCount();
        $this->lock($pdo,true);
        if($n===0){if(!$verifyExisting||$this->load($pdo)!==$data)throw new \RuntimeException('PostgreSQL scope already exists with different content; refusing overwrite.');return;}
        $this->persist($pdo,[],$data);
        if($this->load($pdo)!==$data)throw new \RuntimeException('PostgreSQL import round-trip mismatch.');
    }
    /** Create a scope on the identity transaction, never on a separate connection. */
    public function initializeInTransaction(PDO $pdo,array $data):void
    {
        if(!$pdo->inTransaction())throw new \LogicException('Workspace initialization outside transaction.');
        $q=$this->query($pdo,'SELECT binding FROM cajeer_book.content_installation WHERE installation=?',[$this->namespace]);
        if($q->fetchColumn()!==$this->binding)throw new \RuntimeException('PostgreSQL installation binding mismatch.');
        $this->insert($pdo,$data,false);
    }
    public function initialize(array $data):void
    {
        $pdo=$this->db();$pdo->beginTransaction();
        try{$this->insert($pdo,$data,false);$pdo->commit();}catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    /** Called while the identity lock is held: never omit orphan SQL content from a backup. */
    public static function assertRegistry(string $root,array $identity):void
    {
        $store=self::forDirectory($root);if($store===null)return;
        $expected=['legacy'];foreach($identity['workspaces']??[] as $w)if(!$w['legacy'])$expected[]=$w['organization'].'/'.$w['id'];
        $q=$store->query($store->db(),'SELECT scope FROM cajeer_book.content_store WHERE installation=?',[$store->namespace]);
        $actual=$q->fetchAll(PDO::FETCH_COLUMN);sort($expected,SORT_STRING);sort($actual,SORT_STRING);
        if($actual!==$expected)throw new \RuntimeException('PostgreSQL scopes do not match the workspace registry; inspect and preserve unregistered data before backup.');
    }
    /** Offline all-workspace migration. No source files are deleted or modified. */
    public static function migrate(string $root):array
    {
        if($root!==self::configuredRoot())throw new \RuntimeException('Migration root must match BOOK_DATA_DIR.');
        $id=self::namespace();$marker=self::markerData($root);
        if($marker!==null&&$marker[0]!==$id)throw new \RuntimeException('PostgreSQL namespace mismatch.');
        $pdo=self::connect();
        $sql=file_get_contents(dirname(__DIR__).'/sql/postgres-content.sql');if($sql===false)throw new \RuntimeException('Missing PostgreSQL schema.');
        $pdo->beginTransaction();
        try{
            // Serialize schema creation across installation namespaces in a shared database.
            $pdo->exec('SELECT pg_advisory_xact_lock(1128416305, 1)');$pdo->exec($sql);
            $identitySql=file_get_contents(dirname(__DIR__).'/sql/postgres-identity.sql');if($identitySql===false)throw new \RuntimeException('Missing PostgreSQL identity schema.');$pdo->exec($identitySql);AuditLog::schema($pdo);
            if((int)$pdo->query('SELECT version FROM cajeer_book.content_format WHERE singleton=true')->fetchColumn()!==1)throw new \RuntimeException('Unsupported PostgreSQL content format.');
            $pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        // 0.4.1 already has SQL content. Never import its stale books.json again.
        if($marker!==null){
            $check=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$check->execute([$id]);
            if($check->fetchColumn()!==$marker[1])throw new \RuntimeException('PostgreSQL installation binding mismatch.');
            $identityMarker=self::markerData($root,self::IDENTITY_MARKER);
            if($identityMarker!==null){
                if($identityMarker!==$marker)throw new \RuntimeException('PostgreSQL identity binding mismatch.');
                throw new \RuntimeException('PostgreSQL identity already migrated; use installation check or restore the matched backup.');
            }
            return (new IdentityStore($root,true))->migrationSnapshot(function(array $identity)use($root,$id,$pdo,$marker){
                if(self::markerData($root,self::IDENTITY_MARKER)!==null)throw new \RuntimeException('PostgreSQL identity already migrated.');
                Workspaces::assertRegistryDirectories($root,$identity);self::assertRegistry($root,$identity);
                $pdo->beginTransaction();
                try{
                    $guard=$pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,1128416305))');$guard->execute([$id]);
                    // Validate current SQL content and media, not legacy JSON, before importing identity.
                    $dirs=[$root=>Workspaces::legacy($identity)===null];
                    foreach($identity['workspaces'] as $w)if(!$w['legacy'])$dirs[Workspaces::directory($root,$w)]=false;
                    $report=[];
                    foreach($dirs as $dir=>$seed){$store=new BookStore($dir,$seed);$audit=(new StoreAudit($store,new MediaStore($dir,$store)))->run();if($audit['reference_mismatches']!==0)throw new \RuntimeException('Repair media references before identity migration.');$data=$store->read();BookStore::checkSize($data);$report[self::scope($root,$dir)]=['pages'=>count($data['pages']),'media'=>count($data['media']??[]),'history'=>count($data['history']??[])];}
                    self::rebuildInstallation($pdo,$id,$marker[1]);
                    (new PostgresIdentityStore($id,$marker[1]))->import($pdo,$identity);AuditLog::initialize($pdo,$id);$pdo->commit();
                    Files::write($root.'/'.self::IDENTITY_MARKER,$id.':'.$marker[1]."\n");return $report;
                }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            });
        }
        $report=(new IdentityStore($root,true))->migrationSnapshot(function(array $identity)use($root,$id,$pdo){
            if(self::markerData($root)!==null)throw new \RuntimeException('Installation already switched to PostgreSQL.');
            Workspaces::assertRegistryDirectories($root,$identity);
            $pending=self::markerData($root,self::PENDING);
            $identityMarker=self::markerData($root,self::IDENTITY_MARKER);
            if($identityMarker!==null&&$identityMarker!==$pending)throw new \RuntimeException('Incomplete migration identity marker mismatch; restore matched backup.');
            if($pending!==null&&$pending[0]!==$id)throw new \RuntimeException('Unfinished PostgreSQL migration uses a different namespace.');
            $binding=$pending[1]??bin2hex(random_bytes(16));
            if($pending===null)Files::write($root.'/'.self::PENDING,$id.':'.$binding."\n");
            $dirs=[$root=>Workspaces::legacy($identity)===null];
            foreach($identity['workspaces'] as $w)if(!$w['legacy'])$dirs[Workspaces::directory($root,$w)]=false;
            $pdo->beginTransaction();$report=[];
            try{
                // A concurrent migration using the same namespace must finish before checking its scopes.
                $guard=$pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 1128416305))');$guard->execute([$id]);
                $bind=$pdo->prepare('INSERT INTO cajeer_book.content_installation(installation,binding) VALUES(?,?) ON CONFLICT(installation) DO NOTHING');$bind->execute([$id,$binding]);
                $bind=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$bind->execute([$id]);
                if($bind->fetchColumn()!==$binding)throw new \RuntimeException('PostgreSQL namespace belongs to another installation; refusing to attach.');
                $existing=$pdo->prepare('SELECT scope FROM cajeer_book.content_store WHERE installation=?');$existing->execute([$id]);
                $scopes=array_map(fn($dir)=>self::scope($root,$dir),array_keys($dirs));
                if(array_diff($existing->fetchAll(PDO::FETCH_COLUMN),$scopes))throw new \RuntimeException('PostgreSQL namespace contains unrelated workspaces; use a new namespace.');
                foreach($dirs as $dir=>$seed){
                    $source=new BookStore($dir,$seed,true);$media=new MediaStore($dir,$source);
                    $target=new self($id,self::scope($root,$dir),$binding);
                    if((new StoreAudit($source,$media))->run()['reference_mismatches']!==0)throw new \RuntimeException('Repair legacy media references before migration: '.$target->scope);
                    $report[$target->scope]=$source->snapshot(function(array $data)use($target,$pdo){
                        BookStore::validateDatabase($data);BookStore::checkSize($data);
                        $target->insert($pdo,$data,true);
                        return ['pages'=>count($data['pages']),'media'=>count($data['media']??[]),'history'=>count($data['history']??[])];
                    });
                }
                self::rebuildInstallation($pdo,$id,$binding);
                (new PostgresIdentityStore($id,$binding))->import($pdo,$identity);AuditLog::initialize($pdo,$id);
                $pdo->commit();
            }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            // If marker creation fails after commit, rerun offline; existing rows must match exactly.
            Files::write($root.'/'.self::IDENTITY_MARKER,$id.':'.$binding."\n");
            Files::write($root.'/'.self::MARKER,$id.':'.$binding."\n");
            try{Files::remove($root.'/'.self::PENDING);}catch(\Throwable $e){error_log('PostgreSQL migration marker cleanup: '.$e->getMessage());}
            return $report;
        });
        return $report;
    }
}
