<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;

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
    public function __construct(string $dataDir){$this->dir=rtrim($dataDir,'/').'/identity';}
    private static function empty():array{return ['schema'=>1,'users'=>[],'sessions'=>[],'resets'=>[],'attempts'=>[]];}
    private function transaction(callable $fn):mixed
    {
        Files::dir($this->dir);$lock=Files::path($this->dir,'identity.lock');$path=Files::path($this->dir,'identity.json');
        $h=fopen($lock,'c+b');if(!$h)throw new \RuntimeException('Identity lock unavailable');
        try{
            if(!chmod($lock,0600)||!flock($h,LOCK_EX))throw new \RuntimeException('Identity lock unavailable');
            $exists=file_exists($path);if($exists&&(!is_file($path)||filesize($path)>self::MAX_BYTES))throw new \RuntimeException('Invalid identity store');
            $db=$exists?Files::read($path):self::empty();self::validate($db);$before=$db;
            $result=$fn($db);
            if($db!==$before){self::validate($db);$json=json_encode($db,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(strlen($json)+1>self::MAX_BYTES)throw new \RuntimeException('Identity store quota exceeded');Files::write($path,$json."\n");}
            return $result;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
    private static function hex(string $s,int $length):bool{return strlen($s)===$length&&ctype_xdigit($s)&&strtolower($s)===$s;}
    public static function username(string $s):string{$s=strtolower(trim($s));if(!preg_match('/\A[a-z][a-z0-9_.-]{2,39}\z/D',$s))throw new \InvalidArgumentException('Username must be 3–40 characters (letters, digits, dot, underscore, hyphen).');return $s;}
    private static function name(string $s):string{$s=trim($s);if($s===''||strlen($s)>120||!preg_match('//u',$s)||preg_match('/[\x00-\x1f\x7f]/',$s))throw new \InvalidArgumentException('Invalid display name.');return $s;}
    private static function email(string $s,bool $optional=false):string{$s=strtolower(trim($s));if($optional&&$s==='')return '';if(strlen($s)>254||!filter_var($s,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid email address.');return $s;}
    private static function password(string $s):string{if(strlen($s)<12||strlen($s)>72||str_contains($s,"\0"))throw new \InvalidArgumentException('Password must be 12–72 bytes.');return password_hash($s,PASSWORD_DEFAULT);}
    private static function validHash(string $hash):bool{return strlen($hash)<=255&&password_get_info($hash)['algoName']!=='unknown';}
    private static function validate(array $db):void
    {
        if(($db['schema']??null)!==1)throw new \RuntimeException('Unsupported identity schema');
        foreach(['users','sessions','resets','attempts'] as $k)if(!is_array($db[$k]??null))throw new \RuntimeException('Invalid identity state');
        if(count($db['users'])>self::MAX_USERS||count($db['sessions'])>self::MAX_USERS*10||count($db['resets'])>self::MAX_USERS||count($db['attempts'])>4096)throw new \RuntimeException('Invalid identity counts');
        $names=[];$emails=[];$owners=0;
        foreach($db['users'] as $id=>$u){
            if(!is_string($id)||!self::hex($id,32)||!is_array($u)||($u['id']??null)!==$id||!is_string($u['username']??null)||self::username($u['username'])!==$u['username']||!is_string($u['name']??null)||self::name($u['name'])!==$u['name']||!is_string($u['email']??null)||self::email($u['email'],true)!==$u['email']||!is_string($u['hash']??null)||!self::validHash($u['hash'])||!is_bool($u['owner']??null)||!is_bool($u['enabled']??null)||!is_int($u['created']??null))throw new \RuntimeException('Invalid identity user');
            if(isset($names[$u['username']])||($u['email']!==''&&isset($emails[$u['email']])))throw new \RuntimeException('Duplicate identity');$names[$u['username']]=true;$emails[$u['email']]=true;
            if($u['owner']){if(!$u['enabled'])throw new \RuntimeException('Disabled owner');$owners++;}
        }
        if($db['users']!==[]&&$owners!==1)throw new \RuntimeException('Identity owner invariant failed');
        foreach($db['sessions'] as $key=>$s)if(!is_string($key)||!self::hex($key,64)||!is_array($s)||!isset($db['users'][$s['user']??''])||!is_string($s['id']??null)||!self::hex($s['id'],32)||!is_string($s['csrf']??null)||!self::hex($s['csrf'],64)||!is_int($s['created']??null)||!is_int($s['seen']??null)||!is_int($s['expires']??null))throw new \RuntimeException('Invalid identity session');
        foreach($db['resets'] as $key=>$r)if(!is_string($key)||!self::hex($key,64)||!is_array($r)||!isset($db['users'][$r['user']??''])||!is_int($r['expires']??null))throw new \RuntimeException('Invalid reset record');
        foreach($db['attempts'] as $k=>$a)if(!is_string($k)||!self::hex($k,64)||!is_array($a)||!is_int($a['count']??null)||!is_int($a['until']??null)||$a['count']<1)throw new \RuntimeException('Invalid rate limit');
    }
    private static function safe(array $u):array{return array_intersect_key($u,array_flip(['id','username','name','email','owner','enabled','created']));}
    private static function find(array $db,string $username):?array{foreach($db['users'] as $u)if($u['username']===$username)return $u;return null;}
    private static function prune(array &$db,int $now):void{
        foreach($db['sessions'] as $k=>$s)if($s['expires']<=$now||$s['seen']<=($now-self::IDLE)||!$db['users'][$s['user']]['enabled'])unset($db['sessions'][$k]);
        foreach($db['resets'] as $k=>$r)if($r['expires']<=$now)unset($db['resets'][$k]);
        foreach($db['attempts'] as $k=>$a)if($a['until']<=$now)unset($db['attempts'][$k]);
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
        if(!$s||$s['expires']<=$now||$s['seen']<=$now-self::IDLE||!($db['users'][$s['user']]['enabled']??false))throw new \DomainException('Session expired. Sign in again.');return $s;
    }
    private static function revoke(array &$db,string $id):void{foreach($db['sessions'] as $k=>$s)if($s['user']===$id)unset($db['sessions'][$k]);foreach($db['resets'] as $k=>$r)if($r['user']===$id)unset($db['resets'][$k]);}
    public function checkReady():void{$this->transaction(function($db){$probe=$this->dir.'/.ready-'.bin2hex(random_bytes(12));try{Files::write($probe,'ready');}finally{if(is_file($probe))Files::remove($probe);}});}
    public function initialized():bool{return $this->transaction(fn($db)=>$db['users']!==[]);}
    public function initialize(string $username,string $name,string $email,string $password):array{return $this->transaction(function(&$db)use($username,$name,$email,$password){if($db['users']!==[])throw new \DomainException('Identity already initialized.');return self::safe(self::add($db,self::username($username),self::name($name),self::email($email),self::password($password),true));});}
    public function login(string $username,string $password,string $ip,string $legacyHash=''):array{
        $username=strtolower(trim(substr($username,0,100)));$tooLong=strlen($password)>72;$password=$tooLong?'':$password;
        return $this->transaction(function(&$db)use($username,$password,$ip,$legacyHash,$tooLong){$now=time();self::prune($db,$now);if(!self::rate($db,'login',$ip,$username,$now))return ['status'=>'limited'];
            $u=self::find($db,$username);$legacy=$db['users']===[]&&$username==='admin'&&self::validHash($legacyHash);$hash=$u['hash']??($legacy?$legacyHash:self::DUMMY);
            $ok=!$tooLong&&!str_contains($password,"\0")&&password_verify($password,$hash);
            if(!$ok||(!$u&&!$legacy)||($u&&!$u['enabled']))return ['status'=>'invalid'];
            if($legacy)$u=self::add($db,'admin','Administrator','',$legacyHash,true);
            if(strlen($password)<=72&&password_needs_rehash($u['hash'],PASSWORD_DEFAULT)){$u['hash']=password_hash($password,PASSWORD_DEFAULT);$db['users'][$u['id']]=$u;}
            unset($db['attempts'][hash('sha256','login:account:'.$username)]);return ['status'=>'ok']+self::session($db,$u,$now);
        });
    }
    public function current(string $token):?array{
        if(!self::hex($token,64))return null;
        return $this->transaction(function(&$db)use($token){$now=time();self::prune($db,$now);$key=hash('sha256',$token);$s=$db['sessions'][$key]??null;if(!$s)return null;if($s['seen']<=$now-300){$s['seen']=$now;$db['sessions'][$key]=$s;}return ['session'=>$s,'user'=>self::safe($db['users'][$s['user']])];});
    }
    public function logout(string $token):void{$this->transaction(function(&$db)use($token){unset($db['sessions'][hash('sha256',$token)]);});}
    public function sessions(string $token):array{return $this->transaction(function(&$db)use($token){self::prune($db,time());$s=self::requireSession($db,$token);return array_values(array_map(fn($r)=>array_diff_key($r,['csrf'=>true]),array_filter($db['sessions'],fn($r)=>$r['user']===$s['user'])));});}
    public function revokeSession(string $token,string $id):void{$this->transaction(function(&$db)use($token,$id){$s=self::requireSession($db,$token);foreach($db['sessions'] as $k=>$r)if($r['user']===$s['user']&&($id==='all'||$r['id']===$id))unset($db['sessions'][$k]);});}
    public function profile(string $token,string $name,string $email):void{$this->transaction(function(&$db)use($token,$name,$email){$s=self::requireSession($db,$token);$name=self::name($name);$email=self::email($email);foreach($db['users'] as $id=>$u)if($id!==$s['user']&&$u['email']===$email)throw new \DomainException('Email already exists.');$db['users'][$s['user']]['name']=$name;$db['users'][$s['user']]['email']=$email;});}
    public function changePassword(string $token,string $old,string $new,string $ip):array{return $this->transaction(function(&$db)use($token,$old,$new,$ip){$now=time();self::prune($db,$now);$s=self::requireSession($db,$token);$u=$db['users'][$s['user']];if(!self::rate($db,'password',$ip,$u['id'],$now))return ['status'=>'limited'];if(strlen($old)>72||str_contains($old,"\0")||!password_verify($old,$u['hash']))return ['status'=>'invalid'];$u['hash']=self::password($new);$db['users'][$u['id']]=$u;self::revoke($db,$u['id']);return ['status'=>'ok']+self::session($db,$u,$now);});}
    private static function owner(array $db,string $token):void{$s=self::requireSession($db,$token);if(!$db['users'][$s['user']]['owner'])throw new \DomainException('Owner permission required.');}
    public function users(string $token):array{return $this->transaction(function(&$db)use($token){self::owner($db,$token);return array_values(array_map(self::safe(...),$db['users']));});}
    public function createUser(string $token,string $username,string $name,string $email,string $password):array{return $this->transaction(function(&$db)use($token,$username,$name,$email,$password){self::owner($db,$token);return self::safe(self::add($db,self::username($username),self::name($name),self::email($email),self::password($password),false));});}
    public function setEnabled(string $token,string $id,bool $enabled):void{$this->transaction(function(&$db)use($token,$id,$enabled){self::owner($db,$token);$u=$db['users'][$id]??null;if(!$u||$u['owner'])throw new \DomainException('Cannot change this account.');$db['users'][$id]['enabled']=$enabled;if(!$enabled)self::revoke($db,$id);});}
    /** Trusted operator only; token never stored in plaintext. */
    public function issueReset(string $username):string{return $this->transaction(function(&$db)use($username){$u=self::find($db,self::username($username));if(!$u||!$u['enabled'])throw new \DomainException('Account unavailable.');self::prune($db,time());foreach($db['resets'] as $k=>$r)if($r['user']===$u['id'])unset($db['resets'][$k]);$token=bin2hex(random_bytes(32));$db['resets'][hash('sha256',$token)]=['user'=>$u['id'],'expires'=>time()+self::RESET_TTL];return $token;});}
    public function reset(string $token,string $password,string $ip):string{return $this->transaction(function(&$db)use($token,$password,$ip){$now=time();self::prune($db,$now);$key=hash('sha256',substr($token,0,128));if(!self::rate($db,'reset',$ip,$key,$now))return 'limited';$r=self::hex($token,64)?($db['resets'][$key]??null):null;if(!$r||!$db['users'][$r['user']]['enabled'])return 'invalid';$db['users'][$r['user']]['hash']=self::password($password);self::revoke($db,$r['user']);return 'ok';});}
    /** Backup contains users only; restoring never reactivates sessions or reset tokens. */
    public function backup():array{return $this->transaction(fn($db)=>['schema'=>1,'users'=>$db['users']]);}
    public function restore(array $backup):void{$this->transaction(function(&$db)use($backup){if($db['users']!==[])throw new \DomainException('Identity already initialized.');if(($backup['schema']??null)!==1||!is_array($backup['users']??null)||$backup['users']===[])throw new \InvalidArgumentException('Invalid identity backup.');$db=self::empty();$db['users']=$backup['users'];self::validate($db);});}
}
