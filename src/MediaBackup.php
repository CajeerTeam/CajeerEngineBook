<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;

/** Portable uncompressed TAR; never extracts archive paths directly. */
final class MediaBackup
{
    private const MAX_DB = 32 * 1024 * 1024;
    public function __construct(private readonly string $dir,private readonly BookStore $books,private readonly MediaStore $media) {}
    public function create(string $target): void
    {
        if(!str_ends_with($target,'.tar') || file_exists($target) || is_link($target)) throw new \InvalidArgumentException('Backup destination must be a new .tar file');
        $tmp=dirname($target).'/.book-backup-'.bin2hex(random_bytes(12));Files::dir($tmp);
        $archive=$tmp.'/backup.tar';
        try{
            $this->books->snapshot(function(array $db)use($archive){
                $tar=new \PharData($archive,0,null,\Phar::TAR);
                $json=json_encode($db,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
                if(strlen($json)>self::MAX_DB)throw new \DomainException('Database exceeds portable backup limit (32 MiB)');
                $tar->startBuffering();$tar->addFromString('books.json',$json);unset($json);
                foreach($db['media']??[] as $id=>$item){
                    $path=$this->media->blob($id);
                    if(!is_file($path)||filesize($path)!==$item['size']||Files::hash($path)!==$item['sha256']) throw new \RuntimeException('Missing or damaged media: '.$id);
                    $tar->addFile($path,'media/'.$id.'.blob');
                }
                $tar->stopBuffering();unset($tar);
            });
            // Atomic no-overwrite publication; same filesystem as target.
            chmod($archive,0600);
            $h=fopen($archive,'rb');if(!$h)throw new \RuntimeException('Cannot open backup');try{if(!fsync($h))throw new \RuntimeException('Cannot sync backup');}finally{fclose($h);}
            if(!link($archive,$target))throw new \RuntimeException('Cannot publish backup (destination must not exist)');
            Files::syncDir(dirname($target));
        }finally{if(is_file($archive))unlink($archive);rmdir($tmp);}
    }
    public function download(string $method): void
    {
        $dir=sys_get_temp_dir().'/book-download-'.bin2hex(random_bytes(12));Files::dir($dir);$path=$dir.'/backup.tar';
        try{
            $this->create($path);
            header('Content-Type: application/x-tar');header('Content-Disposition: attachment; filename="cajeerbook-backup.tar"');header('Content-Length: '.filesize($path));
            if($method!=='HEAD')readfile($path);
        }finally{if(is_file($path))unlink($path);rmdir($dir);}
    }
    /** Offline restore into a new directory; existing data is never overwritten. */
    public static function restore(string $archive,string $target): void
    {
        if(!is_file($archive)||is_link($archive)||!str_ends_with($archive,'.tar')||filesize($archive)>MediaStore::MAX_TOTAL+self::MAX_DB+4*1024*1024)throw new \InvalidArgumentException('Invalid backup archive');
        $target=rtrim($target,'/');$parent=dirname($target);
        if($target===''||file_exists($target)||is_link($target)||!is_dir($parent)||is_link($parent))throw new \InvalidArgumentException('Restore destination must be a new directory with an existing parent');
        $stage=$parent.'/.book-restore-'.bin2hex(random_bytes(12));Files::dir($stage);
        try{
            $tar=new \PharData($archive);
            if(!$tar->offsetExists('books.json')||$tar['books.json']->isDir()||$tar['books.json']->isLink()||$tar['books.json']->getSize()>self::MAX_DB)throw new \InvalidArgumentException('Invalid books.json');
            $db=json_decode($tar['books.json']->getContent(),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($db)||($db['schema']??null)!==1||!is_array($db['pages']??null)||count($db['pages'])>1000||!is_array($db['media']??[])||count($db['media']??[])>MediaStore::MAX_FILES)throw new \InvalidArgumentException('Invalid backup database');
            $items=$db['media']??[];$total=0;
            foreach($items as $id=>$item){
                if(!is_string($id)||!MediaStore::validId($id)||!is_array($item)||($item['id']??null)!==$id||!is_string($item['name']??null)||!is_string($item['folder']??null)||!is_int($item['size']??null)||$item['size']<1||$item['size']>MediaStore::MAX_FILE||!is_int($item['revision']??null)||$item['revision']<1||!is_string($item['created']??null)||!is_string($item['updated']??null))throw new \InvalidArgumentException('Invalid media metadata');
                MediaStore::folder($item['folder']);$total+=$item['size'];if($total>MediaStore::MAX_TOTAL)throw new \InvalidArgumentException('Backup exceeds media quota');
                $relative='media/'.$id.'.blob';
                if(!$tar->offsetExists($relative)||$tar[$relative]->isDir()||$tar[$relative]->isLink()||$tar[$relative]->getSize()!==$item['size'])throw new \InvalidArgumentException('Missing or invalid backup blob');
                // Copy only explicitly validated fixed names; never use extractTo().
                Files::dir($stage.'/media');$file=$stage.'/'.$relative;
                Files::write($file,$tar[$relative]->getContent());
                $checked=MediaStore::inspect($file,$item['name']);
                foreach($checked as $k=>$v)if(($item[$k]??null)!==$v)throw new \InvalidArgumentException('Media integrity mismatch: '.$id);
            }
            foreach($db['pages'] as $key=>&$entry){
                if(!is_string($key)||!is_array($entry)||!is_int($entry['revision']??null)||$entry['revision']<1)throw new \InvalidArgumentException('Invalid page entry');
                $parts=explode('/',$key,3);if(count($parts)!==3||BookStore::key(...$parts)!==$key)throw new \InvalidArgumentException('Invalid page key');
                foreach(['draft','published'] as $state){
                    if(!array_key_exists($state,$entry))throw new \InvalidArgumentException('Invalid page snapshot');
                    $p=$entry[$state];if($p===null && $state==='published')continue;
                    if(!is_array($p)||!is_string($p['body']??null)||!is_string($p['title']??null)||!is_string($p['section']??null)||!is_int($p['order']??null)||!is_string($p['updated']??null)||$p['title']===''||strlen($p['title'])>200||strlen($p['section'])>100||!preg_match('//u',$p['title'].$p['section']))throw new \InvalidArgumentException('Invalid page snapshot');
                    Markdown::validate($p['body']);
                    if(isset($p['link_base'])&&(!is_string($p['link_base'])||!PageTree::validKey($p['link_base'])))throw new \InvalidArgumentException('Invalid link base');
                    if(isset($entry['published_key'])&&(!is_string($entry['published_key'])||!PageTree::validKey($entry['published_key'])))throw new \InvalidArgumentException('Invalid public path');
                    $refs=Markdown::mediaReferences($p['body'],$p['link_base']??($state==='published'?PageTree::publicKey($key,$entry):$key));
                    foreach($refs as $id)if(!isset($items[$id]))throw new \InvalidArgumentException('Page references missing media');
                    $entry[$state]['media']=$refs;
                }
            }unset($entry);
            PageTree::validate($db);
            Files::json($stage.'/books.json',$db);unset($tar);
            if(file_exists($target)||is_link($target)||!rename($stage,$target))throw new \RuntimeException('Cannot publish restored directory');
            Files::syncDir($parent);
        }finally{
            if(is_dir($stage)){foreach(glob($stage.'/media/*')?:[] as $f)unlink($f);if(is_dir($stage.'/media'))rmdir($stage.'/media');if(is_file($stage.'/books.json'))unlink($stage.'/books.json');rmdir($stage);}
        }
    }
}
