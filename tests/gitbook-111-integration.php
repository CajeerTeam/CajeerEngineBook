<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MarkdownImport,ImportFiles};
function assertGit111(bool $condition,string $name):void{if(!$condition)throw new RuntimeException('GitBook import failed: '.$name);echo "PASS $name\n";}
function deleteGit111(string $root):void{if(!is_dir($root))return;foreach(new DirectoryIterator($root) as $entry){if($entry->isDot())continue;$p=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())deleteGit111($p);else unlink($p);}rmdir($root);}
if(!class_exists(ZipArchive::class))throw new RuntimeException('ext-zip is required for GitBook import integration');
$root=sys_get_temp_dir().'/book-gitbook-111-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $zip=new ZipArchive();$src=$root.'/gitbook.zip';if($zip->open($src,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create GitBook fixture');
    $zip->addFromString('space/docs/.gitbook.yaml',"root: ./\nredirects:\n  /old-intro: intro.md\n");
    $zip->addFromString('space/docs/SUMMARY.md',"# Summary\n\n## Guides\n* [Home](README.md)\n* [Intro](intro.md)\n");
    $zip->addFromString('space/docs/README.md',"# Main\n[Intro](intro.md)\n\n![Icon](.gitbook/assets/icon.png)\n");
    $zip->addFromString('space/docs/intro.md',"---\ntitle: Introduction\n---\n# Intro\n{% hint style=\"warning\" %}\nCheck this.\n{% endhint %}\n");
    $zip->addFromString('space/docs/.gitbook/assets/icon.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==',true));
    $zip->addFromString('space/api/.gitbook.yaml',"root: ./\n");
    $zip->addFromString('space/api/README.md',"# API\n[Intro](https://app.gitbook.com/s/docs/intro)\n");
    $zip->close();
    $archive=new ImportFiles();$archive->zip($src);
    $store=new BookStore($root.'/book');$store->initialize();$media=new MediaStore($root.'/book',$store);$import=new MarkdownImport($store,$media);$secret=str_repeat('d',64);
    $old=$store->read();$preview=$import->preview($archive->files(),'fallback','1.0','update',$secret);
    assertGit111(count($preview['rows'])===3&&$preview['media_new']===1&&$store->read()===$old,'preview is read-only and counts pages/media');
    $done=$import->apply($archive->files(),'fallback','1.0','update',$secret,$preview['token']);$db=$store->read();
    foreach(['docs/1.0/index','docs/1.0/intro','api/1.0/index'] as $key)assertGit111(isset($db['pages'][$key])&&$db['pages'][$key]['published']===null,'imported independent private draft '.$key);
    assertGit111(isset($db['books']['docs'],$db['books']['api']),'two Spaces created as separate Books');
    assertGit111(str_contains($db['pages']['docs/1.0/intro']['draft']['body'],'book-callout')&&$db['pages']['docs/1.0/intro']['draft']['section']==='Guides','GitBook hint and sidebar stored in Book');
    assertGit111(str_contains($db['pages']['docs/1.0/index']['draft']['body'],'/docs/docs/1.0/intro')&&preg_match('~/media/[a-f0-9]{32}~',$db['pages']['docs/1.0/index']['draft']['body'])===1,'relative links and image imported');
    assertGit111(str_contains($db['pages']['api/1.0/index']['draft']['body'],'/docs/docs/1.0/intro'),'cross-Space links rewritten');
    assertGit111(count($db['media'])===1,'media saved exactly once');
    $second=$import->preview($archive->files(),'fallback','1.0','update',$secret);assertGit111(count(array_filter($second['rows'],fn($r)=>$r['action']==='unchanged'))===3&&$second['media_new']===0,'idempotent repeat import');
    $import->apply($archive->files(),'fallback','1.0','update',$secret,$second['token']);assertGit111($store->read()['pages']['docs/1.0/intro']['revision']===1,'unchanged draft revision retained');
    echo "GitBook 1.0.11 complete import integration passed.\n";
}finally{deleteGit111($root);}
