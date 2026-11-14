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
            if($w['legacy']&&$w['archived'])throw new \RuntimeException('Legacy workspace cannot be archived');
        }
    }
    public static function legacy(array $db):?array{foreach($db['workspaces']??[] as $w)if($w['legacy'])return $w;return null;}
    public static function prefix(array $w):string{return $w['legacy']?'':'/w/'.$w['organization'].'/'.$w['id'];}
    public static function directory(string $root,array $w):string{return $w['legacy']?$root:Files::path($root,'workspaces/'.$w['organization'].'/'.$w['id']);}
    public static function list(array $db,string $uid):array{
        $out=[];foreach($db['workspaces']??[] as $w){$o=$db['organizations'][$w['organization']];if(isset($o['members'][$uid]))$out[]=$w+['owner'=>$o['owner']===$uid,'organization_name'=>$o['name'],'organization_archived'=>$o['archived']];}
        usort($out,fn($a,$b)=>[$a['organization_name'],$a['slug']]<=>[$b['organization_name'],$b['slug']]);return $out;
    }
    public static function create(array &$db,string $uid,string $org,string $name,string $slug,bool $legacy=false):array{
        $o=$db['organizations'][$org]??null;if(!$o||$o['owner']!==$uid||$o['archived'])throw new OrganizationAccessException('Active organization owner required.');
        if($legacy&&(!($db['users'][$uid]['owner']??false)||self::legacy($db)))throw new OrganizationAccessException('Legacy documentation already assigned or installation owner required.');
        $db['workspaces']??=[];if(count($db['workspaces'])>=self::LIMIT)throw new \DomainException('Workspace quota exceeded.');
        $slug=Organizations::slug($slug);$name=Organizations::name($name);self::unique($db,$org,$slug);
        $id=bin2hex(random_bytes(16));$w=['id'=>$id,'organization'=>$org,'name'=>$name,'slug'=>$slug,'legacy'=>$legacy,'archived'=>false,'revision'=>1,'created'=>time()];$db['workspaces'][$id]=$w;return $w;
    }
    private static function unique(array $db,string $org,string $slug,string $except=''):void{foreach($db['workspaces']??[] as $id=>$w)if($id!==$except&&$w['organization']===$org&&$w['slug']===$slug)throw new \DomainException('Workspace slug already exists in organization.');}
    public static function mutate(array &$db,string $uid,string $id,int $rev,string $action,string $name,string $slug):void{
        $w=$db['workspaces'][$id]??null;$o=$w?$db['organizations'][$w['organization']]:null;
        if(!$o||$o['owner']!==$uid||$o['archived'])throw new OrganizationAccessException('Active organization owner required.');
        if($w['revision']!==$rev)throw new \DomainException('Workspace changed. Reload.');
        if($action==='rename'&&!$w['archived']){$w['name']=Organizations::name($name);$slug=Organizations::slug($slug);self::unique($db,$w['organization'],$slug,$id);$w['slug']=$slug;}
        elseif($action==='archive'&&!$w['legacy']&&!$w['archived'])$w['archived']=true;
        elseif($action==='restore'&&$w['archived'])$w['archived']=false;
        else throw new \InvalidArgumentException('Invalid workspace operation.');
        $w['revision']++;$db['workspaces'][$id]=$w;
    }
    /** Route identity never comes from cookies, request body, or a mutable slug. */
    public static function access(array $db,string $uid,string $org,string $id):?array{
        $w=$db['workspaces'][$id]??null;$o=$db['organizations'][$org]??null;
        if(!$w||!$o||$w['organization']!==$org||$w['legacy']||$w['archived']||$o['archived']||!isset($o['members'][$uid]))return null;
        return $w+['owner'=>$o['owner']===$uid];
    }
}
