<?php
declare(strict_types=1);
namespace CajeerBook;

/** Render an OpenAPI source into ordinary editable, reviewable Markdown pages. */
final class OpenApiReference
{
    public const MAX_PAGES=200;
    private static function text(mixed $v):string{
        if(!is_string($v))return '';
        // Treat specification prose as text, not executable Markdown/HTML or rich blocks.
        $v=preg_replace('/[\x00-\x20\x7f]+/u',' ',$v)??'';
        return preg_replace_callback('/[!"#$%&\x27()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~]/u',fn($m)=>'\\'.$m[0],trim($v))??'';
    }
    private static function label(string $s,int $max=200):string{
        $s=trim(preg_replace('/[\x00-\x20\x7f]+/u',' ',$s)??'');if($s==='')return 'API Reference';
        if(strlen($s)>$max){$s=substr($s,0,$max-3);while(!preg_match('//u',$s))$s=substr($s,0,-1);$s.='...';}return $s;
    }
    private static function code(mixed $v):string{
        $json=OpenApiDocument::json($v,true);preg_match_all('/`+/',$json,$m);$n=3;foreach($m[0] as $run)$n=max($n,strlen($run)+1);$f=str_repeat('`',$n);return "\n".$f."json\n".$json."\n".$f."\n";
    }
    private static function prose(string $heading,mixed $value):string{return is_string($value)&&trim($value)!==''?'## '.$heading."\n\n".self::text($value)."\n\n":'';}
    private static function map(mixed $v,string $where):object{if(!is_object($v))throw new \InvalidArgumentException('Expected object: '.$where);return $v;}
    private static function list(mixed $v,string $where):array{if(!is_array($v)||!array_is_list($v))throw new \InvalidArgumentException('Expected array: '.$where);return $v;}
    private static function parameters(mixed $v,string $where):array{
        $out=[];foreach(self::list($v,$where) as $p){
            if(!is_object($p)||!is_string($p->name??null)||$p->name===''||!in_array($p->in??null,['path','query','header','cookie'],true)||($p->in==='path'&&($p->required??null)!==true)||(!isset($p->schema)&&!isset($p->content)))throw new \InvalidArgumentException('Invalid parameter: '.$where);
            if(isset($p->required)&&!is_bool($p->required))throw new \InvalidArgumentException('Invalid required parameter flag.');
            $key=$p->in.':'.$p->name;if(isset($out[$key]))throw new \InvalidArgumentException('Duplicate parameter: '.$key);$out[$key]=$p;
        }return $out;
    }
    private static function servers(mixed $v):array{
        $list=self::list($v,'servers');foreach($list as $server){if(!is_object($server)||!is_string($server->url??null)||trim($server->url)===''||strlen($server->url)>2048)throw new \InvalidArgumentException('Invalid server URL.');if(isset($server->variables))foreach(get_object_vars(self::map($server->variables,'server variables')) as $name=>$var)if(!is_object($var)||!is_string($var->default??null))throw new \InvalidArgumentException('Invalid server variable: '.$name);}return $list;
    }
    private static function content(mixed $v,string $label):void{
        foreach(get_object_vars(self::map($v,$label)) as $media=>$item){if(!is_string($media)||!str_contains($media,'/')||!is_object($item))throw new \InvalidArgumentException('Invalid media type: '.$label);if(isset($item->schema)&&!is_object($item->schema)&&!is_bool($item->schema))throw new \InvalidArgumentException('Invalid media schema: '.$label);}
    }
    private static function schemaFields(mixed $schema):string{
        if(!is_object($schema))return '';$out='';if(is_object($schema->properties??null)){$out.="## Properties\n\n";foreach(get_object_vars($schema->properties) as $name=>$prop){$type=is_object($prop)?($prop->type??($prop->{'$ref'}??'schema')):'boolean schema';if(is_array($type))$type=implode(' / ',array_filter($type,'is_string'));$out.='- **'.self::text((string)$name).'** — '.self::text(is_string($type)?$type:'schema').(in_array((string)$name,is_array($schema->required??null)?$schema->required:[],true)?' (required)':'')."\n";} $out.="\n";}return $out;
    }
    private static function security(mixed $v,object $schemes):array{
        $list=self::list($v,'security');foreach($list as $req){self::map($req,'security requirement');foreach(get_object_vars($req) as $name=>$scopes){if(!property_exists($schemes,(string)$name))throw new \InvalidArgumentException('Unknown security scheme: '.$name);foreach(self::list($scopes,'security scopes') as $s)if(!is_string($s))throw new \InvalidArgumentException('Invalid security scope.');}}return $list;
    }
    public static function render(OpenApiDocument $source,string $book,string $version,string $slug):array{
        if(!BookStore::slug($slug))throw new \InvalidArgumentException('Invalid reference slug.');$prefix=BookStore::key($book,$version,'reference/'.$slug);$d=$source->document();$info=$d->info;
        $components=$d->components??(object)[];$schemes=$source->object($components->securitySchemes??(object)[],'security schemes');$schemas=self::map($components->schemas??(object)[],'schemas');
        foreach(get_object_vars($schemes) as $name=>$scheme){if(!is_object($scheme)||!in_array($scheme->type??null,['apiKey','http','oauth2','openIdConnect','mutualTLS'],true)||($scheme->type==='apiKey'&&(!is_string($scheme->name??null)||!in_array($scheme->in??null,['query','header','cookie'],true)))||($scheme->type==='http'&&!is_string($scheme->scheme??null))||($scheme->type==='oauth2'&&!is_object($scheme->flows??null))||($scheme->type==='openIdConnect'&&!is_string($scheme->openIdConnectUrl??null)))throw new \InvalidArgumentException('Invalid security scheme: '.$name);}
        $globalSecurity=self::security($d->security??[],$schemes);$servers=self::servers($d->servers??[]);$pages=[];$operations=[];$models=[];$ids=[];$bytes=0;$order=10;
        $add=function(string $key,string $title,string $section,string $body)use(&$pages,&$order,&$bytes){
            Markdown::validate($body);if(count($pages)>=self::MAX_PAGES||($bytes+=strlen($body))>4*1024*1024)throw new \InvalidArgumentException('Generated reference exceeds 200 pages or 4 MiB. Split the specification.');
            $pages[$key]=['title'=>self::label($title),'section'=>self::label($section,100),'order'=>$order,'body'=>$body,'media'=>[]];$order+=10;
        };
        // Stable paths depend only on method/path or component name, not summary, tag or operationId.
        $pathMap=get_object_vars($d->paths);ksort($pathMap,SORT_STRING);
        foreach($pathMap as $path=>$raw){
            if(str_starts_with((string)$path,'x-'))continue;if(!is_string($path)||!str_starts_with($path,'/')||strlen($path)>512||preg_match('/[\x00-\x20\x7f?#]/',$path))throw new \InvalidArgumentException('Invalid OpenAPI path.');
            $item=$source->object($raw,'path '.$path);foreach(get_object_vars($item) as $field=>$unused)if(!in_array($field,array_merge(OpenApiDocument::METHODS,['summary','description','servers','parameters']),true)&&!str_starts_with((string)$field,'x-'))throw new \InvalidArgumentException('Unknown path item field: '.$field);$baseParams=self::parameters($item->parameters??[],$path);
            foreach(OpenApiDocument::METHODS as $method){if(!property_exists($item,$method))continue;$op=self::map($item->{$method},$method.' '.$path);$label=strtoupper($method).' '.$path;
                $operationId=$op->operationId??null;if($operationId!==null){if(!is_string($operationId)||trim($operationId)===''||isset($ids[$operationId]))throw new \InvalidArgumentException('Invalid or duplicate operationId.');$ids[$operationId]=true;}
                $params=array_replace($baseParams,self::parameters($op->parameters??[],$label));preg_match_all('/\{([^{}]+)\}/',$path,$matches);foreach($matches[1] as $p)if(!isset($params['path:'.$p]))throw new \InvalidArgumentException('Missing required path parameter: '.$p);foreach($params as $p)if($p->in==='path'&&!in_array($p->name,$matches[1],true))throw new \InvalidArgumentException('Path parameter absent from path: '.$p->name);
                $responses=self::map($op->responses??null,$label.' responses');if(!get_object_vars($responses))throw new \InvalidArgumentException('Operation requires responses: '.$label);
                foreach(get_object_vars($responses) as $status=>$response){if(!preg_match('/\A(?:[1-5](?:[0-9]{2}|XX)|default)\z/D',(string)$status)||!is_object($response)||!is_string($response->description??null))throw new \InvalidArgumentException('Invalid response: '.$label.' '.$status);if(isset($response->content))self::content($response->content,$label.' response '.$status);}
                $tags=self::list($op->tags??[],'tags');foreach($tags as $tag)if(!is_string($tag))throw new \InvalidArgumentException('Invalid operation tag.');
                $security=property_exists($op,'security')?self::security($op->security,$schemes):$globalSecurity;
                $effectiveServers=self::servers($op->servers??$item->servers??$servers);
                $request=$op->requestBody??null;if($request!==null&&(!is_object($request)||!is_object($request->content??null)||!get_object_vars($request->content)))throw new \InvalidArgumentException('Invalid requestBody: '.$label);if($request!==null)self::content($request->content,$label.' request body');
                $key=$prefix.'/operations/'.$method.'-'.substr(hash('sha256',$path),0,24);$title=$label.(is_string($op->summary??null)?' — '.$op->summary:'');
                $body='[API overview](/docs/'.$prefix.'/index)' ."\n\n# ".self::text($label)."\n\n".self::prose('Summary',$op->summary??null).self::prose('Description',$op->description??null);
                if(($op->deprecated??false)===true)$body.="**Deprecated operation.**\n\n";
                if($operationId!==null)$body.='Operation ID: '.self::text($operationId)."\n\n";
                if($tags)$body.='Tags: '.self::text(implode(', ',$tags))."\n\n";
                if($effectiveServers)$body.="## Servers\n".self::code($effectiveServers);
                $body.="\n## Authentication\n".($security?self::code($security):"\nNo security requirement declared.\n");
                $body.="\n## Parameters\n";
                if(!$params)$body.="\nNo parameters.\n";else foreach($params as $p){$body.="\n### ".self::text($p->name.' ('.$p->in.')')."\n\n".(($p->required??false)?'Required.':'Optional.')."\n\n".self::text($p->description??'')."\n".self::code($p);}
                if($request!==null)$body.="\n## Request body\n".self::code($request);
                $body.="\n## Responses\n";foreach(get_object_vars($responses) as $status=>$response){$body.="\n### HTTP ".self::text((string)$status)."\n\n".self::text($response->description)."\n".self::code($response);}
                $add($key,$title,'API / '.($tags[0]??'Operations'),$body);$operations[$key]=['label'=>$label,'tags'=>$tags,'summary'=>$op->summary??''];
            }
        }
        $schemaMap=get_object_vars($schemas);ksort($schemaMap,SORT_STRING);
        foreach($schemaMap as $name=>$schema){if(!is_object($schema)&&!is_bool($schema))throw new \InvalidArgumentException('Invalid schema: '.$name);$key=$prefix.'/schemas/schema-'.substr(hash('sha256',(string)$name),0,24);$expanded=$source->expand($schema);
            $body='[API overview](/docs/'.$prefix.'/index)' ."\n\n# ".self::text((string)$name)."\n\n".self::prose('Description',is_object($expanded)?($expanded->description??null):null).self::schemaFields($expanded)."## Schema\n".self::code($expanded);
            $add($key,'Schema: '.$name,'API / Schemas',$body);$models[$key]=(string)$name;
        }
        $body='# '.self::text($info->title)."\n\nAPI version: ".self::text($info->version)." · OpenAPI ".self::text($d->openapi)."\n\n".self::prose('Description',$info->description??null);
        if($servers)$body.="## Servers\n".self::code($servers);if(get_object_vars($schemes))$body.="\n## Security schemes\n".self::code($schemes);
        $body.="\n## Operations\n\n";foreach($operations as $key=>$op)$body.='- ['.self::text($op['label']).'](/docs/'.$key.')'.($op['tags']?' — '.self::text(implode(', ',$op['tags'])):'')."\n";
        if(!$operations)$body.="No operations in this specification.\n";
        $body.="\n## Schemas\n\n";foreach($models as $key=>$name)$body.='- ['.self::text($name).'](/docs/'.$key.")\n";
        $add($prefix.'/index',$info->title.' — API Reference','API Reference',$body);$pages[$prefix.'/index']['order']=0;
        return ['pages'=>$pages,'title'=>self::label($info->title),'api_version'=>self::label($info->version),'format'=>$d->openapi,'operations'=>count($operations),'schemas'=>count($models)];
    }
}
