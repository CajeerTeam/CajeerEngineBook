<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;
final class BookStore {
    public function __construct(private readonly string $dir) {}
    public static function slug(string $s): bool { return preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$s)===1 && !in_array($s,['.','..'],true); }
    public static function key(string $book,string $version,string $path): string {
        if (!self::slug($book)||!self::slug($version)||strlen($path)>250||!$path) throw new \InvalidArgumentException('Invalid book/version/path');
        foreach(explode('/',$path) as $part) if(!self::slug($part)) throw new \InvalidArgumentException('Invalid page path');
        return "$book/$version/$path";
    }
    public function read(): array {
        $file=$this->dir.'/books.json';
        if(is_link($file)) throw new \RuntimeException('Unsafe store');
        return is_file($file)?Files::read($file):require dirname(__DIR__).'/data/seed.php';
    }
    /** Hold a shared lock while opening immutable blobs or building a consistent backup. */
    public function snapshot(callable $fn): mixed {
        Files::dir($this->dir); $lock=$this->dir.'/books.lock';
        if(is_link($lock)) throw new \RuntimeException('Unsafe lock');
        $h=fopen($lock,'c+'); if(!$h) throw new \RuntimeException('Lock unavailable');
        try { if(!flock($h,LOCK_SH)) throw new \RuntimeException('Lock failed'); return $fn($this->read()); }
        finally { fclose($h); }
    }
    public function change(callable $fn): void {
        Files::dir($this->dir); $lock=$this->dir.'/books.lock';
        if(is_link($lock)) throw new \RuntimeException('Unsafe lock');
        $h=fopen($lock,'c+'); if(!$h) throw new \RuntimeException('Lock unavailable');
        try { if(!flock($h,LOCK_EX)) throw new \RuntimeException('Lock failed'); $db=$this->read(); $fn($db); Files::json($this->dir.'/books.json',$db); }
        finally { fclose($h); }
    }
    public function save(array $input, bool $publish): string {
        return $this->saveResult($input,$publish)['key'];
    }
    /** Save and return the exact revision/publication state committed under the store lock. */
    public function saveResult(array $input, bool $publish): array {
        $key=self::key((string)$input['book'],(string)$input['version'],(string)$input['path']);
        $title=trim((string)$input['title']); $body=(string)$input['body']; $section=trim((string)$input['section']);
        if($title===''||strlen($title)>200||strlen($section)>100||!preg_match('//u',$title.$section)) throw new \InvalidArgumentException('Invalid page fields');
        Markdown::validate($body);
        $media=Markdown::mediaReferences($body,$key);
        $page=['media'=>$media,'title'=>$title,'section'=>$section,'order'=>max(-9999,min(9999,(int)$input['order'])),'body'=>$body,'updated'=>gmdate('c')];
        $result=[];
        $this->change(function(array &$db)use($key,$page,$publish,$input,&$result){
            foreach($page['media'] as $id) if(!isset($db['media'][$id])) throw new \InvalidArgumentException('Unknown media file: '.$id);
            $old=$db['pages'][$key]??['draft'=>null,'published'=>null,'revision'=>0];
            if((int)($input['revision']??0)!==$old['revision']) throw new \DomainException('Page changed. Reload before saving.');
            if(count($db['pages'])>=1000&&!isset($db['pages'][$key])) throw new \DomainException('Page limit reached');
            $old['draft']=$page; if($publish)$old['published']=$page; $old['revision']++;
            $db['pages'][$key]=$old;
            $result=['key'=>$key,'revision'=>$old['revision'],'published'=>$old['published']!==null];
        }); return $result;
    }
    public function remove(string $key,int $revision,bool $unpublish): void {
        $this->change(function(array &$db)use($key,$revision,$unpublish){
            if(!isset($db['pages'][$key])||$db['pages'][$key]['revision']!==$revision) throw new \DomainException('Page changed. Reload.');
            if($unpublish){$db['pages'][$key]['published']=null;$db['pages'][$key]['revision']++;}else unset($db['pages'][$key]);
        });
    }
    public function copyVersion(string $book,string $from,string $to): void {
        self::key($book,$from,'index');self::key($book,$to,'index'); if($from===$to)throw new \InvalidArgumentException('Choose another version');
        $this->change(function(array &$db)use($book,$from,$to){
            $prefix="$book/$from/";$target="$book/$to/";$copy=[];
            foreach($db['pages'] as $key=>$entry){if(str_starts_with($key,$target))throw new \DomainException('Target version exists');if(str_starts_with($key,$prefix)&&$entry['published']!==null)$copy[$target.substr($key,strlen($prefix))]=['draft'=>$entry['published'],'published'=>null,'revision'=>1];}
            if(!$copy||count($db['pages'])+count($copy)>1000)throw new \DomainException('No published source pages or page limit');$db['pages']+=$copy;
        });
    }
    public static function published(array $db): array {
        $pages=[]; foreach($db['pages'] as $key=>$entry)if($entry['published']!==null)$pages[$key]=$entry['published'];
        uksort($pages,fn($a,$b)=>[$pages[$a]['order'],$a]<=>[$pages[$b]['order'],$b]); return $pages;
    }
}
