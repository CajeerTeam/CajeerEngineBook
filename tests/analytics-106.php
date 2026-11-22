<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{IdentityStore,Analytics,Workspaces};
function check106(bool $pass,string $label):void{if(!$pass)throw new RuntimeException($label);echo "PASS $label\n";}
function blocked106(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException|DomainException|RuntimeException|JsonException){check106(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function remove106(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $item){if($item->isDot())continue;if($item->isDir()&&!$item->isLink())remove106($item->getPathname());else unlink($item->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/book-106-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $identity=new IdentityStore($root);$identity->initialize('owner','Owner','owner@example.invalid','owner-passphrase-123');$token=$identity->login('owner','owner-passphrase-123','192.0.2.1')['token'];
    $org=$identity->createOrganization($token,'Analytics 106','analytics-106');
    $a=$identity->createWorkspace($token,$org['id'],'Public A','public-a');$b=$identity->createWorkspace($token,$org['id'],'Public B','public-b');
    $cfg=Analytics::defaults();$cfg['enabled']=true;$cfg['search_terms']=true;$cfg['goals']=['download','signup'];
    $cfg['posthog_key']='phc_abc123456789';$cfg['posthog_host']='https://eu.i.posthog.com';
    $cfg['matomo_url']='https://matomo.example.com';$cfg['matomo_site']='3';
    $cfg['plausible_script']='https://plausible.io/js/pa-AbCd12345.js';
    $cfg['umami_url']='https://umami.example.com';$cfg['umami_site']='94db1cb1-74f4-4a40-ad6c-962362670409';
    Analytics::validate($cfg);$identity->configureAnalytics($token,$a['id'],1,$cfg);
    $a=$identity->withWorkspace($token,$org['id'],$a['id'],fn($ctx,$w)=>$w);$b=$identity->withWorkspace($token,$org['id'],$b['id'],fn($ctx,$w)=>$w);
    $browser=Analytics::browserConfig($a,true);check106($browser['goals']===['download','signup']&&$browser['posthog_host']==='https://eu.i.posthog.com'&&$browser['umami_site']===$cfg['umami_site'],'vendor settings available to public pages only');
    check106(Analytics::browserConfig($a,false)===[]&&Analytics::browserConfig($b,true)===[],'private and unconfigured workspaces do not expose providers');
    $csp=Analytics::contentSecurityPolicy($a,true);
    check106(str_contains($csp,'https://eu-assets.i.posthog.com')&&str_contains($csp,'https://matomo.example.com')&&str_contains($csp,'https://umami.example.com')&&str_contains($csp,'https://plausible.io'),'configured vendor script hosts receive per-workspace CSP allowlist');
    check106(!str_contains(Analytics::contentSecurityPolicy($b,true),'matomo.example.com'),'vendor CSP does not leak into other workspaces');
    $payload=Analytics::accept('{"event":"search","path":"/search","consent":true,"query":"  PHP  Deployment  "}');
    check106($payload===['search','/search','php deployment'],'search query is safely normalized');
    check106(Analytics::accept('{"event":"conversion","path":"/docs/start","consent":true,"goal":"signup"}')===['conversion','/docs/start','signup'],'conversion goal accepted');
    foreach(['{"event":"search","path":"/search","consent":true,"query":"user@example.com"}',
        '{"event":"search","path":"/search","consent":true,"query":"abc 12345678"}',
        '{"event":"search","path":"/docs/start","consent":true,"query":"php setup"}',
        '{"event":"conversion","path":"/","consent":true,"goal":"<script>"}',
        '{"event":"conversion","path":"/","consent":true,"goal":"signup","visitor":"s"}',
        '{"event":"conversion","path":"/","consent":false,"goal":"signup"}'] as $bad)blocked106(fn()=>Analytics::accept($bad),'identifying or malformed dimension denied');
    foreach([['matomo_url','http://not-https.example.com'],['umami_url','https://127.0.0.1'],['posthog_host','https://evil.example.com@localhost'],['plausible_script','https://plausible.io/js/pa-id.js?x=1'],['goals',['signup','signup']]] as [$key,$bad])blocked106(function()use($cfg,$key,$bad){$v=$cfg;$v[$key]=$bad;Analytics::validate($v);},'unsafe or duplicate vendor configuration denied');
    Analytics::collect($root,$a,'page_view','/docs/start');
    Analytics::collect($root,$a,'download','/docs/start');
    Analytics::collect($root,$a,'conversion','/docs/start','download');
    Analytics::collect($root,$a,'conversion','/docs/start','signup');
    for($i=0;$i<4;$i++)Analytics::collect($root,$a,'search','/search','php deployment');
    Analytics::collect($root,$a,'search','/search','privatephrase');
    $r=Analytics::report($root,$a);
    check106($r['events']['page_view']===1&&$r['events']['search']===5&&$r['events']['conversion']===2&&$r['conversions']['download']===1&&$r['conversions']['signup']===1,'real 30-day workspace event and conversion aggregation');
    check106($r['queries']===['php deployment'=>4]&&!isset($r['queries']['privatephrase']),'rare query suppressed from dashboard');
    check106(array_sum($r['daily'])===9,'daily totals count each search and conversion once');
    check106(Analytics::report($root,$b)['events']===[],'cross-workspace isolation');
    blocked106(fn()=>Analytics::collect($root,$a,'conversion','/docs/start','unauthorized'),'unknown goal cannot increment counters');
    $off=$a;$off['analytics']['search_terms']=false;
    blocked106(fn()=>Analytics::collect($root,$off,'search','/search','php deployment'),'disabled search terms rejected');
    $file=Workspaces::directory($root,$a).'/analytics/'.gmdate('Y-m-d').'.json';$json=file_get_contents($file);
    check106(str_contains($json,'php deployment')&&!str_contains($json,'192.0.2.1')&&!str_contains($json,'owner@example')&&!str_contains($json,'/docs/start?'),'durable dimensions are aggregate-only');
    $identity->configureAnalytics($token,$a['id'],2,Analytics::defaults());
    $off=$identity->withWorkspace($token,$org['id'],$a['id'],fn($ctx,$w)=>$w);
    blocked106(fn()=>Analytics::collect($root,$off,'page_view','/'),'disabling analytics stops the collector');
}finally{remove106($root);}
