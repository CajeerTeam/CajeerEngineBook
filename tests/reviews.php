<?php
declare(strict_types=1);
// Real filesystem transactions; --storage-only bypasses only parser-dependent seed/restore/preview.
$storage=in_array('--storage-only',$argv,true);
if($storage){foreach(['core/Update/OperationFiles.php','src/BookStore.php','src/PageTree.php','src/MediaStore.php','src/MediaBackup.php','src/Markdown.php','src/WorkspaceUrls.php','src/Permissions.php','src/OrganizationAccessException.php','src/ReviewView.php'] as $f)require_once dirname(__DIR__).'/'.$f;}else require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,PageTree,MediaStore,MediaBackup,ReviewWorkflow,ReviewView,RevisionHistory,Permissions,OrganizationAccessException};
function ok(bool $v,string $msg):void{if(!$v)throw new RuntimeException($msg);echo "PASS $msg\n";}
function reject(callable $f,string $msg,string $class=DomainException::class):void{try{$f();}catch(Throwable $e){if(!$e instanceof $class)throw $e;ok(true,$msg);return;}throw new RuntimeException('Unexpected success: '.$msg);}
function clean(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$dir=sys_get_temp_dir().'/book-review-'.bin2hex(random_bytes(8));$s=new BookStore($dir);$media=new MediaStore($dir,$s);
$users=[];foreach(['owner'=>'a','admin'=>'b','editor'=>'c','viewer'=>'d','other'=>'e'] as $role=>$char){$uid=str_repeat($char,32);$users[$uid]=['id'=>$uid,'username'=>$role,'role'=>$role==='other'?'admin':$role];}
[$owner,$admin,$editor,$viewer,$other]=array_values($users);$ownerId=$owner['id'];$adminId=$admin['id'];$editorId=$editor['id'];
$key='guide/1.0/start';$pageId=str_repeat('f',32);
try{
 $s->change(fn(&$d)=>$d['pages']=[]);file_put_contents($dir.'/file.txt','review attachment');$mid=$media->upload($dir.'/file.txt','file.txt')['item']['id'];
 $p=['title'=>'Original','section'=>'Guide','order'=>10,'body'=>'Original','updated'=>gmdate('c'),'media'=>[]];
 $s->setHistoryActor($owner);$s->change(fn(&$d)=>$d['pages'][$key]=['id'=>$pageId,'revision'=>1,'draft'=>$p,'published'=>$p],'publish');
 $draft='Reviewed <script>alert(1)</script>'."\n\n[File](/media/$mid)";
 $s->setHistoryActor($editor);$s->change(function(&$d)use($key,$draft,$mid){$d['pages'][$key]['draft']['body']=$draft;$d['pages'][$key]['draft']['media']=[$mid];$d['pages'][$key]['revision']++;},'save');
 $review=fn($actor,$action,$fields,$map=null)=>$s->review($actor,$map??$users,$action,$fields);
 $policy=fn($actor,$required,$rev)=>$review($actor,'policy',['required'=>$required,'revision'=>(string)$rev]);
 $submit=fn($actor,$reviewer,$kind='page',$target='guide/1.0/start',$expected=null)=>$review($actor,'submit',['kind'=>$kind,'target'=>$target,'expected'=>$expected??($kind==='page'?(string)$s->read()['pages'][$target]['revision']:PageTree::token($s->read())),'reviewer'=>$reviewer,'note'=>'Review the current saved state']);
 $decide=fn($actor,$id,$action,$note='',$map=null,$rev=null)=>$review($actor,$action,['id'=>$id,'revision'=>(string)($rev??$s->read()['reviews'][$id]['revision']),'note'=>$note],$map);
 ok(!ReviewWorkflow::required($s->read())&&$s->read()['schema']===1,'existing schema 1 remains direct by default');
 reject(fn()=>$policy($admin,'1',1),'admin cannot disable or enable owner policy',OrganizationAccessException::class);
 $policy($owner,'1',1);ok(ReviewWorkflow::required($s->read())&&$s->read()['schema']===2,'owner enables required policy; schema 2 prevents downgrade bypass');
 reject(fn()=>$policy($owner,'0',1),'stale policy revision rejected');
 $input=['book'=>'guide','version'=>'1.0','path'=>'start','title'=>'Bypass','body'=>'Bypass','section'=>'Guide','order'=>10,'revision'=>2];
 reject(fn()=>$s->saveResult($input,true),'direct save-and-publish blocked under store lock');
 reject(fn()=>$s->change(fn(&$d)=>PageTree::publish($d,'guide/1.0',PageTree::token($d)),'publish-tree'),'direct tree publish blocked');
 reject(fn()=>$submit($editor,$editorId),'self-review forbidden',OrganizationAccessException::class);
 reject(fn()=>$submit($owner,$editorId),'reviewer must be active Admin or Owner',OrganizationAccessException::class);
 reject(fn()=>$submit($viewer,$adminId),'viewer cannot submit',OrganizationAccessException::class);
 reject(fn()=>$submit($editor,$adminId,'page',$key,'1'),'stale saved revision cannot be submitted');
 $id=$submit($editor,$adminId);$r=$s->read()['reviews'][$id];ok($r['snapshot']['draft']['body']===$draft&&$r['snapshot']['published']['body']==='Original'&&$r['requester']['id']===$editorId&&$r['reviewer']['id']===$adminId,'immutable snapshot, requester, reviewer and baseline');
 $hash=$r['snapshot_hash'];reject(fn()=>$submit($editor,$ownerId),'duplicate active request rejected');
 reject(fn()=>$decide($owner,$id,'approve'),'unassigned Owner cannot approve',OrganizationAccessException::class);
 reject(fn()=>$decide($editor,$id,'approve'),'requester cannot approve',OrganizationAccessException::class);
 reject(fn()=>$decide($owner,$id,'publish'),'unapproved request cannot publish');
 $decide($editor,$id,'comment','<script>unsafe comment</script>');
 $view=ReviewView::render($s->read(),'csrf',$admin,$users,['id'=>$id]);ok(str_contains($view,'&lt;script&gt;unsafe comment&lt;/script&gt;')&&!str_contains($view,'<script>')&&str_contains($view,'diff-add')&&str_contains($view,'Request changes'),'server view escapes comments and source, shows diff and decisions');
 reject(fn()=>$decide($admin,$id,'changes',''),'change request requires reason',InvalidArgumentException::class);
 reject(fn()=>$decide($admin,$id,'approve','',null,1),'stale review revision rejected');
 $decide($admin,$id,'changes','Please update the installation command');ok($s->read()['reviews'][$id]['status']==='changes_requested'&&$s->read()['pages'][$key]['published']['body']==='Original','changes requested closes request without publication');
 reject(fn()=>$decide($admin,$id,'approve'),'closed decision cannot be overwritten');
 $id2=$submit($editor,$adminId);$decide($admin,$id2,'approve','Approved');
 $revoked=$users;$revoked[$adminId]['role']='viewer';reject(fn()=>$decide($owner,$id2,'publish','',$revoked),'revoked reviewer approval cannot publish',OrganizationAccessException::class);
 $s->setHistoryActor($editor);$s->change(function(&$d)use($key){$d['pages'][$key]['draft']['title']='Edited after approval';$d['pages'][$key]['revision']++;},'save');
 $d=$s->read();ok($d['reviews'][$id2]['status']==='stale'&&$d['reviews'][$id2]['snapshot']['draft']['title']==='Original'&&$d['reviews'][$id]['snapshot_hash']===$hash,'new edit invalidates approval and preserves old snapshot');
 reject(fn()=>$decide($owner,$id2,'publish'),'stale approved snapshot cannot publish');
 $id3=$submit($editor,$adminId);$decide($admin,$id3,'approve');$before=$s->read()['reviews'][$id3]['snapshot']['draft'];$decide($owner,$id3,'publish','Release');$d=$s->read();ok($d['reviews'][$id3]['status']==='published'&&$d['pages'][$key]['published']===$before&&$d['pages'][$key]['draft']===$before,'only exact approved snapshot published; draft retained');
 $history=RevisionHistory::entries($d,$pageId);ok(reset($history)['action']==='publish'&&reset($history)['actor']['id']===$ownerId&&str_contains(reset($history)['note'],$id3),'publication attributed and linked to review in history');
 reject(fn()=>$decide($owner,$id3,'publish'),'publication is single-use');
 // A no-op save does not disguise the last actual author.
 $s->setHistoryActor($admin);$s->change(function(&$d)use($key){$d['pages'][$key]['draft']['body']='Authored by reviewer';$d['pages'][$key]['draft']['media']=[];$d['pages'][$key]['revision']++;},'save');
 $s->setHistoryActor($editor);$s->change(function(&$d)use($key){$d['pages'][$key]['revision']++;$d['pages'][$key]['draft']['updated']=gmdate('c');},'save');
 reject(fn()=>$submit($editor,$adminId),'cannot launder reviewer authorship with a no-op save',OrganizationAccessException::class);
 $s->change(function(&$d)use($key){$d['pages'][$key]['draft']['title']='Another author changed the title';$d['pages'][$key]['revision']++;},'save');reject(fn()=>$submit($editor,$adminId),'prior unpublished contributor remains ineligible after another author edits',OrganizationAccessException::class);
 $id4=$submit($editor,$ownerId);$decide($owner,$id4,'reject','Not ready');ok($s->read()['reviews'][$id4]['status']==='rejected','rejection persisted');
 $id5=$submit($editor,$ownerId);$decide($editor,$id5,'cancel');ok($s->read()['reviews'][$id5]['status']==='cancelled','requester can cancel');
 // Tree review only changes navigation. Current unpublished body is not published.
 $s->setHistoryActor($editor);$s->change(fn(&$d)=>PageTree::move($d,$key,'','root','moved',PageTree::token($d)),'move');$moved='guide/1.0/moved';$tree=$submit($editor,$adminId,'tree','guide/1.0');
 $d=$s->read();ok($d['reviews'][$tree]['snapshot'][0]['from']===$key&&$d['reviews'][$tree]['snapshot'][0]['to']===$moved,'tree review freezes exact old/new public path and order');
 $decide($admin,$tree,'approve');$pub=$d['pages'][$moved]['published']['body'];$decide($owner,$tree,'publish');$d=$s->read();ok(PageTree::redirect($d,$key)===$moved&&$d['pages'][$moved]['published']['body']===$pub&&$d['pages'][$moved]['draft']['body']==='Authored by reviewer','tree review publishes only navigation, not draft body');
 $s->setHistoryActor($editor);$s->change(fn(&$d)=>PageTree::move($d,$moved,'','root','again',PageTree::token($d)),'move');$tree2=$submit($editor,$adminId,'tree','guide/1.0');$decide($admin,$tree2,'approve');
 $s->change(function(&$d){$d['pages']['guide/1.0/again']['revision']++;},'save');ok($s->read()['reviews'][$tree2]['status']==='stale','tree token changes invalidate approval');
 $again='guide/1.0/again';$s->setHistoryActor($owner);$s->remove($again,$s->read()['pages'][$again]['revision'],true);$d=$s->read();ok(!$media->open($mid,false)&&in_array($key.':review',MediaStore::references($d,$mid),true),'review media retained without public permission');
 reject(fn()=>$media->delete($mid,1),'retained review media cannot be deleted');
 reject(fn()=>$decide($admin,$id,'prune'),'only Owner can prune closed requests',OrganizationAccessException::class);
 $decide($owner,$id,'prune');ok(!isset($s->read()['reviews'][$id]),'owner can prune closed request');
 // First publication and two concurrent publishers compete for one approved request.
 $new='guide/1.0/new';$s->setHistoryActor($editor);$s->change(fn(&$d)=>$d['pages'][$new]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>['title'=>'New page','section'=>'Guide','order'=>42,'body'=>'First publication','updated'=>gmdate('c'),'media'=>[]],'published'=>null],'save');
 $newReview=$submit($editor,$adminId,'page',$new);$decide($admin,$newReview,'approve');$rev=$s->read()['reviews'][$newReview]['revision'];
 $code='foreach(["core/Update/OperationFiles.php","src/BookStore.php","src/PageTree.php","src/Permissions.php","src/OrganizationAccessException.php"] as $f)require_once $argv[1]."/".$f;$s=new CajeerBook\\BookStore($argv[2],false);$users=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);try{$s->review($users[$argv[4]],$users,"publish",["id"=>$argv[5],"revision"=>$argv[6]]);exit(0);}catch(DomainException $e){exit(10);}';
 $cmd=[PHP_BINARY,'-r',$code,dirname(__DIR__),$dir,json_encode($users,JSON_THROW_ON_ERROR),$ownerId,$newReview,(string)$rev];$procs=[];
 for($i=0;$i<2;$i++){$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Cannot start concurrent publisher');fclose($pipes[0]);$procs[]=[$proc,$pipes];}
 $codes=[];foreach($procs as [$proc,$pipes]){$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if(!in_array($exit,[0,10],true))throw new RuntimeException('Concurrent publisher failed: '.$output);$codes[]=$exit;}sort($codes);
 $d=$s->read();ok($codes===[0,10]&&$d['reviews'][$newReview]['status']==='published'&&$d['pages'][$new]['revision']===2&&$d['pages'][$new]['published']['body']==='First publication','concurrent publication commits once, first published page correct');
 // Copying or restoring a draft must not launder its known contributors.
 $source='guide/1.0/authored';$sourceId=bin2hex(random_bytes(16));$s->setHistoryActor($admin);
 $s->change(fn(&$d)=>$d['pages'][$source]=['id'=>$sourceId,'revision'=>1,'draft'=>['title'=>'Authored draft','section'=>'Guide','order'=>100,'body'=>'Unpublished author text','updated'=>gmdate('c'),'media'=>[]],'published'=>null],'save');
 $d=$s->read();$historical=reset($d['history']);$events=RevisionHistory::entries($d,$sourceId);$historical=reset($events);
 ok(in_array($adminId,$historical['review_authors'],true),'historical draft retains contributor provenance');
 $old=$historical;unset($old['review_authors']);ok(in_array($adminId,RevisionHistory::historicalContributors($d,$old,false),true),'older retained history reconstructs known contributor');
 $s->setHistoryActor($editor);$s->change(fn(&$d)=>PageTree::move($d,$source,'','root','authored-copy',PageTree::token($d),true),'duplicate');
 $copy='guide/1.0/authored-copy';$d=$s->read();ok(in_array($adminId,$d['pages'][$copy]['review_authors'],true)&&in_array($editorId,$d['pages'][$copy]['review_authors'],true),'duplicate preserves source and copying author');
 reject(fn()=>$submit($editor,$adminId,'page',$copy),'source author cannot approve duplicated draft',OrganizationAccessException::class);
 if(!$storage){$s->setHistoryActor($owner);$s->remove($source,$s->read()['pages'][$source]['revision'],false);$head=array_key_first(RevisionHistory::entries($s->read(),$sourceId));$s->setHistoryActor($editor);$s->restoreRevision($sourceId,$historical['id'],'draft',0,$head);reject(fn()=>$submit($editor,$adminId,'page',$source),'historical author cannot approve restored draft',OrganizationAccessException::class);}
 $db=$s->read();$bad=$db;$bad['reviews'][$id3]['snapshot']['draft']['body']='tampered';reject(fn()=>ReviewWorkflow::validate($bad),'tampered snapshot fails validation',InvalidArgumentException::class);
 $bad=$db;$bad['reviews'][$id3]['approved_by']=$editor;reject(fn()=>ReviewWorkflow::validate($bad),'forged approval fails validation',InvalidArgumentException::class);
 $tar=$dir.'/backup.tar';(new MediaBackup($dir,$s,$media))->create($tar);$t=new PharData($tar);$saved=json_decode($t['books.json']->getContent(),true,512,JSON_THROW_ON_ERROR);ok($saved['reviews']===$db['reviews']&&$saved['review_policy']===$db['review_policy']&&isset($t['media/'.$mid.'.blob']),'TAR contains exact reviews and related files');unset($t);
 if(!$storage){MediaBackup::restore($tar,$dir.'/restored');$restored=(new BookStore($dir.'/restored'))->read();ok($restored['reviews']===$db['reviews']&&ReviewWorkflow::required($restored),'full TAR restore preserves review state and snapshots');}
 ok(Permissions::studio('editor','/studio/reviews','POST','submit')&&!Permissions::studio('editor','/studio/reviews','POST','approve')&&!Permissions::studio('viewer','/studio/reviews','GET','')&&!Permissions::studio('admin','/studio/reviews','POST','policy')&&!Permissions::studio('owner','/studio/reviews','POST','unknown'),'review route RBAC deny by default');
}finally{clean($dir);}
