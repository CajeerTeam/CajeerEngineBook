<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;
require_once __DIR__.'/SecretKeys.php';

/** Streaming authenticated encryption; no tar extraction or untrusted paths. */
final class RecoveryEnvelope
{
    private const MAGIC="CAJEER-BACKUP-1\n";
    private const CHUNK=1048576;
    private const MAX_TOTAL= 536870912000; // 500 GiB, also bounded by installation quotas
    private const MAX_HEADER=262144;
    public static function key():string
    {
        if(!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'))throw new \RuntimeException('PHP sodium is required.');
        return SecretKeys::active('BOOK_BACKUP_KEY',true);
    }
    /** Authenticate only the bounded metadata frame to choose a retained key. Full open still verifies every frame. */
    public static function keyFor(string $source,string $id):string{
        RecoveryManager::id($id);if(!is_file($source)||is_link($source))throw new \RuntimeException('Invalid recovery source.');
        $in=fopen($source,'rb');if(!$in)throw new \RuntimeException('Cannot open encrypted backup.');
        try{if(self::read($in,strlen(self::MAGIC))!==self::MAGIC)throw new \RuntimeException('Unknown recovery envelope.');
            $header=self::read($in,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);$n=unpack('N',self::read($in,4))[1];
            if($n<SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES||$n>self::MAX_HEADER+SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)throw new \RuntimeException('Invalid encrypted metadata frame.');$cipher=self::read($in,$n);
            foreach(SecretKeys::ring('BOOK_BACKUP_KEY',true) as $key){$state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);$r=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$cipher);if($r===false)continue;
                $m=json_decode($r[0],true,16,JSON_THROW_ON_ERROR);if($r[1]!==0||($m['id']??null)!==$id)throw new \RuntimeException('Recovery envelope ID mismatch.');return $key;}
            throw new \RuntimeException('Backup key unavailable or authentication failed.');
        }finally{fclose($in);}
    }
    private static function name(string $name):bool{return (bool)preg_match('/\A(?:manifest\.json|identity\.json|legacy\.tar|[a-f0-9]{32}\.tar|audit-[a-f0-9]{32}\.jsonl)\z/D',$name);}
    private static function read($f,int $n):string{
        $s='';while(strlen($s)<$n){$p=fread($f,$n-strlen($s));if($p===false||$p==='')throw new \RuntimeException('Truncated recovery envelope.');$s.=$p;}return $s;
    }
    private static function write($f,string $s):void{
        $n=strlen($s);for($i=0;$i<$n;){$w=fwrite($f,substr($s,$i));if(!$w)throw new \RuntimeException('Recovery write failed.');$i+=$w;}
    }
    private static function entries(array $files):array{
        if(count($files)>Workspaces::LIMIT+103||!isset($files['manifest.json'],$files['identity.json'],$files['legacy.tar']))throw new \RuntimeException('Invalid recovery member list.');
        $total=0;foreach($files as $n=>$size){if(!is_string($n)||!self::name($n)||!is_int($size)||$size<1||$size>AuditSnapshot::MAX_BYTES||($total+=$size)>self::MAX_TOTAL)throw new \RuntimeException('Invalid recovery member size/name.');}return $files;
    }
    public static function seal(string $bundle,string $target,string $key,string $id):void{
        RecoveryManager::id($id);$files=[];foreach(new \DirectoryIterator($bundle) as $f){if($f->isDot())continue;if(!$f->isFile()||$f->isLink())throw new \RuntimeException('Invalid backup member.');$files[$f->getFilename()]=$f->getSize();}ksort($files);self::entries($files);
        $out=fopen($target,'xb');if(!$out)throw new \RuntimeException('Cannot create encrypted backup.');$ok=false;
        try{
            chmod($target,0600);[$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);self::write($out,self::MAGIC.$header);
            $push=static function(string $data,int $tag=0)use(&$state,$out){$c=sodium_crypto_secretstream_xchacha20poly1305_push($state,$data,'',$tag);self::write($out,pack('N',strlen($c)).$c);};
            $push(json_encode(['id'=>$id,'files'=>$files],JSON_THROW_ON_ERROR));
            foreach($files as $name=>$size){$in=fopen(Files::path($bundle,$name),'rb');if(!$in)throw new \RuntimeException('Cannot read backup member.');try{for($left=$size;$left>0;$left-=$n){$n=min($left,self::CHUNK);$push(self::read($in,$n));}if(fread($in,1)!=='')throw new \RuntimeException('Backup member changed.');}finally{fclose($in);}}
            $push('',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);if(!fflush($out)||!fsync($out))throw new \RuntimeException('Cannot sync encrypted backup.');$ok=true;
        }finally{fclose($out);if(!$ok)unlink($target);}
    }
    /** Destination must be an empty private staging directory, owned by caller. */
    public static function open(string $source,string $bundle,string $key,string $id):void{
        RecoveryManager::id($id);if(!is_file($source)||is_link($source)||!is_dir($bundle)||is_link($bundle)||count(scandir($bundle))!==2)throw new \RuntimeException('Invalid recovery source/staging.');
        $in=fopen($source,'rb');if(!$in)throw new \RuntimeException('Cannot open encrypted backup.');
        try{
            if(self::read($in,strlen(self::MAGIC))!==self::MAGIC)throw new \RuntimeException('Unknown recovery envelope.');
            $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($in,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),$key);
            $pull=static function(int $max,int $tag)use(&$state,$in):string{
                $n=unpack('N',self::read($in,4))[1];$over=SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
                if($n<$over||$n>$max+$over)throw new \RuntimeException('Invalid encrypted frame length.');
                $r=sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::read($in,$n));if($r===false||$r[1]!==$tag)throw new \RuntimeException('Recovery authentication failed.');return $r[0];
            };
            $meta=json_decode($pull(self::MAX_HEADER,0),true,16,JSON_THROW_ON_ERROR);if(!is_array($meta)||($meta['id']??null)!==$id||!is_array($meta['files']??null))throw new \RuntimeException('Recovery envelope ID mismatch.');$files=self::entries($meta['files']);
            foreach($files as $name=>$size){$out=fopen(Files::path($bundle,$name),'xb');if(!$out)throw new \RuntimeException('Cannot extract recovery member.');try{chmod($bundle.'/'.$name,0600);for($left=$size;$left>0;$left-=$n){$n=min($left,self::CHUNK);$s=$pull($n,0);if(strlen($s)!==$n)throw new \RuntimeException('Invalid recovery member length.');self::write($out,$s);}if(!fflush($out)||!fsync($out))throw new \RuntimeException('Cannot sync recovery member.');}finally{fclose($out);}}
            if($pull(0,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)!==''||fread($in,1)!=='')throw new \RuntimeException('Invalid recovery footer.');Files::syncDir($bundle);
        }finally{fclose($in);}
    }
}
