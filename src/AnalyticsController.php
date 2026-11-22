<?php
declare(strict_types=1);
namespace CajeerBook;

/** Manager-only workspace analytics, provider configuration, and aggregate reports. */
final class AnalyticsController
{
    public function __construct(private readonly IdentityStore $identity,private readonly AuthController $auth,private readonly string $root){}
    private static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function str(string $name):string{return is_string($_POST[$name]??null)?$_POST[$name]:'';}
    private static function field(string $name,string $label,string $value,string $placeholder='',int $max=255):string
    {return '<label>'.self::e($label).'<input name="'.self::e($name).'" maxlength="'.$max.'" value="'.self::e($value).'" placeholder="'.self::e($placeholder).'"></label>';}
    private static function checkbox(string $name,string $label,bool $checked):string
    {return '<label><input type="checkbox" name="'.self::e($name).'" value="1"'.($checked?' checked':'').'> '.self::e($label).'</label>';}
    private static function table(string $heading,string $col,array $data):string
    {
        $h='<h3>'.self::e($heading).'</h3><table><thead><tr><th>'.self::e($col).'</th><th>Count</th></tr></thead><tbody>';
        foreach($data as $key=>$hits)$h.='<tr><td>'.self::e((string)$key).'</td><td>'.(int)$hits.'</td></tr>';
        return $h.='</tbody></table>';
    }
    public function dispatch(string $method,callable $page):void
    {
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');header('Vary: Cookie');
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
        $ctx=$this->auth->current();if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1><a href="/login">Sign in</a>');return;}
        $id=is_string($_GET['id']??null)?$_GET['id']:'';
        if(!Workspaces::id($id)){http_response_code(404);$page('Workspace not found','<h1>Workspace not found</h1>');return;}
        $workspace=null;
        foreach($this->identity->workspaces($this->auth->token()) as $w)if($w['id']===$id&&Permissions::allows($w['role'],'manage')&&!$w['archived']&&!$w['organization_archived']){$workspace=$w;break;}
        if(!$workspace){http_response_code(403);$page('Forbidden','<h1>Workspace analytics permission required</h1>');return;}
        $error='';
        if($method==='POST'){
            if(!hash_equals($ctx['session']['csrf'],self::str('csrf'))){http_response_code(403);$page('Forbidden','<h1>Invalid CSRF token</h1>');return;}
            try{
                $revision=filter_var(self::str('revision'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($revision===false)throw new \InvalidArgumentException('Invalid workspace revision.');
                $goals=trim(self::str('goals'));
                $config=[
                    'enabled'=>isset($_POST['enabled']),
                    'ga4'=>strtoupper(trim(self::str('ga4'))),
                    'metrica'=>trim(self::str('metrica')),
                    'webvisor'=>isset($_POST['webvisor']),
                    'search_terms'=>isset($_POST['search_terms']),
                    'goals'=>$goals===''?[]:explode(',',str_replace(' ','',strtolower($goals))),
                    'posthog_key'=>trim(self::str('posthog_key')),
                    'posthog_host'=>trim(self::str('posthog_host'))===''?Analytics::defaults()['posthog_host']:rtrim(trim(self::str('posthog_host')),'/'),
                    'matomo_url'=>rtrim(trim(self::str('matomo_url')),'/'),
                    'matomo_site'=>trim(self::str('matomo_site')),
                    'plausible_script'=>trim(self::str('plausible_script')),
                    'umami_url'=>rtrim(trim(self::str('umami_url')),'/'),
                    'umami_site'=>strtolower(trim(self::str('umami_site'))),
                ];
                Analytics::validate($config);
                $this->identity->configureAnalytics($this->auth->token(),$id,$revision,$config);
                header('Location: /analytics?id='.$id,true,303);return;
            }catch(OrganizationAccessException $e){http_response_code(403);$error=$e->getMessage();}
             catch(\DomainException|\InvalidArgumentException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
        }
        $cfg=Analytics::settings($workspace);$h='<div class="auth-panel org-panel"><h1>Documentation analytics</h1><p><a href="/workspaces">Workspaces</a> / '.self::e($workspace['name']).'</p>';
        if($error!=='')$h.='<p role="alert">'.self::e($error).'</p>';
        try{
            $r=Analytics::report($this->root,$workspace);
            $views=(int)($r['events']['page_view']??0);$conversions=(int)($r['events']['conversion']??0);
            $ratio=$views>0?number_format(100*$conversions/$views,1):'0.0';
            $h.='<h2>Last 30 days (UTC)</h2><div class="analytics-summary">';
            foreach(['Page views'=>$views,'Searches'=>(int)($r['events']['search']??0),'Downloads'=>(int)($r['events']['download']??0),'Conversions'=>$conversions,'Conversions per 100 views'=>$ratio] as $title=>$num)
                $h.='<div class="analytics-metric"><strong>'.self::e((string)$num).'</strong><span>'.self::e($title).'</span></div>';
            $h.='</div>';
            $max=max(1,...array_values($r['daily']?:[1]));
            $h.='<h3>Daily activity</h3><div class="analytics-chart" role="img" aria-label="Number of recorded events each day">';
            foreach(array_reverse($r['daily'],true) as $day=>$hits){$pct=round(100*$hits/$max,2);$h.='<div class="analytics-chart-row"><time>'.self::e($day).'</time><div class="analytics-bar-track"><span style="width:'.$pct.'%"></span></div><strong>'.(int)$hits.'</strong></div>';}
            $h.='</div>';
            $h.=self::table('Popular documentation pages','Page',$r['pages']);
            $h.=self::table('Search queries (at least 3 searches)','Query',$r['queries']);
            $h.=self::table('Conversions by goal','Goal',$r['conversions']);
            $h.=self::table('Other events','Event',$r['events']);
        }catch(\Throwable $e){Observability::log('analytics.report_failure',['error_type'=>get_class($e)]);$h.='<p role="alert">Analytics reporting is currently unavailable.</p>';}
        $h.='<h2>Collection and providers</h2><form class="editor org-form" action="/analytics?id='.$id.'" method="post">'
            .'<input type="hidden" name="csrf" value="'.self::e($ctx['session']['csrf']).'">'
            .'<input type="hidden" name="revision" value="'.(int)$workspace['revision'].'">'
            .self::checkbox('enabled','Enable analytics on public documentation pages',$cfg['enabled'])
            .self::checkbox('search_terms','Aggregate sanitized search phrases (only show when used 3+ times)',$cfg['search_terms'])
            .self::field('goals','Conversion goals (comma-separated slugs)',implode(',',$cfg['goals']),'download,feedback,signup',480)
            .'<p>Built-in download and feedback events count as conversions when listed as goals. For other configured goals, use CajeerBookAnalytics.trackConversion("signup") or data-book-conversion="signup" on a link or button.</p>'
            .'<h3>Google Analytics 4 / Yandex Metrica</h3>'
            .self::field('ga4','GA4 measurement ID',$cfg['ga4'],'G-XXXXXXXXXX',22)
            .self::field('metrica','Yandex Metrica counter ID',$cfg['metrica'],'12345678',15)
            .self::checkbox('webvisor','Enable Yandex Webvisor after consent',$cfg['webvisor'])
            .'<h3>PostHog</h3>'
            .self::field('posthog_key','PostHog project token',$cfg['posthog_key'],'phc_...',120)
            .self::field('posthog_host','PostHog API host',$cfg['posthog_host'],'https://us.i.posthog.com',255)
            .'<h3>Matomo</h3>'
            .self::field('matomo_url','Matomo HTTPS origin',$cfg['matomo_url'],'https://analytics.example.com',255)
            .self::field('matomo_site','Matomo site ID',$cfg['matomo_site'],'1',10)
            .'<h3>Plausible</h3>'
            .self::field('plausible_script','Site-specific Plausible script URL',$cfg['plausible_script'],'https://plausible.io/js/pa-....js',350)
            .'<h3>Umami</h3>'
            .self::field('umami_url','Umami HTTPS origin',$cfg['umami_url'],'https://stats.example.com',255)
            .self::field('umami_site','Umami website UUID',$cfg['umami_site'],'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',36)
            .'<p>All optional external trackers load only after opt-in. External providers process data under their own policies. First-party counters never store visitors or IPs. Search phrases are sanitized and displayed only in aggregates.</p>'
            .'<button>Save analytics settings</button></form>';
        $page('Workspace analytics',$h.'</div>');
    }
}
