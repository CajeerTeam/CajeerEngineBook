<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** Workspace registry shares the identity lock; content stores have separate locks and quotas. */
final class Workspaces
{
    public const LIMIT=100;
    public static function id(string $id):bool{return preg_match('/\A[a-f0-9]{32}\z/D',$id)===1;}
    public static function validate(array $db):void{
        $items=$db['workspaces']??[];
        if(!is_array($items)||count($items)>self::LIMIT)throw new \RuntimeException('Invalid workspace registry');
        $slugs=[];$legacy=false;
        foreach($items as $id=>$w){
            if(!is_string($id)||!self::id($id)||!is_array($w)||($w['id']??null)!==$id||!isset($db['organizations'][$w['organization']??''])||!is_string($w['slug']??null)||Organizations::slug($w['slug'])!==$w['slug']||!is_string($w['name']??null)||Organizations::name($w['name'])!==$w['name']||!is_bool($w['legacy']??null)||!is_bool($w['archived']??null)||!is_int($w['revision']??null)||$w['revision']<1||!is_int($w['created']??null))throw new \RuntimeException('Invalid workspace');
            $key=$w['organization'].':'.$w['slug'];if(isset($slugs[$key])||($w['legacy']&&$legacy))throw new \RuntimeException('Duplicate workspace');$slugs[$key]=true;$legacy=$legacy||$w['legacy'];
            if(!is_array($w['roles']??[]))throw new \RuntimeException('Invalid workspace roles');
            $o=$db['organizations'][$w['organization']];foreach($w['roles']??[] as $uid=>$role){$r=Permissions::organization($o,$uid);if(!in_array($r,Permissions::MEMBER_ROLES,true)||!in_array($role,['none','viewer','editor','admin'],true)||Permissions::workspace($o,$w,$uid)!==$role)throw new \RuntimeException('Invalid workspace assignment');}
            if(!in_array($w['visibility']??null,Visibility::WORKSPACE,true))throw new \RuntimeException('Invalid workspace visibility');
            if($w['legacy']&&$w['archived'])throw new \RuntimeException('Legacy workspace cannot be archived');
        }
    }
    /** Do not silently omit orphan stores, which may contain otherwise unrecoverable data. */
    public static function assertRegistryDirectories(string $root,array $identity):void
    {
        $dir=Files::path($root,'workspaces');if(!file_exists($dir))return;
        if(!is_dir($dir))throw new \RuntimeException('Invalid workspace directory');$expected=[];
        foreach($identity['workspaces'] as $w)if(!$w['legacy'])$expected[$w['organization']][$w['id']]=true;
        foreach(new \DirectoryIterator($dir) as $org){
            if($org->isDot())continue;if($org->isLink()||!$org->isDir()||!Workspaces::id($org->getFilename()))throw new \RuntimeException('Unexpected workspace directory entry');
            foreach(new \DirectoryIterator($org->getPathname()) as $w){if($w->isDot())continue;if($w->isLink()||!$w->isDir()||!isset($expected[$org->getFilename()][$w->getFilename()]))throw new \RuntimeException('Unregistered workspace directory; inspect and preserve it before backup');}
        }
    }
    public static function legacy(array $db):?array{foreach($db['workspaces']??[] as $w)if($w['legacy'])return $w;return null;}
    public static function prefix(array $w):string{return $w['legacy']?'':'/w/'.$w['organization'].'/'.$w['id'];}
    public static function directory(string $root,array $w):string{return $w['legacy']?$root:Files::path($root,'workspaces/'.$w['organization'].'/'.$w['id']);}
    public static function list(array $db,string $uid):array{
        $out=[];foreach($db['workspaces']??[] as $w){$o=$db['organizations'][$w['organization']];$role=Permissions::workspace($o,$w,$uid);if($role!=='none'){$entry=array_diff_key($w,['roles'=>true])+['owner'=>$o['owner']===$uid,'role'=>$role,'organization_name'=>$o['name'],'organization_archived'=>$o['archived']];if(Permissions::allows($role,'manage')){$entry['assignments']=[];foreach($o['members'] as $member=>$joined){$r=Permissions::organization($o,$member);if(Permissions::canManageMember($role,$r)&&$member!==$uid)$entry['assignments'][]=['id'=>$member,'username'=>$db['users'][$member]['username'],'role'=>$r,'assigned'=>$w['roles'][$member]??'inherit'];}}$out[]=$entry;}}
        usort($out,fn($a,$b)=>[$a['organization_name'],$a['slug']]<=>[$b['organization_name'],$b['slug']]);return $out;
    }
    public static function create(array &$db,string $uid,string $org,string $name,string $slug,bool $legacy=false):array{
        $o=$db['organizations'][$org]??null;if(!$o||!Permissions::allows(Permissions::organization($o,$uid),'manage')||$o['archived'])throw new OrganizationAccessException('Workspace management permission required.');
        if($legacy&&($o['owner']!==$uid||!($db['users'][$uid]['owner']??false)||self::legacy($db)))throw new OrganizationAccessException('Legacy documentation already assigned or installation owner required.');
        $db['workspaces']??=[];if(count($db['workspaces'])>=self::LIMIT)throw new \DomainException('Workspace quota exceeded.');
        $slug=Organizations::slug($slug);$name=Organizations::name($name);self::unique($db,$org,$slug);
        $id=bin2hex(random_bytes(16));$w=['id'=>$id,'organization'=>$org,'name'=>$name,'slug'=>$slug,'legacy'=>$legacy,'visibility'=>$legacy?'public':'private','archived'=>false,'revision'=>1,'created'=>time()];$db['workspaces'][$id]=$w;return $w;
    }
    private static function unique(array $db,string $org,string $slug,string $except=''):void{foreach($db['workspaces']??[] as $id=>$w)if($id!==$except&&$w['organization']===$org&&$w['slug']===$slug)throw new \DomainException('Workspace slug already exists in organization.');}
    public static function mutate(array &$db,string $uid,string $id,int $rev,string $action,string $name,string $slug):void{
        $w=$db['workspaces'][$id]??null;$o=$w?$db['organizations'][$w['organization']]:null;
        if(!$o||!Permissions::allows(Permissions::workspace($o,$w,$uid),'manage')||$o['archived'])throw new OrganizationAccessException('Workspace management permission required.');
        if($w['revision']!==$rev)throw new \DomainException('Workspace changed. Reload.');
        if($action==='rename'&&!$w['archived']){$w['name']=Organizations::name($name);$slug=Organizations::slug($slug);self::unique($db,$w['organization'],$slug,$id);$w['slug']=$slug;}
        elseif($action==='visibility'&&!$w['archived']){if(!in_array($name,Visibility::WORKSPACE,true))throw new \InvalidArgumentException('Invalid visibility');$w['visibility']=$name;}
        elseif($action==='archive'&&!$w['legacy']&&!$w['archived'])$w['archived']=true;
        elseif($action==='restore'&&$w['archived'])$w['archived']=false;
        else throw new \InvalidArgumentException('Invalid workspace operation.');
        $w['revision']++;$db['workspaces'][$id]=$w;
    }
    /** Public Reader only. Explicitly denied signed-in members retain the denial. */
    public static function publicAccess(array $db,string $uid,string $org,string $id):?array{
        $w=$db['workspaces'][$id]??null;$o=$db['organizations'][$org]??null;
        if(!$w||!$o||$w['organization']!==$org||$w['legacy']||$w['archived']||$o['archived']||Visibility::workspace($w)!=='public'||isset($o['members'][$uid]))return null;
        return array_diff_key($w,['roles'=>true])+['owner'=>false,'role'=>'none'];
    }
    /** Route identity never comes from cookies, request body, or a mutable slug. */
    public static function access(array $db,string $uid,string $org,string $id):?array{
        $w=$db['workspaces'][$id]??null;$o=$db['organizations'][$org]??null;
        if(!$w||!$o||$w['organization']!==$org||$w['legacy']||$w['archived']||$o['archived']||!isset($o['members'][$uid]))return null;
        $role=Permissions::workspace($o,$w,$uid);return $role==='none'?null:array_diff_key($w,['roles'=>true])+['owner'=>$o['owner']===$uid,'role'=>$role];
    }
}
