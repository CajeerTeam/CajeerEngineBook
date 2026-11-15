<?php
declare(strict_types=1);
namespace CajeerBook;
use PDO;
use CajeerEngine\Update\OperationFiles as Files;

/** Portable, streaming snapshot of retained audit chains. Restored copies are historical evidence,
 * not silently replayed as new events in a different installation namespace. */
final class AuditSnapshot
{
    public const MAX_BYTES=4*1024*1024*1024;
    private static function line($f,array $v):void{$s=json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";if(fwrite($f,$s)!==strlen($s))throw new \RuntimeException('Audit snapshot write failed.');if(ftell($f)>self::MAX_BYTES)throw new \RuntimeException('Audit snapshot exceeds portable limit; archive old events first.');}
    public static function create(string $root,string $target):array{
        $parent=DataDirectory::resolve(dirname($target),dirname(__DIR__).'/public');$name=basename($target);
        if(!is_dir($parent)||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.jsonl\z/D',$name)||$parent===$root||str_starts_with($parent,$root.'/'))throw new \InvalidArgumentException('Snapshot requires a private destination outside the live data directory.');
        $target=$parent.'/'.$name;if(file_exists($target)||is_link($target))throw new \InvalidArgumentException('Snapshot destination exists.');
        [$pdo,$id]=AuditLog::connection($root);$tmp=$parent.'/.audit-snapshot-'.bin2hex(random_bytes(16));$f=null;$pdo->beginTransaction();
        try{
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');$heads=AuditLog::verify($pdo,$id);
            $old=umask(0077);try{$f=fopen($tmp,'xb');}finally{umask($old);}if(!$f)throw new \RuntimeException('Cannot allocate snapshot.');
            self::line($f,['format'=>1,'kind'=>'audit-snapshot','installation'=>$id]);
            $q=$pdo->prepare('SELECT seq,payload,previous_hash,event_hash FROM cajeer_book.audit_event WHERE installation=? AND stream=? AND seq>? ORDER BY seq LIMIT 1000');
            foreach($heads as $stream=>$head){self::line($f,['stream'=>$stream]+$head);$seq=$head['anchor'];do{$q->execute([$id,$stream,$seq]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r){$seq=(int)$r['seq'];self::line($f,['sequence'=>$seq,'previous_hash'=>$r['previous_hash'],'hash'=>$r['event_hash'],'payload'=>$r['payload']]);}}while(count($rows)===1000);}
            self::line($f,['end'=>count($heads)]);if(!fflush($f)||!fsync($f))throw new \RuntimeException('Audit snapshot sync failed.');fclose($f);$f=null;
            $pdo->commit();$report=self::verify($tmp);if(!link($tmp,$target))throw new \RuntimeException('Cannot publish audit snapshot.');Files::syncDir($parent);return $report;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{if(is_resource($f))fclose($f);if(is_file($tmp))unlink($tmp);}
    }
    public static function verify(string $path):array{
        if(!is_file($path)||is_link($path)||filesize($path)>self::MAX_BYTES)throw new \InvalidArgumentException('Invalid audit snapshot.');$f=fopen($path,'rb');if(!$f)throw new \RuntimeException('Cannot open audit snapshot.');
        $read=static function()use($f):?array{$s=fgets($f,16384);if($s===false)return null;if(!str_ends_with($s,"\n"))throw new \RuntimeException('Truncated audit snapshot.');$v=json_decode($s,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new \RuntimeException('Invalid snapshot line.');return $v;};
        $hex=static fn($s,$n)=>is_string($s)&&strlen($s)===$n&&preg_match('/\A[a-f0-9]+\z/D',$s);
        try{$h=$read();$id=$h['installation']??null;if(($h['format']??null)!==1||($h['kind']??null)!=='audit-snapshot'||!$hex($id,32))throw new \RuntimeException('Invalid snapshot header.');$streams=[];
            while(($head=$read())!==null){if(isset($head['end'])){if($head['end']!==count($streams)||$read()!==null||!isset($streams['identity'],$streams['legacy']))throw new \RuntimeException('Snapshot footer mismatch.');return ['installation'=>$id,'streams'=>$streams];}
                $stream=$head['stream']??null;$seq=$head['anchor']??null;$hash=$head['anchor_hash']??null;$end=$head['head']??null;$tail=$head['head_hash']??null;
                if(!is_string($stream)||!preg_match('~\A(?:identity|legacy|[a-f0-9]{32}/[a-f0-9]{32})\z~D',$stream)||isset($streams[$stream])||!is_int($seq)||$seq<0||!is_int($end)||$end<$seq||!$hex($hash,64)||!$hex($tail,64)||($seq===0&&$hash!==AuditLog::ZERO)||($head['retained']??null)!==$end-$seq)throw new \RuntimeException('Invalid snapshot stream.');
                while($seq<$end){$r=$read();if(($r['sequence']??null)!==++$seq||($r['previous_hash']??null)!==$hash||!is_string($r['payload']??null)||strlen($r['payload'])>4096||!$hex($r['hash']??null,64)||!hash_equals($r['hash'],AuditLog::hash($id,$stream,$seq,$hash,$r['payload'])))throw new \RuntimeException('Snapshot chain mismatch.');$hash=$r['hash'];}
                if($hash!==$tail)throw new \RuntimeException('Snapshot head mismatch.');$streams[$stream]=$head;
            }throw new \RuntimeException('Snapshot footer missing.');
        }finally{fclose($f);}
    }
}
