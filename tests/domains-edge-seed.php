<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{IdentityStore,BookStore,Workspaces,CustomDomains};
$root=getenv('BOOK_DATA_DIR');$s=new IdentityStore($root);$s->initialize('owner','Owner','owner@example.org','test-owner-password-123');$auth=$s->login('owner','test-owner-password-123','192.0.2.1');$t=$auth['token'];$csrf=$auth['session']['csrf'];$o=$s->createOrganization($t,'Edge Test','edge-test');$w=$s->createWorkspace($t,$o['id'],'Edge Docs','edge-docs');$id=$w['id'];$s->mutateWorkspace($t,$id,1,'visibility','public','');$host='docs.example.net';$s->mutateDomain($t,$csrf,$id,$host,'add',2);
$store=new BookStore(Workspaces::directory($root,$w),false);$store->createBook('guide','Edge Guide');$store->change(function(&$db){$p=['title'=>'Edge Public','section'=>'Guide','order'=>10,'body'=>'Edge public content','updated'=>gmdate('c'),'media'=>[]];$key='guide/1/start';$db['pages'][$key]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>$p,'published_key'=>$key];},'save');
$r=$s->backup()['workspaces'][$id]['domains'][$host];echo json_encode(['id'=>$id,'token'=>$t,'csrf'=>$csrf,'dns'=>CustomDomains::dnsName($host),'proof'=>CustomDomains::challenge($r)],JSON_THROW_ON_ERROR).PHP_EOL;
