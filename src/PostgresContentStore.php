<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
use PDO;
require_once __DIR__.'/PostgresSearch.php';
require_once __DIR__.'/Search.php';
require_once __DIR__.'/RedisRuntime.php';
require_once __DIR__.'/Observability.php';

/** PostgreSQL content storage. Immutable media bytes remain on disk; identity shares the installation binding. */
final class PostgresContentStore
{
    private const MARKER='postgres.required';
    private const PENDING='postgres.migrating';
    private const IDENTITY_MARKER='postgres.identity.required';
    private const CHILDREN=['ci'=>['tokens','builds'],'automation'=>['jobs','receipts']];
    private ?PDO $connection=null;
    private static ?PDO $backupConnection=null;
    private static ?string $backupNamespace=null;
    private bool $wake=false;
    private ?array $readPlans=null;
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
    public static function connect(?string $user=null,?string $password=null):PDO
    {
        $dsn=getenv('BOOK_PG_DSN')?:'';
        if(!str_starts_with($dsn,'pgsql:'))throw new \RuntimeException('BOOK_PG_DSN must be a PostgreSQL PDO DSN.');
        if(!in_array('pgsql',PDO::getAvailableDrivers(),true))throw new \RuntimeException('PostgreSQL requires the PHP pdo_pgsql extension.');
        $start=hrtime(true);$ok=false;
        try{
            $pdo=new PDO($dsn,$user??(getenv('BOOK_PG_USER')?:null),$password??(getenv('BOOK_PG_PASSWORD')?:null),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_PERSISTENT=>false,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
            $pdo->exec("SET search_path TO pg_catalog; SET lock_timeout TO '30s'; SET statement_timeout TO '120s'; SET client_encoding TO 'UTF8'");
            $ok=true;return $pdo;
        }catch(\PDOException $e){throw new \RuntimeException('PostgreSQL content connection failed.',0,$e);}finally{Observability::operation('sql_connect',$start,$ok);}
    }
    /** Runtime connections pin the database-wide schema; raw connect is operator/test only. */
    public static function runtimeConnect():PDO
    {
        $pdo=self::connect();
        if((int)$pdo->query("SELECT CASE WHEN NOT pg_is_in_recovery() AND current_setting('transaction_read_only')='off' THEN 1 ELSE 0 END")->fetchColumn()!==1)throw new \RuntimeException('PostgreSQL writable primary required.');
        DatabaseMigrations::pin($pdo);return $pdo;
    }
    private function db():PDO{
        if($this->connection===null){
            $pdo=self::runtimeConnect();
            if((int)$pdo->query('SELECT version FROM cajeer_book.content_format WHERE singleton=true')->fetchColumn()!==1)throw new \RuntimeException('Unsupported PostgreSQL content format.');
            $q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$this->namespace]);
            if($q->fetchColumn()!==$this->binding)throw new \RuntimeException('PostgreSQL installation binding mismatch; verify database and data directory.');
            $this->connection=$pdo;
        }
        return $this->connection;
    }
    private function query(PDO $pdo,string $sql,array $args=[]):\PDOStatement
    {
        $start=hrtime(true);$ok=false;try{
            if($this->readPlans!==null&&str_starts_with($sql,'SELECT ')){$ex=$pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql);$ex->execute($args);$this->readPlans[]=self::decode($ex->fetchColumn());}
            $s=$pdo->prepare($sql);$s->execute($args);$ok=true;return $s;
        }finally{Observability::operation('sql_content_query',$start,$ok);}
    }
    private function params():array{return [$this->namespace,$this->scope];}
    private static function json(mixed $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $v):mixed{return json_decode($v,true,512,JSON_THROW_ON_ERROR);}
    private function lock(PDO $pdo,bool $write):void
    {
        $start=hrtime(true);$ok=false;try{$r=$this->query($pdo,'SELECT revision FROM cajeer_book.content_store WHERE installation=? AND scope=? '.($write?'FOR UPDATE':'FOR SHARE'),$this->params())->fetchColumn();
        if($r===false)throw new \RuntimeException('PostgreSQL workspace content missing; restore or migrate it explicitly.');$ok=true;}finally{Observability::operation('sql_content_lock',$start,$ok);}
    }
    /** Called under the identity snapshot lock. Reuse this read connection for
     * nested snapshots so a queued exclusive writer cannot deadlock the backup. */
    public static function freezeInstallation(string $root,callable $fn):void
    {
        if(self::$backupConnection!==null)throw new \LogicException('Nested installation freeze.');
        $binding=self::identityBinding($root);if(!$binding)throw new \LogicException('PostgreSQL required.');
        $pdo=self::runtimeConnect();$pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT scope FROM cajeer_book.content_store WHERE installation=? ORDER BY scope FOR SHARE');$q->execute([$binding[0]]);$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
            self::$backupConnection=$pdo;self::$backupNamespace=$binding[0];$fn();$pdo->commit();
        }finally{self::$backupConnection=null;self::$backupNamespace=null;if($pdo->inTransaction())$pdo->rollBack();}
    }
    /** A single row lock pins all sections/records and the media lifecycle for the workspace. */
    public function transaction(bool $write,callable $fn):mixed
    {
        if(self::$backupConnection!==null){if($write||$this->namespace!==self::$backupNamespace)throw new \LogicException('Write or foreign scope during backup snapshot.');return $fn($this->load(self::$backupConnection));}
        $start=hrtime(true);$ok=false;$pdo=$this->db();$pdo->beginTransaction();$this->wake=false;
        try{
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->lock($pdo,$write);$result=$fn($this->load($pdo));$pdo->commit();
            if($this->wake)RedisRuntime::forBinding($this->namespace,$this->binding)?->notify($this->scope);
            $ok=true;return $result;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{Observability::operation('sql_content_transaction',$start,$ok);}
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
            // Bound both parameter count and bytes. A bulk publish/import no longer
            // incurs one SQL round trip per changed record, while preserving JSON
            // array ordering, FK constraints and the surrounding transaction.
            $removed=[];foreach($old as $key=>$unused)if(!array_key_exists($key,$value))$removed[]=(string)$key;
            foreach(array_chunk($removed,128) as $chunk)$this->query($pdo,'DELETE FROM cajeer_book.content_record WHERE installation=? AND scope=? AND section=? AND record_key IN ('.implode(',',array_fill(0,count($chunk),'?')).')',[...$p,$name,...$chunk]);
            $batch=[];$bytes=0;
            $flush=function()use($pdo,&$batch,&$bytes){
                if(!$batch)return;
                $this->query($pdo,'INSERT INTO cajeer_book.content_record(installation,scope,section,record_key,position,value) VALUES '.implode(',',array_fill(0,count($batch),'(?,?,?,?,?,CAST(? AS json))')).' ON CONFLICT(installation,scope,section,record_key) DO UPDATE SET position=EXCLUDED.position,value=EXCLUDED.value',array_merge(...$batch));
                $batch=[];$bytes=0;
            };
            foreach($value as $key=>$item){
                if(str_contains((string)$key,"\0"))throw new \DomainException('Invalid PostgreSQL record key.');
                if(!array_key_exists($key,$old)||$positions[$key]!==$i||$old[$key]!==$item){
                    $json=self::json($item);$size=strlen($json)+strlen((string)$key);
                    if(count($batch)>=64||($batch&&$bytes+$size>2097152))$flush();
                    $batch[]=[...$p,$name,(string)$key,$i,$json];$bytes+=$size;
                }
                $i++;
            }
            $flush();
        }
        $this->query($pdo,'UPDATE cajeer_book.content_store SET revision=revision+1 WHERE installation=? AND scope=?',$p);
        PostgresSearch::sync($pdo,$p,$previous,$next);
    }
    /** Selective published reads. The scope lock pins both authorization and publication
     * until the result (or media file descriptor) has been obtained. No draft/history load.
     */
    private function publishedRead(callable $fn):mixed
    {
        if(self::$backupConnection!==null)throw new \LogicException('Reader projection is not a backup snapshot.');
        $pdo=$this->db();$pdo->beginTransaction();$start=hrtime(true);$ok=false;
        try{$this->lock($pdo,false);$result=$fn($pdo);$pdo->commit();$ok=true;return $result;}
        catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{Observability::operation('sql_content_transaction',$start,$ok);}
    }
    private const PUBLIC_KEY="coalesce(cajeer_book.reader_json(p.value)->>'published_key',p.record_key)";
    private const PUBLISHED="json_typeof(cajeer_book.reader_json(p.value)->'published')='object'";
    private static function publishedWhere(string $visibility,string $role):string
    {
        $where='p.installation=? AND p.scope=? AND '.self::PUBLISHED." AND split_part(p.record_key,'/',1)=split_part(".self::PUBLIC_KEY.", '/',1) AND split_part(p.record_key,'/',2)=split_part(".self::PUBLIC_KEY.", '/',2)";
        if(Permissions::allows($role,'read'))return $where;
        if($visibility!=='public')return $where.' AND false';
        // A missing book row is the legacy implicit public book. The publication
        // key is validated to remain in the draft's book/version by BookStore.
        return $where." AND NOT EXISTS (SELECT 1 FROM cajeer_book.books b WHERE b.installation=p.installation AND b.scope=p.scope AND b.section='root:books' AND b.record_key=split_part(".self::PUBLIC_KEY.", '/', 1) AND (cajeer_book.reader_json(b.value)->'visibility' IS NULL OR cajeer_book.reader_json(b.value)->>'visibility' IN ('inherit','public')) IS NOT TRUE)";
    }
    private function publishedRows(PDO $pdo,string $visibility,string $role,?string $key=null,?string $id=null,string $projection='full',bool $legacyId=false):array
    {
        $where=self::publishedWhere($visibility,$role);$args=$this->params();
        if($key!==null){$where.=' AND '.self::PUBLIC_KEY.'=?';$args[]=$key;}
        if($id!==null){$where.=$legacyId?" AND cajeer_book.reader_json(p.value)->>'id' IS NULL AND substr(encode(sha256(convert_to('cajeerbook-page:'||p.record_key,'UTF8')),'hex'),1,32)=?":" AND cajeer_book.reader_json(p.value)->>'id'=?";$args[]=$id;}
        // Only routing metadata is sanitized by reader_json. For rare escaped NULs
        // (or a literal backslash-u0000 sequence), decode the original entry in PHP.
        // This preserves arbitrary valid Markdown and exact legacy snapshot values.
        $snapshot=match($projection){
            'navigation'=>"(SELECT json_object_agg(k,v ORDER BY n) FROM json_each(cajeer_book.reader_json(p.value)->'published') WITH ORDINALITY f(k,v,n) WHERE k IN ('title','section','order','locale','translation_group'))::text",
            'list'=>"(SELECT json_object_agg(k,v ORDER BY n) FROM json_each(cajeer_book.reader_json(p.value)->'published') WITH ORDINALITY f(k,v,n) WHERE k NOT IN ('body','link_base','playground','source_digest','translation_group'))::text",
            'full'=>"(cajeer_book.reader_json(p.value)->'published')::text",default=>throw new \LogicException('Unknown published projection.')};
        $raw="strpos(p.value::text,chr(92)||'u0000')>0";
        $sql="SELECT p.record_key, ".self::PUBLIC_KEY." AS public_key,cajeer_book.reader_json(p.value)->>'id' AS id,($raw) AS raw_entry,CASE WHEN $raw THEN p.value::text ELSE $snapshot END AS snapshot FROM cajeer_book.pages p WHERE $where ORDER BY p.position";
        $out=[];$seen=[];foreach($this->query($pdo,$sql,$args) as $row){
            $p=self::decode($row['snapshot']);
            if($row['raw_entry']){$p=$p['published']??null;if(!is_array($p))throw new \RuntimeException('Invalid published snapshot.');$p=match($projection){'navigation'=>array_intersect_key($p,array_flip(['title','section','order','locale','translation_group'])),'list'=>array_diff_key($p,array_flip(['body','link_base','playground','source_digest','translation_group'])),'full'=>$p};}
            $draft=$row['record_key'];$public=$row['public_key'];
            if(!PageTree::validKey($draft)||!PageTree::validKey($public)||PageTree::scope($draft)!==PageTree::scope($public)||isset($seen[$public])||!is_array($p)||!is_string($p['title']??null)||!is_string($p['section']??null)||!is_int($p['order']??null)||($row['id']!==null&&!preg_match('/\A[a-f0-9]{32}\z/D',$row['id'])))throw new \RuntimeException('Invalid published row.');
            Locale::language($p);if(isset($p['translation_group'])&&(!is_string($p['translation_group'])||!preg_match('/\A[a-f0-9]{32}\z/D',$p['translation_group'])))throw new \RuntimeException('Invalid translation group.');
            if($projection==='full'){if(!is_string($p['body']??null)||!is_string($p['updated']??null)||!is_array($p['media']??[]))throw new \RuntimeException('Invalid published snapshot.');ApiPlayground::validateSnapshot($p);Locale::validateSnapshot($p);}
            $seen[$public]=true;$entry=['published'=>$p,'published_key'=>$public];if($row['id']!==null)$entry['id']=$row['id'];$out[$draft]=$entry;
        }
        return $out;
    }
    private function rootRecords(PDO $pdo,string $section,?string $key=null):array
    {
        $sql='SELECT record_key,value::text AS value FROM cajeer_book.content_record WHERE installation=? AND scope=? AND section=?';$args=[...$this->params(),'root:'.$section];
        if($key!==null){$sql.=' AND record_key=?';$args[]=$key;}
        $sql.=' ORDER BY position';$out=[];foreach($this->query($pdo,$sql,$args) as $row)$out[$row['record_key']]=self::decode($row['value']);return $out;
    }
    /** Return the exact published snapshot, or a visible redirect, without reading siblings. */
    public function publishedPage(string $key,string $visibility,string $role):array
    {
        if(!PageTree::validKey($key))return ['page'=>null,'redirect'=>null];
        return $this->publishedRead(function(PDO $pdo)use($key,$visibility,$role){
            $rows=$this->publishedRows($pdo,$visibility,$role,$key);
            if($rows){$entry=reset($rows);return ['page'=>$entry['published']+['id'=>PageTree::id((string)array_key_first($rows),$entry)],'redirect'=>null];}
            $id=$this->rootRecords($pdo,'redirects',$key)[$key]??null;
            if(is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)){
                $targets=$this->publishedRows($pdo,$visibility,$role,null,$id,'navigation');
                if(!$targets)$targets=$this->publishedRows($pdo,$visibility,$role,null,$id,'navigation',true);
                foreach($targets as $draft=>$entry){$target=PageTree::publicKey($draft,$entry);if($target!==$key&&PageTree::scope($target)===PageTree::scope($key))return ['page'=>null,'redirect'=>$target];}
            }
            return ['page'=>null,'redirect'=>null];
        });
    }
    /** Only the requested document has its body loaded; navigation uses compact snapshots. */
    public function readerSnapshot(string $visibility,string $role,?string $key,bool $apiList=false):array
    {
        return $this->publishedRead(function(PDO $pdo)use($visibility,$role,$key,$apiList){
            $db=['pages'=>$this->publishedRows($pdo,$visibility,$role,null,null,$apiList?'list':'navigation'),'books'=>$this->rootRecords($pdo,'books')];
            if($apiList)return $db;
            foreach(['redirects','retired_paths'] as $section)$db[$section]=$this->rootRecords($pdo,$section);
            if($key!==null){foreach($this->publishedRows($pdo,$visibility,$role,$key) as $draft=>$entry)$db['pages'][$draft]=$entry;
                // OpenAPI operation buttons need the reviewed specification; other Reader pages don't.
                if(preg_match('~/operations/(?:get|head|post|put|patch|delete|options)-[a-f0-9]{24}$~D',$key))$db['openapi']=$this->rootRecords($pdo,'openapi');}
            return $db;
        });
    }
    /** Authorize a published reference in SQL, then open the blob under the scope lock. */
    public function publishedMedia(string $id,string $visibility,string $role,callable $fn):mixed
    {
        return $this->publishedRead(function(PDO $pdo)use($id,$visibility,$role,$fn){
            $sql="SELECT 1 FROM cajeer_book.pages p WHERE ".self::publishedWhere($visibility,$role)." AND CAST(cajeer_book.reader_json(p.value)->'published'->'media' AS jsonb) @> CAST(? AS jsonb) LIMIT 1";
            if($this->query($pdo,$sql,[...$this->params(),self::json([$id])])->fetchColumn()===false)return null;
            $item=$this->rootRecords($pdo,'media',$id)[$id]??null;return $item===null?null:$fn($item);
        });
    }
    /** CLI-only profiling of the actual optimized queries, not a synthetic substitute. */
    public function profilePublished(string $key,string $mediaId):array
    {
        if(PHP_SAPI!=='cli'||$this->readPlans!==null)throw new \LogicException('Published profiling requires an isolated CLI invocation.');
        $out=[];
        try{foreach(['page'=>fn()=>$this->publishedPage($key,'public','none'),'navigation'=>fn()=>$this->readerSnapshot('public','none',$key),'media'=>fn()=>$this->publishedMedia($mediaId,'public','none',fn($item)=>$item)] as $name=>$fn){
            $this->readPlans=[];$result=$fn();$out[$name]=['plans'=>$this->readPlans,'result_bytes'=>strlen(self::json($result))];
        }return $out;}finally{$this->readPlans=null;}
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
        $repair=self::connect();$q=$repair->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$binding[0]]);
        if($q->fetchColumn()!==$binding[1])throw new \RuntimeException('PostgreSQL installation binding mismatch.');
        DatabaseMigrations::repairSearchIndexes($repair);$repair=null;
        return (new IdentityStore($root))->installationSnapshot(function(array $identity)use($root,$binding){
            Workspaces::assertRegistryDirectories($root,$identity);self::assertRegistry($root,$identity);
            $pdo=self::runtimeConnect();$pdo->beginTransaction();
            try{
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
        // A completed installation must use the schema engine, never the legacy import.
        if($marker!==null){
            $check=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$check->execute([$id]);
            if($check->fetchColumn()!==$marker[1])throw new \RuntimeException('PostgreSQL installation binding mismatch.');
            $identityMarker=self::markerData($root,self::IDENTITY_MARKER);
            if($identityMarker!==null)throw new \RuntimeException('PostgreSQL identity already migrated; use bin/migrations.php apply --offline or restore the matched backup.');
        }
        DatabaseMigrations::prepareInstallation($pdo,$marker!==null);
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
            try{Files::remove($root.'/'.self::PENDING);}catch(\Throwable $e){Observability::log('migration.cleanup_failure',['error_type'=>get_class($e)]);}
            return $report;
        });
        return $report;
    }
}
