<?php
declare(strict_types=1);
namespace CajeerBook;

/** Public Reader host registry. Domain ownership is a renewable DNS lease, not a login credential. */
final class CustomDomains
{
    public const LEASE=604800;
    public const RECHECK=86400;
    public const LIMIT=5;
    public static function name(string $value):string
    {
        $name=strtolower($value);
        if(strlen($name)>240||!preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',$name)||filter_var($name,FILTER_VALIDATE_IP)||preg_match('/\.(?:localhost|local|internal|invalid|test|onion|example)\z/D',$name))throw new \InvalidArgumentException('Use a public DNS hostname (ASCII or IDNA A-label), without scheme, port, wildcard or trailing dot.');
        return $name;
    }
    public static function primary():?string
    {
        $v=getenv('BOOK_PRIMARY_HOST');return $v===false||$v===''?null:self::name($v);
    }
    public static function requestHost(mixed $host):?string
    {
        if(!is_string($host)||strlen($host)>259)return null;
        // Only default HTTP(S) ports; do not accept forwarded host headers or URL syntax.
        $host=preg_replace('/:(?:80|443)\z/D','',$host);
        try{return self::name($host);}catch(\InvalidArgumentException){return null;}
    }
    public static function eligible(array $db,array $w):bool
    {
        return !$w['legacy']&&!$w['archived']&&!($db['organizations'][$w['organization']]['archived']??true)&&Visibility::workspace($w)==='public';
    }
    public static function active(array $r,?int $now=null):bool
    {
        $now??=time();return !$r['disabled']&&$r['verified']>0&&$r['verified']<=$now&&$r['verified']+self::LEASE>$now;
    }
    public static function record():array{return ['token'=>bin2hex(random_bytes(32)),'verified'=>0,'checked'=>0,'disabled'=>false];}
    public static function challenge(array $r):string{return 'cajeer-book='.$r['token'];}
    public static function dnsName(string $host):string{return '_cajeer-book.'.self::name($host);}
    /** DNS-only lookup of the exact TXT name; no HTTP requests or user-supplied resolver. */
    public static function proof(string $host,string $token,?callable $lookup=null):?bool
    {
        $name=self::dnsName($host);$records=$lookup?$lookup($name):@dns_get_record($name.'.',DNS_TXT);
        if(!is_array($records))return null;
        foreach($records as $r){if(!is_array($r)||strtoupper((string)($r['type']??''))!=='TXT'||strtolower(rtrim((string)($r['host']??''),'.'))!==$name)continue;
            $text=$r['txt']??null;if(is_array($r['entries']??null))$text=implode('',$r['entries']);
            if(is_string($text)&&hash_equals('cajeer-book='.$token,$text))return true;
        }
        return false;
    }
    public static function validate(array $db):void
    {
        $seen=[];
        foreach($db['workspaces']??[] as $w){$domains=$w['domains']??[];
            if(!is_array($domains)||count($domains)>self::LIMIT||($w['legacy']&&$domains))throw new \RuntimeException('Invalid domain registry');
            foreach($domains as $host=>$r){
                if(!is_string($host)||self::name($host)!==$host||isset($seen[$host])||!is_array($r)||array_diff(array_keys($r),['token','verified','checked','disabled'])||!is_string($r['token']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$r['token'])||!is_int($r['verified']??null)||$r['verified']<0||!is_int($r['checked']??null)||$r['checked']<0||!is_bool($r['disabled']??null)||($r['disabled']&&$r['verified']!==0))throw new \RuntimeException('Invalid or duplicate domain binding');
                $seen[$host]=true;
            }
            if(isset($w['primary_domain'])&&(!is_string($w['primary_domain'])||!isset($domains[$w['primary_domain']])))throw new \RuntimeException('Invalid primary domain');
        }
    }
    public static function find(array $db,string $host):?array
    {
        if($host===self::primary())return null;
        foreach($db['workspaces']??[] as $w)if(isset($w['domains'][$host])&&self::eligible($db,$w)&&self::active($w['domains'][$host]))return $w;
        return null;
    }
    public static function canonical(array $w,string $fallback):string
    {
        $preferred=$w['primary_domain']??'';
        if(isset($w['domains'][$preferred])&&$preferred!==self::primary()&&self::active($w['domains'][$preferred]))return $preferred;
        return $fallback;
    }
    public static function publicOrigin(?array $w):?string
    {
        if($w===null||$w['legacy']||$w['archived']||Visibility::workspace($w)!=='public'||self::primary()===null)return null;
        $host=self::canonical($w,'');return $host!==''?'https://'.$host:null;
    }
    public static function status(array $r):string{return $r['disabled']?'disabled':(self::active($r)?'verified':($r['checked']?'unverified / expired':'pending'));}
    /** Restore must never reactivate a historic claim after a domain has been reassigned. */
    public static function resetRestored(array &$db):void
    {
        foreach($db['workspaces'] as &$w){foreach($w['domains']??[] as $host=>$r){$w['domains'][$host]=self::record();$w['domains'][$host]['disabled']=$r['disabled'];}}unset($w);
    }
    public static function readerPath(string $path):bool
    {
        return in_array($path,['/','/search','/api/v1/search','/api/v1/pages','/diagram-frame','/sitemap.xml','/robots.txt'],true)||preg_match('~\A/(?:docs|media|api/v1/pages|l/(?:en|ru)/docs)/[^\\\\\x00-\x20]*\z~D',$path)===1;
    }
}
