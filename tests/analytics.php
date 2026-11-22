<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{IdentityStore,Analytics,Workspaces,OrganizationAccessException};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $pass,string $label):void{if(!$pass)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException|DomainException|RuntimeException|JsonException){ok(true,$label);return;}throw new RuntimeException('Allowed: '.$label);}
function clean(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/book-analytics-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $identity=new IdentityStore($root);$owner=$identity->initialize('owner','Owner','owner@example.invalid','password-owner-123');
 $token=$identity->login('owner','password-owner-123','192.0.2.1')['token'];
 $other=$identity->createUser($token,'member','Member','member@example.invalid','password-member-123');$otherToken=$identity->login('member','password-member-123','192.0.2.2')['token'];
 $org=$identity->createOrganization($token,'Analytics Organization','analytics-org');
 $a=$identity->createWorkspace($token,$org['id'],'Documentation A','docs-a');$b=$identity->createWorkspace($token,$org['id'],'Documentation B','docs-b');
 $aId=$a['id'];$bId=$b['id'];
 ok(Analytics::settings($a)===Analytics::defaults(),'analytics off by default');
 $cfg=['enabled'=>true,'ga4'=>'G-ABCDEF1234','metrica'=>'12345678','webvisor'=>true];
 deny(fn()=>$identity->configureAnalytics($otherToken,$aId,1,$cfg),'unauthorized settings write rejected');
 deny(fn()=>$identity->configureAnalytics($token,$aId,1,['enabled'=>true,'ga4'=>'<script>','metrica'=>'','webvisor'=>false]),'malicious GA4 ID rejected');
 deny(fn()=>$identity->configureAnalytics($token,$aId,1,['enabled'=>true,'ga4'=>'','metrica'=>'','webvisor'=>true]),'Webvisor requires a Metrica counter');
 $identity->configureAnalytics($token,$aId,1,$cfg);
 ok(array_intersect_key(Analytics::settings($identity->workspaces($token)[0]),$cfg)===$cfg,'valid tracker settings persisted for one workspace');
 deny(fn()=>$identity->configureAnalytics($token,$aId,1,$cfg),'stale settings write rejected');
 $identity->mutateWorkspace($token,$aId,2,'visibility','public','');
 $a=$identity->withWorkspace($token,$org['id'],$aId,fn($ctx,$w)=>$w);$b=$identity->withWorkspace($token,$org['id'],$bId,fn($ctx,$w)=>$w);
 ok(Analytics::browserConfig($a,true)['ga4']==='G-ABCDEF1234'&&Analytics::browserConfig($a,false)===[]&&Analytics::browserConfig($b,true)===[],'workspace browser configuration is scoped and explicit');
 $payload=Analytics::accept('{"event":"page_view","path":"/docs/guide/1.0/start","consent":true}');ok($payload===['page_view','/docs/guide/1.0/start'],'valid opt-in event accepted');
 foreach(['{"event":"page_view","path":"/docs/guide/../secret","consent":true}', '{"event":"page_view","path":"/docs/guide/1.0/start?token=secret","consent":true}', '{"event":"unapproved","path":"/","consent":true}', '{"event":"page_view","path":"/","consent":false}', '{"event":"page_view","path":"/","consent":true,"visitor":"secret"}', '{"event":"page_view","path":"/","consent":true,"ip":"192.0.2.1"}'] as $raw)deny(fn()=>Analytics::accept($raw),'invalid or identifying event payload rejected');
 Analytics::collect($root,$a,'page_view','/docs/guide/1.0/start');Analytics::collect($root,$a,'page_view','/docs/guide/1.0/start');Analytics::collect($root,$a,'search','/search');
 $r=Analytics::report($root,$a);ok($r['events']['page_view']===2&&$r['events']['search']===1&&$r['pages']['/docs/guide/1.0/start']===2&&array_sum($r['daily'])===3,'first-party durable aggregated views and events');
 ok(Analytics::report($root,$b)['events']===[],'workspace events are isolated');
 $path=Workspaces::directory($root,$a).'/analytics/'.gmdate('Y-m-d').'.json';$raw=file_get_contents($path);
 ok(!str_contains($raw,'192.0.2')&&!str_contains($raw,'token=')&&!str_contains($raw,$owner['id']),'aggregates contain no visitor identifiers or query strings');
 $identity->configureAnalytics($token,$aId,3,Analytics::defaults());$off=$identity->withWorkspace($token,$org['id'],$aId,fn($ctx,$w)=>$w);
 deny(fn()=>Analytics::collect($root,$off,'page_view','/'),'disabled workspace refuses new events');
 ok(Analytics::browserConfig($off,true)===[],'disabled workspace does not expose tracker config');
 $stored=Files::read($root.'/identity/identity.json');$stored['workspaces'][$aId]['analytics']['ga4']='javascript:alert(1)';Files::json($root.'/identity/identity.json',$stored);
 deny(fn()=>$identity->workspaces($token),'corrupt analytics settings fail closed');
}finally{clean($root);}
