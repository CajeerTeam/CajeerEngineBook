<?php
declare(strict_types=1);
namespace CajeerBook;

/** Import an untrusted Mintlify repository without executing MDX, JSX, JS or remote URLs. */
final class MintlifyImport
{
    private const PAGE_EXT='~\.(?:md|mdx|markdown)$~i';
    private const ALLOWED_ASSETS='~\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$~i';

    /** @return array{files:array,nav:array,redirects:array,warnings:array,mintlify:bool} */
    public static function prepare(array $files):array
    {
        $names=array_keys($files);$prefix='';
        // Directory/ZIP imports sometimes contain a single enclosing repository folder.
        foreach($names as $path)ImportFiles::path((string)$path);
        if($names){$root=explode('/',(string)$names[0])[0];if(!in_array($root,['docs.json','mint.json'],true)&&count(array_filter($names,static fn($p)=>str_starts_with((string)$p,$root.'/')))===count($names)
            && (isset($files[$root.'/docs.json'])||isset($files[$root.'/mint.json'])))$prefix=$root.'/';}
        $normalized=[];foreach($files as $name=>$path){$name=substr((string)$name,strlen($prefix));ImportFiles::path($name);if(isset($normalized[$name]))throw new \InvalidArgumentException('Duplicate Mintlify source: '.$name);$normalized[$name]=$path;}
        $configuration=array_key_exists('docs.json',$normalized)?'docs.json':(array_key_exists('mint.json',$normalized)?'mint.json':null);
        if($configuration===null)return ['files'=>$files,'nav'=>[],'redirects'=>[],'warnings'=>[],'mintlify'=>false];
        if(isset($normalized['docs.json'],$normalized['mint.json']))throw new \InvalidArgumentException('Choose either docs.json or mint.json, not both');
        $raw=file_get_contents($normalized[$configuration]);if(!is_string($raw)||strlen($raw)>512*1024)throw new \InvalidArgumentException('Mintlify configuration exceeds 512 KiB');
        try{$config=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('Invalid Mintlify JSON configuration',0,$e);}
        if(!is_array($config)||array_is_list($config))throw new \InvalidArgumentException('Mintlify configuration must be a JSON object');
        $warnings=[];$nav=[];$counter=0;
        $tree=$config['navigation']??null;
        if(!is_array($tree))throw new \InvalidArgumentException('Mintlify navigation must be an array or object');
        self::walk($tree,[],$nav,$counter,$warnings);
        $redirects=[];$rules=$config['redirects']??[];
        if(!is_array($rules))throw new \InvalidArgumentException('Invalid Mintlify redirects');
        if(count($rules)>500)throw new \InvalidArgumentException('Too many Mintlify redirects');
        foreach($rules as $source=>$rule){
            if(is_string($source)&&is_string($rule)){$old=$source;$target=$rule;}
            elseif(is_array($rule)){$old=$rule['source']??$rule['from']??null;$target=$rule['destination']??$rule['to']??null;}
            else throw new \InvalidArgumentException('Invalid Mintlify redirect rule');
            if(!is_string($old)||!is_string($target))throw new \InvalidArgumentException('Invalid Mintlify redirect paths');
            if(preg_match('~\A(?:https?://|//)~i',$target)){
                if(count($warnings)<1000)$warnings[]=['source'=>'redirects','url'=>'External redirect not imported: '.$old];
                continue;
            }
            $old=self::route($old);$target=self::route($target);
            if($old===$target)continue;
            if(isset($redirects[$old])&&$redirects[$old]!==$target)throw new \InvalidArgumentException('Conflicting Mintlify redirect');
            $redirects[$old]=$target;
        }
        $content=[];foreach($normalized as $name=>$path){
            if($name===$configuration)continue;
            if(preg_match(self::PAGE_EXT,$name)||preg_match(self::ALLOWED_ASSETS,$name))$content[$name]=$path;
            elseif(count($warnings)<1000)$warnings[]=['source'=>$name,'url'=>'Unsupported file type omitted (not executed)'];
        }
        return ['files'=>$content,'nav'=>$nav,'redirects'=>$redirects,'warnings'=>$warnings,'mintlify'=>true];
    }
    /** Normalize a public Mintlify route to a portable Book path. */
    public static function route(string $name):string
    {
        if(strlen($name)>512||!preg_match('//u',$name)||str_contains($name,'\\')||preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1f\x7f?#:]~',$name)
            ||preg_match('~\A(?:[a-z][a-z0-9+.-]*:|//)~i',$name))throw new \InvalidArgumentException('Unsafe Mintlify page route');
        if(str_contains($name,'*'))throw new \InvalidArgumentException('Wildcard Mintlify redirects cannot be mapped to a single Book page');
        $name=trim($name,'/');$name=preg_replace(self::PAGE_EXT,'',$name);
        if($name===''||strtolower($name)==='index'||strtolower($name)==='readme')return 'index';
        $parts=explode('/',$name);foreach($parts as $part){if($part===''||$part==='.'||$part==='..'||strlen($part)>180)throw new \InvalidArgumentException('Unsafe Mintlify page route');}
        return implode('/',array_map(static function(string $segment):string{
            $s=strtolower($segment);$s=trim(preg_replace('/[^a-z0-9._-]+/u','-',$s)??'','-._');return $s!==''?substr($s,0,64):'page-'.substr(hash('sha256',$segment),0,12);
        },$parts));
    }
    /** Traverse both legacy mint.json groups and the recursive docs.json navigation. */
    private static function walk(mixed $node,array $groups,array &$nav,int &$counter,array &$warnings,int $depth=0):void
    {
        if($depth>20)throw new \InvalidArgumentException('Mintlify navigation is too deep');
        if(is_string($node)){
            if(preg_match('~\A(?:https?://|mailto:|#)~i',$node))return;
            $route=self::route($node);if(isset($nav[$route]))return;
            if(++$counter>1000)throw new \InvalidArgumentException('Mintlify navigation has too many pages');
            $section=implode(' / ',array_slice($groups,-4));if(strlen($section)>100){
                $short='';foreach(preg_split('//u',$section,-1,PREG_SPLIT_NO_EMPTY)?:[] as $character){if(strlen($short.$character)>100)break;$short.=$character;}
                $section=$short;if(count($warnings)<1000)$warnings[]=['source'=>'navigation','url'=>'Section title shortened to Book limit: '.$route];
            }
            $nav[$route]=['order'=>$counter*10,'section'=>$section];return;
        }
        if(!is_array($node))throw new \InvalidArgumentException('Invalid Mintlify navigation item');
        if(array_is_list($node)){foreach($node as $child)self::walk($child,$groups,$nav,$counter,$warnings,$depth+1);return;}
        // External menu entries are not local documentation pages.
        if(isset($node['href'])&&is_string($node['href'])&&preg_match('~\A(?:https?://|mailto:|#)~i',$node['href']))return;
        $name='';foreach(['group','tab','anchor','dropdown','version','language','product','item'] as $field)if(is_string($node[$field]??null)&&$node[$field]!==''){$name=trim($node[$field]);break;}
        $inner=$name!==''?array_merge($groups,[$name]):$groups;
        if(isset($node['root'])&&is_string($node['root']))self::walk($node['root'],$inner,$nav,$counter,$warnings,$depth+1);
        foreach(['pages','groups','tabs','anchors','dropdowns','versions','languages','products','menu','global'] as $field)
            if(isset($node[$field]))self::walk($node[$field],$inner,$nav,$counter,$warnings,$depth+1);
        if(isset($node['page'])&&is_string($node['page']))self::walk($node['page'],$inner,$nav,$counter,$warnings,$depth+1);
        if(isset($node['$ref'])){if(count($warnings)<1000)$warnings[]=['source'=>'docs.json','url'=>'External navigation $ref requires a merged configuration: '.$node['$ref']];}
    }
    /** Map extensionless navigation/links to the uploaded source, preserving source-relative media paths. */
    public static function find(string $route,array $files):?string
    {
        $route=trim($route,'/');
        $candidates=[$route.'.mdx',$route.'.md',$route.'.markdown',$route.'/index.mdx',$route.'/index.md',$route.'/README.md',$route.'/README.mdx'];
        if($route==='index')$candidates=['index.mdx','index.md','README.md','README.mdx',...$candidates];
        $match=[];foreach(array_unique($candidates) as $candidate)if(isset($files[$candidate]))$match[]=$candidate;
        if(count($match)>1)throw new \InvalidArgumentException('Ambiguous Mintlify route: '.$route);
        return $match[0]??null;
    }
}
