<?php
declare(strict_types=1);
namespace CajeerBook;

/** Operator-configured Sentinel discovery. A majority must agree; never fall back to a stale fixed primary. */
final class RedisTopology
{
    public static function endpoint(string $host,string $port):array
    {
        if(strlen($host)>253||!preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/D',$host)||!ctype_digit($port)||(int)$port<1||(int)$port>65535)throw new \InvalidArgumentException('Invalid Redis endpoint.');
        return [strtolower($host),(int)$port];
    }
    public static function config():?array
    {
        $raw=getenv('BOOK_REDIS_SENTINELS')?:'';if($raw==='')return null;
        $name=getenv('BOOK_REDIS_SENTINEL_MASTER')?:'book';
        if(!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$name))throw new \InvalidArgumentException('Invalid Sentinel master name.');
        $nodes=[];foreach(explode(',',$raw) as $node){$parts=explode(':',trim($node));if(count($parts)!==2)throw new \InvalidArgumentException('Invalid Sentinel address.');[$h,$p]=self::endpoint(...$parts);$nodes[$h.':'.$p]=[$h,$p];}
        if(count($nodes)<3||count($nodes)>5||count($nodes)%2===0||count($nodes)!==count(explode(',',$raw)))throw new \InvalidArgumentException('Configure three or five distinct Sentinel endpoints.');
        return ['name'=>$name,'nodes'=>array_values($nodes)];
    }
    public static function discover(array $config,callable $query):array
    {
        $votes=[];$need=intdiv(count($config['nodes']),2)+1;
        foreach($config['nodes'] as [$host,$port]){
            try{$answer=$query($host,$port,$config['name']);if(!is_array($answer)||count($answer)!==2||!is_string($answer[0]??null)||!is_scalar($answer[1]??null))continue;
                $endpoint=self::endpoint($answer[0],(string)$answer[1]);$key=implode(':',$endpoint);$votes[$key]=($votes[$key]??0)+1;if($votes[$key]>=$need)return $endpoint;
            }catch(\Throwable){} // disagreement/unavailable is not a valid primary
        }
        throw new \RuntimeException('Redis Sentinel majority unavailable.');
    }
}
