<?php
declare(strict_types=1);
namespace CajeerBook;

/** Bounded OpenAPI source bundle. References resolve only against supplied documents. */
final class OpenApiDocument
{
    public const MAX_BYTES=1048576, MAX_FILES=32, MAX_NODES=40000;
    public const METHODS=['get','put','post','delete','options','head','patch','trace'];
    private array $docs=[];
    private int $budget=0;
    
    public function __construct(public readonly string $root, array $files, bool $normalized=false)
    {
        ImportFiles::path($root);
        if(!$files||count($files)>self::MAX_FILES||!isset($files[$root]))throw new \InvalidArgumentException('Choose the root OpenAPI document and at most 32 files.');
        $size=0;
        foreach($files as $name=>$text){
            if(!is_string($name)||!is_string($text)||!preg_match('/\.(json|ya?ml)$/i',$name))throw new \InvalidArgumentException('Only JSON and YAML specification files are supported.');
            ImportFiles::path($name);
            if(($size+=strlen($text))>self::MAX_BYTES||!preg_match('//u',$text))throw new \InvalidArgumentException('Specification bundle must be UTF-8 and at most 1 MiB.');
            $this->docs[$name]=self::parse($text,$normalized?"source.json":$name);
        }
        ksort($this->docs,SORT_STRING);
        $normalizedBytes=0;foreach($this->docs as $d)$normalizedBytes+=strlen(self::json($d));if($normalizedBytes>self::MAX_BYTES)throw new \InvalidArgumentException('Normalized source exceeds 1 MiB.');
        foreach($this->docs as $file=>$doc)$this->scan($doc,$file,0);
        $d=$this->docs[$root];
        if(!is_object($d)||!is_string($d->openapi??null)||!preg_match('/\A3\.[01]\.\d+\z/D',$d->openapi)||!is_object($d->info??null)||!self::nonempty($d->info->title??null)||!self::nonempty($d->info->version??null)||!is_object($d->paths??null))throw new \InvalidArgumentException('Expected OpenAPI 3.0.x or 3.1.x with info.title, info.version and paths.');
        if(isset($d->components)&&!is_object($d->components))throw new \InvalidArgumentException('Invalid components object.');
    }
    private static function nonempty(mixed $v):bool{return is_string($v)&&trim($v)!=='';}
    public static function parse(string $text,string $name="source.yaml"):mixed
    {
        $text=preg_replace('/^\xEF\xBB\xBF/','',$text);$trim=ltrim($text);
        try{
            if(str_ends_with(strtolower($name),'.json'))return json_decode($text,false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            if(str_starts_with($trim,'{')||str_starts_with($trim,'[')){try{return json_decode($text,false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){}}
            return \Symfony\Component\Yaml\Yaml::parse($text,\Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE|\Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP|\Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_ALIAS,48,0);
        }catch(\JsonException|\Symfony\Component\Yaml\Exception\ParseException $e){throw new \InvalidArgumentException('Invalid OpenAPI JSON/YAML document. Check syntax and duplicate YAML keys.',0,$e);}
    }
    /** Accept an exported normalized source bundle as well as ordinary source files. */
    public static function load(string $root,array $files):self{
        if(count($files)===1&&isset($files[$root])&&str_ends_with(strtolower($root),'.json')&&strlen($files[$root])<=2*self::MAX_BYTES){
            try{$value=json_decode($files[$root],false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){$value=null;}
            if(is_object($value)&&!property_exists($value,'openapi')&&is_string($value->root??null)&&is_object($value->files??null))return self::fromBundle($files[$root]);
        }
        return new self($root,$files);
    }
    public function bundle():string{return self::json((object)['root'=>$this->root,'files'=>(object)$this->docs]);}
    public static function fromBundle(string $bundle):self{
        if(strlen($bundle)>2*self::MAX_BYTES)throw new \InvalidArgumentException('Specification bundle too large.');
        try{$b=json_decode($bundle,false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Invalid specification bundle.');}
        if(!is_object($b)||!is_string($b->root??null)||!is_object($b->files??null))throw new \InvalidArgumentException('Invalid specification bundle.');
        $files=[];foreach(get_object_vars($b->files) as $name=>$doc)$files[$name]=self::json($doc);
        return new self($b->root,$files,true);
    }
    public static function json(mixed $v,bool $pretty=false):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|($pretty?JSON_PRETTY_PRINT:0));}
    public function document():object{return $this->docs[$this->root];}
    private function scan(mixed $v,string $file,int $depth):void{
        if(++$this->budget>self::MAX_NODES||$depth>48)throw new \InvalidArgumentException('Specification nesting or node budget exceeded.');
        if(is_object($v)){
            if(!$v instanceof \stdClass)throw new \InvalidArgumentException('Unsupported YAML object.');
            foreach(get_object_vars($v) as $k=>$x){
                if(in_array($k,['$id','$anchor','$dynamicRef','$dynamicAnchor','$recursiveRef','$recursiveAnchor'],true))throw new \InvalidArgumentException('JSON Schema URI identifiers and dynamic/recursive anchors are not supported; use local JSON pointers.');
                if($k==='$ref'){if(!is_string($x))throw new \InvalidArgumentException('Invalid $ref.');$this->pointer($file,$x);}
                $this->scan($x,$file,$depth+1);
            }
        }elseif(is_array($v)){foreach($v as $x)$this->scan($x,$file,$depth+1);}
        elseif(!is_scalar($v)&&$v!==null||is_float($v)&&!is_finite($v))throw new \InvalidArgumentException('Unsupported specification value.');
    }
    /** Resolve a URI fragment JSON pointer against uploaded files, without filesystem or network access. */
    private function pointer(string $file,string $ref):array{
        if(strlen($ref)>1024||$ref===''||preg_match('/[\x00-\x20\x7f\\\\]/',$ref)||str_contains($ref,'?'))throw new \InvalidArgumentException('Invalid $ref URI.');
        $p=explode('#',$ref,2);$part=rawurldecode($p[0]);$fragment=rawurldecode($p[1]??'');if(!preg_match('//u',$fragment)||preg_match('/[\x00-\x1f\x7f]/',$fragment)||preg_match('/%(?![a-fA-F0-9]{2})/',$ref))throw new \InvalidArgumentException('Invalid $ref encoding.');
        if($part!==''){
            if(str_contains($part,':')||str_contains($part,'\\')||str_starts_with($part,'/')||str_contains($part,'?')||str_contains($part,'#'))throw new \InvalidArgumentException('External or absolute $ref is prohibited.');
            $segments=dirname($file)==='.'?[]:explode('/',dirname($file));
            foreach(explode('/',$part) as $s){if($s==='.' )continue;if($s==='..'){if(!$segments)throw new \InvalidArgumentException('$ref escapes specification bundle.');array_pop($segments);}else{$segments[]=$s;}}
            $file=ImportFiles::path(implode('/',$segments));
        }
        if(!array_key_exists($file,$this->docs))throw new \InvalidArgumentException('Missing referenced specification file: '.$file);
        $v=$this->docs[$file];if($fragment!==''&&!str_starts_with($fragment,'/'))throw new \InvalidArgumentException('Only JSON pointer fragments are supported.');
        if($fragment!=='')foreach(explode('/',substr($fragment,1)) as $token){
            if(preg_match('/~(?![01])/',$token))throw new \InvalidArgumentException('Invalid JSON pointer escape.');$key=str_replace(['~1','~0'],['/','~'],$token);
            if(is_object($v)&&property_exists($v,$key))$v=$v->{$key};elseif(is_array($v)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$key)&&array_key_exists((int)$key,$v))$v=$v[(int)$key];else throw new \InvalidArgumentException('Unresolved $ref: '.$ref);
        }
        return [$file,$v,$file.'#'.$fragment];
    }
    /** Resolve references for display. Recursive schemas retain a visible reference instead of expanding forever. */
    public function expand(mixed $v,?string $file=null,array $stack=[],int $depth=0,?int &$budget=null,?int &$bytes=null):mixed{
        $file??=$this->root;$budget??=0;$bytes??=0;$bytes+=is_string($v)?strlen($v)+8:16;if($bytes>self::MAX_BYTES)throw new \InvalidArgumentException('Expanded specification exceeds byte budget.');
        if(++$budget>self::MAX_NODES||$depth>48)throw new \InvalidArgumentException('Expanded reference exceeds complexity limit.');
        if(is_array($v)){return array_map(function($x)use($file,$stack,$depth,&$budget,&$bytes){return $this->expand($x,$file,$stack,$depth+1,$budget,$bytes);},$v);}
        if(!is_object($v))return $v;
        if(isset($v->{'$ref'})){
            [$target,$value,$id]=$this->pointer($file,$v->{'$ref'});
            if(isset($stack[$id]))return (object)['$ref'=>$id];
            $stack[$id]=true;$resolved=$this->expand($value,$target,$stack,$depth+1,$budget,$bytes);
            $siblings=clone $v;unset($siblings->{'$ref'});if(!get_object_vars($siblings))return $resolved;
            $extra=$this->expand($siblings,$file,$stack,$depth+1,$budget,$bytes);
            if(is_object($resolved)&&!array_diff(array_keys(get_object_vars($extra)),['summary','description'])){foreach(get_object_vars($extra) as $k=>$x)$resolved->{$k}=$x;return $resolved;}
            // A schema $ref with siblings is conjunctive in 3.1, not a shallow replacement.
            return (object)['allOf'=>[$resolved,$extra]];
        }
        $out=new \stdClass();foreach(get_object_vars($v) as $k=>$x){$bytes+=strlen((string)$k);if($bytes>self::MAX_BYTES)throw new \InvalidArgumentException('Expanded specification exceeds byte budget.');$out->{$k}=$this->expand($x,$file,$stack,$depth+1,$budget,$bytes);}return $out;
    }
    /** Reference Objects at structural positions are resolved before validation; cycles are invalid here. */
    public function object(mixed $v,string $label,?string $file=null):object{
        $file??=$this->root;$seen=[];$extras=[];
        while(is_object($v)&&isset($v->{'$ref'})){
            $extras[]=$v;[$file,$v,$id]=$this->pointer($file,$v->{'$ref'});if(isset($seen[$id])||count($seen)>=32)throw new \InvalidArgumentException('Cyclic structural reference: '.$label);$seen[$id]=true;
        }
        if(!is_object($v))throw new \InvalidArgumentException('Expected object: '.$label);
        $out=$this->expand($v,$file);if(!is_object($out))throw new \InvalidArgumentException('Invalid object: '.$label);
        // OpenAPI Reference Object permits summary and description overrides.
        foreach(array_reverse($extras) as $extra)foreach(['summary','description'] as $k)if(isset($extra->{$k})&&is_string($extra->{$k}))$out->{$k}=$extra->{$k};
        return $out;
    }
}
