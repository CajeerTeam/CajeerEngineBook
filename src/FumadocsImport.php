<?php
declare(strict_types=1);
namespace CajeerBook;

/** Fumadocs MDX/content-collections repository importer (static content only).
 * Reads JSON and bytes; source TS/JS is never loaded or executed. */
final class FumadocsImport
{
    private const DOC='~\.(?:md|mdx|markdown)$~i';
    private const ASSET='~\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$~i';
    private const ALIASES=['cn'=>'zh-Hans','zh'=>'zh-Hans','tw'=>'zh-Hant','pt-br'=>'pt-BR','zh-cn'=>'zh-Hans','zh-tw'=>'zh-Hant'];
    private static function lang(string $candidate):?string{
        return Locale::valid($candidate)?$candidate:(self::ALIASES[strtolower($candidate)]??null);
    }
    private static function readJson(string $path):array{
        if(!is_file($path)||is_link($path)||filesize($path)>131072)throw new \InvalidArgumentException('Fumadocs meta.json is too large or invalid');
        try{$json=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('Invalid Fumadocs meta.json',0,$e);}
        if(!is_array($json)||array_is_list($json))throw new \InvalidArgumentException('Fumadocs meta.json must be an object');
        foreach(['title','description','icon'] as $name)if(isset($json[$name])&&(!is_string($json[$name])||strlen($json[$name])>200||preg_match('/[\x00-\x1f\x7f]/',$json[$name])))throw new \InvalidArgumentException('Invalid Fumadocs meta label');
        if(isset($json['pages'])&&(!is_array($json['pages'])||!array_is_list($json['pages'])||count($json['pages'])>200))throw new \InvalidArgumentException('Invalid Fumadocs pages list');
        foreach($json['pages']??[] as $entry)if(!is_string($entry)||strlen($entry)>250||!preg_match('//u',$entry))throw new \InvalidArgumentException('Invalid Fumadocs navigation item');
        if(isset($json['staticParams']))throw new \InvalidArgumentException('Fumadocs dynamic staticParams must be pre-rendered before import');
        return $json;
    }
    private static function section(string $section):string{
        if(strlen($section)<=100)return $section;
        $result='';foreach(preg_split('//u',$section,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){if(strlen($result.$char)>100)break;$result.=$char;}return $result;
    }
    private static function slug(string $input):string{
        if(preg_match('/[\[\]{}\\\\]/',$input))throw new \InvalidArgumentException('Dynamic Fumadocs routes must be exported as concrete pages');
        $value=trim((string)preg_replace('/[^a-z0-9._-]+/','-',strtolower($input)),'._-');
        if($value==='')$value='item-'.substr(hash('sha256',$input),0,10);
        return strlen($value)>64?substr($value,0,51).'-'.substr(hash('sha256',$input),0,12):$value;
    }
    /** Source files may be nested inside an exported repository folder. */
    public static function prepare(array $files,string $version):array{
        $none=['fumadocs'=>false,'files'=>$files,'pages'=>[],'nav'=>[],'warnings'=>[]];
        $roots=[];$hasDoc=false;
        foreach($files as $name=>$path){ImportFiles::path((string)$name);
            if(preg_match('~^(.*?content/docs/)(?:meta(?:\.[a-zA-Z-]+)?\.json)$~',(string)$name,$m))$roots[$m[1]]=true;
            if(preg_match('~(?:^|/)(?:source\.config\.[cm]?[jt]s|content-collections\.[cm]?[jt]s)$~',(string)$name)){
                if(preg_match('~^(.*?)(?:source\.config|content-collections)\.~',(string)$name,$m))$roots[$m[1].'content/docs/']=true;
            }
        }
        foreach(array_keys($roots) as $root)foreach($files as $name=>$path)if(str_starts_with((string)$name,$root)&&preg_match(self::DOC,(string)$name)){$hasDoc=true;break 2;}
        if(!$hasDoc)return $none;
        $matching=[];foreach(array_keys($roots) as $root){foreach($files as $name=>$path)if(str_starts_with((string)$name,$root)&&preg_match(self::DOC,(string)$name)){$matching[$root]=true;break;}}
        if(count($matching)!==1)throw new \InvalidArgumentException('Import one Fumadocs content collection at a time');
        $root=array_key_first($matching);$repo=substr($root,0,-strlen('content/docs/'));
        $pages=[];$meta=[];$content=[];$warnings=[];$seen=[];
        foreach($files as $source=>$file)if(str_starts_with((string)$source,$root)){
            $relative=substr((string)$source,strlen($root));
            if(preg_match('~^(.*?)(?:^|/)?meta(?:\.([a-zA-Z-]+))?\.json$~',$relative,$m)){
                $locale=isset($m[2])?self::lang($m[2]):'en';
                $dir=trim($m[1],'/');$parts=$dir===''?[]:explode('/',$dir);
                if($parts&&self::lang($parts[0])!==null){$dirLocale=self::lang(array_shift($parts));if($locale!=='en'&&$locale!==$dirLocale)throw new \InvalidArgumentException('Conflicting Fumadocs meta locales');$locale=$dirLocale;$dir=implode('/',$parts);}
                if($locale===null)throw new \InvalidArgumentException('Unsupported Fumadocs metadata locale: '.$source);
                if(isset($meta[$dir][$locale]))throw new \InvalidArgumentException('Duplicate localized meta.json');
                $meta[$dir][$locale]=self::readJson($file);
            }
        }
        foreach($files as $source=>$file){$source=(string)$source;
            if(str_starts_with($source,$root)){
                $relative=substr($source,strlen($root));
                if(preg_match('~(?:^|/)meta(?:\.[a-zA-Z-]+)?\.json$~',$relative))continue;
                if(preg_match(self::DOC,$relative)){
                    $without=preg_replace(self::DOC,'',$relative);$pathParts=explode('/',$without);$stem=array_pop($pathParts);$locale='en';
                    if(preg_match('/^(.*?)\.([a-zA-Z]{2}(?:-[a-zA-Z]{2,5})?)$/',$stem,$m)){
                        $possible=self::lang($m[2]);if($possible!==null){$stem=$m[1];$locale=$possible;}
                    }
                    // Fumadocs can also use a locale directory, configured with parser: 'dir'.
                    if($pathParts&&self::lang($pathParts[0])!==null){$dirLocale=self::lang(array_shift($pathParts));if($locale!=='en'&&$locale!==$dirLocale)throw new \InvalidArgumentException('Mixed Fumadocs locale encodings');$locale=$dirLocale;}
                    if($stem==='')throw new \InvalidArgumentException('Empty Fumadocs page name');
                    $groups=$pathParts;$canonicalParts=[];foreach($groups as $part){if(preg_match('/^\([^)]+\)$/D',$part))continue;$canonicalParts[]=self::slug($part);}
                    $isIndex=strtolower($stem)==='index';if(!$isIndex)$canonicalParts[]=self::slug($stem);
                    $route=implode('/',$canonicalParts)?:'index';
                    // Original Fumadocs URLs retain the version-root folder even when Book
                    // maps that root to its native version dimension.
                    $sourceRoute=$route;$targetVersion=$version;
                    $ancestor='';foreach($groups as $part){$ancestor=($ancestor===''?'':$ancestor.'/').$part;$settings=array_replace($meta[$ancestor]['en']??[],$meta[$ancestor][$locale]??[]);
                        if(($settings['root']??null)==='version'){
                            $targetVersion=self::slug($part);
                            $before=[];foreach(explode('/',$ancestor) as $folder)if(!preg_match('/^\([^)]+\)$/D',$folder))$before[]=self::slug($folder);
                            $cut=implode('/',$before);
                            if($route===$cut)$route='index';
                            elseif(str_starts_with($route,$cut.'/'))$route=substr($route,strlen($cut)+1)?:'index';
                        }
                    }
                    if(!BookStore::slug($targetVersion))throw new \InvalidArgumentException('Invalid Fumadocs version');
                    $identity=$targetVersion.'|'.$locale.'|'.$route;
                    if(isset($seen[$identity]))throw new \InvalidArgumentException('Duplicate Fumadocs route: '.$identity);$seen[$identity]=true;
                    $pages[$source]=['route'=>($locale==='en'?'':self::slug($locale).'/').$route,'canonical'=>$route,'source_route'=>$sourceRoute,'version'=>$targetVersion,'locale'=>$locale,'dir'=>implode('/',$groups),'stem'=>$stem,'title'=>null];
                    $content[$source]=$file;continue;
                }
                if(preg_match(self::ASSET,$relative)){$content[$source]=$file;continue;}
                if(count($warnings)<1000)$warnings[]=['source'=>$source,'url'=>'Unsupported Fumadocs collection file omitted'];
                continue;
            }
            if(str_starts_with($source,$repo.'public/')&&preg_match(self::ASSET,$source)){$content[$source]=$file;continue;}
            if(count($warnings)<1000&&preg_match('~(?:^|/)(?:source\.config|content-collections|meta\.[jt]s|components/mdx)~',$source))$warnings[]=['source'=>$source,'url'=>'Source code/configuration is not executed or imported'];
        }
        if(!$pages)throw new \InvalidArgumentException('Fumadocs content collection has no pages');
        // Resolve an ordered tree separately per locale. A localized meta.json overrides base meta.json.
        $nav=[];$locales=array_unique(array_column($pages,'locale'));$lookup=[];$folders=[];
        foreach($pages as $source=>$page){$lookup[$page['locale']][$page['dir']][$page['stem']]=$source;
            $components=$page['dir']===''?[]:explode('/',$page['dir']);$parent='';foreach($components as $segment){$folders[$parent][$segment]=true;$parent=($parent===''?'':$parent.'/').$segment;}
        }
        foreach($locales as $locale){$index=0;$visited=[];
            $walk=function(string $dir,array $ancestors,int $depth)use(&$walk,&$nav,&$visited,&$index,$meta,$lookup,$folders,$locale,&$warnings):void{
                if($depth>20)throw new \InvalidArgumentException('Fumadocs navigation exceeds depth limit');
                $settings=array_replace($meta[$dir]['en']??[],$meta[$dir][$locale]??[]);
                $label=trim((string)($settings['title']??($dir!==''?basename($dir):'')));
                $groups=$label!==''?array_merge($ancestors,[$label]):$ancestors;
                $direct=$lookup[$locale][$dir]??[];$sub=$folders[$dir]??[];
                $available=array_keys($direct+$sub);sort($available,SORT_STRING);
                $rules=$settings['pages']??['...'];$exclude=[];
                foreach($rules as $rule)if(str_starts_with($rule,'!'))$exclude[substr($rule,1)]=true;
                $entries=[];$remaining=array_values(array_filter($available,fn($name)=>!isset($exclude[$name])));
                foreach($rules as $rule){
                    if($rule==='...'||$rule==='z...a'){$rest=array_values(array_diff($remaining,$entries));if($rule==='z...a')$rest=array_reverse($rest);array_push($entries,...$rest);continue;}
                    if(str_starts_with($rule,'!')||preg_match('~^(?:external:)?\[~',$rule))continue;
                    if(preg_match('/^---(?:\[[^]]+\])?(.+?)---$/u',$rule)){$entries[]=$rule;continue;}
                    if(str_starts_with($rule,'...')){$folder=substr($rule,3);if(isset($sub[$folder]))$entries[]=$folder;continue;}
                    $rule=preg_replace('~^\./~','',$rule);if($rule==='')continue;
                    $part=explode('/',$rule,2)[0];
                    if(str_contains($rule,'/')&&isset($lookup[$locale][($dir===''?'':$dir.'/').dirname($rule)][basename($rule)]))$entries[]=$rule;
                    elseif(isset($direct[$part])||isset($sub[$part]))$entries[]=$part;
                    elseif(count($warnings)<1000)$warnings[]=['source'=>$dir.'/meta.json','url'=>'Navigation entry not found: '.$rule];
                }
                // Keep hidden pages as unpublished drafts, but do not pretend they were visible in the sidebar.
                $entries=array_values(array_unique($entries));$lastGroup=$groups;
                foreach($entries as $entry){
                    if(preg_match('/^---(?:\[[^]]+\])?(.+?)---$/u',$entry,$heading)){$lastGroup=array_merge($groups,[trim($heading[1])]);continue;}
                    if(str_contains($entry,'/')){
                        $relative=($dir===''?'':$dir.'/').$entry;$parent=dirname($relative);$source=$lookup[$locale][$parent][basename($relative)]??null;
                        if($source!==null&&!isset($visited[$source])){
                            $visited[$source]=true;$section=implode(' / ',array_slice($lastGroup,-4))?:'Documentation';
                            $nav[$source]=['order'=>++$index*10,'section'=>self::section($section)];
                        }
                        continue;
                    }
                    if(isset($direct[$entry])){$source=$direct[$entry];if(!isset($visited[$source])){
                        $visited[$source]=true;$section=implode(' / ',array_slice($lastGroup,-4))?:'Documentation';
                        $nav[$source]=['order'=>++$index*10,'section'=>self::section($section)];
                    }}
                    if(isset($sub[$entry]))$walk(($dir===''?'':$dir.'/').$entry,$lastGroup,$depth+1);
                }
                foreach($direct as $stem=>$source)if(!isset($visited[$source])){
                    $visited[$source]=true;$section=implode(' / ',array_slice($groups,-4))?:'Documentation';
                    $nav[$source]=['order'=>++$index*10,'section'=>self::section($section)];
                    if(count($warnings)<1000)$warnings[]=['source'=>$source,'url'=>'Page not listed in meta.json; imported as an unpublished draft'];
                }
                foreach($sub as $name=>$unused)if(!in_array($name,$entries,true))$walk(($dir===''?'':$dir.'/').$name,$groups,$depth+1);
            };
            $walk('',[],0);
        }
        return ['fumadocs'=>true,'files'=>$content,'pages'=>$pages,'nav'=>$nav,'warnings'=>$warnings,'root'=>$root,'repo'=>$repo];
    }
}
