<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MarkdownImport,ImportFiles,Locale,PageTree};
function test113i(bool $pass,string $name):void{if(!$pass)throw new RuntimeException('Fumadocs 1.0.13: '.$name);echo "PASS $name\n";}
function remove113i(string $path):void{if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $entry){if($entry->isDot())continue;$file=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())remove113i($file);else unlink($file);}rmdir($path);}
if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP ext-zip required');
$root=sys_get_temp_dir().'/book-fumadocs-113-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $zipPath=$root.'/fumadocs.zip';$z=new ZipArchive();if($z->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create fixture ZIP');
    $base='docs-project/';
    $z->addFromString($base.'source.config.ts',"export default { docs: { dir: 'content/docs' } };\n");
    $z->addFromString($base.'content/docs/meta.json',json_encode(['title'=>'Documentation','pages'=>['index','v1','v2']],JSON_THROW_ON_ERROR));
    $z->addFromString($base.'content/docs/index.mdx',"---\ntitle: Introduction\nicon: House\n---\n# Welcome\n\n[Legacy](/docs/v1/start)\n");
    $z->addFromString($base.'content/docs/v1/meta.json',json_encode(['title'=>'Version One','root'=>'version','pages'=>['index','start']],JSON_THROW_ON_ERROR));
    $z->addFromString($base.'content/docs/v1/index.mdx',"# Version One\n");
    $z->addFromString($base.'content/docs/v1/start.mdx',"---\ntitle: Getting Started\n---\n# Start\n[Version two](/docs/v2/start)\n![Logo](/images/icon.png)\n<Callout type=\"warn\">Important information</Callout>\n<Tabs items={[\"Linux\",\"Windows\"]}><Tab value=\"Linux\">bash</Tab><Tab value=\"Windows\">powershell</Tab></Tabs>\n");
    $z->addFromString($base.'content/docs/v1/start.ru.mdx',"---\ntitle: Начало\n---\n# Начало\n[English](start.mdx)\n");
    $z->addFromString($base.'content/docs/v2/meta.json',json_encode(['title'=>'Version Two','root'=>'version','pages'=>['start']],JSON_THROW_ON_ERROR));
    $z->addFromString($base.'content/docs/v2/start.mdx',"# V2 Start\n");
    $z->addFromString($base.'public/images/icon.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==',true));
    $z->close();
    $upload=new ImportFiles();$upload->zip($zipPath);
    $store=new BookStore($root.'/store');$store->initialize();$media=new MediaStore($root.'/store',$store);
    $import=new MarkdownImport($store,$media);$secret=str_repeat('e',64);$before=$store->read();
    $preview=$import->preview($upload->files(),'manual','default','update',$secret);
    test113i($store->read()===$before,'preview never writes to BookStore');
    test113i(count($preview['rows'])===5&&$preview['media_new']===1,'versions, translated page and asset prepared for import');
    $import->apply($upload->files(),'manual','default','update',$secret,$preview['token']);$db=$store->read();
    foreach(['manual/default/index','manual/v1/index','manual/v1/start','manual/v1/ru/start','manual/v2/start'] as $key)test113i(isset($db['pages'][$key])&&$db['pages'][$key]['published']===null,'draft exists and is unpublished: '.$key);
    $en=$db['pages']['manual/v1/start'];$ru=$db['pages']['manual/v1/ru/start'];
    test113i(Locale::language($en['draft'])==='en'&&Locale::language($ru['draft'])==='ru','localized documents retain locale');
    test113i(($ru['draft']['translation_group']??'')===PageTree::id('manual/v1/start',$en)&&($ru['draft']['source_digest']??'')===Locale::digest($en['draft']),'translation linked to English draft');
    test113i(str_contains($en['draft']['body'],'/docs/manual/v2/start'),'cross-version link is rewritten');
    test113i(str_contains($en['draft']['body'],'book-callout')&&str_contains($en['draft']['body'],'book-tabs'),'static MDX is editable Book rich content');
    test113i(preg_match('~/media/[0-9a-f]{32}~',$en['draft']['body'])===1&&count($db['media'])===1,'public/ media is stored and linked');
    test113i($db['pages']['manual/v1/index']['draft']['order']<$db['pages']['manual/v1/start']['draft']['order'],'meta.json navigation persisted');
    $again=$import->preview($upload->files(),'manual','default','update',$secret);
    test113i(count(array_filter($again['rows'],fn($row)=>$row['action']==='unchanged'))===count($again['rows'])&&$again['media_new']===0,'second preview detects no changes');
    $import->apply($upload->files(),'manual','default','update',$secret,$again['token']);
    test113i($store->read()['pages']['manual/v1/start']['revision']===1,'idempotent repeat does not create revisions');
    $beforeDigest=$store->read()['pages']['manual/v1/ru/start']['draft']['source_digest'];
    $updated=new ZipArchive();if($updated->open($zipPath)!==true)throw new RuntimeException('Cannot edit fixture ZIP');
    $updated->addFromString($base.'content/docs/v1/start.mdx',"---\ntitle: Getting Started\n---\n# Updated guide\n![Logo](/images/icon.png)\n");$updated->close();
    $changed=new ImportFiles();$changed->zip($zipPath);
    $next=$import->preview($changed->files(),'manual','default','update',$secret);
    $import->apply($changed->files(),'manual','default','update',$secret,$next['token']);
    $latest=$store->read();
    test113i($latest['pages']['manual/v1/start']['revision']===2,'changed English source updates only its draft revision');
    test113i($latest['pages']['manual/v1/ru/start']['draft']['source_digest']===$beforeDigest,'unchanged translation stays stale until independently updated');
    echo "Fumadocs 1.0.13 ZIP / preview / apply / locale integration passed.\n";
}finally{remove113i($root);}
