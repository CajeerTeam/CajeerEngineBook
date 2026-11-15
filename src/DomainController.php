<?php
declare(strict_types=1);
namespace CajeerBook;

/** Management is available only on the installation's main host. */
final class DomainController
{
    public function __construct(private readonly IdentityStore $identity,private readonly AuthController $auth){}
    private static function e(string $s):string{return Markdown::e($s);}
    private static function str(mixed $v):string{return is_string($v)?$v:'';}
    private static function hidden(string $k,string $v):string{return '<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';}
    public function dispatch(string $method,callable $page):void
    {
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');
        $ctx=$this->auth->current();if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1>');return;}
        $token=$this->auth->token();$id=self::str($method==='POST'?($_POST['id']??null):($_GET['id']??null));$csrf=$ctx['session']['csrf'];$error='';
        $items=$this->identity->workspaces($token);$w=null;foreach($items as $item)if($item['id']===$id&&!$item['legacy']&&!$item['organization_archived']&&Permissions::allows($item['role'],'manage')){$w=$item;break;}
        if(!$w){http_response_code(404);$page('Domains unavailable','<h1>Domains unavailable</h1>');return;}
        if($method==='POST'){
            if(!hash_equals($csrf,self::str($_POST['csrf']??null))){http_response_code(403);$page('Forbidden','<h1>Invalid CSRF token</h1>');return;}
            try{
                $rev=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($rev===false)throw new \InvalidArgumentException('Invalid revision');
                $host=self::str($_POST['host']??null);$action=self::str($_POST['action']??null);
                if($action==='verify'){$ok=$this->identity->verifyDomain($token,$csrf,$id,$host,$rev);if(!$ok)throw new \DomainException('DNS TXT proof not confirmed. Check the exact record and retry.');}
                else $this->identity->mutateDomain($token,$csrf,$id,$host,$action,$rev);
                header('Location: /domains?id='.$id,true,303);return;
            }catch(OrganizationAccessException $e){http_response_code(403);$error=$e->getMessage();}
            catch(\DomainException|\InvalidArgumentException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
            $w=null;foreach($this->identity->workspaces($token) as $item)if($item['id']===$id&&!$item['organization_archived']&&Permissions::allows($item['role'],'manage')){$w=$item;break;}if(!$w){http_response_code(403);return;}
        }
        $html='<div class="auth-panel org-panel"><h1>Custom Domains</h1><p><a href="/workspaces">Workspaces</a> / '.self::e($w['name']).'</p>'.($error!==''?'<p role="alert">'.self::e($error).'</p>':'');
        $primary=CustomDomains::primary();if($primary===null){$page('Custom Domains',$html.'<p>Configure BOOK_PRIMARY_HOST and HTTPS ingress first.</p></div>');return;}
        $form=static function(string $action,string $body,string $label)use($id,$csrf,$w):string{return '<form method="post" action="/domains" class="editor org-form">'.self::hidden('id',$id).self::hidden('csrf',$csrf).self::hidden('revision',(string)$w['revision']).self::hidden('action',$action).$body.'<button>'.self::e($label).'</button></form>';};
        $eligible=!$w['archived']&&Visibility::workspace($w)==='public';
        $html.='<p>Public Reader only. Set CNAME to <code>'.self::e($primary).'</code> (or A/AAAA to the ingress) and keep the DNS TXT proof. HTTPS certificates are provisioned by the ingress after verification.</p>';
        if(!$eligible)$html.='<p role="alert">Workspace is private or archived; all domain routing is suspended.</p>';
        if($eligible)$html.=$form('add','<label>Domain hostname<input required name="host" maxlength="240" placeholder="docs.company.com"></label>','Add domain');
        foreach($w['domains']??[] as $host=>$r){$hidden=self::hidden('host',$host);$active=$eligible&&CustomDomains::active($r);$html.='<section class="org-card"><h2>'.self::e($host).'</h2><p>'.self::e(CustomDomains::status($r)).(($w['primary_domain']??null)===$host?' · Primary':'').($active?' · <a href="https://'.self::e($host).'/">Open Reader</a>':'').'</p><p>TXT name: <code>'.self::e(CustomDomains::dnsName($host)).'</code><br>TXT value: <code>'.self::e(CustomDomains::challenge($r)).'</code></p>';
            if($r['verified'])$html.='<p>Verification valid until '.self::e(gmdate('c',$r['verified']+CustomDomains::LEASE)).'</p>';
            if($eligible&&!$r['disabled'])$html.=$form('verify',$hidden,'Verify DNS');if($active)$html.=$form('primary',$hidden,'Make primary');
            if(!$r['disabled'])$html.=$form('disable',$hidden,'Disable');$html.=$form('reset',$hidden,'Rotate proof / enable').$form('remove',$hidden,'Remove').'</section>';
        }
        $page('Custom Domains',$html.'</div>');
    }
}
