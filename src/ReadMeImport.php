<?php
declare(strict_types=1);
namespace CajeerBook;

/** ReadMe Git Sync / branch ZIP importer. Never evaluates front matter, MDX or remote URLs. */
final class ReadMeImport
{
    private const ASSET='~\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$~i';
    private const DOC='~\.(?:md|mdx|markdown)$~i';
    private const OAS='~\.(?:json|ya?ml)$~i';

    private static function read(string $path,int $limit):string{
        $size=@filesize($path);if($size===false||$size>$limit)throw new \InvalidArgumentException('ReadMe input exceeds size limit');
        $text=@file_get_contents($path);if(!is_string($text)||strlen($text)>$limit)throw new \InvalidArgumentException('Cannot read ReadMe input');return $text;
    }
    private static function scalar(string $raw):string{
        $raw=trim($raw);if(str_starts_with($raw,'"')){try{$v=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Invalid ReadMe quoted value');}if(!is_string($v))throw new \InvalidArgumentException('ReadMe YAML value must be text');return $v;}
        if(str_starts_with($raw,"'")){if(!preg_match("/\A'(?:[^']|'')*'\z/D",$raw))throw new \InvalidArgumentException('Invalid ReadMe quoted value');return str_replace("''","'",substr($raw,1,-1));}
        return preg_replace('/\s+#.*$/','',$raw)??$raw;
    }
    private static function front(string $source):array{
        if(str_starts_with($source,"\xef\xbb\xbf"))$source=substr($source,3);
        if(!preg_match('/\A---[ \t]*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s',$source,$m))return [[], $source];
        if(strlen($m[1])>16384)throw new \InvalidArgumentException('ReadMe front matter exceeds 16 KiB');
        $meta=[];$group='';foreach(preg_split('/\r?\n/',$m[1]) as $line){
            if(trim($line)===''||preg_match('/^\s*#/',$line))continue;
            if(preg_match('/^([a-zA-Z][\w-]*):\s*(.*?)\s*$/',$line,$kv)){
                $group=$kv[1];if(in_array($group,['title','excerpt','description','hidden','deprecated','slug','icon'],true)&&$kv[2]!=='')$meta[$group]=self::scalar($kv[2]);continue;
            }
            if(preg_match('/^ {2,}(file|operationId|webhook):\s*(.+?)\s*$/',$line,$kv)&&$group==='api')$meta['api_'.$kv[1]]=self::scalar($kv[2]);
        }
        return [$meta,substr($source,strlen($m[0]))];
    }
    private static function order(string $source):array{
        if(strlen($source)>65536)throw new \InvalidArgumentException('ReadMe _order.yaml exceeds 64 KiB');
        $names=[];foreach(preg_split('/\r?\n/',$source) as $line){$line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
            if(!preg_match('/^-\s+(.+?)\s*$/u',$line,$m))throw new \InvalidArgumentException('ReadMe _order.yaml must be a list of slugs');
            $slug=self::scalar($m[1]);if(!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}\z/D',$slug)||isset($names[$slug]))throw new \InvalidArgumentException('Invalid or duplicated ReadMe sidebar slug');$names[$slug]=true;
        }return array_keys($names);
    }
    private static function slug(string $path):string{
        $s=strtolower($path);$s=preg_replace('/[^a-z0-9._-]+/','-',$s);$s=trim($s,'._-');if($s==='')return 'page-'.substr(hash('sha256',$path),0,12);
        return strlen($s)>64?substr($s,0,51).'-'.substr(hash('sha256',$path),0,12):$s;
    }
    private static function route(string $section,string $rel):string{
        $rel=preg_replace(self::DOC,'',$rel);$segments=[];foreach(explode('/',$rel) as $part)$segments[]=self::slug($part);
        if(end($segments)==='index')array_pop($segments);
        return $section.'/'.(implode('/',$segments)?:'index');
    }
    private static function tree(string $section,array $pages,array $orderBy,array &$nav):void{
        $count=0;$visited=[];
        $visit=function(string $directory,array $parents,int $depth)use(&$visit,$section,$pages,$orderBy,&$nav,&$count,&$visited){
            if($depth>12)throw new \InvalidArgumentException('ReadMe navigation exceeds depth limit');
            $children=[];$dirs=[];foreach($pages as $rel=>$file){
                if($directory!==''&&!str_starts_with($rel,$directory.'/'))continue;
                $tail=$directory===''?$rel:substr($rel,strlen($directory)+1);
                if(str_contains($tail,'/')){$dirs[explode('/',$tail,2)[0]]=true;}else $children[preg_replace(self::DOC,'',$tail)]=$rel;
            }
            $entries=array_keys($children+$dirs);$specified=$orderBy[$directory]??[];
            foreach($specified as $name)if(!in_array($name,$entries,true))throw new \InvalidArgumentException('ReadMe _order.yaml references a missing page or category: '.$name);
            $entries=array_values(array_unique(array_merge($specified,$entries)));$first=[];if(isset($children['index']))$first[]='index';
            $entries=array_values(array_unique(array_merge($first,$entries)));foreach($entries as $name){
                $dir=$directory===''?$name:$directory.'/'.$name;
                if(isset($children[$name])){$rel=$children[$name];if(isset($visited[$rel]))continue;$visited[$rel]=true;
                    $nav[$pages[$rel]]=['order'=>++$count*10,'section'=>$section.(count($parents)?' / '.implode(' / ',array_map(fn($p)=>ucwords(str_replace(['-','_'],' ',$p)),$parents)):'')];
                }
                if(isset($dirs[$name]))$visit($dir,array_merge($parents,[$name]),$depth+1);
            }
        };$visit('',[],0);
    }
    public static function prepare(array $files,string $book,string $fallbackVersion):array{
        foreach($files as $name=>$unused)if(preg_match('~(?:^|/)(?:docs\.json|mint\.json|\.gitbook\.yaml|docusaurus\.config\.(?:js|ts)|sidebars\.(?:js|ts|json))$~',(string)$name))return self::none($files);
        $groups=[];foreach($files as $name=>$path){ImportFiles::path((string)$name);
            if(preg_match('~(?:^|/)(docs|recipes|reference)/(.+)$~',(string)$name,$m,PREG_OFFSET_CAPTURE)){
                $position=$m[0][1]+(str_starts_with($m[0][0],'/')?1:0);$prefix=substr((string)$name,0,$position);$section=$m[1][0];$relative=$m[2][0];$groups[$prefix][$section][$relative]=$path;
            }
        }
        if(!$groups)return self::none($files);
        $candidate=false;foreach($groups as $sections)foreach($sections as $entries)foreach($entries as $relative=>$file)if(basename($relative)==='_order.yaml')$candidate=true;
        foreach($groups as $sections)if(isset($sections['recipes'])||isset($sections['reference']))$candidate=true;
        if(!$candidate)return self::none($files);
        if(count($groups)>30)throw new \InvalidArgumentException('ReadMe export contains too many branches');
        $names=array_keys($groups);$multi=count($groups)>1;$prefixBase='';if($multi){
            $common=null;foreach($names as $prefix){$parts=explode('/',trim($prefix,'/'));$last=array_pop($parts);if(!BookStore::slug($last))throw new \InvalidArgumentException('Ambiguous ReadMe export roots');$base=implode('/',$parts);if($common!==null&&$common!==$base)throw new \InvalidArgumentException('ReadMe versions must share one parent folder');$common=$base;}$prefixBase=$common??'';
        }
        $content=$pages=$nav=$warnings=$virtual=$operationRoutes=[];$specs=0;$hasReference=false;
        foreach($files as $source=>$unused)if(preg_match('~(?:^|/)(?:custom_pages|custom_blocks|changelogs)/~',(string)$source)&&count($warnings)<1000)$warnings[]=['source'=>(string)$source,'url'=>'ReadMe custom content is not part of the Guides/Reference/Recipes importer'];
        foreach($groups as $prefix=>$sections){
            $label=basename(rtrim($prefix,'/'));$version=$fallbackVersion;
            if(preg_match('~(?:^|/)(?:versions|branches)/([^/]+)/$~',$prefix,$m))$version=$m[1];
            elseif($multi)$version=$label;
            if(!BookStore::slug($version))throw new \InvalidArgumentException('Invalid ReadMe version name');
            foreach(['docs'=>'Guides','reference'=>'API Reference','recipes'=>'Recipes'] as $section=>$sectionTitle){
                $entries=$sections[$section]??[];if(!$entries)continue;if($section==='reference')$hasReference=true;
                $docs=$controls=[];
                foreach($entries as $rel=>$file){$name=$prefix.$section.'/'.$rel;
                    if(basename($rel)==='_order.yaml'){$controls[dirname($rel)==='.'?'':dirname($rel)]=self::order(self::read($file,65536));continue;}
                    if(preg_match(self::DOC,$rel)){$docs[$rel]=$name;$content[$name]=$file;
                        [$meta]=self::front(self::read($file,Markdown::MAX_BYTES));$route=self::route($section,$rel);
                        if(isset($meta['slug'])&&$meta['slug']!==''){$slug=ltrim($meta['slug'],'/');if(preg_match('~\A[a-zA-Z0-9._/-]{1,180}\z/D',$slug))$route=$section.'/'.implode('/',array_map(self::slug(...),explode('/',$slug)));}
                        $pages[$name]=['version'=>$version,'route'=>$route,'title'=>$meta['title']??null,'section'=>$sectionTitle,'api_file'=>$meta['api_file']??null,'api_operationId'=>$meta['api_operationId']??null];
                        if(in_array(strtolower($meta['hidden']??''),['true','yes','1'],true)&&count($warnings)<1000)$warnings[]=['source'=>$name,'url'=>'Hidden ReadMe page imported as an unpublished draft'];
                    }elseif(preg_match(self::ASSET,$rel))$content[$name]=$file;
                    elseif(preg_match(self::OAS,$rel))continue;
                    elseif(count($warnings)<1000)$warnings[]=['source'=>$name,'url'=>'Unsupported ReadMe file omitted'];
                }
                if($docs){$sorted=[];self::tree($sectionTitle,$docs,$controls,$sorted);foreach($sorted as $fileName=>$row){$nav[$fileName]=$row;}}
                if($section==='reference')foreach($entries as $rel=>$file){
                    if(!preg_match(self::OAS,$rel))continue;$data=self::read($file,OpenApiDocument::MAX_BYTES);
                    if(!preg_match('/^\s*(?:\{|openapi\s*:)/',$data))continue;
                    $basename=basename($rel);$key=substr($rel,0,-strlen($basename));$bundle=[];
                    foreach($entries as $other=>$otherFile)if(preg_match(self::OAS,$other)&&basename($other)!=='_order.yaml'&&str_starts_with($other,$key)){
                        $r=substr($other,strlen($key));if(count($bundle)>=OpenApiDocument::MAX_FILES)throw new \InvalidArgumentException('ReadMe OpenAPI source has too many files');$bundle[$r]=self::read($otherFile,OpenApiDocument::MAX_BYTES);
                    }
                    // Skip referenced JSON Schemas, which do not have their own openapi header.
                    if(!preg_match('/^\s*(?:\{[\s\S]{0,800}"openapi"\s*:|openapi\s*:)/',$data))continue;
                    $oas=OpenApiDocument::load($basename,$bundle);
                    $slug=self::slug(str_replace('/','-',preg_replace(self::OAS,'',$rel)));
                    $render=OpenApiReference::render($oas,$book,$version,$slug);$prefixKey=BookStore::key($book,$version,'index');$head=substr($prefixKey,0,-strlen('/index'));
                    foreach($render['pages'] as $keyName=>$doc){$route=substr($keyName,strlen($head)+1);$virtualName='readme-generated/'.$version.'/'.substr(hash('sha256',$prefix.$section.'/'.$rel),0,12).'/'.substr(hash('sha256',$keyName),0,20).'.md';
                        $virtual[$virtualName]=$doc['body'];$pages[$virtualName]=['version'=>$version,'route'=>$route,'title'=>$doc['title'],'section'=>$doc['section'],'api_file'=>null,'api_operationId'=>null];
                        $nav[$virtualName]=['order'=>$doc['order'],'section'=>$doc['section']];
                    }
                    foreach(get_object_vars($oas->document()->paths) as $apiPath=>$item){if(!is_object($item))continue;foreach(OpenApiDocument::METHODS as $method)if(isset($item->{$method})&&is_object($item->{$method})&&is_string($item->{$method}->operationId??null))$operationRoutes[$version][$rel][$item->{$method}->operationId]='/docs/'.$book.'/'.$version.'/reference/'.$slug.'/operations/'.$method.'-'.substr(hash('sha256',(string)$apiPath),0,24);}
                    $specs++;
                }
            }
        }
        // ReadMe branch ZIPs often omit image binaries. Accept attached local assets when present.
        foreach($files as $path=>$file)if(!isset($content[$path])&&preg_match('~(?:^|/)(?:assets|images|uploads)/.+\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$~i', (string)$path))$content[$path]=$file;
        foreach($pages as $pageName=>&$page)if(isset($page['api_file'],$page['api_operationId'])){
            $target=$operationRoutes[$page['version']][$page['api_file']][$page['api_operationId']]??null;
            if($target===null){$matches=[];foreach($operationRoutes[$page['version']]??[] as $specName=>$operations)if(basename($specName)===basename($page['api_file'])&&isset($operations[$page['api_operationId']]))$matches[]=$operations[$page['api_operationId']];if(count($matches)===1)$target=$matches[0];}
            if($target!==null)$page['api_target']=$target;elseif(count($warnings)<1000)$warnings[]=['source'=>$pageName,'url'=>'OpenAPI operation was not found in the supplied specification: '.$page['api_operationId']];
        }unset($page);
        if(!$pages)throw new \InvalidArgumentException('ReadMe export contains no pages');
        if(count($content)+count($virtual)>ImportFiles::MAX_FILES)throw new \InvalidArgumentException('ReadMe export exceeds import limit; split the source');
        if($hasReference&&$specs===0&&count($warnings)<1000)$warnings[]=['source'=>'reference','url'=>'No OpenAPI files supplied; interactive API operations cannot be generated'];
        // The ReadMe ZIP deliberately omits remote files.readme.io images; retain their links and warn.
        return ['readme'=>true,'files'=>$content,'pages'=>$pages,'nav'=>$nav,'virtual'=>$virtual,'warnings'=>$warnings];
    }
    private static function none(array $files):array{return ['readme'=>false,'files'=>$files,'pages'=>[],'nav'=>[],'virtual'=>[],'warnings'=>[]];}
    private static function block(string $type,array $payload):string{
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(RichBlocks::parse($type,$json)===null)throw new \InvalidArgumentException('ReadMe rich block exceeds Book limits');return "\n```$type\n$json\n```\n";
    }
    public static function convert(string $source,string $path,array &$warnings,?string $apiTarget=null):string{
        [$meta,$body]=self::front($source);
        if(isset($meta['api_file'])&&isset($meta['api_operationId'])){
            // Keep the authored explanation, including code samples. Generated OpenAPI pages are imported separately.
            $body="**OpenAPI operation:** ".preg_replace('/[^a-zA-Z0-9._-]/','',$meta['api_operationId']).($apiTarget!==null?' — [Endpoint reference]('.$apiTarget.')':'')."\n\n".$body;
        }
        if(in_array(strtolower($meta['deprecated']??''),['true','1','yes'],true))$body="> **Deprecated:** This content is marked deprecated in ReadMe.\n\n".$body;
        if(isset($meta['excerpt'])&&trim($meta['excerpt'])!=='')$body='> '.str_replace(["\r","\n"],' ',trim($meta['excerpt']))."\n\n".$body;
        $title=$meta['title']??null;
        // Blocks inside fenced examples must not be interpreted.
        $chunks=preg_split('/(^ {0,3}(`{3,}|~{3,})[^\r\n]*(?:\r?\n|$).*?^ {0,3}\2[ \t]*(?:\r?\n|$))/ms',$body,-1,PREG_SPLIT_DELIM_CAPTURE);
        $converted='';foreach($chunks as $i=>$chunk){if($i%3!==0){if($i%3===1)$converted.=$chunk;continue;}
            $chunk=preg_replace_callback('~<Callout\b([^>]*)>(.*?)</Callout>~si',static function($m){$type=preg_match('/(?:theme|type)=["\'](warning|danger|success|tip)["\']/i',$m[1],$match)?strtolower($match[1]):'info';return self::block('book-callout',['v'=>1,'type'=>$type,'title'=>'','body'=>trim($m[2])]);},$chunk);
            $chunk=preg_replace_callback('~^ {0,3}>\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(?:\r?\n {0,3}>?[^\r\n]*)*~mi',static function($m){$type=match(strtoupper($m[1])){'TIP'=>'tip','WARNING'=>'warning','CAUTION'=>'danger',default=>'info'};$text=preg_replace('/^ {0,3}> ?/m','',$m[0]);$text=preg_replace('/^\[![A-Z]+\]\s*/','',$text);return self::block('book-callout',['v'=>1,'type'=>$type,'title'=>'','body'=>trim($text)]);},$chunk);
            $converted.=$chunk;
        }
        // Ignore rather than execute unsupported dynamic MDX.
         $withoutCode=preg_replace('/^ {0,3}(`{3,}|~{3,})[^\r\n]*\n.*?^ {0,3}\1\s*$/ms','',$converted)??$converted;
        if(preg_match('/^\s*(?:import|export)\s+(?:[\w{*]|default)/m',$withoutCode)||preg_match('/\{\s*(?:\.\.\.|[A-Za-z_$][\w$]*\s*(?:\(|\?|\.))/', $withoutCode))throw new \InvalidArgumentException('ReadMe executable MDX requires static export: '.$path);
        if(preg_match('~https://files\.readme\.io/[^\s)"\'>]+~i',$converted)&&count($warnings)<1000)$warnings[]=['source'=>$path,'url'=>'Remote files.readme.io assets are not present in the ReadMe ZIP; links preserved'];
        $converted=preg_replace_callback('~<Recipe\b[^>]*\bslug=[\"\']([a-zA-Z0-9._/-]{1,120})[\"\'][^>]*/>~i',static fn($m)=>'[Recipe: '.$m[1].'](/recipes/'.ltrim($m[1],'/').')',$converted);
        if(preg_match('~<(?!(?:Callout|Tabs|Tab|Step|Steps|Accordion|AccordionGroup|Card|CardGroup|CodeGroup|Frame)\b)[A-Z][A-Za-z0-9]*\b~',$withoutCode)&&count($warnings)<1000)$warnings[]=['source'=>$path,'url'=>'Unsupported custom ReadMe MDX component preserved as inert markup'];
        $converted=MintlifyMdx::convert($converted,$warnings);
        $front=$title!==null?'---'."\n".'title: '.json_encode($title,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n---\n":'';
        $result=$front.$converted;Markdown::validate($result);return $result;
    }
}
