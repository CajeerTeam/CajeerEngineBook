<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;
require_once __DIR__.'/Observability.php';

/** Durable operator scheduler, encrypted repository and destructive-free recovery drills. */
final class RecoveryManager
{
    private string $root;private string $spool;private RecoveryRemote $remote;
    private int $interval;private int $retention;
    public function __construct(private readonly string $public){
        $this->root=PostgresContentStore::configuredRoot();
        $dir=getenv('BOOK_BACKUP_DIR')?:'';if($dir==='')throw new \InvalidArgumentException('BOOK_BACKUP_DIR is required.');
        $this->spool=DataDirectory::resolve($dir,$public);RecoveryRemote::separate($this->root,$this->spool);
        Files::dir($this->spool);if((fileperms($this->spool)&0077)!==0)throw new \RuntimeException('Backup directory must be private (0700).');
        $this->remote=new RecoveryRemote($this->root,$this->spool,$public);
        $this->interval=self::number('BOOK_BACKUP_INTERVAL',86400,300,604800);
        $this->retention=self::number('BOOK_BACKUP_RETENTION_DAYS',30,1,3650);
    }
    private static function number(string $name,int $default,int $min,int $max):int{
        $v=getenv($name);if($v===false||$v==='')return $default;if(!ctype_digit($v)||strlen($v)>10||(int)$v<$min||(int)$v>$max)throw new \InvalidArgumentException('Invalid '.$name);return (int)$v;
    }
    public static function id(string $id):string{if(!preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}\z/D',$id))throw new \InvalidArgumentException('Invalid backup ID.');return $id;}
    private function artifact(string $id):string{return Files::path($this->spool,self::id($id).'.cbk');}
    private function report(string $id):string{return Files::path($this->spool,self::id($id).'.json');}
    private function fingerprint():string{return hash('sha256',$this->root."\n".(getenv('BOOK_PG_NAMESPACE')?:'')."\n".$this->remote->identity());}
    private function state():array{
        $path=Files::path($this->spool,'state.json');clearstatcache(true,$path);if(!is_file($path))return ['format'=>1,'repository'=>$this->fingerprint(),'last_success'=>0,'last_capture'=>0,'last_id'=>'','last_attempt'=>0,'failures'=>0,'pending'=>null];
        if(filesize($path)>16384)throw new \RuntimeException('Invalid recovery state.');$s=Files::read($path);
        if(($s['format']??null)!==1||($s['repository']??null)!==$this->fingerprint()||!is_int($s['last_success']??null)||!is_int($s['last_capture']??null)||!is_int($s['last_attempt']??null)||!is_int($s['failures']??null)||!is_string($s['last_id']??null)||!array_key_exists('pending',$s))throw new \RuntimeException('Recovery state invalid or repository changed; use a new spool.');
        if($s['last_id']!=='')self::id($s['last_id']);
        if($s['pending']!==null){if(!is_array($s['pending'])||!is_string($s['pending']['id']??null)||!is_int($s['pending']['captured_at']??null)||!is_string($s['pending']['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$s['pending']['sha256']))throw new \RuntimeException('Invalid pending backup state.');self::id($s['pending']['id']);}
        return $s;
    }
    private function save(array $s):void{Files::json($this->spool.'/state.json',$s);Observability::backup($s);}
    private function locked(callable $fn):mixed{
        $path=Files::path($this->spool,'scheduler.lock');$h=fopen($path,'c+b');if(!$h)throw new \RuntimeException('Backup lock unavailable.');
        try{chmod($path,0600);if(!flock($h,LOCK_EX|LOCK_NB))throw new \RuntimeException('Another recovery operation is running.');
            // Recover private plaintext staging left by a killed process. Never touch published files.
            foreach(new \DirectoryIterator($this->spool) as $f)if(preg_match('/\A\.recovery-[a-f0-9]{32}\z/D',$f->getFilename()))self::clean($f->getPathname());
            return $fn();
        }finally{fclose($h);}
    }
    private function stage():string{$p=$this->spool.'/.recovery-'.bin2hex(random_bytes(16));if(!mkdir($p,0700))throw new \RuntimeException('Cannot allocate recovery staging.');return $p;}
    private static function clean(string $p):void{
        if(is_link($p)||is_file($p)){if(!unlink($p))throw new \RuntimeException('Cannot remove recovery staging file.');return;}if(!is_dir($p))return;
        foreach(new \DirectoryIterator($p) as $f)if(!$f->isDot())self::clean($f->getPathname());if(!rmdir($p))throw new \RuntimeException('Cannot remove recovery staging directory.');
    }
    private function audit(string $action,bool $failure=false):void{
        if(!getenv('BOOK_PG_DSN'))return;
        $i=new IdentityStore($this->root);if($failure)$i->auditFailure($action,'failure');else $i->operatorAudit($action);
    }
    /** A real restore, never against live BOOK_DATA_DIR. Optional SQL drill uses a new namespace
     * in an operator-provisioned separate database and validates indexes and the new audit chain. */
    private function drillFile(string $file,string $stage,string $key,string $id):array{
        $bundle=$stage.'/bundle';mkdir($bundle,0700);RecoveryEnvelope::open($file,$bundle,$key,$id);
        $restored=$stage.'/restored';InstallationBackup::restore($bundle,$restored,$this->public);
        $report=InstallationBackup::audit($restored,$this->public,true);
        $dsn=getenv('BOOK_BACKUP_DRILL_PG_DSN')?:'';
        if($dsn!=='')$report['postgres_drill']=$this->postgresDrill($restored,$dsn);
        self::clean($bundle);self::clean($restored);return $report;
    }
    private function postgresDrill(string $restored,string $dsn):array{
        if(!str_starts_with($dsn,'pgsql:')||$dsn===getenv('BOOK_PG_DSN'))throw new \InvalidArgumentException('Drill requires a separate PostgreSQL database.');
        $sourceNamespace=getenv('BOOK_PG_DSN')?PostgresContentStore::namespace():null;
        $ns=bin2hex(random_bytes(16));$env=['BOOK_DATA_DIR'=>$restored,'BOOK_PG_DSN'=>$dsn,'BOOK_PG_NAMESPACE'=>$ns,'BOOK_PG_USER'=>getenv('BOOK_BACKUP_DRILL_PG_USER')?:'','BOOK_PG_PASSWORD'=>getenv('BOOK_BACKUP_DRILL_PG_PASSWORD')?:'','BOOK_REDIS_HOST'=>''];$old=[];
        foreach($env as $name=>$value){$old[$name]=getenv($name);putenv($name.'='.$value);}
        try{
            // DSNs can alias the same database (hostname, port, options or credentials).
            // Refuse a database containing the source installation before creating any schema.
            if($sourceNamespace!==null){
                $probe=PostgresContentStore::connect();
                if(is_string($probe->query("SELECT to_regclass('cajeer_book.content_installation')::text")->fetchColumn())){
                    $q=$probe->prepare('SELECT 1 FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$sourceNamespace]);
                    if($q->fetchColumn()!==false)throw new \RuntimeException('Drill database contains the source installation. Use a separate database.');
                }
            }
            PostgresContentStore::migrate($restored);$result=InstallationBackup::audit($restored,$this->public);$result['search']=PostgresContentStore::searchStatus($restored);
            [$pdo,$id]=AuditLog::connection($restored);$result['audit_streams']=count(AuditLog::verify($pdo,$id));return $result;
        }finally{
            try{
                // Only remove this drill's randomly generated, marker-bound namespace.
                $binding=null;foreach(['postgres.required','postgres.migrating'] as $marker){$p=$restored.'/'.$marker;if(is_file($p)&&!is_link($p)&&preg_match('/\A([a-f0-9]{32}):([a-f0-9]{32})\n\z/D',(string)file_get_contents($p),$m)&&$m[1]===$ns){$binding=[$m[1],$m[2]];break;}}if($binding!==null){$pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=? AND binding=?');$q->execute($binding);// A failed migration may already have rolled back the namespace.
                if($q->rowCount()>1)throw new \RuntimeException('Drill namespace cleanup failed.');}
            }finally{foreach($old as $name=>$value)putenv($value===false?$name:$name.'='.$value);}
        }
    }
    /** Run now, or only when due. Retries retain the same immutable encrypted local copy. */
    public function run(bool $force=false):array{
        $start=hrtime(true);$ok=false;try{$r=$this->runBackup($force);$ok=true;if($r['status']!=='not_due')Observability::log('backup.run',['outcome'=>'ok']);return $r;}catch(\Throwable $e){Observability::log('backup.failure',['error_type'=>get_class($e)]);throw $e;}finally{Observability::operation('backup_run',$start,$ok);try{if(Observability::enabled())Observability::backup($this->state());}catch(\Throwable){}Observability::flush();}
    }
    private function runBackup(bool $force):array{return $this->locked(function()use($force){
        $s=$this->state();$now=time();
        if(!$force&&(($s['failures']>0&&$s['last_attempt']+300>$now)||($s['pending']===null&&$s['last_capture']+$this->interval>$now)))return ['status'=>'not_due','state'=>$s];
        $s['last_attempt']=$now;$this->save($s);$stage=$this->stage();$key=null;
        try{
            $key=RecoveryEnvelope::key();$checked=null;
            if($s['pending']===null){
                $captured=time();$id=gmdate('Ymd\THis\Z',$captured).'-'.bin2hex(random_bytes(16));$tmp=$stage.'/snapshot.cbk';
                InstallationBackup::create($this->root,$stage.'/source',$this->public);RecoveryEnvelope::seal($stage.'/source',$tmp,$key,$id);self::clean($stage.'/source');
                // Prove the local copy is recoverable before it is uploaded or published.
                $checked=$this->drillFile($tmp,$stage,$key,$id);
                $sha=Files::hash($tmp);if(!link($tmp,$this->artifact($id)))throw new \RuntimeException('Cannot publish local backup.');Files::syncDir($this->spool);
                $s['pending']=['id'=>$id,'sha256'=>$sha,'captured_at'=>$captured];$this->save($s);
            }
            $id=$s['pending']['id'];$sha=$s['pending']['sha256'];$file=$this->artifact($id);
            if(!hash_equals($sha,Files::hash($file)))throw new \RuntimeException('Pending local backup is damaged.');
            if($this->remote->mode()!=='local'){
                $this->remote->upload($id,$file);$download=$stage.'/download.cbk';$this->remote->download($id,$download);
                if(!hash_equals($sha,Files::hash($download)))throw new \RuntimeException('Remote read-back digest mismatch.');$file=$download;
            }
            $checked=($this->remote->mode()==='local'&&$checked!==null)?$checked:$this->drillFile($file,$stage,$key,$id);
            $report=['id'=>$id,'sha256'=>$sha,'bytes'=>filesize($file),'captured_at'=>$s['pending']['captured_at'],'verified_at'=>time(),'remote'=>$this->remote->mode(),'restore'=>$checked];
            $this->audit('backup.verified');Files::json($this->report($id),$report);
            $s['last_success']=time();$s['last_capture']=$s['pending']['captured_at'];$s['last_id']=$id;$s['pending']=null;$s['failures']=0;$this->save($s);
            try{$this->prune($s['last_id']);}catch(\Throwable $e){Observability::log('backup.retention_failure',['error_type'=>get_class($e)]);$report['retention_warning']=true;}return ['status'=>'verified']+$report;
        }catch(\Throwable $e){$s['failures']=min(1000000,$s['failures']+1);$s['last_attempt']=time();$this->save($s);try{$this->audit('backup.failed',true);}catch(\Throwable){}throw $e;}
        finally{if(is_string($key))sodium_memzero($key);self::clean($stage);}
    });}
    private function prune(string $latest):void{
        $verified=[];foreach(new \DirectoryIterator($this->spool) as $f){if(!$f->isFile()||$f->isLink()||!preg_match('/\A([0-9]{8}T[0-9]{6}Z-[a-f0-9]{32})\.json\z/D',$f->getFilename(),$m))continue;
            $r=Files::read($f->getPathname());if(($r['id']??null)!==$m[1]||!is_int($r['verified_at']??null))throw new \RuntimeException('Invalid backup report.');$verified[$m[1]]=$r['verified_at'];}
        arsort($verified,SORT_NUMERIC);$keep=array_slice(array_keys($verified),0,2);foreach($verified as $id=>$at)if($id!==$latest&&!in_array($id,$keep,true)&&$at<time()-$this->retention*86400){Files::remove($this->artifact($id));Files::remove($this->report($id));}
    }
    /** Non-mutating probe, safe during an active backup. A verified *capture* is the RPO. */
    public function health(int $maxAge):array{
        if($maxAge<300||$maxAge>1209600)throw new \InvalidArgumentException('Invalid backup RPO limit.');
        $s=$this->state();$age=$s['last_capture']>0?max(0,time()-$s['last_capture']):null;
        $ok=$age!==null&&$age<=$maxAge&&$s['last_capture']<=time()&&$s['last_id']!=='';
        return ['status'=>$ok?'ok':'unavailable','capture_age_seconds'=>$age,'max_age_seconds'=>$maxAge,'pending'=>$s['pending']!==null,'failures'=>$s['failures'],'last_id'=>$s['last_id']];
    }
    public function status():array{return $this->locked(fn()=>['state'=>$this->state(),'remote'=>$this->remote->mode(),'interval_seconds'=>$this->interval,'retention_days'=>$this->retention]);}
    /** Restore from a local or remote ID. No requirement for the source installation to be online. */
    public function restore(string $id,string $target,bool $remote=false):array{return $this->locked(function()use($id,$target,$remote){
        self::id($id);$target=DataDirectory::resolve($target,$this->public);RecoveryRemote::separate($target,$this->root);RecoveryRemote::separate($target,$this->spool);if(file_exists($target)||is_link($target)||!is_dir(dirname($target)))throw new \InvalidArgumentException('Restore requires a new directory with existing parent.');
        $stage=$this->stage();$key=null;try{$key=RecoveryEnvelope::key();
            $file=$this->artifact($id);if($remote){$file=$stage.'/download.cbk';$this->remote->download($id,$file);}
            mkdir($stage.'/bundle',0700);RecoveryEnvelope::open($file,$stage.'/bundle',$key,$id);
            InstallationBackup::restore($stage.'/bundle',$target,$this->public);return InstallationBackup::audit($target,$this->public,true);
        }finally{if(is_string($key))sodium_memzero($key);self::clean($stage);}
    });}
    public function drill(string $id,bool $remote=false):array{return $this->locked(function()use($id,$remote){
        self::id($id);$stage=$this->stage();$key=null;try{$key=RecoveryEnvelope::key();$file=$this->artifact($id);if($remote){$file=$stage.'/download.cbk';$this->remote->download($id,$file);}return $this->drillFile($file,$stage,$key,$id);}
        finally{if(is_string($key))sodium_memzero($key);self::clean($stage);}
    });}
}
