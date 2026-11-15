<?php
declare(strict_types=1);
namespace CajeerBook;

/** Operator-only diagnostics; component failures do not hide the remaining samples. */
final class Diagnostics
{
    private array $metrics=[];
    private array $checks=[];
    private function probe(string $name,callable $fn):void{
        $start=hrtime(true);$ok=false;
        try{$fn();$ok=true;}catch(\Throwable $e){Observability::log('diagnostics.failure',['component'=>$name,'error_type'=>get_class($e)]);}
        $this->checks[$name]=$ok?'ok':'unavailable';$this->metrics['dependency_up{component="'.$name.'"}']=$ok?1:0;
        $this->metrics['dependency_probe_duration_seconds{component="'.$name.'"}']=(hrtime(true)-$start)/1e9;
    }
    private static function q(\PDO $pdo,string $sql,array $args=[]):\PDOStatement{$q=$pdo->prepare($sql);$q->execute($args);return $q;}
    public function collect():array{
        $this->metrics=[];$this->checks=[];
        $this->probe('telemetry',function(){$this->metrics+=Observability::snapshot();});
        $root=null;$this->probe('storage',function()use(&$root){$root=PostgresContentStore::configuredRoot();if(!is_dir($root)||!is_readable($root)||!is_writable($root))throw new \RuntimeException('Storage unavailable');$free=disk_free_space($root);if($free===false)throw new \RuntimeException('Storage unavailable');$this->metrics['storage_free_bytes']=$free;});
        $this->metrics+=Observability::httpDefaults();
        $this->probe('configuration',fn()=>ReleaseHealth::configuration());
        $this->probe('runtime',fn()=>ReleaseHealth::check(dirname(__DIR__)));
        $pg=(bool)getenv('BOOK_PG_DSN');$redis=RedisRuntime::enabled();$this->metrics['postgres_configured']=$pg?1:0;$this->metrics['redis_configured']=$redis?1:0;
        if($pg){
            $pdo=null;$id=null;
            $this->probe('postgres',function()use($root,&$pdo,&$id){
                if($root===null)throw new \RuntimeException('Storage unavailable');$binding=PostgresContentStore::identityBinding($root);if(!$binding)throw new \RuntimeException('No binding');
                $pdo=PostgresContentStore::connect();$pdo->exec("SET lock_timeout='2s'; SET statement_timeout='3s'");DatabaseMigrations::pin($pdo);
                if(self::q($pdo,'SELECT binding FROM cajeer_book.content_installation WHERE installation=?',[$binding[0]])->fetchColumn()!==$binding[1])throw new \RuntimeException('Wrong installation');$id=$binding[0];
            });
            $this->probe('identity',function()use($pdo,$id){if(!$pdo||!$id||!self::q($pdo,'SELECT 1 FROM cajeer_book.identity_state WHERE installation=?',[$id])->fetchColumn())throw new \RuntimeException('Identity unavailable');});
            $this->probe('search',function()use($pdo,$id){if(!$pdo||!$id)throw new \RuntimeException('Search unavailable');$n=self::q($pdo,'SELECT count(*) FROM cajeer_book.content_store c LEFT JOIN cajeer_book.search_state s USING(installation,scope) WHERE c.installation=? AND s.revision IS DISTINCT FROM c.revision',[$id])->fetchColumn();if((int)$n!==0)throw new \RuntimeException('Search stale');$pdo->query('SELECT locale FROM cajeer_book.search_document LIMIT 0');});
            $this->probe('audit',function()use($pdo,$id){if(!$pdo||!$id)throw new \RuntimeException('Audit unavailable');AuditLog::assertInstallation($pdo,$id);});
            $this->probe('queue',function()use($pdo,$id){if(!$pdo||!$id)throw new \RuntimeException('Queue unavailable');$counts=array_fill_keys(['queued','running','succeeded','dead','cancelled'],0);$oldest=0;$now=time();
                foreach(self::q($pdo,"SELECT value->>'state' AS state,count(*) AS n,min((value->>'due')::bigint) AS due FROM cajeer_book.automation_jobs WHERE installation=? GROUP BY 1",[$id]) as $r){if(isset($counts[$r['state']]))$counts[$r['state']]=(int)$r['n'];if($r['state']==='queued')$oldest=max(0,$now-(int)$r['due']);}
                foreach($counts as $state=>$n)$this->metrics['queue_jobs{state="'.$state.'"}']=$n;$this->metrics['queue_oldest_due_age_seconds']=$oldest;
            });
            if((getenv('BOOK_PRIMARY_HOST')?:'')!=='')$this->probe('domains',function()use($pdo,$id){if(!$pdo||!$id)throw new \RuntimeException('Domains unavailable');$expiring=0;$active=0;$unverified=0;$now=time();
                $q=self::q($pdo,"SELECT w.value::text AS value FROM cajeer_book.identity_workspaces w JOIN cajeer_book.identity_organizations o ON o.installation=w.installation AND o.record_key=w.value->>'organization' WHERE w.installation=? AND COALESCE((o.value->>'archived')::boolean,false)=false",[$id]);
                foreach($q as $row){$w=json_decode($row['value'],true,32,JSON_THROW_ON_ERROR);if(($w['archived']??true)||($w['legacy']??true)||Visibility::workspace($w)!=='public')continue;foreach($w['domains']??[] as $r){if(CustomDomains::active($r,$now)){$active++;if($r['verified']+CustomDomains::LEASE-$now<172800)$expiring++;}elseif(!$r['disabled']&&$r['checked']>0)$unverified++;}}
                $this->metrics['domains_active']=$active;$this->metrics['domains_expiring']=$expiring;$this->metrics['domains_unverified']=$unverified;
            });
            $this->probe('postgres_stats',function()use($pdo,$id){if(!$pdo||!$id)throw new \RuntimeException('Postgres unavailable');$r=$pdo->query("SELECT count(*) AS connections,count(*) FILTER (WHERE wait_event_type='Lock') AS waiting FROM pg_stat_activity WHERE datname=current_database()")->fetch(\PDO::FETCH_ASSOC);$this->metrics['postgres_connections']=(int)$r['connections'];$this->metrics['postgres_lock_waiters']=(int)$r['waiting'];});
        }else{$this->probe('identity',function()use($root){if(!$root)throw new \RuntimeException('Storage unavailable');(new IdentityStore($root))->checkReady();});}
        if($redis)$this->probe('redis',function()use($root){if(!$root)throw new \RuntimeException('Storage unavailable');$r=RedisRuntime::forRoot($root);if(!$r)throw new \RuntimeException('Redis unavailable');$r->check();$s=$r->stats();
            foreach(['cache_hits','cache_misses','rate_denied','notifications','jobs_processed'] as $k)$this->metrics['redis_'.$k.'_total']=(int)($s['metrics'][$k]??0);
            $this->metrics['redis_stream_length']=$s['stream_length'];$this->metrics['redis_workers_recent']=$s['workers_recent'];
        });
        if($redis)$this->probe('redis_resources',function()use($root){if(!$root)throw new \RuntimeException('Storage unavailable');$r=RedisRuntime::forRoot($root);if(!$r)throw new \RuntimeException('Redis unavailable');$s=$r->resources();foreach(['used_memory'=>'memory_used_bytes','maxmemory'=>'memory_limit_bytes','evicted_keys'=>'evicted_keys_total','rejected_connections'=>'rejected_connections_total','connected_clients'=>'connected_clients'] as $k=>$name)$this->metrics['redis_'.$name]=$s[$k];});
        if(getenv('BOOK_MONITOR_TLS')==='1')$this->probe('tls',function(){
            $host=CustomDomains::primary();if(!$host)throw new \RuntimeException('Primary host missing');
            $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true,'capture_peer_cert'=>true]]);
            $socket=@stream_socket_client('tls://'.$host.':443',$errno,$errstr,3,STREAM_CLIENT_CONNECT,$ctx);if(!$socket)throw new \RuntimeException('TLS handshake failed');
            try{$cert=stream_context_get_params($socket)['options']['ssl']['peer_certificate']??null;$parsed=$cert?openssl_x509_parse($cert):false;$expiry=$parsed['validTo_time_t']??0;if(!is_int($expiry)||$expiry<=time())throw new \RuntimeException('Certificate invalid');$this->metrics['tls_certificate_expiry_timestamp_seconds']=$expiry;}finally{fclose($socket);}
        });
        $this->metrics['domain_recheck_last_success_timestamp_seconds']??=0;$this->metrics['domain_recheck_failures_total']??=0;
        if(getenv('BOOK_MONITOR_DOMAINS')==='1')$this->probe('domain_check',function(){ $at=$this->metrics['domain_recheck_last_success_timestamp_seconds'];if($at<=0||$at>time()||time()-$at>7200)throw new \RuntimeException('DNS recheck heartbeat stale');});
        $this->metrics['worker_last_success_timestamp_seconds']??=0;$this->metrics['worker_jobs_processed_total']??=0;$this->metrics['worker_scope_failures_total']??=0;
        $worker=getenv('BOOK_MONITOR_WORKERS')==='1';$backup=getenv('BOOK_MONITOR_BACKUP')==='1';$this->metrics['worker_expected']=$worker?1:0;$this->metrics['backup_expected']=$backup?1:0;
        if($worker)$this->probe('worker',function(){ $at=$this->metrics['worker_last_success_timestamp_seconds']??0;if($at<=0||time()-$at>300||$at>time())throw new \RuntimeException('Worker heartbeat stale');});
        if($backup)$this->probe('backup',function(){
            $s=Observability::backupSnapshot();$max=getenv('BOOK_BACKUP_MAX_AGE')?:'90000';if(!ctype_digit($max)||(int)$max<300||(int)$max>1209600)throw new \RuntimeException('Invalid RPO');
            $this->metrics['backup_max_age_seconds']=(int)$max;foreach(['capture','verified','updated','failures','pending'] as $k){$v=$s[$k]??0;if(!is_int($v)||$v<0)throw new \RuntimeException('Invalid backup telemetry');$this->metrics['backup_'.$k.(in_array($k,['capture','verified','updated'],true)?'_timestamp_seconds':'')]=$v;}
            if(($s['capture']??0)<=0||$s['capture']>time()||time()-$s['capture']>(int)$max)throw new \RuntimeException('Backup stale');
        });
        if($backup)$this->probe('backup_scheduler',function(){if(($this->metrics['backup_updated_timestamp_seconds']??0)<time()-3600)throw new \RuntimeException('Backup scheduler heartbeat stale');});
        $this->metrics['build_info{version="'.Observability::version().'"}']=1;
        $this->metrics['diagnostics_healthy']=in_array('unavailable',$this->checks,true)?0:1;
        ksort($this->metrics);return ['status'=>$this->metrics['diagnostics_healthy']?'ok':'unavailable','checks'=>$this->checks,'metrics'=>$this->metrics];
    }
    public static function prometheus(array $data):string{
        $groups=[];foreach($data['metrics'] as $k=>$v){$name=explode('{',$k,2)[0];$base=preg_replace('/_(?:bucket|count|sum)$/','',$name);$hist=in_array($base,['http_request_duration_seconds','operation_duration_seconds'],true);$family=$hist?$base:$name;$groups[$family]['type']=$hist?'histogram':(str_ends_with($name,'_total')?'counter':'gauge');$groups[$family]['samples'][$k]=$v;}
        $out='';foreach($groups as $family=>$group){$out.='# TYPE book_'.$family.' '.$group['type']."\n";$samples=$group['samples'];
            if($group['type']==='histogram')uksort($samples,static function($a,$b){
                $order=static function($k){$le=0;$rank=2;if(str_contains($k,'_bucket{')){$rank=0;preg_match('/,le="([^"]+)"\}$/D',$k,$m);$le=($m[1]??'')==='+Inf'?INF:(float)($m[1]??0);}elseif(str_contains($k,'_sum{'))$rank=1;
                    $labels=preg_replace('/,le="[^"]+"\}$/D','}',substr($k,strpos($k,'{')));return [$labels,$rank,$le];};return $order($a)<=>$order($b);});
            foreach($samples as $k=>$v)$out.='book_'.$k.' '.json_encode($v,JSON_THROW_ON_ERROR)."\n";}return $out;
    }
    public static function serve():void{
        header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($path,['/metrics','/diagnostics'],true)){http_response_code(404);return;}
        if(!Observability::enabled()||!Observability::authorized()){http_response_code(404);return;}
        if($method!=='GET'){http_response_code(405);header('Allow: GET');return;}
        $data=(new self())->collect();
        if($path==='/metrics'){header('Content-Type: text/plain; version=0.0.4; charset=utf-8');echo self::prometheus($data);return;}
        header('Content-Type: application/json; charset=utf-8');http_response_code($data['status']==='ok'?200:503);echo json_encode(['status'=>$data['status'],'checks'=>$data['checks']],JSON_THROW_ON_ERROR);
    }
}
