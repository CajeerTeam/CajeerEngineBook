<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;

/** Real embeddings API client and fail-closed, derived portable vector store.
 * A provider is explicitly enabled by operator environment, never by a visitor.
 */
final class Embeddings
{
    public static function enabled(): bool { return (getenv('BOOK_EMBEDDING_URL') ?: '') !== ''; }
    public static function model(): string {
        $model=getenv('BOOK_EMBEDDING_MODEL')?:'';
        if(!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:\/-]{0,127}\z/D',$model))throw new \RuntimeException('BOOK_EMBEDDING_MODEL is required and must be a model identifier.');
        return $model;
    }
    public static function input(string $title,string $section,string $body): string {
        return Search::cut(trim($title."\n".$section."\n".$body),6000);
    }
    public static function digest(string $input): string { return hash('sha256',$input); }
    private static function url(): string {
        $url=getenv('BOOK_EMBEDDING_URL')?:'';
        $p=parse_url($url);
        if(!is_array($p)||!isset($p['scheme'],$p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['query'])||strlen($url)>1024)throw new \RuntimeException('Invalid embedding provider URL.');
        $scheme=strtolower($p['scheme']);$host=strtolower($p['host']);
        if($scheme!=='https'&& !($scheme==='http'&&in_array($host,['localhost','127.0.0.1','[::1]','::1'],true)))throw new \RuntimeException('Embedding API must use HTTPS or local loopback HTTP.');
        if(!preg_match('/\A[a-z0-9.:\-\[\]]+\z/D',$host))throw new \RuntimeException('Invalid embedding provider hostname.');
        return $url;
    }
    /** Validate finite, normalized vectors from the actual provider. */
    public static function normalize(array $vector): array {
        $n=count($vector);if($n<2||$n>4096||array_keys($vector)!==range(0,$n-1))throw new \RuntimeException('Invalid embedding vector dimensions.');
        $sum=0.0;$floats=[];
        foreach($vector as $x){if(!is_float($x)&&!is_int($x))throw new \RuntimeException('Invalid embedding component.');$v=(float)$x;if(!is_finite($v)||abs($v)>1.0e10)throw new \RuntimeException('Invalid embedding component.');$sum+=$v*$v;$floats[]=$v;}
        if(!is_finite($sum)||$sum<1.0e-18)throw new \RuntimeException('Zero or invalid embedding.');
        $norm=sqrt($sum);return array_map(static fn(float $x):float=>$x/$norm,$floats);
    }
    public static function cosine(array $a,array $b): float {
        if(count($a)!==count($b)||count($a)<2)return -1.0;
        $sum=0.0;foreach($a as $i=>$v)$sum+=(float)$v*(float)$b[$i];return max(-1.0,min(1.0,$sum));
    }
    /** OpenAI-compatible /embeddings endpoint. Full output is validated, no redirects or SSL downgrades. */
    public static function generate(array $inputs): array {
        if(!$inputs||count($inputs)>16)throw new \InvalidArgumentException('Embedding batch must contain 1-16 documents.');
        foreach($inputs as $input)if(!is_string($input)||$input===''||strlen($input)>6000||!preg_match('//u',$input))throw new \InvalidArgumentException('Invalid embedding input.');
        $model=self::model();$url=self::url();
        $data=json_encode(['model'=>$model,'input'=>array_values($inputs)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $headers="Content-Type: application/json\r\nAccept: application/json\r\n";
        $key=getenv('BOOK_EMBEDDING_API_KEY')?:'';
        if($key!==''&&(!preg_match('/\A[\x21-\x7e]{1,1024}\z/D',$key)))throw new \RuntimeException('Invalid embedding API key.');
        if($key!=='')$headers.="Authorization: Bearer ".$key."\r\n";
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$data,'timeout'=>8,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $h=@fopen($url,'rb',false,$ctx);if(!$h)throw new \RuntimeException('Embedding API unreachable.');
        try{
            $meta=stream_get_meta_data($h);$status=0;
            foreach($meta['wrapper_data']??[] as $line)if(preg_match('~\AHTTP/\S+\s+(\d{3})~',$line,$m))$status=(int)$m[1];
            if($status!==200)throw new \RuntimeException('Embedding API returned HTTP '.$status.'.');
            $json=stream_get_contents($h,8*1024*1024+1);
        }finally{fclose($h);}
        if(!is_string($json)||strlen($json)>8*1024*1024)throw new \RuntimeException('Embedding response too large.');
        $r=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($r)||!is_array($r['data']??null)||count($r['data'])!==count($inputs))throw new \RuntimeException('Invalid embedding response.');
        $out=[];$dimensions=null;
        foreach($r['data'] as $row){
            if(!is_array($row)||!is_int($row['index']??null)||!is_array($row['embedding']??null)||isset($out[$row['index']])||$row['index']<0||$row['index']>=count($inputs))throw new \RuntimeException('Invalid embedding response index.');
            $vec=self::normalize($row['embedding']);$dimensions??=count($vec);
            if(count($vec)!==$dimensions)throw new \RuntimeException('Embedding dimensions changed within a batch.');
            $out[$row['index']]=$vec;
        }
        ksort($out);return array_values($out);
    }
    private static function file(string $dir):string{return Files::path($dir,'search-embeddings.json');}
    /** An index is entirely derived; missing/invalid entries are ignored, never trusted for access. */
    public static function portableVectors(string $dir): array {
        $f=self::file($dir);
        if(is_link($f)||(!is_file($f)&&file_exists($f)))throw new \RuntimeException('Unsafe embedding index file.');
        if(!is_file($f))return [];
        try{$data=Files::read($f);}catch(\Throwable $e){Observability::log('search.embedding_cache_invalid',['error_type'=>get_class($e)]);return [];}
        if(!is_array($data)||($data['model']??null)!==self::model()||!is_array($data['entries']??null))return [];
        return $data['entries'];
    }
    public static function refreshPortable(string $dir,int $limit=8): int {
        if(!self::enabled())return 0;
        $store=new BookStore($dir,false,true); // never accidentally read a different SQL backend
        $projection=$store->snapshot(static fn(array $db):array=>Search::projection($db));
        $entries=self::portableVectors($dir);$pending=[];
        foreach($projection as $key=>$p){$input=self::input($p['title'],$p['section'],Search::plain($key,$p));$digest=self::digest($input);
            if(($entries[$key]['digest']??null)===$digest)continue;
            $pending[$key]=['input'=>$input,'digest'=>$digest];if(count($pending)>=$limit)break;
        }
        // Also remove vectors for deleted/unpublished pages, even when there are
        // no new documents to embed (e.g. the last page was unpublished).
        if(!$pending&&!array_diff_key($entries,$projection))return 0;
        $vectors=$pending?self::generate(array_column(array_values($pending),'input')):[];$i=0;
        // Commit only if the underlying published snapshot is still identical.
        $lock=Files::path($dir,'search-embeddings.lock');if(is_link($lock))throw new \RuntimeException('Unsafe embedding index lock.');
        $h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Embedding index lock unavailable.');chmod($lock,0600);
        try{
            if(!flock($h,LOCK_EX))throw new \RuntimeException('Embedding index lock unavailable.');
            $current=$store->snapshot(static fn(array $db):array=>Search::projection($db));
            $entries=self::portableVectors($dir);$saved=0;
            foreach($pending as $key=>$p){$v=$vectors[$i++];if(!isset($current[$key]))continue;
                $now=self::input($current[$key]['title'],$current[$key]['section'],Search::plain($key,$current[$key]));
                if(!hash_equals($p['digest'],self::digest($now)))continue;
                $entries[$key]=['digest'=>$p['digest'],'vector'=>$v];$saved++;
            }
            foreach($entries as $key=>$_)if(!isset($current[$key]))unset($entries[$key]);
            Files::write(self::file($dir),json_encode(['model'=>self::model(),'entries'=>$entries],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return $saved;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
}
