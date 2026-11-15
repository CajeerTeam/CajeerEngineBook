<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
require_once __DIR__.'/Organizations.php';
require_once __DIR__.'/OrganizationAccessException.php';
require_once __DIR__.'/Workspaces.php';
require_once __DIR__.'/Permissions.php';
require_once __DIR__.'/Visibility.php';
require_once __DIR__.'/ActivityJournal.php';
require_once __DIR__.'/PostgresContentStore.php';
require_once __DIR__.'/PostgresIdentityStore.php';
require_once __DIR__.'/Oidc.php';
require_once __DIR__.'/CustomDomains.php';

/** Private, versioned identity state. All mutations (including rate limits) serialize on one lock. */
final class IdentityStore
{
    private const MAX_BYTES=4*1024*1024;
    private const MAX_USERS=500;
    private const IDLE=28800;
    private const LIFETIME=604800;
    private const RESET_TTL=1800;
    private const DUMMY='$2y$12$DpzqfIAI7BeOso89k9BBBuHsphbrZMkYKhXVXARXfTDkjhp6ihHSu';
    private readonly string $dir;
    private ?\PDO $identityConnection=null;
    private ?PostgresIdentityStore $identityBackend=null;
    public function __construct(string $dataDir,private readonly bool $portable=false){$this->dir=rtrim($dataDir,'/').'/identity';}
    private static function empty():array{return ['schema'=>4,'users'=>[],'sessions'=>[],'resets'=>[],'attempts'=>[],'organizations'=>[],'invitations'=>[],'workspaces'=>[]];}
    /** A root-level marker survives loss of the identity directory. It binds the installation to its first owner. */
    private static function ownerId(array $db):?string{
        foreach($db['users'] as $id=>$u)if($u['owner'])return $id;
        return null;
    }
    private static function assertIdentityAnchor(string $root,array $db):void{
        $anchor=Files::path($root,'identity.required');$owner=self::ownerId($db);
        if(file_exists($anchor)){
            if(!is_file($anchor)||filesize($anchor)!==33||$owner===null||file_get_contents($anchor)!==$owner."\n")throw new \RuntimeException('Identity anchor mismatch; restore the installation backup.');
        }elseif($owner!==null){
            // Upgrade an existing valid installation, including read-only requests, before exposing content.
            Files::write($anchor,$owner."\n");
        }elseif(file_exists(Files::path($root,'workspaces'))){
            throw new \RuntimeException('Workspace data exists without initialized identity; restore the installation backup.');
        }
    }
    private static function upgrade(array $db):array
    {
        if(($db['schema']??null)===1){$db['schema']=2;$db['organizations']=[];$db['invitations']=[];}
        if(($db['schema']??null)===2)$db['schema']=3;
        $db['workspaces']??=[];
        if(($db['schema']??null)===3){$db['schema']=4;foreach($db['workspaces'] as &$w)$w['visibility']??=Visibility::workspace($w);unset($w);}
        self::validate($db);return $db;
    }
    private static function checkedJson(array &$db):string
    {
        ActivityJournal::trim($db,self::MAX_BYTES);self::validate($db);
        $json=json_encode($db,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(strlen($json)+1>self::MAX_BYTES)throw new \RuntimeException('Identity store quota exceeded');return $json;
    }
    private function transaction(callable $fn,bool $persist=true,bool $shared=false):mixed
    {
        if($shared&&$persist)throw new \LogicException('Shared identity transactions cannot persist.');
        $root=dirname($this->dir);
        if(!$this->portable&&($pg=PostgresIdentityStore::forDirectory($root))!==null){
            return $pg->transaction(function(array $db,\PDO $pdo)use($pg,$fn,$persist,$root,$shared){
                self::validate($db);$before=$db;
                if(strlen(json_encode($db,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))+1>self::MAX_BYTES)throw new \RuntimeException('Identity store quota exceeded');
                // Existing file anchors remain a check on migrated installations. PostgreSQL also
                // maintains its own immutable owner anchor, so new SQL users need no file write.
                $anchor=Files::path($root,'identity.required');
                if(file_exists($anchor)&&(!is_file($anchor)||filesize($anchor)!==33||file_get_contents($anchor)!==self::ownerId($db)."\n"))throw new \RuntimeException('Identity anchor mismatch; restore the installation backup.');
                $flush=function()use(&$db,&$before,$persist,$pg,$pdo):void{
                    if(!$persist||$db===$before)return;
                    
                    $pg->audit($pdo,array_diff_key($db['activity']??[],$before['activity']??[]));
                    self::checkedJson($db);$pg->write($pdo,$before,$db);$before=$db;
                };
                $this->identityConnection=$pdo;$this->identityBackend=$pg;
                try{$result=$fn($db,$flush);if($shared&&$db!==$before)throw new \LogicException('Shared identity transaction mutated state.');$flush();return $result;}
                finally{$this->identityConnection=null;$this->identityBackend=null;}
            },$shared);
        }
        return $this->fileTransaction($fn,$persist);
    }
    private function fileTransaction(callable $fn,bool $persist):mixed
    {
        Files::dir($this->dir);$lock=Files::path($this->dir,'identity.lock');$path=Files::path($this->dir,'identity.json');$root=dirname($this->dir);
        $h=fopen($lock,'c+b');if(!$h)throw new \RuntimeException('Identity lock unavailable');
        try{
            if(!chmod($lock,0600)||!flock($h,LOCK_EX))throw new \RuntimeException('Identity lock unavailable');
            // Recheck after acquiring the file lock so a racing cutover cannot permit stale writes.
            if(!$this->portable&&PostgresIdentityStore::forDirectory($root)!==null)throw new \RuntimeException('Identity backend changed; retry after migration.');
            $exists=file_exists($path);if($exists&&(!is_file($path)||filesize($path)>self::MAX_BYTES))throw new \RuntimeException('Invalid identity store');
            $db=$exists?Files::read($path):self::empty();$before=$db;$db=self::upgrade($db);
            self::assertIdentityAnchor($root,$db);
            $flush=function()use(&$db,&$before,$persist,$path,$root):void{
                if(!$persist||$db===$before)return;
                $json=self::checkedJson($db);
                self::assertIdentityAnchor($root,$db);Files::write($path,$json."\n");$before=$db;
            };
            $result=$fn($db,$flush);$flush();return $result;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
    /** Offline migration only: full validated source, including hashed sessions/invitations/resets. */
    public function migrationSnapshot(callable $fn):mixed
    {
        if(!$this->portable)throw new \LogicException('Migration requires explicit portable source.');
        return $this->fileTransaction(function($db)use($fn){
            // Do not trim or mutate source state during migration; refuse oversize legacy data.
            if(strlen(json_encode($db,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))+1>self::MAX_BYTES)throw new \RuntimeException('Identity store quota exceeded');
            return $fn($db);
        },false);
    }
    private static function hex(string $s,int $length):bool{return strlen($s)===$length&&ctype_xdigit($s)&&strtolower($s)===$s;}
    public static function username(string $s):string{$s=strtolower(trim($s));if(!preg_match('/\A[a-z][a-z0-9_.-]{2,39}\z/D',$s))throw new \InvalidArgumentException('Username must be 3–40 characters (letters, digits, dot, underscore, hyphen).');return $s;}
    private static function name(string $s):string{$s=trim($s);if($s===''||strlen($s)>120||!preg_match('//u',$s)||preg_match('/[\x00-\x1f\x7f]/',$s))throw new \InvalidArgumentException('Invalid display name.');return $s;}
    private static function email(string $s,bool $optional=false):string{$s=strtolower(trim($s));if($optional&&$s==='')return '';if(strlen($s)>254||!filter_var($s,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid email address.');return $s;}
    private static function password(string $s):string{if(strlen($s)<12||strlen($s)>72||str_contains($s,"\0"))throw new \InvalidArgumentException('Password must be 12–72 bytes.');return password_hash($s,PASSWORD_DEFAULT);}
    private static function validHash(string $hash):bool{return strlen($hash)<=255&&password_get_info($hash)['algoName']!=='unknown';}
    private static function validate(array $db):void
    {
        if(($db['schema']??null)!==4)throw new \RuntimeException('Unsupported identity schema');
        foreach(['users','sessions','resets','attempts'] as $k)if(!is_array($db[$k]??null))throw new \RuntimeException('Invalid identity state');
        if(count($db['users'])>self::MAX_USERS||count($db['sessions'])>self::MAX_USERS*10||count($db['resets'])>self::MAX_USERS||count($db['attempts'])>4096)throw new \RuntimeException('Invalid identity counts');
        $names=[];$emails=[];$owners=0;$external=[];
        foreach($db['users'] as $id=>$u){
            if(!is_string($id)||!self::hex($id,32)||!is_array($u)||($u['id']??null)!==$id||!is_string($u['username']??null)||self::username($u['username'])!==$u['username']||!is_string($u['name']??null)||self::name($u['name'])!==$u['name']||!is_string($u['email']??null)||self::email($u['email'],true)!==$u['email']||!is_string($u['hash']??null)||!self::validHash($u['hash'])||!is_bool($u['owner']??null)||!is_bool($u['enabled']??null)||!is_int($u['created']??null))throw new \RuntimeException('Invalid identity user');
            if(isset($names[$u['username']])||($u['email']!==''&&isset($emails[$u['email']])))throw new \RuntimeException('Duplicate identity');$names[$u['username']]=true;$emails[$u['email']]=true;
            if(isset($u['oidc'])){ $link=$u['oidc'];if($u['owner']||!is_array($link)||!is_string($link['issuer']??null)||!is_string($link['subject']??null)||$link['subject']===''||strlen($link['subject'])>255||!is_string($link['organization']??null)||!self::hex($link['organization'],32))throw new \RuntimeException('Invalid federated identity');if(isset($link['restrictions'])){if(!is_array($link['restrictions'])||count($link['restrictions'])>1000)throw new \RuntimeException('Invalid federated restrictions');foreach($link['restrictions'] as $wid=>$role)if(!is_string($wid)||!self::hex($wid,32)||!in_array($role,['none','viewer','editor','admin'],true))throw new \RuntimeException('Invalid federated restriction');}$key=hash('sha256',$link['issuer']."\0".$link['subject']);if(isset($external[$key]))throw new \RuntimeException('Duplicate federated identity');$external[$key]=true;}
            if($u['owner']){if(!$u['enabled'])throw new \RuntimeException('Disabled owner');$owners++;}
        }
        if($db['users']!==[]&&$owners!==1)throw new \RuntimeException('Identity owner invariant failed');
        foreach($db['sessions'] as $key=>$s)if(!is_string($key)||!self::hex($key,64)||!is_array($s)||!isset($db['users'][$s['user']??''])||!is_string($s['id']??null)||!self::hex($s['id'],32)||!is_string($s['csrf']??null)||!self::hex($s['csrf'],64)||!is_int($s['created']??null)||!is_int($s['seen']??null)||!is_int($s['expires']??null))throw new \RuntimeException('Invalid identity session');
        foreach($db['resets'] as $key=>$r)if(!is_string($key)||!self::hex($key,64)||!is_array($r)||!isset($db['users'][$r['user']??''])||!is_int($r['expires']??null))throw new \RuntimeException('Invalid reset record');
        foreach($db['attempts'] as $k=>$a)if(!is_string($k)||!self::hex($k,64)||!is_array($a)||!is_int($a['count']??null)||!is_int($a['until']??null)||$a['count']<1)throw new \RuntimeException('Invalid rate limit');
        $flows=$db['oidc_flows']??[];if(!is_array($flows)||count($flows)>500)throw new \RuntimeException('Invalid OIDC flow state');
        foreach($flows as $key=>$f)if(!is_string($key)||!self::hex($key,64)||!is_array($f)||!is_string($f['browser']??null)||!self::hex($f['browser'],64)||!is_string($f['policy']??null)||!self::hex($f['policy'],64)||!is_int($f['expires']??null)||!is_string($f['nonce']??null)||!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$f['nonce'])||!is_string($f['verifier']??null)||!preg_match('/\A[A-Za-z0-9_-]{64}\z/D',$f['verifier']))throw new \RuntimeException('Invalid OIDC flow');
        Organizations::validate($db);Workspaces::validate($db);CustomDomains::validate($db);ActivityJournal::validate($db);
    }
    private static function safe(array $u):array{return array_intersect_key($u,array_flip(['id','username','name','email','owner','enabled','created']))+(isset($u['oidc'])?['federated'=>true]:[]);}
    private static function find(array $db,string $username):?array{foreach($db['users'] as $u)if($u['username']===$username)return $u;return null;}
    private static function prune(array &$db,int $now):void{
        foreach($db['sessions'] as $k=>$s)if($s['expires']<=$now||$s['seen']<=($now-self::IDLE)||!self::sessionAllowed($db['users'][$s['user']],$s))unset($db['sessions'][$k]);
        foreach($db['resets'] as $k=>$r)if($r['expires']<=$now)unset($db['resets'][$k]);
        foreach($db['attempts'] as $k=>$a)if($a['until']<=$now)unset($db['attempts'][$k]);
        foreach($db['oidc_flows']??[] as $k=>$f)if($f['expires']<=$now)unset($db['oidc_flows'][$k]);
    }
    private static function rate(array &$db,string $bucket,string $ip,string $name,int $now):bool{
        $keys=[hash('sha256',$bucket.':ip:'.$ip)=>30,hash('sha256',$bucket.':account:'.$name)=>5];
        foreach($keys as $k=>$max)if(($db['attempts'][$k]['count']??0)>=$max)return false;
        if(count($db['attempts'])>4094)return false;
        foreach($keys as $k=>$max){$db['attempts'][$k]??=['count'=>0,'until'=>$now+900];$db['attempts'][$k]['count']++;}return true;
    }
    private static function add(array &$db,string $username,string $name,string $email,string $hash,bool $owner):array{
        if(count($db['users'])>=self::MAX_USERS)throw new \DomainException('User quota exceeded.');
        if(self::find($db,$username))throw new \DomainException('Username already exists.');
        foreach($db['users'] as $u)if($email!==''&&$u['email']===$email)throw new \DomainException('Email already exists.');
        $id=bin2hex(random_bytes(16));return $db['users'][$id]=['id'=>$id,'username'=>$username,'name'=>$name,'email'=>$email,'hash'=>$hash,'owner'=>$owner,'enabled'=>true,'created'=>time()];
    }
    private static function session(array &$db,array $u,int $now):array{
        $owned=array_filter($db['sessions'],fn($s)=>$s['user']===$u['id']);uasort($owned,fn($a,$b)=>$a['seen']<=>$b['seen']);while(count($owned)>=10){$k=array_key_first($owned);unset($owned[$k],$db['sessions'][$k]);}
        $token=bin2hex(random_bytes(32));$s=['id'=>bin2hex(random_bytes(16)),'user'=>$u['id'],'csrf'=>bin2hex(random_bytes(32)),'created'=>$now,'seen'=>$now,'expires'=>$now+self::LIFETIME];$db['sessions'][hash('sha256',$token)]=$s;return ['token'=>$token,'session'=>$s,'user'=>self::safe($u)];
    }
    private static function requireSession(array $db,string $token):array{
        $s=self::hex($token,64)?($db['sessions'][hash('sha256',$token)]??null):null;$now=time();
        if(!$s||$s['expires']<=$now||$s['seen']<=$now-self::IDLE||!self::sessionAllowed($db['users'][$s['user']],$s))throw new \DomainException('Session expired. Sign in again.');return $s;
    }
    private static function revoke(array &$db,string $id):void{foreach($db['sessions'] as $k=>$s)if($s['user']===$id)unset($db['sessions'][$k]);foreach($db['resets'] as $k=>$r)if($r['user']===$id)unset($db['resets'][$k]);}

    private static function sessionAllowed(array $u,array $s):bool
    {
        if(!$u['enabled'])return false;
        if($u['owner'])return true;
        if(!isset($u['oidc']))return !Oidc::required();
        try{$c=Oidc::config();return $c!==null&&($s['oidc_policy']??null)===Oidc::fingerprint($c)&&$u['oidc']['issuer']===$c['issuer']&&$u['oidc']['organization']===$c['organization'];}catch(\Throwable){return false;}
    }
    public function checkOidcOrganization(string $id):void{$this->transaction(function($db)use($id){if(!$db['users']||!isset($db['organizations'][$id])||$db['organizations'][$id]['archived'])throw new \DomainException('OIDC organization unavailable.');},false);}
    /** Short-lived, browser-bound authorization transactions. No network calls under the identity lock. */
    public function beginOidc(array $c,string $browser,string $ip):array
    {
        return $this->transaction(function(&$db)use($c,$browser,$ip){
            $now=time();self::prune($db,$now);
            if($db['users']===[]||!isset($db['organizations'][$c['organization']])||$db['organizations'][$c['organization']]['archived'])throw new \DomainException('OIDC organization unavailable.');
            if(!self::rate($db,'oidc-start',$ip,hash('sha256',$browser),$now))return ['status'=>'limited'];
            $db['oidc_flows']??=[];foreach($db['oidc_flows'] as $k=>$f)if($f['expires']<=$now||$f['browser']===hash('sha256',$browser))unset($db['oidc_flows'][$k]);
            if(count($db['oidc_flows'])>=500)return ['status'=>'limited'];
            $state=bin2hex(random_bytes(32));$f=['browser'=>hash('sha256',$browser),'nonce'=>Oidc::b64(random_bytes(32)),'verifier'=>Oidc::b64(random_bytes(48)),'expires'=>$now+300,'policy'=>Oidc::fingerprint($c)];
            $db['oidc_flows'][hash('sha256',$state)]=$f;return ['status'=>'ok','state'=>$state]+$f;
        });
    }
    public function consumeOidc(array $c,string $state,string $browser):?array
    {
        if(!self::hex($state,64)||!self::hex($browser,64))return null;
        return $this->transaction(function(&$db)use($c,$state,$browser){
            $k=hash('sha256',$state);$f=$db['oidc_flows'][$k]??null;
            if(!$f||!hash_equals($f['browser'],hash('sha256',$browser)))return null;
            unset($db['oidc_flows'][$k]);return $f['expires']>time()&&hash_equals($f['policy'],Oidc::fingerprint($c))?$f:null;
        });
    }
    /** Claims are supplied only by Oidc::verify after signature, issuer, nonce and audience checks. */
    public function federatedLogin(array $c,array $claims):array
    {
        return $this->transaction(function(&$db)use($c,$claims){
            $now=time();self::prune($db,$now);$oid=$c['organization'];$o=$db['organizations'][$oid]??null;
            if($db['users']===[]||!$o||$o['archived']||$claims['exp']<=$now)return ['status'=>'invalid'];
            $u=null;foreach($db['users'] as $candidate)if(($candidate['oidc']['issuer']??null)===$c['issuer']&&($candidate['oidc']['subject']??null)===$claims['sub']){$u=$candidate;break;}
            if($u&&(!$u['enabled']||$u['owner']||$u['oidc']['organization']!==$oid))return ['status'=>'invalid'];
            $role=Oidc::role($c,$claims);
            if($role==='none'){
                if($u){self::revoke($db,$u['id']);$id=$u['id'];if(isset($o['members'][$id])&&$o['owner']!==$id){unset($o['members'][$id],$o['roles'][$id]);$o['revision']++;$o['updated']=$now;$db['organizations'][$oid]=$o;foreach($db['workspaces'] as &$w)if($w['organization']===$oid&&isset($w['roles'][$id])){$db['users'][$id]['oidc']['restrictions'][$w['id']]=$w['roles'][$id];unset($w['roles'][$id]);$w['revision']++;$w['updated']=$now;}unset($w);ActivityJournal::record($db,$u,'organization.remove','SSO group access removed',$id,$oid);}}
                return ['status'=>'invalid'];
            }
            if(!$u){
                $username='sso-'.substr(hash('sha256',$c['issuer']."\0".$claims['sub']),0,32);
                $email='';if(($claims['email_verified']??false)===true&&is_string($claims['email']??null))$email=self::email($claims['email']);
                $name=is_string($claims['name']??null)?$claims['name']:$username;
                try{$name=self::name($name);}catch(\InvalidArgumentException){$name=$username;}
                $u=self::add($db,$username,$name,$email,self::password(bin2hex(random_bytes(32))),false);$u['oidc']=['issuer'=>$c['issuer'],'subject'=>$claims['sub'],'organization'=>$oid];$db['users'][$u['id']]=$u;
                ActivityJournal::record($db,$u,'account.create','SSO provisioning',$u['id']);
            }
            $id=$u['id'];if($o['owner']===$id)return ['status'=>'invalid'];
            $old=Permissions::organization($o,$id);if($old!==$role){$o['members'][$id]??=$now;$o['roles'][$id]=$role;$o['revision']++;$o['updated']=$now;$db['organizations'][$oid]=$o;
                foreach($db['invitations'] as $k=>$i)if($i['organization']===$oid&&($i['user']===$id||$i['username']===$u['username']))unset($db['invitations'][$k]);
                ActivityJournal::record($db,$u,'organization.role','SSO group role',$id,$oid,'',[],$role);
            }
            foreach($db['workspaces'] as &$w)if($w['organization']===$oid&&isset($w['roles'][$id])&&($w['roles'][$id]==='admin'||($role==='viewer'&&$w['roles'][$id]==='editor'))){$w['roles'][$id]=$role;$w['revision']++;$w['updated']=$now;}unset($w);
            // Restore narrower workspace grants after a group-removal/rejoin cycle.
            foreach($u['oidc']['restrictions']??[] as $wid=>$restriction)if(isset($db['workspaces'][$wid])&&$db['workspaces'][$wid]['organization']===$oid&&in_array($restriction,['none','viewer','editor','admin'],true)){
                $w=&$db['workspaces'][$wid];$w['roles']??=[];if(!isset($w['roles'][$id])){$w['roles'][$id]=$restriction==='none'?'none':($role==='viewer'?'viewer':($restriction==='admin'?'editor':$restriction));$w['revision']++;$w['updated']=$now;}unset($w);
            }
            unset($db['users'][$id]['oidc']['restrictions']);
            // Preserve explicit workspace restrictions. Organization role is a ceiling.
            self::revoke($db,$id);$r=self::session($db,$u,$now);$r['session']['expires']=min($claims['exp'],$now+3600);$r['session']['oidc_policy']=Oidc::fingerprint($c);$db['sessions'][hash('sha256',$r['token'])]=$r['session'];
            ActivityJournal::record($db,$u,'login','SSO sign in',$id);return ['status'=>'ok']+$r;
        });
    }
    public function checkReady():void{$this->transaction(function($db){if($this->identityConnection!==null){$this->identityBackend->auditReady($this->identityConnection);return;}$probe=$this->dir.'/.ready-'.bin2hex(random_bytes(12));try{Files::write($probe,'ready');}finally{if(is_file($probe))Files::remove($probe);}},false,true);}
    public function initialized():bool{return $this->transaction(fn($db)=>$db['users']!==[]);}
    public function initialize(string $username,string $name,string $email,string $password):array{return $this->transaction(function(&$db)use($username,$name,$email,$password){if($db['users']!==[])throw new \DomainException('Identity already initialized.');$u=self::add($db,self::username($username),self::name($name),self::email($email),self::password($password),true);ActivityJournal::record($db,$u,'account.create',$u['username'],$u['id']);return self::safe($u);});}
    public function login(string $username,string $password,string $ip,string $legacyHash=''):array{
        $username=strtolower(trim(substr($username,0,100)));$tooLong=strlen($password)>72;$password=$tooLong?'':$password;
        return $this->transaction(function(&$db)use($username,$password,$ip,$legacyHash,$tooLong){$now=time();self::prune($db,$now);if(!self::rate($db,'login',$ip,$username,$now)){ActivityJournal::record($db,null,'login.limited');return ['status'=>'limited'];}
            $u=self::find($db,$username);$legacy=$db['users']===[]&&$username==='admin'&&self::validHash($legacyHash);$hash=$u['hash']??($legacy?$legacyHash:self::DUMMY);
            $ok=!$tooLong&&!str_contains($password,"\0")&&password_verify($password,$hash);
            if(!$ok||(!$u&&!$legacy)||($u&&(!$u['enabled']||isset($u['oidc'])||(!$u['owner']&&Oidc::required())))){ActivityJournal::record($db,null,'login.failed');return ['status'=>'invalid'];}
            if($legacy)$u=self::add($db,'admin','Administrator','',$legacyHash,true);
            if(strlen($password)<=72&&password_needs_rehash($u['hash'],PASSWORD_DEFAULT)){$u['hash']=password_hash($password,PASSWORD_DEFAULT);$db['users'][$u['id']]=$u;}
            unset($db['attempts'][hash('sha256','login:account:'.$username)]);ActivityJournal::record($db,$u,'login','Sign in',$u['id']);return ['status'=>'ok']+self::session($db,$u,$now);
        });
    }
    public function current(string $token):?array{
        if(!self::hex($token,64))return null;
        return $this->transaction(function(&$db)use($token){$now=time();self::prune($db,$now);$key=hash('sha256',$token);$s=$db['sessions'][$key]??null;if(!$s)return null;if($s['seen']<=$now-300){$s['seen']=$now;$db['sessions'][$key]=$s;}return ['session'=>$s,'user'=>self::safe($db['users'][$s['user']])];});
    }
    public function logout(string $token):void{$this->transaction(function(&$db)use($token){$s=$db['sessions'][hash('sha256',$token)]??null;if($s){ActivityJournal::record($db,$db['users'][$s['user']],'logout','Sign out',$s['user']);unset($db['sessions'][hash('sha256',$token)]);}});}
    public function sessions(string $token):array{return $this->transaction(function(&$db)use($token){self::prune($db,time());$s=self::requireSession($db,$token);return array_values(array_map(fn($r)=>array_diff_key($r,['csrf'=>true]),array_filter($db['sessions'],fn($r)=>$r['user']===$s['user'])));});}
    public function revokeSession(string $token,string $id):void{$this->transaction(function(&$db)use($token,$id){$s=self::requireSession($db,$token);$n=0;foreach($db['sessions'] as $k=>$r)if($r['user']===$s['user']&&($id==='all'||$r['id']===$id)){unset($db['sessions'][$k]);$n++;}if($n)ActivityJournal::record($db,$db['users'][$s['user']],'account.sessions','Sessions revoked',$s['user'],'','',[],$n.' session(s)');});}
    public function profile(string $token,string $name,string $email):void{$this->transaction(function(&$db)use($token,$name,$email){$s=self::requireSession($db,$token);$name=self::name($name);$email=self::email($email);foreach($db['users'] as $id=>$u)if($id!==$s['user']&&$u['email']===$email)throw new \DomainException('Email already exists.');$db['users'][$s['user']]['name']=$name;$db['users'][$s['user']]['email']=$email;ActivityJournal::record($db,$db['users'][$s['user']],'account.profile','Profile updated',$s['user']);});}
    public function changePassword(string $token,string $old,string $new,string $ip):array{return $this->transaction(function(&$db)use($token,$old,$new,$ip){$now=time();self::prune($db,$now);$s=self::requireSession($db,$token);$u=$db['users'][$s['user']];if(isset($u['oidc']))return ['status'=>'invalid'];if(!self::rate($db,'password',$ip,$u['id'],$now))return ['status'=>'limited'];if(strlen($old)>72||str_contains($old,"\0")||!password_verify($old,$u['hash']))return ['status'=>'invalid'];$u['hash']=self::password($new);$db['users'][$u['id']]=$u;self::revoke($db,$u['id']);ActivityJournal::record($db,$u,'account.password','Password changed',$u['id']);return ['status'=>'ok']+self::session($db,$u,$now);});}
    private static function owner(array $db,string $token):void{$s=self::requireSession($db,$token);if(!$db['users'][$s['user']]['owner'])throw new \DomainException('Owner permission required.');}
    public function users(string $token):array{return $this->transaction(function(&$db)use($token){self::owner($db,$token);return array_values(array_map(self::safe(...),$db['users']));});}
    public function createUser(string $token,string $username,string $name,string $email,string $password):array{return $this->transaction(function(&$db)use($token,$username,$name,$email,$password){self::owner($db,$token);$u=self::add($db,self::username($username),self::name($name),self::email($email),self::password($password),false);ActivityJournal::record($db,$db['users'][self::requireSession($db,$token)['user']],'account.create',$u['username'],$u['id']);return self::safe($u);});}
    public function setEnabled(string $token,string $id,bool $enabled):void{$this->transaction(function(&$db)use($token,$id,$enabled){self::owner($db,$token);$u=$db['users'][$id]??null;if(!$u||$u['owner'])throw new \DomainException('Cannot change this account.');if(!$enabled)foreach($db['organizations'] as $o)if($o['owner']===$id)throw new \DomainException('Transfer organization ownership before disabling this account.');$db['users'][$id]['enabled']=$enabled;if(!$enabled)self::revoke($db,$id);if($u['enabled']!==$enabled)ActivityJournal::record($db,$db['users'][self::requireSession($db,$token)['user']],$enabled?'account.enable':'account.disable',$u['username'],$id);});}
    /** Trusted operator only; token never stored in plaintext. */
    public function issueReset(string $username):string{return $this->transaction(function(&$db)use($username){$u=self::find($db,self::username($username));if(!$u||!$u['enabled']||isset($u['oidc']))throw new \DomainException('Account unavailable.');self::prune($db,time());foreach($db['resets'] as $k=>$r)if($r['user']===$u['id'])unset($db['resets'][$k]);$token=bin2hex(random_bytes(32));$db['resets'][hash('sha256',$token)]=['user'=>$u['id'],'expires'=>time()+self::RESET_TTL];return $token;});}
    public function reset(string $token,string $password,string $ip):string{return $this->transaction(function(&$db)use($token,$password,$ip){$now=time();self::prune($db,$now);$key=hash('sha256',substr($token,0,128));if(!self::rate($db,'reset',$ip,$key,$now))return 'limited';$r=self::hex($token,64)?($db['resets'][$key]??null):null;if(!$r||!$db['users'][$r['user']]['enabled']||isset($db['users'][$r['user']]['oidc']))return 'invalid';$db['users'][$r['user']]['hash']=self::password($password);self::revoke($db,$r['user']);ActivityJournal::record($db,$db['users'][$r['user']],'account.reset','Password recovered',$r['user']);return 'ok';});}
    /** Trusted operator only: no credentials or membership records returned. */
    public function legacyWorkspaceAvailable(string $token):bool{return $this->transaction(fn($db)=>($db['users'][self::requireSession($db,$token)['user']]['owner']??false)&&Workspaces::legacy($db)===null);}
    /** Domain management shares the identity lock and current workspace RBAC. */
    private static function domainManager(array $db,string $token,string $csrf,string $id):string{
        $session=self::requireSession($db,$token);$uid=$session['user'];$w=$db['workspaces'][$id]??null;$o=$w?$db['organizations'][$w['organization']]:null;
        if(!hash_equals($session['csrf'],$csrf)||!$o||$o['archived']||$w['legacy']||!Permissions::allows(Permissions::workspace($o,$w,$uid),'manage'))throw new OrganizationAccessException('Domain management denied.');
        return $uid;
    }
    public function mutateDomain(string $token,string $csrf,string $id,string $host,string $action,int $rev):void{
        $host=CustomDomains::name($host);$primary=CustomDomains::primary();if($primary===null)throw new \DomainException('Configure BOOK_PRIMARY_HOST first.');
        if($host===$primary)throw new \InvalidArgumentException('Primary application host is reserved.');
        $this->transaction(function(&$db)use($token,$csrf,$id,$host,$action,$rev){
            $uid=self::domainManager($db,$token,$csrf,$id);$w=&$db['workspaces'][$id];if($w['revision']!==$rev)throw new \DomainException('Workspace changed. Reload.');
            $domains=$w['domains']??[];
            if($action==='add'){
                if(!CustomDomains::eligible($db,$w))throw new \DomainException('Only active public workspaces support domains.');
                foreach($db['workspaces'] as $other)if(isset($other['domains'][$host]))throw new \DomainException('Domain already registered.');
                if(count($domains)>=CustomDomains::LIMIT)throw new \DomainException('Domain quota reached.');$domains[$host]=CustomDomains::record();
            }else{
                if(!isset($domains[$host]))throw new \InvalidArgumentException('Unknown domain.');
                if($action==='remove'){unset($domains[$host]);if(($w['primary_domain']??null)===$host)unset($w['primary_domain']);}
                elseif($action==='disable'){$domains[$host]=CustomDomains::record();$domains[$host]['disabled']=true;}
                elseif($action==='reset')$domains[$host]=CustomDomains::record();
                elseif($action==='primary'){if(!CustomDomains::eligible($db,$w)||!CustomDomains::active($domains[$host]))throw new \DomainException('Verify domain first.');$w['primary_domain']=$host;}
                else throw new \InvalidArgumentException('Unknown domain operation.');
            }
            $w['domains']=$domains;$w['revision']++;ActivityJournal::record($db,$db['users'][$uid],'workspace.domain',$host,$id,$w['organization'],$id,[],$action);
        });
    }
    /** Capture proof under lock, resolve DNS outside it, then compare/re-authorize on commit. */
    public function verifyDomain(string $token,string $csrf,string $id,string $host,int $rev,?callable $lookup=null):bool{
        $host=CustomDomains::name($host);
        $capture=$this->transaction(function(&$db)use($token,$csrf,$id,$host,$rev){
            self::domainManager($db,$token,$csrf,$id);$w=&$db['workspaces'][$id];$r=$w['domains'][$host]??null;
            if(CustomDomains::primary()===null||$host===CustomDomains::primary()||$w['revision']!==$rev||!CustomDomains::eligible($db,$w)||!$r||$r['disabled'])throw new \DomainException('Domain unavailable or workspace changed.');
            if($r['checked']>time()-30)throw new \DomainException('Wait 30 seconds before checking DNS again.');
            $w['domains'][$host]['checked']=time();return $r['token'];
        });
        $proof=CustomDomains::proof($host,$capture,$lookup);
        return $this->transaction(function(&$db)use($token,$csrf,$id,$host,$capture,$proof,$rev){
            $uid=self::domainManager($db,$token,$csrf,$id);$w=&$db['workspaces'][$id];$r=$w['domains'][$host]??null;
            if($w['revision']!==$rev||!CustomDomains::eligible($db,$w)||!$r||$r['disabled']||!hash_equals($r['token'],$capture))throw new \DomainException('Domain changed during DNS verification.');
            if($proof!==null)$w['domains'][$host]['verified']=$proof?time():0;
            if($proof===true&&!isset($w['primary_domain']))$w['primary_domain']=$host;
            $w['revision']++;ActivityJournal::record($db,$db['users'][$uid],'workspace.domain',$host,$id,$w['organization'],$id,[],$proof===true?'verified':($proof===false?'proof missing':'DNS unavailable'));
            return $proof===true;
        });
    }
    /** Periodic renewal of previously verified claims. Never activates pending claims. */
    public function recheckDomains(?callable $lookup=null):array{
        if(CustomDomains::primary()===null)return ['checked'=>0,'valid'=>0,'failed'=>0];
        $items=$this->transaction(function($db){$out=[];foreach($db['workspaces'] as $w)if(CustomDomains::eligible($db,$w))foreach($w['domains']??[] as $host=>$r)if(!$r['disabled']&&$r['verified']>0&&$r['checked']<=time()-CustomDomains::RECHECK)$out[]=[$w['id'],$host,$r['token']];return $out;},false);
        $result=['checked'=>0,'valid'=>0,'failed'=>0];
        foreach($items as [$id,$host,$challenge]){
            $claimed=$this->transaction(function(&$db)use($id,$host,$challenge){$w=$db['workspaces'][$id]??null;$r=$w['domains'][$host]??null;if(!$w||!CustomDomains::eligible($db,$w)||!$r||$r['disabled']||$r['verified']===0||$r['checked']>time()-CustomDomains::RECHECK||!hash_equals($r['token'],$challenge))return false;$db['workspaces'][$id]['domains'][$host]['checked']=time();return $db['workspaces'][$id]['domains'][$host]['checked'];});
            if(!$claimed)continue;$result['checked']++;$proof=CustomDomains::proof($host,$challenge,$lookup);
            $this->transaction(function(&$db)use($id,$host,$challenge,$proof,$claimed){$w=$db['workspaces'][$id]??null;$r=$w['domains'][$host]??null;if(!$w||!CustomDomains::eligible($db,$w)||!$r||$r['disabled']||$r['checked']!==$claimed||!hash_equals($r['token'],$challenge))return;if($proof!==null)$db['workspaces'][$id]['domains'][$host]['verified']=$proof?time():0;if($proof===false){$db['workspaces'][$id]['revision']++;ActivityJournal::record($db,null,'workspace.domain',$host,$id,$w['organization'],$id,[],'DNS proof lost');}});
            $result[$proof===true?'valid':'failed']++;
        }
        return $result;
    }
    /** Always anonymous, even if a caller supplies a valid main-host session cookie. */
    public function withDomain(string $host,callable $fn):mixed{return $this->transaction(fn($db)=>$fn(CustomDomains::find($db,$host)),false,true);}
    public function operatorWorkspaces():array{return $this->transaction(fn($db)=>array_values($db['workspaces']));}
    public function checkWorkspaceStores(string $root):void{$this->transaction(function($db)use($root){Workspaces::assertRegistryDirectories($root,$db);PostgresContentStore::assertRegistry($root,$db);(new BookStore($root,Workspaces::legacy($db)===null))->checkReady();foreach($db['workspaces'] as $w)if(!$w['legacy'])(new BookStore(Workspaces::directory($root,$w),false))->checkReady();},false,true);}
    public function workspaces(string $token):array{return $this->transaction(fn($db)=>Workspaces::list($db,self::requireSession($db,$token)['user']));}
    public function createWorkspace(string $token,string $org,string $name,string $slug,bool $legacy=false):array{return $this->transaction(function(&$db)use($token,$org,$name,$slug,$legacy){$uid=self::requireSession($db,$token)['user'];$w=Workspaces::create($db,$uid,$org,$name,$slug,$legacy);ActivityJournal::record($db,$db['users'][$uid],$legacy?'workspace.adopt':'workspace.create',$w['name'],$w['id'],$org,$w['id']);
        if($legacy){$root=dirname($this->dir);$store=new BookStore($root);$store->materialize();}
        if(!$legacy){$dir=Workspaces::directory(dirname($this->dir),$w);if(file_exists($dir)||is_link($dir))throw new \RuntimeException('Workspace directory already exists');
            if($this->identityConnection!==null){$this->identityBackend->initializeWorkspace($this->identityConnection,$org.'/'.$w['id']);}
            else{Files::dir($dir);(new BookStore($dir,false))->initialize();}
        }
        return $w;});}
    public function mutateWorkspace(string $token,string $id,int $rev,string $action,string $name,string $slug):void{$this->transaction(function(&$db)use($token,$id,$rev,$action,$name,$slug){$uid=self::requireSession($db,$token)['user'];Workspaces::mutate($db,$uid,$id,$rev,$action,$name,$slug);$w=$db['workspaces'][$id];ActivityJournal::record($db,$db['users'][$uid],'workspace.'.$action,$w['name'],$id,$w['organization'],$id,[],$action==='visibility'?$w['visibility']:'');});}
    public function assignWorkspaceRole(string $token,string $id,int $rev,string $target,string $role):void{
        $this->transaction(function(&$db)use($token,$id,$rev,$target,$role){
            $uid=self::requireSession($db,$token)['user'];$w=$db['workspaces'][$id]??null;$o=$w?$db['organizations'][$w['organization']]:null;
            if(!$o||$o['archived']||$w['archived']||!Permissions::allows(Permissions::workspace($o,$w,$uid),'manage'))throw new OrganizationAccessException('Workspace management denied.');
            if($w['revision']!==$rev)throw new \DomainException('Workspace changed. Reload.');
            Permissions::assignWorkspace($o,$w,$uid,$target,$role);$w['revision']++;$db['workspaces'][$id]=$w;ActivityJournal::record($db,$db['users'][$uid],'workspace.role',$w['name'],$target,$w['organization'],$id,[$target],$db['users'][$target]['username'].' · '.$role);
        });
    }
    /** Identity lock precedes every scoped content lock. No identity calls inside callback. */
    private static function context(array &$db,string $token):?array{
        try{$s=self::requireSession($db,$token);}catch(\DomainException){return null;}
        if($s['seen']<=time()-300){$s['seen']=time();$db['sessions'][hash('sha256',$token)]=$s;}
        return ['session'=>$s,'user'=>self::safe($db['users'][$s['user']])];
    }
    /** Review assignment uses live enabled users and effective permissions under the identity lock. */
    private static function reviewUsers(array $db,?array $w,string $permission='edit'):array{
        $out=[];$o=$w?$db['organizations'][$w['organization']]:null;
        if($w&&($w['archived']||$o['archived']))return [];
        foreach($db['users'] as $id=>$u){if(!$u['enabled'])continue;$role=$w?Permissions::workspace($o,$w,$id):($u['owner']?'owner':'none');
            if(Permissions::allows($role,$permission))$out[$id]=['id'=>$id,'username'=>$u['username'],'role'=>$role];}
        return $out;
    }
    /** Trusted automation entrypoint; the configured administrator is re-evaluated on each phase. */
    public function automationWorkspace(string $org,string $id,callable $fn):mixed{
        return $this->transaction(function($db)use($org,$id,$fn){
            if($id===''){$w=Workspaces::legacy($db);if($org!=='')return null;}
            else{$w=$db['workspaces'][$id]??null;if(!$w||$w['organization']!==$org||$w['legacy'])return null;}
            return $fn($w,self::reviewUsers($db,$w,'manage'));
        },false);
    }
    public function automationScopes():array{
        return $this->transaction(function($db){$out=[['org'=>'','id'=>'']];foreach($db['workspaces'] as $w)if(!$w['legacy'])$out[]=['org'=>$w['organization'],'id'=>$w['id']];return $out;},false);
    }
    public function withWorkspace(string $token,string $org,string $id,callable $fn,bool $publishedRead=false):mixed{
        $shared=$token===''&&$publishedRead;
        return $this->transaction(function(&$db,$flush)use($token,$org,$id,$fn,$publishedRead){
            $ctx=self::context($db,$token);$uid=$ctx['user']['id']??'';
            $w=Workspaces::access($db,$uid,$org,$id);
            if(!$w&&$publishedRead)$w=Workspaces::publicAccess($db,$uid,$org,$id);
            if($ctx&&$w&&Permissions::allows($w['role'],'edit')){$ctx['review_users']=self::reviewUsers($db,$db['workspaces'][$id]);$ctx['team_members']=self::reviewUsers($db,$db['workspaces'][$id],'read');}
            // Flush session heartbeat before dispatch; SQL keeps the identity lock through content dispatch.
            $flush();return $fn($ctx,$w);
        },!$shared,$shared);
    }
    /** Root/legacy routes cannot bypass the current workspace or book visibility policy. */
    public function withLegacy(string $token,callable $fn,bool $publishedRead=false):mixed{
        $shared=$token===''&&$publishedRead;
        return $this->transaction(function(&$db,$flush)use($token,$fn){
            $ctx=self::context($db,$token);$u=$ctx['user']??null;$w=Workspaces::legacy($db);$o=$w?$db['organizations'][$w['organization']]:null;
            $role=$o?(($o['archived']||$w['archived'])?'none':Permissions::workspace($o,$w,$u['id']??'')):(($u['owner']??false)?'owner':'none');
            $visible=!$o||(!$o['archived']&&!$w['archived']&&($role!=='none'||(!isset($o['members'][$u['id']??''])&&Visibility::workspace($w)==='public')));
            if($ctx&&Permissions::allows($role,'edit')){$ctx['review_users']=self::reviewUsers($db,$w);$ctx['team_members']=self::reviewUsers($db,$w,'read');}
            $flush();return $fn($ctx,$role,$w,$visible);
        },!$shared,$shared);
    }
    public function withLegacyStudio(string $token,callable $fn):mixed{
        return $this->withLegacy($token,fn($ctx,$role,$w,$visible)=>$fn($ctx,Permissions::allows($role,'edit'),$role));
    }
    public function organizations(string $token):array{return $this->transaction(fn($db)=>Organizations::list($db,self::requireSession($db,$token)['user']));}
    public function organization(string $token,string $id):?array{return $this->transaction(fn($db)=>Organizations::detail($db,self::requireSession($db,$token)['user'],$id));}
    public function createOrganization(string $token,string $name,string $slug):array{return $this->transaction(function(&$db)use($token,$name,$slug){$uid=self::requireSession($db,$token)['user'];if(isset($db['users'][$uid]['oidc']))throw new \DomainException('Federated accounts cannot own organizations.');$o=Organizations::create($db,$uid,$name,$slug);ActivityJournal::record($db,$db['users'][$uid],'organization.create',$o['name'],$o['id'],$o['id']);return $o;});}
    public function mutateOrganization(string $token,string $id,int $revision,string $action,array $fields):array{return $this->transaction(function(&$db)use($token,$id,$revision,$action,$fields){$uid=self::requireSession($db,$token)['user'];$old=$db['organizations'][$id]??null;if($action==='leave'&&($db['users'][$uid]['oidc']['organization']??null)===$id)throw new \DomainException('SSO membership is managed by the identity provider.');if(in_array($action,['role','remove'],true)&&($db['users'][$fields['user']??'']['oidc']['organization']??null)===$id)throw new \DomainException('SSO membership is managed by the identity provider. Disable the account to block access locally.');if($action==='transfer'&&isset($db['users'][$fields['user']??'']['oidc']))throw new \DomainException('Federated accounts cannot own organizations.');$r=Organizations::mutate($db,$uid,$id,$revision,$action,$fields);$o=$db['organizations'][$id]??$old;$target='';$recipients=[];$detail='';
        if(in_array($action,['role','transfer','remove'],true)){$target=$fields['user'];$recipients=[$target];$detail=$db['users'][$target]['username'].($action==='role'?' · '.$fields['role']:'');}
        elseif($action==='invite'){foreach($db['invitations'] as $i)if($i['organization']===$id&&$i['username']===$r['username']){$target=$i['id'];$recipients=$i['user']===null?[]:[$i['user']];$detail=$i['username'];break;}}
        elseif($action==='revoke')$target=$fields['invitation'];
        ActivityJournal::record($db,$db['users'][$uid],'organization.'.$action,$o['name'],$target,$id,'',$recipients,$detail);return $r;});}
    /** Accept and (if invited to a new username) create the account in the same durable transaction. */
    public function acceptInvitation(string $invite,string $session,string $ip,string $name='',string $email='',string $password=''):array{
        return $this->transaction(function(&$db)use($invite,$session,$ip,$name,$email,$password){
            $now=time();self::prune($db,$now);
            if(!self::rate($db,'invite',$ip,hash('sha256',substr($invite,0,128)),$now))return ['status'=>'limited'];
            $i=Organizations::invitation($db,$invite);if(!$i)return ['status'=>'invalid'];
            if($session!==''){$s=self::requireSession($db,$session);$u=$db['users'][$s['user']];}
            else{if($i['user']!==null||self::find($db,$i['username']))return ['status'=>'signin'];
                $u=self::add($db,$i['username'],self::name($name),self::email($email),self::password($password),false);}
            if($u['username']!==$i['username']||($i['user']!==null&&$i['user']!==$u['id']))return ['status'=>'invalid'];
            $id=Organizations::accept($db,$i,$invite,$u);$o=$db['organizations'][$id];ActivityJournal::record($db,$u,'organization.join',$o['name'],$u['id'],$id,'',[$o['owner']]);return ['status'=>'ok','organization'=>$id];
        });
    }
    public function team(string $token,?string $mark=null,?string $csrf=null,int $page=1):?array{
        return $this->transaction(function(&$db)use($token,$mark,$csrf,$page){
            $ctx=self::context($db,$token);if(!$ctx)return null;$uid=$ctx['user']['id'];
            $inbox=ActivityJournal::identityVisible($db,$uid,false);
            if($mark!==null){if(!is_string($csrf)||!hash_equals($ctx['session']['csrf'],$csrf))throw new OrganizationAccessException('Invalid CSRF token');ActivityJournal::mark($db,$uid,$mark,$inbox);return $ctx;}
            $workspaces=Workspaces::list($db,$uid);$legacy=Workspaces::legacy($db);
            if(!$legacy&&$ctx['user']['owner'])array_unshift($workspaces,['id'=>'','name'=>'Legacy documentation','organization_name'=>'Installation','legacy'=>true,'role'=>'owner','archived'=>false,'organization_archived'=>false]);
            $ctx['workspace_count']=count($workspaces);$ctx['workspace_page']=max(1,min(100,$page));$workspaces=array_slice($workspaces,($ctx['workspace_page']-1)*10,10);
            foreach($workspaces as &$w)if(!$w['archived']&&!$w['organization_archived']&&Permissions::allows($w['role'],'edit')){try{$store=new BookStore(Workspaces::directory(dirname($this->dir),$w),$w['legacy']&&$w['id']==='');$w['summary']=TeamView::summary($store->read(),$uid,$w['role']);}catch(\Throwable $e){error_log('Team summary: '.$e->getMessage());$w['unavailable']=true;}}unset($w);
            $ctx['workspaces']=$workspaces;$ctx['organizations']=Organizations::list($db,$uid);$ctx['inbox']=$inbox;$ctx['audit']=ActivityJournal::identityVisible($db,$uid,true);
            return $ctx;
        });
    }
    /** Backup includes organizations and memberships; never live sessions, reset or invitation credentials. */
    /** Authorize and query/export while the identity lock pins current memberships. */
    public function enterpriseAudit(string $token,array $filters,int $limit=50,bool $export=false,string $csrf=''):array{
        return $this->transaction(function(&$db)use($token,$filters,$limit,$export,$csrf){
            try{$s=self::requireSession($db,$token);}catch(\DomainException){throw new OrganizationAccessException('Audit sign-in required.');}$uid=$s['user'];$access=AuditLog::access($db,$uid);
            if(!$access['owner']&&!$access['orgs']&&!$access['workspaces'])throw new OrganizationAccessException('Audit permission required.');
            if($export&&!hash_equals($s['csrf'],$csrf))throw new OrganizationAccessException('Invalid CSRF token.');
            if(!$this->identityConnection||!$this->identityBackend)throw new \DomainException('Enterprise audit requires PostgreSQL.');
            $result=$this->identityBackend->auditFind($this->identityConnection,$access,$filters,$limit);
            if($export)$this->identityBackend->audit($this->identityConnection,[AuditLog::event('audit.export',$db['users'][$uid])]);
            return $result+['csrf'=>$s['csrf']];
        });
    }
    public function auditFailure(string $action,string $outcome='denied'):void{
        if(!getenv('BOOK_PG_DSN'))return;
        $this->transaction(function(&$db)use($action,$outcome){$this->identityBackend->audit($this->identityConnection,[AuditLog::event($action,null,'',$outcome)]);});
    }
    public function operatorAudit(string $action):void{
        if(!getenv('BOOK_PG_DSN'))return;
        $this->transaction(function(&$db)use($action){$this->identityBackend->audit($this->identityConnection,[AuditLog::event($action)]);});
    }
    private static function backupState(array $db):array{return ['schema'=>4,'users'=>$db['users'],'organizations'=>$db['organizations'],'workspaces'=>$db['workspaces'],'activity'=>$db['activity']??[]];}
    public function backup():array{return $this->transaction(fn($db)=>self::backupState($db));}
    /** Operator-only consistent installation snapshot. Lock order is identity -> content, as in HTTP. */
    public function installationSnapshot(callable $fn):mixed{return $this->transaction(fn($db)=>$fn(self::backupState($db)),false);}
    public function restore(array $backup):void{$this->transaction(function(&$db)use($backup){if($this->identityConnection!==null)throw new \DomainException('Use full installation restore into a new directory, then migrate to PostgreSQL.');if($db['users']!==[])throw new \DomainException('Identity already initialized.');if(!in_array($backup['schema']??null,[1,2,3,4],true)||!is_array($backup['users']??null)||$backup['users']===[]||(($backup['schema']??null)!==1&&!is_array($backup['organizations']??null)))throw new \InvalidArgumentException('Invalid identity backup.');$db=self::empty();$db['users']=$backup['users'];$db['organizations']=$backup['schema']!==1?$backup['organizations']:[];$db['workspaces']=$backup['workspaces']??[];$db['activity']=$backup['activity']??[];if($backup['schema']!==4){foreach($db['workspaces'] as &$w)$w['visibility']??=Visibility::workspace($w);unset($w);}self::validate($db);CustomDomains::resetRestored($db);});}
}
