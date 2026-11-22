<?php
declare(strict_types=1);
/** Real Ed25519 verification and fake bounded HTTPS transport: no live GitHub dependency. */
require_once dirname(__DIR__).'/src/DatabaseMigrations.php';
require_once dirname(__DIR__).'/src/UpdateCenter.php';
use CajeerBook\UpdateCenter;
function ok(bool $value,string $label):void{if(!$value)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function fails(Closure $run,string $label):void{try{$run();}catch(Throwable){echo 'PASS '.$label."\n";return;}throw new RuntimeException('FAIL '.$label);}
$root=sys_get_temp_dir().'/book-update-113-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);mkdir($root.'/data',0700);
file_put_contents($root.'/VERSION',"1.1.2\n");
$keypair=sodium_crypto_sign_keypair();$private=sodium_crypto_sign_secretkey($keypair);$public=sodium_crypto_sign_publickey($keypair);
$der=hex2bin('302a300506032b6570032100').$public;
$keyfile=$root.'/pinned.pem';file_put_contents($keyfile,"-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64)."-----END PUBLIC KEY-----\n");
$repo='CajeerTeam/CajeerEngineBook';$version='1.1.3';
$names=['source.zip','deployment.zip','gate-evidence.json','SHA256SUMS',
'book-sbom.spdx.json','book-provenance.json','book-build-metadata.json','book-scan.json','book-smoke.log',
'postgres-pitr-sbom.spdx.json','postgres-pitr-provenance.json','postgres-pitr-build-metadata.json','postgres-pitr-scan.json','postgres-pitr-smoke.log',
'qualification.json','qualification-lifecycle.json','qualification-soak.json','qualification-candidate.json'];
$hash=str_repeat('a',64);$assets=[];$signed=[];
foreach($names as $name){$signed[$name]=['sha256'=>$hash,'size'=>12];$assets[]=['name'=>$name,'size'=>12,'state'=>'uploaded','digest'=>'sha256:'.$hash];}
$manifest=['format'=>1,'product'=>'CajeerEngineBook','repository'=>$repo,'version'=>$version,
'commit'=>str_repeat('b',40),'platform'=>'linux/amd64','schema_version'=>7,'signing_key_sha256'=>hash('sha256',$der),
'images'=>['book'=>'ghcr.io/cajeerteam/cajeerenginebook@sha256:'.str_repeat('c',64),
'postgres-pitr'=>'ghcr.io/cajeerteam/cajeerenginebook-postgres-pitr@sha256:'.str_repeat('d',64)],'artifacts'=>$signed];
$body=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$sig=sodium_crypto_sign_detached($body,$private);
$assets[]=['name'=>'release.json','size'=>strlen($body),'state'=>'uploaded','digest'=>'sha256:'.hash('sha256',$body)];
$assets[]=['name'=>'release.json.sig','size'=>64,'state'=>'uploaded','digest'=>'sha256:'.hash('sha256',$sig)];
foreach(['source.zip.sig','deployment.zip.sig'] as $name)$assets[]=['name'=>$name,'size'=>64,'state'=>'uploaded'];
$release=['tag_name'=>'v1.1.3','draft'=>false,'prerelease'=>false,'published_at'=>'2026-11-22T10:00:00Z',
'name'=>'Signed update','body'=>'Signed release notes','assets'=>$assets];
$base='https://github.com/'.$repo.'/releases/download/v1.1.3/';
$api='https://api.github.com/repos/'.$repo.'/releases?per_page=100&page=1';
$map=[$api=>json_encode([$release],JSON_THROW_ON_ERROR),$base.'release.json'=>$body,$base.'release.json.sig'=>$sig];
$transport=static function(string $url,int $cap)use(&$map):string {if(!isset($map[$url]))throw new RuntimeException('Unexpected network address');return $map[$url];};
$center=new UpdateCenter($root,$root.'/data',$transport,$keyfile);
try{
    $r=$center->check();ok($r['status']==='available'&&$r['candidate']['version']==='1.1.3','Valid GitHub release discovered and authenticated');
    ok($r['candidate']['manifest_sha256']===hash('sha256',$body),'Signed manifest digest exposed');
    ok($r['candidate']['requires_schema_upgrade']===false,'Schema compatibility recognized');
    $center->save($r,false);$center->save($r,true);
    ok($center->cached()['candidate']['version']==='1.1.3'&&$center->cached(true)['prepared']===true,'Atomic candidate metadata state persisted');
    $stored=fileperms($root.'/data/update-center/prepared.json')&0777;ok($stored===0600,'Prepared metadata not world-readable');
    $map[$base.'release.json.sig']=str_repeat('x',64);
    ok($center->check()['status']==='rejected','Tampered signature rejected');
    $map[$base.'release.json.sig']=$sig;
    $map[$base.'release.json']=$body.' ';ok($center->check()['status']==='rejected','Altered signed manifest rejected');
    $map[$base.'release.json']=$body;
    $r1=$release;array_pop($r1['assets']);$map[$api]=json_encode([$r1]);
    ok($center->check()['status']==='rejected','Missing assets rejected');
    $r1=$release;$r1['assets'][0]['digest']='sha256:'.str_repeat('0',64);$map[$api]=json_encode([$r1]);
    ok($center->check()['status']==='rejected','Published artifact digest mismatch rejected');
    ok(UpdateCenter::allowedDownloadUrl('https://release-assets.githubusercontent.com/objects/123?token=abc',true),'Pinned GitHub release CDN allowed');
    ok(!UpdateCenter::allowedDownloadUrl('http://release-assets.githubusercontent.com/o',true)&&
       !UpdateCenter::allowedDownloadUrl('https://evil.example/o',true)&&
       !UpdateCenter::allowedDownloadUrl('https://github.com:444/o',true)&&
       !UpdateCenter::allowedDownloadUrl('https://api.github.com/private',true),'Untrusted redirects rejected');
    $map[$api]='[]';ok($center->check()['status']==='up_to_date','Empty release inventory is not an update');
    $r1=$release;$r1['tag_name']='v1.1.2';$map[$api]=json_encode([$r1]);
    ok($center->check()['status']==='up_to_date','Downgrade and replay ignored');
    $r1=$release;$r1['tag_name']='v1.1.4-rc.1';$r1['prerelease']=true;$map[$api]=json_encode([$r1]);
    ok($center->check('beta')['status']==='unsupported_prerelease','Prerelease never offered for installation');
    $map[$api]='{"message":"API rate limit"}';fails(fn()=>$center->check(),'GitHub API errors do not masquerade as up to date');
    unlink($keyfile);fails(fn()=>$center->check(),'Missing pinned key fails closed');
    fails(fn()=>$center->save(['status'=>'rejected'],true),'Rejected manifest cannot be prepared');
    $bad=$root.'/data/update-center/latest.json';unlink($bad);symlink($root.'/VERSION',$bad);
    fails(fn()=>$center->save($r,false),'Symlink update-state write rejected');
    echo "Update Center 1.1.3 checks completed.\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($root);
}
