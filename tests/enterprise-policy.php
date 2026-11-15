<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\ReleaseHealth;
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $f,string $label):void{try{$f();}catch(RuntimeException|InvalidArgumentException){ok(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
$keys=['BOOK_PRIMARY_HOST','BOOK_SECURE_COOKIE','BOOK_OIDC_CONFIG','BOOK_OIDC_CLIENT_SECRET','BOOK_OIDC_REQUIRED','BOOK_PG_DSN','BOOK_REDIS_HOST'];$old=[];
foreach($keys as $k){$old[$k]=getenv($k);putenv($k);}
try{
 ReleaseHealth::configuration();ok(true,'legacy configuration remains valid');
 putenv('BOOK_OIDC_REQUIRED=1');deny(fn()=>ReleaseHealth::configuration(),'mandatory SSO cannot be ready without provider');putenv('BOOK_OIDC_REQUIRED=0');
 putenv('BOOK_PRIMARY_HOST=book.example.org');putenv('BOOK_SECURE_COOKIE=0');deny(fn()=>ReleaseHealth::configuration(),'custom domains require secure cookies');putenv('BOOK_SECURE_COOKIE=1');
 $c=['issuer'=>'https://id.example.org/realm','client_id'=>'book','redirect_uri'=>'https://wrong.example.org/auth/oidc/callback','organization'=>str_repeat('a',32),'label'=>'Corporate','groups'=>['readers'=>'viewer']];
 putenv('BOOK_OIDC_CONFIG='.json_encode($c));putenv('BOOK_OIDC_CLIENT_SECRET=test-secret');deny(fn()=>ReleaseHealth::configuration(),'OIDC callback cannot be on another host');
 $c['redirect_uri']='https://book.example.org/auth/oidc/callback';putenv('BOOK_OIDC_CONFIG='.json_encode($c));ReleaseHealth::configuration();ok(true,'OIDC primary HTTPS callback accepted');
 putenv('BOOK_REDIS_HOST=redis');deny(fn()=>ReleaseHealth::configuration(),'Redis without PostgreSQL not ready');putenv('BOOK_PG_DSN=pgsql:host=postgres;dbname=book');ReleaseHealth::configuration();ok(true,'enterprise configuration accepted without network side effects');
}finally{foreach($old as $k=>$v)putenv($v===false?$k:$k.'='.$v);}
