<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{Oidc,IdentityStore};
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
function fails(callable $run,string $message):void{try{$run();}catch(Throwable){check(true,$message);return;}throw new RuntimeException('Expected failure: '.$message);}
function cleanDirectory(string $dir):void{foreach(new DirectoryIterator($dir) as $entry){if($entry->isDot())continue;$name=$entry->getPathname();if($entry->isDir())cleanDirectory($name);else unlink($name);}rmdir($dir);}
$root=sys_get_temp_dir().'/book-skif4er-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $store=new IdentityStore($root);
    $store->initialize('owner','Owner','owner@example.org','secure-local-password');
    $owner=$store->login('owner','secure-local-password','192.0.2.7')['token'];
    $org=$store->createOrganization($owner,'SkiF4er users','skif4er');
    $secret=$root.'/secret';file_put_contents($secret,"test-client-secret\n");chmod($secret,0600);
    putenv('BOOK_SKIF4ER_CLIENT_ID=book-prod');
    putenv('BOOK_SKIF4ER_CLIENT_SECRET_FILE='.$secret);
    putenv('BOOK_SKIF4ER_REDIRECT_URI=https://book.example.org/auth/oidc/callback');
    putenv('BOOK_SKIF4ER_ORGANIZATION='.$org['id']);
    putenv('BOOK_SKIF4ER_DEFAULT_ROLE=viewer');
    $config=Oidc::config();
    check($config['issuer']==='https://id.skif4er.ru/realms/skif4er'&&$config['post_logout_redirect_uri']==='https://book.example.org/login'&&$config['client_id']==='book-prod','pinned SkiF4er issuer and exact RP logout redirect');
    check($config['backchannel_logout']===true&&$config['secret']==='test-client-secret','confidential client and mandatory backchannel support');
    $now=time();$browser=bin2hex(random_bytes(32));$flow=$store->beginOidc($config,$browser,'192.0.2.8');
    check($flow['status']==='ok'&&$store->consumeOidc($config,$flow['state'],$browser)['nonce']===$flow['nonce'],'server-side state, browser binding and PKCE verifier persist');
    $claims=['iss'=>$config['issuer'],'sub'=>'federated-123','aud'=>'book-prod','iat'=>$now,'exp'=>$now+1800,'sid'=>'keycloak-session-1','nonce'=>$flow['nonce'],'name'=>'External User','email'=>'external@example.org','email_verified'=>true];
    check(Oidc::role($config,$claims)==='viewer','explicit viewer onboarding does not require corporate group claim');
    $encrypted=Oidc::seal($config,'some-confidential-OIDC-token');
    check($encrypted!=='some-confidential-OIDC-token'&&Oidc::unseal($config,$encrypted)==='some-confidential-OIDC-token','server tokens encrypted and authenticated at rest');
    $changed=$config;$changed['secret']='different-key';fails(fn()=>Oidc::unseal($changed,$encrypted),'wrong secret cannot decrypt a stored token');
    $private=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    $details=openssl_pkey_get_details($private);
    $jwks=['keys'=>[['kty'=>'RSA','kid'=>'key-1','n'=>Oidc::b64($details['rsa']['n']),'e'=>Oidc::b64($details['rsa']['e']),'alg'=>'RS256','use'=>'sig']]];
    $signed=function(array $body)use($private):string{$data=Oidc::b64(json_encode(['alg'=>'RS256','kid'=>'key-1'])).'.'.Oidc::b64(json_encode($body));openssl_sign($data,$signature,$private,OPENSSL_ALGO_SHA256);return $data.'.'.Oidc::b64($signature);};
    $verified=Oidc::verify($signed($claims),$jwks,$config,$flow['nonce'],$now);
    check($verified===$claims,'signed SkiF4er issuer ID token passes RS256, audience and nonce verification');
    fails(fn()=>Oidc::verify($signed(array_replace($claims,['aud'=>'another-client'])),$jwks,$config,$flow['nonce'],$now),'wrong SkiF4er client audience denied');
    fails(fn()=>Oidc::verify($signed(array_replace($claims,['nonce'=>'spoofed'])),$jwks,$config,$flow['nonce'],$now),'wrong callback nonce denied');
    $result=$store->federatedLogin($config,$verified,['id_token'=>'test-id-token','access_token'=>'test-access-token','refresh_token'=>'test-refresh-token']);
    check($result['status']==='ok'&&$store->current($result['token'])!==null,'verified SkiF4er claims provision local authorized session');
    check($store->organization($result['token'],$org['id'])['role']==='viewer','JIT membership receives viewer access only');
    check(isset($result['session']['oidc_id_token'],$result['session']['oidc_access_token'],$result['session']['oidc_refresh_token'],$result['session']['oidc_sid']),'encrypted credentials and provider SID persist in shared session state');
    foreach($store->sessions($result['token']) as $s)check(!isset($s['oidc_id_token'],$s['oidc_access_token'],$s['oidc_refresh_token']),'session list does not expose token material');
    $backup=$store->backup();check(!isset($backup['sessions'])&&($backup['users'][$result['user']['id']]['oidc']['subject']??'')==='federated-123','backups retain identity link without tokens or session state');
    $store->federatedLogout($config,['sid'=>'different-session','iat'=>$now]);
    check($store->current($result['token'])!==null,'unrelated provider SID cannot revoke a different session');
    $store->federatedLogout($config,['sid'=>'keycloak-session-1','iat'=>$now]);
    check($store->current($result['token'])===null,'SID-specific provider logout revokes the matching local session');
    $again=$store->federatedLogin($config,$claims);
    check($again['status']==='ok'&&$store->current($again['token'])!==null,'a new authenticated login can recover after SID logout');
    $store->setEnabled($owner,$again['user']['id'],false);
    check($store->current($again['token'])===null&&$store->federatedLogin($config,$claims)['status']==='invalid','operator access revocation prevents new SkiF4er sessions');
    putenv('BOOK_SKIF4ER_DEFAULT_ROLE=none');fails(fn()=>Oidc::config(),'SkiF4er login is denied without explicit role policy');
    putenv('BOOK_SKIF4ER_DEFAULT_ROLE=viewer');
    $enterprise=['issuer'=>'https://company.example.org/realm','client_id'=>'book-work','redirect_uri'=>'https://book.example.org/auth/oidc/callback','organization'=>$org['id'],'label'=>'Company ID','groups'=>[],'default_role'=>'viewer'];
    putenv('BOOK_OIDC_CONFIG='.json_encode($enterprise));putenv('BOOK_OIDC_CLIENT_SECRET=another-confidential-secret');
    check(count(Oidc::configs())===2&&Oidc::config()===null&&Oidc::config('skif4er')['issuer']===$config['issuer']&&Oidc::config('enterprise')['issuer']===$enterprise['issuer'],'SkiF4er ID and enterprise OIDC can coexist without ambiguous defaults');
    putenv('BOOK_OIDC_CONFIG');putenv('BOOK_OIDC_CLIENT_SECRET');
    putenv('BOOK_SKIF4ER_REDIRECT_URI=https://other.example.org/callback');fails(fn()=>Oidc::config(),'invalid SkiF4er callback rejected');
}finally{
    foreach(['BOOK_SKIF4ER_CLIENT_ID','BOOK_SKIF4ER_CLIENT_SECRET_FILE','BOOK_SKIF4ER_REDIRECT_URI','BOOK_SKIF4ER_ORGANIZATION','BOOK_SKIF4ER_DEFAULT_ROLE','BOOK_OIDC_CONFIG','BOOK_OIDC_CLIENT_SECRET'] as $v)putenv($v);
    cleanDirectory($root);
}
