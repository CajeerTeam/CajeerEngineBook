<?php
declare(strict_types=1);
namespace CajeerBook;

/** Durable per-workspace inbox/outbox. All mutations occur inside BookStore::change. */
final class AutomationQueue
{
    public const MAX_JOBS=512, MAX_PENDING=448, MAX_RECEIPTS=2048, MAX_ATTEMPTS=5, LEASE=900, RECEIPT_TTL=604800;
    public const EVENTS=['document.changed','document.published','review.requested','review.approved','git.sync.completed','git.sync.failed','openapi.updated','build.ready','build.approved','build.published'];
    private const STATES=['queued','running','succeeded','dead','cancelled'];
    private static function id(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{32}\z/D',$v)===1;}
    private static function hash(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function key():string{
        $v=getenv('BOOK_AUTOMATION_KEY')?:'';if(!preg_match('/\A[a-fA-F0-9]{64}\z/D',$v))throw new \DomainException('Configure BOOK_AUTOMATION_KEY (32-byte hex) first.');return hex2bin($v);
    }
    private static function seal(string $secret,string $id,string $kind):string{
        if(strlen($secret)<32||strlen($secret)>128||!preg_match('/\A[!-~]+\z/D',$secret))throw new \InvalidArgumentException('Webhook secret must be 32–128 printable non-space ASCII characters.');
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'book-automation:'.$id.':'.$kind,16);if($cipher===false)throw new \RuntimeException('Cannot encrypt webhook secret.');return base64_encode($iv.$tag.$cipher);
    }
    public static function secret(array $config,string $kind):string{
        $raw=base64_decode($config[$kind.'_secret'],true);if($raw===false||strlen($raw)<60)throw new \RuntimeException('Invalid webhook credential.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'book-automation:'.$config['id'].':'.$kind);
        if($plain===false)throw new \DomainException('Webhook credential unavailable. Reconfigure automation.');return $plain;
    }
    public static function destination(string $url,bool $check=true):string{
        $origin=ApiPlayground::origin($url);$path=parse_url($url,PHP_URL_PATH)??'/';
        if(strlen($url)>2048||!preg_match("~\\A/[A-Za-z0-9._/!$&'()*+,;=:@%\\~-]*\\z~D",$path))throw new \InvalidArgumentException('Invalid webhook destination path.');
        // Use a simple path grammar: no ambiguous percent escapes or traversal.
        if(str_contains($path,'%')||str_contains($path,'//')||array_intersect(explode('/',$path),['.','..']))throw new \InvalidArgumentException('Invalid webhook destination path.');
        if($check&&!in_array($origin,ApiPlayground::origins('BOOK_WEBHOOK_ORIGINS'),true))throw new \InvalidArgumentException('Webhook origin is not allowed by the installation operator.');
        return $origin.$path;
    }
    public static function validate(array $db):void{
        $a=$db['automation']??null;if($a===null){if(($db['schema']??null)===5)throw new \RuntimeException('Automation store missing.');return;}
        if(!in_array($db['schema']??null,[5,6],true)||!is_array($a)||array_diff(array_keys($a),['config','jobs','receipts'])||!array_key_exists('config',$a)||!is_array($a['jobs']??null)||count($a['jobs'])>self::MAX_JOBS||!is_array($a['receipts']??null)||count($a['receipts'])>self::MAX_RECEIPTS)throw new \RuntimeException('Invalid automation store.');
        $c=$a['config'];if($c!==null){
            if(!is_array($c)||array_diff(array_keys($c),['id','actor','git','url','events','in_secret','out_secret'])||!self::id($c['id']??null)||!self::id($c['actor']??null)||!is_string($c['git']??null)||($c['git']!==''&&!self::id($c['git']))||!is_string($c['url']??null)||!is_array($c['events']??null)||!array_is_list($c['events'])||count($c['events'])>count(self::EVENTS)||count(array_filter($c['events'],'is_string'))!==count($c['events'])||count(array_unique($c['events']))!==count($c['events'])||array_diff($c['events'],self::EVENTS))throw new \RuntimeException('Invalid automation configuration.');
            foreach(['in','out'] as $kind){$s=$c[$kind.'_secret']??null;$bytes=is_string($s)?base64_decode($s,true):false;if(!is_string($s)||($s!==''&&($bytes===false||strlen($bytes)<60||strlen($bytes)>156)))throw new \RuntimeException('Invalid automation credential.');}
            if(($c['git']==='')!==($c['in_secret']==='')||($c['url']==='')!==($c['out_secret']==='')||($c['url']!==''&&(!$c['events']||self::destination($c['url'],false)!==$c['url'])))throw new \RuntimeException('Invalid automation destination.');
        }
        foreach($a['jobs'] as $id=>$j){
            if(!self::id($id)||!is_array($j)||array_diff(array_keys($j),['id','config','actor','kind','payload','state','attempts','created','updated','due','lease','lease_until','error','result_commit'])||($j['id']??null)!==$id||!self::id($j['config']??null)||!self::id($j['actor']??null)||!in_array($j['kind']??null,['sync','delivery'],true)||!in_array($j['state']??null,self::STATES,true)||!is_int($j['attempts']??null)||$j['attempts']<0||$j['attempts']>self::MAX_ATTEMPTS||!is_int($j['created']??null)||!is_int($j['due']??null)||!is_int($j['lease_until']??null)||!is_int($j['updated']??null)||!is_string($j['lease']??null)||($j['lease']!==''&&!self::id($j['lease']))||!is_string($j['error']??null)||strlen($j['error'])>200||preg_match('/[\x00-\x1f\x7f]/',$j['error'])||!is_string($j['result_commit']??null)||($j['result_commit']!==''&&!preg_match('/\A[a-f0-9]{40}\z/D',$j['result_commit']))||!is_array($j['payload']??null))throw new \RuntimeException('Invalid automation job.');
            if(($j['state']==='running')!==($j['lease']!==''&&$j['lease_until']>0)||($j['state']==='running'&&$j['attempts']<1))throw new \RuntimeException('Invalid automation lease.');
            if(in_array($j['state'],['queued','running'],true)&&(!$c||$j['config']!==$c['id']||$j['actor']!==$c['actor']))throw new \RuntimeException('Invalid active automation job binding.');
            $p=$j['payload'];if($j['kind']==='sync'){if(array_keys($p)!==['git','commit']||!self::id($p['git'])||!is_string($p['commit'])||($p['commit']!==''&&!preg_match('/\A[a-f0-9]{40}\z/D',$p['commit'])))throw new \RuntimeException('Invalid sync payload.');}
            elseif(array_keys($p)!==['id','event','at']||$p['id']!==$id||!in_array($p['event'],self::EVENTS,true)||$p['at']!==$j['created'])throw new \RuntimeException('Invalid delivery payload.');
        }
        $pending=array_filter($a['jobs'],fn($j)=>in_array($j['state'],['queued','running'],true));if(count($pending)>self::MAX_PENDING||count(array_filter($pending,fn($j)=>$j['kind']==='sync'))>64)throw new \RuntimeException('Automation pending quota exceeded.');
        foreach($a['receipts'] as $hash=>$r)if(!self::hash($hash)||!is_array($r)||array_diff(array_keys($r),['body','job','expires'])||!self::hash($r['body']??null)||!self::id($r['job']??null)||!is_int($r['expires']??null))throw new \RuntimeException('Invalid webhook receipt.');
    }
    private static function init(array &$db):void{$db['schema']=max(5,$db['schema']??1);$db['automation']??=['config'=>null,'jobs'=>[],'receipts'=>[]];}
    public static function configure(array &$db,string $actor,string $expected,string $in,string $url,string $out,array $events):void{
        self::init($db);$a=&$db['automation'];if(($a['config']['id']??'')!==$expected)throw new \DomainException('Automation configuration changed. Reload.');
        $id=bin2hex(random_bytes(16));$git=$in!==''?($db['github']['id']??''):'';if($in!==''&&$git==='')throw new \DomainException('Connect Git before enabling inbound webhooks.');
        if(($url==='')!==($out===''))throw new \InvalidArgumentException('Destination and outgoing secret are both required.');
        $events=array_values(array_intersect(self::EVENTS,$events));if($url!==''&&!$events)throw new \InvalidArgumentException('Select outgoing events.');
        $a['config']=$in===''&&$url===''?null:['id'=>$id,'actor'=>$actor,'git'=>$git,'url'=>$url===''?'':self::destination($url),'in_secret'=>$in===''?'':self::seal($in,$id,'in'),'out_secret'=>$out===''?'':self::seal($out,$id,'out'),'events'=>$url===''?[]:$events];
        foreach($a['jobs'] as &$j)if(in_array($j['state'],['queued','running'],true))self::terminal($j,'cancelled','Configuration replaced.');unset($j);
    }
    private static function terminal(array &$j,string $state,string $error=''):void{$j['state']=$state;$j['error']=$error;$j['updated']=time();$j['lease']='';$j['lease_until']=0;}
    private static function add(array &$a,array $c,string $kind,array $payload,?string $id=null):string{
        $id??=bin2hex(random_bytes(16));$now=time();
        if(count(array_filter($a['jobs'],fn($j)=>in_array($j['state'],['queued','running'],true)))>=self::MAX_PENDING)throw new \DomainException('Automation queue full. Drain jobs before retrying.');
        // Never evict pending work. Terminal history is bounded separately from replay receipts.
        if(count($a['jobs'])>=self::MAX_JOBS){foreach($a['jobs'] as $k=>$j)if(in_array($j['state'],['succeeded','dead','cancelled'],true)){unset($a['jobs'][$k]);break;}}
        if(count($a['jobs'])>=self::MAX_JOBS)throw new \DomainException('Automation queue full. Drain jobs before retrying.');
        $a['jobs'][$id]=['id'=>$id,'config'=>$c['id'],'actor'=>$c['actor'],'kind'=>$kind,'payload'=>$payload,'state'=>'queued','attempts'=>0,'created'=>$now,'updated'=>$now,'due'=>$now,'lease'=>'','lease_until'=>0,'error'=>'','result_commit'=>''];return $id;
    }
    /** Signature verification precedes parsing; no raw body or header is persisted. */
    public static function receive(array &$db,string $provider,string $body,array $headers,array $admins):array{
        $c=$db['automation']['config']??null;$git=$db['github']??null;
        if(!$c||!$git||$c['git']!==$git['id']||!isset($admins[$c['actor']])||($git['provider']??'github')!==$provider||$c['in_secret']==='')throw new \DomainException('Webhook unavailable.');
        $secret=self::secret($c,'in');
        if($provider==='github'){$valid=hash_equals('sha256='.hash_hmac('sha256',$body,$secret),$headers['signature']??'');$event=$headers['event']??'';$delivery=$headers['delivery']??'';}
        elseif($provider==='gitlab'){$valid=hash_equals($secret,$headers['signature']??'');$event=$headers['event']??'';$delivery=$headers['delivery']??'';}else throw new \InvalidArgumentException('Unknown provider.');
        if(!$valid)throw new OrganizationAccessException('Invalid webhook signature.');
        if($provider==='github'&&$event==='ping')return ['status'=>'ignored'];
        $push=$event===($provider==='github'?'push':'Push Hook');$merge=$event===($provider==='github'?'pull_request':'Merge Request Hook');
        if(!$push&&!$merge)return ['status'=>'ignored'];
        $p=json_decode($body,true,48,JSON_THROW_ON_ERROR);if(!is_array($p))throw new \InvalidArgumentException('Invalid webhook body.');
        if($provider==='github'){if(!is_string($p['repository']['full_name']??null)||strcasecmp($p['repository']['full_name'],$git['repo'])!==0)throw new OrganizationAccessException('Webhook repository mismatch.');}
        elseif(($p['project']['id']??null)!==($git['project_id']??null))throw new OrganizationAccessException('Webhook project mismatch.');
        if($push){
            if(($p['ref']??null)!=='refs/heads/'.$git['branch']||($p['deleted']??false)===true||($p['after']??null)===str_repeat('0',40))return ['status'=>'ignored'];
            $commit=$p['after']??null;if(!is_string($commit)||!preg_match('/\A[a-f0-9]{40}\z/D',$commit))throw new \InvalidArgumentException('Invalid push commit.');
        }elseif($provider==='github'){
            $pr=$p['pull_request']??[];if(($p['action']??null)!=='closed'||($pr['merged']??null)!==true||($pr['base']['ref']??null)!==$git['branch'])return ['status'=>'ignored'];
            $commit=$pr['merge_commit_sha']??'';
        }else{
            $mr=$p['object_attributes']??[];if(($mr['action']??null)!=='merge'||($mr['target_branch']??null)!==$git['branch'])return ['status'=>'ignored'];
            if(($mr['target_project_id']??null)!==($git['project_id']??null))throw new OrganizationAccessException('Webhook target project mismatch.');
            $commit=$mr['merge_commit_sha']??$mr['squash_commit_sha']??'';
        }
        // A merged MR may not supply a merge SHA (fast-forward). The worker resolves the configured branch head.
        if(!is_string($commit)||($commit!==''&&!preg_match('/\A[a-f0-9]{40}\z/D',$commit)))throw new \InvalidArgumentException('Invalid merge commit.');
        if($delivery===''&&$provider==='gitlab')$delivery=hash('sha256',$body);
        if(!preg_match('/\A[A-Za-z0-9._:-]{1,160}\z/D',$delivery))throw new \InvalidArgumentException('Invalid delivery identifier.');
        $a=&$db['automation'];$now=time();foreach($a['receipts'] as $k=>$r)if($r['expires']<=$now)unset($a['receipts'][$k]);
        $key=hash('sha256',$c['id'].':'.$provider.':id:'.$delivery);$hash=hash('sha256',$body);$bodyKey=hash('sha256',$c['id'].':'.$provider.':body:'.$hash);
        if(isset($a['receipts'][$key])){if(!hash_equals($a['receipts'][$key]['body'],$hash))throw new \DomainException('Delivery identifier reused with different payload.');return ['status'=>'duplicate','job'=>$a['receipts'][$key]['job']];}
        if(isset($a['receipts'][$bodyKey]))return ['status'=>'duplicate','job'=>$a['receipts'][$bodyKey]['job']];
        if(count($a['receipts'])>self::MAX_RECEIPTS-2)throw new \DomainException('Webhook replay ledger full. Retry later.');
        $active=count(array_filter($a['jobs'],fn($j)=>$j['kind']==='sync'&&in_array($j['state'],['queued','running'],true)));if($active>=64)throw new \DomainException('Sync queue full. Retry later.');
        $id=self::add($a,$c,'sync',['git'=>$git['id'],'commit'=>$commit]);$a['receipts'][$key]=$a['receipts'][$bodyKey]=['body'=>$hash,'job'=>$id,'expires'=>$now+self::RECEIPT_TTL];return ['status'=>'queued','job'=>$id];
    }
    public static function enqueueEvent(array &$db,string $event):void{
        $c=$db['automation']['config']??null;if(!$c||$c['url']===''||!in_array($event,$c['events'],true))return;
        $a=&$db['automation'];$id=bin2hex(random_bytes(16));$now=time();self::add($a,$c,'delivery',['id'=>$id,'event'=>$event,'at'=>$now],$id);
        // Keep event timestamp identical to persisted creation timestamp, even at a second boundary.
        $a['jobs'][$id]['payload']['at']=$a['jobs'][$id]['created'];
    }
    /** Transactional outbox: metadata only, never page text, paths, credentials or review notes. */
    public static function capture(array $before,array &$db):void{
        if(empty($db['automation']['config']['url']))return;
        $events=[];$changed=false;
        // Compare public projections by public URL, not draft key: a draft-only rename
        // must not signal publication when the old public URL and snapshot are retained.
        $publicA=BookStore::published($before);$publicB=BookStore::published($db);
        foreach($publicA as &$p)unset($p['updated']);unset($p);foreach($publicB as &$p)unset($p['updated']);unset($p);ksort($publicA);ksort($publicB);$published=$publicA!==$publicB;
        foreach($before['pages']+$db['pages'] as $key=>$unused){$a=$before['pages'][$key]??null;$b=$db['pages'][$key]??null;if($a===$b)continue;$draftA=$a['draft']??null;$draftB=$b['draft']??null;unset($draftA['updated'],$draftB['updated']);if($draftA!==$draftB||($a['id']??null)!==($b['id']??null))$changed=true;}
        if($changed||$published)$events[]='document.changed';if($published)$events[]='document.published';
        foreach($db['reviews']??[] as $id=>$r){$old=$before['reviews'][$id]['status']??null;if($old!==$r['status']){if($r['status']==='in_review')$events[]='review.requested';if($r['status']==='approved')$events[]='review.approved';}}
        if(($before['openapi']??[])!==($db['openapi']??[]))$events[]='openapi.updated';
        $old=$before['github']??null;$new=$db['github']??null;if($new&&$old&&$old['runs']!==$new['runs']){$r=end($new['runs']);if(($r['type']??'')==='sync')$events[]='git.sync.completed';if(($r['type']??'')==='error')$events[]='git.sync.failed';}
        foreach($db['ci']['builds']??[] as $id=>$b){$old=$before['ci']['builds'][$id]['status']??null;if($old!==$b['status']&&in_array($b['status'],['ready','approved','published'],true))$events[]='build.'.$b['status'];}
        foreach(array_unique($events) as $event)self::enqueueEvent($db,$event);
    }
    public static function claim(array &$db,int $now,bool $recover=false):?array{
        if(!isset($db['automation']))return null;$a=&$db['automation'];$failed=0;
        foreach($a['jobs'] as &$j){if($j['state']==='running'&&($recover||$j['lease_until']<=$now)){if($j['attempts']>=self::MAX_ATTEMPTS){self::terminal($j,'dead','Worker lease expired.');if($j['kind']==='sync')$failed++;}else{self::terminal($j,'queued','Worker lease expired.');$j['due']=$now;}}}unset($j);
        for($i=0;$i<$failed;$i++)self::enqueueEvent($db,'git.sync.failed');
        $candidate=null;foreach($a['jobs'] as $id=>$j)if($j['state']==='queued'&&$j['due']<=$now&&($candidate===null||$j['due']<$a['jobs'][$candidate]['due']))$candidate=$id;
        if($candidate===null)return null;$j=&$a['jobs'][$candidate];$j['state']='running';$j['attempts']++;$j['lease']=bin2hex(random_bytes(16));$j['lease_until']=$now+self::LEASE;$j['updated']=$now;return $j;
    }
    public static function assertLease(array $db,array $job,?int $now=null):array{
        $j=$db['automation']['jobs'][$job['id']]??null;$c=$db['automation']['config']??null;
        if(!$j||$j['state']!=='running'||$j['lease']!==$job['lease']||$j['lease_until']<=($now??time())||!$c||$c['id']!==$job['config']||$c['actor']!==$job['actor'])throw new \DomainException('Automation job no longer authorized.');return $c;
    }
    public static function finish(array &$db,array $job,string $state,string $error='',bool $retry=false,int $now=0,string $commit=''):void{
        if(!in_array($state,['succeeded','dead','cancelled'],true))throw new \InvalidArgumentException('Invalid terminal job state.');$now=$now?:time();$j=&$db['automation']['jobs'][$job['id']];if(!is_array($j)||$j['state']!=='running'||$j['lease']!==$job['lease']||$j['lease_until']<=$now)throw new \DomainException('Automation lease lost or expired.');
        if($retry&&$j['attempts']<self::MAX_ATTEMPTS){self::terminal($j,'queued',$error);$j['due']=$now+min(3600,30*(2**($j['attempts']-1)));return;}
        self::terminal($j,$state,$error);$j['result_commit']=$commit;
        if($job['kind']==='sync'&&$state==='dead')self::enqueueEvent($db,'git.sync.failed');
    }
    public static function retry(array &$db,string $id):void{
        $j=&$db['automation']['jobs'][$id];$c=$db['automation']['config']??null;if(!is_array($j)||$j['state']!=='dead'||!$c||$j['config']!==$c['id'])throw new \DomainException('Only failed jobs from the active configuration can be retried.');if(count(array_filter($db['automation']['jobs'],fn($x)=>in_array($x['state'],['queued','running'],true)))>=self::MAX_PENDING||($j['kind']==='sync'&&count(array_filter($db['automation']['jobs'],fn($x)=>$x['kind']==='sync'&&in_array($x['state'],['queued','running'],true)))>=64))throw new \DomainException('Automation queue full. Drain jobs before retrying.');self::terminal($j,'queued');$j['attempts']=0;$j['due']=time();$j['result_commit']='';
    }
    public static function delivery(array $config,array $job):array{
        $body=json_encode($job['payload'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$ts=(string)time();$signature=hash_hmac('sha256',$ts.'.'.$body,self::secret($config,'out'));
        return ['url'=>self::destination($config['url']),'origin'=>ApiPlayground::origin($config['url']),'method'=>'POST','headers'=>['Content-Type'=>'application/json','X-Cajeer-Delivery'=>$job['id'],'X-Cajeer-Timestamp'=>$ts,'X-Cajeer-Signature'=>'sha256='.$signature],'body'=>$body];
    }
}
