<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Update/OperationFiles.php';
require dirname(__DIR__).'/src/IdentityStore.php';
require dirname(__DIR__).'/src/BookStore.php';
require dirname(__DIR__).'/src/WorkspaceUrls.php';
require dirname(__DIR__).'/src/MediaStore.php';
require dirname(__DIR__).'/src/PageTree.php';
require dirname(__DIR__).'/src/PageTreeView.php';
require dirname(__DIR__).'/src/Markdown.php';
use CajeerBook\{IdentityStore,Workspaces,WorkspaceUrls,BookStore,MediaStore,PageTree};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException|RuntimeException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $dir):void{foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/book-workspaces-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $s=new IdentityStore($root);$a=$s->initialize('admin','Admin','admin@example.com','password-admin-123');$at=$s->login('admin','password-admin-123','192.0.2.1')['token'];
 $b=$s->createUser($at,'writer','Writer','writer@example.com','password-writer-123');$bt=$s->login('writer','password-writer-123','192.0.2.2')['token'];
 $o=$s->createOrganization($at,'Org A','org-a');$other=$s->createOrganization($bt,'Org B','org-b');$oid=$o['id'];$bid=$other['id'];
 $x=$s->createWorkspace($at,$oid,'Workspace X','shared');$y=$s->createWorkspace($bt,$bid,'Workspace Y','shared');$xi=$x['id'];$yi=$y['id'];
 $xd=Workspaces::directory($root,$x);$yd=Workspaces::directory($root,$y);$xs=new BookStore($xd,false);$ys=new BookStore($yd,false);
 ok($xd!==$yd&&$xs->read()['pages']===[]&&$ys->read()['pages']===[],'new spaces are independent and empty');
 deny(fn()=>$s->createWorkspace($at,$oid,'Duplicate','SHARED'),'unique slug inside organization');
 deny(fn()=>$s->createWorkspace($at,$bid,'Unauthorized','unauthorized'),'installation owner cannot manage other organization');
 ok($s->withWorkspace($at,$oid,$xi,fn($c,$w)=>$w['owner'])===true&&$s->withWorkspace($at,$bid,$yi,fn($c,$w)=>$w)===null,'scoped access excludes installation owner bypass');
 ok($s->withWorkspace($at,$oid,$yi,fn($c,$w)=>$w)===null,'wrong organization/workspace combination denied');
 $xs->createBook('guide','X guide');$ys->createBook('guide','Y guide');$xb=BookStore::books($xs->read())['guide'];$yb=BookStore::books($ys->read())['guide'];
 ok($xb['title']==='X guide'&&$yb['title']==='Y guide'&&$xb['id']!==$yb['id'],'same book slug independent stable IDs');
 deny(fn()=>$xs->createBook('guide','Duplicate'),'duplicate book denied');
 $file=$root.'/upload.txt';file_put_contents($file,'workspace test attachment');$xm=new MediaStore($xd,$xs);$ym=new MediaStore($yd,$ys);$m=$xm->upload($file,'test.txt','')['item'];$n=$ym->upload($file,'test.txt','')['item'];
 ok($m['id']!==$n['id']&&$xm->open($n['id'],true)===null&&$ym->open($m['id'],true)===null,'media ID and deduplication scoped');
 ok($xm->open($m['id'],false)===null,'draft media not public');
 $s->mutateWorkspace($at,$xi,1,'rename','Renamed','renamed');ok($s->workspaces($at)[0]['id']===$xi&&Workspaces::directory($root,$s->workspaces($at)[0])===$xd,'rename preserves route and data location');
 deny(fn()=>$s->mutateWorkspace($at,$xi,1,'archive','',''),'stale workspace update rejected');
 $s->mutateWorkspace($at,$xi,2,'archive','','');ok($s->withWorkspace($at,$oid,$xi,fn($c,$w)=>$w)===null,'archive denies content access');$s->mutateWorkspace($at,$xi,3,'restore','','');
 ok($s->withWorkspace($at,$oid,$xi,fn($c,$w)=>$w['owner'])===true&&BookStore::books($xs->read())['guide']['id']===$xb['id'],'restore preserves books');
 $inv=$s->mutateOrganization($at,$oid,1,'invite',['username'=>'writer']);$s->acceptInvitation($inv['token'],$bt,'192.0.2.2');
 ok($s->withWorkspace($bt,$oid,$xi,fn($c,$w)=>$w['owner'])===false,'member read access only');
 $s->mutateOrganization($at,$oid,3,'transfer',['user'=>$b['id']]);ok($s->withWorkspace($at,$oid,$xi,fn($c,$w)=>$w['owner'])===false&&$s->withWorkspace($bt,$oid,$xi,fn($c,$w)=>$w['owner'])===true,'ownership transfer immediately changes content authority');
 $s->mutateOrganization($bt,$oid,4,'remove',['user'=>$a['id']]);ok($s->withWorkspace($at,$oid,$xi,fn($c,$w)=>$w)===null,'membership removal immediately revokes access');
 $s->mutateOrganization($bt,$oid,5,'archive',[]);ok($s->withWorkspace($bt,$oid,$xi,fn($c,$w)=>$w)===null,'organization archive blocks access');
 deny(fn()=>$s->mutateOrganization($bt,$oid,6,'delete',['confirm'=>'org-a']),'cannot delete organization with workspace data');
 $legacyOrg=$s->createOrganization($at,'Legacy Org','legacy-org');$legacy=$s->createWorkspace($at,$legacyOrg['id'],'Legacy','legacy',true);ok(Workspaces::directory($root,$legacy)===$root&&Workspaces::prefix($legacy)===''&&$s->withLegacyStudio($at,fn($c,$allowed)=>$allowed),'legacy adoption preserves root and owner');
 deny(fn()=>$s->createWorkspace($at,$legacyOrg['id'],'Again','again',true),'legacy adoption once only');
 $backup=$s->backup();ok(count($backup['workspaces'])===3&&!isset($backup['sessions']),'identity backup includes registry but no sessions');
 $restored=new IdentityStore($root.'/restored');$restored->restore($backup);ok(count($restored->operatorWorkspaces())===3,'registry restored with same IDs');
 deny(fn()=>(new BookStore(Workspaces::directory($root.'/restored',$x),false))->read(),'missing scoped content fails closed rather than showing seed');
 $prefix=Workspaces::prefix($x);WorkspaceUrls::set($prefix);ok(WorkspaceUrls::url('/studio?edit=guide')===$prefix.'/studio?edit=guide'&&WorkspaceUrls::markdown('/docs/guide/1.0/../../../media/'.$m['id'])===$prefix.'/media/'.$m['id'],'scope URL and relative media normalization');
 ok(str_contains(CajeerBook\PageTreeView::list($xs->read(),''),'href="'.$prefix.'/studio/tree"'),'server-generated tree links are scoped');
 ok(WorkspaceUrls::url('/assets/book.js')==='/assets/book.js'&&WorkspaceUrls::url('/login')==='/login','global assets and identity routes unchanged');WorkspaceUrls::set('');
 // Revocation/archival cannot interleave with an authorized content transaction.
 $worker='require $argv[1]."/core/Update/OperationFiles.php";require $argv[1]."/src/IdentityStore.php";$s=new CajeerBook\\IdentityStore($argv[2]);echo "started\\n";flush();$s->mutateWorkspace($argv[3],$argv[4],1,"archive","","");echo "done\\n";';
 $proc=null;$pipes=[];$s->withWorkspace($bt,$bid,$yi,function($ctx,$w)use(&$proc,&$pipes,$worker,$root,$bt,$yi){
     $proc=proc_open([PHP_BINARY,'-r',$worker,dirname(__DIR__),$root,$bt,$yi],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Worker unavailable');fclose($pipes[0]);
     ok(trim(fgets($pipes[1]))==='started','parallel workspace writer started');usleep(150000);ok(proc_get_status($proc)['running'],'identity lock holds through content operation');
 });
 $output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($proc);ok($status===0&&trim($output)==='done'&&$s->withWorkspace($bt,$bid,$yi,fn($c,$w)=>$w)===null,'parallel archive commits after content operation and blocks later access');
 $s->checkWorkspaceStores($root);ok(true,'readiness validates all registered content stores');
}finally{clean($root);}
