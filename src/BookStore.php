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
        $page=['media'=>[],'title'=>$title,'section'=>$section,'order'=>max(-9999,min(9999,(int)$input['order'])),'body'=>$body,'updated'=>gmdate('c')];
        $result=[];
        $this->change(function(array &$db)use($key,$page,$publish,$input,&$result){
            $old=$db['pages'][$key]??['draft'=>null,'published'=>null,'revision'=>0];
            if((int)($input['revision']??0)!==$old['revision']) throw new \DomainException('Page changed. Reload before saving.');
            if(count($db['pages'])>=1000&&!isset($db['pages'][$key])) throw new \DomainException('Page limit reached');
            $old['id']??=isset($db['pages'][$key])?PageTree::id($key,$old):bin2hex(random_bytes(16));
            if(PageTree::reserved($db,$key,$old['id']))throw new \DomainException('Page path is reserved');
            $navigationChanged=$old['draft']===null||$old['draft']['title']!==$page['title']||$old['draft']['order']!==$page['order'];
            $draft=$page;if(isset($old['draft']['link_base']))$draft['link_base']=$old['draft']['link_base'];
            $draft['media']=Markdown::mediaReferences($draft['body'],$draft['link_base']??$key);
            foreach($draft['media'] as $id)if(!isset($db['media'][$id]))throw new \InvalidArgumentException('Unknown media file: '.$id);
            $old['draft']=$draft;
            if($publish){$publicKey=$old['published']!==null?PageTree::publicKey($key,$old):$key;$snapshot=$draft;
                if($old['published']===null&&isset($old['published_key'])&&$old['published_key']!==$key)PageTree::remember($db,$old['published_key'],$old['id']);
                if($old['published']!==null){$snapshot['order']=$old['published']['order'];if($publicKey!==$key)$snapshot['link_base']??=$key;}
                $old['published']=$snapshot;$old['published_key']=$publicKey;
            } $old['revision']++;
            $db['pages'][$key]=$old;
            $result=['key'=>$key,'revision'=>$old['revision'],'published'=>$old['published']!==null,'id'=>$old['id'],'navigation_changed'=>$navigationChanged];
        }); return $result;
    }
    public function remove(string $key,int $revision,bool $unpublish): void {
        $this->change(function(array &$db)use($key,$revision,$unpublish){
            if(!isset($db['pages'][$key])||$db['pages'][$key]['revision']!==$revision) throw new \DomainException('Page changed. Reload.');
            $e=&$db['pages'][$key];$e['id']??=PageTree::id($key,$e);
            if($e['published']!==null||isset($e['published_key'])){
                $publicKey=PageTree::publicKey($key,$e);$e['published_key']=$publicKey;
                if(!$unpublish)PageTree::remember($db,$publicKey,$e['id']);
            }
            if($unpublish){$e['published']=null;$e['revision']++;}else {PageTree::retire($db,$key,$e['id']);unset($db['pages'][$key]);}
        });
    }
    public function copyVersion(string $book,string $from,string $to): void {
        self::key($book,$from,'index');self::key($book,$to,'index'); if($from===$to)throw new \InvalidArgumentException('Choose another version');
        $this->change(function(array &$db)use($book,$from,$to){
            $prefix="$book/$from/";$target="$book/$to/";$copy=[];$ids=[];
            foreach($db['pages'] as $key=>$entry){
                if(str_starts_with($key,$target))throw new \DomainException('Target version exists');
                $publicKey=PageTree::publicKey($key,$entry);
                if(str_starts_with($publicKey,$prefix)&&$entry['published']!==null){
                    $dest=$target.substr($publicKey,strlen($prefix));$id=bin2hex(random_bytes(16));
                    if(PageTree::reserved($db,$dest,$id))throw new \DomainException('Target URL is reserved');
                    $page=$entry['published'];if(isset($page['link_base']))$page['link_base']=$target.substr($page['link_base'],strlen($prefix));
                    $copy[$dest]=['id'=>$id,'draft'=>$page,'published'=>null,'revision'=>1];$ids[PageTree::id($key,$entry)]=$id;
                }
            }
            if(!$copy||count($db['pages'])+count($copy)>1000)throw new \DomainException('No published source pages or page limit');$db['pages']+=$copy;
            foreach(['redirects','retired_paths'] as $field)foreach($db[$field]??[] as $alias=>$sourceId){
                if(!str_starts_with($alias,$prefix)||!isset($ids[$sourceId]))continue;
                $dest=$target.substr($alias,strlen($prefix));$id=$ids[$sourceId];
                if(PageTree::reserved($db,$dest,$id)||(isset($copy[$dest])&&$copy[$dest]['id']!==$id))throw new \DomainException('Target redirect is reserved');
                if($field==='redirects')PageTree::remember($db,$dest,$id);else PageTree::retire($db,$dest,$id);
            }
        });
    }
    public static function published(array $db): array {
        $pages=[]; foreach($db['pages'] as $key=>$entry)if($entry['published']!==null)$pages[PageTree::publicKey($key,$entry)]=$entry['published']+['id'=>PageTree::id($key,$entry)];
        return PageTree::ordered($pages);
    }
}
