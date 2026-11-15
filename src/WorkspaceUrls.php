<?php
declare(strict_types=1);
namespace CajeerBook;

/** Request-local URL namespace. Canonical Markdown and store keys remain unprefixed. */
final class WorkspaceUrls
{
    private static string $prefix='';
    public static function set(string $prefix):void{if($prefix!==''&&!preg_match('~\A/w/[a-f0-9]{32}/[a-f0-9]{32}\z~D',$prefix))throw new \InvalidArgumentException('Invalid workspace prefix');self::$prefix=$prefix;}
    public static function prefix():string{return self::$prefix;}
    public static function url(string $url):string{
        if(self::$prefix===''||!preg_match('~\A/(?:studio|docs|media|api/v1|search|playground|sdk|webhooks)(?:/|[?#]|$)~D',$url))return $url;
        return self::$prefix.$url;
    }
    public static function markdown(string $url):string{
        return self::$prefix===''?$url:self::url(self::normalize($url));
    }
    public static function normalize(string $url):string{
        if(!str_starts_with($url,'/')||str_starts_with($url,'//'))return $url;
        $n=strcspn($url,'?#');$path=substr($url,0,$n);$suffix=substr($url,$n);$parts=[];
        foreach(explode('/',$path) as $part){$decoded=rawurldecode($part);if($decoded===''||$decoded==='.')continue;if($decoded==='..'){array_pop($parts);continue;}if(str_contains($decoded,'/')||str_contains($decoded,'\\'))return '#';$parts[]=$part;}
        return '/'.implode('/',$parts).$suffix;
    }
}
