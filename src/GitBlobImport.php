<?php
declare(strict_types=1);
namespace CajeerBook;

/** Shared bounded, content-addressed blob staging. Cache is never authoritative. */
final class GitBlobImport
{
    public static function stage(array $entries,?string $cacheDir,callable $load,int $maxTotal=ImportFiles::MAX_TOTAL):array{
        $set=new ImportFiles();$tmp=tempnam(sys_get_temp_dir(),'book-git-');if($tmp===false)throw new \RuntimeException('Cannot stage Git blob.');chmod($tmp,0600);
        $lock=null;$downloaded=0;$cached=0;$total=0;
        try{
            if($cacheDir!==null){
                \CajeerEngine\Update\OperationFiles::dir($cacheDir);$lockPath=\CajeerEngine\Update\OperationFiles::path($cacheDir,'cache.lock');$lock=fopen($lockPath,'c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \DomainException('Another Git download is running. Retry shortly.');chmod($lockPath,0600);
                $wanted=array_fill_keys(array_column($entries,'sha'),true);
                foreach(new \DirectoryIterator($cacheDir) as $file){if($file->isDot()||$file->getFilename()==='cache.lock')continue;if(!$file->isLink()&&$file->isFile()&&preg_match('/\A\.tmp-[a-f0-9]{24}\z/D',$file->getFilename())){\CajeerEngine\Update\OperationFiles::remove($file->getPathname());continue;}if($file->isLink()||!$file->isFile()||!preg_match('/\A([a-f0-9]{40})\.blob\z/D',$file->getFilename(),$m))throw new \RuntimeException('Unexpected Git cache entry.');if(!isset($wanted[$m[1]]))\CajeerEngine\Update\OperationFiles::remove($file->getPathname());}
            }
            foreach($entries as $path=>$e){
                $cache=$cacheDir===null?null:\CajeerEngine\Update\OperationFiles::path($cacheDir,$e['sha'].'.blob');$bytes=false;
                if($cache!==null&&is_file($cache)&&filesize($cache)<=MediaStore::MAX_FILE&&(!isset($e['size'])||filesize($cache)===$e['size'])){$candidate=file_get_contents($cache);if(is_string($candidate)&&sha1('blob '.strlen($candidate)."\0".$candidate)===$e['sha']){$bytes=$candidate;$cached++;}}
                if($bytes===false){$bytes=$load($e['sha']);
                    if(!is_string($bytes)||strlen($bytes)>MediaStore::MAX_FILE||(isset($e['size'])&&strlen($bytes)!==$e['size'])||sha1('blob '.strlen($bytes)."\0".$bytes)!==$e['sha'])throw new \DomainException('Git blob integrity check failed.');
                    $downloaded++;if($total+strlen($bytes)>$maxTotal)throw new \DomainException('Git documentation exceeds import limits.');
                    if($cache!==null)\CajeerEngine\Update\OperationFiles::write($cache,$bytes);
                }
                if(($total+=strlen($bytes))>$maxTotal)throw new \DomainException('Git documentation exceeds import limits.');
                $entries[$path]['size']=strlen($bytes);
                if(file_put_contents($tmp,$bytes)!==strlen($bytes))throw new \RuntimeException('Cannot stage Git blob.');clearstatcache(true,$tmp);$set->add($path,$tmp);unset($bytes,$candidate,$blob);
            }
        }finally{if(is_resource($lock))fclose($lock);@unlink($tmp);}
        return ['files'=>$set,'entries'=>$entries,'downloaded'=>$downloaded,'cached'=>$cached];
    }
}
