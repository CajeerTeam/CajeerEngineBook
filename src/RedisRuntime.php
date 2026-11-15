<?php
declare(strict_types=1);
namespace CajeerBook;
require_once __DIR__.'/Observability.php';

/** Redis is an acceleration/notification layer, never an identity or job authority. */
final class RedisRuntime
{
    private ?\Redis $client=null;
    private float $retryAt=0;
    private bool $groupReady=false;
    private string $consumer;
    private int $lastPrune=0;
    private const GROUP='workers-v1';
    private string $prefix;
    private static array $instances=[];
    private const CACHE_MAX=524288;
    private const RATE_LUA=<<<'LUA'
local n = redis.call('INCR', KEYS[1])
if n == 1 or redis.call('TTL', KEYS[1]) < 0 then
  redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return {n <= tonumber(ARGV[1]) and 1 or 0, redis.call('TTL', KEYS[1])}
LUA;
    private function __construct(private readonly string $namespace,private readonly string $binding)
    {
        $this->prefix='book:044:'.hash('sha256',$namespace.':'.$binding).':';
        $this->consumer=bin2hex(random_bytes(12));
    }
    public static function enabled():bool{return (getenv('BOOK_REDIS_HOST')?:'')!=='';}
    public static function forRoot(string $root):?self
    {
        if(!self::enabled())return null;
        $b=PostgresContentStore::identityBinding($root);
        if($b===null)throw new \RuntimeException('Redis runtime requires a migrated PostgreSQL installation.');
        return self::forBinding($b[0],$b[1]);
    }
    public static function forBinding(string $namespace,string $binding):?self
    {
        if(!self::enabled())return null;
        $key=$namespace.':'.$binding;
        return self::$instances[$key]??=new self($namespace,$binding);
    }
    private function connection():\Redis
    {
        if($this->client!==null)return $this->client;
        if(microtime(true)<$this->retryAt)throw new \RuntimeException('Redis temporarily unavailable.');
        if(!class_exists(\Redis::class))throw new \RuntimeException('Redis runtime requires ext-redis.');
        $host=getenv('BOOK_REDIS_HOST')?:'';$port=getenv('BOOK_REDIS_PORT')?:'6379';
        if(!preg_match('/\A[a-zA-Z0-9.-]+\z/D',$host)||!ctype_digit($port)||(int)$port<1||(int)$port>65535)throw new \RuntimeException('Invalid Redis host or port.');
        $tls=getenv('BOOK_REDIS_TLS')==='1';$context=[];
        if($tls){$context=['stream'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]];if($ca=getenv('BOOK_REDIS_CA'))$context['stream']['cafile']=$ca;}
        $r=new \Redis();
        try{
            if(!@$r->connect(($tls?'tls://':'').$host,(int)$port,0.5,null,0,0.75,$context))throw new \RuntimeException('Redis connection failed.');
            $password=getenv('BOOK_REDIS_PASSWORD');$user=getenv('BOOK_REDIS_USER')?:'';
            if($user!==''&&($password===false||$password===''))throw new \RuntimeException('Redis ACL user requires password.');
            if($password!==false&&$password!==''&&!$r->auth($user!==''?[$user,$password]:$password))throw new \RuntimeException('Redis authentication failed.');
            $this->client=$r;return $r;
        }catch(\Throwable $e){try{$r->close();}catch(\Throwable){}throw new \RuntimeException('Redis connection unavailable.',0,$e);}
    }
    private function failed():void
    {
        if($this->client!==null){try{$this->client->close();}catch(\Throwable){}$this->client=null;}
        $this->retryAt=microtime(true)+2;$this->groupReady=false;
    }
    private function invoke(callable $fn,?string $allowedError=null):mixed
    {
        $start=hrtime(true);$ok=false;
        try{if(microtime(true)<$this->retryAt)throw new \RuntimeException('Redis temporarily unavailable.');$r=$this->connection();$r->clearLastError();$v=$fn($r);$error=$r->getLastError();
            if($error&&($allowedError===null||!str_starts_with($error,$allowedError)))throw new \RuntimeException('Redis command failed.');$ok=true;return $v;
        }catch(\Throwable $e){if($allowedError!==null&&str_starts_with($e->getMessage(),$allowedError))return false;$this->failed();throw new \RuntimeException('Redis runtime unavailable.',0,$e);}finally{Observability::operation('redis_command',$start,$ok);}
    }
    private function command(string ...$args):mixed{return $this->invoke(fn($r)=>$r->rawCommand(...$args));}
    private function createGroup(string $stream):void
    {
        $this->invoke(fn($r)=>$r->rawCommand('XGROUP','CREATE',$stream,self::GROUP,'0-0','MKSTREAM'),'BUSYGROUP');
    }
    public function check():void
    {
        $v=$this->command('PING');if($v!==true&&$v!=='PONG'&&$v!=='+PONG')throw new \RuntimeException('Redis ping failed.');
        // Verify the actual permissions needed by this runtime, without touching user data.
        $key=$this->prefix.'probe:'.bin2hex(random_bytes(8));
        try{$this->command('SET',$key,'ready','EX','10');if($this->command('GET',$key)!=='ready')throw new \RuntimeException('Redis cache probe failed.');$this->limit('probe',$key,2,10);$this->command('EVAL',"local id=redis.call('XADD',KEYS[1],'*','scope','probe'); redis.call('EXPIRE',KEYS[1],10); return id",'1',$key.':stream');$this->createGroup($key.':stream');if(!$this->command('XREADGROUP','GROUP',self::GROUP,$this->consumer,'COUNT','1','NOACK','STREAMS',$key.':stream','>'))throw new \RuntimeException('Redis stream probe failed.');}
        finally{try{$this->command('DEL',$key,$key.':stream',$this->rateKey('probe',$key));}catch(\Throwable){}}
    }
    private function cacheKey(string $kind,array $parts):string{return $this->prefix.'cache:'.$kind.':'.hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    /** Cache only already-authorized public results. HMAC binds cached values to their exact key. */
    public function remember(string $kind,array $parts,callable $compute):array
    {
        $key=$this->cacheKey($kind,$parts);
        try{
            $v=$this->command('GET',$key);
            if(is_string($v)&&strlen($v)<=self::CACHE_MAX+65&&strlen($v)>65&&$v[64]===':'){
                $json=substr($v,65);
                if(hash_equals(hash_hmac('sha256',$key."\n".$json,$this->binding),substr($v,0,64))){$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(is_array($data)){$this->metric('cache_hits');return $data;}}
            }
        }catch(\Throwable){} // source-of-truth read still happens on cache failure
        $this->metric('cache_misses');$data=$compute();
        try{$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);if(strlen($json)<=self::CACHE_MAX)$this->command('SET',$key,hash_hmac('sha256',$key."\n".$json,$this->binding).':'.$json,'EX',(string)random_int(240,360));}catch(\Throwable){}
        return $data;
    }
    private function rateKey(string $bucket,string $subject):string{return $this->prefix.'rate:'.$bucket.':'.hash('sha256',$subject);}
    /** Atomic fixed window, shared by all web nodes. Returns allowed and remaining window seconds. */
    public function limit(string $bucket,string $subject,int $max,int $seconds):array
    {
        if(!preg_match('/\A[a-z-]{1,32}\z/D',$bucket)||$max<1||$seconds<1)throw new \InvalidArgumentException('Invalid rate policy.');
        $v=$this->command('EVAL',self::RATE_LUA,'1',$this->rateKey($bucket,$subject),(string)$max,(string)$seconds);
        if(!is_array($v)||count($v)!==2||!in_array($v[0],[0,1,'0','1'],true))throw new \RuntimeException('Invalid Redis rate response.');
        if((int)$v[0]===0)$this->metric('rate_denied');
        return ['allowed'=>(int)$v[0]===1,'retry'=>max(1,(int)$v[1])];
    }
    public static function clientIp():string
    {
        // Never trust arbitrary X-Forwarded-For. Configure the trusted proxy/Apache remoteip upstream.
        $ip=$_SERVER['REMOTE_ADDR']??'';$packed=is_string($ip)?@inet_pton($ip):false;
        return $packed===false?'unknown':bin2hex($packed);
    }
    /** Post-commit wakeup; the durable SQL queue is reconciled even if this fails. */
    public function notify(string $scope):void
    {
        try{$this->command('XADD',$this->prefix.'jobs','MAXLEN','~','2048','*','scope',$scope);$this->metric('notifications');}catch(\Throwable){}
    }
    private function metric(string $name,int $delta=1):void
    {
        try{$this->command('HINCRBY',$this->prefix.'metrics',$name,(string)$delta);}catch(\Throwable){}
    }
    public function heartbeat(int $processed):void
    {
        try{$now=time();$this->command('HSET',$this->prefix.'metrics','last_worker_tick',(string)$now);$this->command('ZADD',$this->prefix.'workers',(string)$now,$this->consumer);$this->command('ZREMRANGEBYSCORE',$this->prefix.'workers','-inf',(string)($now-1800));if($processed)$this->metric('jobs_processed',$processed);}catch(\Throwable){}
    }
    public function stats():array
    {
        $raw=$this->invoke(fn($r)=>$r->hGetAll($this->prefix.'metrics'));$stats=[];
        if(is_array($raw))foreach($raw as $k=>$v)$stats[$k]=(int)$v;
        return ['metrics'=>$stats,'stream_length'=>(int)$this->command('XLEN',$this->prefix.'jobs'),'workers_recent'=>(int)$this->command('ZCOUNT',$this->prefix.'workers',(string)(time()-60),'+inf')];
    }
    /** Optional INFO privileges, isolated from core readiness. Only numeric allowlisted fields. */
    public function resources():array
    {
        $result=[];foreach(['memory'=>['used_memory','maxmemory'],'stats'=>['evicted_keys','rejected_connections'],'clients'=>['connected_clients']] as $section=>$keys){
            $raw=$this->command('INFO',$section);if(!is_string($raw))throw new \RuntimeException('Redis INFO unavailable.');
            foreach($keys as $k){if(!preg_match('/^'.preg_quote($k,'/').':([0-9]+)\r?$/m',$raw,$m))throw new \RuntimeException('Redis INFO field missing.');$result[$k]=(int)$m[1];}
        }return $result;
    }
    private function pruneConsumers(string $stream):void
    {
        if(time()-$this->lastPrune<600)return;$this->lastPrune=time();
        $rows=$this->command('XINFO','CONSUMERS',$stream,self::GROUP);
        if(!is_array($rows))return;
        foreach($rows as $row){$info=[];if(!is_array($row))continue;for($i=0;$i+1<count($row);$i+=2)$info[(string)$row[$i]]=$row[$i+1];
            if(is_string($info['name']??null)&&$info['name']!==$this->consumer&&(int)($info['pending']??-1)===0&&(int)($info['idle']??0)>3600000)$this->command('XGROUP','DELCONSUMER',$stream,self::GROUP,$info['name']);}
    }
    /** A consumer group distributes hints. NOACK is intentional: a Redis message is not a job
     * claim, and a lost hint is reconciled from SQL on the next tick. There is no second queue
     * authority or pending-message leak to recover. */
    public function wait():array
    {
        try{
            $stream=$this->prefix.'jobs';if(!$this->groupReady){$this->createGroup($stream);$this->groupReady=true;}
            $this->pruneConsumers($stream);$this->connection()->setOption(\Redis::OPT_READ_TIMEOUT,3.0);
            $v=$this->command('XREADGROUP','GROUP',self::GROUP,$this->consumer,'COUNT','128','BLOCK','2000','NOACK','STREAMS',$stream,'>');$scopes=[];
            if(is_array($v))foreach($v as $entry)foreach($entry[1]??[] as $message){
                $fields=$message[1]??[];
                if(($fields[0]??null)==='scope'&&is_string($fields[1]??null)&&preg_match('~\A(?:legacy|[a-f0-9]{32}/[a-f0-9]{32})\z~D',$fields[1]))$scopes[$fields[1]]=true;
            }
            return array_keys($scopes);
        }catch(\Throwable){$this->failed();sleep(2);return [];}
        finally{if($this->client!==null){try{$this->client->setOption(\Redis::OPT_READ_TIMEOUT,0.75);}catch(\Throwable){$this->failed();}}}
    }
}
