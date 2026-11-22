<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MarkdownImport,ImportFiles,PageTree};
function assert109(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
$root=sys_get_temp_dir().'/book-mint-integration-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $config=['navigation'=>['groups'=>[['group'=>'Start','pages'=>['index','guide']]]], 'redirects'=>[['source'=>'/old-guide','destination'=>'/guide']]];
    $zipFile=$root.'/mintlify.zip';$zip=new ZipArchive();if($zip->open($zipFile,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create fixture archive');
    $zip->addFromString('project/docs.json',json_encode($config,JSON_THROW_ON_ERROR));
    $zip->addFromString('project/index.mdx',"---\ntitle: Home\n---\n# Home\n\n[Open guide](/guide)\n");
    $zip->addFromString('project/guide.mdx',"---\ntitle: Guide\nicon: book\n---\n# Guide\n\n<Note>Read this first</Note>\n\n![Logo](/images/logo.png)\n");
    $zip->addFromString('project/public/images/logo.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==',true));
    $zip->close();
    $archive=new ImportFiles();$archive->zip($zipFile);
    $store=new BookStore($root.'/books');$store->initialize();$media=new MediaStore($root.'/books',$store);$importer=new MarkdownImport($store,$media);
    $secret=str_repeat('a',64);$before=$store->read();
    $preview=$importer->preview($archive->files(),'kb','1.0','update',$secret);
    assert109(count($preview['rows'])===2&&$preview['media_new']===1&&$store->read()===$before,'Mintlify ZIP preview produces 2 drafts and 1 asset, without side effects');
    $applied=$importer->apply($archive->files(),'kb','1.0','update',$secret,$preview['token']);
    $db=$store->read();$home=$db['pages']['kb/1.0/index']??null;$guide=$db['pages']['kb/1.0/guide']??null;
    assert109(is_array($home)&&is_array($guide)&&$home['published']===null&&$guide['published']===null,'Mintlify import creates drafts, never publishes');
    assert109($home['draft']['order']===10&&$guide['draft']['order']===20&&$guide['draft']['section']==='Start','Mintlify order and section persisted');
    assert109(str_contains($home['draft']['body'],'/docs/kb/1.0/guide')&&str_contains($guide['draft']['body'],'```book-callout')&&preg_match('~/media/[a-f0-9]{32}~',$guide['draft']['body'])===1,'imported MDX component and links resolve to Book URLs');
    assert109(count($db['media'])===1&&count($guide['draft']['media'])===1&&$db['redirects']['kb/1.0/old-guide']===$guide['id'],'media stored and redirect bound to stable page ID');
    assert109(PageTree::redirect($db,'kb/1.0/old-guide')===null,'redirect never exposes an unpublished draft');
    $p=$guide['draft'];$store->save(['book'=>'kb','version'=>'1.0','path'=>'guide','title'=>$p['title'],'section'=>$p['section'],'order'=>$p['order'],'body'=>$p['body'],'revision'=>1],true);
    assert109(PageTree::redirect($store->read(),'kb/1.0/old-guide')==='kb/1.0/guide','redirect resolves only after authorized publication');
    $preview=$importer->preview($archive->files(),'kb','1.0','update',$secret);
    assert109(count(array_filter($preview['rows'],static fn($row)=>$row['action']==='unchanged'))===2,'repeated Mintlify import detects unchanged drafts');
    $importer->apply($archive->files(),'kb','1.0','update',$secret,$preview['token']);
    assert109($store->read()['pages']['kb/1.0/guide']['revision']===2&&count($store->read()['media'])===1,'repeat import keeps revision and deduplicates media');
    echo "Mintlify 1.0.9 end-to-end import integration passed.\n";
}finally{
    if(is_dir($root)){$iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iter as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);}
}
