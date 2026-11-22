<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
use PDO;

/** Tenant-scoped, aggregate-only analytics. Never persists IP, cookies, user IDs or URLs with queries. */
final class Analytics
{
    public const EVENTS=['page_view','search','link_click','download','feedback'];
    public static function defaults():array{return ['enabled'=>false,'ga4'=>'','metrica'=>'','webvisor'=>false];}
    public static function settings(array $workspace):array{return $workspace['analytics']??self::defaults();}
    public static function validate(array $value):void
    {
        if(array_keys($value)!==array_keys(self::defaults())||!is_bool($value['enabled'])||!is_string($value['ga4'])||!is_string($value['metrica'])||!is_bool($value['webvisor'])||($value['ga4']!==''&&!preg_match('/\AG-[A-Z0-9]{6,20}\z/D',$value['ga4']))||($value['metrica']!==''&&!preg_match('/\A[1-9][0-9]{0,14}\z/D',$value['metrica']))||($value['webvisor']&&$value['metrica']===''))throw new \InvalidArgumentException('Invalid analytics configuration.');
    }
    public static function browserConfig(?array $workspace,bool $reader):array
    {
        if(!$workspace||!$reader)return [];
        $cfg=self::settings($workspace);
        if(!$cfg['enabled']||$workspace['archived'])return [];
        return ['workspace'=>$workspace['id'],'ga4'=>$cfg['ga4'],'metrica'=>$cfg['metrica'],'webvisor'=>$cfg['webvisor']];
    }
    public static function pagePath(string $value):string
    {
        if($value==='/'||$value==='/search')return $value;
        if(strlen($value)>260||!preg_match('~\A/docs/[a-z0-9][a-z0-9._/-]{0,248}\z~D',$value)||str_contains($value,'//')||preg_match('~(?:^|/)\.{1,2}(?:/|$)~D',$value))throw new \InvalidArgumentException('Invalid analytics page.');
        return $value;
    }
    public static function event(string $name):string
    {
        if(!in_array($name,self::EVENTS,true))throw new \InvalidArgumentException('Unknown analytics event.');
        return $name;
    }
    /** HTTPS and same-origin origin are checked by the HTTP dispatcher, including custom domains. */
    public static function accept(string $raw):array
    {
        if(strlen($raw)>1024)throw new \InvalidArgumentException('Analytics payload too large.');
        $v=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($v)||array_keys($v)!==['event','path','consent']||($v['consent']??null)!==true||!is_string($v['event'])||!is_string($v['path']))throw new \InvalidArgumentException('Invalid analytics payload.');
        return [self::event($v['event']),self::pagePath($v['path'])];
    }
    public static function collect(string $root,array $workspace,string $event,string $path):void
    {
        if(!self::settings($workspace)['enabled']||$workspace['archived'])throw new \DomainException('Analytics disabled.');
        self::event($event);self::pagePath($path);
        $day=gmdate('Y-m-d');$scope=$workspace['legacy']?'legacy':$workspace['organization'].'/'.$workspace['id'];
        if(getenv('BOOK_PG_DSN')){
            $binding=PostgresContentStore::installationBinding($root);if(!$binding)throw new \RuntimeException('PostgreSQL installation unavailable.');
            $pdo=PostgresContentStore::runtimeConnect();
            $q=$pdo->prepare('INSERT INTO cajeer_book.analytics_daily (installation,scope,day,event,page_path,hits) VALUES (?,?,?, ?, ?,1) ON CONFLICT (installation,scope,day,event,page_path) DO UPDATE SET hits=cajeer_book.analytics_daily.hits+1');
            $q->execute([$binding[0],$scope,$day,$event,$path]);return;
        }
        $dir=Files::path(Workspaces::directory($root,$workspace),'analytics');Files::dir($dir);
        $lock=Files::path($dir,'analytics.lock');$handle=fopen($lock,'c+b');if(!$handle)throw new \RuntimeException('Analytics lock unavailable.');
        try{
            if(!flock($handle,LOCK_EX)||!chmod($lock,0600))throw new \RuntimeException('Analytics lock unavailable.');
            $file=Files::path($dir,$day.'.json');$data=['day'=>$day,'events'=>[]];
            if(file_exists($file)){
                if(!is_file($file)||is_link($file)||filesize($file)>2097152)throw new \RuntimeException('Invalid analytics data.');
                $data=Files::read($file);
                if(!is_array($data)||($data['day']??null)!==$day||!is_array($data['events']??null))throw new \RuntimeException('Corrupt analytics data.');
            }
            $key=$event.'|'.$path;
            if(!array_key_exists($key,$data['events'])&&count($data['events'])>=1000)throw new \RuntimeException('Analytics cardinality limit exceeded.');
            $n=$data['events'][$key]??0;if(!is_int($n)||$n<0||$n>=1000000000)throw new \RuntimeException('Invalid analytics counter.');
            $data['events'][$key]=$n+1;
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(strlen($json)>2097152)throw new \RuntimeException('Analytics data quota exceeded.');
            Files::write($file,$json."\n");
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    /** Managers only: 30-day aggregate summary; no individual visitor data exists. */
    public static function report(string $root,array $workspace):array
    {
        $scope=$workspace['legacy']?'legacy':$workspace['organization'].'/'.$workspace['id'];
        $from=gmdate('Y-m-d',strtotime('-29 days'));$daily=[];$pages=[];$events=[];
        $add=static function(string $date,string $event,string $page,int $hits)use(&$daily,&$pages,&$events):void{
            if($hits<0||!in_array($event,self::EVENTS,true))throw new \RuntimeException('Corrupt analytics row.');
            $daily[$date]=($daily[$date]??0)+$hits;
            $events[$event]=($events[$event]??0)+$hits;
            if($event==='page_view')$pages[$page]=($pages[$page]??0)+$hits;
        };
        if(getenv('BOOK_PG_DSN')){
            $binding=PostgresContentStore::installationBinding($root);if(!$binding)throw new \RuntimeException('PostgreSQL installation unavailable.');
            $pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('SELECT day::text,event,page_path,hits FROM cajeer_book.analytics_daily WHERE installation=? AND scope=? AND day>=? ORDER BY day');
            $q->execute([$binding[0],$scope,$from]);
            foreach($q as $r)$add($r['day'],$r['event'],$r['page_path'],(int)$r['hits']);
        }else{
            $dir=Files::path(Workspaces::directory($root,$workspace),'analytics');
            for($i=29;$i>=0;$i--){$day=gmdate('Y-m-d',strtotime('-'.$i.' days'));$file=Files::path($dir,$day.'.json');if(!file_exists($file))continue;
                if(!is_file($file)||is_link($file)||filesize($file)>2097152)throw new \RuntimeException('Invalid analytics data.');
                $row=Files::read($file);
                if(!is_array($row)||($row['day']??null)!==$day||!is_array($row['events']??null))throw new \RuntimeException('Invalid analytics data.');
                foreach($row['events'] as $key=>$hits){$part=explode('|',$key,2);if(count($part)!==2||!is_int($hits))throw new \RuntimeException('Invalid analytics record.');$add($day,$part[0],$part[1],$hits);}
            }
        }
        krsort($daily);arsort($pages);arsort($events);
        return ['daily'=>$daily,'pages'=>array_slice($pages,0,20,true),'events'=>$events];
    }
}
