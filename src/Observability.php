<?php
declare(strict_types=1);
namespace CajeerBook;

/** Bounded, best-effort telemetry. No request data, SQL text or credentials are stored.
 * Sixteen fixed shards aggregate PHP processes on the shared installation volume.
 * Each update is atomic; a failed telemetry write never changes a business result. */
final class Observability
{
    private const TIMESTAMPS=['worker_last_success_timestamp_seconds','domain_recheck_last_success_timestamp_seconds'];
    private const BUCKETS=[0.005,0.01,0.025,0.05,0.1,0.2,0.3,0.5,1,2,5,10];
    private static array $pending=[];
    private static bool $registered=false;
    private static ?array $request=null;
    public static function version():string{static $v=null;if($v===null){$raw=trim((string)@file_get_contents(dirname(__DIR__).'/VERSION'));$v=preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D',$raw)?$raw:'unknown';}return $v;}
    public static function enabled():bool{return getenv('BOOK_OBSERVABILITY')==='1';}
    private static function prefix(string $path,string $base):bool{return $path===$base||str_starts_with($path,$base.'/');}
    public static function route(string $path):string{
        $path=preg_replace('~^/w/[a-f0-9]{32}/[a-f0-9]{32}(?=/|$)~D','',$path);
        $path=preg_replace('~^/l/(?:en|ru)(?=/|$)~','',$path);
        return match(true){$path===''||$path==='/'=>'home',str_starts_with($path,'/docs/')=>'reader',str_starts_with($path,'/media/')=>'media',in_array($path,['/search','/api/v1/search'],true)=>'search',self::prefix($path,'/api/v1/ci')=>'ci',str_starts_with($path,'/api/v1/')=>'api',self::prefix($path,'/studio')=>'studio',str_starts_with($path,'/webhooks/')=>'webhook',str_starts_with($path,'/auth/')||in_array($path,['/login','/logout'],true)||self::prefix($path,'/account')=>'auth',$path==='/health/ready'=>'ready',str_starts_with($path,'/health/')=>'health',self::prefix($path,'/audit')=>'audit',self::prefix($path,'/organizations')||self::prefix($path,'/workspaces')||self::prefix($path,'/domains')||self::prefix($path,'/team')=>'admin',default=>'other'};
    }
    private static function directory():string{
        require_once __DIR__.'/DataDirectory.php';
        $root=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:dirname(__DIR__).'/var',dirname(__DIR__).'/public');
        $dir=$root.'/observability';
        if(is_link($dir)||(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)))throw new \RuntimeException('Telemetry directory unavailable');
        if((fileperms($dir)&0077)!==0)throw new \RuntimeException('Telemetry directory must be private');
        return $dir;
    }
    private static function path(string $name):string{
        $path=self::directory().'/'.$name;if(is_link($path))throw new \RuntimeException('Invalid telemetry path');return $path;
    }
    private static function read(string $path):array{
        clearstatcache(true,$path);if(!is_file($path))return [];
        if(is_link($path)||filesize($path)>512*1024)throw new \RuntimeException('Invalid telemetry file');
        $v=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new \RuntimeException('Invalid telemetry data');return $v;
    }
    private static function write(string $path,array $value):void{
        $json=json_encode($value,JSON_THROW_ON_ERROR);if(strlen($json)>512*1024)throw new \RuntimeException('Telemetry quota exceeded');$tmp=dirname($path).'/.tmp-'.bin2hex(random_bytes(12));
        $h=fopen($tmp,'xb');if(!$h)throw new \RuntimeException('Telemetry write failed');
        try{if(!chmod($tmp,0600)||fwrite($h,$json)!==strlen($json)||!fflush($h))throw new \RuntimeException('Telemetry write failed');fclose($h);$h=null;if(!rename($tmp,$path))throw new \RuntimeException('Telemetry publish failed');}
        finally{if(is_resource($h))fclose($h);if(is_file($tmp))unlink($tmp);}
    }
    private static function add(string $key,float|int $value):void{
        if(!self::enabled()||getenv('BOOK_OBSERVABILITY_EXPORTER')==='1')return;
        if(!self::$registered){register_shutdown_function([self::class,'flush']);self::$registered=true;}
        self::$pending[$key]=(in_array($key,self::TIMESTAMPS,true))?max(self::$pending[$key]??0,$value):((self::$pending[$key]??0)+$value);
    }
    private static function histogram(string $name,string $labels,float $seconds):void{
        $seconds=max(0,$seconds);self::add($name.'_count'.$labels,1);self::add($name.'_sum'.$labels,$seconds);
        foreach(self::BUCKETS as $b)self::add($name.'_bucket'.self::extra($labels,'le',(string)$b),$seconds<=$b?1:0);
        self::add($name.'_bucket'.self::extra($labels,'le','+Inf'),1);
    }
    private static function extra(string $labels,string $key,string $v):string{return $labels===''?'{'.$key.'="'.$v.'"}':substr($labels,0,-1).','.$key.'="'.$v.'"}';}
    public static function operation(string $operation,float $started,bool $ok):void{
        if(!self::enabled()||getenv('BOOK_OBSERVABILITY_EXPORTER')==='1')return;
        if(!in_array($operation,['sql_connect','sql_content_query','sql_search_query','sql_identity_query','sql_identity_lock','sql_content_lock','sql_identity_transaction','sql_content_transaction','redis_command','worker_tick','backup_run','dns_recheck','media_verify'],true))return;
        self::histogram('operation_duration_seconds','{operation="'.$operation.'",outcome="'.($ok?'ok':'error').'"}',(hrtime(true)-$started)/1e9);
    }
    public static function domainRecheck(bool $ok,int $failed):void{if($ok)self::add('domain_recheck_last_success_timestamp_seconds',time());self::add('domain_recheck_failures_total',max(0,$failed));}
    public static function mediaIntegrityFailure():void{self::add('media_integrity_failures_total',1);}
    public static function workerScopeFailure():void{self::add('worker_scope_failures_total',1);}
    public static function worker(int $processed):void{self::add('worker_last_success_timestamp_seconds',time());self::add('worker_jobs_processed_total',max(0,$processed));}
    public static function startRequest(string $id):void{
        if(self::$request!==null)return;
        $method=$_SERVER['REQUEST_METHOD']??'GET';$method=in_array($method,['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'],true)?$method:'OTHER';
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);self::$request=['id'=>$id,'route'=>self::route(is_string($path)?$path:'/invalid'),'method'=>$method,'started'=>hrtime(true)];
    }
    public static function finishRequest():void{
        $r=self::$request;if($r===null)return;self::$request=null;
        $status=http_response_code()?:200;$class=$status===429?'429':(intdiv($status,100).'xx');$seconds=(hrtime(true)-$r['started'])/1e9;
        $labels='{route="'.$r['route'].'",method="'.$r['method'].'",status="'.$class.'"}';
        self::add('http_requests_total'.$labels,1);self::histogram('http_request_duration_seconds','{route="'.$r['route'].'"}',$seconds);
        self::log('http.request',['request_id'=>$r['id'],'route'=>$r['route'],'method'=>$r['method'],'status'=>$status,'duration_ms'=>round($seconds*1000,3)]);self::flush();
    }
    public static function log(string $event,array $fields=[]):void{
        if(!self::enabled()){if(str_contains($event,'failure'))error_log($event.' '.($fields['error_type']??''));return;}
        // Only callers' fixed event codes and allowlisted scalar fields are emitted.
        $data=['timestamp'=>gmdate('c'),'event'=>$event,'release'=>self::version(),'node'=>substr(hash('sha256',gethostname()?:'unknown'),0,16)];
        if(self::$request!==null)$data['request_id']=self::$request['id'];
        foreach(['request_id','route','method','status','duration_ms','component','outcome','processed','error_type','job_id','kind','attempt'] as $k)if(isset($fields[$k])&&is_scalar($fields[$k]))$data[$k]=$fields[$k];
        error_log(json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE));
    }
    public static function flush():void{
        if(!self::$pending)return;$pending=self::$pending;self::$pending=[];$lock=null;
        try{
            $shard=hexdec(substr(hash('sha256',(gethostname()?:'').':'.getmypid()),0,1));$path=self::path('metrics-'.$shard.'.json');
            $lock=fopen(self::path('metrics-'.$shard.'.lock'),'c');if(!$lock)throw new \RuntimeException('Telemetry lock unavailable');$deadline=hrtime(true)+20000000;while(!flock($lock,LOCK_EX|LOCK_NB)){if(hrtime(true)>=$deadline)throw new \RuntimeException('Telemetry shard busy');usleep(1000);}
            chmod(self::path('metrics-'.$shard.'.lock'),0600);$data=self::read($path);
            foreach($pending as $key=>$n)$data[$key]=in_array($key,self::TIMESTAMPS,true)?max($data[$key]??0,$n):(($data[$key]??0)+$n);
            self::write($path,$data);
        }catch(\Throwable){self::log('telemetry.write_failed');}finally{if(is_resource($lock))fclose($lock);}
    }
    public static function httpDefaults():array{
        $out=[];foreach(['home','reader','media','search','ci','api','studio','webhook','auth','ready','health','audit','admin','other'] as $route)foreach(['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS','OTHER'] as $method)foreach(['1xx','2xx','3xx','4xx','429','5xx'] as $status)$out['http_requests_total{route="'.$route.'",method="'.$method.'",status="'.$status.'"}']=0;return $out;
    }
    public static function snapshot():array{
        $all=[];for($i=0;$i<16;$i++)foreach(self::read(self::path('metrics-'.$i.'.json')) as $k=>$v){
            if(!is_string($k)||!preg_match('/\A[a-z_]+(?:\{[a-z_]+="[a-zA-Z0-9_+.]+"(?:,[a-z_]+="[a-zA-Z0-9_+.]+")*\})?\z/D',$k)||(!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0)throw new \RuntimeException('Invalid telemetry sample');
            $all[$k]=in_array($k,self::TIMESTAMPS,true)?max($all[$k]??0,$v):(($all[$k]??0)+$v);
        }return $all;
    }
    public static function backup(array $state):void{
        if(!self::enabled())return;
        try{self::write(self::path('backup.json'),['capture'=>(int)$state['last_capture'],'verified'=>(int)$state['last_success'],'failures'=>(int)$state['failures'],'pending'=>$state['pending']!==null?1:0,'updated'=>time()]);}catch(\Throwable){self::log('telemetry.backup_failed');}
    }
    public static function backupSnapshot():array{return self::read(self::path('backup.json'));}
    public static function authorized():bool{
        $file=getenv('BOOK_METRICS_TOKEN_FILE')?:'';$token=$file!==''&&is_file($file)&&is_readable($file)&&filesize($file)<=128?trim((string)file_get_contents($file)):'';
        $auth=$_SERVER['HTTP_AUTHORIZATION']??'';
        return preg_match('/\A[a-f0-9]{64}\z/D',$token)===1&&is_string($auth)&&hash_equals('Bearer '.$token,$auth);
    }
}
