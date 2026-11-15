<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{AuditLog as A,AuditArchive,AuditView,Permissions};
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(Throwable){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
$ns=str_repeat('a',32);$org=str_repeat('b',32);$wid=str_repeat('c',32);$uid=str_repeat('d',32);$target=str_repeat('e',32);$stream="$org/$wid";
$r=A::event('page.publish',['id'=>$uid,'username'=>'secret-user','hash'=>'secret-hash'],$target);$r['subject']='secret Markdown';$r['detail']='3 page(s)';$r['token']='secret-token';$e=A::project($r,$stream);
ok($e['actor']===$uid&&$e['target']===$target&&$e['organization']===$org&&$e['workspace']===$wid&&$e['count']===3,'safe projection keeps stable IDs and batch count');
ok(!str_contains(json_encode($e),'secret'),'arbitrary text, username, hashes and credentials omitted');
$r['type']='workspace.role';$r['detail']='sensitive-user · editor';$e2=A::project($r,'identity');ok($e2['code']==='editor'&&!str_contains(json_encode($e2),'sensitive'),'only allowed role extracted');
unset($r['outcome']);$r['type']='login.failed';ok(A::project($r,'identity')['outcome']==='denied','failed login outcome');
$r['type']='workspace.domain';$r['detail']='DNS proof lost';ok(A::project($r,'identity')['outcome']==='failure','DNS verification failure outcome');
$payload=json_encode($e,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$h=A::hash($ns,$stream,1,A::ZERO,$payload);
ok($h!==A::hash(str_repeat('f',32),$stream,1,A::ZERO,$payload)&&$h!==A::hash($ns,'legacy',1,A::ZERO,$payload)&&$h!==A::hash($ns,$stream,2,A::ZERO,$payload),'chain binds installation, stream and sequence');
ok($h!==A::hash($ns,$stream,1,A::ZERO,$payload.' ')&&$h!==A::hash($ns,$stream,1,str_repeat('f',64),$payload),'chain binds exact bytes and previous hash');
$cursor=$e['at'].'.'.$e['id'];ok(A::filters(['action'=>'page.publish','actor'=>$uid,'organization'=>$org,'workspace'=>$wid,'outcome'=>'success','from'=>'2024-02-29','to'=>'2024-03-01','before'=>$cursor])['before']===$cursor,'valid typed filters and opaque keyset cursor');
foreach([['action'=>"x' OR 1=1"],['actor'=>[]],['outcome'=>'all'],['from'=>'2025-02-29'],['from'=>'2026-01-01','to'=>'2025-01-01'],['before'=>'1'],['before'=>'1.x'],['workspace'=>'not-id']] as $bad)deny(fn()=>A::filters($bad),'invalid filter rejected '.json_encode($bad));
$db=['users'=>[$uid=>['owner'=>false]],'organizations'=>[$org=>['owner'=>$target,'members'=>[$uid=>1,$target=>1],'roles'=>[$uid=>'admin']]],'workspaces'=>[$wid=>['organization'=>$org,'roles'=>[$uid=>'none']]]];
ok(A::access($db,$uid)===['owner'=>false,'orgs'=>[$org],'workspaces'=>[]],'organization admin workspace deny preserved');$db['workspaces'][$wid]['roles'][$uid]='admin';ok(A::access($db,$uid)['workspaces']===[$wid],'authorized workspace included');unset($db['organizations'][$org]['members'][$uid]);ok(A::access($db,$uid)['orgs']===[]&&A::access($db,$uid)['workspaces']===[],'revoked member loses historical access');
$rows=[['cursor'=>$cursor,'stream'=>$stream,'sequence'=>1,'previous_hash'=>A::ZERO,'hash'=>$h,'event'=>$e]];
$csv=AuditView::encode($rows,'csv');$jsonl=AuditView::encode($rows,'jsonl');ok(str_contains($csv,'page.publish')&&json_decode(trim($jsonl),true)===$rows[0],'CSV and JSONL preserve event metadata');
$html=AuditView::render(['csrf'=>'csrf','events'=>$rows,'next'=>$cursor],['action'=>'page.publish']);ok(str_contains($html,'Event details')&&str_contains($html,'Older events')&&str_contains($html,'csrf'),'explorer details, cursor and CSRF export form');
$dir=sys_get_temp_dir().'/audit-policy-'.bin2hex(random_bytes(8));mkdir($dir,0700);$file=$dir.'/test.jsonl';
try{$header=['format'=>1,'installation'=>$ns,'stream'=>$stream,'anchor'=>0,'anchor_hash'=>A::ZERO,'cutoff'=>$e['at']+1];$record=['sequence'=>1,'previous_hash'=>A::ZERO,'hash'=>$h,'payload'=>$payload];$footer=['end'=>1,'hash'=>$h,'count'=>1];$write=fn($a)=>file_put_contents($file,implode("\n",array_map(fn($v)=>json_encode($v,JSON_THROW_ON_ERROR),$a))."\n");$write([$header,$record,$footer]);ok(AuditArchive::verify($file)['hash']===$h,'external archive verification');$write([$header,array_replace($record,['payload'=>$payload.' ']),$footer]);deny(fn()=>AuditArchive::verify($file),'archive payload tamper detected');$write([$header,$record]);deny(fn()=>AuditArchive::verify($file),'truncated archive rejected');$write([array_replace($header,['installation'=>str_repeat('f',32)]),$record,$footer]);deny(fn()=>AuditArchive::verify($file),'cross-installation archive tamper rejected');}
finally{unlink($file);rmdir($dir);}
