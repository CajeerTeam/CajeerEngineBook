<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,PageTree,StaticExport,Permissions,WorkspaceUrls};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejected(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException|DomainException|RuntimeException){check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
function clean(string $dir):void{foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
function zip(string $path):ZipArchive{$z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('Cannot open ZIP');return $z;}
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/book-static-test-'.bin2hex(random_bytes(10));mkdir($tmp,0700);
try{
    $store=new BookStore($tmp.'/data');$store->materialize();$media=new MediaStore($tmp.'/data',$store);$export=new StaticExport($root,$store,$media);
    $upload=function(string $name,string $bytes)use($tmp,$media){$path=$tmp.'/'.$name;file_put_contents($path,$bytes);return $media->upload($path,$name)['item']['id'];};
    $image=$upload('pixel.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));
    $attachment=$upload('guide.txt',"Offline attachment content\n");$unused=$upload('unused.txt',"DRAFT_MEDIA_SECRET\n");$private=$upload('private.txt',"PRIVATE_MEDIA_SECRET\n");
    $save=function(string $book,string $version,string $path,string $title,string $body,bool $publish=true,int $revision=0,int $order=10)use($store){return $store->save(compact('book','version','path','title','body','revision','order')+['section'=>'Guide'],$publish);};
    $tabs='~~~book-tabs' . "\n".json_encode(['v'=>1,'kind'=>'tabs','sync'=>'os','items'=>[['key'=>'linux','title'=>'Linux','body'=>'## Linux heading' . "\n\n".'Nested keyword [Next](nested/index.html#child-heading)'],['key'=>'win','title'=>'Windows','body'=>'Windows instructions']]],JSON_THROW_ON_ERROR)."\n~~~\n";
    $body="## Guide heading\n\nРусский поиск offline guide\n\n[Next](nested/index.html#child-heading)\n\n[Moved](/docs/guide/1/old#old-heading)\n\n[Private](/docs/secret/1/start)\n\n![Pixel](/media/$image)\n\n[Attachment](/media/$attachment?download=1)\n\n![Remote alternative](https://example.invalid/remote.png)\n\n[External](https://example.invalid/site)\n\n<script>window.staticInjected=1</script>\n\n".$tabs."\n```mermaid\nflowchart LR\nA[Start] --> B[Finish]\n```\n";
    $save('guide','1','start','Offline guide',$body);
    $save('guide','1','nested','Parent','Parent page',true,0,20);
    $save('guide','1','nested/index.html','Child','## Child heading',true,0,30);
    $save('guide','1','old','Moved page','## Old heading',true,0,40);
    $save('guide','2','start','Version 2','Version 2 content');
    $save('guide','1','draft','Draft','DRAFT_PAGE_SECRET [Draft](/media/'.$unused.')',false);
    $save('guide','1','start','Unpublished title','DRAFT_EDIT_SECRET',false,1);
    $save('secret','1','start','Private title','PRIVATE_PAGE_SECRET [Secret](/media/'.$private.')');$store->setBookVisibility('secret','private',1);
    $store->change(function(&$db){PageTree::move($db,'guide/1/old','','root','renamed',PageTree::token($db),false);});
    $store->change(function(&$db){PageTree::publish($db,'guide/1',PageTree::token($db));});
    $before=file_get_contents($tmp.'/data/books.json');
    $result=$export->create($tmp.'/public.zip','public','guide/1');
    check($result['pages']===4&&$result['media']===2&&$result['redirects']===1,'scoped published snapshot, referenced media and redirects');
    $z=zip($tmp.'/public.zip');$page=StaticExport::pagePath('guide/1/start');$html=$z->getFromName($page);$index=$z->getFromName('assets/search-index.js');
    check(is_string($html),'generated page exists');
    foreach(['../p-nested/p-index.html/index.html#child-heading','../p-renamed/index.html#old-heading','data-book-tabs','data-book-mermaid','Remote alternative','https://example.invalid/site','&lt;script&gt;'] as $needle)check(str_contains($html,$needle),'HTML contains '.$needle);
    check(!str_contains($html,'src="https://example.invalid')&&!str_contains($html,'/docs/secret/1/start')&&!str_contains($html,'DRAFT_EDIT_SECRET'),'remote images, private links and unpublished edits excluded');
    check(str_contains($index,'Русский поиск')&&!str_contains($index,'PRIVATE_PAGE_SECRET')&&!str_contains($index,'DRAFT_PAGE_SECRET'),'search index contains only exported rendered text');
    check($z->getFromName('media/'.$image.'.png')===file_get_contents($tmp.'/pixel.png')&&$z->getFromName('media/'.$attachment.'.txt')===file_get_contents($tmp.'/guide.txt'),'original media bytes preserved');
    check($z->locateName('media/'.$unused.'.txt')===false&&$z->locateName('media/'.$private.'.txt')===false&&$z->locateName('books.json')===false,'unused, draft and private media and store excluded');
    check(str_contains($z->getFromName(StaticExport::pagePath('guide/1/old')),'../p-renamed/index.html'),'old published redirect resolves to static target');
    check($z->locateName('assets/diagram-frame.html')!==false&&$z->locateName('assets/mermaid-LICENSE.txt')!==false,'self-contained Mermaid runtime and license');$z->close();
    rejected(fn()=>$export->create($tmp.'/public.zip','public','guide/1'),'existing archive is not overwritten');
    rejected(fn()=>$export->create($tmp.'/none.zip','private'),'private workspace public export refused');
    rejected(fn()=>$export->create($tmp.'/none.zip','public','secret/1'),'private book public export refused');
    check(!file_exists($tmp.'/none.zip'),'empty export leaves no archive');
    $export->create($tmp.'/private.zip','private','secret/1',true);$z=zip($tmp.'/private.zip');check(str_contains($z->getFromName('assets/search-index.js'),'PRIVATE_PAGE_SECRET')&&$z->locateName('media/'.$private.'.txt')!==false,'explicit private published export');$z->close();
    WorkspaceUrls::set('/w/'.str_repeat('a',32).'/'.str_repeat('b',32));try{$export->create($tmp.'/scoped.zip','public','guide/1');}finally{WorkspaceUrls::set('');}
    $z=zip($tmp.'/scoped.zip');check(str_contains($z->getFromName($page),'../p-nested/p-index.html/index.html#child-heading'),'scoped URL prefix stripped in static site');$z->close();
    $export->create($tmp.'/all.zip','public');$z=zip($tmp.'/all.zip');check(str_contains($z->getFromName($page),'p-2/p-start/index.html'),'version switching between exported versions');$z->close();
    check(file_get_contents($tmp.'/data/books.json')===$before,'export never mutates store');
    file_put_contents($media->blob($attachment),'corrupt');rejected(fn()=>$export->create($tmp.'/bad.zip','public','guide/1'),'damaged referenced media aborts export');check(!file_exists($tmp.'/bad.zip')&&glob($tmp.'/.book-static-*')===[],'failure cleans temporary data');
    foreach(['viewer','editor','none'] as $role)check(!Permissions::studio($role,'/studio/static-export','GET','')&&!Permissions::studio($role,'/studio/static-export','POST','download'),'export denied to '.$role);
    check(Permissions::studio('admin','/studio/static-export','POST','download')&&!Permissions::studio('owner','/studio/static-export','POST','unknown'),'admin download only');
    foreach($argv as $arg)if(str_starts_with($arg,'--site=')){if(!copy($tmp.'/all.zip',substr($arg,7)))throw new RuntimeException('Cannot copy browser fixture');}
}finally{clean($tmp);}
