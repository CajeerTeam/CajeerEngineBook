<?php
declare(strict_types=1);
namespace CajeerBook;

/** Safe Docusaurus repository importer. Never evaluates untrusted sidebar/config JavaScript. */
final class DocusaurusImport
{
    private const PAGE='~\.(?:md|mdx|markdown)$~i';
    private const ASSET='~\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$~i';

    /** @return array{docusaurus:bool,files:array,pages:array,nav:array,warnings:array} */
    public static function prepare(array $files,string $currentVersion):array
    {
        $prefix='';$names=array_keys($files);
        foreach($names as $name)ImportFiles::path((string)$name);
        if($names){$first=explode('/',(string)$names[0])[0];$all=true;foreach($names as $name)if(!str_starts_with((string)$name,$first.'/')){$all=false;break;}
            if($all&&self::hasMarker(array_fill_keys(array_map(static fn($s)=>substr((string)$s,strlen($first)+1),$names),true)))$prefix=$first.'/';}
        $files0=[];foreach($files as $source=>$file){$key=substr((string)$source,strlen($prefix));ImportFiles::path($key);if(isset($files0[$key]))throw new \InvalidArgumentException('Duplicate Docusaurus path');$files0[$key]=$file;}
        if(!self::hasMarker($files0))return ['docusaurus'=>false,'files'=>$files,'pages'=>[],'nav'=>[],'warnings'=>[]];
        BookStore::key('import',$currentVersion,'index');
        if(isset($files0['versions.json'])){
            $raw=self::read($files0['versions.json'],32768);try{$versions=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('Invalid Docusaurus versions.json',0,$e);}
            if(!is_array($versions)||!array_is_list($versions)||count($versions)>30)throw new \InvalidArgumentException('Invalid Docusaurus versions.json');
            foreach($versions as $name)if(!is_string($name)||!BookStore::slug($name))throw new \InvalidArgumentException('Invalid Docusaurus version');
        }
        $warnings=[];$content=[];$pages=[];$byId=[];
        foreach($files0 as $path=>$file){
            $context=self::context($path,$currentVersion);
            if($context!==null&&preg_match(self::PAGE,$path)){
                [$version,$locale,$relative]=$context;
                if(!Locale::valid($locale))throw new \InvalidArgumentException('Unsupported Docusaurus locale: '.$locale);
                if(!BookStore::slug($version))throw new \InvalidArgumentException('Unsupported Docusaurus version: '.$version);
                $meta=self::frontmatter(self::read($file,Markdown::MAX_BYTES));
                $id=preg_replace(self::PAGE,'',$relative);
                if(isset($meta['id'])){$dirname=dirname($relative);$id=($dirname==='.'?'':$dirname.'/').$meta['id'];}
                $id=self::route($id);
                $rawRoute=isset($meta['slug'])?ltrim($meta['slug'],'/'):preg_replace(self::PAGE,'',$relative);
                if(!isset($meta['slug']))$rawRoute=preg_replace('~/(?:index|readme)$~i','',$rawRoute);
                $route=self::route($rawRoute);
                if($route==='index')$route='index';
                $target=$locale==='en'?$route:strtolower($locale).'/'.$route;
                $index=$version.'|'.$locale.'|'.$id;
                if(isset($byId[$index]))throw new \InvalidArgumentException('Duplicate Docusaurus sidebar ID: '.$index);
                $byId[$index]=$path;
                $pages[$path]=['version'=>$version,'locale'=>$locale,'route'=>$target,'id'=>$id,'source_route'=>$route,'title'=>$meta['sidebar_label']??null,'position'=>isset($meta['sidebar_position'])&&preg_match('/\A[0-9]{1,4}\z/D',$meta['sidebar_position'])?(int)$meta['sidebar_position']:null];
                $content[$path]=$file;
            }elseif(preg_match(self::ASSET,$path)&&!preg_match('~^(?:node_modules|src/components|build|\.docusaurus)/~',$path))$content[$path]=$file;
            elseif(!self::controlFile($path)&&count($warnings)<200)$warnings[]=['source'=>$path,'url'=>'Non-document or unsupported asset omitted'];
        }
        if(!$pages)throw new \InvalidArgumentException('Docusaurus bundle contains no docs Markdown/MDX pages');
        $nav=[];$sidebars=[];
        foreach($files0 as $path=>$file){
            if($path==='sidebars.js'||$path==='sidebars.ts'||$path==='sidebars.json'||preg_match('~^versioned_sidebars/version-([a-zA-Z0-9._-]+)-sidebars\.(?:js|ts|json)$~',$path)){
                $version=$currentVersion;if(preg_match('~^versioned_sidebars/version-([a-zA-Z0-9._-]+)-sidebars~',$path,$m))$version=$m[1];
                if(!isset($sidebars[$version])||str_ends_with($path,'.json'))$sidebars[$version]=self::sidebar($path,$file);
            }
        }
        foreach($pages as $source=>$spec)$nav[$source]=['order'=>0,'section'=>'','title'=>$spec['title']];
        foreach($sidebars as $version=>$sidebar){
            $ids=[];foreach($pages as $source=>$spec)if($spec['version']===$version&&$spec['locale']==='en')$ids[$spec['id']]=$source;
            $order=0;$visited=[];
            foreach($sidebar as $sidebarName=>$items){if(!is_array($items))throw new \InvalidArgumentException('Invalid Docusaurus sidebar');
                self::walk($items,[],(string)$sidebarName,$ids,$pages,$nav,$visited,$order,$warnings,0);
            }
            // Versions and locales share deterministic sidebar positions, but never page identities.
            foreach($pages as $source=>$spec){if($spec['version']!==$version||$spec['locale']==='en')continue;
                $original=$ids[$spec['id']]??null;if($original!==null)$nav[$source]=$nav[$original];}
        }
        $fallbackOrder=100;foreach($pages as $source=>$spec)if(!$nav[$source]['order']){
            $nav[$source]['order']=min(9999,($spec['position']??null)!==null?1000+$spec['position']*10:$fallbackOrder++*10);
            $nav[$source]['section']=Locale::LANGUAGES[$spec['locale']]??'Imported';
        }
        return ['docusaurus'=>true,'files'=>$content,'pages'=>$pages,'nav'=>$nav,'warnings'=>$warnings];
    }
    private static function hasMarker(array $files):bool
    {
        foreach(['docusaurus.config.js','docusaurus.config.ts','sidebars.js','sidebars.ts','sidebars.json','versions.json'] as $marker)if(isset($files[$marker]))return true;
        foreach($files as $name=>$unused)if(str_starts_with((string)$name,'versioned_docs/')||str_starts_with((string)$name,'versioned_sidebars/'))return true;
        return false;
    }
    private static function controlFile(string $path):bool{return $path==='versions.json'||preg_match('~^(?:docusaurus\.config\.|sidebars\.|versioned_sidebars/|i18n/.+/docusaurus-plugin-content-docs/(?:current|version-[^/]+)\.json|package\.|yarn\.|\.git)~',$path)===1;}
    private static function context(string $path,string $currentVersion):?array
    {
        if(preg_match('~^docs/(.+)$~',$path,$m))return [$currentVersion,'en',$m[1]];
        if(preg_match('~^versioned_docs/version-([a-zA-Z0-9._-]+)/(.+)$~',$path,$m))return [$m[1],'en',$m[2]];
        if(preg_match('~^i18n/([a-zA-Z-]+)/docusaurus-plugin-content-docs/(current|version-[a-zA-Z0-9._-]+)/(.+)$~',$path,$m))return [$m[2]==='current'?$currentVersion:substr($m[2],8),$m[1],$m[3]];
        return null;
    }
    private static function read(string $path,int $max):string{$data=@file_get_contents($path);if(!is_string($data)||strlen($data)>$max)throw new \InvalidArgumentException('Oversized or unreadable Docusaurus input');return $data;}
    private static function frontmatter(string $markdown):array
    {
        if(!preg_match('/\A(?:\xef\xbb\xbf)?---[ \t]*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s',$markdown,$m))return [];
        $out=[];foreach(preg_split('/\r?\n/',$m[1]) as $line)if(preg_match('/\A(id|slug|sidebar_label|sidebar_position):[ \t]*(.+?)\s*\z/u',$line,$kv)){
            $v=trim($kv[2]);if(str_starts_with($v,'"')){$v=json_decode($v,true);if(!is_string($v))throw new \InvalidArgumentException('Invalid Docusaurus front matter');}
            elseif(str_starts_with($v,"'")){$v=trim($v,"'");}
            if(!is_string($v)||strlen($v)>300||preg_match('/[\x00-\x1f\x7f]/',$v))throw new \InvalidArgumentException('Invalid Docusaurus metadata');
            $out[$kv[1]]=$v;
        }
        return $out;
    }
    public static function route(string $route):string{return MintlifyImport::route($route);}
    private static function sidebar(string $name,string $file):array
    {
        $raw=self::read($file,262144);
        if(str_ends_with($name,'.json')){try{$parsed=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('Invalid Docusaurus sidebar JSON',0,$e);}}
        else $parsed=self::staticJs($raw);
        if(!is_array($parsed)||array_is_list($parsed))throw new \InvalidArgumentException('Docusaurus sidebars must contain an object');
        return $parsed;
    }
    /** Whitelisted JavaScript literal grammar: comments, quoted strings, object/array literals only. */
    private static function staticJs(string $js):array
    {
        // Remove comments only outside strings, reject any non-literal references rather than execute them.
        $js=preg_replace('~\A\s*/\*.*?\*/\s*~s','',$js)??$js;
        if(!preg_match('/(?:\b(?:const|let|var)\s+sidebars(?:\s*:\s*SidebarsConfig)?\s*=|module\.exports\s*=|export\s+default)\s*/',$js,$m,PREG_OFFSET_CAPTURE))throw new \InvalidArgumentException('Docusaurus sidebar must be a static literal');
        $pos=$m[0][1]+strlen($m[0][0]);$value=self::jsValue($js,$pos,0);self::jsSpace($js,$pos);
        if(!is_array($value)||array_is_list($value))throw new \InvalidArgumentException('Invalid Docusaurus sidebar structure');
        // After literal only a static export reference is permitted.
        $rest=trim(substr($js,$pos));if(!preg_match('/\A;?\s*(?:export\s+default\s+sidebars\s*;?|module\.exports\s*=\s*sidebars\s*;?)?\s*\z/s',$rest))throw new \InvalidArgumentException('Dynamic Docusaurus sidebar code is not supported');
        return $value;
    }
    private static function jsSpace(string $raw,int &$pos):void
    {
        $len=strlen($raw);while($pos<$len){if(ctype_space($raw[$pos])){$pos++;continue;}
            if(substr($raw,$pos,2)==='//'){$n=strpos($raw,"\n",$pos+2);$pos=$n===false?$len:$n+1;continue;}
            if(substr($raw,$pos,2)==='/*'){$n=strpos($raw,'*/',$pos+2);if($n===false)throw new \InvalidArgumentException('Unclosed JS comment');$pos=$n+2;continue;}break;}
    }
    private static function jsValue(string $raw,int &$pos,int $depth):mixed
    {
        if($depth>24)throw new \InvalidArgumentException('Sidebar nesting is too deep');self::jsSpace($raw,$pos);
        $first=$raw[$pos]??'';if($first==='{'||$first==='['){$object=$first==='{';$pos++;$out=[];self::jsSpace($raw,$pos);
            while(($raw[$pos]??'')!==($object?'}':']')){
                if($pos>=strlen($raw))throw new \InvalidArgumentException('Unterminated sidebar literal');
                if($object){$key=self::jsToken($raw,$pos,true);self::jsSpace($raw,$pos);if(($raw[$pos]??'')!==':')throw new \InvalidArgumentException('Expected sidebar object colon');$pos++;}
                $item=self::jsValue($raw,$pos,$depth+1);if($object){if(array_key_exists($key,$out))throw new \InvalidArgumentException('Duplicate sidebar key');$out[$key]=$item;}else $out[]=$item;
                self::jsSpace($raw,$pos);if(($raw[$pos]??'')===','){$pos++;self::jsSpace($raw,$pos);continue;}if(($raw[$pos]??'')!==($object?'}':']'))throw new \InvalidArgumentException('Invalid sidebar literal separator');
            }$pos++;return $out;}
        $atom=self::jsToken($raw,$pos,false);return match($atom){'true'=>true,'false'=>false,'null'=>null,default=>$atom};
    }
    private static function jsToken(string $raw,int &$pos,bool $key):string
    {
        self::jsSpace($raw,$pos);$quote=$raw[$pos]??'';
        if($quote==='"'||$quote==="'"){$pos++;$out='';$len=strlen($raw);while($pos<$len){$c=$raw[$pos++];if($c===$quote)return $out;
                if($c==='\\'){$next=$raw[$pos++]??'';$out.=match($next){'n'=>"\n",'r'=>"\r",'t'=>"\t",'"'=>'"',"'"=>"'",'\\'=>'\\',default=>throw new \InvalidArgumentException('Unsupported JS escape')};}
                else $out.=$c;
            }throw new \InvalidArgumentException('Unterminated JS string');}
        if(preg_match('/\G[a-zA-Z_$][a-zA-Z0-9_$-]*/',$raw,$m,0,$pos)){$pos+=strlen($m[0]);if(!$key&&!in_array($m[0],['true','false','null'],true))throw new \InvalidArgumentException('Dynamic JavaScript in sidebar is not supported');return $m[0];}
        throw new \InvalidArgumentException('Invalid static sidebar token');
    }
    private static function walk(array $items,array $groups,string $sidebar,array $ids,array $pages,array &$nav,array &$seen,int &$counter,array &$warnings,int $depth):void
    {
        if($depth>24)throw new \InvalidArgumentException('Docusaurus sidebar too deep');
        foreach($items as $item){
            if(is_string($item)){$type='doc';$id=$item;}
            elseif(is_array($item)){$type=$item['type']??(isset($item['items'])?'category':'doc');$id=$item['id']??null;}
            else throw new \InvalidArgumentException('Unsupported sidebar item');
            if($type==='category'){
                $label=$item['label']??$item['key']??'Category';if(!is_string($label)||strlen($label)>100||preg_match('/[\x00-\x1f\x7f]/',$label))throw new \InvalidArgumentException('Invalid sidebar category');
                $children=$item['items']??[];if(!is_array($children))throw new \InvalidArgumentException('Invalid sidebar children');
                $categoryLink=$item['link']??null;
                if(is_array($categoryLink)&&($categoryLink['type']??null)==='doc'&&is_string($categoryLink['id']??null)){
                    $linkId=self::route($categoryLink['id']);if(isset($ids[$linkId]))self::put($ids[$linkId],[...$groups,$label],$nav,$seen,$counter,$warnings);
                }
                self::walk($children,[...$groups,$label],$sidebar,$ids,$pages,$nav,$seen,$counter,$warnings,$depth+1);
                continue;
            }
            if($type==='autogenerated'){
                $prefix=$item['dirName']??'.';if(!is_string($prefix)||str_contains($prefix,'..'))throw new \InvalidArgumentException('Unsafe autogenerated sidebar');
                $eligible=[];foreach($ids as $name=>$source)if($prefix==='.'||str_starts_with($name,trim($prefix,'/').'/'))$eligible[$name]=$source;
                uksort($eligible,static function(string $a,string $b)use($pages,$eligible):int{
                    $aPos=$pages[$eligible[$a]]['position']??9999;$bPos=$pages[$eligible[$b]]['position']??9999;
                    return $aPos<=>$bPos ?: strcmp($a,$b);
                });
                foreach($eligible as $name=>$source)self::put($source,$groups,$nav,$seen,$counter,$warnings);continue;
            }
            if(in_array($type,['link','html'],true))continue;
            if(!in_array($type,['doc','ref'],true)||!is_string($id))throw new \InvalidArgumentException('Unsupported Docusaurus sidebar item');
            $id=self::route($id);if(!isset($ids[$id])){if(count($warnings)<200)$warnings[]=['source'=>'sidebars','url'=>'Sidebar page absent: '.$id];continue;}
            self::put($ids[$id],$groups,$nav,$seen,$counter,$warnings);
        }
    }
    private static function put(string $source,array $groups,array &$nav,array &$seen,int &$counter,array &$warnings):void
    {
        if(isset($seen[$source]))return;$seen[$source]=true;
        $section=implode(' / ',array_slice($groups,-4));if(strlen($section)>100)$section=mb_strcut($section,0,100,'UTF-8');
        $nav[$source]['order']=++$counter*10;$nav[$source]['section']=$section;
    }
}
