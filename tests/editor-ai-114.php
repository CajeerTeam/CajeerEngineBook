<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{BookStore,EditorAssistant,EditorAssistantView,ReviewWorkflow,PageTree,Permissions};
// The CI suite uses the real CommonMark parser; a minimal tokenizer permits
// transaction/HTTP regression tests on environments without Composer vendor/.
if(!class_exists('League\CommonMark\Parser\MarkdownParser')){
    final class EditorAiTestMarkdown {
        public static function validate(string $s):void {if(strlen($s)>262144||!preg_match('//u',$s))throw new InvalidArgumentException('Invalid Markdown');}
        public static function mediaReferences(string $md,string $key):array {preg_match_all('~/media/([a-f0-9]{32})~',$md,$m);return array_values(array_unique($m[1]));}
        public static function e(string $s):string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    }
    class_alias(EditorAiTestMarkdown::class,'CajeerBook\Markdown');
}

function ok114(bool $b,string $desc):void {if(!$b)throw new RuntimeException($desc);echo "PASS $desc\n";}
function reject114(callable $fn,string $desc):void {try{$fn();}catch(Throwable){ok114(true,$desc);return;}throw new RuntimeException('Accepted: '.$desc);}
function rm114(string $path):void {if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $f){if($f->isDot())continue;$p=$f->getPathname();if($f->isDir()&&!$f->isLink())rm114($p);else unlink($p);}rmdir($path);}
$dir=sys_get_temp_dir().'/book-editor-ai-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException($errstr);
$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',__DIR__.'/fixtures',__DIR__.'/fixtures/editor-ai-api.php'],[0=>['pipe','r'],1=>['file',$dir.'/api.out','a'],2=>['file',$dir.'/api.err','a']],$pipes,dirname(__DIR__));if(!is_resource($proc))throw new RuntimeException('Fixture failed');fclose($pipes[0]);
try{
    putenv('BOOK_EDITOR_AI_ENABLED=1');putenv('BOOK_CHAT_API_KEY=book-test-key-1234567890');putenv('BOOK_CHAT_TEST_LOOPBACK=1');putenv('BOOK_CHAT_API_URL=http://127.0.0.1:'.$port.'/v1/responses');
    for($i=0;$i<30;$i++){if(@fsockopen('127.0.0.1',$port,$errno,$str,0.1)){break;}usleep(100000);}
    $actor=['id'=>str_repeat('a',32),'username'=>'editor'];$reviewer=['id'=>str_repeat('b',32),'username'=>'reviewer'];$users=[$actor['id']=>$actor+['role'=>'editor'],$reviewer['id']=>$reviewer+['role'=>'admin']];
    $store=new BookStore($dir.'/store',false,true);$store->initialize();$store->setHistoryActor($actor);
    $key=$store->save(['book'=>'manual','version'=>'1.0','path'=>'install','title'=>'Install','section'=>'Intro','order'=>10,'body'=>'# Old\n\nTODO: expand','revision'=>0],false);
    ok114($key==='manual/1.0/install','portable draft created');
    $db=$store->read();$gaps=EditorAssistant::analyzeGaps($db,'manual/1.0');ok114(count($gaps['issues'])===1&&str_contains($gaps['advice'],'Prioritize'),'AI gap analysis with verified local findings');
    $explanation=EditorAssistant::explainMigration('ERROR: migration version 6 failed; password=SECRET api_key=TOPSECRET');ok114(str_contains($explanation,'migration schema'),'migration explanation from real API');
    reject114(fn()=>EditorAssistant::explainMigration(str_repeat('x',4001)),'oversized migration error denied');
    reject114(fn()=>EditorAssistant::gaps($db,'../private'),'invalid scope denied');
    $p=EditorAssistant::suggest($db,$key,'Improve setup instructions');ok114(str_contains($p['proposal'],'# Corrected')&&$p['revision']===1,'valid markdown produced by model');
    $signed=EditorAssistant::seal($p,'test-secret',$actor['id']);$accepted=EditorAssistant::unseal($signed['payload'],$signed['signature'],'test-secret',$actor['id']);ok114($accepted['markdown']===$p['proposal'],'signed immutable proposal verified');
    reject114(fn()=>EditorAssistant::unseal($signed['payload'],$signed['signature'],'test-secret',$reviewer['id']),'another actor cannot apply proposal');
    reject114(fn()=>EditorAssistant::unseal($signed['payload'],'x'.$signed['signature'],'test-secret',$actor['id']),'tampered signature rejected');
    reject114(fn()=> $store->submitAiProposal($accepted,$actor,$users,$actor['id']),'self-review rejected atomically');
    ok114($store->read()['pages'][$key]['revision']===1,'failed review rolls back draft');
    $rid=$store->submitAiProposal($accepted,$actor,$users,$reviewer['id']);$db=$store->read();
    ok114($db['pages'][$key]['revision']===2&&$db['pages'][$key]['ai_review_required']===true&&$db['reviews'][$rid]['status']==='in_review','proposal and review committed together');
    reject114(fn()=> $store->save(['book'=>'manual','version'=>'1.0','path'=>'install','title'=>'Install','section'=>'Intro','order'=>10,'body'=>'# Try direct publish','revision'=>2],true),'direct publication of AI draft blocked');
    reject114(fn()=> $store->submitAiProposal($accepted,$actor,$users,$reviewer['id']),'stale AI proposal rejected');
    $store->review($reviewer,$users,'approve',['id'=>$rid,'revision'=>'1','note'=>'Reviewed markdown and confirmed']);
    $db=$store->read();ok114($db['reviews'][$rid]['status']==='approved','independent reviewer approves');
    $store->review($reviewer,$users,'publish',['id'=>$rid,'revision'=>'2','note'=>'Publish']);$db=$store->read();
    ok114($db['reviews'][$rid]['status']==='published'&&!isset($db['pages'][$key]['ai_review_required'])&&str_contains($db['pages'][$key]['published']['body'],'Corrected'),'approved snapshot published and review requirement cleared');
    $view=EditorAssistantView::render($db,'csrf',$actor,$users,['page'=>$key],['type'=>'migration','answer'=>'<script>alert(1)</script>']);
    ok114(!str_contains($view,'<script>')&&str_contains($view,'&lt;script&gt;'),'AI output escaped in HTML');
    ok114(Permissions::studio('editor','/studio/assistant','POST','suggest')&&!Permissions::studio('viewer','/studio/assistant','GET',''),'AI page requires editor permission');
}finally{proc_terminate($proc);proc_close($proc);rm114($dir);foreach(['BOOK_EDITOR_AI_ENABLED','BOOK_CHAT_API_KEY','BOOK_CHAT_TEST_LOOPBACK','BOOK_CHAT_API_URL'] as $v)putenv($v);}
