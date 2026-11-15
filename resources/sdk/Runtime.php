<?php
declare(strict_types=1);
namespace CajeerSdk;

final class Response {
    public function __construct(public readonly int $status,public readonly array $headers,public readonly string $body){}
    public function json():mixed{return json_decode($this->body,true,64,JSON_THROW_ON_ERROR);}
}
final class Runtime {
    private static function scalar(mixed $v,array $s):string{
        if(!is_string($v)||strlen($v)>4096||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v))throw new \InvalidArgumentException('Invalid parameter value');
        $valid=match($s['type']){'integer'=>preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D',$v)===1,'number'=>preg_match('/\A[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:e[+-]?[0-9]+)?\z/iD',$v)===1&&is_finite((float)$v),'boolean'=>in_array($v,['true','false'],true),default=>true};
        if(!$valid||isset($s['enum'])&&!in_array($v,array_map(fn($x)=>is_bool($x)?($x?'true':'false'):(string)$x,$s['enum']),true))throw new \InvalidArgumentException('Parameter type or enum mismatch');return $v;
    }
    public static function build(array $d,array $input):array{
        $server=$input['server']??0;$security=$input['security']??0;$values=$input['values']??[];$auth=$input['auth']??[];
        if(!is_int($server)||!isset($d['servers'][$server])||!is_int($security)||!isset($d['security'][$security])||!is_array($values)||!array_is_list($values)||count($values)!==count($d['parameters'])||!is_array($auth)||!array_is_list($auth)||count($auth)!==count($d['security'][$security]))throw new \InvalidArgumentException('Invalid server, security or parameter count');
        $path=$d['path'];$query=[];$cookies=[];$headers=['Accept'=>'application/json, text/plain, */*'];
        $put=function($loc,$name,$v)use(&$path,&$query,&$cookies,&$headers){if($loc==='path'){if($v===''||in_array($v,['.','..'],true)||strpbrk($v,'/\\%')!==false)throw new \InvalidArgumentException('Unsafe path parameter');$path=str_replace('{'.$name.'}',rawurlencode($v),$path);}elseif($loc==='query')$query[]=rawurlencode($name).'='.rawurlencode($v);elseif($loc==='cookie')$cookies[]=rawurlencode($name).'='.rawurlencode($v);else{if(preg_match('/[^\x20-\x7e]/',$v))throw new \InvalidArgumentException('Invalid header value');$headers[$name]=$v;}};
        foreach($d['parameters'] as $i=>$p){$v=$values[$i];if($v===null||$v===''){if($p['required'])throw new \InvalidArgumentException('Required parameter: '.$p['name']);continue;}$put($p['in'],$p['name'],self::scalar($v,$p['schema']));}
        if(strpbrk($path,'{}')!==false)throw new \InvalidArgumentException('Missing path parameter');
        foreach($d['security'][$security] as $i=>$a){$v=self::scalar($auth[$i],['type'=>'string']);if($v==='')throw new \InvalidArgumentException('Missing credential');if($a['kind']==='basic'){if(!str_contains($v,':'))throw new \InvalidArgumentException('Basic auth requires username:password');$v='Basic '.base64_encode($v);}elseif($a['kind']==='bearer')$v='Bearer '.$v;$put($a['in'],$a['field'],$v);}
        if($cookies)$headers['Cookie']=implode('; ',$cookies);$body=$input['body']??'';$type=$input['content_type']??'';
        if(!is_string($body)||strlen($body)>262144||!preg_match('//u',$body)||!is_string($type))throw new \InvalidArgumentException('Invalid request body');
        if($body!==''){if(!in_array($type,$d['body_types'],true)||in_array($d['method'],['GET','HEAD'],true))throw new \InvalidArgumentException('Invalid content type');if($type==='application/json'||str_ends_with($type,'+json'))json_decode($body,false,64,JSON_THROW_ON_ERROR);$headers['Content-Type']=$type;}elseif($d['body_required'])throw new \InvalidArgumentException('Required body');
        $url=$d['servers'][$server].$path.($query?'?'.implode('&',$query):'');if(strlen($url)>8192||strlen(implode('',array_keys($headers)).implode('',$headers))>16384)throw new \InvalidArgumentException('Request too large');return ['url'=>$url,'method'=>$d['method'],'headers'=>$headers,'body'=>$body];
    }
    public static function execute(array $d,array $input):Response{
        $r=self::build($d,$input);$headers=[];foreach($r['headers'] as $k=>$v)$headers[]=$k.': '.$v;
        $ctx=stream_context_create(['http'=>['method'=>$r['method'],'header'=>implode("\r\n",$headers),'content'=>$r['body'],'timeout'=>15,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $f=@fopen($r['url'],'rb',false,$ctx);if(!$f)throw new \RuntimeException('HTTPS request failed');try{$meta=stream_get_meta_data($f);$lines=$meta['wrapper_data']??[];$status=0;$out=[];foreach($lines as $line){if(preg_match('~^HTTP/\S+ ([0-9]{3})~',$line,$m)){$status=(int)$m[1];$out=[];}elseif(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$out[strtolower($k)][]=trim($v);}}$body=stream_get_contents($f,1048577);if($body===false||stream_get_meta_data($f)['timed_out'])throw new \RuntimeException('HTTPS response failed');if(strlen($body)>1048576)throw new \RuntimeException('Response exceeds 1 MiB');if(!$status)throw new \RuntimeException('Invalid HTTP response');return new Response($status,$out,$body);}finally{fclose($f);}
    }
}
