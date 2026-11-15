<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;
require_once __DIR__.'/RevisionHistory.php';
require_once __DIR__.'/ReviewWorkflow.php';
require_once __DIR__.'/ActivityJournal.php';
require_once __DIR__.'/GitHubSync.php';
require_once __DIR__.'/GitHubClient.php';
require_once __DIR__.'/ImportFiles.php';
final class BookStore {
    public const MAX_DB_BYTES = 32 * 1024 * 1024;
    private ?array $historyActor=null;
    public function setHistoryActor(array $user):void { $this->historyActor=['id'=>$user['id'],'username'=>$user['username']]; }
    public function __construct(private readonly string $dir, private readonly bool $seed=true) {}
    public static function slug(string $s): bool { return preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$s)===1 && !in_array($s,['.','..'],true); }
    public static function key(string $book,string $version,string $path): string {
        if (!self::slug($book)||!self::slug($version)||strlen($path)>250||!$path) throw new \InvalidArgumentException('Invalid book/version/path');
        foreach(explode('/',$path) as $part) if(!self::slug($part)) throw new \InvalidArgumentException('Invalid page path');
        return "$book/$version/$path";
    }
    public function read(): array {
        $file=Files::path($this->dir,'books.json');
        if(is_link($file)) throw new \RuntimeException('Unsafe store');
        if(file_exists($file)&&!is_file($file))throw new \RuntimeException('Invalid store file');
        if(!is_file($file)&&(!$this->seed||glob(Files::path($this->dir,'media').'/*.blob')))throw new \RuntimeException('Workspace content store missing; restore its backup.');
        $db=is_file($file)?Files::read($file):require dirname(__DIR__).'/data/seed.php';
        if(!in_array($db['schema']??null,[1,2],true)||!is_array($db['pages']??null)||!is_array($db['media']??[]))throw new \RuntimeException('Unsupported or corrupt book store');
        try{self::validatePages($db);}catch(\InvalidArgumentException $e){throw new \RuntimeException('Invalid persisted page tree',0,$e);}
        self::books($db);
        if(isset($db['history']))RevisionHistory::validate($db);
        ReviewWorkflow::validate($db);
        ActivityJournal::validate($db);
        GitHubSync::validate($db);
        return $db;
    }
    /** Validate the trust boundary before any projection, including public book visibility. */
    private static function validatePages(array $db):void {
        if(!is_array($db['pages']??null)||count($db['pages'])>1000)throw new \RuntimeException('Invalid page collection');
        foreach($db['pages'] as $entry){
            if(!is_array($entry)||!is_int($entry['revision']??null)||$entry['revision']<1||!array_key_exists('published',$entry))throw new \RuntimeException('Invalid page revision');
            foreach(['draft','published'] as $state){
                $p=$entry[$state]??null;if($state==='published'&&$p===null)continue;
                if(!is_array($p)||!is_string($p['title']??null)||trim($p['title'])===''||strlen($p['title'])>200||!is_string($p['section']??null)||strlen($p['section'])>100||!is_int($p['order']??null)||!is_string($p['body']??null)||strlen($p['body'])>262144||!preg_match('//u',$p['title'].$p['section'].$p['body'])||!is_string($p['updated']??null)||!is_array($p['media']??[]))throw new \RuntimeException('Invalid page snapshot');
                foreach($p['media']??[] as $id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \RuntimeException('Invalid page media reference');
            }
        }
        PageTree::validate($db);
    }
    /** Freeze the initial legacy seed before binding the root to an organization. */
    public function materialize():void {
        Files::dir($this->dir);$lock=Files::path($this->dir,'books.lock');$h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Lock unavailable');
        try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Lock failed');$db=$this->read();if(!is_file($this->dir.'/books.json'))self::writeDatabase($this->dir.'/books.json',$db);}finally{fclose($h);}
    }
    /** Hold a shared lock while opening immutable blobs or building a consistent backup. */
    public function snapshot(callable $fn): mixed {
        Files::dir($this->dir); $lock=$this->dir.'/books.lock';
        if(is_link($lock)) throw new \RuntimeException('Unsafe lock');
        $h=fopen($lock,'c+'); if(!$h) throw new \RuntimeException('Lock unavailable');
        try { if(!flock($h,LOCK_SH)) throw new \RuntimeException('Lock failed'); return $fn($this->read()); }
        finally { fclose($h); }
    }
    public function change(callable $fn,?string $historyAction=null,string $historyNote='',?string $historySource=null): void {
        Files::dir($this->dir); $lock=$this->dir.'/books.lock';
        if(is_link($lock)) throw new \RuntimeException('Unsafe lock');
        $h=fopen($lock,'c+'); if(!$h) throw new \RuntimeException('Lock unavailable');
        try { if(!flock($h,LOCK_EX)) throw new \RuntimeException('Lock failed'); $db=$this->read(); $before=$db; $fn($db); if($historyAction!==null)ReviewWorkflow::trackAuthors($before,$db,$this->historyActor,$historyAction); ReviewWorkflow::refresh($db); if($historyAction!==null)RevisionHistory::capture($before,$db,$historyAction,$this->historyActor,$historyNote,$historySource); self::books($db); ActivityJournal::content($before,$db,$this->historyActor,$historyAction); self::writeDatabase($this->dir.'/books.json',$db); }
        finally { fclose($h); }
    }
    /** Keep all new snapshots within the portable backup limit, before any rename. */
    public static function writeDatabase(string $path,array $db): void {
        self::validatePages($db);
        self::books($db);
        if(isset($db['history']))RevisionHistory::validate($db);
        ReviewWorkflow::validate($db);
        ActivityJournal::validate($db);
        GitHubSync::validate($db);
        $json=json_encode($db,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        if(strlen($json)>self::MAX_DB_BYTES){
            clearstatcache(true,$path);
            $old=is_file($path)&&!is_link($path)?filesize($path):0;
            // Legacy installations larger than the portable limit may shrink in stages.
            if($old===false||$old<=self::MAX_DB_BYTES||strlen($json)>=$old)throw new \DomainException('Book data exceeds 32 MiB; remove content or split the installation.');
        }
        Files::write($path,$json);
    }
    public function checkReady(): void {
        $this->snapshot(function(array $db): void {
            if(count($db['pages'])>1000||count($db['media']??[])>MediaStore::MAX_FILES)throw new \RuntimeException('Store record quota exceeded');
            foreach($db['pages'] as $entry){
                if(!is_int($entry['revision']??null)||$entry['revision']<1||!array_key_exists('published',$entry))throw new \RuntimeException('Invalid page record');
                foreach(['draft','published'] as $state){
                    $p=$entry[$state]??null;if($p===null&&$state==='published')continue;
                    if(!is_array($p)||!is_string($p['body']??null)||!is_string($p['title']??null)||!is_string($p['section']??null)||!is_int($p['order']??null)||!is_string($p['updated']??null))throw new \RuntimeException('Invalid page snapshot');
                }
            }
            PageTree::validate($db);
            if(is_file($this->dir.'/books.json')&&filesize($this->dir.'/books.json')>self::MAX_DB_BYTES)throw new \RuntimeException('Legacy store exceeds the portable data limit');
            $probe=$this->dir.'/.ready-'.bin2hex(random_bytes(12));
            try { Files::write($probe,'ready'); } finally { if(is_file($probe))Files::remove($probe); }
            $media=Files::path($this->dir,'media');
            if(file_exists($media)&&(!is_dir($media)||!is_writable($media)))throw new \RuntimeException('Media directory unavailable');
            $total=0;$store=new MediaStore($this->dir,$this);
            foreach($db['media']??[] as $id=>$item){
                if(!is_string($id)||!MediaStore::validId($id)||!is_array($item)||($item['id']??null)!==$id||!is_int($item['size']??null)||$item['size']<1||$item['size']>MediaStore::MAX_FILE)throw new \RuntimeException('Invalid media metadata');
                $blob=$store->blob($id);if(!is_file($blob)||!is_readable($blob)||filesize($blob)!==$item['size'])throw new \RuntimeException('Missing or damaged media blob');
                $total+=$item['size'];
            }
            if($total>MediaStore::MAX_TOTAL)throw new \RuntimeException('Media quota exceeded');
        });
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
            if($publish)ReviewWorkflow::guard($db,'page',$key);
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
            if($publish)self::publishSnapshot($db,$key,$old); $old['revision']++;
            [$book]=explode('/',$key,2);$books=self::books($db);
            if(!isset($books[$book])){if(count($books)>=1000)throw new \DomainException('Book limit reached');$books[$book]=['slug'=>$book,'id'=>bin2hex(random_bytes(16)),'title'=>$book];}
            $db['pages'][$key]=$old;$db['books']=$books;
            $result=['key'=>$key,'revision'=>$old['revision'],'published'=>$old['published']!==null,'id'=>$old['id'],'navigation_changed'=>$navigationChanged];
        },$publish?'publish':'save',RevisionHistory::note((string)($input['note']??''))); return $result;
    }
    /** Shared publication mutation. Callers authorize the direct or reviewed publication under books.lock. */
    public static function publishSnapshot(array &$db,string $key,array &$entry,?string $review=null):void {
        ReviewWorkflow::guard($db,'page',$key,$review);
        $publicKey=$entry['published']!==null?PageTree::publicKey($key,$entry):$key;$snapshot=$review===null?$entry['draft']:ReviewWorkflow::get($db,$review)['snapshot']['draft'];$snapshot['media']??=[];
        if($entry['published']===null&&isset($entry['published_key'])&&$entry['published_key']!==$key)PageTree::remember($db,$entry['published_key'],$entry['id']);
        if($entry['published']!==null){$snapshot['order']=$entry['published']['order'];if($publicKey!==$key)$snapshot['link_base']??=$key;}
        $entry['published']=$snapshot;$entry['published_key']=$publicKey;
    }
    /** All review state transitions share the page/history transaction. */
    public function review(array $actor,array $users,string $action,array $fields):string {
        $id=$fields['id']??'';$result=$id;$note=$fields['note']??'';
        // The immutable kind decides which history event is written. A concurrent request cannot alter it.
        $history=null;if($action==='publish'){$r=ReviewWorkflow::get($this->read(),$id);$history=$r['kind']==='tree'?'publish-tree':'publish';}
        $this->setHistoryActor($actor);
        $this->change(function(array &$db)use($actor,$users,$action,$fields,$id,$note,&$result){
            $rev=filter_var($fields['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($action==='submit'){$result=ReviewWorkflow::submit($db,$actor,$users,$fields['kind']??'',$fields['target']??'',$fields['expected']??'',$fields['reviewer']??'',$note);return;}
            if($rev===false)throw new \InvalidArgumentException('Invalid review revision');
            if($action==='policy'){if(!in_array($fields['required']??null,['0','1'],true))throw new \InvalidArgumentException('Invalid review policy');ReviewWorkflow::setPolicy($db,$actor,$users,$rev,$fields['required']==='1');$result='';return;}
            if($action==='publish'){ReviewWorkflow::publish($db,$actor,$users,$id,$rev,$note);return;}
            if($action==='prune'){ReviewWorkflow::prune($db,$actor,$users,$id,$rev);$result='';return;}
            ReviewWorkflow::decide($db,$actor,$users,$id,$rev,$action,$note);
        },$history,$history?'Review '.$id:'');return $result;
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
        },$unpublish?'unpublish':'delete');
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
        },'copy');
    }
    public function restoreRevision(string $page,string $id,string $state,int $revision,string $head,string $note=''):string {
        $key='';$this->change(function(&$db)use($page,$id,$state,$revision,$head,&$key){$key=RevisionHistory::restore($db,$page,$id,$state,$revision,$head);},'restore',$note,$id);return $key;
    }
    /** Books are real persisted records, with deterministic IDs for pre-registry content. */
    public static function books(array $db):array{
        $books=$db['books']??[];if(!is_array($books)||count($books)>1000)throw new \RuntimeException('Invalid book registry');$ids=[];
        foreach($books as $slug=>$b){if(!self::slug((string)$slug)||!is_array($b)||($b['slug']??null)!==(string)$slug||!is_string($b['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$b['id'])||isset($ids[$b['id']])||!is_string($b['title']??null)||trim($b['title'])===''||strlen($b['title'])>200||!preg_match('//u',$b['title']))throw new \RuntimeException('Invalid book record');$ids[$b['id']]=true;}
        foreach($books as $b)if(!in_array(array_key_exists('visibility',$b)?$b['visibility']:'inherit',['inherit','public','private'],true)||!is_int(array_key_exists('visibility_revision',$b)?$b['visibility_revision']:1)||($b['visibility_revision']??1)<1)throw new \RuntimeException('Invalid book visibility');
        foreach($db['pages'] as $key=>$entry){$slug=explode('/',$key,2)[0];if(!isset($books[$slug]))$books[$slug]=['slug'=>$slug,'id'=>substr(hash('sha256','legacy-book:'.$slug),0,32),'title'=>$slug];}
        if(count($books)>1000)throw new \RuntimeException('Book quota exceeded');ksort($books,SORT_STRING);return $books;
    }
    public function createBook(string $slug,string $title):void{
        $title=trim($title);if(!self::slug($slug)||$title===''||strlen($title)>200||!preg_match('//u',$title)||preg_match('/[\x00-\x1f\x7f]/',$title))throw new \InvalidArgumentException('Invalid book fields');
        $this->change(function(&$db)use($slug,$title){$books=self::books($db);if(isset($books[$slug]))throw new \DomainException('Book already exists');if(count($books)>=1000)throw new \DomainException('Book limit reached');$books[$slug]=['slug'=>$slug,'id'=>bin2hex(random_bytes(16)),'title'=>$title];$db['books']=$books;});
    }
    public function setBookVisibility(string $slug,string $visibility,int $revision):void{
        if(!in_array($visibility,['inherit','public','private'],true)||$revision<1)throw new \InvalidArgumentException('Invalid book visibility');
        $this->change(function(&$db)use($slug,$visibility,$revision){$books=self::books($db);$b=$books[$slug]??null;if(!$b)throw new \InvalidArgumentException('Unknown book');if(($b['visibility_revision']??1)!==$revision)throw new \DomainException('Book visibility changed. Reload.');$b['visibility']=$visibility;$b['visibility_revision']=$revision+1;$books[$slug]=$b;$db['books']=$books;});
    }
    public static function published(array $db): array {
        $pages=[]; foreach($db['pages'] as $key=>$entry)if($entry['published']!==null)$pages[PageTree::publicKey($key,$entry)]=$entry['published']+['id'=>PageTree::id($key,$entry)];
        return PageTree::ordered($pages);
    }
}
