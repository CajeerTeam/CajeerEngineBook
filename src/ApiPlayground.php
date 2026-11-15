<?php
declare(strict_types=1);
namespace CajeerBook;

/** A request definition is part of the reviewed page snapshot, never the latest draft source. */
final class ApiPlayground
{
    public const MAX_BODY=262144, MAX_RESPONSE=1048576;
    private const METHODS=['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'];
    private const BLOCKED_HEADERS=['host','content-length','transfer-encoding','connection','proxy-authorization','proxy-authenticate','upgrade','te','trailer','expect','origin','referer','accept-encoding','cookie','set-cookie','authorization','content-type','user-agent','forwarded','via','accept','keep-alive','x-http-method-override','x-method-override','x-original-url','x-rewrite-url','dnt'];
    public static function headerName(string $name):bool {
        $n=strtolower($name);return preg_match('/\A[A-Za-z0-9!#$%&\x27*+.^_`|~-]{1,80}\z/D',$name)===1&&!in_array($n,self::BLOCKED_HEADERS,true)&&!str_starts_with($n,'sec-')&&!str_starts_with($n,'proxy-')&&!str_starts_with($n,'x-forwarded-')&&!str_starts_with($n,'access-control-');
    }
    /** Exact HTTPS origins, DNS host names only; no wildcard, credentials or path. */
    public static function origin(string $url):string {
        if(strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new \InvalidArgumentException('Invalid HTTPS server URL.');
        $p=parse_url($url);$host=strtolower($p['host']??'');$port=$p['port']??443;
        if(!is_array($p)||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||$port<1||$port>65535||!preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z/D',$host)||!preg_match('/[a-z]/',$host)||preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)$/i',$host))throw new \InvalidArgumentException('Only HTTPS DNS server origins are supported.');
        foreach(explode('.',$host) as $label)if(strlen($label)>63)throw new \InvalidArgumentException('Invalid API server host.');
        return 'https://'.$host.($port===443?'':':'.$port);
    }
    private static function base(string $url):string {
        $origin=self::origin($url);$path=parse_url($url,PHP_URL_PATH)??'';
        self::path($path===''?'/':$path,false);return $origin.rtrim($path,'/');
    }
    private static function path(string $path,bool $template):void {
        if(!str_starts_with($path,'/')||str_starts_with($path,'//')||strlen($path)>2048||!preg_match("#\\A/[A-Za-z0-9._~!$&'()*+,;=:@/%{}-]*\\z#D",$path)||preg_match('/%(?![a-fA-F0-9]{2})/',$path))throw new \InvalidArgumentException('Invalid API path.');
        foreach(explode('/',$path) as $segment){$d=rawurldecode($segment);if(in_array($d,['.','..'],true)||preg_match('/[\/\\\\\x00-\x20\x7f?#%]/',$d))throw new \InvalidArgumentException('Unsafe API path segment.');}
        if(!$template&&strpbrk($path,'{}')!==false)throw new \InvalidArgumentException('Unresolved API server variable.');
    }
    public static function origins(string $env='BOOK_PLAYGROUND_ORIGINS'):array {
        $out=[];foreach(explode(',',getenv($env)?:'') as $s){$s=trim($s);if($s==='')continue;try{$o=self::origin($s);if(rtrim($s,'/')===$o||rtrim($s,'/')===$o.':443')$out[$o]=true;}catch(\InvalidArgumentException){}}
        return array_keys($out);
    }
    private static function scalarSchema(mixed $s):?array {
        if(!is_object($s)||isset($s->properties)||isset($s->items)||isset($s->allOf)||isset($s->oneOf)||isset($s->anyOf)||isset($s->{'$ref'}))return null;$type=$s->type??'string';if(!is_string($type)||!in_array($type,['string','integer','number','boolean'],true))return null;
        $out=['type'=>$type];if(isset($s->enum)){if(!is_array($s->enum)||count($s->enum)>100)return null;$out['enum']=[];foreach($s->enum as $v){if(!is_scalar($v)||strlen((string)$v)>512)return null;$out['enum'][]=$v;}}
        return $out;
    }
    /** Return null for unsupported parameter serializations/auth, without breaking Reference import. */
    public static function definition(string $method,string $path,array $params,array $servers,array $security,object $schemes,?object $request):?array {
        $method=strtoupper($method);if(!in_array($method,self::METHODS,true))return null;
        try{self::path($path,true);}catch(\InvalidArgumentException){return null;}
        $bases=[];foreach($servers as $s){$url=$s->url;foreach(get_object_vars($s->variables??(object)[]) as $name=>$v)$url=str_replace('{'.$name.'}',$v->default,$url);try{$bases[]=self::base($url);}catch(\InvalidArgumentException){}}
        $bases=array_values(array_unique($bases));if(!$bases||count($bases)>20||count($params)>64)return null;
        $parameters=[];$names=[];
        foreach($params as $p){$name=$p->name;$in=$p->in;$schema=self::scalarSchema($p->schema??null);if(!$schema||isset($p->content)||strlen($name)>128||preg_match('/[\x00-\x20\x7f]/',$name)||($in==='header'&&!self::headerName($name))||($in==='cookie'&&!preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D',$name)))return null;
            if(isset($p->style)&&$p->style!==(['path'=>'simple','header'=>'simple','query'=>'form','cookie'=>'form'][$in]??''))return null;
            $n=$in.':'.($in==='header'?strtolower($name):$name);if(isset($names[$n]))return null;$names[$n]=true;
            $parameters[]=['name'=>$name,'in'=>$in,'required'=>($p->required??false)===true,'schema'=>$schema];
        }
        $alternatives=[];if(!$security)$security=[(object)[]];
        foreach($security as $req){$a=[];$used=$names;$valid=true;foreach(get_object_vars($req) as $name=>$scopes){$s=$schemes->{$name}??null;$type=$s->type??'';$kind=null;$in='header';$field='Authorization';
                if($type==='http'&&in_array(strtolower($s->scheme??''),['bearer','basic'],true))$kind=strtolower($s->scheme);
                elseif($type==='apiKey'){$kind='key';$in=$s->in;$field=$s->name;if($field===''||($in==='header'&&!self::headerName($field))||strlen($field)>128||preg_match('/[\x00-\x20\x7f]/',$field)||($in==='cookie'&&!preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D',$field)))$valid=false;}
                else $valid=false;
                $k=$in.':'.($in==='header'?strtolower($field):$field);if(isset($used[$k]))$valid=false;$used[$k]=true;
                if(!$valid||$kind===null||strlen((string)$name)>128||preg_match('/[\x00-\x1f\x7f]/',(string)$name)){$valid=false;break;}$a[]=['name'=>(string)$name,'kind'=>$kind,'in'=>$in,'field'=>$field];
            }if($valid&&count($a)<=8)$alternatives[]=$a;
        }
        if(!$alternatives||count($alternatives)>16)return null;
        $types=[];if($request!==null){foreach(get_object_vars($request->content??(object)[]) as $t=>$unused){if(preg_match('~\A(?:application/(?:json|[a-z0-9.+-]+\+json|x-www-form-urlencoded)|text/plain)\z~D',$t))$types[]=$t;}if(!$types||count($types)>8||in_array($method,['GET','HEAD'],true))return null;}
        $d=['method'=>$method,'path'=>$path,'servers'=>$bases,'parameters'=>$parameters,'security'=>$alternatives,'body_types'=>$types,'body_required'=>($request->required??false)===true];
        try{self::validate($d);}catch(\InvalidArgumentException){return null;}
        return strlen(OpenApiDocument::json($d))<=16384?$d:null;
    }
    public static function marker(array $definition):string{return '[book-playground-v1-'.hash('sha256',OpenApiDocument::json($definition)).']: #';}
    public static function validateSnapshot(array $p):void {
        if(!array_key_exists('playground',$p))return;$d=$p['playground'];
        if(!is_array($d)||strlen(OpenApiDocument::json($d))>16384||!is_string($p['body']??null)||!str_ends_with($p['body'],self::marker($d)."\n"))throw new \RuntimeException('Invalid Playground snapshot binding.');
        // Verify the stored definition against the same bounded request model used at generation.
        self::validate($d);
    }
    private static function validate(array $d):void {
        if(!in_array($d['method']??null,self::METHODS,true)||!is_string($d['path']??null)||!is_array($d['servers']??null)||!array_is_list($d['servers'])||!$d['servers']||count($d['servers'])>20||!is_array($d['parameters']??null)||!array_is_list($d['parameters'])||count($d['parameters'])>64||!is_array($d['security']??null)||!array_is_list($d['security'])||!$d['security']||count($d['security'])>16||!is_array($d['body_types']??null)||!array_is_list($d['body_types'])||count($d['body_types'])>8||!is_bool($d['body_required']??null))throw new \InvalidArgumentException('Invalid Playground definition.');
        self::path($d['path'],true);foreach($d['servers'] as $s)if(!is_string($s)||self::base($s)!==$s)throw new \InvalidArgumentException('Invalid Playground server.');
        $seen=[];foreach($d['parameters'] as $p){if(!is_array($p)||!is_string($p['name']??null)||$p['name']===''||strlen($p['name'])>128||preg_match('/[\x00-\x20\x7f]/',$p['name'])||!in_array($p['in']??null,['path','query','header','cookie'],true)||!is_bool($p['required']??null)||!is_array($p['schema']??null)||self::scalarSchema((object)$p['schema'])!==$p['schema']||($p['in']==='header'&&!self::headerName($p['name']))||($p['in']==='cookie'&&!preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D',$p['name'])))throw new \InvalidArgumentException('Invalid Playground parameter.');$k=$p['in'].':'.($p['in']==='header'?strtolower($p['name']):$p['name']);if(isset($seen[$k]))throw new \InvalidArgumentException('Duplicate parameter.');$seen[$k]=true;}
        foreach($d['security'] as $alt){if(!is_array($alt)||!array_is_list($alt)||count($alt)>8)throw new \InvalidArgumentException('Invalid Playground security.');$used=$seen;foreach($alt as $a){if(!is_array($a)||!is_string($a['name']??null)||!in_array($a['kind']??null,['basic','bearer','key'],true)||!in_array($a['in']??null,['header','query','cookie'],true)||!is_string($a['field']??null)||$a['field']===''||strlen($a['field'])>128||preg_match('/[\x00-\x20\x7f]/',$a['field'])||($a['kind']!=='key'&&($a['in']!=='header'||$a['field']!=='Authorization'))||($a['kind']==='key'&&$a['in']==='header'&&!self::headerName($a['field']))||($a['in']==='cookie'&&!preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D',$a['field'])))throw new \InvalidArgumentException('Invalid Playground credential.');$k=$a['in'].':'.($a['in']==='header'?strtolower($a['field']):$a['field']);if(isset($used[$k]))throw new \InvalidArgumentException('Duplicate credential field.');$used[$k]=true;}}
        foreach($d['body_types'] as $t)if(!is_string($t)||!preg_match('~\A(?:application/(?:json|[a-z0-9.+-]+\+json|x-www-form-urlencoded)|text/plain)\z~D',$t))throw new \InvalidArgumentException('Invalid Playground body type.');
    }
    /** Only resolve visible, mapped published pages. Legacy generated bodies must match a retained source exactly. */
    public static function published(array $db,array $visible,string $key,?array &$legacyCache=null):?array {
        $p=$visible[$key]??null;if(!$p||!preg_match('~/operations/(?:get|head|post|put|patch|delete|options)-[a-f0-9]{24}$~D',$key))return null;
        foreach($db['openapi']??[] as $s){$map=$s['pages'][$key]??null;if(!$map||$map['id']!==$p['id'])continue;
            if(isset($p['playground'])){try{self::validateSnapshot($p);$d=$p['playground'];if(str_ends_with($key,'/'.strtolower($d['method']).'-'.substr(hash('sha256',$d['path']),0,24)))return $d;}catch(\Throwable){}return null;}
            // If older revisions have been pruned, a matching legacy body may refer to different auth definitions.
            if(($s['revisions'][0]['revision']??null)!==1)return null;
            $match=null;$hash=null;
            foreach($s['revisions'] as $r){$cacheKey=$s['book'].'/'.$s['version'].'/'.$s['slug'].'/'.hash('sha256',$r['bundle']);try{if($legacyCache!==null&&isset($legacyCache[$cacheKey]))$model=$legacyCache[$cacheKey];else{$doc=OpenApiDocument::fromBundle($r['bundle']);$model=OpenApiReference::render($doc,$s['book'],$s['version'],$s['slug'],true);if($legacyCache!==null)$legacyCache[$cacheKey]=$model;}}catch(\InvalidArgumentException|\DomainException){return null;}$candidate=$model['pages'][$key]??null;if(!$candidate||$candidate['body']!==$p['body'])continue;$d=$candidate['playground']??null;if(!$d)return null;$h=hash('sha256',OpenApiDocument::json($d));if($hash!==null&&$hash!==$h)return null;$match=$d;$hash=$h;}
            return $match;
        }return null;
    }
    private static function value(mixed $v,array $schema):string {
        if(!is_string($v)||strlen($v)>4096||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v))throw new \InvalidArgumentException('Invalid parameter value.');
        $valid=match($schema['type']){'integer'=>preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D',$v)===1,'number'=>preg_match('/\A[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:e[+-]?[0-9]+)?\z/iD',$v)===1&&is_finite((float)$v),'boolean'=>in_array($v,['true','false'],true),default=>true};
        if(!$valid)throw new \InvalidArgumentException('Parameter type does not match OpenAPI.');
        if(isset($schema['enum'])&&!in_array($v,array_map(fn($x)=>is_bool($x)?($x?'true':'false'):(string)$x,$schema['enum']),true))throw new \InvalidArgumentException('Parameter is not an allowed enum value.');return $v;
    }
    /** Build only declared request fields; never accept a client URL, method or arbitrary headers. */
    public static function request(array $d,array $input,array $allowed,bool $proxy):array {
        self::validate($d);if(array_diff(array_keys($input),['server','security','values','auth','body','content_type','confirm']))throw new \InvalidArgumentException('Unexpected Playground request field.');$server=$input['server']??null;$alt=$input['security']??null;$values=$input['values']??null;$auth=$input['auth']??null;
        if(!is_int($server)||!isset($d['servers'][$server])||!is_int($alt)||!isset($d['security'][$alt])||!is_array($values)||!array_is_list($values)||count($values)!==count($d['parameters'])||!is_array($auth)||!array_is_list($auth)||count($auth)!==count($d['security'][$alt]))throw new \InvalidArgumentException('Invalid Playground request fields.');
        $base=$d['servers'][$server];$origin=self::origin($base);if(!in_array($origin,$allowed,true))throw new \InvalidArgumentException('API server is not enabled by the operator.');
        $path=$d['path'];$query=[];$headers=['Accept'=>'application/json, text/plain, */*'];$cookies=[];
        $put=function(string $in,string $name,string $v)use(&$path,&$query,&$headers,&$cookies,$proxy){
            if($in==='path'){if($v===''||in_array($v,['.','..'],true)||preg_match('/[\/\\\\%]/',$v))throw new \InvalidArgumentException('Unsafe path parameter.');$path=str_replace('{'.$name.'}',rawurlencode($v),$path);}
            elseif($in==='query'){$query[]=rawurlencode($name).'='.rawurlencode($v);}
            elseif($in==='header'){if(preg_match('/[^\x20-\x7e]/',$v))throw new \InvalidArgumentException('Header values must be printable ASCII.');$headers[$name]=$v;}else{if(!$proxy)throw new \InvalidArgumentException('Cookie parameters require managed proxy.');$cookies[]=rawurlencode($name).'='.rawurlencode($v);}
        };
        foreach($d['parameters'] as $i=>$p){$v=$values[$i];if($v===null||$v===''){if($p['required'])throw new \InvalidArgumentException('Required parameter is missing: '.$p['name']);continue;}$put($p['in'],$p['name'],self::value($v,$p['schema']));}
        if(strpbrk($path,'{}')!==false)throw new \InvalidArgumentException('Missing path parameter.');
        foreach($d['security'][$alt] as $i=>$a){$v=$auth[$i];if(!is_string($v)||$v==='')throw new \InvalidArgumentException('Required credential is missing.');$v=self::value($v,['type'=>'string']);if($a['kind']==='basic'){if(!str_contains($v,':'))throw new \InvalidArgumentException('Basic credentials must be username:password.');$v='Basic '.base64_encode($v);}elseif($a['kind']==='bearer')$v='Bearer '.$v;$put($a['in'],$a['field'],$v);}
        if($cookies)$headers['Cookie']=implode('; ',$cookies);
        $body=$input['body']??'';$type=$input['content_type']??'';if(!is_string($body)||strlen($body)>self::MAX_BODY||!is_string($type)||!preg_match('//u',$body))throw new \InvalidArgumentException('Invalid request body.');
        if($body!==''){if(!in_array($type,$d['body_types'],true)||in_array($d['method'],['GET','HEAD'],true))throw new \InvalidArgumentException('Request body type is not declared.');if($type==='application/json'||str_ends_with($type,'+json'))try{json_decode($body,false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Request body must be valid JSON.');}$headers['Content-Type']=$type;}
        elseif($d['body_required'])throw new \InvalidArgumentException('Request body is required.');
        $url=$base.$path.($query?'?'.implode('&',$query):'');if(strlen($url)>8192||strlen(implode('',array_keys($headers)).implode('',array_values($headers)))>16384)throw new \InvalidArgumentException('Request URL or headers exceed limit.');
        return ['method'=>$d['method'],'url'=>$url,'headers'=>$headers,'body'=>$body,'origin'=>$origin];
    }
}
