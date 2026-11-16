<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{Oidc,IdentityStore,Permissions};
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(Throwable){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
function clean(string $p):void{foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$root=sys_get_temp_dir().'/book-oidc-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $s=new IdentityStore($root);$owner=$s->initialize('owner','Owner','owner@example.org','owner-password-123');$ot=$s->login('owner','owner-password-123','192.0.2.1')['token'];$org=$s->createOrganization($ot,'Team','team');$oid=$org['id'];
 $config=['issuer'=>'https://id.example.org/realms/team','client_id'=>'book','redirect_uri'=>'https://book.example.org/auth/oidc/callback','organization'=>$oid,'label'=>'Corporate ID','groups'=>['readers'=>'viewer','writers'=>'editor']];putenv('BOOK_OIDC_CONFIG='.json_encode($config));putenv('BOOK_OIDC_CLIENT_SECRET=test-secret');$c=Oidc::config();
 $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$d=openssl_pkey_get_details($key);$j=['keys'=>[['kty'=>'RSA','kid'=>'key-1','n'=>Oidc::b64($d['rsa']['n']),'e'=>Oidc::b64($d['rsa']['e']),'alg'=>'RS256','use'=>'sig']]];
 $browser=bin2hex(random_bytes(32));$flow=$s->beginOidc($c,$browser,'192.0.2.2');$state=$flow['state'];ok($flow['status']==='ok'&&strlen($flow['verifier'])===64,'one-time PKCE transaction');
 ok($s->consumeOidc($c,$state,bin2hex(random_bytes(32)))===null,'wrong browser rejected');ok((new IdentityStore($root))->consumeOidc($c,$state,$browser)['nonce']===$flow['nonce'],'flow shared across instances');ok($s->consumeOidc($c,$state,$browser)===null,'flow replay denied');
 $now=time();$claims=['iss'=>$c['issuer'],'sub'=>'subject-1','aud'=>'book','nonce'=>$flow['nonce'],'iat'=>$now,'exp'=>$now+3600,'groups'=>['readers','writers'],'name'=>'Corporate User','email'=>'sso@example.org','email_verified'=>true];
 $jwt=function(array $claims,array $head=['alg'=>'RS256','kid'=>'key-1'])use($key){$x=Oidc::b64(json_encode($head)).'.'.Oidc::b64(json_encode($claims));openssl_sign($x,$sig,$key,OPENSSL_ALGO_SHA256);return $x.'.'.Oidc::b64($sig);};
 ok(Oidc::verify($jwt($claims),$j,$c,$flow['nonce'],$now)===$claims,'valid RSA signature and claims');
 foreach(['iss'=>'https://wrong.example','aud'=>'other','nonce'=>'wrong','exp'=>$now-1,'iat'=>$now+600,'nbf'=>$now+600,'sub'=>'','azp'=>'other'] as $k=>$v)deny(fn()=>Oidc::verify($jwt(array_replace($claims,[$k=>$v])),$j,$c,$flow['nonce'],$now),'reject '.$k);
 foreach(['azp'=>null,'nbf'=>null,'events'=>null,'aud'=>(object)['0'=>'book'],'groups'=>(object)['0'=>'writers']] as $k=>$v)deny(fn()=>Oidc::verify($jwt(array_replace($claims,[$k=>$v])),$j,$c,$flow['nonce'],$now),'reject malformed optional claim '.$k);
 foreach(['crit','jku','jwk','x5u','b64'] as $k)deny(fn()=>Oidc::verify($jwt($claims,['alg'=>'RS256','kid'=>'key-1',$k=>null]),$j,$c,$flow['nonce'],$now),'reject unsupported header '.$k);
 foreach(['use','alg','key_ops'] as $k){$badKeys=$j;$badKeys['keys'][0][$k]=null;deny(fn()=>Oidc::verify($jwt($claims),$badKeys,$c,$flow['nonce'],$now),'reject null JWK '.$k);}
 deny(fn()=>Oidc::verify($jwt($claims,['alg'=>'none','kid'=>'key-1']),$j,$c,$flow['nonce'],$now),'reject algorithm confusion');deny(fn()=>Oidc::verify($jwt($claims,['alg'=>'RS256','kid'=>'unknown']),$j,$c,$flow['nonce'],$now),'reject unknown key');deny(fn()=>Oidc::verify($jwt($claims),['keys'=>[$j['keys'][0],$j['keys'][0]]],$c,$flow['nonce'],$now),'reject duplicate signing keys');
 foreach([['unexpected'=>'book'],['book',7],array_fill(0,33,'book')] as $aud)deny(fn()=>Oidc::verify($jwt(array_replace($claims,['aud'=>$aud])),$j,$c,$flow['nonce'],$now),'reject malformed audience');
 foreach([['sign'],['verify'=>true]] as $ops){$badKeys=$j;$badKeys['keys'][0]['key_ops']=$ops;deny(fn()=>Oidc::verify($jwt($claims),$badKeys,$c,$flow['nonce'],$now),'reject unsuitable JWK key_ops');}
 $bad=$jwt($claims);$bad=substr($bad,0,strrpos($bad,'.')+1).Oidc::b64(random_bytes(256));deny(fn()=>Oidc::verify($bad,$j,$c,$flow['nonce'],$now),'reject forged signature');
 $a=$s->federatedLogin($c,$claims);$uid=$a['user']['id'];ok($a['status']==='ok'&&!$a['user']['owner']&&$s->organization($a['token'],$oid)['role']==='editor','JIT account with mapped editor role');
 $w=$s->createWorkspace($ot,$oid,'Restricted','restricted');$w2=$s->createWorkspace($ot,$oid,'Editor','editor');$s->assignWorkspaceRole($ot,$w['id'],1,$uid,'none');$s->assignWorkspaceRole($ot,$w2['id'],1,$uid,'editor');
 ok($s->withWorkspace($a['token'],$oid,$w['id'],fn($ctx,$w)=>$w)===null,'explicit workspace deny overrides SSO group');
 $b=$s->federatedLogin($c,array_replace($claims,['groups'=>['readers']]));ok($b['user']['id']===$uid&&$s->current($a['token'])===null&&$s->organization($b['token'],$oid)['role']==='viewer','stable subject, group downgrade, old session revoked');
 ok($s->withWorkspace($b['token'],$oid,$w2['id'],fn($ctx,$w)=>$w['role'])==='viewer','workspace editor grant clamped on group downgrade');
 deny(fn()=>$s->mutateOrganization($ot,$oid,$s->organization($ot,$oid)['revision'],'role',['user'=>$uid,'role'=>'admin']),'managed group role cannot be manually elevated');
 deny(fn()=>$s->mutateOrganization($ot,$oid,$s->organization($ot,$oid)['revision'],'remove',['user'=>$uid]),'managed membership cannot be silently removed and re-provisioned');
 ok($s->login($b['user']['username'],'owner-password-123','192.0.2.4')['status']==='invalid','SSO account cannot use local password');deny(fn()=>$s->issueReset($b['user']['username']),'SSO account cannot recover local password');
 $local=$s->createUser($ot,'local','Local','local@example.org','local-password-123');$lt=$s->login('local','local-password-123','192.0.2.5')['token'];deny(fn()=>$s->federatedLogin($c,array_replace($claims,['sub'=>'new-sub','email'=>'local@example.org'])),'email collision does not link local identity');
 ok($s->federatedLogin($c,array_replace($claims,['groups'=>[]]))['status']==='invalid'&&$s->current($b['token'])===null,'group removal denies and revokes');
 $a=$s->federatedLogin($c,$claims);ok($s->withWorkspace($a['token'],$oid,$w['id'],fn($ctx,$w)=>$w)===null,'workspace deny survives group removal and rejoin');$s->setEnabled($ot,$uid,false);ok($s->current($a['token'])===null&&$s->federatedLogin($c,$claims)['status']==='invalid','disabled federated identity cannot re-provision');$s->setEnabled($ot,$uid,true);
 putenv('BOOK_OIDC_REQUIRED=1');ok($s->current($lt)===null&&$s->login('local','local-password-123','192.0.2.6')['status']==='invalid'&&$s->current($ot)!==null,'required SSO revokes local member sessions, owner emergency access preserved');
 $a=$s->federatedLogin($c,$claims);putenv('BOOK_OIDC_CLIENT_SECRET=rotated');ok($s->current($a['token'])===null,'policy/secret change revokes federated sessions');
 $copy=new IdentityStore($root.'/copy');$copy->restore($s->backup());$backup=$copy->backup();ok($backup['users'][$uid]['oidc']['subject']==='subject-1'&&!isset($backup['oidc_flows']),'backup preserves link but excludes temporary flows');
}finally{putenv('BOOK_OIDC_REQUIRED');putenv('BOOK_OIDC_CONFIG');putenv('BOOK_OIDC_CLIENT_SECRET');clean($root);}
