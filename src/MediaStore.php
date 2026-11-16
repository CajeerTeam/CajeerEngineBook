<?php
declare(strict_types=1);
namespace CajeerBook;
require_once __DIR__.'/Observability.php';

use CajeerEngine\Update\OperationFiles as Files;

/** Private, immutable blobs; metadata commits share the BookStore transaction. */
final class MediaStore
{
    public const MAX_FILE = 20 * 1024 * 1024;
    public const MAX_TOTAL = 512 * 1024 * 1024;
    public const MAX_FILES = 1000;
    private const TYPES = [
        'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'gif'=>'image/gif', 'webp'=>'image/webp',
        'pdf'=>'application/pdf', 'zip'=>'application/zip', 'txt'=>'text/plain', 'md'=>'text/plain', 'csv'=>'text/plain',
    ];
    public function __construct(private readonly string $dir, private readonly BookStore $books) {}
    public static function validId(string $id): bool { return preg_match('/\A[a-f0-9]{32}\z/D',$id)===1; }
    public static function name(string $name): string
    {
        $name=trim($name);
        if ($name==='' || strlen($name)>180 || !preg_match('//u',$name) || preg_match('~[\\x00-\\x1f\\x7f/\\\\]~',$name) || $name==='.' || $name==='..') throw new \InvalidArgumentException('Invalid file name');
        return $name;
    }
    public static function folder(string $folder): string
    {
        $folder=trim($folder);
        if ($folder==='') return '';
        if($folder==='@root') throw new \InvalidArgumentException('Reserved folder name');
        if (strlen($folder)>240 || count(explode('/',$folder))>8) throw new \InvalidArgumentException('Invalid folder');
        foreach(explode('/',$folder) as $part) if(self::name($part)!==$part) throw new \InvalidArgumentException('Invalid folder');
        return $folder;
    }
    public function blob(string $id): string
    {
        if(!self::validId($id)) throw new \InvalidArgumentException('Invalid media ID');
        return Files::path($this->dir,'media/'.$id.'.blob');
    }
    /** MIME is determined by file contents, not the browser or the filename alone. */
    public static function inspect(string $path,string $name): array
    {
        $name=self::name($name); $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!isset(self::TYPES[$ext]) || is_link($path) || !is_file($path)) throw new \InvalidArgumentException('Unsupported file type');
        $size=filesize($path);
        if($size===false || $size<1 || $size>self::MAX_FILE) throw new \InvalidArgumentException('File must be 1 byte to 20 MiB');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path); $expected=self::TYPES[$ext];
        if ($expected==='text/plain') {
            if(!in_array($mime,['text/plain','text/csv','application/csv','text/markdown','text/x-markdown'],true)) throw new \InvalidArgumentException('File content does not match its extension');
            $text=file_get_contents($path);
            if($text===false || !preg_match('//u',$text) || str_contains($text,"\0")) throw new \InvalidArgumentException('Text files must be UTF-8');
        } elseif($mime!==$expected && !($expected==='application/zip' && $mime==='application/x-zip-compressed')) throw new \InvalidArgumentException('File content does not match its extension');
        $width=$height=null;
        if(str_starts_with($expected,'image/')) {
            $info=@getimagesize($path);
            if(!$info || ($info['mime']??'')!==$expected || $info[0]<1 || $info[1]<1 || $info[0]>4096 || $info[1]>4096 || $info[0]*$info[1]>12000000) throw new \InvalidArgumentException('Invalid image or image exceeds 4096 px / 12 megapixels');
            [$width,$height]=$info;
            if(function_exists('imagecreatefromstring')){
                $bytes=file_get_contents($path);$decoded=$bytes===false?false:@imagecreatefromstring($bytes);unset($bytes);
                if($decoded===false)throw new \InvalidArgumentException('Invalid or unsupported image data');
                unset($decoded);
            }
        }
        return ['name'=>$name,'mime'=>$expected,'size'=>$size,'sha256'=>Files::hash($path),'width'=>$width,'height'=>$height];
    }
    /** HTTP layer checks is_uploaded_file; CLI/tests may use trusted local files. */
    public function upload(string $source,string $name,string $folder=''): array
    {
        $meta=self::inspect($source,$name);$folder=self::folder($folder);$created=null;$result=[];
        try {
            $this->books->change(function(array &$db)use($source,$meta,$folder,&$created,&$result){
                $db['media']??=[];$total=0;
                foreach($db['media'] as $id=>$item){
                    $total+=$item['size'];
                    if($item['sha256']===$meta['sha256'] && $item['mime']===$meta['mime']){
                        $path=$this->blob($id);
                        if(!is_file($path) || Files::hash($path)!==$item['sha256']) throw new \RuntimeException('Existing media blob is damaged');
                        $result=['item'=>$item,'duplicate'=>true];return;
                    }
                }
                if(count($db['media'])>=self::MAX_FILES || $total+$meta['size']>self::MAX_TOTAL) throw new \DomainException('Media quota exceeded');
                do{$id=bin2hex(random_bytes(16));$path=$this->blob($id);}while(file_exists($path));
                $created=$path;Files::copy($source,$path);
                if(Files::hash($path)!==$meta['sha256']) throw new \RuntimeException('Upload changed while copying');
                $item=$meta+['id'=>$id,'folder'=>$folder,'created'=>gmdate('c'),'updated'=>gmdate('c'),'revision'=>1];
                $db['media'][$id]=$item;$result=['item'=>$item,'duplicate'=>false];
            });
        } catch(\Throwable $e){
            // A filesystem sync can fail after the metadata rename: never remove a committed blob.
            if($created!==null && is_file($created)){
                try{$id=basename($created,'.blob');if(!isset($this->books->read()['media'][$id])) Files::remove($created);}catch(\Throwable $cleanup){Observability::log('cleanup.failure',['error_type'=>get_class($cleanup)]);}
            }
            throw $e;
        }
        return $result;
    }
    public static function references(array $db,string $id,bool $publishedOnly=false): array
    {
        $refs=[];
        foreach($db['pages'] as $key=>$entry){
            foreach($publishedOnly?['published']:['draft','published'] as $state){
                $p=$entry[$state]??null;
                if($p===null) continue;
                // Published access is granted only by validated AST references recorded at save time.
                // For deletion also conservatively retain literal references in draft/code/source.
                if(in_array($id,$p['media']??[],true) || (!$publishedOnly && str_contains($p['body'],'/media/'.$id))) $refs[]=$key.':'.$state;
            }
        }
        if(!$publishedOnly)$refs=array_merge($refs,RevisionHistory::mediaReferences($db,$id),ReviewWorkflow::mediaIndex($db)[$id]??[],CiPublishing::mediaIndex($db)[$id]??[]);
        return $refs;
    }
    /** One pass for the paginated library, rather than scanning the entire database for each card. */
    public static function referenceIndex(array $db): array
    {
        $all=$published=[];
        foreach($db['pages'] as $key=>$entry){
            foreach(['draft','published'] as $state){
                $p=$entry[$state]??null;if($p===null)continue;
                $ids=$p['media']??[];$label=$key.':'.$state;
                if($state==='published')foreach($ids as $id)$published[$id][]=$label;
                preg_match_all('~/media/([a-f0-9]{32})~',$p['body'],$matches);
                foreach(array_unique(array_merge($ids,$matches[1])) as $id)$all[$id][]=$label;
            }
        }
        foreach(array_merge_recursive(RevisionHistory::mediaIndex($db),ReviewWorkflow::mediaIndex($db),CiPublishing::mediaIndex($db)) as $id=>$labels)$all[$id]=array_merge($all[$id]??[],$labels);
        return ['all'=>$all,'published'=>$published];
    }
    public function update(string $id,int $revision,string $name,string $folder): void
    {
        $name=self::name($name);$folder=self::folder($folder);
        $this->books->change(function(array &$db)use($id,$revision,$name,$folder){
            $item=self::requireItem($db,$id,$revision);
            if((self::TYPES[strtolower(pathinfo($name,PATHINFO_EXTENSION))]??null)!==$item['mime']) throw new \InvalidArgumentException('Renaming must preserve the file type');
            $item['name']=$name;$item['folder']=$folder;$item['revision']++;$item['updated']=gmdate('c');$db['media'][$id]=$item;
        });
    }
    private static function requireItem(array $db,string $id,int $revision): array
    {
        if(!self::validId($id) || !isset($db['media'][$id])) throw new \InvalidArgumentException('Media not found');
        $item=$db['media'][$id];if($revision!==$item['revision']) throw new \DomainException('File changed. Reload the library.');return $item;
    }
    public function delete(string $id,int $revision): void
    {
        $this->books->change(function(array &$db)use($id,$revision){
            self::requireItem($db,$id,$revision);
            if(self::references($db,$id)) throw new \DomainException('File is used by a draft, published page, retained history or review');
            unset($db['media'][$id]);
        });
        // ID is never reused. Delete only after commit; already-open readers can finish.
        foreach(['blob','thumbPath','thumbDigestPath'] as $method)try{Files::remove($this->$method($id));}catch(\Throwable $e){Observability::log('cleanup.failure',['error_type'=>get_class($e)]);}
    }
    /** Explicit maintenance for blobs left by interrupted transactions. Never follows symlinks. */
    public function collectOrphans(): int
    {
        $removed=0;
        $this->books->change(function(array &$db)use(&$removed){
            $directory=Files::path($this->dir,'media');if(!is_dir($directory))return;
            foreach(new \DirectoryIterator($directory) as $entry){
                if($entry->isDot()||$entry->isLink()||!$entry->isFile())continue;
                $name=$entry->getFilename();
                if(preg_match('/\A([a-f0-9]{32})\.(?:blob|thumb\.png|thumb\.sha256)\z/D',$name,$m)){
                    if(isset($db['media'][$m[1]]))continue;
                }elseif(!preg_match('/\A\.tmp-[a-f0-9]{24}\z/D',$name))continue;
                Files::remove($entry->getPathname());$removed++;
            }
        });
        return $removed;
    }
    private function thumbPath(string $id): string
    {
        if(!self::validId($id))throw new \InvalidArgumentException('Invalid media ID');
        return Files::path($this->dir,'media/'.$id.'.thumb.png');
    }
    private function thumbDigestPath(string $id):string{return Files::path($this->dir,'media/'.$id.'.thumb.sha256');}
    /** Copy and authenticate before sending headers. A later disk change cannot alter the verified response. */
    private static function verifiedStream(string $path,array $item)
    {
        if(is_link($path)||!is_file($path))throw new \RuntimeException('Missing media blob');
        $in=@fopen($path,'rb');if(!$in)throw new \RuntimeException('Cannot open media');
        $out=null;
        try{
            $size=$item['size']??null;$sha=$item['sha256']??null;
            if(!is_int($size)||$size<1||$size>self::MAX_FILE||!is_string($sha)||!preg_match('/\A[a-f0-9]{64}\z/D',$sha)||fstat($in)['size']!==$size)throw new \RuntimeException('Damaged media blob');
            $out=fopen('php://temp/maxmemory:2097152','w+b');if(!$out)throw new \RuntimeException('Cannot allocate verified media stream');
            $hash=hash_init('sha256');$remaining=$size;
            while($remaining>0){$chunk=fread($in,min(65536,$remaining));if(!is_string($chunk)||$chunk===''||fwrite($out,$chunk)!==strlen($chunk))throw new \RuntimeException('Interrupted media read');hash_update($hash,$chunk);$remaining-=strlen($chunk);}
            if(fread($in,1)!==''||!hash_equals($sha,hash_final($hash))||!rewind($out))throw new \RuntimeException('Damaged media blob');
            $result=$out;$out=null;return $result;
        }finally{fclose($in);if(is_resource($out))fclose($out);}
    }
    private function openVerified(string $id,array $item)
    {
        $start=hrtime(true);$ok=false;
        try{$stream=self::verifiedStream($this->blob($id),$item);$ok=true;return $stream;}
        catch(\Throwable $e){Observability::mediaIntegrityFailure();Observability::log('media.integrity_failure',['component'=>'media','error_type'=>get_class($e)]);throw $e;}
        finally{Observability::operation('media_verify',$start,$ok);}
    }
    /** Repair exact bytes only, using the authoritative metadata under the same scope lock as deletion. */
    public function repair(string $id,string $source):void
    {
        if(!self::validId($id))throw new \InvalidArgumentException('Invalid media ID');
        $this->books->change(function(array &$db)use($id,$source){
            $item=$db['media'][$id]??null;if(!$item)throw new \InvalidArgumentException('Media not found');
            $stream=self::verifiedStream($source,$item);
            try{$bytes=stream_get_contents($stream);if(!is_string($bytes)||strlen($bytes)!==$item['size'])throw new \RuntimeException('Cannot read repair source');Files::write($this->blob($id),$bytes);}finally{fclose($stream);}
            foreach(['thumbPath','thumbDigestPath'] as $method)try{Files::remove($this->$method($id));}catch(\Throwable $e){Observability::log('cleanup.failure',['error_type'=>get_class($e)]);}
        });
        Observability::log('media.repaired',['component'=>'media','outcome'=>'ok']);
    }
    /** Derived previews are expendable. Authenticate the original and the cached bytes before use. */
    public function thumbnail(string $id,?callable $authorized=null,?array $publishedAccess=null): ?array
    {
        if(!self::validId($id)||!function_exists('imagecreatefromstring'))return null;
        $make=function(array $item)use($id){
            if(!str_starts_with($item['mime'],'image/'))return null;
            $source=$this->openVerified($id,$item);
            try{
                $path=$this->thumbPath($id);$digestPath=$this->thumbDigestPath($id);$png=null;
                if(is_file($path)&&is_file($digestPath)){
                    $digest=@file_get_contents($digestPath,false,null,0,131);$candidate=@file_get_contents($path,false,null,0,1048577);
                    if(is_string($candidate)&&strlen($candidate)<=1048576&&is_string($digest)&&hash_equals($item['sha256'].':'.hash('sha256',$candidate),$digest))$png=$candidate;
                }
                if($png===null){
                    $bytes=stream_get_contents($source);$original=is_string($bytes)?@imagecreatefromstring($bytes):false;unset($bytes);
                    if($original===false)return null;
                    $ratio=min(1,320/max($item['width'],$item['height']));$w=max(1,(int)round($item['width']*$ratio));$h=max(1,(int)round($item['height']*$ratio));
                    $canvas=imagecreatetruecolor($w,$h);if($canvas===false)return null;
                    imagealphablending($canvas,false);imagesavealpha($canvas,true);imagecopyresampled($canvas,$original,0,0,0,0,$w,$h,$item['width'],$item['height']);unset($original);
                    ob_start();try{$ok=imagepng($canvas);$png=ob_get_contents();}finally{ob_end_clean();}
                    if(!$ok||!is_string($png))return null;
                    try{Files::write($path,$png);Files::write($digestPath,$item['sha256'].':'.hash('sha256',$png));}catch(\Throwable $e){Observability::log('media.preview_cache_failure',['error_type'=>get_class($e)]);}
                }
                $stream=fopen('php://temp','w+b');if(!$stream)throw new \RuntimeException('Cannot allocate thumbnail');
                if(fwrite($stream,$png)!==strlen($png)||!rewind($stream)){fclose($stream);throw new \RuntimeException('Cannot buffer thumbnail');}
                return [strlen($png),$stream];
            }finally{fclose($source);}
        };
        if($publishedAccess!==null)return $this->books->publishedMedia($id,$publishedAccess[0],$publishedAccess[1],$make);
        return $this->books->snapshot(function(array $db)use($id,$authorized,$make){$item=$db['media'][$id]??null;return !$item||($authorized&&!$authorized($db,$id))?null:$make($item);});
    }
    public function open(string $id,bool $private,?callable $authorized=null,?array $publishedAccess=null): ?array
    {
        if(!self::validId($id)) return null;
        $open=fn(array $item)=>[$item,$this->openVerified($id,$item)];
        if(!$private&&$publishedAccess!==null)return $this->books->publishedMedia($id,$publishedAccess[0],$publishedAccess[1],$open);
        return $this->books->snapshot(function(array $db)use($id,$private,$authorized,$open){
            $item=$db['media'][$id]??null;
            if(!$item || (!$private && !($authorized?$authorized($db,$id):self::references($db,$id,true)))) return null;
            return $open($item);
        });
    }
}
