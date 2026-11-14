<?php
declare(strict_types=1);
namespace CajeerBook;

/** Server-rendered organization management. Every write is CSRF protected and reauthorized in IdentityStore. */
final class OrganizationController
{
    public function __construct(private readonly IdentityStore $store, private readonly AuthController $auth) {}
    private static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function post(string $k):string{return is_string($_POST[$k]??null)?$_POST[$k]:'';}
    private static function hidden(string $k,string $v):string{return '<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';}
    private static function field(string $k,string $label,string $value='',string $type='text',int $max=120):string{return '<label>'.self::e($label).'<input required name="'.self::e($k).'" type="'.self::e($type).'" maxlength="'.$max.'" value="'.self::e($value).'"'.($type==='password'?' autocomplete="new-password"':'').'></label>';}
    private static function form(string $path,string $csrf,string $action,string $body,string $label,?int $revision=null):string{return '<form class="editor org-form" method="post" action="'.self::e($path).'">'.self::hidden('csrf',$csrf).self::hidden('action',$action).($revision===null?'':self::hidden('revision',(string)$revision)).$body.'<button type="submit">'.self::e($label).'</button></form>';}
    private static function notice(string $s):string{return $s===''?'':'<p class="auth-notice" role="alert">'.self::e($s).'</p>';}
    private static function redirect(string $path):void{header('Location: '.$path,true,303);}
    private static function date(int $time):string{return gmdate('Y-m-d H:i',$time).' UTC';}
    private static function ip():string{$ip=$_SERVER['REMOTE_ADDR']??'';return is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)?$ip:'unknown';}
    public function dispatch(string $path,string $method,callable $page):void
    {
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        $ctx=$this->auth->current();$session=$this->auth->token();
        if($path==='/organizations/join'){$this->join($method,$ctx,$session,$page);return;}
        if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1><p><a href="/login">Sign in</a> to manage organizations.</p>');return;}
        $id='';if($path!=='/organizations'){
            if(!preg_match('~\A/organizations/([a-f0-9]{32})\z~D',$path,$m)){http_response_code(404);$page('Not found','<h1>Organization not found</h1>');return;}$id=$m[1];
        }
        $csrf=$ctx['session']['csrf'];$error='';$issued=null;
        if($id!==''&&!$this->store->organization($session,$id)){http_response_code(404);$page('Not found','<h1>Organization not found</h1>');return;}
        if($method==='POST'){
            if($csrf===''||!hash_equals($csrf,self::post('csrf'))){http_response_code(403);$page('Invalid request','<h1>Invalid CSRF token</h1>');return;}
            try{
                $action=self::post('action');
                if($id===''){
                    if($action!=='create')throw new \InvalidArgumentException('Unknown organization action.');
                    $o=$this->store->createOrganization($session,self::post('name'),self::post('slug'));self::redirect('/organizations/'.$o['id']);return;
                }
                $rev=filter_var(self::post('revision'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($rev===false)throw new \InvalidArgumentException('Invalid organization revision.');
                $fields=[];foreach(['name','slug','username','user','invitation','confirm'] as $k)$fields[$k]=self::post($k);
                $r=$this->store->mutateOrganization($session,$id,$rev,$action,$fields);
                if(isset($r['token']))$issued=$r;else{self::redirect(in_array($action,['leave','delete'],true)?'/organizations':$path);return;}
            }catch(OrganizationAccessException $e){http_response_code(403);$error=$e->getMessage();}
            catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
        }
        if($id===''){
            $html='<div class="auth-panel org-panel"><h1>Your organizations</h1><p><a href="/account">Account</a> · <a href="/organizations/join">Accept invitation</a></p>'.self::notice($error).'<h2>Organizations</h2>';
            foreach($this->store->organizations($session) as $o)$html.='<div class="org-card"><h3><a href="/organizations/'.$o['id'].'">'.self::e($o['name']).'</a></h3><p>'.self::e($o['slug']).' · '.self::e($o['role']).' · '.$o['member_count'].' members'.($o['archived']?' · Archived':'').'</p></div>';
            $html.='<h2>Create organization</h2>'.self::form($path,$csrf,'create',self::field('name','Organization name').self::field('slug','Slug (3–64 characters)','','text',64),'Create organization').'</div>';
            $page('Organizations',$html);return;
        }
        $o=$this->store->organization($session,$id);if(!$o){http_response_code(404);$page('Not found','<h1>Organization not found</h1>');return;}
        $rev=$o['revision'];$owner=$o['role']==='owner';
        $html='<div class="auth-panel org-panel"><p><a href="/organizations">All organizations</a></p><h1>'.self::e($o['name']).'</h1><p>'.self::e($o['slug']).' · '.self::e($o['role']).' · '.($o['archived']?'Archived':'Active').'</p>'.self::notice($error);
        if($issued){$link='/organizations/join#'.$issued['token'];$html.='<section class="org-card"><h2>Invitation created</h2><p>Share this one-time invitation with '.self::e($issued['username']).'. It expires in seven days. The token is shown only now.</p><label>Invitation link<input readonly data-org-invite-link value="'.self::e($link).'"></label><button type="button" data-org-copy>Copy full link</button><p>Or copy the token: <code>'.self::e($issued['token']).'</code></p><p><a href="'.self::e($link).'">Open invitation form</a></p><p role="status" data-org-copy-status></p></section>';}
        if($owner&&!$o['archived'])$html.='<h2>Settings</h2>'.self::form($path,$csrf,'rename',self::field('name','Organization name',$o['name']).self::field('slug','Slug',$o['slug'],'text',64),'Save settings',$rev).'<h2>Invite participant</h2><p>Use the existing username, or choose a username for a new account. Existing accounts must sign in to accept.</p>'.self::form($path,$csrf,'invite',self::field('username','Invited username','','text',40),'Create / replace invitation',$rev);
        $html.='<h2>Members</h2>';
        foreach($o['members'] as $m){$html.='<div class="org-card"><strong>'.self::e($m['name']).'</strong><p>'.self::e($m['username'].' · '.$m['role']).($m['enabled']?'':' · Disabled').' · Joined '.self::date($m['joined']).'</p>';
            if($owner&&$m['role']!=='owner'){
                if(!$o['archived'])$html.=self::form($path,$csrf,'remove',self::hidden('user',$m['id']),'Remove member',$rev);
                if($m['enabled'])$html.=self::form($path,$csrf,'transfer',self::hidden('user',$m['id']).'<p>Transfer ownership to this member? You will become a regular member.</p>','Transfer ownership',$rev);
            }$html.='</div>';}
        if($owner&&!$o['archived']){$html.='<h2>Invitations</h2>';foreach($o['invitations'] as $i)$html.='<div class="org-card"><strong>'.self::e($i['username']).'</strong><p>'.($i['expires']<=time()?'Expired':'Pending').' · Expires '.self::date($i['expires']).'</p>'.self::form($path,$csrf,'revoke',self::hidden('invitation',$i['id']),'Revoke invitation',$rev).'</div>';}
        if($owner){$html.='<h2>Organization lifecycle</h2>';if($o['archived'])$html.=self::form($path,$csrf,'restore','','Restore organization',$rev).'<p>Deletion is permanent. Back up identity data with the operator CLI before deleting.</p>'.self::form($path,$csrf,'delete',self::field('confirm','Type the organization slug to confirm'),'Delete archived organization',$rev);else $html.=self::form($path,$csrf,'archive','<p>Archive this organization and invalidate all pending invitations.</p>','Archive organization',$rev);}
        else $html.='<h2>Membership</h2>'.self::form($path,$csrf,'leave','','Leave organization',$rev);
        $page($o['name'],$html.'</div>');
    }
    private function join(string $method,?array $ctx,string $session,callable $page):void
    {
        $csrf=$ctx['session']['csrf']??$this->auth->preauthToken();$error='';$invite='';
        if($method==='POST'){
            if($csrf===''||!hash_equals($csrf,self::post('csrf'))){http_response_code(403);$page('Invalid request','<h1>Invalid CSRF token</h1>');return;}
            $invite=self::post('token');
            try{
                if(!$ctx&&self::post('password')!==self::post('confirm'))throw new \InvalidArgumentException('Passwords do not match.');
                $r=$this->store->acceptInvitation(trim($invite),$ctx?$session:'',self::ip(),self::post('name'),self::post('email'),self::post('password'));
                if($r['status']==='ok'){self::redirect($ctx?'/organizations/'.$r['organization']:'/login?joined=1');return;}
                if($r['status']==='limited'){http_response_code(429);header('Retry-After: 900');$error='Too many attempts. Try again later.';}
                else{http_response_code(422);$error=$r['status']==='signin'?'Sign in to the invited account, then reopen the invitation link or paste the token into the form.':'Invitation is invalid, expired or not intended for this account.';}
            }catch(\InvalidArgumentException|\DomainException $e){http_response_code(422);$error=$e->getMessage();}
        }
        $body='<p>'.($ctx?'Signed in as <strong>'.self::e($ctx['user']['username']).'</strong>.':'Already have an account? <a href="/login">Sign in</a>, then reopen the invitation link or paste the token here. New users can create their invited account below.').'</p>';
        $fields=self::field('token','Invitation token',$invite,'password',64); // token is never in the request URL
        if(!$ctx)$fields.=self::field('name','Display name').self::field('email','Email','', 'email',254).self::field('password','New password (12–72 bytes)','','password',72).self::field('confirm','Confirm password','','password',72);
        $page('Accept invitation','<div class="auth-panel org-panel"><h1>Accept invitation</h1>'.$body.self::notice($error).self::form('/organizations/join',$csrf,'accept',$fields,$ctx?'Join organization':'Create account and join').'</div>');
    }
}
