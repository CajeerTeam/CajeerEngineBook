<?php
declare(strict_types=1);
namespace CajeerBook;

/** Authenticated settings and aggregate report UI; no direct access to the registry file. */
final class AnalyticsController
{
    public function __construct(private readonly IdentityStore $identity,private readonly AuthController $auth,private readonly string $root){}
    private static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function str(string $name):string{return is_string($_POST[$name]??null)?$_POST[$name]:'';}
    public function dispatch(string $method,callable $page):void
    {
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');header('Vary: Cookie');
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
        $ctx=$this->auth->current();if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1><a href="/login">Sign in</a>');return;}
        $id=is_string($_GET['id']??null)?$_GET['id']:'';
        if(!Workspaces::id($id)){http_response_code(404);$page('Workspace not found','<h1>Workspace not found</h1>');return;}
        $items=$this->identity->workspaces($this->auth->token());$workspace=null;
        foreach($items as $w)if($w['id']===$id&&Permissions::allows($w['role'],'manage')&&!$w['archived']&&!$w['organization_archived']){$workspace=$w;break;}
        if(!$workspace){http_response_code(403);$page('Forbidden','<h1>Workspace analytics permission required</h1>');return;}
        $error='';
        if($method==='POST'){
            if(!hash_equals($ctx['session']['csrf'],self::str('csrf'))){http_response_code(403);$page('Forbidden','<h1>Invalid CSRF token</h1>');return;}
            try{
                $revision=filter_var(self::str('revision'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($revision===false)throw new \InvalidArgumentException('Invalid workspace revision.');
                $config=['enabled'=>isset($_POST['enabled']),'ga4'=>strtoupper(trim(self::str('ga4'))),'metrica'=>trim(self::str('metrica')),'webvisor'=>isset($_POST['webvisor'])];
                $this->identity->configureAnalytics($this->auth->token(),$id,$revision,$config);
                header('Location: /analytics?id='.$id,true,303);return;
            }catch(OrganizationAccessException $e){http_response_code(403);$error=$e->getMessage();}
             catch(\DomainException|\InvalidArgumentException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
        }
        $cfg=Analytics::settings($workspace);$h='<div class="auth-panel org-panel"><h1>Workspace analytics</h1><p><a href="/workspaces">Workspaces</a> / '.self::e($workspace['name']).'</p>';
        if($error!=='')$h.='<p role="alert">'.self::e($error).'</p>';
        $h.='<form class="editor org-form" action="/analytics?id='.$id.'" method="post">'
             .'<input type="hidden" name="csrf" value="'.self::e($ctx['session']['csrf']).'">'
             .'<input type="hidden" name="revision" value="'.$workspace['revision'].'">'
             .'<label><input type="checkbox" name="enabled" value="1"'.($cfg['enabled']?' checked':'').'> Enable first-party aggregate collection and optional trackers</label>'
             .'<label>Google Analytics 4 measurement ID (G-XXXXXXXX)<input name="ga4" maxlength="22" value="'.self::e($cfg['ga4']).'" placeholder="G-XXXXXXXXXX"></label>'
             .'<label>Yandex Metrica counter ID<input name="metrica" inputmode="numeric" maxlength="15" value="'.self::e($cfg['metrica']).'" placeholder="12345678"></label>'
             .'<label><input type="checkbox" name="webvisor" value="1"'.($cfg['webvisor']?' checked':'').'> Enable Yandex Webvisor after consent</label>'
             .'<p>Tracking is disabled until the visitor explicitly accepts it. No IP addresses, visitor identities, URL queries or page contents are retained in first-party counters. Consent can be withdrawn from the page footer.</p>'
             .'<button>Save analytics settings</button></form>';
        try{
            $r=Analytics::report($this->root,$workspace);
            $h.='<h2>Last 30 days</h2><p>Page views: '.(int)($r['events']['page_view']??0).'</p><h3>Events</h3><table><thead><tr><th>Event</th><th>Count</th></tr></thead><tbody>';
            foreach($r['events'] as $name=>$hits)$h.='<tr><td>'.self::e($name).'</td><td>'.(int)$hits.'</td></tr>';
            $h.='</tbody></table><h3>Top pages</h3><table><thead><tr><th>Path</th><th>Views</th></tr></thead><tbody>';
            foreach($r['pages'] as $path=>$hits)$h.='<tr><td>'.self::e($path).'</td><td>'.(int)$hits.'</td></tr>';
            $h.='</tbody></table><h3>Daily event totals</h3><table><thead><tr><th>UTC date</th><th>Total events</th></tr></thead><tbody>';
            foreach($r['daily'] as $date=>$hits)$h.='<tr><td>'.self::e($date).'</td><td>'.(int)$hits.'</td></tr>';
            $h.='</tbody></table>';
        }catch(\Throwable $e){Observability::log('analytics.report_failure',['error_type'=>get_class($e)]);$h.='<p role="alert">Analytics reporting is currently unavailable.</p>';}
        $page('Workspace analytics',$h.'</div>');
    }
}
