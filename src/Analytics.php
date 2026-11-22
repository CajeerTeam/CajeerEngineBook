<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;
use PDO;

/** Consent-gated, tenant-scoped daily counters. No IP, cookie, user or session identifiers. */
final class Analytics
{
    public const EVENTS=['page_view','search','link_click','download','feedback','conversion'];
    private const BASE_EVENTS=['page_view','search','link_click','download','feedback'];
    private const MAX_CATEGORIES=1000;
    public static function defaults():array
    {
        return ['enabled'=>false,'ga4'=>'','metrica'=>'','webvisor'=>false,
            'search_terms'=>false,'goals'=>[],
            'posthog_key'=>'','posthog_host'=>'https://us.i.posthog.com',
            'matomo_url'=>'','matomo_site'=>'',
            'plausible_script'=>'','umami_url'=>'','umami_site'=>''];
    }
    /** Backward-compatible defaults for 1.0.5 workspace records. Unknown keys stay visible to validation. */
    public static function settings(array $workspace):array
    {
        $raw=$workspace['analytics']??[];
        return is_array($raw)?array_replace(self::defaults(),$raw):['invalid'=>$raw];
    }
    private static function httpsOrigin(string $url):bool
    {
        if($url===''||strlen($url)>255||preg_match('/[\x00-\x20\x7f]/',$url))return false;
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'],$parts['pass'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||isset($parts['port']))return false;
        $host=$parts['host']??'';
        if(!is_string($host)||strlen($host)>253||!preg_match('/\A(?=.{4,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/D',$host)||filter_var($host,FILTER_VALIDATE_IP))return false;
        return ($parts['path']??'')==='';
    }
    private static function scriptUrl(string $url):bool
    {
        if($url===''||strlen($url)>350||preg_match('/[\x00-\x20\x7f]/',$url))return false;
        $p=parse_url($url);
        return is_array($p)&&isset($p['host'],$p['path'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['port'])&&!isset($p['query'])&&!isset($p['fragment'])&&self::httpsOrigin('https://'.$p['host'])&&($p['scheme']??null)==='https'&&preg_match('~\A/[a-zA-Z0-9_./-]+\.js\z~D',$p['path'])===1&&!str_contains($p['path'],'..')&&!str_contains($p['path'],'//');
    }
    public static function validate(array $value):void
    {
        if(array_keys($value)!==array_keys(self::defaults())||!is_bool($value['enabled'])||!is_bool($value['webvisor'])||!is_bool($value['search_terms']))throw new \InvalidArgumentException('Invalid analytics configuration.');
        foreach(['ga4','metrica','posthog_key','posthog_host','matomo_url','matomo_site','plausible_script','umami_url','umami_site'] as $key)if(!is_string($value[$key]))throw new \InvalidArgumentException('Invalid analytics field: '.$key);
        if(($value['ga4']!==''&&!preg_match('/\AG-[A-Z0-9]{6,20}\z/D',$value['ga4']))||($value['metrica']!==''&&!preg_match('/\A[1-9][0-9]{0,14}\z/D',$value['metrica']))||($value['webvisor']&&$value['metrica']===''))throw new \InvalidArgumentException('Invalid Google/Yandex tracker settings.');
        if($value['posthog_key']!==''&&!preg_match('/\A[a-zA-Z0-9_]{8,120}\z/D',$value['posthog_key']))throw new \InvalidArgumentException('Invalid PostHog project key.');
        if(!self::httpsOrigin($value['posthog_host'])||($value['matomo_url']!==''&&!self::httpsOrigin($value['matomo_url']))||($value['umami_url']!==''&&!self::httpsOrigin($value['umami_url']))||($value['plausible_script']!==''&&!self::scriptUrl($value['plausible_script'])))throw new \InvalidArgumentException('Analytics hosts and scripts must be valid HTTPS addresses.');
        if(($value['matomo_url']==='')!==($value['matomo_site']==='')||($value['matomo_site']!==''&&!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value['matomo_site']))||($value['umami_url']==='')!==($value['umami_site']==='')||($value['umami_site']!==''&&!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',$value['umami_site'])))throw new \InvalidArgumentException('Invalid Matomo/Umami site ID or host.');
        if(!is_array($value['goals'])||!array_is_list($value['goals'])||count($value['goals'])>12||count($value['goals'])!==count(array_unique($value['goals'])))throw new \InvalidArgumentException('Invalid conversion goals.');
        foreach($value['goals'] as $goal)if(!is_string($goal)||!self::validGoal($goal))throw new \InvalidArgumentException('Invalid conversion goal.');
    }
    private static function validGoal(string $goal):bool{return preg_match('/\A[a-z][a-z0-9_]{1,39}\z/D',$goal)===1;}
    public static function browserConfig(?array $workspace,bool $reader):array
    {
        if(!$workspace||!$reader||($workspace['archived']??false))return [];
        $cfg=self::settings($workspace);self::validate($cfg);
        if(!$cfg['enabled'])return [];
        return ['workspace'=>$workspace['id']]+array_diff_key($cfg,['enabled'=>true]);
    }
    /** Per-workspace CSP allowlist; custom tracker hosts remain forbidden everywhere else. */
    public static function contentSecurityPolicy(?array $workspace,bool $reader):string
    {
        $script=["'self'",'https://www.googletagmanager.com','https://mc.yandex.ru','https://mc.yandex.com','https://yastatic.net'];
        $connect=["'self'",'https://*.google-analytics.com','https://*.yandex.ru','https://*.yandex.com'];
        $cfg=self::browserConfig($workspace,$reader);
        foreach(['matomo_url','umami_url','plausible_script','posthog_host'] as $key){
            if($key==='posthog_host'&&empty($cfg['posthog_key']))continue;
            $url=$cfg[$key]??'';if($url==='')continue;
            $p=parse_url($url);$origin='https://'.$p['host'];$script[]=$origin;$connect[]=$origin;
            if($key==='posthog_host')$script[]=str_replace('.i.posthog.com','-assets.i.posthog.com',$origin);
        }
        return "default-src 'self'; script-src ".implode(' ',array_unique($script)).'; connect-src '.implode(' ',array_unique($connect))."; style-src 'self'; img-src 'self' data: https:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
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
    /** Do not accept URLs, contact identifiers, high-entropy tokens, or unbounded text as search dimensions. */
    public static function query(string $value):string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??'');
        $value=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtr(strtolower($value),array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY)));
        $length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value);
        if($length<3||$length>80||!preg_match('/\A[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N} _.-]*\z/uD',$value)||preg_match('/\d{4}|(?:^|\s)[a-f0-9]{24,}(?:\s|$)/ui',$value)||count(explode(' ',$value))>8)throw new \InvalidArgumentException('Search query not suitable for aggregate reporting.');
        return $value;
    }
    /** HTTPS and origin are checked by App; no arbitrary browser-supplied attributes. */
    public static function accept(string $raw):array
    {
        if(strlen($raw)>1024)throw new \InvalidArgumentException('Analytics payload too large.');
        $v=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($v)||!isset($v['event'],$v['path'],$v['consent'])||$v['consent']!==true||!is_string($v['event'])||!is_string($v['path'])||array_diff(array_keys($v),['event','path','consent','query','goal']))throw new \InvalidArgumentException('Invalid analytics payload.');
        $event=self::event($v['event']);$path=self::pagePath($v['path']);
        if(isset($v['query'])){
            if($event!=='search'||$path!=='/search'||!is_string($v['query'])||isset($v['goal']))throw new \InvalidArgumentException('Invalid search event.');
            return [$event,$path,self::query($v['query'])];
        }
        if(isset($v['goal'])){
            if($event!=='conversion'||!is_string($v['goal'])||!self::validGoal($v['goal']))throw new \InvalidArgumentException('Invalid conversion event.');
            return [$event,$path,$v['goal']];
        }
        if($event==='conversion')throw new \InvalidArgumentException('Conversion goal required.');
        return [$event,$path];
    }
    public static function collect(string $root,array $workspace,string $event,string $path,string $dimension=''):void
    {
        $cfg=self::settings($workspace);self::validate($cfg);
        if(!$cfg['enabled']||($workspace['archived']??false))throw new \DomainException('Analytics disabled.');
        self::event($event);self::pagePath($path);
        if($event==='conversion'){
            if(!in_array($dimension,$cfg['goals'],true))throw new \InvalidArgumentException('Goal not configured for workspace.');
        }elseif($event==='search'&&$dimension!==''){
            if(!$cfg['search_terms']||$path!=='/search'||self::query($dimension)!==$dimension)throw new \InvalidArgumentException('Search dimensions disabled or invalid.');
        }elseif($dimension!=='')throw new \InvalidArgumentException('Unexpected event dimension.');
        $kind=$event==='conversion'?'conversion':($dimension!==''?'search_query':'');
        $day=gmdate('Y-m-d');$scope=$workspace['legacy']?'legacy':$workspace['organization'].'/'.$workspace['id'];
        if(getenv('BOOK_PG_DSN')){
            $binding=PostgresContentStore::installationBinding($root);if(!$binding)throw new \RuntimeException('PostgreSQL installation unavailable.');
            $pdo=PostgresContentStore::runtimeConnect();
            $pdo->beginTransaction();
            try{
                if($event!=='conversion'){
                    $q=$pdo->prepare('INSERT INTO cajeer_book.analytics_daily (installation,scope,day,event,page_path,hits) VALUES (?,?,?,?,?,1) ON CONFLICT (installation,scope,day,event,page_path) DO UPDATE SET hits=cajeer_book.analytics_daily.hits+1');
                    $q->execute([$binding[0],$scope,$day,$event,$path]);
                }
                if($kind!==''){
                    $q=$pdo->prepare('INSERT INTO cajeer_book.analytics_dimensions_daily (installation,scope,day,kind,dimension,hits) VALUES (?,?,?,?,?,1) ON CONFLICT (installation,scope,day,kind,dimension) DO UPDATE SET hits=cajeer_book.analytics_dimensions_daily.hits+1');
                    $q->execute([$binding[0],$scope,$day,$kind,$dimension]);
                }
                $pdo->commit();
            }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            return;
        }
        $dir=Files::path(Workspaces::directory($root,$workspace),'analytics');Files::dir($dir);
        $lock=Files::path($dir,'analytics.lock');$handle=fopen($lock,'c+b');if(!$handle)throw new \RuntimeException('Analytics lock unavailable.');
        try{
            if(!flock($handle,LOCK_EX)||!chmod($lock,0600))throw new \RuntimeException('Analytics lock unavailable.');
            $file=Files::path($dir,$day.'.json');$data=['day'=>$day,'events'=>[],'dimensions'=>[]];
            if(file_exists($file)){
                if(!is_file($file)||is_link($file)||filesize($file)>2097152)throw new \RuntimeException('Invalid analytics data.');
                $data=Files::read($file);
                if(!is_array($data)||($data['day']??null)!==$day||!is_array($data['events']??null)||!is_array($data['dimensions']??[]))throw new \RuntimeException('Corrupt analytics data.');
                $data['dimensions']??=[];
            }
            if($event!=='conversion')self::increment($data['events'],$event.'|'.$path);
            if($kind!=='')self::increment($data['dimensions'],$kind.'|'.$dimension);
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(strlen($json)>2097152)throw new \RuntimeException('Analytics data quota exceeded.');
            Files::write($file,$json."\n");
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    private static function increment(array &$items,string $key):void
    {
        if(!array_key_exists($key,$items)&&count($items)>=self::MAX_CATEGORIES)throw new \RuntimeException('Analytics cardinality limit exceeded.');
        $n=$items[$key]??0;if(!is_int($n)||$n<0||$n>=1000000000)throw new \RuntimeException('Invalid analytics counter.');
        $items[$key]=$n+1;
    }
    /** 30-day report. Search phrases displayed only after >=3 opted-in searches. */
    public static function report(string $root,array $workspace):array
    {
        $scope=$workspace['legacy']?'legacy':$workspace['organization'].'/'.$workspace['id'];
        $from=gmdate('Y-m-d',strtotime('-29 days'));$daily=[];$pages=[];$events=[];$queries=[];$conversions=[];
        $add=static function(string $date,string $event,string $page,int $hits)use(&$daily,&$pages,&$events):void{
            if($hits<0||!in_array($event,self::BASE_EVENTS,true))throw new \RuntimeException('Corrupt analytics row.');
            $daily[$date]=($daily[$date]??0)+$hits;$events[$event]=($events[$event]??0)+$hits;
            if($event==='page_view')$pages[$page]=($pages[$page]??0)+$hits;
        };
        $dimensionAdd=static function(string $date,string $kind,string $name,int $hits)use(&$daily,&$events,&$queries,&$conversions):void{
            if($hits<0||!in_array($kind,['search_query','conversion'],true))throw new \RuntimeException('Corrupt analytics dimension.');
            if($kind==='search_query'){
                if(self::query($name)!==$name)throw new \RuntimeException('Corrupt search query.');
                $queries[$name]=($queries[$name]??0)+$hits;
            }else{
                if(!self::validGoal($name))throw new \RuntimeException('Corrupt conversion goal.');
                $conversions[$name]=($conversions[$name]??0)+$hits;
                $daily[$date]=($daily[$date]??0)+$hits;
                $events['conversion']=($events['conversion']??0)+$hits;
            }
        };
        if(getenv('BOOK_PG_DSN')){
            $binding=PostgresContentStore::installationBinding($root);if(!$binding)throw new \RuntimeException('PostgreSQL installation unavailable.');
            $pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('SELECT day::text,event,page_path,hits FROM cajeer_book.analytics_daily WHERE installation=? AND scope=? AND day>=? ORDER BY day');
            $q->execute([$binding[0],$scope,$from]);foreach($q as $r)$add($r['day'],$r['event'],$r['page_path'],(int)$r['hits']);
            $q=$pdo->prepare('SELECT day::text,kind,dimension,hits FROM cajeer_book.analytics_dimensions_daily WHERE installation=? AND scope=? AND day>=? ORDER BY day');
            $q->execute([$binding[0],$scope,$from]);foreach($q as $r)$dimensionAdd($r['day'],$r['kind'],$r['dimension'],(int)$r['hits']);
        }else{
            $dir=Files::path(Workspaces::directory($root,$workspace),'analytics');
            for($i=29;$i>=0;$i--){$day=gmdate('Y-m-d',strtotime('-'.$i.' days'));$file=Files::path($dir,$day.'.json');if(!file_exists($file))continue;
                if(!is_file($file)||is_link($file)||filesize($file)>2097152)throw new \RuntimeException('Invalid analytics data.');
                $row=Files::read($file);
                if(!is_array($row)||($row['day']??null)!==$day||!is_array($row['events']??null)||!is_array($row['dimensions']??[]))throw new \RuntimeException('Invalid analytics data.');
                foreach($row['events'] as $key=>$hits){$part=explode('|',$key,2);if(count($part)!==2||!is_int($hits))throw new \RuntimeException('Invalid analytics record.');$add($day,$part[0],$part[1],$hits);}
                foreach($row['dimensions']??[] as $key=>$hits){$part=explode('|',$key,2);if(count($part)!==2||!is_int($hits))throw new \RuntimeException('Invalid analytics dimension.');$dimensionAdd($day,$part[0],$part[1],$hits);}
            }
        }
        krsort($daily);arsort($pages);arsort($events);arsort($queries);arsort($conversions);
        $queries=array_filter($queries,static fn(int $hits):bool=>$hits>=3);
        return ['daily'=>$daily,'pages'=>array_slice($pages,0,20,true),'events'=>$events,'queries'=>array_slice($queries,0,20,true),'conversions'=>array_slice($conversions,0,20,true)];
    }
}
