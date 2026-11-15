<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
use PDO;

/** PostgreSQL content storage. Identity and immutable media bytes remain on disk in 0.4.1. */
final class PostgresContentStore
{
    private const MARKER='postgres.required';
    private const PENDING='postgres.migrating';
    private const CHILDREN=['ci'=>['tokens','builds'],'automation'=>['jobs','receipts']];
    private ?PDO $connection=null;
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
    public static function forDirectory(string $dir):?self
    {
        $dir=rtrim($dir,'/');
        if(!getenv('BOOK_PG_DSN')){
            if(self::markerData($dir)!==null||self::markerData(self::rootOf($dir))!==null||self::markerData($dir,self::PENDING)!==null||self::markerData(self::rootOf($dir),self::PENDING)!==null)throw new \RuntimeException('PostgreSQL content is required or migration is incomplete; configure BOOK_PG_DSN.');
            return null;
        }
        $root=self::configuredRoot();$scope=self::scope($root,$dir);
        $id=self::namespace();$marker=self::markerData($root);
        if($marker===null||$marker[0]!==$id)throw new \RuntimeException('PostgreSQL content is not initialized for this installation; run bin/postgres-content.php migrate offline.');
        return new self($id,$scope,$marker[1]);
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
        $pdo=$this->db();$pdo->beginTransaction();
        try{
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->lock($pdo,$write);$result=$fn($this->load($pdo));$pdo->commit();return $result;
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
    }
    public function write(array $before,array $after):void
    {
        $pdo=$this->db();if(!$pdo->inTransaction())throw new \LogicException('Content write outside transaction.');
        if($before!==$after)$this->persist($pdo,$before,$after);
    }
    private function insert(PDO $pdo,array $data,bool $verifyExisting):void
    {
        $n=$this->query($pdo,'INSERT INTO cajeer_book.content_store(installation,scope) VALUES(?,?) ON CONFLICT DO NOTHING',$this->params())->rowCount();
        $this->lock($pdo,true);
        if($n===0){if(!$verifyExisting||$this->load($pdo)!==$data)throw new \RuntimeException('PostgreSQL scope already exists with different content; refusing overwrite.');return;}
        $this->persist($pdo,[],$data);
        if($this->load($pdo)!==$data)throw new \RuntimeException('PostgreSQL import round-trip mismatch.');
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
        $id=self::namespace();$marker=self::marker($root);
        if($marker!==null)throw new \RuntimeException('Installation already switched to PostgreSQL; use installation check, not migration.');
        $pdo=self::connect();
        $sql=file_get_contents(dirname(__DIR__).'/sql/postgres-content.sql');if($sql===false)throw new \RuntimeException('Missing PostgreSQL schema.');
        $pdo->beginTransaction();
        try{
            // Serialize schema creation across installation namespaces in a shared database.
            $pdo->exec('SELECT pg_advisory_xact_lock(1128416305, 1)');$pdo->exec($sql);
            if((int)$pdo->query('SELECT version FROM cajeer_book.content_format WHERE singleton=true')->fetchColumn()!==1)throw new \RuntimeException('Unsupported PostgreSQL content format.');
            $pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $report=(new IdentityStore($root))->installationSnapshot(function(array $identity)use($root,$id,$pdo){
            if(self::markerData($root)!==null)throw new \RuntimeException('Installation already switched to PostgreSQL.');
            Workspaces::assertRegistryDirectories($root,$identity);
            $pending=self::markerData($root,self::PENDING);
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
                $pdo->commit();
            }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            // If marker creation fails after commit, rerun offline; existing rows must match exactly.
            Files::write($root.'/'.self::MARKER,$id.':'.$binding."\n");
            try{Files::remove($root.'/'.self::PENDING);}catch(\Throwable $e){error_log('PostgreSQL migration marker cleanup: '.$e->getMessage());}
            return $report;
        });
        return $report;
    }
}
