<?php
declare(strict_types=1);
namespace CajeerBook;

/** Organization state lives inside the identity transaction: no split-brain membership or invitation writes. */
final class Organizations
{
    public const LIMIT = 100;
    public const INVITE_LIMIT = 100;
    public const TTL = 604800;

    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        if (!preg_match('/\A[a-z][a-z0-9-]{1,62}[a-z0-9]\z/D', $s)) throw new \InvalidArgumentException('Slug must be 3–64 letters, digits or hyphens, starting with a letter and ending with a letter or digit.');
        return $s;
    }
    public static function name(string $s): string
    {
        $s = trim($s);
        if ($s === '' || strlen($s) > 120 || !preg_match('//u', $s) || preg_match('/[\x00-\x1f\x7f]/', $s)) throw new \InvalidArgumentException('Invalid organization name.');
        return $s;
    }
    private static function hex(mixed $v, int $n): bool { return is_string($v) && strlen($v)===$n && preg_match('/\A[a-f0-9]+\z/D',$v)===1; }
    public static function validate(array $db): void
    {
        if (!is_array($db['organizations']??null) || !is_array($db['invitations']??null) || count($db['organizations'])>self::LIMIT || count($db['invitations'])>self::LIMIT*self::INVITE_LIMIT) throw new \RuntimeException('Invalid organization state');
        $slugs=[];$counts=[];$targets=[];$ids=[];
        foreach ($db['organizations'] as $id=>$o) {
            if (!self::hex($id,32)||!is_array($o)||($o['id']??null)!==$id||!is_string($o['slug']??null)||self::slug($o['slug'])!==$o['slug']||isset($slugs[$o['slug']])||!is_string($o['name']??null)||self::name($o['name'])!==$o['name']||!is_bool($o['archived']??null)||!is_int($o['revision']??null)||$o['revision']<1||!is_int($o['created']??null)||!is_int($o['updated']??null)||!is_array($o['members']??null)||!self::hex($o['owner']??null,32)) throw new \RuntimeException('Invalid organization');
            $slugs[$o['slug']]=true;
            if (!($db['users'][$o['owner']]['enabled']??false)||!isset($o['members'][$o['owner']])) throw new \RuntimeException('Organization requires an enabled owner');
            foreach ($o['members'] as $uid=>$joined) if (!isset($db['users'][$uid])||!is_int($joined)) throw new \RuntimeException('Invalid organization membership');
        }
        foreach ($db['invitations'] as $hash=>$i) {
            if (!self::hex($hash,64)||!is_array($i)||!self::hex($i['id']??null,32)||isset($ids[$i['id']])||!self::hex($i['organization']??null,32)||!isset($db['organizations'][$i['organization']])||!is_string($i['username']??null)||IdentityStore::username($i['username'])!==$i['username']||!array_key_exists('user',$i)||($i['user']!==null&&(!self::hex($i['user'],32)||($db['users'][$i['user']]['username']??null)!==$i['username']))||!is_int($i['created']??null)||!is_int($i['expires']??null)||$i['expires']!==$i['created']+self::TTL) throw new \RuntimeException('Invalid organization invitation');
            $o=$db['organizations'][$i['organization']];$key=$o['id'].':'.$i['username'];$ids[$i['id']]=true;
            if ($o['archived']||isset($targets[$key])||($i['user']!==null&&isset($o['members'][$i['user']]))) throw new \RuntimeException('Invalid invitation target');
            $targets[$key]=true;$counts[$o['id']]=($counts[$o['id']]??0)+1;if($counts[$o['id']]>self::INVITE_LIMIT)throw new \RuntimeException('Invitation quota exceeded');
        }
    }
    public static function list(array $db,string $uid):array
    {
        $out=[];foreach($db['organizations'] as $o)if(isset($o['members'][$uid]))$out[]=self::summary($o,$uid);
        usort($out,fn($a,$b)=>strcmp($a['slug'],$b['slug']));return $out;
    }
    private static function summary(array $o,string $uid):array {return array_diff_key($o,['members'=>true])+['role'=>$o['owner']===$uid?'owner':'member','member_count'=>count($o['members'])];}
    public static function detail(array $db,string $uid,string $id):?array
    {
        $o=$db['organizations'][$id]??null;if(!$o||!isset($o['members'][$uid]))return null;
        $members=[];foreach($o['members'] as $member=>$joined){$u=$db['users'][$member];$members[]=['id'=>$member,'username'=>$u['username'],'name'=>$u['name'],'enabled'=>$u['enabled'],'joined'=>$joined,'role'=>$member===$o['owner']?'owner':'member'];}
        usort($members,fn($a,$b)=>strcmp($a['username'],$b['username']));$invites=[];
        if($o['owner']===$uid)foreach($db['invitations'] as $i)if($i['organization']===$id)$invites[]=array_diff_key($i,['user'=>true]);
        return self::summary($o,$uid)+['members'=>$members,'invitations'=>$invites];
    }
    private static function uniqueSlug(array $db,string $slug,string $except=''):void{foreach($db['organizations'] as $id=>$o)if($id!==$except&&$o['slug']===$slug)throw new \DomainException('Organization slug already exists.');}
    private static function bump(array &$o):void{$o['revision']++;$o['updated']=time();}
    public static function create(array &$db,string $uid,string $name,string $slug):array
    {
        if(count($db['organizations'])>=self::LIMIT)throw new \DomainException('Organization quota exceeded.');
        $slug=self::slug($slug);$name=self::name($name);self::uniqueSlug($db,$slug);$id=bin2hex(random_bytes(16));$now=time();
        $o=['id'=>$id,'slug'=>$slug,'name'=>$name,'owner'=>$uid,'members'=>[$uid=>$now],'archived'=>false,'revision'=>1,'created'=>$now,'updated'=>$now];$db['organizations'][$id]=$o;return self::summary($o,$uid);
    }
    /** All management, including authorization and optimistic locking, happens under the identity lock. */
    public static function mutate(array &$db,string $uid,string $id,int $revision,string $action,array $p):array
    {
        $o=$db['organizations'][$id]??null;
        if(!$o||!isset($o['members'][$uid]))throw new OrganizationAccessException('Organization not found.');
        if($action!=='leave'&&$o['owner']!==$uid)throw new OrganizationAccessException('Organization owner permission required.');
        if($o['revision']!==$revision)throw new \DomainException('Organization changed. Reload and retry.');
        if($o['archived']&&!in_array($action,['restore','delete','leave','transfer'],true))throw new \DomainException('Organization is archived.');
        $target=$p['user']??'';$result=[];
        switch($action){
            case 'rename':$o['name']=self::name($p['name']??'');$slug=self::slug($p['slug']??'');self::uniqueSlug($db,$slug,$id);$o['slug']=$slug;break;
            case 'archive':$o['archived']=true;self::clearInvites($db,$id);break;
            case 'restore':if(!$o['archived'])throw new \DomainException('Organization is not archived.');$o['archived']=false;break;
            case 'delete':if(!$o['archived']||($p['confirm']??'')!==$o['slug'])throw new \DomainException('Archive first and confirm the organization slug.');self::clearInvites($db,$id);unset($db['organizations'][$id]);return ['deleted'=>true];
            case 'leave':if($o['owner']===$uid)throw new \DomainException('Transfer ownership before leaving.');unset($o['members'][$uid]);break;
            case 'remove':if($target===$o['owner']||!isset($o['members'][$target]))throw new \DomainException('Cannot remove this member.');unset($o['members'][$target]);break;
            case 'transfer':if($target===$uid||!isset($o['members'][$target])||!($db['users'][$target]['enabled']??false))throw new \DomainException('Select another enabled member.');$o['owner']=$target;break;
            case 'invite':
                $username=IdentityStore::username($p['username']??'');$targetId=null;
                foreach($db['users'] as $u)if($u['username']===$username){if(!$u['enabled']||isset($o['members'][$u['id']]))throw new \DomainException('Account is disabled or already a member.');$targetId=$u['id'];break;}
                $count=0;foreach($db['invitations'] as $hash=>$i)if($i['organization']===$id){if($i['expires']<=time()||$i['username']===$username)unset($db['invitations'][$hash]);else $count++;}
                if($count>=self::INVITE_LIMIT)throw new \DomainException('Invitation quota exceeded.');
                $token=bin2hex(random_bytes(32));$now=time();$i=['id'=>bin2hex(random_bytes(16)),'organization'=>$id,'username'=>$username,'user'=>$targetId,'created'=>$now,'expires'=>$now+self::TTL];$db['invitations'][hash('sha256',$token)]=$i;$result=['token'=>$token,'username'=>$username];break;
            case 'revoke':$found=false;foreach($db['invitations'] as $hash=>$i)if($i['organization']===$id&&$i['id']===($p['invitation']??'')){unset($db['invitations'][$hash]);$found=true;break;}if(!$found)throw new \DomainException('Invitation unavailable.');break;
            default:throw new \InvalidArgumentException('Unknown organization action.');
        }
        self::bump($o);$db['organizations'][$id]=$o;return $result+['organization'=>$id];
    }
    private static function clearInvites(array &$db,string $id):void{foreach($db['invitations'] as $hash=>$i)if($i['organization']===$id)unset($db['invitations'][$hash]);}
    public static function invitation(array $db,string $token):?array
    {
        if(!self::hex($token,64))return null;$i=$db['invitations'][hash('sha256',$token)]??null;
        if(!$i||$i['expires']<=time()||($db['organizations'][$i['organization']]['archived']??true))return null;return $i;
    }
    public static function accept(array &$db,array $i,string $token,array $u):string
    {
        $o=$db['organizations'][$i['organization']];
        if(!$u['enabled']||$u['username']!==$i['username']||($i['user']!==null&&$i['user']!==$u['id'])||isset($o['members'][$u['id']]))throw new \DomainException('Invitation unavailable for this account.');
        $o['members'][$u['id']]=time();self::bump($o);$db['organizations'][$o['id']]=$o;unset($db['invitations'][hash('sha256',$token)]);return $o['id'];
    }
}
