<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{BookStore,IdentityStore,Workspaces,MediaStore,PageTree,Locale,Visibility,PostgresContentStore as P,Search};
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$pg=in_array('--postgres',$argv,true);$dsn=getenv('BOOK_PG_DSN');if($pg&&!$dsn)throw new RuntimeException('PostgreSQL test requires BOOK_PG_DSN.');
$root=sys_get_temp_dir().'/book-reader-'.bin2hex(random_bytes(12));$ns=bin2hex(random_bytes(16));
putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');
try{
    $i=new IdentityStore($root);$owner=$i->initialize('owner','Owner','owner@example.org','password-owner-123');$token=$i->login('owner','password-owner-123','192.0.2.1')['token'];
    $org=$i->createOrganization($token,'Reader Org','reader-org');$w=$i->createWorkspace($token,$org['id'],'Reader','reader');$i->mutateWorkspace($token,$w['id'],1,'visibility','public','');
    $other=$i->createWorkspace($token,$org['id'],'Other','other');$dir=Workspaces::directory($root,$w);$s=new BookStore($dir,false);$s->setHistoryActor($owner);$m=new MediaStore($dir,$s);
    $file=$root.'/media.txt';file_put_contents($file,'Public media bytes');$mid=$m->upload($file,'public.txt','')['item']['id'];file_put_contents($file,'Private media bytes');$private=$m->upload($file,'private.txt','')['item']['id'];unlink($file);
    $id=str_repeat('a',32);$body='Public original [file](/media/'.$mid.')';$snapshot=fn($title,$text,$media=[])=>['title'=>$title,'section'=>'Guide','order'=>10,'body'=>$text,'updated'=>'2025-01-01T00:00:00+00:00','media'=>$media];
    $s->change(function(&$db)use($snapshot,$id,$body,$mid,$private){
        $db['books']=['guide'=>['slug'=>'guide','title'=>'Guide','id'=>str_repeat('b',32)],'secret'=>['slug'=>'secret','title'=>'Secret','id'=>str_repeat('c',32),'visibility'=>'private']];
        $original=$snapshot('Original',$body,[$mid]);$draft=$original;$draft['body']='UNPUBLISHED DRAFT';$draft['media']=[];
        $db['pages']['guide/1/new-draft']=['id'=>$id,'draft'=>$draft,'published'=>$original,'published_key'=>'guide/1/current','revision'=>2];
        $db['redirects']['guide/1/old']=$id;$db['retired_paths']['guide/1/retired']=$id;
        $translated=$snapshot('Перевод','Русский документ')+['locale'=>'ru','translation_group'=>$id,'source_digest'=>Locale::digest($original)];
        $db['pages']['guide/1/ru']=['id'=>str_repeat('d',32),'draft'=>$translated,'published'=>$translated,'revision'=>1];
        $p=$snapshot('Private','PRIVATE BODY [file](/media/'.$private.')',[$private]);$db['pages']['secret/1/private']=['draft'=>$p,'published'=>$p,'revision'=>1];
        $db['pages']['guide/1/unpublished']=['draft'=>$p,'published'=>null,'revision'=>1];
        $nul=$snapshot("NUL\0Title","NUL\0body and literal \\u0000");$db['pages']['guide/1/nul']=['draft'=>$nul,'published'=>$nul,'revision'=>1];
        $db['pages']['legacy/1/start']=['draft'=>$snapshot('Legacy','Implicit book'),'published'=>$snapshot('Legacy','Implicit book'),'revision'=>1];
        $db['redirects']['legacy/1/old']=PageTree::id('legacy/1/start',[]);
        for($j=0;$j<130;$j++){$key=sprintf('guide/1/page-%03d',$j);$p=$snapshot('Topic '.$j,str_repeat('Large document body. ',100).'batchmarker');$p['order']=$j;$db['pages'][$key]=['draft'=>$p,'published'=>$p,'revision'=>1];}
    });
    ok((new CajeerBook\StoreAudit($s,$m))->run()['reference_mismatches']===0,'fixture media index matches actual Markdown');
    $expected=$s->read();if($pg){putenv('BOOK_PG_DSN='.$dsn);P::migrate($root);$s=new BookStore($dir,false);$m=new MediaStore($dir,$s);ok($s->read()===$expected,'batch SQL import preserves exact JSON content, ordering and metadata');}
    $pages=Visibility::pages($expected,'public','none');
    foreach([['public','none'],['private','none'],['private','viewer']] as [$visibility,$role]){
        $all=Visibility::pages($expected,$visibility,$role);$navigation=$s->readerSnapshot($visibility,$role,'guide/1/current');$actual=Visibility::pages($navigation,$visibility,$role);
        ok(array_keys($actual)===array_keys($all),'Reader navigation ordering and visibility: '.$visibility.'/'.$role);
        foreach($all as $key=>$page){$found=$actual[$key];foreach(['title','section','order','id'] as $field)if($found[$field]!==$page[$field])throw new RuntimeException('Navigation metadata mismatch.');}
        if(isset($all['guide/1/current']))ok($actual['guide/1/current']===$all['guide/1/current']&&Locale::variants($actual,'guide/1/current')===Locale::variants($all,'guide/1/current')&&Visibility::links($navigation,$actual)===Visibility::links($expected,$all),'selected body, locale variants and link aliases preserved');
        $list=Visibility::pages($s->readerSnapshot($visibility,$role,null,true),$visibility,$role);$strip=fn($p)=>array_diff_key($p,array_flip(['body','link_base','playground','source_digest','translation_group']));
        ok(array_map($strip,$list)===array_map($strip,$all),'published list metadata equals full snapshot: '.$visibility.'/'.$role);
        foreach(['guide/1/current','guide/1/ru','secret/1/private','guide/1/unpublished','legacy/1/start','guide/1/nul'] as $key)ok($s->publishedPage($key,$visibility,$role)['page']===($all[$key]??null),'exact point lookup '.$visibility.'/'.$role.' '.$key);
    }
    ok($s->publishedPage('guide/1/old','public','none')['redirect']==='guide/1/current'&&$s->publishedPage('legacy/1/old','public','none')['redirect']==='legacy/1/start','stable ID redirect and legacy derived ID redirect');
    ok($s->publishedPage('guide/1/new-draft','public','none')['page']===null,'draft rename does not expose unpublished path');
    $opened=$m->open($mid,false,null,['public','none']);ok($opened!==null&&stream_get_contents($opened[1])==='Public media bytes','public media authorized and actual bytes opened');fclose($opened[1]);
    ok($m->open($private,false,null,['public','none'])===null&&$m->open($mid,false,null,['private','none'])===null,'private published and draft media denied');
    ok((new MediaStore(Workspaces::directory($root,$other),new BookStore(Workspaces::directory($root,$other),false)))->open($mid,false,null,['public','viewer'])===null,'media lookup is scoped to workspace');
    ok($i->withWorkspace('',$org['id'],$w['id'],fn($c,$w)=>$c===null&&$w['role']==='none',true),'anonymous registry grants only public role');
    ok($i->withWorkspace('',str_repeat('f',32),$w['id'],fn($c,$w)=>$w,true)===null&&$i->withWorkspace('',$org['id'],$other['id'],fn($c,$w)=>$w,true)===null,'wrong organization and private workspace denied');
    if($pg){
        $nav=$s->readerSnapshot('public','none','guide/1/current');$navPages=Visibility::pages($nav,'public','none');
        ok(!isset($navPages['guide/1/page-001']['body'])&&!isset($nav['history'])&&!isset($nav['ci'])&&!isset($nav['media'])&&strlen(json_encode($nav))<strlen(json_encode($expected))/4,'SQL navigation returns compact metadata, not sibling bodies/history/CI');
        $pdo=P::connect();$pdo->beginTransaction();$pdo->exec('SET LOCAL enable_seqscan=off');
        $q=$pdo->prepare("EXPLAIN SELECT record_key FROM cajeer_book.pages WHERE installation=? AND scope=? AND json_typeof(cajeer_book.reader_json(value)->'published')='object' AND coalesce(cajeer_book.reader_json(value)->>'published_key',record_key)=?");$q->execute([$ns,$org['id'].'/'.$w['id'],'guide/1/current']);$plan=implode(' ',$q->fetchAll(PDO::FETCH_COLUMN));$pdo->rollBack();ok(str_contains($plan,'pages_public_key_idx'),'published path index is usable by PostgreSQL planner');
        $raw=new P($ns,$org['id'].'/'.$w['id'],P::installationBinding($root)[1]);$profile=$raw->profilePublished('guide/1/current',$mid);ok(count($profile['page']['plans'])>=2&&$profile['navigation']['result_bytes']>$profile['page']['result_bytes'],'real EXPLAIN ANALYZE uses optimized read paths');
        ok($s->search('public','none',Search::options(['q'=>'batchmarker','limit'=>1]))['total']===130,'batched search writes retain every published document');
    }
    $s->setBookVisibility('guide','private',1);ok($s->publishedPage('guide/1/current','public','none')['page']===null&&$s->publishedPage('guide/1/old','public','none')['redirect']===null&&$m->open($mid,false,null,['public','none'])===null,'book privacy revokes page, redirect and media immediately');
    $s->setBookVisibility('guide','public',2);$s->remove('guide/1/new-draft',2,true);ok($s->publishedPage('guide/1/current','public','none')['page']===null&&$m->open($mid,false,null,['public','none'])===null,'unpublish revokes indexed point read and media');
    $i->mutateWorkspace($token,$w['id'],2,'visibility','private','');ok($i->withWorkspace('',$org['id'],$w['id'],fn($c,$w)=>$w,true)===null,'workspace visibility revokes next anonymous request');
}finally{
    if($pg&&$dsn){putenv('BOOK_PG_DSN='.$dsn);$s=null;$m=null;$raw=null;try{$pdo=P::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}catch(Throwable $e){fwrite(STDERR,'Cleanup: '.$e->getMessage()."\n");}}
    clean($root);
}
