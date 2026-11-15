<?php
declare(strict_types=1);
namespace CajeerBook;

use PDO;
use CajeerEngine\Update\OperationFiles as Files;

/** Operator-only retention. Archive a bounded contiguous prefix, durably publish it,
 * then advance the anchor and delete that prefix in the same database transaction. */
final class AuditArchive
{
    private static function q(PDO $pdo,string $sql,array $args=[]):\PDOStatement{$q=$pdo->prepare($sql);$q->execute($args);return $q;}
    private static function json(array $a):string{return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
    private static function write($f,array $a):void{$s=self::json($a);if(fwrite($f,$s)!==strlen($s))throw new \RuntimeException('Audit archive write failed.');}
    private static function path(string $root,string $target):string{
        $parent=DataDirectory::resolve(dirname($target),dirname(__DIR__).'/public');$name=basename($target);
        if(!is_dir($parent)||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.jsonl\z/D',$name))throw new \InvalidArgumentException('Archive must be a new .jsonl file in an existing private directory.');
        if($parent===$root||str_starts_with($parent,$root.'/'))throw new \InvalidArgumentException('Archive must be outside the live data directory.');
        $path=$parent.'/'.$name;if(file_exists($path)||is_link($path))throw new \InvalidArgumentException('Archive destination exists.');return $path;
    }
    public static function prune(string $root,string $stream,int $days,string $target):array{
        if($days<1||$days>36500)throw new \InvalidArgumentException('Retention must be 1–36500 days.');
        $target=self::path($root,$target);[$pdo,$id]=AuditLog::connection($root);AuditLog::assertReady($pdo);
        $pdo->beginTransaction();$tmp=null;$f=null;
        try{
            $h=self::q($pdo,'SELECT * FROM cajeer_book.audit_stream WHERE installation=? AND stream=? FOR UPDATE',[$id,$stream])->fetch(PDO::FETCH_ASSOC);
            if(!$h)throw new \InvalidArgumentException('Unknown audit stream.');
            $start=(int)$h['anchor_seq'];$seq=$start;$hash=$h['anchor_hash'];$cutoff=time()-$days*86400;
            $rows=self::q($pdo,'SELECT seq,payload,previous_hash,event_hash,at_epoch FROM cajeer_book.audit_event WHERE installation=? AND stream=? AND seq>? ORDER BY seq LIMIT 10000',[$id,$stream,$start])->fetchAll(PDO::FETCH_ASSOC);
            // Never skip a newer row; even if the clock moved backwards, remove only a prefix.
            $prefix=[];foreach($rows as $r){if((int)$r['seq']!==$seq+1||$r['previous_hash']!==$hash||!hash_equals($r['event_hash'],AuditLog::hash($id,$stream,$seq+1,$hash,$r['payload'])))throw new \RuntimeException('Audit chain mismatch.');
                if((int)$r['at_epoch']>=$cutoff)break;
                $e=json_decode($r['payload'],true,16,JSON_THROW_ON_ERROR);if($e['at']!==(int)$r['at_epoch'])throw new \RuntimeException('Audit timestamp mismatch.');
                $seq++;$hash=$r['event_hash'];$prefix[]=['sequence'=>$seq,'previous_hash'=>$r['previous_hash'],'hash'=>$hash,'payload'=>$r['payload']];
            }
            if(!$prefix){$pdo->commit();return ['archived'=>0,'stream'=>$stream];}
            $tmp=dirname($target).'/.audit-'.bin2hex(random_bytes(16));$old=umask(0077);try{$f=fopen($tmp,'xb');}finally{umask($old);}if(!$f)throw new \RuntimeException('Cannot create archive staging file.');
            self::write($f,['format'=>1,'installation'=>$id,'stream'=>$stream,'anchor'=>$start,'anchor_hash'=>$h['anchor_hash'],'cutoff'=>$cutoff]);foreach($prefix as $row)self::write($f,$row);
            self::write($f,['end'=>$seq,'hash'=>$hash,'count'=>count($prefix)]);if(!fflush($f)||!fsync($f))throw new \RuntimeException('Audit archive sync failed.');fclose($f);$f=null;
            $checked=self::verify($tmp);if($checked['end']!==$seq||$checked['hash']!==$hash)throw new \RuntimeException('Audit archive verification failed.');
            if(!link($tmp,$target))throw new \RuntimeException('Cannot publish audit archive.');Files::syncDir(dirname($target));
            self::q($pdo,'UPDATE cajeer_book.audit_stream SET anchor_seq=?,anchor_hash=? WHERE installation=? AND stream=?',[$seq,$hash,$id,$stream]);
            $n=self::q($pdo,'DELETE FROM cajeer_book.audit_event WHERE installation=? AND stream=? AND seq>? AND seq<=?',[$id,$stream,$start,$seq])->rowCount();if($n!==count($prefix))throw new \RuntimeException('Audit archive count mismatch.');
            // Preserve the retention operation in the SAME stream, avoiding cross-stream lock ordering.
            AuditLog::append($pdo,$id,$stream,[AuditLog::event('audit.retention')]);
            $pdo->commit();return ['archived'=>$n,'stream'=>$stream,'anchor'=>$seq,'anchor_hash'=>$hash,'file'=>$target,'sha256'=>Files::hash($target)];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{if(is_resource($f))fclose($f);if($tmp!==null&&is_file($tmp))unlink($tmp);}
    }
    /** Streaming verification, including archive boundary and exact JSON payload bytes. */
    public static function verify(string $file):array{
        if(!is_file($file)||is_link($file))throw new \InvalidArgumentException('Invalid archive file.');$f=fopen($file,'rb');if(!$f)throw new \RuntimeException('Cannot open archive.');
        $read=static function()use($f):?array{$line=fgets($f,16384);if($line===false)return null;if(!str_ends_with($line,"\n"))throw new \RuntimeException('Truncated or oversized audit archive line.');$a=json_decode($line,true,16,JSON_THROW_ON_ERROR);if(!is_array($a))throw new \RuntimeException('Invalid archive record.');return $a;};
        try{$h=$read();if(($h['format']??null)!==1||!is_string($h['installation']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$h['installation'])||!is_string($h['stream']??null)||!preg_match('~\A(?:identity|legacy|[a-f0-9]{32}/[a-f0-9]{32})\z~D',$h['stream'])||!is_int($h['anchor']??null)||$h['anchor']<0||!is_string($h['anchor_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$h['anchor_hash'])||!is_int($h['cutoff']??null))throw new \RuntimeException('Invalid audit archive header.');
            $seq=$h['anchor'];$hash=$h['anchor_hash'];$count=0;
            while(($r=$read())!==null){if(isset($r['end'])){if($r['end']!==$seq||($r['hash']??null)!==$hash||($r['count']??null)!==$count||$count===0||$read()!==null)throw new \RuntimeException('Audit archive footer mismatch.');return ['installation'=>$h['installation'],'stream'=>$h['stream'],'anchor'=>$h['anchor'],'anchor_hash'=>$h['anchor_hash'],'end'=>$seq,'hash'=>$hash,'count'=>$count];}
                if(($r['sequence']??null)!==++$seq||($r['previous_hash']??null)!==$hash||!is_string($r['payload']??null)||strlen($r['payload'])>4096||!is_string($r['hash']??null)||!hash_equals($r['hash'],AuditLog::hash($h['installation'],$h['stream'],$seq,$hash,$r['payload'])))throw new \RuntimeException('Audit archive chain mismatch.');
                $e=json_decode($r['payload'],true,16,JSON_THROW_ON_ERROR);if(!is_int($e['at']??null)||$e['at']>=$h['cutoff'])throw new \RuntimeException('Archive contains event outside retention cutoff.');$hash=$r['hash'];$count++;}
            throw new \RuntimeException('Audit archive footer missing.');
        }finally{fclose($f);}
    }
}
