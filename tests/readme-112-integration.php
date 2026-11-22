<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MarkdownImport,ImportFiles};
function check112i(bool $yes,string $label):void{if(!$yes)throw new RuntimeException('ReadMe import: '.$label);echo "PASS $label\n";}
function clean112i(string $path):void{if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $entry){if($entry->isDot())continue;$file=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())clean112i($file);else unlink($file);}rmdir($path);}
if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP ZIP required');
$root=sys_get_temp_dir().'/book-readme-112-integration-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $spec=['openapi'=>'3.1.0','info'=>['title'=>'Birds API','version'=>'1.0'],'paths'=>['/birds'=>['get'=>['operationId'=>'listBirds','summary'=>'List birds','responses'=>['200'=>['description'=>'OK']]]]]];
    $z=new ZipArchive();$archiveFile=$root.'/readme.zip';if($z->open($archiveFile,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create ZIP');
    $z->addFromString('branches/v1/docs/_order.yaml',"- start\n- advanced\n");
    $z->addFromString('branches/v1/docs/start.md',"---\ntitle: Getting started\n---\n# Welcome\n\n[Guide](advanced/index.md)\n![Icon](../images/icon.png)\n<Callout theme=\"warning\">Important notice</Callout>\n");
    $z->addFromString('branches/v1/docs/advanced/index.md',"# Advanced\n");
    $z->addFromString('branches/v1/images/icon.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==',true));
    $z->addFromString('branches/v1/recipes/_order.yaml',"- tutorial\n");
    $z->addFromString('branches/v1/recipes/tutorial.md',"# Tutorial\n<Steps><Step title=\"Do it\">Use API</Step></Steps>\n");
    $z->addFromString('branches/v1/reference/_order.yaml',"- list-birds\n");
    $z->addFromString('branches/v1/reference/list-birds.md',"---\napi:\n  file: birds.json\n  operationId: listBirds\n---\n# Endpoint\nMethod notes.\n");
    $z->addFromString('branches/v1/reference/birds.json',json_encode($spec,JSON_THROW_ON_ERROR));
    $z->addFromString('branches/v2/docs/_order.yaml',"- start\n");
    $z->addFromString('branches/v2/docs/start.md',"# V2 Welcome\n");
    $z->close();$staged=new ImportFiles();$staged->zip($archiveFile);
    $store=new BookStore($root.'/book');$store->initialize();$media=new MediaStore($root.'/book',$store);$import=new MarkdownImport($store,$media);$secret=str_repeat('c',64);
    $before=$store->read();$preview=$import->preview($staged->files(),'manual','1.0','update',$secret);
    check112i($store->read()===$before,'preview does not mutate stored pages or media');
    check112i(count($preview['rows'])>=7&&$preview['media_new']===1,'multiple branches, endpoint pages, OAS generation and PNG counted');
    $import->apply($staged->files(),'manual','1.0','update',$secret,$preview['token']);$db=$store->read();
    foreach(['manual/v1/docs/start','manual/v1/docs/advanced','manual/v1/recipes/tutorial','manual/v1/reference/list-birds','manual/v2/docs/start','manual/v1/reference/birds/index'] as $key){check112i(isset($db['pages'][$key])&&$db['pages'][$key]['published']===null,'unpublished imported draft: '.$key);}
    check112i(str_contains($db['pages']['manual/v1/docs/start']['draft']['body'],'/docs/manual/v1/docs/advanced'),'Guide links rewritten');
    check112i(preg_match('~/media/[0-9a-f]{32}~',$db['pages']['manual/v1/docs/start']['draft']['body'])===1&&count($db['media'])===1,'local image imported and linked');
    check112i(str_contains($db['pages']['manual/v1/docs/start']['draft']['body'],'book-callout'),'callout stored as Book rich block');
    check112i(str_contains($db['pages']['manual/v1/recipes/tutorial']['draft']['body'],'book-steps'),'Recipe steps rendered as Book block');
    check112i(str_contains($db['pages']['manual/v1/reference/list-birds']['draft']['body'],'/docs/manual/v1/reference/birds/operations/get-'),'API endpoint linked to OpenAPI operation');
    $next=$import->preview($staged->files(),'manual','1.0','update',$secret);
    check112i(count(array_filter($next['rows'],fn($r)=>$r['action']==='unchanged'))===count($next['rows'])&&$next['media_new']===0,'repeat import is idempotent');
    $import->apply($staged->files(),'manual','1.0','update',$secret,$next['token']);check112i($store->read()['pages']['manual/v1/docs/start']['revision']===1,'unchanged content preserves existing revision');
    echo "ReadMe 1.0.12 full ZIP preview/apply integration passed.\n";
}finally{clean112i($root);}
