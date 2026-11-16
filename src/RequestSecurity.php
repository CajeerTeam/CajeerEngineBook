<?php
declare(strict_types=1);
namespace CajeerBook;

/** Validate the original wire target before routing, and supplement CSRF tokens with browser origin checks. */
final class RequestSecurity
{
    public static function path(mixed $target):string
    {
        if(!is_string($target)||$target===''||strlen($target)>16384||!str_starts_with($target,'/')||str_starts_with($target,'//')||preg_match('/[\x00-\x20\x7f\\\\#]/',$target))throw new \InvalidArgumentException('Invalid request target.');
        $path=explode('?',$target,2)[0];
        // The application uses ASCII keys, never decoded slash/backslash/dot path segments.
        // Reject ambiguous path spellings rather than normalizing differently from the proxy.
        if(str_contains($path,'//')||preg_match('~(?:^|/)\.{1,2}(?:/|$)|%(?:00|0[1-9a-f]|1[0-9a-f]|2e|2f|5c|7f|25|3f|23)|%(?![0-9a-f]{2})~i',$path))throw new \InvalidArgumentException('Ambiguous request path.');
        return $path;
    }
    private static function authority(string $value):?array
    {
        // HTTP_HOST is never used to choose a tenant here. It only compares the browser's Origin.
        if($value===''||strlen($value)>512||preg_match('/[\x00-\x20\x7f\\\\\/@?#]/',$value))return null;
        if(!preg_match('~\A(\[[0-9a-fA-F:.]+\]|[a-zA-Z0-9.-]+)(?::([0-9]{1,5}))?\z~D',$value,$m))return null;
        $host=strtolower($m[1]);
        if(str_starts_with($host,'[')&&!filter_var(substr($host,1,-1),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))return null;
        $port=isset($m[2])?(int)$m[2]:null;if($port!==null&&($port<1||$port>65535))return null;
        return [$host,$port];
    }
    /** Browser-origin protection is additional to (not a replacement for) existing CSRF.
     *  Machine CI/webhooks have separate bearer/signature authentication. */
    public static function browserPost(string $path,string $method,array $server,?string $primary):bool
    {
        if($method!=='POST'||$path==='/auth/oidc/backchannel-logout'||preg_match('~\A(?:/w/[a-f0-9]{32}/[a-f0-9]{32})?/(?:api/v1/ci|webhooks/(?:github|gitlab))\z~D',$path))return true;
        $site=$server['HTTP_SEC_FETCH_SITE']??null;
        if($site!==null&&(!is_string($site)||!in_array($site,['same-origin','none'],true)))return false;
        $origin=$server['HTTP_ORIGIN']??null;if($origin===null)return true; // CLI/legacy browsers: CSRF still mandatory.
        if(!is_string($origin)||strlen($origin)>512||!preg_match('~\A(https?)://([^/]+)\z~D',$origin,$m))return false;
        $o=self::authority($m[2]);$h=self::authority($primary??(is_string($server['HTTP_HOST']??null)?$server['HTTP_HOST']:''));
        if($o===null||$h===null||$o[0]!==$h[0])return false;
        $default=$m[1]==='https'?443:80;
        if(($o[1]??$default)!==($h[1]??$default))return false;
        if($primary!==null&&$m[1]!=='https')return false;
        if($primary===null&&isset($server['HTTPS'])){
            $scheme=!empty($server['HTTPS'])&&$server['HTTPS']!=='off'?'https':'http';
            if($m[1]!==$scheme)return false;
        }
        return true;
    }
}
