<?php
declare(strict_types=1);
namespace CajeerBook;

/** Local operator report. Never serialize exception messages, configuration, paths or store records. */
final class AdminReport
{
    private array $checks=[];
    private function probe(string $id, callable $fn, string $remedy): bool
    {
        $start=hrtime(true);
        try{$fn();$status='ok';}catch(\Throwable){$status='failed';}
        $this->checks[$id]=['status'=>$status,'duration_ms'=>round((hrtime(true)-$start)/1e6,2),'action'=>$status==='ok'?null:$remedy];
        return $status==='ok';
    }
    private function skip(string $id,string $reason):void
    {
        $this->checks[$id]=['status'=>'skipped','duration_ms'=>0,'action'=>$reason];
    }
    public function collect(string $project,bool $deep=false,bool $oidc=false,bool $monitoring=false):array
    {
        $this->checks=[];$root=null;$binding=null;$identity=null;
        $pg=(bool)getenv('BOOK_PG_DSN');$redis=RedisRuntime::enabled();
        $runtime=$this->probe('runtime',fn()=>ReleaseHealth::check($project),'Install locked Composer dependencies, required PHP extensions and local Mermaid assets; check configuration.');
        $config=$this->probe('configuration',fn()=>ReleaseHealth::configuration(),'Check primary host, secure cookies, OIDC, Redis topology and PostgreSQL settings.');
        $storage=$this->probe('storage',function()use(&$root){
            $root=PostgresContentStore::configuredRoot();
            if(!is_dir($root)||!is_readable($root)||!is_writable($root))throw new \RuntimeException('Storage unavailable');
            $free=disk_free_space($root);if($free===false||$free<=0)throw new \RuntimeException('No space');
        },'Mount the existing private data volume and verify runtime UID permissions and disk space.');
        $bound=false;
        if($storage)$bound=$this->probe('binding',function()use($root,&$binding){$binding=PostgresContentStore::identityBinding($root);},'Restore matching PostgreSQL settings and binding markers; do not delete markers or reimport stale JSON.');
        else $this->skip('binding','Storage must pass first.');
        $sql=false;
        if($pg&&$bound)$sql=$this->probe('postgres_schema',function()use($binding){
            if(!$binding)throw new \RuntimeException('Binding missing');
            $pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$binding[0]]);
            if($q->fetchColumn()!==$binding[1]||!DatabaseMigrations::status($pdo)['compatible'])throw new \RuntimeException('Schema mismatch');
        },'Check writable primary, credentials, binding and bin/migrations.php verify. Use offline migration only with a verified backup.');
        else $this->skip('postgres_schema',$pg?'Binding must pass first.':'PostgreSQL not configured.');
        $ready=false;
        if($config&&$bound&&(!$pg||$sql)){
            $identity=new IdentityStore($root);
            $ready=$this->probe('identity',function()use($identity){$identity->checkReady();$identity->installationSnapshot(static function(array $db){if(empty($db['users']))throw new \RuntimeException('Owner not initialized');});},'Check identity anchor and owner; initialize only a new installation. Restore a matched backup if identity is damaged.');
        }else $this->skip('identity','Configuration, binding and configured SQL must pass first.');
        if($ready)$this->probe('workspace_stores',fn()=>$identity->checkWorkspaceStores($root),'Check registered workspace directories, content, media and search. Do not delete orphan stores.');
        else $this->skip('workspace_stores','Identity must pass first.');
        if($redis&&$sql&&$config)$this->probe('redis',function()use($root){$r=RedisRuntime::forRoot($root);if(!$r)throw new \RuntimeException('Redis unavailable');$r->check();},'Check Redis primary/Sentinel, authentication and TLS. Do not flush shared Redis.');
        else $this->skip('redis',$redis?'Configured SQL and configuration must pass first.':'Redis not configured.');
        foreach(['BOOK_GITHUB_KEY'=>'git_keys','BOOK_AUTOMATION_KEY'=>'automation_keys','BOOK_BACKUP_KEY'=>'backup_keys'] as $env=>$id){
            if(getenv($env)||getenv($env.'_FILE'))$this->probe($id,fn()=>SecretKeys::ring($env,$env==='BOOK_BACKUP_KEY'),'Check private key file permissions, active key and retained keys; never print key values.');
            else $this->skip($id,'Key not configured in this process.');
        }
        if($deep&&$runtime&&$ready){
            $this->probe('installation_integrity',fn()=>InstallationBackup::audit($root,$project.'/public'),'Run installation.php check privately; preserve data before repairs.');
            if($pg&&$sql)$this->probe('audit_chain',function()use($root){[$pdo,$id]=AuditLog::connection($root);AuditLog::verify($pdo,$id);},'Run audit.php check and compare external signed checkpoints. Preserve evidence.');
            else $this->skip('audit_chain','PostgreSQL not configured or unavailable.');
            $this->probe('active_credentials',function()use($identity,$root){$identity->installationSnapshot(function(array $db)use($root){
                $stores=[new BookStore($root,Workspaces::legacy($db)===null)];foreach($db['workspaces'] as $w)if(!$w['legacy'])$stores[]=new BookStore(Workspaces::directory($root,$w),false);
                foreach($stores as $store)$store->snapshot(function(array $state){GitHubSync::verifyKey($state);AutomationQueue::verifyKey($state);});
            });},'Run security.php verify privately; retain old keys until all live credentials are rewrapped.');
        }else foreach(['installation_integrity','audit_chain','active_credentials'] as $id)$this->skip($id,$deep?'Runtime and identity must pass first.':'Use --deep for maintenance checks.');
        if($oidc&&$config&&$ready)$this->probe('oidc_discovery',function()use($identity){ $configs=Oidc::configs();if(!$configs)throw new \RuntimeException('OIDC not configured');foreach($configs as $c){$identity->checkOidcOrganization($c['organization']);Oidc::discovery($c);} },'Check IdP discovery, CA trust, organization and configured issuer.');
        elseif($oidc)$this->checks['oidc_discovery']=['status'=>'failed','duration_ms'=>0,'action'=>'Configuration and identity must pass first.'];
        else $this->skip('oidc_discovery','Use --oidc to contact the configured provider.');
        if($monitoring){$this->probe('monitoring',function(){if(!Observability::enabled())throw new \RuntimeException('Telemetry disabled');$d=(new Diagnostics())->collect();if($d['status']!=='ok')throw new \RuntimeException('Dependency unavailable');},'Run diagnostics.php status as the diagnostics service; inspect expected worker, backup, DNS and TLS checks.');}
        else $this->skip('monitoring','Use --monitoring on the configured diagnostics service.');
        // Explicitly requested deep checks must not silently succeed when blocked.
        $failed=false;foreach($this->checks as $c)if($c['status']==='failed')$failed=true;
        return ['format'=>1,'version'=>trim((string)file_get_contents($project.'/VERSION')),'engine_version'=>EngineCore::VERSION,'schema_required'=>DatabaseMigrations::VERSION,'backend'=>$pg?'postgresql':'file','redis_configured'=>$redis,'status'=>$failed?'unavailable':'ok','checks'=>$this->checks];
    }
}
