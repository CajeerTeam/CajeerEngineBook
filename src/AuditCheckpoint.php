<?php
declare(strict_types=1);
namespace CajeerBook;
use PDO;
use CajeerEngine\Update\OperationFiles as Files;

/** Signed, externally retained checkpoint. Private signing seed is operator-only. */
final class AuditCheckpoint
{
    private const DOMAIN="cajeer-book-audit-checkpoint-v1\n";
    private static function hex(mixed $value,int $length):bool{return is_string($value)&&strlen($value)===$length&&ctype_xdigit($value)&&strtolower($value)===$value;}
    private static function payload(array $p):string{
        if(array_keys($p)!==['format','installation','created','streams']||$p['format']!==1||!self::hex($p['installation'],32)||!is_int($p['created'])||$p['created']<1||!is_array($p['streams'])||count($p['streams'])>Workspaces::LIMIT+2||!isset($p['streams']['identity'],$p['streams']['legacy']))throw new \RuntimeException('Invalid audit checkpoint.');
        foreach($p['streams'] as $stream=>$h)if(!is_string($stream)||!preg_match('~\A(?:identity|legacy|[a-f0-9]{32}/[a-f0-9]{32})\z~D',$stream)||!is_array($h)||array_keys($h)!==['sequence','hash']||!is_int($h['sequence'])||$h['sequence']<0||!self::hex($h['hash'],64)||($h['sequence']===0&&$h['hash']!==AuditLog::ZERO))throw new \RuntimeException('Invalid audit checkpoint stream.');
        return self::DOMAIN.json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    public static function sign(array $payload,string $seed):array{
        if(strlen($seed)!==SODIUM_CRYPTO_SIGN_SEEDBYTES)throw new \RuntimeException('Invalid audit signing seed.');$kp=sodium_crypto_sign_seed_keypair($seed);$secret=sodium_crypto_sign_secretkey($kp);
        try{return ['payload'=>$payload,'signature'=>bin2hex(sodium_crypto_sign_detached(self::payload($payload),$secret))];}finally{sodium_memzero($secret);sodium_memzero($kp);}
    }
    public static function verify(array $checkpoint,string $public):array{
        if(strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||array_keys($checkpoint)!==['payload','signature']||!is_array($checkpoint['payload'])||!self::hex($checkpoint['signature'],128)||!sodium_crypto_sign_verify_detached(hex2bin($checkpoint['signature']),self::payload($checkpoint['payload']),$public))throw new \RuntimeException('Audit checkpoint signature invalid.');return $checkpoint['payload'];
    }
    private static function key(string $file):string{$hex=SecretKeys::readFile($file,66);if(!preg_match('/\A[a-fA-F0-9]{64}\z/D',$hex))throw new \RuntimeException('Invalid audit key file.');return hex2bin($hex);}
    public static function create(string $root,string $target,string $seedFile):array{
        $parent=DataDirectory::resolve(dirname($target),dirname(__DIR__).'/public');if($parent===$root||str_starts_with($parent,$root.'/')||!is_dir($parent)||(fileperms($parent)&0077)!==0||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.json\z/D',basename($target)))throw new \InvalidArgumentException('Checkpoint requires a new file in a private external directory.');$target=$parent.'/'.basename($target);
        [$pdo,$id]=AuditLog::connection($root);$heads=AuditLog::verify($pdo,$id);$streams=[];foreach($heads as $name=>$head)$streams[$name]=['sequence'=>$head['head'],'hash'=>$head['head_hash']];
        $seed=self::key($seedFile);try{$checkpoint=self::sign(['format'=>1,'installation'=>$id,'created'=>time(),'streams'=>$streams],$seed);}finally{sodium_memzero($seed);}
        $tmp=$parent.'/.checkpoint-'.bin2hex(random_bytes(16));try{Files::write($tmp,json_encode($checkpoint,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");if(!link($tmp,$target))throw new \RuntimeException('Cannot publish checkpoint without overwrite.');Files::syncDir($parent);}finally{if(is_file($tmp))unlink($tmp);}
        return ['status'=>'signed','installation'=>$id,'streams'=>count($streams)];
    }
    /** Compare with a consistent retained SQL suffix. If retention passed the checkpoint, fail closed. */
    public static function check(string $root,string $file,string $publicFile):array{
        $raw=SecretKeys::readFile($file,131072);$checkpoint=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$p=self::verify($checkpoint,self::key($publicFile));[$pdo,$id]=AuditLog::connection($root);
        if($p['installation']!==$id)throw new \RuntimeException('Checkpoint installation mismatch.');$pdo->beginTransaction();
        try{$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');$heads=AuditLog::verify($pdo,$id);$q=$pdo->prepare('SELECT event_hash FROM cajeer_book.audit_event WHERE installation=? AND stream=? AND seq=?');
            foreach($p['streams'] as $stream=>$h){$now=$heads[$stream]??null;$seq=$h['sequence'];if(!$now||$seq>$now['head']||$seq<$now['anchor'])throw new \RuntimeException('Checkpoint outside retained chain; verify archived evidence separately.');
                if($seq===$now['anchor'])$hash=$now['anchor_hash'];else{$q->execute([$id,$stream,$seq]);$hash=$q->fetchColumn();}
                if(!is_string($hash)||!hash_equals($h['hash'],$hash))throw new \RuntimeException('Audit checkpoint chain mismatch.');}
            $pdo->commit();return ['status'=>'verified','installation'=>$id,'streams'=>count($p['streams'])];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
