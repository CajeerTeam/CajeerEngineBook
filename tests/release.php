<?php
declare(strict_types=1);
use CajeerBook\{BookStore,DataDirectory,Markdown,MediaStore,MediaBackup,PageTree,ReleaseHealth,StoreAudit};
$policy=in_array('--policy-only',$argv,true);
if($policy){foreach(['core/Update/OperationFiles.php','src/DataDirectory.php','src/BookStore.php','src/PageTree.php','src/Markdown.php','src/ReleaseHealth.php','src/MediaStore.php'] as $f)require dirname(__DIR__).'/'.$f;}else require dirname(__DIR__).'/bootstrap.php';
function ok(bool $pass,string $name):void {if(!$pass)throw new RuntimeException($name);echo "PASS $name\n";}
function rejected(callable $fn,string $name):void {try{$fn();}catch(RuntimeException|DomainException|InvalidArgumentException $e){ok(true,$name);return;}throw new RuntimeException('Accepted: '.$name);}
function rmTree(string $dir):void {if(!is_dir($dir)||is_link($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())rmTree($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
function fence(string $kind,array $data):string{return "~~~book-$kind\n".json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n~~~\n";}
$root=sys_get_temp_dir().'/book-release-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/public');
try {
    ok(DataDirectory::resolve($root.'/private/new',$root.'/public')===$root.'/private/new','new private path resolved');
    rejected(fn()=>DataDirectory::resolve($root.'/public/new/deep',$root.'/public'),'public descendant rejected before creation');
    rejected(fn()=>DataDirectory::resolve($root.'/private/../public/new',$root.'/public'),'parent traversal rejected');
    symlink($root.'/public',$root.'/alias');
    rejected(fn()=>DataDirectory::resolve($root.'/alias/new',$root.'/public'),'symlink alias to public rejected');
    mkdir($root.'/private');symlink($root.'/private',$root.'/public/escape');
    rejected(fn()=>DataDirectory::resolve($root.'/public/escape/new',$root.'/public'),'public symlink to outside rejected');
    symlink($root.'/absent',$root.'/dangling');
    rejected(fn()=>DataDirectory::resolve($root.'/dangling/new',$root.'/public'),'dangling symlink rejected');
    file_put_contents($root.'/file','not a directory');
    rejected(fn()=>DataDirectory::resolve($root.'/file/new',$root.'/public'),'file ancestor rejected');
    $dir=$root.'/data';$store=new BookStore($dir);$store->checkReady();
    ok(is_dir($dir)&&glob($dir.'/.ready-*')===[],'readiness writes and cleans probe');
    $store->change(fn(&$db)=>$db['release_test']='original');$before=file_get_contents($dir.'/books.json');
    rejected(fn()=>$store->change(fn(&$db)=>$db['release_test']=str_repeat('x',BookStore::MAX_DB_BYTES)),'oversize transaction rejected');
    ok(file_get_contents($dir.'/books.json')===$before,'oversize transaction leaves exact prior bytes');
    // A pre-0.2 legacy store above the portable limit can be reduced over several commits.
    file_put_contents($dir.'/books.json',json_encode(['schema'=>1,'pages'=>[],'legacy'=>str_repeat('x',BookStore::MAX_DB_BYTES+4096)],JSON_THROW_ON_ERROR));
    rejected(fn()=>$store->checkReady(),'oversize legacy store not ready');
    $store->change(fn(&$db)=>$db['legacy']=str_repeat('x',BookStore::MAX_DB_BYTES+1024));
    ok(strlen($store->read()['legacy'])===BookStore::MAX_DB_BYTES+1024,'legacy oversize store can shrink in stages');
    file_put_contents($dir.'/books.json','{"schema":99,"pages":[]}');
    rejected(fn()=>$store->read(),'unknown schema fails closed');
    file_put_contents($dir.'/books.json',$before);
    mkdir($root.'/media-dir');symlink($root.'/media-dir',$dir.'/media');
    rejected(fn()=>$store->checkReady(),'readiness rejects symlink media directory');unlink($dir.'/media');
    $id=str_repeat('a',32);
    $store->change(fn(&$db)=>$db['media'][$id]=['id'=>$id,'size'=>123]);
    rejected(fn()=>$store->checkReady(),'readiness rejects missing media blob');
    $store->change(fn(&$db)=>$db['media']=[]);
    $mediaId=new ReflectionMethod(Markdown::class,'mediaId');$preview=new ReflectionMethod(Markdown::class,'previewMediaUrl');
    foreach(['','?download=1','#figure','?download=1#figure'] as $suffix){$url='/media/'.$id.$suffix;ok($mediaId->invoke(null,$url)===$id&&$preview->invoke(null,$url)==='/studio/media/file/'.$id.$suffix,'media reference and private preview preserve suffix '.$suffix);}
    ok($mediaId->invoke(null,'https://example.com/media/'.$id)===null&&$mediaId->invoke(null,'/media/'.$id.'/extra')===null,'external and noncanonical paths not granted access');
    rejected(fn()=>ReleaseHealth::check($root),'readiness rejects missing runtime assets');
    if($policy)exit;
    ReleaseHealth::check(dirname(__DIR__));ok(true,'locked runtime and local Mermaid installed');
    $media=new MediaStore($dir,$store);$png=$root.'/pixel.png';file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));$image=$media->upload($png,'pixel.png')['item']['id'];
    $file=$root.'/notes.txt';file_put_contents($file,"Release integration file\n");$attachment=$media->upload($file,'notes.txt')['item']['id'];
    $callout=fence('callout',['v'=>1,'type'=>'tip','title'=>'Tip','body'=>'**Ready**']);
    $cards=fence('cards',['v'=>1,'columns'=>2,'cards'=>[['title'=>'Guide','body'=>'Read more','href'=>'guide','icon'=>'book','image'=>'/media/'.$image,'alt'=>'Pixel']]]);
    $steps=fence('steps',['v'=>1,'kind'=>'steps','items'=>[['title'=>'Start','body'=>'[Download](/media/'.$attachment.'?download=1)']]]);
    $accordion=fence('accordion',['v'=>1,'kind'=>'accordion','items'=>[['title'=>'Details','body'=>$steps,'open'=>false]]]);
    $tabs=fence('tabs',['v'=>1,'kind'=>'tabs','sync'=>'os','items'=>[['key'=>'linux','title'=>'Linux','body'=>$accordion],['key'=>'win','title'=>'Windows','body'=>'## Windows']]]);
    $codes=fence('code-tabs',['v'=>1,'kind'=>'code-tabs','sync'=>'os','items'=>[['key'=>'linux','title'=>'Bash','language'=>'bash','code'=>'echo hello'],['key'=>'win','title'=>'PowerShell','language'=>'powershell','code'=>'Get-ChildItem']]]);
    $body="# Mixed document\n\n| A | B |\n| --- | --- |\n| **One** | Two |\n\n- [x] Ready\n\n![Pixel](/media/$image)\n\n[Download](/media/$attachment?download=1)\n\n<script>alert(1)</script>\n\n".$callout."\n".$cards."\n".$tabs."\n".$codes."\n```mermaid\nflowchart LR\nA-->B\n```\n";
    // Simulate 0.1.9's index for a query-bearing local URL. Upgrade must reindex it explicitly.
    $legacyKey='release/1.0/legacy';$legacy=['title'=>'Legacy','section'=>'QA','order'=>5,'body'=>'[file](/media/'.$attachment.'?download=1)','updated'=>gmdate('c'),'media'=>[]];
    $store->change(fn(&$db)=>$db['pages'][$legacyKey]=['revision'=>1,'draft'=>$legacy,'published'=>null]);
    $audit=new StoreAudit($store,$media);ok($audit->run()['reference_mismatches']===1,'audit detects old query reference index without writing');
    ok($audit->run(true)['reference_mismatches']===1&&$store->read()['pages'][$legacyKey]['draft']['media']===[$attachment]&&$store->read()['pages'][$legacyKey]['revision']===2,'atomic reindex repairs reference and invalidates old revision');
    ok($audit->run()['reference_mismatches']===0,'reindex idempotent');
    $key='release/1.0/mixed';$input=['book'=>'release','version'=>'1.0','path'=>'mixed','title'=>'Mixed','section'=>'QA','order'=>10,'body'=>$body,'revision'=>0];
    $store->save($input,false);ok(!isset(BookStore::published($store->read())[$key])&&$media->open($image,false)===null&&$media->open($attachment,false)===null,'mixed draft and attachments private');
    $r=Markdown::render($body,$key,true,true);ok(implode('',array_column($r['blocks'],'source'))===$body,'mixed source lossless visual round trip');
    foreach(['<table','book-callout','book-card','data-tab-panel','book-accordion-item','book-steps','data-book-mermaid','/studio/media/file/'.$attachment.'?download=1'] as $needle)ok(str_contains($r['html'],$needle),'mixed preview contains '.$needle);
    ok(!str_contains($r['html'],'<script>'),'mixed content escapes HTML');
    $input['revision']=1;$store->save($input,true);$db=$store->read();ok(isset(BookStore::published($db)[$key])&&count($db['pages'][$key]['published']['media'])===2,'all media references published');
    foreach([$image,$attachment] as $id){$opened=$media->open($id,false);ok($opened!==null,'published blob available');fclose($opened[1]);}
    $input['revision']=2;$input['body']='Unpublished secret';$store->save($input,false);ok(BookStore::published($store->read())[$key]['body']===$body,'new draft does not alter published mixed content');
    $store->change(function(&$db)use($key){PageTree::move($db,$key,'','root','renamed',PageTree::token($db),false);});
    $db=$store->read();ok(isset(BookStore::published($db)[$key]),'old public path survives draft move');
    $store->change(function(&$db){PageTree::publish($db,'release/1.0',PageTree::token($db));});$db=$store->read();$new='release/1.0/renamed';ok(PageTree::redirect($db,$key)===$new&&BookStore::published($db)[$new]['body']===$body,'atomic tree publish redirects without leaking draft');
    $backup=$root.'/backup.tar';(new MediaBackup($dir,$store,$media))->create($backup);MediaBackup::restore($backup,$root.'/restored');$restored=new BookStore($root.'/restored');$db2=$restored->read();ok($db2['pages'][$new]===$db['pages'][$new]&&PageTree::redirect($db2,$key)===$new,'full backup restores draft, publication and redirects');
    $restoredMedia=new MediaStore($root.'/restored',$restored);ok(hash_file('sha256',$restoredMedia->blob($image))===hash_file('sha256',$png),'backup restores original image bytes');
    $restored->remove($new,$db2['pages'][$new]['revision'],true);ok($restoredMedia->open($image,false)===null&&$restoredMedia->open($attachment,false)===null,'unpublish revokes both public media');
} finally {rmTree($root);}
