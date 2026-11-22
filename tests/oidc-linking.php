<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{IdentityStore,Oidc};
function verifyIt(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $callback,string $name):void{try{$callback();}catch(DomainException|InvalidArgumentException|RuntimeException){verifyIt(true,$name);return;}throw new RuntimeException('Unexpected success: '.$name);}
function cleanTree(string $root):void{foreach(new DirectoryIterator($root) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())cleanTree($f->getPathname());else unlink($f->getPathname());}rmdir($root);}
$root=sys_get_temp_dir().'/book-oidc-linking-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $s=new IdentityStore($root);$owner=$s->initialize('owner','Owner','owner@example.org','owner-password-123');$ot=$s->login('owner','owner-password-123','192.0.2.1')['token'];
    $org=$s->createOrganization($ot,'Project','project');$id=$org['id'];$u=$s->createUser($ot,'localuser','Local User','local@example.org','local-password-123');$lt=$s->login('localuser','local-password-123','192.0.2.2')['token'];
    $invite=$s->mutateOrganization($ot,$id,$s->organization($ot,$id)['revision'],'invite',['username'=>'localuser']);
    verifyIt($s->acceptInvitation($invite['token'],$lt,'192.0.2.2')['status']==='ok','local user joins organization by invitation');
    putenv('BOOK_SKIF4ER_CLIENT_ID=book-104');putenv('BOOK_SKIF4ER_CLIENT_SECRET=confidential-skif');putenv('BOOK_SKIF4ER_REDIRECT_URI=https://book.example.org/auth/oidc/callback');putenv('BOOK_SKIF4ER_ORGANIZATION='.$id);putenv('BOOK_SKIF4ER_DEFAULT_ROLE=viewer');
    putenv('BOOK_OIDC_PROVIDERS='.json_encode(['work'=>['issuer'=>'https://work.example.org/realm','client_id'=>'book-work','redirect_uri'=>'https://book.example.org/auth/oidc/callback','organization'=>$id,'label'=>'Work ID','groups'=>['editors'=>'editor'],'default_role'=>'viewer']]));putenv('BOOK_OIDC_WORK_CLIENT_SECRET=confidential-work');
    $configs=Oidc::configs();verifyIt(count($configs)===2&&isset($configs['skif4er'],$configs['work'])&&Oidc::config()===null,'multiple providers isolated by id, issuer and client');
    $private=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$key=openssl_pkey_get_details($private);
    $jwks=['keys'=>[['kty'=>'RSA','kid'=>'test','use'=>'sig','n'=>Oidc::b64($key['rsa']['n']),'e'=>Oidc::b64($key['rsa']['e'])]]];
    $sign=function(array $claims)use($private):string{$head=Oidc::b64(json_encode(['alg'=>'RS256','kid'=>'test']));$payload=Oidc::b64(json_encode($claims));openssl_sign($head.'.'.$payload,$signature,$private,OPENSSL_ALGO_SHA256);return $head.'.'.$payload.'.'.Oidc::b64($signature);};
    $claims=function(array $config,array $flow,string $subject,string $role='viewer'):array{return ['iss'=>$config['issuer'],'aud'=>$config['client_id'],'sub'=>$subject,'nonce'=>$flow['nonce'],'iat'=>time(),'exp'=>time()+1800,'name'=>'Verified User','email'=>'local@example.org','email_verified'=>true,'groups'=>$role==='editor'?['editors']:[]];};
    rejects(fn()=>$s->beginOidc($configs['skif4er'],bin2hex(random_bytes(32)),'192.0.2.2',$lt,'wrong-password'),'password proof mandatory for linking');
    rejects(fn()=>$s->beginOidc($configs['skif4er'],bin2hex(random_bytes(32)),'192.0.2.1',$ot,'owner-password-123'),'owner cannot link externally');
    foreach(['skif4er','work'] as $provider){
        $c=$configs[$provider];$browser=bin2hex(random_bytes(32));$flow=$s->beginOidc($c,$browser,'192.0.2.2',$lt,'local-password-123');
        verifyIt($s->oidcFlowProvider($flow['state'],$browser)===$provider,'flow resolves '.$provider.' only through browser-bound state');
        verifyIt($s->oidcFlowProvider($flow['state'],bin2hex(random_bytes(32)))===null,'unrelated browser cannot resolve flow');
        $f=$s->consumeOidc($c,$flow['state'],$browser);
        verifyIt($f!==null&&$s->consumeOidc($c,$flow['state'],$browser)===null,'link callback cannot be replayed');
        $raw=$claims($c,$f,'account-'.$provider,$provider==='work'?'editor':'viewer');
        rejects(fn()=>Oidc::verify($sign(array_replace($raw,['iss'=>'https://attacker.example'])),$jwks,$c,$f['nonce'],time()),'wrong issuer rejected on link');
        $verified=Oidc::verify($sign($raw),$jwks,$c,$f['nonce'],time());
        rejects(fn()=>$s->completeOidcLink($c,$verified,$f,bin2hex(random_bytes(32))),'link requires original logged-in session');
        $s->completeOidcLink($c,$verified,$f,$provider==='skif4er'?'':$lt);
        verifyIt(in_array($provider,$s->linkedProviders($lt),true),'linked '.$provider.' to existing local account');
    }
    verifyIt(count($s->linkedProviders($lt))===2&&$s->current($lt)['user']['id']===$u['id'],'multiple links preserve original account and local session');
    $backup=$s->backup();verifyIt(count($backup['users'][$u['id']]['oidc_links'])===2&&!isset($backup['oidc_flows'],$backup['sessions']),'backup retains link metadata, not OIDC transactions or tokens');
    rejects(fn()=>$s->mutateOrganization($ot,$id,$s->organization($ot,$id)['revision'],'role',['user'=>$u['id'],'role'=>'admin']),'linked accounts cannot be elevated to organization admin');
    $claimsLogin=function(string $which,string $sid)use($configs):array{$c=$configs[$which];return ['iss'=>$c['issuer'],'aud'=>$c['client_id'],'sub'=>'account-'.$which,'iat'=>time(),'exp'=>time()+1800,'sid'=>$sid,'groups'=>$which==='work'?['editors']:[]];};
    $a=$s->federatedLogin($configs['skif4er'],$claimsLogin('skif4er','sid-1'));
    verifyIt($a['status']==='ok'&&$a['user']['id']===$u['id']&&$s->current($a['token'])!==null,'linked SkiF4er ID issues actual Book session');
    $b=$s->federatedLogin($configs['work'],$claimsLogin('work','sid-2'));
    verifyIt($b['status']==='ok'&&$b['user']['id']===$u['id']&&$s->current($b['token'])!==null&&$s->organization($b['token'],$id)['role']==='viewer','work provider editor claim cannot elevate local viewer');
    $s->federatedLogout($configs['skif4er'],['sid'=>'sid-1','sub'=>'account-skif4er','iat'=>time()]);
    verifyIt($s->current($a['token'])===null&&$s->current($b['token'])!==null&&$s->current($lt)!==null,'provider SID logout affects only matching provider');
    $s->federatedLogout($configs['work'],['sub'=>'account-work','iat'=>time()]);
    verifyIt($s->current($b['token'])===null&&$s->current($lt)!==null,'provider subject logout does not kill local session');
    rejects(fn()=>$s->unlinkOidc($lt,'work','wrong-password'),'unlink requires local password proof');
    $s->unlinkOidc($lt,'work','local-password-123');
    verifyIt($s->linkedProviders($lt)===['skif4er'],'unlink removes only named provider');
    verifyIt($s->federatedLogin($configs['work'],$claimsLogin('work','sid-3'))['status']==='ok','unlinked subject is not associated with old account');
    $s->unlinkOidc($lt,'skif4er','local-password-123');
    verifyIt($s->linkedProviders($lt)===[]&&$s->login('localuser','local-password-123','192.0.2.2')['status']==='ok','last unlink preserves local password account');
    verifyIt($s->current($lt)!==null,'local session survives unlink');
}finally{
    foreach(['BOOK_SKIF4ER_CLIENT_ID','BOOK_SKIF4ER_CLIENT_SECRET','BOOK_SKIF4ER_REDIRECT_URI','BOOK_SKIF4ER_ORGANIZATION','BOOK_SKIF4ER_DEFAULT_ROLE','BOOK_OIDC_PROVIDERS','BOOK_OIDC_WORK_CLIENT_SECRET'] as $v)putenv($v);
    cleanTree($root);
}
