<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\RequestSecurity as R;
use CajeerBook\ImportFiles;
$n=0;
function ok(bool $condition,string $message):void{global $n;if(!$condition)throw new RuntimeException($message);++$n;}
function denied(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$message);return;}throw new RuntimeException($message);}
foreach(['/','/docs/guide/1/start?x=%2f','/api/v1/pages','/w/'.str_repeat('a',32).'/'.str_repeat('b',32).'/docs'] as $path)ok(R::path($path)===explode('?',$path)[0],'valid target');
foreach(['http://evil.test/','//evil.test/','/a//b','/a/../b','/a/./b','/a/%2e/b','/a%2fb','/a%5Cb','/a%252f','/a%00','/a%0a','/a%','/a#x','/a\\b',"/a\r",'/a%3f'] as $path)denied(fn()=>R::path($path),'invalid target '.$path);
$server=['HTTP_HOST'=>'book.test:8080','HTTP_ORIGIN'=>'http://book.test:8080','HTTP_SEC_FETCH_SITE'=>'same-origin'];
ok(R::browserPost('/account','POST',$server,null),'same-origin POST');
foreach(['https://evil.test','null','http://book.test','http://book.test:8080/','http://book.test:8080, http://evil.test','http://user@book.test:8080'] as $origin)ok(!R::browserPost('/account','POST',array_replace($server,['HTTP_ORIGIN'=>$origin]),null),'reject origin');
ok(!R::browserPost('/account','POST',array_replace($server,['HTTP_SEC_FETCH_SITE'=>'same-site']),null),'same-site is not same-origin');
ok(!R::browserPost('/login','POST',['HTTP_HOST'=>'book.test','HTTP_ORIGIN'=>'http://book.test'],'book.test'),'primary HTTPS required');
ok(R::browserPost('/login','POST',['HTTP_HOST'=>'book.test','HTTP_ORIGIN'=>'https://book.test'],'book.test'),'primary HTTPS');
ok(R::browserPost('/account','POST',[],null),'legacy CSRF still checked by controller');
ok(R::browserPost('/api/v1/ci','POST',['HTTP_ORIGIN'=>'null'],null),'machine auth remains independent');
ok(R::browserPost('/webhooks/gitlab','POST',['HTTP_SEC_FETCH_SITE'=>'cross-site'],null),'machine webhook');
foreach(['http://book.test:','http://book.test:00000','http://[::::]:8080'] as $origin)ok(!R::browserPost('/account','POST',array_replace($server,['HTTP_ORIGIN'=>$origin,'HTTP_HOST'=>substr($origin,7)]),null),'reject malformed authority');
ok(!R::browserPost('/account','POST',['HTTP_HOST'=>'book.test','HTTPS'=>'off','HTTP_ORIGIN'=>'https://book.test'],null),'explicit HTTP cannot accept HTTPS origin');
ok(R::browserPost('/account','POST',['HTTP_HOST'=>'book.test','HTTPS'=>'off','HTTP_ORIGIN'=>'https://book.test'],'book.test'),'authenticated edge primary uses HTTPS');
$tmp=tempnam(sys_get_temp_dir(),'book-security-');file_put_contents($tmp,'hello');$set=new ImportFiles();
try{$set->add('a.md',$tmp);ok(file_get_contents($set->files()['a.md'])==='hello','exact copy');denied(fn()=>$set->add('a.md',$tmp),'duplicate');denied(fn()=>$set->add('../bad',$tmp),'traversal');$set->addBytes('b.md','world');ok(count($set->files())===2,'staging usable after rejection');
if(class_exists(ZipArchive::class)){$z=$tmp.'.zip';$zip=new ZipArchive();$zip->open($z,ZipArchive::CREATE);$zip->addFromString('valid.md','valid');$zip->addFromString('../bad','bad');$zip->close();try{denied(fn()=>$set->zip($z),'unsafe zip');ok(count($set->files())===2,'failed zip is atomic');$set->addBytes('valid.md','retry');ok(count($set->files())===3,'zip reservation rolled back');}finally{unlink($z);}}
else {if(in_array('--require-zip',$argv,true))throw new RuntimeException('ZipArchive required');echo "SKIP ZipArchive unavailable\n";}
}finally{unlink($tmp);}
echo "PASS $n security hardening checks\n";
