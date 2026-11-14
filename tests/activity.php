<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{ActivityJournal,BookStore,IdentityStore,TeamView,Permissions,Workspaces,MediaStore,PageTree};
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function reject(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException|CajeerBook\OrganizationAccessException){check(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $path):void{if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($path);}
$dir=sys_get_temp_dir().'/book-team-'.bin2hex(random_bytes(8));
try{
 $i=new IdentityStore($dir);$owner=$i->initialize('admin','Admin','admin@example.org','owner-password-123');$o=$i->login('admin','owner-password-123','192.0.2.1');$ot=$o['token'];
 $writer=$i->createUser($ot,'writer','Writer','writer@example.org','writer-password-123');$manager=$i->createUser($ot,'manager','Manager','manager@example.org','manager-password-123');$outsider=$i->createUser($ot,'outside','Outside','outside@example.org','outside-password-123');
 $wt=$i->login('writer','writer-password-123','192.0.2.2')['token'];$mt=$i->login('manager','manager-password-123','192.0.2.3')['token'];$xt=$i->login('outside','outside-password-123','192.0.2.4')['token'];
 $org=$i->createOrganization($ot,'Team <safe>','team-safe');$oid=$org['id'];$rev=fn()=>$i->organization($ot,$oid)['revision'];
 $invite=$i->mutateOrganization($ot,$oid,$rev(),'invite',['username'=>'writer'])['token'];$it=$i->team($wt);$e=reset($it['inbox']);check($e['type']==='organization.invite'&&$e['recipients']===[$writer['id']],'existing-user invitation notification');
 check(!str_contains(json_encode($it),$invite)&&!str_contains(json_encode($it),hash('sha256',$invite)),'notification contains no invitation credential');
 check($i->team($xt)['inbox']===[]&&$i->team($xt)['audit']===[],'outsider has no organization notifications or audit');
 reject(fn()=>$i->team($xt,$e['id'],$i->current($xt)['session']['csrf']),'foreign notification receipt denied');
 $i->team($wt,$e['id'],$i->current($wt)['session']['csrf']);check(ActivityJournal::unread($i->team($wt)['inbox'],$writer['id'])===0,'read receipt durable per user');
 reject(fn()=>$i->team($wt,'all','bad'),'global read requires CSRF under lock');
 $i->acceptInvitation($invite,$wt,'192.0.2.2');check($i->team($wt)['inbox']===[],'consumed invitation disappears from inbox');
 $mInvite=$i->mutateOrganization($ot,$oid,$rev(),'invite',['username'=>'manager'])['token'];$i->acceptInvitation($mInvite,$mt,'192.0.2.3');
 $i->mutateOrganization($ot,$oid,$rev(),'role',['user'=>$writer['id'],'role'=>'editor']);$i->mutateOrganization($ot,$oid,$rev(),'role',['user'=>$manager['id'],'role'=>'admin']);
 check(count($i->team($wt)['inbox'])===1&&$i->team($wt)['audit']===[],'editor role notification without administrative audit');
 $w=$i->createWorkspace($ot,$oid,'Engineering','engineering');$wid=$w['id'];$folder=Workspaces::directory($dir,$w);$s=new BookStore($folder,false);
 $s->setHistoryActor($writer);$key='guide/1.0/start';$pid=str_repeat('f',32);$p=['title'=>'Draft <private>','section'=>'Guide','order'=>10,'body'=>'secret body','updated'=>gmdate('c'),'media'=>[]];
 $s->change(fn(&$d)=>$d['pages'][$key]=['id'=>$pid,'draft'=>$p,'published'=>null,'revision'=>1],'save');
 $db=$s->read();check(count($db['activity'])===2&&reset($db['activity'])['actor']['id']===$writer['id']&&!str_contains(json_encode($db['activity']),'secret body'),'real save captures actor, not body');
 $s->change(function(&$d)use($key){$d['pages'][$key]['revision']++;$d['pages'][$key]['draft']['updated']=gmdate('c');},'save');check(count($s->read()['activity'])===2,'identical autosave does not flood journal');
 $before=$s->read();reject(fn()=>$s->change(function(&$d){$d['pages']=[];throw new DomainException('rejected');},'delete'),'rejected write rolls back');check($s->read()===$before,'rejected mutation generates no event');
 $users=[$owner['id']=>$owner+['role'=>'owner'],$manager['id']=>$manager+['role'=>'admin'],$writer['id']=>$writer+['role'=>'editor']];
 $rid=$s->review($writer,$users,'submit',['kind'=>'page','target'=>$key,'expected'=>'2','reviewer'=>$manager['id'],'note'=>'secret discussion']);
 $db=$s->read();$notify=ActivityJournal::inbox($db['activity'],$manager['id']);$event=$notify[0];check(count($notify)===1&&$event['type']==='review.in_review'&&$event['target']===$rid,'submission notification assigned reviewer only');
 check(!str_contains(json_encode($db['activity']),'secret discussion'),'discussion body absent from journal');
 $s->review($owner,$users,'comment',['id'=>$rid,'revision'=>'1','note'=>'Independent comment']);$db=$s->read();$shared=end($db['activity']);check(count($shared['recipients'])===2&&$shared['type']==='review.comment','third-party comment notifies both participants');
 $s->change(fn(&$d)=>ActivityJournal::mark($d,$writer['id'],$shared['id'],$d['activity']));check(!in_array($manager['id'],$s->read()['activity'][$shared['id']]['read'],true),'one recipient cannot clear another receipt');
 $s->change(fn(&$d)=>ActivityJournal::mark($d,$manager['id'],$shared['id'],$d['activity']));$db=$s->read();

 check(TeamView::summary($db,$manager['id'])===['unread'=>1,'tasks'=>1,'drafts'=>0],'review task summary uses live persisted reviews');
 reject(fn()=>$s->change(fn(&$d)=>ActivityJournal::mark($d,$writer['id'],$event['id'],$d['activity'])),'cannot mark another reviewer notification');
 $s->change(fn(&$d)=>ActivityJournal::mark($d,$manager['id'],'all',$d['activity']));check(TeamView::summary($s->read(),$manager['id'])['unread']===0,'read all persists without closing task');
 $s->review($manager,$users,'changes',['id'=>$rid,'revision'=>'2','note'=>'Fix private issue']);$db=$s->read();check(TeamView::summary($db,$writer['id'])['tasks']===1&&ActivityJournal::inbox($db['activity'],$writer['id'])[0]['type']==='review.changes_requested','changes requested notifies author');
 $rid2=$s->review($writer,$users,'submit',['kind'=>'page','target'=>$key,'expected'=>'2','reviewer'=>$manager['id']]);$s->review($manager,$users,'approve',['id'=>$rid2,'revision'=>'1']);$db=$s->read();check(TeamView::summary($db,$writer['id'])['tasks']===1&&TeamView::summary($db,$manager['id'])['tasks']===1,'resubmission replaces old task; approved task awaits publication');
 $s->review($manager,$users,'publish',['id'=>$rid2,'revision'=>'2']);check(TeamView::summary($s->read(),$writer['id'])['tasks']===0,'publication closes task');
 $html=TeamView::workspace($s->read(),$i->current($wt),'editor',[]);check(str_contains($html,'Draft &lt;private&gt;')&&!str_contains($html,'Draft <private>')&&!str_contains($html,'secret body')&&!str_contains($html,'Publication and access audit'),'safe dashboard markup and editor lacks audit');
 check(str_contains(TeamView::workspace($s->read(),$i->current($mt),'admin',[]),'Publication and access audit'),'admin sees scoped publication audit');
 $i->assignWorkspaceRole($ot,$wid,1,$writer['id'],'none');check($i->team($wt)['workspaces']===[]&&$i->team($wt)['inbox']!==[],'scope revoked immediately, organization-level notification remains');
 $i->mutateOrganization($ot,$oid,$rev(),'remove',['user'=>$writer['id']]);check($i->team($wt)['inbox']===[],'revoked membership hides previous notifications');
 $backup=$i->backup();check(isset($backup['activity'])&&!str_contains(json_encode($backup),$ot),'identity backup preserves audit but not session token');
 $restored=new IdentityStore($dir.'/restore');$restored->restore($backup);$rt=$restored->login('admin','owner-password-123','192.0.2.5')['token'];check(count($restored->team($rt)['audit'])>1,'restored identity retains journal');
 $bad=$s->read();$id=array_key_first($bad['activity']);$bad['activity'][$id]['read']=[$outsider['id']];reject(fn()=>ActivityJournal::validate($bad),'invalid receipt rejected on validation');
 $bounded=['pages'=>[]];for($n=0;$n<1100;$n++)ActivityJournal::record($bounded,$owner,'page.save','guide/1.0/start',$pid);ActivityJournal::trim($bounded,BookStore::MAX_DB_BYTES,true);ActivityJournal::validate($bounded);check(count($bounded['activity'])<1100,'bounded retention prunes oldest activity');
 check(Permissions::studio('viewer','/studio/team','GET','')===false&&Permissions::studio('editor','/studio/team','POST','read')&& !Permissions::studio('editor','/studio/team','POST','anything'),'team routes deny by default');
}finally{clean($dir);}
