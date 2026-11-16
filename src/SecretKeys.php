<?php
declare(strict_types=1);
namespace CajeerBook;

/** Operator-owned secrets, bounded key history, no secret material in status output. */
final class SecretKeys
{
    public static function readFile(string $path,int $max=8192):string
    {
        if($path===''||$path[0]!=='/'||is_link($path))throw new \RuntimeException('Secret file must be an absolute regular private file.');
        clearstatcache(true,$path);$real=realpath($path);$public=realpath(dirname(__DIR__).'/public');if($real===false||($public!==false&&str_starts_with($real,$public.'/')))throw new \RuntimeException('Secret file must be outside public.');$before=@lstat($path);
        if(!$before||($before['mode']&0170000)!==0100000||($before['mode']&0077)!==0||$before['size']<1||$before['size']>$max)throw new \RuntimeException('Invalid private secret file.');
        $h=@fopen($path,'rb');if(!$h)throw new \RuntimeException('Cannot read secret file.');
        try{$s=fstat($h);if(!$s||$s['dev']!==$before['dev']||$s['ino']!==$before['ino']||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0)throw new \RuntimeException('Secret file changed.');$raw=stream_get_contents($h,$max+1);if(!is_string($raw)||strlen($raw)!==$s['size']||strlen($raw)>$max)throw new \RuntimeException('Invalid secret file size.');return rtrim($raw,"\r\n");}finally{fclose($h);}
    }
    public static function value(string $env,int $max=4096):string
    {
        $file=getenv($env.'_FILE')?:'';$value=getenv($env)?:'';
        if($file!==''&&$value!=='')throw new \RuntimeException('Ambiguous secret configuration: '.$env);
        $v=$file!==''?self::readFile($file,$max+2):$value;
        if(strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new \RuntimeException('Invalid secret: '.$env);return $v;
    }
    /** Active first. Previous keys are read-only and explicitly retained by operator. */
    public static function ring(string $env,bool $fileOnly=false):array
    {
        $file=getenv($env.'_FILE')?:'';$legacy=$fileOnly?'':(getenv($env)?:'');
        if($file!==''&&$legacy!=='')throw new \RuntimeException('Ambiguous key configuration: '.$env);
        $raw=$file!==''?self::readFile($file):$legacy;
        if(preg_match('/\A[a-fA-F0-9]{64}\z/D',$raw))return ['legacy'=>hex2bin($raw)];
        if($file===''||$raw==='')throw new \DomainException('Configure private key: '.$env);
        try{$r=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Invalid keyring.');}
        if(!is_array($r)||count($r)!==2||!is_string($r['active']??null)||!is_array($r['keys']??null)||count($r['keys'])<1||count($r['keys'])>8||!isset($r['keys'][$r['active']]))throw new \RuntimeException('Invalid keyring.');
        $out=[];foreach($r['keys'] as $id=>$hex){if(!is_string($id)||!preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,31}\z/D',$id)||!is_string($hex)||!preg_match('/\A[a-fA-F0-9]{64}\z/D',$hex))throw new \RuntimeException('Invalid keyring entry.');$out[$id]=hex2bin($hex);}
        if(count(array_unique($out,SORT_STRING))!==count($out))throw new \RuntimeException('Duplicate key material.');
        return [$r['active']=>$out[$r['active']]]+$out;
    }
    public static function active(string $env,bool $fileOnly=false):string{return array_values(self::ring($env,$fileOnly))[0];}
    public static function decrypt(string $env,string $raw,string $aad,bool $activeOnly=false):string
    {
        foreach($activeOnly?[self::active($env)]:self::ring($env) as $key){$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$aad);if($plain!==false)return $plain;}
        throw new \DomainException('Credential cannot be decrypted with configured keys.');
    }
}
