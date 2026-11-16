<?php
declare(strict_types=1);
namespace CajeerBook;

/** Single role policy. Organization roles are ceilings; workspace grants can narrow authoring/read access. */
final class Permissions
{
    public const ROLES=['owner','admin','editor','viewer'];
    public const MEMBER_ROLES=['admin','editor','viewer'];
    private const RANK=['none'=>0,'viewer'=>1,'editor'=>2,'admin'=>3,'owner'=>4];
    public static function organization(array $o,string $uid):string {
        if(!isset($o['members'][$uid]))return 'none';
        return $o['owner']===$uid?'owner':($o['roles'][$uid]??'viewer');
    }
    public static function workspace(array $o,array $w,string $uid):string {
        $role=self::organization($o,$uid);
        if($role==='none'||$role==='owner')return $role;
        $assigned=$w['roles'][$uid]??$role;
        return self::RANK[$assigned]<self::RANK[$role]?$assigned:$role;
    }
    public static function allows(string $role,string $permission):bool {
        $minimum=match($permission){
            'read'=>1,'edit'=>2,'publish','delete','export','manage'=>3,'ownership'=>4,default=>PHP_INT_MAX,
        };
        return (self::RANK[$role]??0)>=$minimum;
    }
    public static function canManageMember(string $actor,string $target):bool {
        return $target!=='owner'&&($actor==='owner'||($actor==='admin'&&in_array($target,['editor','viewer'],true)));
    }
    public static function assignOrganization(array &$o,string $actor,string $target,string $role):void {
        $a=self::organization($o,$actor);$t=self::organization($o,$target);
        if($actor===$target||$t==='none'||!self::canManageMember($a,$t)||!in_array($role,self::MEMBER_ROLES,true)||($role==='admin'&&$a!=='owner'))throw new OrganizationAccessException('Role assignment denied.');
        $o['roles'][$target]=$role;
    }
    public static function assignWorkspace(array $o,array &$w,string $actor,string $target,string $role):void {
        $a=self::workspace($o,$w,$actor);$t=self::organization($o,$target);
        if($actor===$target||$t==='none'||!self::canManageMember($a,$t)||!in_array($role,['inherit','none','viewer','editor','admin'],true)||($role!=='inherit'&&self::RANK[$role]>self::RANK[$t]))throw new OrganizationAccessException('Workspace role assignment denied.');
        if($role==='inherit')unset($w['roles'][$target]);else $w['roles'][$target]=$role;
    }
    /** Deny by default: enumerate every Studio operation, not just UI buttons. */
    public static function studio(string $role,string $path,string $method,string $action):bool {
        if(!self::allows($role,'edit'))return false;
        if(in_array($method,['GET','HEAD'],true)){
            if(in_array($path,['/studio/automation','/studio/ci','/studio/search'],true))return self::allows($role,'manage');
            if(in_array($path,['/studio/export','/studio/media/backup','/studio/static-export'],true))return self::allows($role,'export');
            return in_array($path,['/studio','/studio/translations','/studio/books','/studio/tree','/studio/media','/studio/media/picker','/studio/editor/render','/studio/history','/studio/reviews','/studio/team','/studio/import','/studio/openapi','/studio/github','/studio/git'],true)||str_starts_with($path,'/studio/media/file/');
        }
        if($method!=='POST')return false;
        $permission=match($path){
            '/studio'=>match($action){'save','copy'=>'edit','publish','unpublish'=>'publish','delete'=>'delete',default=>''},
            '/studio/translations'=>in_array($action,['create','review-source','set-language'],true)?'edit':'',
            '/studio/tree'=>match($action){'move','duplicate'=>'edit','publish'=>'publish',default=>''},
            '/studio/media'=>match($action){'upload','update'=>'edit','delete'=>'delete',default=>''},
            '/studio/books'=>match($action){'create'=>'edit','visibility'=>'manage',default=>''},
            '/studio/editor/render'=>'edit',
            '/studio/github','/studio/git'=>match($action){'configure','rotate','disconnect'=>'manage','preview','apply','propose'=>'edit',default=>''},
            '/studio/openapi'=>in_array($action,['preview','apply','git-preview','restore-preview'],true)?'edit':'',
            '/studio/import'=>in_array($action,['preview','apply'],true)?'edit':'',
            '/studio/history'=>$action==='restore'?'edit':'',
            '/studio/team'=>$action==='read'?'edit':'',
            '/studio/static-export'=>$action==='download'?'export':'',
            '/studio/ci'=>in_array($action,['issue','rotate','revoke','build','approve','publish','cancel','prune','rollback'],true)?'manage':'',
            '/studio/automation'=>in_array($action,['configure','rotate','retire','retry'],true)?'manage':'',
            '/studio/reviews'=>match($action){'submit','comment','cancel'=>'edit','approve','changes','reject','publish'=>'publish','policy','prune'=>'ownership',default=>''},default=>'',
        };
        return self::allows($role,$permission);
    }
}
