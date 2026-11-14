<?php
declare(strict_types=1);
namespace CajeerBook;

final class WorkspaceController
{
    public function __construct(private readonly IdentityStore $identity,private readonly AuthController $auth){}
    private static function e(string $s):string{return Markdown::e($s);}
    private static function post(string $k):string{return is_string($_POST[$k]??null)?$_POST[$k]:'';}
    private static function h(string $k,string $v):string{return '<input type="hidden" name="'.$k.'" value="'.self::e($v).'">';}
    private static function field(string $k,string $label,string $value=''):string{return '<label>'.$label.'<input required name="'.$k.'" maxlength="120" value="'.self::e($value).'"></label>';}
    public function dispatch(string $method,callable $page):void{
        header('X-Robots-Tag: noindex, nofollow');$ctx=$this->auth->current();if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1><a href="/login">Sign in</a>');return;}
        $token=$this->auth->token();$csrf=$ctx['session']['csrf'];$error='';
        if($method==='POST'){
            if(!hash_equals($csrf,self::post('csrf'))){http_response_code(403);$page('Forbidden','<h1>Invalid CSRF token</h1>');return;}
            try{$action=self::post('action');if(in_array($action,['create','adopt'],true))$this->identity->createWorkspace($token,self::post('organization'),self::post('name'),self::post('slug'),$action==='adopt');
                else{$rev=filter_var(self::post('revision'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($rev===false)throw new \InvalidArgumentException('Invalid revision');$this->identity->mutateWorkspace($token,self::post('id'),$rev,$action,self::post('name'),self::post('slug'));}
                header('Location: /workspaces',true,303);return;
            }catch(OrganizationAccessException $e){http_response_code(403);$error=$e->getMessage();}catch(\DomainException|\InvalidArgumentException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
        }
        $orgs=$this->identity->organizations($token);$items=$this->identity->workspaces($token);$canAdopt=$ctx['user']['owner']&&$this->identity->legacyWorkspaceAvailable($token);
        $form=fn($action,$body,$label)=>'<form class="editor org-form" method="post" action="/workspaces">'.self::h('csrf',$csrf).self::h('action',$action).$body.'<button>'.self::e($label).'</button></form>';
        $html='<div class="auth-panel org-panel"><h1>Workspaces</h1><p><a href="/organizations">Manage organizations</a></p>'.($error!==''?'<p role="alert">'.self::e($error).'</p>':'');
        foreach($items as $w){$prefix=Workspaces::prefix($w);$html.='<section class="org-card"><h2>'.self::e($w['name']).'</h2><p>'.self::e($w['organization_name'].' / '.$w['slug']).($w['legacy']?' · Legacy':'').($w['archived']||$w['organization_archived']?' · Archived':'').'</p>';
            if(!$w['archived']&&!$w['organization_archived']){$html.='<p><a href="'.$prefix.'/">Reader</a>'.($w['owner']?' · <a href="'.$prefix.'/studio">Studio</a> · <a href="'.$prefix.'/studio/books">Books</a>':'').'</p>';}
            if($w['owner']&&!$w['organization_archived']){$hidden=self::h('id',$w['id']).self::h('revision',(string)$w['revision']);if(!$w['archived'])$html.=$form('rename',$hidden.self::field('name','Name',$w['name']).self::field('slug','Slug',$w['slug']),'Save settings');if(!$w['legacy'])$html.=$form($w['archived']?'restore':'archive',$hidden,$w['archived']?'Restore workspace':'Archive workspace');}$html.='</section>';
        }
        foreach($orgs as $o)if($o['role']==='owner'&&!$o['archived']){$html.='<h2>Create workspace in '.self::e($o['name']).'</h2>'.$form('create',self::h('organization',$o['id']).self::field('name','Workspace name').self::field('slug','Slug'),'Create workspace');if($canAdopt)$html.='<p>Adopt legacy content only if this organization should control all existing documentation. Public URLs stay unchanged; future ownership transfers affect legacy Studio.</p>'.$form('adopt',self::h('organization',$o['id']).self::field('name','Legacy workspace name','Legacy documentation').self::field('slug','Slug','legacy'), 'Adopt existing documentation (once)');}
        $page('Workspaces',$html.'</div>');
    }
}
