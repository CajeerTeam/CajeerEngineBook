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
                else{$rev=filter_var(self::post('revision'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($rev===false)throw new \InvalidArgumentException('Invalid revision');if($action==='role')$this->identity->assignWorkspaceRole($token,self::post('id'),$rev,self::post('user'),self::post('role'));else $this->identity->mutateWorkspace($token,self::post('id'),$rev,$action,$action==='visibility'?self::post('visibility'):self::post('name'),self::post('slug'));}
                header('Location: /workspaces',true,303);return;
            }catch(OrganizationAccessException $e){http_response_code(403);$error=$e->getMessage();}catch(\DomainException|\InvalidArgumentException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
        }
        $orgs=$this->identity->organizations($token);$items=$this->identity->workspaces($token);$canAdopt=$ctx['user']['owner']&&$this->identity->legacyWorkspaceAvailable($token);
        $form=fn($action,$body,$label)=>'<form class="editor org-form" method="post" action="/workspaces">'.self::h('csrf',$csrf).self::h('action',$action).$body.'<button'.($action==='visibility'?' data-confirm="Change workspace visibility? Public mode exposes published non-private books and referenced files."':'').'>'.self::e($label).'</button></form>';
        $html='<div class="auth-panel org-panel"><h1>Workspaces</h1><p><a href="/team">Team Studio</a> · <a href="/organizations">Manage organizations</a></p>'.($error!==''?'<p role="alert">'.self::e($error).'</p>':'');
        foreach($items as $w){$prefix=Workspaces::prefix($w);$html.='<section class="org-card"><h2>'.self::e($w['name']).'</h2><p>'.self::e($w['organization_name'].' / '.$w['slug'].' · '.ucfirst($w['role'])).($w['legacy']?' · Legacy':'').' · '.ucfirst(Visibility::workspace($w)).($w['archived']||$w['organization_archived']?' · Archived':'').'</p>';
            if(!$w['archived']&&!$w['organization_archived']){$html.='<p><a href="'.$prefix.'/">Reader</a>'.(Permissions::allows($w['role'],'edit')?' · <a href="'.$prefix.'/studio">Studio</a> · <a href="'.$prefix.'/studio/team">Team dashboard</a> · <a href="'.$prefix.'/studio/books">Books</a>':'').'</p>';}
            if(Permissions::allows($w['role'],'manage')&&!$w['organization_archived']){$hidden=self::h('id',$w['id']).self::h('revision',(string)$w['revision']);if(!$w['archived'])$html.=$form('rename',$hidden.self::field('name','Name',$w['name']).self::field('slug','Slug',$w['slug']),'Save settings');if(!$w['archived']){$options='';foreach(Visibility::WORKSPACE as $v)$options.='<option value="'.$v.'"'.(Visibility::workspace($w)===$v?' selected':'').'>'.ucfirst($v).'</option>';$html.=$form('visibility',$hidden.'<label>Workspace visibility<select name="visibility">'.$options.'</select></label><p>Public makes published non-private books accessible to anyone. Private requires membership.</p>','Save visibility');}if(!$w['legacy'])$html.=$form($w['archived']?'restore':'archive',$hidden,$w['archived']?'Restore workspace':'Archive workspace');}if(!$w['archived']&&!$w['organization_archived']&&Permissions::allows($w['role'],'manage')){$html.='<p><a href="/domains?id='.$w['id'].'">Custom Domains</a> · <a href="/analytics?id='.$w['id'].'">Analytics</a></p><h3>Workspace access</h3><p>Inherit the organization role, restrict to Viewer or deny access. Owner always has access. Only Owner can restrict an Admin.</p>';foreach($w['assignments'] as $m){$options='';foreach(match($m['role']){'admin'=>['inherit','admin','editor','viewer','none'],'editor'=>['inherit','editor','viewer','none'],default=>['inherit','viewer','none']} as $r)$options.='<option value="'.$r.'"'.($r===$m['assigned']?' selected':'').'>'.ucfirst($r).'</option>';$html.=$form('role',$hidden.self::h('user',$m['id']).'<label>'.self::e($m['username'].' ('.$m['role'].')').'<select name="role">'.$options.'</select></label>','Save access');}}$html.='</section>';
        }
        foreach($orgs as $o)if(Permissions::allows($o['role'],'manage')&&!$o['archived']){$html.='<h2>Create workspace in '.self::e($o['name']).'</h2>'.$form('create',self::h('organization',$o['id']).self::field('name','Workspace name').self::field('slug','Slug'),'Create workspace');if($canAdopt&&$o['role']==='owner')$html.='<p>Adopt legacy content only if this organization should control all existing documentation. URLs stay unchanged; future ownership transfers affect legacy Studio. Visibility can be changed after adoption.</p>'.$form('adopt',self::h('organization',$o['id']).self::field('name','Legacy workspace name','Legacy documentation').self::field('slug','Slug','legacy'), 'Adopt existing documentation (once)');}
        $page('Workspaces',$html.'</div>');
    }
}
