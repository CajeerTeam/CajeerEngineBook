<?php
declare(strict_types=1);
// This suite uses real storage and an empty page set for restore; it can run without the parser.
if(in_array('--storage-only',$argv,true)){foreach(['core/Update/OperationFiles.php','src/BookStore.php','src/PageTree.php','src/MediaStore.php','src/MediaBackup.php'] as $file)require dirname(__DIR__).'/'.$file;}
else require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MediaBackup};
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function reject(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException|DomainException $e){check(true,$message);return;}throw new RuntimeException($message.' was accepted');}
function removeTree(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())removeTree($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$work=sys_get_temp_dir().'/book-media-test-'.bin2hex(random_bytes(8));mkdir($work,0700);$dir=$work.'/data';$books=new BookStore($dir);$media=new MediaStore($dir,$books);
try{
    $png=$work.'/test.png';file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));
    // Storage operations do not depend on the Markdown renderer.
    $one=$media->upload($png,'pixel.png','guides/images');$id=$one['item']['id'];
    check(!$one['duplicate']&&MediaStore::validId($id)&&$one['item']['width']===1&&$one['item']['mime']==='image/png','actual raster upload and dimensions');
    check(is_file($media->blob($id))&&hash_file('sha256',$png)===$one['item']['sha256'],'durable blob and SHA256');
    check($media->upload($png,'copy.png')['duplicate']&&count($books->read()['media'])===1,'content deduplication');
    check($media->open($id,false)===null,'private by default');
    if(function_exists('imagecreatefromstring')){
        $thumb=$media->thumbnail($id);check($thumb!==null,'GD generates cached thumbnail');$bytes=stream_get_contents($thumb[1]);fclose($thumb[1]);check(getimagesizefromstring($bytes)[0]===1&&is_file($dir.'/media/'.$id.'.thumb.png'),'thumbnail bytes and durable cache');
    }
    [$item,$handle]=$media->open($id,true);check(stream_get_contents($handle)===file_get_contents($png),'authorized stream bytes');fclose($handle);
    $media->update($id,1,'renamed.png','guides');$renamed=$books->read()['media'][$id];check($renamed['name']==='renamed.png'&&$renamed['folder']==='guides'&&$renamed['revision']===2,'rename and folder change');
    reject(fn()=>$media->update($id,1,'again.png',''),'stale metadata revision');
    reject(fn()=>$media->update($id,2,'fake.pdf',''),'rename type change');
    reject(fn()=>$media->upload($png,'../pixel.png'),'path traversal');
    reject(fn()=>$media->upload($png,'fake.php'),'executable extension');
    reject(fn()=>$media->upload($png,'fake.pdf'),'MIME mismatch');
    reject(fn()=>$media->upload($png,'pixel.png','a/../b'),'folder traversal');
    $text=$work.'/test.txt';file_put_contents($text,"Some documentation text\n");$two=$media->upload($text,'notes.txt');$tid=$two['item']['id'];check($two['item']['mime']==='text/plain','text upload');
    $fake=$work.'/fake';file_put_contents($fake,'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');reject(fn()=>$media->upload($fake,'fake.png'),'SVG masquerading as PNG');
    $large=$work.'/large';$h=fopen($large,'wb');ftruncate($h,MediaStore::MAX_FILE+1);fclose($h);reject(fn()=>$media->upload($large,'large.txt'),'size quota');
    // Simulate a committed snapshot without requiring a parser in this storage test.
    $books->change(function(&$db)use($id){$db['pages']['test/1.0/media']=['revision'=>1,'draft'=>['body'=>'[file](/media/'.$id.')','media'=>[$id]],'published'=>null];});
    reject(fn()=>$media->delete($id,2),'draft reference prevents deletion');
    check($media->open($id,false)===null,'draft does not grant public access');
    $books->change(function(&$db){$db['pages']['test/1.0/media']['published']=$db['pages']['test/1.0/media']['draft'];});
    $public=$media->open($id,false);check($public!==null,'committed published reference grants public access');fclose($public[1]);
    reject(fn()=>$media->delete($id,2),'published reference prevents deletion');
    $books->change(function(&$db){$db['pages']=[];});
    check($media->open($id,false)===null,'unpublish revokes public access');
    $archive=$work.'/backup.tar';(new MediaBackup($dir,$books,$media))->create($archive);check(is_file($archive),'full TAR backup');
    MediaBackup::restore($archive,$work.'/restored');$restored=new BookStore($work.'/restored');$rm=new MediaStore($work.'/restored',$restored);check($restored->read()===$books->read()&&hash_file('sha256',$rm->blob($id))===hash_file('sha256',$png),'restore all metadata and bytes');
    reject(fn()=>MediaBackup::restore($archive,$work.'/restored'),'restore never overwrites existing data');
    $bad=$work.'/bad.tar';$tar=new PharData($bad);$tar->addFromString('books.json',json_encode($books->read()));$tar->addFromString('media/'.$id.'.blob',str_repeat('x',filesize($png)));$tar->addFile($media->blob($tid),'media/'.$tid.'.blob');unset($tar);
    reject(fn()=>MediaBackup::restore($bad,$work.'/broken'),'tampered backup rejected');check(!file_exists($work.'/broken'),'failed restore leaves no live directory');
    $orphan=$media->blob(str_repeat('b',32));file_put_contents($orphan,'orphan');check($media->collectOrphans()===1&&!file_exists($orphan)&&is_file($media->blob($id)),'orphan cleanup preserves referenced blobs');
    $media->delete($tid,1);check(!isset($books->read()['media'][$tid])&&!file_exists($media->blob($tid)),'delete removes record and blob');
    reject(fn()=>$media->delete($id,1),'stale delete rejected');$media->delete($id,2);check(!$books->read()['media'],'all unused files removable');
}finally{removeTree($work);}
