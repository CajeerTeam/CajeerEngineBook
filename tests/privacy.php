<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Update/OperationFiles.php';
require dirname(__DIR__).'/src/IdentityStore.php';
require dirname(__DIR__).'/src/BookStore.php';
require dirname(__DIR__).'/src/PageTree.php';
require dirname(__DIR__).'/src/MediaStore.php';
use CajeerBook\{IdentityStore,BookStore,Visibility,Permissions,PageTree,MediaStore};
use CajeerEngine\Update\OperationFiles as Files;
function check(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
function reject(callable $fn,string $label):void{try{$fn();}catch(DomainException|InvalidArgumentException|RuntimeException){check(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $dir):void{foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/book-privacy-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $identity=new IdentityStore($root);$owner=$identity->initialize('owner','Owner','owner@example.org','owner-password-123');$token=$identity->login('owner','owner-password-123','192.0.2.1')['token'];
 $org=$identity->createOrganization($token,'Org','org')['id'];$w=$identity->createWorkspace($token,$org,'Private','private');$legacy=$identity->createWorkspace($token,$org,'Legacy','legacy',true);
 $file=$root.'/identity/identity.json';$data=Files::read($file);$data['schema']=3;foreach($data['workspaces'] as &$item)unset($item['visibility']);unset($item);Files::json($file,$data);
 $migrated=$identity->backup();check($migrated['schema']===4&&$migrated['workspaces'][$w['id']]['visibility']==='private'&&$migrated['workspaces'][$legacy['id']]['visibility']==='public','migration retains 0.2.4 private scoped and public legacy defaults');
 check($identity->current($token)['user']['id']===$owner['id'],'migration preserves sessions and owner ID');
 check($identity->withWorkspace('',$org,$w['id'],fn($ctx,$ws)=>$ws,true)===null,'anonymous private workspace denied');
 $identity->mutateWorkspace($token,$w['id'],1,'visibility','public','');
 check($identity->withWorkspace('',$org,$w['id'],fn($ctx,$ws)=>$ws['role']??null,true)==='none','anonymous public Reader has no authoring privileges');
 check($identity->withWorkspace('',$org,$w['id'],fn($ctx,$ws)=>$ws)===null,'public Reader does not grant Studio access');
 reject(fn()=>$identity->mutateWorkspace($token,$w['id'],1,'visibility','private',''),'stale visibility revision denied');
 reject(fn()=>$identity->mutateWorkspace($token,$w['id'],2,'visibility','invalid',''),'invalid workspace visibility denied');
 $saved=$identity->backup();$restored=new IdentityStore($root.'/restored');$restored->restore($saved);check($restored->backup()['workspaces']===$saved['workspaces'],'backup retains workspace policies');
 $invalid=$saved;$invalid['workspaces'][$legacy['id']]['visibility']='unrecognized';reject(fn()=>(new IdentityStore($root.'/invalid'))->restore($invalid),'invalid visibility in backup rejected');
 $missing=$saved;unset($missing['workspaces'][$legacy['id']]['visibility']);reject(fn()=>(new IdentityStore($root.'/missing'))->restore($missing),'missing visibility in current-schema backup rejected');
 $store=new BookStore($root.'/workspaces/'.$org.'/'.$w['id'],false);$media=new MediaStore($root.'/workspaces/'.$org.'/'.$w['id'],$store);
 $store->createBook('public','Public');$store->createBook('private','Private');$store->createBook('inherited','Inherited');
 $source=$root.'/file.txt';file_put_contents($source,'private binary content');$private=$media->upload($source,'file.txt')['item']['id'];file_put_contents($source,'shared binary content');$shared=$media->upload($source,'shared.txt')['item']['id'];file_put_contents($source,'draft only content');$draft=$media->upload($source,'draft.txt')['item']['id'];
 $store->change(function(&$db)use($private,$shared,$draft){foreach(['public'=>[$shared],'private'=>[$private,$shared],'inherited'=>[]] as $book=>$ids){$p=['title'=>$book,'section'=>'Guide','order'=>10,'body'=>'text','updated'=>gmdate('c'),'media'=>$ids];$key=$book.'/1.0/start';$db['pages'][$key]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>$p,'published_key'=>$key];}$db['pages']['public/1.0/start']['draft']['media'][]=$draft;$id=$db['pages']['private/1.0/start']['id'];$db['redirects']['private/1.0/old']=$id;});
 $store->setBookVisibility('private','private',1);$store->setBookVisibility('public','public',1);$db=$store->read();
 check(count(Visibility::pages($db,'public','none'))===2&&!isset(Visibility::pages($db,'public','none')['private/1.0/start']),'public projection excludes private books');
 check(Visibility::pages($db,'private','none')===[],'private workspace ceiling overrides explicit public book');
 foreach(Permissions::ROLES as $role)check(count(Visibility::pages($db,'private',$role))===3,'authorized '.$role.' reads published books');
 check(!isset(Visibility::links($db,Visibility::pages($db,'public','none'))['private/1.0/old']),'link map hides private redirect target');
 $public=fn($d,$id)=>Visibility::media($d,$id,'public','none');$viewer=fn($d,$id)=>Visibility::media($d,$id,'public','viewer');
 check($media->open($private,false,$public)===null&&$media->open($draft,false,$viewer)===null,'media authorization excludes private references and drafts');
 foreach([[$shared,$public],[$private,$viewer]] as [$id,$policy]){$result=$media->open($id,false,$policy);check($result!==null,'authorized immutable media stream');fclose($result[1]);}
 $store->setBookVisibility('public','private',2);check($media->open($shared,false,$public)===null,'shared media revoked when last public reference is hidden');
 reject(fn()=>$store->setBookVisibility('public','public',2),'stale book visibility revision denied');
 reject(fn()=>$store->setBookVisibility('public','unknown',3),'invalid book visibility rejected');
 reject(fn()=>$store->change(function(&$db){$db['books']['public']['visibility']=null;}),'invalid persisted visibility rejected before commit');
 check($store->read()['books']['public']['visibility']==='private','invalid mutation did not change persistent policy');
 check(Permissions::studio('editor','/studio/books','POST','visibility')===false&&Permissions::studio('admin','/studio/books','POST','visibility')===true,'RBAC separately authorizes book visibility');
 echo "OK\n";
}finally{clean($root);}
