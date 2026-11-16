<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;
use PDO;

/** A named WAL target plus authenticated portable sidecar. Never imports SQL data on physical restore. */
final class PhysicalRecovery
{
    public static function label(string $label):string{
        if(!preg_match('/\A[0-9]{8}-[0-9]{6}F(?:_[0-9]{8}-[0-9]{6}[DI])?\z/D',$label))throw new \InvalidArgumentException('Invalid pgBackRest backup label.');return $label;
    }
    public static function point(string $id):string{RecoveryManager::id($id);return 'book_'.substr($id,-32);}
    public static function validate(array $m,string $id):array{
        if(($m['format']??null)!==1||($m['id']??null)!==$id||($m['point']??null)!==self::point($id)||!is_string($m['backup']??null)||!is_string($m['system_id']??null)||!preg_match('/\A[0-9]{1,20}\z/D',$m['system_id'])||!is_string($m['database']??null)||!preg_match('/\A[a-zA-Z0-9_]{1,63}\z/D',$m['database'])||!is_string($m['lsn']??null)||!preg_match('/\A[0-9A-F]{1,8}\/[0-9A-F]{1,8}\z/D',$m['lsn'])||!is_string($m['wal']??null)||!preg_match('/\A[0-9A-F]{24}\z/D',$m['wal'])||!is_int($m['timeline']??null)||$m['timeline']<1||hexdec(substr($m['wal'],0,8))!==$m['timeline']||!is_int($m['captured_at']??null)||$m['captured_at']<1||!is_string($m['namespace']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$m['namespace'])||!is_string($m['binding']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$m['binding']))throw new \RuntimeException('Invalid physical recovery metadata.');
        self::label($m['backup']);return $m;
    }
    private static function root():string{return PostgresContentStore::configuredRoot();}
    private static function publicDir():string{return dirname(__DIR__).'/public';}
    private static function spool():string{
        $p=getenv('BOOK_PITR_DIR')?:'';if($p==='')throw new \RuntimeException('Configure BOOK_PITR_DIR outside application data.');
        $p=DataDirectory::resolve($p,self::publicDir());RecoveryRemote::separate($p,self::root());
        if(!is_dir($p)||!is_writable($p)||((fileperms($p)&0077)!==0))throw new \RuntimeException('PITR spool must be a private existing directory.');return $p;
    }
    private static function remote(string $spool,bool $readOnly=false):RecoveryRemote{
        $r=new RecoveryRemote(self::root(),$spool,self::publicDir(),$readOnly);if($r->mode()==='local')throw new \RuntimeException('PITR requires independent remote sidecar storage.');return $r;
    }
    private static function stage(string $spool,&$lock):string{
        $lock=fopen(Files::path($spool,'pitr.lock'),'c+b');if(!$lock)throw new \RuntimeException('Cannot open PITR lock.');
        try{chmod($spool.'/pitr.lock',0600);if(!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('Another PITR operation is active.');
            foreach(new \DirectoryIterator($spool) as $f)if(preg_match('/\A\.pitr-[a-f0-9]{32}\z/D',$f->getFilename()))self::clean($f->getPathname());
            $p=$spool.'/.pitr-'.bin2hex(random_bytes(16));if(!mkdir($p,0700))throw new \RuntimeException('Cannot create PITR staging.');return $p;
        }catch(\Throwable $e){fclose($lock);throw $e;}
    }
    private static function clean(string $p):void{if(is_link($p)||is_file($p)){unlink($p);return;}if(is_dir($p)){foreach(new \DirectoryIterator($p) as $f)if(!$f->isDot())self::clean($f->getPathname());rmdir($p);}}
    private static function control():PDO{
        $user=getenv('BOOK_PITR_PG_USER')?:null;$pass=getenv('BOOK_PITR_PG_PASSWORD');
        return PostgresContentStore::connect($user,$pass===false?null:$pass);
    }
    private static function cluster(PDO $pdo):array{
        $r=$pdo->query("SELECT system_identifier::text AS system_id,current_database() AS database, current_setting('archive_mode') AS archive_mode, current_setting('wal_level') AS wal_level, CASE WHEN pg_is_in_recovery() THEN 1 ELSE 0 END AS recovery FROM pg_control_system()")->fetch(PDO::FETCH_ASSOC);
        if(!$r||!preg_match('/\A[0-9]{1,20}\z/D',$r['system_id']))throw new \RuntimeException('Cannot identify PostgreSQL cluster.');return $r;
    }
    private static function binding(PDO $pdo,array $b):void{
        $q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$b[0]]);if($q->fetchColumn()!==$b[1])throw new \RuntimeException('Physical recovery installation mismatch.');
        if((int)$pdo->query('SELECT count(*) FROM cajeer_book.content_installation')->fetchColumn()!==1)throw new \RuntimeException('Physical recovery requires a dedicated Book database with one installation.');
    }
    public static function capture(string $label):array{
        self::label($label);$root=self::root();$binding=PostgresContentStore::identityBinding($root);if(!$binding)throw new \RuntimeException('Physical backup requires PostgreSQL.');
        $spool=self::spool();$remote=self::remote($spool);$pdo=self::control();$cluster=self::cluster($pdo);self::binding($pdo,$binding);
        if((int)$cluster['recovery']!==0||$cluster['archive_mode']!=='on'||!in_array($cluster['wal_level'],['replica','logical'],true))throw new \RuntimeException('Writable primary with WAL archive_mode=on required.');
        if((int)$pdo->query("SELECT count(*) FROM pg_database WHERE NOT datistemplate AND datname NOT IN (current_database(),'postgres')")->fetchColumn()!==0)throw new \RuntimeException('Physical recovery requires a dedicated PostgreSQL cluster.');
        if((int)$pdo->query("SELECT count(*) FROM pg_tablespace WHERE spcname NOT IN ('pg_default','pg_global')")->fetchColumn()!==0)throw new \RuntimeException('External tablespaces require a separate recovery procedure.');
        $id=gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(16));$lock=null;$stage=self::stage($spool,$lock);$meta=null;
        try{
            InstallationBackup::create($root,$stage.'/bundle',self::publicDir(),function()use($pdo,$cluster,$binding,$id,$label,&$meta){
                // Identity and all content scope locks are held by InstallationBackup.
                $q=$pdo->prepare('SELECT pg_create_restore_point(?)::text');$q->execute([self::point($id)]);$lsn=$q->fetchColumn();
                $q=$pdo->prepare('SELECT pg_walfile_name(CAST(? AS pg_lsn))');$q->execute([$lsn]);$wal=$q->fetchColumn();
                $meta=self::validate(['format'=>1,'id'=>$id,'point'=>self::point($id),'backup'=>$label,'system_id'=>$cluster['system_id'],'database'=>$cluster['database'],'namespace'=>$binding[0],'binding'=>$binding[1],'lsn'=>$lsn,'wal'=>$wal,'timeline'=>(int)hexdec(substr($wal,0,8)),'captured_at'=>time()],$id);
                $pdo->query('SELECT pg_switch_wal()')->fetchColumn();return $meta;
            });
            $key=RecoveryEnvelope::key();try{RecoveryEnvelope::seal($stage.'/bundle',$stage.'/snapshot.cbk',$key,$id);}finally{sodium_memzero($key);}
            $local=$spool.'/'.$id.'.cbk';if(!link($stage.'/snapshot.cbk',$local))throw new \RuntimeException('Cannot publish local PITR sidecar.');Files::syncDir($spool);
            // Local immutable copy remains available if remote is temporarily unavailable.
            try{self::upload($id,$local,$remote,$stage);}catch(\Throwable $e){throw new \RuntimeException('PITR remote verification failed; retry local ID '.$id,0,$e);}return $meta+['sidecar_sha256'=>Files::hash($local),'sidecar_remote'=>$remote->mode()];
        }finally{try{self::clean($stage);}finally{fclose($lock);}}
    }
    private static function upload(string $id,string $local,RecoveryRemote $remote,string $stage):void{
        $remote->upload($id,$local);$remote->download($id,$stage.'/readback.cbk');if(!hash_equals(Files::hash($local),Files::hash($stage.'/readback.cbk')))throw new \RuntimeException('PITR sidecar remote verification failed.');
    }
    private static function unpack(string $id,string $stage,RecoveryRemote $remote,bool $local=false):array{
        RecoveryManager::id($id);$file=$stage.'/snapshot.cbk';if($local)$file=self::spool().'/'.$id.'.cbk';else $remote->download($id,$file);
        $bundle=$stage.'/bundle';mkdir($bundle,0700);$key=RecoveryEnvelope::keyFor($file,$id);try{RecoveryEnvelope::open($file,$bundle,$key,$id);}finally{sodium_memzero($key);}
        $m=Files::read($bundle.'/manifest.json');return self::validate($m['pitr']??[],$id);
    }
    public static function inspect(string $id,bool $retry=false):array{
        RecoveryManager::id($id);$spool=self::spool();$remote=self::remote($spool,!$retry);$lock=null;$stage=self::stage($spool,$lock);
        try{$m=self::unpack($id,$stage,$remote,$retry);if($retry)self::upload($id,$spool.'/'.$id.'.cbk',$remote,$stage);return $m;}finally{try{self::clean($stage);}finally{fclose($lock);}}
    }
    private static function same(mixed $a,mixed $b):bool{
        // JSON round trip normalizes numeric encoding, but keeps maps, lists and record order.
        return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)===json_encode($b,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
    /** Restore to a new app directory only after the isolated physical server has reached the exact WAL target. */
    public static function restore(string $id,string $target):array{
        RecoveryManager::id($id);$spool=self::spool();$remote=self::remote($spool,true);$lock=null;$stage=self::stage($spool,$lock);$old=getenv('BOOK_DATA_DIR');$start=hrtime(true);
        try{
            $m=self::unpack($id,$stage,$remote);$target=DataDirectory::resolve($target,self::publicDir());RecoveryRemote::separate($target,$spool);RecoveryRemote::separate($target,self::root());
            if(file_exists($target)||is_link($target)||!is_dir(dirname($target)))throw new \RuntimeException('Restore requires a new application data directory.');
            if(PostgresContentStore::namespace()!==$m['namespace'])throw new \RuntimeException('Restored namespace must match authenticated sidecar.');
            if(RedisRuntime::enabled())throw new \RuntimeException('Disable Redis during recovery; use an empty separate Redis instance before cutover.');
            $pdo=self::control();$c=self::cluster($pdo);self::binding($pdo,[$m['namespace'],$m['binding']]);
            if($c['system_id']!==$m['system_id']||$c['database']!==$m['database']||(int)$c['recovery']!==0||$c['archive_mode']!=='off')throw new \RuntimeException('Use isolated promoted recovery cluster with archive_mode=off.');
            $q=$pdo->prepare("SELECT CASE WHEN pg_last_wal_replay_lsn()=CAST(? AS pg_lsn) AND substring(pg_walfile_name(pg_current_wal_lsn()),1,8)<>? THEN 1 ELSE 0 END");$q->execute([$m['lsn'],substr($m['wal'],0,8)]);if((int)$q->fetchColumn()!==1)throw new \RuntimeException('Recovery has not reached exactly the authenticated restore point on a new timeline.');
            // Stage beside target for atomic publication; never touch the live application directory.
            $dest=dirname($target).'/.pitr-app-'.bin2hex(random_bytes(16));$bundle=$stage.'/bundle';
            try{
                InstallationBackup::restore($bundle,$dest,self::publicDir());$identity=Files::read($bundle.'/identity.json');
                $marker=$m['namespace'].':'.$m['binding']."\n";Files::write($dest.'/postgres.required',$marker);Files::write($dest.'/postgres.identity.required',$marker);putenv('BOOK_DATA_DIR='.$dest);
                $i=new IdentityStore($dest);$i->installationSnapshot(function($actual)use($identity,$dest,$bundle,$m){
                    if(!self::same($identity,$actual))throw new \RuntimeException('Restored SQL identity differs from sidecar.');
                    PostgresContentStore::assertRegistry($dest,$actual);
                    PostgresContentStore::freezeInstallation($dest,function()use($actual,$dest,$bundle,$m){
                        $stores=['legacy.tar'=>[$dest,Workspaces::legacy($actual)===null]];foreach($actual['workspaces'] as $w)if(!$w['legacy'])$stores[$w['id'].'.tar']=[Workspaces::directory($dest,$w),false];
                        foreach($stores as $name=>[$dir,$seed]){$tar=new \PharData($bundle.'/'.$name);$expected=json_decode($tar['books.json']->getContent(),true,512,JSON_THROW_ON_ERROR);$db=(new BookStore($dir,$seed))->read();if(!self::same($expected,$db))throw new \RuntimeException('Restored SQL content differs from sidecar: '.$name);}
                        [$audit,$ns]=AuditLog::connection($dest);$heads=AuditLog::verify($audit,$ns);$expected=AuditSnapshot::verify($bundle.'/audit-'.$m['namespace'].'.jsonl');foreach($expected['streams'] as &$head)unset($head['stream']);unset($head);if($heads!==$expected['streams'])throw new \RuntimeException('Restored audit chain differs from sidecar.');
                    });
                });
                $report=InstallationBackup::audit($dest,self::publicDir());PostgresContentStore::searchStatus($dest);
                // The portable copy contains historical audit and file DB snapshots. SQL is authoritative.
                // Keep the portable snapshots only in the encrypted sidecar, not in the active data tree.
                Files::remove($dest.'/books.json');Files::remove($dest.'/identity/identity.json');Files::remove($dest.'/audit-history/audit-'.$m['namespace'].'.jsonl');foreach($identity['workspaces'] as $w)if(!$w['legacy'])Files::remove(Workspaces::directory($dest,$w).'/books.json');
                $i->invalidateRecoveredAccess();
                if(file_exists($target)||is_link($target)||!rename($dest,$target))throw new \RuntimeException('Cannot publish recovered application directory.');Files::syncDir(dirname($target));
                return ['status'=>'restored_isolated','id'=>$id,'point'=>$m['point'],'replay_lsn'=>$m['lsn'],'capture_age_seconds'=>max(0,time()-$m['captured_at']),'app_restore_seconds'=>(hrtime(true)-$start)/1e9,'report'=>$report];
            }finally{self::clean($dest);}
        }finally{putenv($old===false?'BOOK_DATA_DIR':'BOOK_DATA_DIR='.$old);try{self::clean($stage);}finally{fclose($lock);}}
    }
}
