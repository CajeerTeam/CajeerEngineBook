<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{Analytics, AnalyticsBackup, BookStore, MediaBackup, MediaStore};
use CajeerEngine\Update\OperationFiles as Files;
function check110(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
function reject110(callable $call,string $label):void{try{$call();}catch(InvalidArgumentException|RuntimeException|JsonException){echo "PASS $label\n";return;}throw new RuntimeException('Unexpectedly accepted: '.$label);}
function clean110(string $path):void{if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())clean110($entry->getPathname());rmdir($path);}
$base=sys_get_temp_dir().'/book-recovery-110-'.bin2hex(random_bytes(8));mkdir($base,0700);
$original=getenv('BOOK_PG_DSN');putenv('BOOK_PG_DSN');
try{
    $source=$base.'/original';Files::dir($source);$books=new BookStore($source,false);$books->initialize();$books->createBook('guide','Guide');
    $ws=['legacy'=>true,'analytics'=>array_replace(Analytics::defaults(),['enabled'=>true,'search_terms'=>true,'goals'=>['signup_done']])];
    Analytics::collect($source,$ws,'page_view','/docs/guide/1.0/intro');
    Analytics::collect($source,$ws,'search','/search','publish');
    Analytics::collect($source,$ws,'conversion','/docs/guide/1.0/intro','signup_done');
    $before=Analytics::report($source,$ws);
    $media=new MediaStore($source,$books);$backup=new MediaBackup($source,$books,$media);
    $backup->capture($base.'/captured');$captured=new BookStore($base.'/captured',false,true);
    (new MediaBackup($base.'/captured',$captured,new MediaStore($base.'/captured',$captured)))->create($base.'/store.tar');
    MediaBackup::restore($base.'/store.tar',$base.'/recovered');
    check110(Analytics::report($base.'/recovered',$ws)===$before,'durable analytics recovered with content, search and conversions');
    check110((new BookStore($base.'/recovered',false,true))->read()['books']['guide']['title']==='Guide','content restored with analytics');
    $day=gmdate('Y-m-d').'.json';$path=$source.'/analytics/'.$day;
    AnalyticsBackup::validate($day,'{"day":"'.gmdate('Y-m-d').'","events":{"page_view|/":1}}');
    echo "PASS legacy 1.0.5 daily aggregates without dimensions are supported\n";
    $saved=file_get_contents($path);file_put_contents($path,'{"day":"'.gmdate('Y-m-d').'","events":{"page_view|/":-1}}');
    reject110(fn()=>$backup->create($base.'/corrupt.tar'),'invalid live analytics aborts backup');
    check110(!file_exists($base.'/corrupt.tar'),'invalid snapshot is not published');
    file_put_contents($path,$saved);
    reject110(fn()=>AnalyticsBackup::validate($day,'{"day":"2026-01-01","events":{},"dimensions":{}}'),'incorrect analytics date rejected');
    reject110(fn()=>AnalyticsBackup::validate($day,'{"day":"'.gmdate('Y-m-d').'","events":{"page_view|/docs/../private":1},"dimensions":{}}'),'unsafe analytics path rejected');
    reject110(fn()=>AnalyticsBackup::validate($day,'{"day":"'.gmdate('Y-m-d').'","events":{},"dimensions":{"conversion|invalid goal":1}}'),'invalid goal rejected');
    reject110(fn()=>AnalyticsBackup::validate($day,'{"day":"'.gmdate('Y-m-d').'","events":{},"dimensions":{},"visitor_id":"leak"}'),'unknown private fields rejected');
    file_put_contents($source.'/analytics/unexpected.txt','unvalidated');
    reject110(fn()=>AnalyticsBackup::snapshot($source),'unknown analytics snapshot member rejected');
    unlink($source.'/analytics/unexpected.txt');
    rename($path,$source.'/analytics/actual.json');symlink($source.'/analytics/actual.json',$path);
    reject110(fn()=>AnalyticsBackup::snapshot($source),'symbolic link analytics entry rejected');
    unlink($path);rename($source.'/analytics/actual.json',$path);
    // Malicious TAR content is not silently discarded by restore.
    $bad=$base.'/tampered.tar';copy($base.'/store.tar',$bad);
    $tar=new PharData($bad);$tar->addFromString('analytics/not-a-day.json','{"day":"2026-01-01"}');unset($tar);
    reject110(fn()=>MediaBackup::restore($bad,$base.'/invalid'),'unexpected analytics TAR member rejected');
    check110(!file_exists($base.'/invalid')&&!count(glob($base.'/.book-restore-*')?:[]),'tampered restore leaves no published data or plaintext staging');
}finally{putenv($original===false?'BOOK_PG_DSN':'BOOK_PG_DSN='.$original);clean110($base);}
echo "OK\n";
