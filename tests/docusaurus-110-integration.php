<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MarkdownImport,ImportFiles};
function checkDocus110(bool $pass,string $label):void{if(!$pass)throw new RuntimeException('Docusaurus import failed: '.$label);echo "PASS $label\n";}
function eraseDocus110(string $path):void{if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $entry){if($entry->isDot())continue;$name=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())eraseDocus110($name);else unlink($name);}rmdir($path);}
if(!class_exists(ZipArchive::class))throw new RuntimeException('ext-zip is required for Docusaurus import integration');
$root=sys_get_temp_dir().'/docusaurus-110-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $zipPath=$root.'/fixture.zip';$zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Failed to create ZIP fixture');
    $zip->addFromString('repo/docusaurus.config.js',"module.exports = {title:'Example'};");
    $zip->addFromString('repo/sidebars.js',"const sidebars = { tutorial: ['intro', {type:'category',label:'Guides',items:['guide']} ] }; export default sidebars;");
    $zip->addFromString('repo/versions.json','["1.1"]');
    $zip->addFromString('repo/versioned_sidebars/version-1.1-sidebars.json','{"tutorial":["intro"]}');
    $zip->addFromString('repo/docs/intro.mdx',"---\nid: intro\ntitle: Welcome\n---\n# Welcome\n\n![Logo](/img/logo.png)\n\n[Legacy](/docs/1.1/intro)\n\n:::tip[Useful]\nCheck this out\n:::\n");
    $zip->addFromString('repo/docs/guide.mdx',"---\nid: guide\ntitle: Guide\n---\nimport Tabs from '@theme/Tabs';\nimport TabItem from '@theme/TabItem';\n\n# Guide\n\n<Tabs><TabItem value=\"one\" label=\"First\">A</TabItem></Tabs>\n");
    $zip->addFromString('repo/versioned_docs/version-1.1/intro.md',"# Archived version\n");
    $zip->addFromString('repo/i18n/ru/docusaurus-plugin-content-docs/current/intro.md',"---\ntitle: Приветствие\n---\n# Добро пожаловать\n");
    $zip->addFromString('repo/static/img/logo.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==',true));
    $zip->close();
    $archive=new ImportFiles();$archive->zip($zipPath);
    $store=new BookStore($root.'/books');$store->initialize();$media=new MediaStore($root.'/books',$store);$import=new MarkdownImport($store,$media);
    $before=$store->read();$secret=str_repeat('a',64);$preview=$import->preview($archive->files(),'guide','2.0','update',$secret);
    checkDocus110(count($preview['rows'])===4&&$preview['media_new']===1&&$store->read()===$before,'preview is side-effect-free and counts four pages plus media');
    $result=$import->apply($archive->files(),'guide','2.0','update',$secret,$preview['token']);$db=$store->read();
    foreach(['guide/2.0/intro','guide/2.0/guide','guide/2.0/ru/intro','guide/1.1/intro'] as $key)checkDocus110(isset($db['pages'][$key])&&$db['pages'][$key]['published']===null,'version/language page saved as unpublished draft: '.$key);
    checkDocus110($db['pages']['guide/2.0/ru/intro']['draft']['locale']==='ru'&&$db['pages']['guide/2.0/intro']['draft']['order']===10&&$db['pages']['guide/2.0/guide']['draft']['section']==='Guides','locale and sidebar order/sections persisted');
    checkDocus110(str_contains($db['pages']['guide/2.0/intro']['draft']['body'],'```book-callout')&&str_contains($db['pages']['guide/2.0/guide']['draft']['body'],'```book-tabs'),'MDX and admonitions converted to native rich blocks');
    checkDocus110(str_contains($db['pages']['guide/2.0/intro']['draft']['body'],'/docs/guide/1.1/intro')&&preg_match('~/media/[a-f0-9]{32}~',$db['pages']['guide/2.0/intro']['draft']['body'])===1&&count($db['media'])===1,'cross-version links and media use Book routes');
    $repeat=$import->preview($archive->files(),'guide','2.0','update',$secret);checkDocus110(count(array_filter($repeat['rows'],static fn($r)=>$r['action']==='unchanged'))===4&&$repeat['media_new']===0,'repeat import idempotent');
    $import->apply($archive->files(),'guide','2.0','update',$secret,$repeat['token']);checkDocus110($store->read()['pages']['guide/1.1/intro']['revision']===1,'repeat import does not overwrite unmodified drafts');
    echo "Docusaurus 1.0.10 end-to-end integration passed.\n";
}finally{eraseDocus110($root);}
