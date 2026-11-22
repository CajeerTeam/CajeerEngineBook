<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
use PDO;

/** Durable, precharged request and maximum-token budgets, scoped per workspace and actor. */
final class ChatQuota
{
    public static function limit(string $env,int $fallback,int $low,int $high):int {
        $v=getenv($env);
        if($v===false||$v==='')return $fallback;
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$v)||($n=(int)$v)<$low||$n>$high)throw new \RuntimeException('Invalid chat quota setting: '.$env);
        return $n;
    }
    public static function identity(string $who):string {
        $secret=getenv('BOOK_CHAT_API_KEY')?:'';
        if(strlen($secret)<16)throw new \RuntimeException('Chat quota secret unavailable.');
        return hash_hmac('sha256',$who,$secret);
    }
    public static function reservation(string $question,array $history,array $sources):int {
        $bytes=strlen($question);
        foreach($history as $turn)$bytes+=strlen($turn['content']);
        foreach($sources as $source)$bytes+=strlen($source['text']);
        // Conservative upper bound for typical tokenization; always charge output cap in advance.
        return min(40000,$bytes+OpenAiResponses::maxOutput()+4096);
    }
    public static function reserve(string $root,string $workspace,string $actor,int $tokens):bool {
        if(!preg_match('~\A(?:legacy|[a-f0-9]{32}/[a-f0-9]{32})\z~D',$workspace)||!preg_match('/\A[a-f0-9]{64}\z/D',$actor)||$tokens<128||$tokens>50000)throw new \InvalidArgumentException('Invalid quota identity.');
        $limits=[
            ['workspace',self::limit('BOOK_CHAT_WORKSPACE_DAILY_REQUESTS',500,1,100000),self::limit('BOOK_CHAT_WORKSPACE_DAILY_TOKENS',8000000,1000,100000000)],
            ['actor:'.$actor,self::limit('BOOK_CHAT_USER_DAILY_REQUESTS',20,1,10000),self::limit('BOOK_CHAT_USER_DAILY_TOKENS',500000,1000,10000000)]
        ];
        $day=gmdate('Y-m-d');
        if(getenv('BOOK_PG_DSN'))return self::postgres($workspace,$day,$limits,$tokens);
        return self::portable($root,$workspace,$day,$limits,$tokens);
    }
    private static function postgres(string $scope,string $day,array $limits,int $tokens):bool {
        $pdo=PostgresContentStore::runtimeConnect();$pdo->beginTransaction();
        try{
            foreach($limits as [$key,$requests,$budget]){
                $stmt=$pdo->prepare('INSERT INTO cajeer_book.chat_usage(installation,scope,day,principal,requests,tokens) VALUES (?,?,?,?,1,?) ON CONFLICT (installation,scope,day,principal) DO UPDATE SET requests=cajeer_book.chat_usage.requests+1,tokens=cajeer_book.chat_usage.tokens+EXCLUDED.tokens WHERE cajeer_book.chat_usage.requests<? AND cajeer_book.chat_usage.tokens+EXCLUDED.tokens<=? RETURNING requests');
                $stmt->execute([PostgresContentStore::namespace(),$scope,$day,$key,$tokens,$requests,$budget]);
                if($stmt->fetchColumn()===false){$pdo->rollBack();return false;}
            }
            $pdo->commit();return true;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private static function portable(string $root,string $scope,string $day,array $limits,int $tokens):bool {
        $dir=Files::path($root,'chat-quota');Files::dir($dir);
        $lock=Files::path($dir,'quota.lock');if(is_link($lock))throw new \RuntimeException('Unsafe chat quota lock.');
        $h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Chat quota lock unavailable.');
        try{
            chmod($lock,0600);if(!flock($h,LOCK_EX))throw new \RuntimeException('Chat quota lock unavailable.');
            $file=Files::path($dir,$day.'.json');if(is_link($file))throw new \RuntimeException('Unsafe chat quota record.');
            $rows=is_file($file)?Files::read($file):[];
            if(!is_array($rows)||count($rows)>50000)throw new \RuntimeException('Chat quota ledger invalid.');
            $keys=[];
            foreach($limits as [$name,$maxReq,$maxTokens]){
                $key=hash('sha256',$scope.':'.$name);$row=$rows[$key]??['requests'=>0,'tokens'=>0];
                if(!is_array($row)||!is_int($row['requests']??null)||!is_int($row['tokens']??null)||$row['requests']<0||$row['tokens']<0)throw new \RuntimeException('Invalid chat quota row.');
                if($row['requests']>=$maxReq||$row['tokens']+$tokens>$maxTokens)return false;
                $keys[$key]=['requests'=>$row['requests']+1,'tokens'=>$row['tokens']+$tokens];
            }
            foreach($keys as $k=>$row)$rows[$k]=$row;
            Files::json($file,$rows);return true;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
}
