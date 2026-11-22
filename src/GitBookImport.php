<?php
declare(strict_types=1);
namespace CajeerBook;

/** Read-only, bounded importer of GitBook Git Sync exports; never interprets templates or YAML objects. */
final class GitBookImport
{
    public static function prepare(array $files,string $fallbackBook,string $version):array
    {
        $markers=[];
        foreach($files as $name=>$file){ImportFiles::path((string)$name);
            if(preg_match('~(?:^|/)\.gitbook\.yaml$~',$name))$markers[]=substr($name,0,-strlen('.gitbook.yaml'));
        }
        if(!$markers){foreach($files as $name=>$unused)if(str_contains('/'.$name,'/.gitbook/assets/')){$markers[]=substr($name,0,strpos($name,'.gitbook/assets/'));break;}}
        if(!$markers)return ['gitbook'=>false,'files'=>$files,'pages'=>[],'nav'=>[],'warnings'=>[],'redirects'=>[],'books'=>[]];
        $markers=array_values(array_unique($markers));usort($markers,static fn($a,$b)=>strlen($b)<=>strlen($a));
        foreach($markers as $a)foreach($markers as $b)if($a!==$b&&str_starts_with($b,$a))throw new \InvalidArgumentException('Nested GitBook space directories are ambiguous');
        $multi=count($markers)>1;$content=$pages=$nav=$warnings=$redirects=$books=[];
        foreach($markers as $prefix){
            $config=isset($files[$prefix.'.gitbook.yaml'])?self::configuration(self::read($files[$prefix.'.gitbook.yaml'],65536)):['root'=>'','readme'=>'README.md','summary'=>'SUMMARY.md','redirects'=>[]];
            $base=$prefix.$config['root'];
            $slug=$multi?self::bookSlug($prefix):$fallbackBook;
            if(isset($books[$slug]))throw new \InvalidArgumentException('GitBook space names collide; rename space folders');
            $books[$slug]=['slug'=>$slug,'title'=>$multi?self::bookTitle($prefix):$fallbackBook];
            $spaceFiles=[];
            foreach($files as $name=>$file){
                if(str_starts_with($name,$base)){$rel=substr($name,strlen($base));if($rel!=='')$spaceFiles[$rel]=$name;}
                elseif(str_starts_with($name,$prefix.'.gitbook/assets/'))$spaceFiles[substr($name,strlen($prefix))]=$name;
            }
            $summaryPath=$base.$config['summary'];$order=[];
            if(isset($files[$summaryPath]))$order=self::summary(self::read($files[$summaryPath],262144));
            $listed=[];$sequence=0;
            foreach($order as $entry){$path=$entry['path'];if(!isset($spaceFiles[$path])){if(count($warnings)<1000)$warnings[]=['source'=>$summaryPath,'url'=>'Missing GitBook page: '.$path];continue;}
                if(isset($listed[$path]))throw new \InvalidArgumentException('Duplicated GitBook SUMMARY page');$listed[$path]=true;
                $nav[$spaceFiles[$path]]=['order'=>++$sequence*10,'section'=>$entry['section'],'title'=>$entry['title']];
            }
            foreach($spaceFiles as $rel=>$path){
                if($path===$summaryPath||preg_match('~(?:^|/)\.gitbook\.yaml$~',$path)||str_starts_with($rel,'.gitbook/includes/')||$rel==='.gitbook/vars.yaml')continue;
                if(preg_match('/\.(?:md|mdx|markdown)$/i',$rel)){
                    $route=self::route($rel,$config['readme']);
                    if(isset($pages[$path]))throw new \InvalidArgumentException('Duplicate GitBook page');
                    $pages[$path]=['book'=>$slug,'version'=>$version,'route'=>$route,'root'=>$base,'space_prefix'=>$prefix,'title'=>$nav[$path]['title']??null];
                    $content[$path]=$files[$path];
                    if(!isset($nav[$path]))$nav[$path]=['order'=>++$sequence*10,'section'=>self::section($rel),'title'=>null];
                }elseif(preg_match('/\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$/i',$rel))$content[$path]=$files[$path];
                elseif(preg_match('/\.(?:svg|html?|js|jsx|ts|tsx)$/i',$rel)&&count($warnings)<1000)$warnings[]=['source'=>$path,'url'=>'Unsupported GitBook asset omitted'];
            }
            foreach($config['redirects'] as $old=>$dest){
                $oldRoute=MintlifyImport::route($old);$targetRel=self::redirectTarget($dest);
                $target=$base.$targetRel;
                if(!isset($pages[$target])){if(count($warnings)<1000)$warnings[]=['source'=>$prefix.'.gitbook.yaml','url'=>'Redirect destination not imported: '.$dest];continue;}
                $redirects[]=['book'=>$slug,'version'=>$version,'old'=>$oldRoute,'target'=>$target];
            }
        }
        if(!$pages)throw new \InvalidArgumentException('GitBook export contains no Markdown pages');
        if(count($content)>ImportFiles::MAX_FILES)throw new \InvalidArgumentException('GitBook export exceeds import file limit');
        return ['gitbook'=>true,'files'=>$content,'pages'=>$pages,'nav'=>$nav,'warnings'=>$warnings,'redirects'=>$redirects,'books'=>$books,'original'=>$files];
    }
    public static function convert(string $markdown,string $source,array $files,array &$warnings):string
    {
        if(strlen($markdown)>Markdown::MAX_BYTES)throw new \InvalidArgumentException('GitBook page exceeds Markdown limit');
        [$header,$body]=self::frontmatter($markdown,$source,$warnings);
        // Preserve literal examples in fenced code blocks, including import/include examples.
        $chunks=preg_split('/(^ {0,3}(?:`{3,}|~{3,})[^\r\n]*(?:\r?\n|$).*?^ {0,3}(?:`{3,}|~{3,})[ \t]*(?:\r?\n|$))/ms',$body,-1,PREG_SPLIT_DELIM_CAPTURE);
        $out='';foreach($chunks as $i=>$chunk)$out.=($i%2)?$chunk:self::blocks(self::includes($chunk,$source,$files,$warnings,[],0),$warnings);
        $result=$header.$out;Markdown::validate($result);return $result;
    }
    private static function blocks(string $source,array &$warnings):string
    {
        if(preg_match('/\{%\s*(?:if|else|elseif|endif|conditional)\b/i',$source))throw new \InvalidArgumentException('Conditional GitBook content cannot be safely flattened');
        $source=preg_replace_callback('~\{%\s*hint\s+style=["\'](info|warning|danger|success|tip)["\']\s*%\}(.*?)\{%\s*endhint\s*%\}~si',static function($m){
            $type=match(strtolower($m[1])){'warning'=>'warning','danger'=>'danger','success'=>'success','tip'=>'tip',default=>'info'};
            return self::block('book-callout',['v'=>1,'type'=>$type,'title'=>'','body'=>trim($m[2])]);
        },$source);
        $source=preg_replace_callback('~\{%\s*tabs\s*%\}(.*?)\{%\s*endtabs\s*%\}~si',static function($m)use(&$warnings){
            $inner=$m[1];$items=[];$offset=0;
            preg_match_all('~\{%\s*tab\s+title=["\']([^"\']{1,200})["\']\s*%\}(.*?)\{%\s*endtab\s*%\}~si',$inner,$all,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
            foreach($all as $match){if(trim(substr($inner,$offset,$match[0][1]-$offset))!=='')throw new \InvalidArgumentException('Unsupported GitBook tabs nesting');
                $items[]=['key'=>'tab-'.(count($items)+1),'title'=>$match[1][0],'body'=>trim($match[2][0])];$offset=$match[0][1]+strlen($match[0][0]);
            }
            if(trim(substr($inner,$offset))!==''||!$items)throw new \InvalidArgumentException('Malformed GitBook tabs');
            return self::block('book-tabs',['v'=>1,'kind'=>'tabs','sync'=>'','items'=>$items]);
        },$source);
        $source=preg_replace_callback('~\{%\s*stepper\s*%\}(.*?)\{%\s*endstepper\s*%\}~si',static function($m){
            $inner=$m[1];$items=[];$offset=0;
            preg_match_all('~\{%\s*step\s*%\}(.*?)\{%\s*endstep\s*%\}~si',$inner,$all,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
            foreach($all as $match){if(trim(substr($inner,$offset,$match[0][1]-$offset))!=='')throw new \InvalidArgumentException('Malformed GitBook stepper');
                $body=trim($match[1][0]);$title='Step '.(count($items)+1);if(preg_match('/\A#{1,4}\s+([^\r\n]+)\s*(?:\r?\n)/',$body,$titleMatch)){$title=trim($titleMatch[1]);$body=substr($body,strlen($titleMatch[0]));}
                $items[]=['title'=>$title,'body'=>trim($body)];$offset=$match[0][1]+strlen($match[0][0]);
            }
            if(!$items||trim(substr($inner,$offset))!=='')throw new \InvalidArgumentException('Malformed GitBook stepper');
            return self::block('book-steps',['v'=>1,'kind'=>'steps','items'=>$items]);
        },$source);
        $source=preg_replace_callback('~<details\s*>\s*<summary>([^<>]{1,512})</summary>(.*?)</details>~si',static function($m){
            return self::block('book-accordion',['v'=>1,'kind'=>'accordion','items'=>[['title'=>trim($m[1]),'body'=>trim($m[2]),'open'=>false]]]);
        },$source);
        $source=preg_replace_callback('~\{%\s*content-ref\s+url=["\']([^"\']{1,2048})["\']\s*%\}(.*?)\{%\s*endcontent-ref\s*%\}~si',static function($m){
            return '[Reference]('.$m[1].')';
        },$source);
        $source=preg_replace_callback('~\{%\s*file\s+src=["\']([^"\']{1,2048})["\']\s*%\}(.*?)\{%\s*endfile\s*%\}~si',static function($m){
            $caption=trim(strip_tags($m[2]));return '['.($caption!==''?$caption:'Download file').']('.$m[1].')';
        },$source);
        $source=preg_replace_callback('~\{%\s*code\s+title=["\'][^"\']*["\']\s*%\}\s*(.*?)\s*\{%\s*endcode\s*%\}~si',static fn($m)=>$m[1],$source);
        $source=preg_replace_callback('~\{%\s*embed\s+url=["\'](https?://[^"\']+)["\']\s*%\}~i',static fn($m)=>'[Embedded resource]('.$m[1].')',$source);
        if(preg_match('/\{%\s*(?:hint|tabs|tab|stepper|step|include|code|endhint|endtabs|endtab|endstepper|endstep|content-ref|endcontent-ref|file|endfile)\b/i',$source))throw new \InvalidArgumentException('Unsupported or malformed GitBook block');
        return $source;
    }
    private static function block(string $kind,array $data):string{
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(RichBlocks::parse($kind,$json)===null)throw new \InvalidArgumentException('GitBook block exceeds rich component limits');
        return "\n```$kind\n$json\n```\n";
    }
    private static function includes(string $source,string $path,array $files,array &$warnings,array $stack,int $depth):string
    {
        if($depth>6)throw new \InvalidArgumentException('GitBook include nesting limit reached');
        return preg_replace_callback('~\{%\s*include\s+["\']([^"\']+)["\']\s*%\}~i',static function($m)use($path,$files,&$warnings,$stack,$depth){
            $raw=$m[1];if(preg_match('~\A(?:https?://|//)~i',$raw))throw new \InvalidArgumentException('Remote GitBook includes are forbidden');
            $prefix='';$pos=strpos($path,'.gitbook/');if($pos!==false)$prefix=substr($path,0,$pos);
            else{foreach(array_keys($files) as $file)if(str_ends_with($file,'.gitbook.yaml')&&str_starts_with($path,dirname($file)==='.'?'':dirname($file).'/')){$prefix=dirname($file)==='.'?'':dirname($file).'/';break;}}
            $parts=[];$p=ltrim($raw,'/');if(str_starts_with($p,'reusable-content/'))$p='.gitbook/includes/'.substr($p,17);
            elseif(!str_starts_with($p,'.gitbook/includes/'))$p='.gitbook/includes/'.$p;
            foreach(explode('/',$p) as $part){if($part==='..')throw new \InvalidArgumentException('GitBook include traversal');if($part==='')continue;$parts[]=$part;}
            $target=$prefix.implode('/',$parts);ImportFiles::path($target);
            if(isset($stack[$target]))throw new \InvalidArgumentException('Circular GitBook include');
            if(!isset($files[$target])){if(count($warnings)<1000)$warnings[]=['source'=>$path,'url'=>'Unresolved reusable content: '.$raw];return '> Reusable content unavailable: '.str_replace(['[',']','<','>'], '',$raw);}
            $content=self::read($files[$target],Markdown::MAX_BYTES);$stack[$target]=true;
            return self::includes($content,$target,$files,$warnings,$stack,$depth+1);
        },$source)??throw new \InvalidArgumentException('GitBook include limit exceeded');
    }
    private static function frontmatter(string $markdown,string $path,array &$warnings):array
    {
        if(!preg_match('/\A(?:\xef\xbb\xbf)?---[ \t]*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s',$markdown,$m))return ['', $markdown];
        if(strlen($m[1])>16384)throw new \InvalidArgumentException('GitBook front matter exceeds 16 KiB');
        $meta=[];foreach(preg_split('/\r?\n/',$m[1]) as $line){
            if(preg_match('/\A(title|description):\s*(.*?)\s*\z/',$line,$kv)){
                $val=self::scalar($kv[2]);if($val!==''&&strlen($val)<=512)$meta[$kv[1]]=$val;
            }elseif(preg_match('/\A(?:if|visibility):\s*(.+)/',$line))throw new \InvalidArgumentException('Conditional GitBook page visibility cannot be migrated automatically');
            elseif(preg_match('/\Ahidden:\s*(.+)/',$line)&&count($warnings)<1000)$warnings[]=['source'=>$path,'url'=>'GitBook hidden navigation state is not transferred; imported as unpublished draft'];
        }
        $head='';if($meta){$head="---\n";foreach($meta as $key=>$val)$head.=$key.': '.json_encode($val,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";$head.="---\n";}
        return [$head,substr($markdown,strlen($m[0]))];
    }
    private static function configuration(string $raw):array
    {
        if(preg_match('/(^|\s)(?:!!|!<|&[a-zA-Z]|\*[a-zA-Z]|<<:)/',$raw))throw new \InvalidArgumentException('Unsupported GitBook YAML alias or tag');
        $result=['root'=>'','readme'=>'README.md','summary'=>'SUMMARY.md','redirects'=>[]];$scope='';
        foreach(preg_split('/\r?\n/',$raw) as $line){if(trim($line)===''||preg_match('/^\s*#/',$line))continue;
            if(preg_match('/^([a-zA-Z]+):(?:\s*(.*))?$/',$line,$m)){$scope=$m[1];if($scope==='root'){$v=self::scalar($m[2]??'');$result['root']=self::safeRelative($v,true);}continue;}
            if(preg_match('/^\s{2}([a-zA-Z_-]+):\s*(.*?)\s*$/',$line,$m)&&$scope==='structure'){
                if(in_array($m[1],['readme','summary'],true))$result[$m[1]]=self::safeRelative(self::scalar($m[2]));
            }elseif(preg_match('/^\s{2}([^:#][^:]*):\s*(.*?)\s*$/',$line,$m)&&$scope==='redirects'){
                $old=self::scalar(trim($m[1]));$target=self::scalar($m[2]);
                if(count($result['redirects'])>=500)throw new \InvalidArgumentException('Too many GitBook redirects');
                $result['redirects'][$old]=$target;
            }
        }
        return $result;
    }
    private static function scalar(string $v):string{
        $v=trim($v);if($v==='')return '';
        if($v[0]==='"'){try{$d=json_decode($v,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Invalid GitBook YAML quote');}if(!is_string($d))throw new \InvalidArgumentException('Invalid YAML scalar');return $d;}
        if($v[0]==="'"){if(!str_ends_with($v,"'"))throw new \InvalidArgumentException('Unclosed YAML scalar');return str_replace("''","'",substr($v,1,-1));}
        return preg_replace('/\s+#.*$/','',$v);
    }
    private static function safeRelative(string $value,bool $directory=false):string
    {
        $value=preg_replace('~^\./~','',$value);$value=rtrim($value,'/');
        if($directory&&($value===''||$value==='.'))return '';
        ImportFiles::path($value);return $directory?$value.'/':$value;
    }
    private static function route(string $relative,string $readme):string{
        $relative=preg_replace('/\.(?:md|mdx|markdown)$/i','',$relative);
        if(preg_match('~(?:^|/)(?:README|index)$~i',$relative))$relative=dirname($relative)==='.'?'index':dirname($relative);
        if(preg_match('~^'.preg_quote(preg_replace('/\.(?:md|mdx|markdown)$/i','',$readme),'~').'$~i',$relative))$relative='index';
        return MintlifyImport::route($relative);
    }
    private static function section(string $path):string{return dirname($path)==='.'?'Imported':str_replace(['_','-'],' ',explode('/',$path)[0]);}
    private static function summary(string $text):array
    {
        $out=[];$group='Imported';$parents=[];
        foreach(preg_split('/\r?\n/',$text) as $line){
            if(preg_match('/^##\s+(.+?)\s*$/u',$line,$m)){$group=trim($m[1]);$parents=[];continue;}
            if(!preg_match('/^(\s*)[-*+]\s+\[([^\]]{1,200})\]\(([^)]+)\)\s*$/u',$line,$m))continue;
            $spaces=strlen($m[1]);if($spaces>96||$spaces%2!==0)throw new \InvalidArgumentException('Unsupported GitBook SUMMARY indentation');
            $target=rawurldecode($m[3]);if(preg_match('~\A[a-z][a-z0-9+.-]*:~i',$target)||str_starts_with($target,'/'))continue;
            $target=self::safeRelative($target);
            $depth=intdiv($spaces,2);$section=$group;if($depth&&isset($parents[$depth-1]))$section=$parents[$depth-1];
            $out[]=['path'=>$target,'title'=>$m[2],'section'=>$section];$parents[$depth]=$m[2];
        }
        return $out;
    }
    private static function redirectTarget(string $target):string{
        $target=self::safeRelative(ltrim($target,'/'));if(!preg_match('/\.(?:md|mdx|markdown)$/i',$target))$target.='.md';return $target;
    }
    private static function bookSlug(string $prefix):string{
        $parts=explode('/',trim($prefix,'/'));$last=end($parts);$slug=strtolower(preg_replace('/[^a-z0-9._-]+/i','-',(string)$last));$slug=trim($slug,'-._');if(!BookStore::slug($slug))throw new \InvalidArgumentException('Invalid GitBook Space directory name');return $slug;
    }
    private static function bookTitle(string $prefix):string{return ucwords(str_replace(['-','_'],' ',self::bookSlug($prefix)));}
    private static function read(string $path,int $limit):string{
        $data=@file_get_contents($path);if(!is_string($data)||strlen($data)>$limit)throw new \InvalidArgumentException('GitBook source unreadable or exceeds size limit');return $data;
    }
}
