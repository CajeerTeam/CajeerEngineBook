<?php
declare(strict_types=1);
$storage=in_array('--storage-only',$argv,true);$pg=in_array('--postgres',$argv,true);
require __DIR__.'/'.($storage?'oidc-bootstrap.php':'../bootstrap.php');
use CajeerBook\{BookStore,Locale,PageTree,Search,Visibility,IdentityStore,PostgresContentStore,StaticExport,MediaStore};
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException|RuntimeException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$root=sys_get_temp_dir().'/book-lang-'.bin2hex(random_bytes(8));$dsn=getenv('BOOK_PG_DSN');$ns=bin2hex(random_bytes(16));putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_DSN');putenv('BOOK_PG_NAMESPACE='.$ns);
try{
 $s=new BookStore($root,false);$s->initialize();
 if($pg){if(!$dsn||$storage)throw new RuntimeException('Full PostgreSQL test required');(new IdentityStore($root))->initialize('owner','Owner','owner@example.org','password-owner-123');}
 $save=function(string $path,string $title,string $body,int $rev=0,bool $publish=false,array $extra=[],string $version='1',string $book='guide')use(&$s,$storage){
  $input=['book'=>$book,'version'=>$version,'path'=>$path,'title'=>$title,'section'=>'Guide','order'=>10,'body'=>$body,'revision'=>$rev]+$extra;
  if(!$storage)return $s->saveResult($input,$publish);
  // Storage-only: real transaction and publication; skip Markdown parser, unavailable without vendor.
  $key=BookStore::key($book,$version,$path);$s->change(function(&$db)use($key,$title,$body,$rev,$publish,$extra){$e=$db['pages'][$key]??['id'=>bin2hex(random_bytes(16)),'revision'=>0,'draft'=>null,'published'=>null];if($e['revision']!==$rev)throw new DomainException('stale');$p=['title'=>$title,'section'=>'Guide','order'=>10,'body'=>$body,'updated'=>gmdate('c'),'media'=>[]];if(isset($extra['translation_source']))$p+=Locale::attach($db,$key,$extra['locale'],$extra['translation_source'],$extra['source_digest']);elseif($e['draft'])$p+=Locale::metadata($e['draft']);elseif(isset($extra['locale']))$p['locale']=Locale::require($extra['locale']);$e['draft']=$p;if($publish)BookStore::publishSnapshot($db,$key,$e);$e['revision']++;$db['pages'][$key]=$e;},$publish?'publish':'save');return ['key'=>$key];
 };
 $save('start','Quasar','Quasar policies.',0,true);$key='guide/1/start';$ru='guide/1/ru/start';$db=$s->read();$id=$db['pages'][$key]['id'];$hash=Locale::digest($db['pages'][$key]['published']);$extra=['locale'=>'ru','translation_source'=>$id,'source_digest'=>$hash];
 ok(Locale::language($db['pages'][$key]['published'])==='en','legacy English default');
 $save('ru/start','Квазар','Quasar документация.',0,false,$extra);
 ok(Locale::variants(BookStore::published($s->read()),$key)===['en'=>$key],'unpublished translation absent');
 deny(fn()=>$save('ru/duplicate','Duplicate','text',0,false,$extra),'duplicate language rejected');
 deny(fn()=>$save('wrong','Wrong','text',0,false,array_replace($extra,['locale'=>'en'])),'source language rejected');
 deny(fn()=>$save('wrong','Wrong','text',0,false,$extra,'2'),'cross-version source rejected');
 deny(fn()=>$save('wrong','Wrong','text',0,false,array_replace($extra,['source_digest'=>str_repeat('a',64)])),'stale source digest rejected');
 $save('ru/start','Квазар','Quasar документация.',1,true);$db=$s->read();ok(Locale::variants(BookStore::published($db),$key)===['en'=>$key,'ru'=>$ru],'independent publication and stable group');
 ok(Search::projection($db)[$ru]['locale']==='ru'&&Search::projection($db)[$key]['locale']==='en','published index language');
 $ci=CajeerBook\CiPublishing::state($db,'guide/1');$media=new MediaStore($root,$s);CajeerBook\CiPublishing::verify($db,$ci,[$key=>['/l/ru/docs/'.$key],$ru=>['/l/en/docs/'.$ru]],$media);ok(true,'CI validates available localized links');deny(fn()=>CajeerBook\CiPublishing::verify($db,$ci,[$key=>['/l/ru/docs/guide/1/missing']],$media),'CI rejects missing localized target');
 $history=CajeerBook\RevisionHistory::entries($db,$db['pages'][$ru]['id']);ok(reset($history)['published']['translation_group']===$id,'history retains exact translation metadata');
 if($pg){putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($root);$s=new BookStore($root,false);ok(Locale::variants(BookStore::published($s->read()),$key)===['en'=>$key,'ru'=>$ru],'migration preserves language groups');PostgresContentStore::reindex($root);ok($s->searchInfo()['documents']===2,'language index rebuild verified');}
 if(!$storage){$search=fn($lang,$role='none')=>$s->search('public',$role,Search::options(['q'=>'quasar','locale'=>$lang]),true);ok(array_column($search('ru')['hits'],'key')===[$ru]&&$search('en')['total']===1&&$search('')['total']===2,'language search hits and count');ok(array_keys($search('ru')['pages'])===[$ru],'language navigation');}
 if(!$storage){$zpath=$root.'/bilingual.zip';(new StaticExport(dirname(__DIR__),$s,new MediaStore($root,$s)))->create($zpath,'public','guide/1');$z=new ZipArchive();if($z->open($zpath)!==true)throw new RuntimeException('ZIP');$html=$z->getFromName(StaticExport::pagePath($key));ok(str_contains($html,'hreflang="ru"')&&str_contains($html,'../p-ru/p-start/index.html'),'static language links point to actual relative variant');$z->close();}
 $save('start','Quasar','Changed original',1,false);ok(Locale::digest(Locale::source($s->read(),'guide/1',$id))===$hash,'draft source change does not stale translations');
 $save('start','Quasar','Changed original',2,true);$db=$s->read();$new=Locale::digest(Locale::source($db,'guide/1',$id));ok($new!==$hash&&$db['pages'][$ru]['published']['source_digest']===$hash,'publication marks translation outdated');
 deny(fn()=>$s->reviewTranslationSource($ru,2,$hash),'stale review rejected');$s->reviewTranslationSource($ru,2,$new);$db=$s->read();ok($db['pages'][$ru]['draft']['source_digest']===$new&&$db['pages'][$ru]['published']['source_digest']===$hash&&$db['pages'][$ru]['revision']===3,'source review updates draft and revision only');
 $save('ru/start','Квазар','Quasar новая документация.',3,true);$db=$s->read();ok($db['pages'][$ru]['published']['source_digest']===$new,'normal edit preserves metadata');
 $s->copyVersion('guide','1','2');$db=$s->read();ok($db['pages']['guide/2/ru/start']['draft']['translation_group']===$db['pages']['guide/2/start']['id']&&$db['pages']['guide/2/ru/start']['published']===null,'copy version remaps source identity');
 $s->change(function(&$db){PageTree::move($db,'guide/1/start','','root','renamed',PageTree::token($db));},'move');$s->change(function(&$db){PageTree::publish($db,'guide/1',PageTree::token($db));},'publish-tree');$db=$s->read();ok(Locale::variants(BookStore::published($db),$ru)['en']==='guide/1/renamed','source rename retains language links');
 $s->setBookVisibility('guide','private',1);ok(Locale::variants(Visibility::pages($s->read(),'public','none'),$ru)===[],'private variants unavailable');if(!$storage)ok($search('ru')['total']===0&&$search('ru','owner')['total']===1,'language search respects privacy');$s->setBookVisibility('guide','public',2);
 $s->remove('guide/1/renamed',$s->read()['pages']['guide/1/renamed']['revision'],true);$db=$s->read();ok(Locale::source($db,'guide/1',$id)===null&&Locale::variants(BookStore::published($db),$ru)===['ru'=>$ru],'unpublished original leaves independent translation');
 if(!$storage){$zpath=$root.'/site.zip';(new StaticExport(dirname(__DIR__),$s,new MediaStore($root,$s)))->create($zpath,'public','guide/1');$z=new ZipArchive();if($z->open($zpath)!==true)throw new RuntimeException('ZIP');ok(str_contains($z->getFromName(StaticExport::pagePath($ru)),'<html lang="ru"')&&str_contains($z->getFromName('assets/search-index.js'),'"locale":"ru"'),'static page and offline index retain locale');$z->close();}
 $save('standalone','Standalone','text',0,true,[],'3');$stand='guide/3/standalone';$s->setPageLocale($stand,1,'ru');$db=$s->read();ok(Locale::language($db['pages'][$stand]['draft'])==='ru'&&Locale::language($db['pages'][$stand]['published'])==='en','retag legacy draft without changing published locale');deny(fn()=>$s->setPageLocale($ru,4,'en'),'linked translation cannot be retagged');
 ok(CajeerBook\MarkdownImport::document("---\nlocale: ru\n---\n# Russian",'ru.md',10)['locale']==='ru','Markdown import locale front matter');
 deny(fn()=>Search::options(['locale'=>'../../bad']),'unsupported locale rejected');
 Locale::setUi('ru');ok(Locale::t('Search')==='Поиск'&&Locale::t('unknown')==='unknown','Russian catalog and English fallback');
}finally{if($pg&&$dsn){putenv('BOOK_PG_DSN='.$dsn);$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}clean($root);}
