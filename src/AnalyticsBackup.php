<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** Portable, consent-only aggregate snapshots; no raw visitor identifiers are stored. */
final class AnalyticsBackup
{
    public const MAX_BYTES = 64 * 1024 * 1024;
    public const MAX_DAYS = 3660;
    private const MAX_DAY_BYTES = 2097152;

    public static function day(string $name):string
    {
        if(!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\.json\z/D',$name,$m)
            || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new \InvalidArgumentException('Invalid analytics snapshot filename.');
        return substr($name,0,10);
    }
    /** Validate data on both sides of the backup trust boundary. */
    public static function validate(string $name,string $raw):void
    {
        $day=self::day($name);
        if(strlen($raw)<2||strlen($raw)>self::MAX_DAY_BYTES)throw new \RuntimeException('Analytics snapshot exceeds per-day limit.');
        $data=json_decode($raw,true,12,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_diff(array_keys($data),['day','events','dimensions'])||($data['day']??null)!==$day
            ||!is_array($data['events']??null)||!is_array($data['dimensions']??[])
            ||count($data['events'])>1000||count($data['dimensions']??[])>1000)throw new \RuntimeException('Invalid analytics snapshot.');
        foreach($data['events'] as $key=>$hits){
            if(!is_string($key)||!is_int($hits)||$hits<0||$hits>1000000000)throw new \RuntimeException('Invalid analytics event counter.');
            $parts=explode('|',$key,2);
            if(count($parts)!==2||!in_array($parts[0],['page_view','search','link_click','download','feedback'],true)||Analytics::pagePath($parts[1])!==$parts[1])throw new \RuntimeException('Invalid analytics event.');
        }
        foreach($data['dimensions']??[] as $key=>$hits){
            if(!is_string($key)||!is_int($hits)||$hits<0||$hits>1000000000)throw new \RuntimeException('Invalid analytics dimension counter.');
            $parts=explode('|',$key,2);
            if(count($parts)!==2||!in_array($parts[0],['search_query','conversion'],true))throw new \RuntimeException('Invalid analytics dimension.');
            if($parts[0]==='search_query'){
                if(Analytics::query($parts[1])!==$parts[1])throw new \RuntimeException('Invalid analytics search dimension.');
            }elseif(!preg_match('/\A[a-z][a-z0-9_]{1,39}\z/D',$parts[1]))throw new \RuntimeException('Invalid analytics conversion.');
        }
    }
    /** Lock the independent analytics writer while copying the exact daily bytes. */
    public static function snapshot(string $root):array
    {
        $dir=Files::path($root,'analytics');
        if(!file_exists($dir)&&!is_link($dir))return [];
        if(!is_dir($dir)||is_link($dir))throw new \RuntimeException('Invalid analytics directory.');
        $lock=Files::path($dir,'analytics.lock');
        if(is_link($lock))throw new \RuntimeException('Unsafe analytics lock.');
        $handle=fopen($lock,'c+b');if(!$handle)throw new \RuntimeException('Cannot lock analytics snapshot.');
        try{
            if(!flock($handle,LOCK_SH))throw new \RuntimeException('Cannot lock analytics snapshot.');
            $out=[];$size=0;
            foreach(new \DirectoryIterator($dir) as $entry){
                if($entry->isDot()||$entry->getFilename()==='analytics.lock')continue;
                $name=$entry->getFilename();self::day($name);
                if(!$entry->isFile()||$entry->isLink()||$entry->getSize()>self::MAX_DAY_BYTES||count($out)>=self::MAX_DAYS)throw new \RuntimeException('Invalid analytics snapshot member.');
                $bytes=file_get_contents($entry->getPathname());
                if($bytes===false)throw new \RuntimeException('Cannot read analytics snapshot.');
                self::validate($name,$bytes);$size+=strlen($bytes);
                if($size>self::MAX_BYTES)throw new \RuntimeException('Analytics snapshot quota exceeded.');
                $out[$name]=$bytes;
            }
            ksort($out,SORT_STRING);return $out;
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
}
