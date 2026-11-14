<?php
declare(strict_types=1);
// The storage/navigation tests intentionally require no Markdown vendor package.
require dirname(__DIR__).'/core/Update/OperationFiles.php';
require dirname(__DIR__).'/src/BookStore.php';
require dirname(__DIR__).'/src/PageTree.php';
require dirname(__DIR__).'/src/Markdown.php';
require dirname(__DIR__).'/src/PageTreeView.php';
use CajeerBook\{BookStore,PageTree,PageTreeView,Markdown};
function check(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function reject(callable $fn,string $name): void {try{$fn();}catch(InvalidArgumentException|DomainException){check(true,$name);return;}throw new RuntimeException('Accepted: '.$name);}
function page(string $title,int $order,bool $published=true): array {$p=['title'=>$title,'order'=>$order,'section'=>'Guide','body'=>$title,'updated'=>'2026-11-14','media'=>[]];return ['revision'=>1,'draft'=>$p,'published'=>$published?$p:null];}
$dir=sys_get_temp_dir().'/book-tree-'.bin2hex(random_bytes(8));$store=new BookStore($dir);
try{
 $store->change(function(&$db){$db=['schema'=>1,'pages'=>[
    'book/1.0/start'=>page('Start',10), 'book/1.0/guide'=>page('Guide',20),
    'book/1.0/guide/install'=>page('Install',30),'book/1.0/guide/advanced/tuning'=>page('Tuning',40),
    'book/1.0/guide/private'=>page('Private',50,false),'book/1.0/reference'=>page('Reference',60),
    'book/2.0/guide'=>page('Other version',10),
 ]];});
 $db=$store->read();$old=$db;$id=PageTree::id('book/1.0/guide',$db['pages']['book/1.0/guide']);
 $nodes=PageTree::nodes(PageTree::drafts($db));check(!$nodes['book/1.0/guide/advanced']['page']&&$nodes['book/1.0/guide']['children']===['book/1.0/guide/install','book/1.0/guide/advanced','book/1.0/guide/private'],'legacy paths build real and implicit nested nodes');
 check(array_keys(BookStore::published($db))===['book/1.0/start','book/1.0/guide','book/1.0/guide/install','book/1.0/guide/advanced/tuning','book/1.0/reference','book/2.0/guide'],'preorder public traversal and version isolation');
 $move=function($source,$target,$position,$slug='',$duplicate=false,$token=null)use($store){$token??=PageTree::token($store->read());$store->change(fn(&$db)=>PageTree::move($db,$source,$target,$position,$slug,$token,$duplicate));};
 $publish=function($scope='book/1.0',$token=null)use($store){$token??=PageTree::token($store->read());$store->change(fn(&$db)=>PageTree::publish($db,$scope,$token));};
 reject(fn()=>$move('book/1.0/guide','book/1.0/guide/install','inside'),'reject descendant cycle');
 reject(fn()=>$move('book/1.0/guide','book/2.0/guide','inside'),'reject cross-version move');
 reject(fn()=>$move('book/1.0/guide','','root','reference'),'reject occupied branch');
 reject(fn()=>$move('book/1.0/guide','','root','../bad'),'reject unsafe slug');
 reject(fn()=>$move('book/1.0/guide','','root','',false,str_repeat('0',64)),'reject stale tree token');
 check($store->read()===$old,'rejected transactions leave bytes unchanged');
 $move('book/1.0/guide','book/1.0/reference','inside','manual');$db=$store->read();$new='book/1.0/reference/manual';
 check(!isset($db['pages']['book/1.0/guide'])&&isset($db['pages'][$new.'/advanced/tuning'],$db['pages'][$new.'/private']),'whole branch moved including implicit folders and draft');
 check(PageTree::id($new,$db['pages'][$new])===$id&&$db['pages'][$new]['revision']>1,'identity preserved and old revision invalidated');
 check(BookStore::published($db)===BookStore::published($old),'public paths, order and body unchanged until publish tree');
 check(PageTree::redirect($db,'book/1.0/guide')===null&&PageTree::draftRedirect($db,'book/1.0/guide')===$new,'private editor redirect only before publication');
 check(PageTree::reserved($db,'book/1.0/guide','other-id'),'former draft URL cannot be reused by another identity');
 check($db['pages'][$new]['draft']['link_base']==='book/1.0/guide','relative reference context retained');
 $store->change(function(&$db)use($new){$db['pages'][$new]['draft']['body']='Unpublished edit';$db['pages'][$new]['revision']++;});
 reject(fn()=>$publish('book/1.0',PageTree::token($db)),'concurrent draft save invalidates tree token');
 reject(fn()=>$publish('book/9.9'),'unknown version publication rejected');
 $publish();$db=$store->read();$public=BookStore::published($db);
 check(!isset($public['book/1.0/guide'])&&isset($public[$new],$public[$new.'/install'])&&!isset($public[$new.'/private']),'publish paths only for already published pages');
 check($public[$new]['body']==='Guide'&&$db['pages'][$new]['draft']['body']==='Unpublished edit','tree publication never exposes draft body');
 check(PageTree::redirect($db,'book/1.0/guide')===$new&&PageTree::redirect($db,'book/1.0/guide/install')===$new.'/install','old published paths resolve directly');
 check(PageTree::redirect($db,'book/1.0/guide/private')===null,'no public redirect for private path');
 $simulated=$db;$simulated['pages'][$new.'/private']['published']=$simulated['pages'][$new.'/private']['draft'];$simulated['pages'][$new.'/private']['published_key']=$new.'/private';
 check(PageTree::rewriteLink('/docs/book/1.0/guide/private',PageTree::linkMap($simulated))==='/docs/'.$new.'/private'&&PageTree::redirect($simulated,'book/1.0/guide/private')===null,'links to moved formerly private pages resolve without exposing old routes');
 check(PageTree::rewriteLink('/docs/book/1.0/guide/install#anchor',PageTree::linkMap($db))==='/docs/'.$new.'/install#anchor','published relative link target preserved');
 check(PageTree::rewriteLink('/docs/book/1.0/guide/advanced/../install?q=1#anchor',PageTree::linkMap($db))==='/docs/'.$new.'/install?q=1#anchor','relative dot segments and URL suffix preserved');
 $url=new ReflectionMethod(Markdown::class,'safeUrl');check($url->invoke(null,'install',false,$new,'book/1.0/guide')==='/docs/book/1.0/install','original relative base used');
 check($url->invoke(null,'?q=1',false,$new,'book/1.0/guide')==='/docs/'.$new.'?q=1','query-only links use current public path');
 $move($new,'book/1.0/start','before','manual');$publish();$db=$store->read();$final='book/1.0/manual';
 check(PageTree::redirect($db,'book/1.0/guide')===$final&&PageTree::redirect($db,$new)===$final,'redirects follow repeated moves without chains');
 check(array_key_first(BookStore::published($db))===$final,'reorder before root reflected in published preorder');
 $move($final,'book/1.0/reference','after','copy',true);$db=$store->read();$copy='book/1.0/copy';
 check(isset($db['pages'][$copy.'/install'],$db['pages'][$copy.'/advanced/tuning'])&&$db['pages'][$copy]['published']===null&&$db['pages'][$copy]['revision']===1&&PageTree::id($copy,$db['pages'][$copy])!==$id,'duplicate branch has new identities and drafts only');
 $move($copy.'/advanced','book/1.0/start','inside','advanced');$db=$store->read();check(isset($db['pages']['book/1.0/start/advanced/tuning'])&&!isset($db['pages'][$copy.'/advanced/tuning']),'implicit folder branch can move');
 $store->copyVersion('book','1.0','3.0');$db=$store->read();check(isset($db['pages']['book/3.0/manual'])&&$db['pages']['book/3.0/manual']['published']===null&&$db['pages']['book/3.0/manual']['id']!==$id,'version copy uses published hierarchy and new identities');
 check(isset($db['redirects']['book/3.0/guide'])&&PageTree::redirect($db,'book/3.0/guide')===null,'version copy reserves inherited aliases without exposing drafts');
 $store->remove($final,$db['pages'][$final]['revision'],true);$db=$store->read();check(PageTree::redirect($db,'book/1.0/guide')===null&&!isset(BookStore::published($db)[$final]),'unpublish revokes redirects and page');
 $store->remove($final,$db['pages'][$final]['revision'],false);$db=$store->read();check(PageTree::reserved($db,$final,'other-id')&&PageTree::redirect($db,'book/1.0/guide')===null,'delete keeps URL reservation, no dangling redirect');
 PageTree::validate($db);check(true,'new metadata validates for portable backup');
 $bad=$db;$bad['redirects']['book/1.0/../bad']=$id;reject(fn()=>PageTree::validate($bad),'backup rejects invalid redirect path');
 $bad=$db;$bad['pages']['book/1.0/start']['id']=$bad['pages']['book/1.0/reference']['id'];reject(fn()=>PageTree::validate($bad),'backup rejects duplicate identity');
 $html=PageTreeView::studio($db,'token','book/1.0');check(str_contains($html,'draggable="true"')&&str_contains($html,'data-drop-position="inside"')&&str_contains($html,'name="position"'),'server rendered drag targets and no-JS form');
 check(PageTreeView::newUnder($db,'book/1.0/start')===['book','1.0','start/new-page'],'new child path prefilled');
 $nav=PageTreeView::reader(BookStore::published($db),'book/1.0/manual/install');check(str_contains($nav,'<details open>')&&str_contains($nav,'aria-current="page"')&&!str_contains($nav,'Private'),'nested Reader navigation excludes drafts');
 check((new BookStore($dir))->read()===$db,'tree persists across store reopen');
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);}
