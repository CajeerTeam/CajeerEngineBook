<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;

/** Bounded upload/ZIP reader. Archive names are never used as filesystem paths. */
final class ImportFiles
{
    public const MAX_FILES=200, MAX_TOTAL=64*1024*1024, MAX_ARCHIVE=20*1024*1024;
    private string $temp='';
    private array $files=[];
    private int $bytes=0;
    public function __construct(){
        $this->temp=sys_get_temp_dir().'/book-import-'.bin2hex(random_bytes(16));
        if(!mkdir($this->temp,0700))throw new \RuntimeException('Cannot create import staging directory');
    }
    public function __destruct(){if($this->temp==='')return;foreach(glob($this->temp.'/*')?:[] as $p)if(is_file($p)&&!is_link($p))@unlink($p);if($this->temp!=='')@rmdir($this->temp);}
    public static function path(string $name):string{
        if(strlen($name)>512||!preg_match('//u',$name)||preg_match('~[\\\\:\x00-\x1f\x7f]|^/|//|(?:^|/)\.{1,2}(?:/|$)~',$name)||$name===''||str_ends_with($name,'/')||count(explode('/',$name))>24)throw new \InvalidArgumentException('Unsafe import path');
        foreach(explode('/',$name) as $part)if(strlen($part)>180||trim($part)!==$part||$part==='')throw new \InvalidArgumentException('Invalid import path component');
        return $name;
    }
    private function reserve(string $name,int $size):string{
        $name=self::path($name);
        if(isset($this->files[$name]))throw new \InvalidArgumentException('Duplicate import path: '.$name);
        foreach($this->files as $existing=>$unused)if(str_starts_with($name,$existing.'/')||str_starts_with((string)$existing,$name.'/'))throw new \InvalidArgumentException('Import file/directory collision');
        if(count($this->files)>=self::MAX_FILES||$size<0||$size>MediaStore::MAX_FILE||$this->bytes+$size>self::MAX_TOTAL)throw new \InvalidArgumentException('Import exceeds file or expanded size limit');
        $this->bytes+=$size;return $this->temp.'/'.bin2hex(random_bytes(16));
    }
    public function add(string $name,string $source):void{
        if(is_link($source)||!is_file($source))throw new \InvalidArgumentException('Import source is not a regular file');
        $in=@fopen($source,'rb');if(!$in)throw new \InvalidArgumentException('Cannot read source');
        $dest=null;$out=null;$size=0;$reserved=false;
        try{
            $stat=fstat($in);if(!$stat||($stat['mode']&0170000)!==0100000)throw new \InvalidArgumentException('Import source is not a regular file');
            $size=$stat['size'];$dest=$this->reserve($name,$size);$reserved=true;
            $out=fopen($dest,'xb');if(!$out||!chmod($dest,0600))throw new \RuntimeException('Cannot stage import file');
            if(stream_copy_to_stream($in,$out,$size+1)!==$size||!feof($in)||!fflush($out)||!fsync($out))throw new \InvalidArgumentException('Import source changed or could not be copied');
            $this->files[$name]=$dest;$reserved=false;
        }finally{if(is_resource($out))fclose($out);fclose($in);if($reserved){$this->bytes-=$size;if(is_string($dest)&&is_file($dest))@unlink($dest);}}
    }
    /** CI uploads are decoded into random private staging files, never archive paths. */
    public function addBytes(string $name,string $bytes):void{
        $size=strlen($bytes);$dest=$this->reserve($name,$size);try{Files::write($dest,$bytes);$this->files[$name]=$dest;}catch(\Throwable $e){$this->bytes-=$size;@unlink($dest);throw $e;}
    }
    public function zip(string $source):void{
        if(!class_exists(\ZipArchive::class))throw new \RuntimeException('PHP zip extension is required');
        if(is_link($source)||!is_file($source)||filesize($source)>self::MAX_ARCHIVE)throw new \InvalidArgumentException('ZIP exceeds 20 MiB');
        $zip=new \ZipArchive();if($zip->open($source,\ZipArchive::RDONLY|\ZipArchive::CHECKCONS)!==true)throw new \InvalidArgumentException('Invalid ZIP archive');
        $before=$this->files;$beforeBytes=$this->bytes;$staged=[];
        try{
            if($zip->numFiles>self::MAX_FILES*2)throw new \InvalidArgumentException('Too many ZIP entries');
            $seen=[];
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);if($stat===false)throw new \InvalidArgumentException('Invalid ZIP entry');
                $name=$stat['name'];$dir=str_ends_with($name,'/');$path=self::path($dir?substr($name,0,-1):$name);
                if(isset($seen[$path]))throw new \InvalidArgumentException('Duplicate ZIP entry: '.$path);$seen[$path]=$dir;
                $opsys=$attr=0;if(!$zip->getExternalAttributesIndex($i,$opsys,$attr))throw new \InvalidArgumentException('Invalid ZIP attributes');
                $type=($attr>>16)&0170000;
                if($opsys===\ZipArchive::OPSYS_UNIX&&$type!==0&&$type!==($dir?0040000:0100000))throw new \InvalidArgumentException('ZIP contains a symlink or special file');
                if(($stat['encryption_method']??0)!==0)throw new \InvalidArgumentException('Encrypted ZIP entries are not supported');
                if($dir){if($stat['size']!==0)throw new \InvalidArgumentException('Invalid ZIP directory');continue;}
                $dest=$this->reserve($path,$stat['size']);$staged[]=$dest;
                $in=$zip->getStreamIndex($i);$out=fopen($dest,'xb');
                try{if(!$in||!$out)throw new \InvalidArgumentException('Cannot read ZIP entry');if(!chmod($dest,0600))throw new \RuntimeException('Cannot protect staged entry');$n=stream_copy_to_stream($in,$out,$stat['size']+1);if($n!==$stat['size']||!feof($in)||!fflush($out))throw new \InvalidArgumentException('ZIP entry size mismatch');}
                finally{if(is_resource($in))fclose($in);if(is_resource($out))fclose($out);}
                if(hash_file('crc32b',$dest)!==sprintf('%08x',$stat['crc']))throw new \InvalidArgumentException('ZIP entry checksum mismatch');
                $this->files[$path]=$dest;
            }
            foreach($seen as $path=>$dir){$parent=dirname((string)$path);while($parent!=='.'){if(isset($seen[$parent])&&!$seen[$parent])throw new \InvalidArgumentException('ZIP file/directory collision');$parent=dirname($parent);}}
        }catch(\Throwable $e){foreach($staged as $dest)@unlink($dest);$this->files=$before;$this->bytes=$beforeBytes;throw $e;}finally{$zip->close();}
    }
    public static function uploads(array $uploads,string $mode,?array $paths=null):self{
        $set=new self();
        if(!in_array($mode,['files','directory','zip'],true)||!isset($uploads['name'])||!is_array($uploads['name']))throw new \InvalidArgumentException('Select import files');
        if(count($uploads['name'])>self::MAX_FILES||($mode==='zip'&&count($uploads['name'])!==1))throw new \InvalidArgumentException('Invalid upload count');
        if($paths!==null&&($mode!=='directory'||!array_is_list($paths)||!array_is_list($uploads['name'])||count($paths)!==count($uploads['name'])))throw new \InvalidArgumentException('Invalid directory upload paths');
        $root=null;
        foreach($uploads['name'] as $i=>$name){
            $tmp=$uploads['tmp_name'][$i]??null;$path=$mode==='directory'?($paths!==null?$paths[$i]:($uploads['full_path'][$i]??null)):$name;
            if(!is_string($name)||!is_string($path)||!is_string($tmp)||($uploads['error'][$i]??null)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp))throw new \InvalidArgumentException('File upload failed or exceeded server limit');
            if($mode==='directory'){self::path($path);if($paths!==null&&basename($path)!==$name)throw new \InvalidArgumentException('Directory upload filename mismatch');$parts=explode('/',$path,2);if(count($parts)!==2)throw new \InvalidArgumentException('Directory upload must include its root folder');$root??=$parts[0];if($root!==$parts[0])throw new \InvalidArgumentException('Choose one directory');$path=$parts[1];}
            if($mode==='zip')$set->zip($tmp);else $set->add($path,$tmp);
        }
        return $set;
    }
    public function files():array{$files=$this->files;ksort($files,SORT_STRING);return $files;}
}
