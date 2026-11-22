<?php
declare(strict_types=1);
namespace CajeerBook;
require_once __DIR__.'/Observability.php';
require_once __DIR__.'/MintlifyImport.php';
require_once __DIR__.'/MintlifyMdx.php';
require_once __DIR__.'/DocusaurusImport.php';
require_once __DIR__.'/DocusaurusMdx.php';
require_once __DIR__.'/GitBookImport.php';

use CajeerEngine\Update\OperationFiles as Files;

/** Source-preserving import planner and atomic draft/media commit. */
final class MarkdownImport
{
    public function __construct(private readonly BookStore $store,private readonly MediaStore $media){}
    /** Called under the installation identity lock; never expose the signing key. */
    public static function previewSecret(string $root,string $csrf):string{
        $path=Files::path($root,'identity/import-preview.key');
        if(!file_exists($path))Files::write($path,bin2hex(random_bytes(32))."\n");
        if(!is_file($path)||filesize($path)!==65)throw new \RuntimeException('Invalid import preview key');
        $key=trim((string)file_get_contents($path));
        if(!preg_match('/\A[a-f0-9]{64}\z/',$key))throw new \RuntimeException('Invalid import preview key');
        return hash_hmac('sha256',$csrf,$key);
    }
    private static function slug(string $s):string{
        $original=$s;$s=strtolower($s);$s=preg_replace('/[^a-z0-9._-]+/u','-',$s);$s=trim($s,'-._');
        if($s==='')return 'page-'.substr(hash('sha256',$original),0,12);if(strlen($s)>64)$s=substr($s,0,51).'-'.substr(hash('sha256',$original),0,12);return $s;
    }
    /** Safe scalar subset of YAML front matter. No aliases, tags, objects or multiline values. */
    public static function document(string $body,string $path,int $order):array{
        if(str_starts_with($body,"\xef\xbb\xbf"))$body=substr($body,3);
        Markdown::validate($body);if(str_contains($body,"\0"))throw new \InvalidArgumentException('Markdown contains NUL');
        $meta=[];
        if(preg_match('/\A---[ \t]*\r?\n(?:[ \t]*(?:#.*)?\r?\n)*[a-zA-Z][a-zA-Z0-9_-]*:/',$body)){
            if(!preg_match('/\A---[ \t]*\r?\n(.*?)\r?\n(?:---|\.\.\.)[ \t]*(?:\r?\n|\z)/s',$body,$m)||strlen($m[1])>8192)throw new \InvalidArgumentException('Invalid or oversized front matter: '.$path);
            foreach(preg_split('/\r?\n/',$m[1]) as $line){
                if(trim($line)===''||str_starts_with(ltrim($line),'#'))continue;
                if(!preg_match('/\A([a-zA-Z][a-zA-Z0-9_-]*):[ \t]*(.*?)\s*\z/u',$line,$kv))throw new \InvalidArgumentException('Only scalar front matter is supported: '.$path);
                $key=$kv[1];$v=$kv[2];if(isset($meta[$key]))throw new \InvalidArgumentException('Duplicate front matter key');
                if(str_starts_with($v,'"')){try{$v=json_decode($v,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Invalid quoted front matter value');}if(!is_string($v))throw new \InvalidArgumentException('Invalid scalar');}
                elseif(str_starts_with($v,"'")){if(!preg_match("/\\A'(?:[^']|'')*'\\z/u",$v))throw new \InvalidArgumentException('Invalid quoted front matter value');$v=str_replace("''","'",substr($v,1,-1));}
                else{if(preg_match('/\A[!&*\[\]{>|]/',$v)||preg_match('/:\s/',$v))throw new \InvalidArgumentException('Unsupported YAML front matter value');$v=preg_replace('/\s+#.*$/u','',$v);}
                if(!in_array($key,['title','description','order','slug','section','locale'],true))throw new \InvalidArgumentException('Unsupported front matter key: '.$key);
                if(!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v))throw new \InvalidArgumentException('Invalid scalar front matter value');
                $meta[$key]=$v;
            }
            $body=substr($body,strlen($m[0]));
            if(isset($meta['description'])&&$meta['description']!==''){$description=preg_replace_callback('/[\\\\`*_{}\[\]<>#+.!|~()\-]/u',fn($m)=>'\\'.$m[0],$meta['description']);$body=$description."\n\n".$body;}
        }
        $parts=explode('/',$path);$file=array_pop($parts);$stem=preg_replace('/\.(?:md|mdx|markdown)$/i','',$file);$dir=array_map(self::slug(...),$parts);
        $leaf=isset($meta['slug'])?self::slug($meta['slug']):self::slug($stem);
        if(!isset($meta['slug'])&&in_array(strtolower($stem),['readme','index'],true)){$pagePath=$dir?implode('/',$dir):'index';}else $pagePath=implode('/',array_merge($dir,[$leaf]));
        $title=$meta['title']??(preg_match('/^ {0,3}#\s+(.+?)\s*#*\s*$/m',$body,$h)?trim($h[1]):($dir&&in_array(strtolower($stem),['readme','index'],true)?end($parts):$stem));
        $section=$meta['section']??($parts[0]??'Imported');
        if(isset($meta['order'])){if(!preg_match('/\A-?[0-9]{1,4}\z/',$meta['order']))throw new \InvalidArgumentException('Invalid front matter order');$order=(int)$meta['order'];}
        if(trim($title)===''||strlen($title)>200||strlen($section)>100||preg_match('/[\x00-\x1f\x7f]/',$title.$section))throw new \InvalidArgumentException('Invalid imported title or section');
        return ['path'=>$pagePath,'title'=>$title,'section'=>$section,'order'=>$order,'body'=>$body,'explicit_order'=>isset($meta['order'])]+(isset($meta['locale'])?['locale'=>Locale::require($meta['locale'])]:[]);
    }
    /** Resolve a local link against the source tree, never the server filesystem. */
    private static function destination(string $url,string $source,array $pages,array $assets,array &$warnings):string{
        $raw=html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $cut=strcspn($raw,'?#');$absolute=substr($raw,0,$cut);
        if(isset($pages[$absolute])&&str_starts_with($absolute,'https://app.gitbook.com/s/'))return '/docs/'.$pages[$absolute].substr($raw,$cut);
        if($raw===''||preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i',$raw))return $url;
         $n=strcspn($raw,'?#');$canonical=substr($raw,0,$n);if(str_starts_with($canonical,"/")&&isset($pages[$canonical]))return '/docs/'.$pages[$canonical].substr($raw,$n);
        $root=str_starts_with($raw,'/');if($root&&preg_match('~^/(?:docs|media|studio|api|w|l)(?:/|$)~',$raw))return $url;
        $n=strcspn($raw,'?#');$path=rawurldecode(substr($raw,0,$n));$suffix=substr($raw,$n);$parts=$root||dirname($source)==='.'?[]:explode('/',dirname($source));
        if(str_contains($path,'\\')||preg_match('/[\x00-\x1f\x7f]/',$path)){$warnings[$url]=true;return $url;}
        foreach(explode('/',$path) as $p){if($p===''||$p==='.')continue;if($p==='..'){if(!$parts){$warnings[$url]=true;return $url;}array_pop($parts);}else $parts[]=$p;}
        $target=implode('/',$parts);
        if(isset($pages[$target]))return '/docs/'.$pages[$target].$suffix;
        if(isset($assets[$target]))return '/media/'.$assets[$target].$suffix;
        $matches=[];foreach(['README.md','index.md','README.markdown','index.markdown'] as $index){$candidate=($target===''?'':$target.'/').$index;if(isset($pages[$candidate]))$matches[]=$pages[$candidate];}
        if(count(array_unique($matches))===1)return '/docs/'.$matches[0].$suffix;
        if(!$root)$warnings[$url]=true;return $url;
    }
    private static function normalizedUrl(string $url):string{return rawurldecode(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));}
    /** Rewrite actual Markdown source, using parser block ranges to protect code and HTML. */
    public static function rewrite(string $body,string $source,array $pages,array $assets,array &$warnings,?array $regions=null,int $depth=0):string{
        // Production callers always obtain real CommonMark block boundaries; the optional argument
        // permits pure source-rewriting tests without an installed parser.
        $known=null;if($regions===null){$syntax=Markdown::importSyntax($body);$regions=$syntax['regions'];$known=[];foreach($syntax['links'] as $link)$known[self::normalizedUrl($link['url'])]=true;}
        preg_match_all('/[^\r\n]*(?:\r\n|\r|\n|$)/',$body,$m);$lines=$m[0];if(end($lines)==='')array_pop($lines);
        $out='';$normal='';
        $flush=static function()use(&$normal,&$out,$source,$pages,$assets,&$warnings,$known){
            if($normal==='')return;$out.=self::rewriteChunk($normal,$source,$pages,$assets,$warnings,$known);$normal='';
        };
        for($i=0;$i<count($lines);$i++){
            $region=$regions[$i+1]??null;if($region===null){$normal.=$lines[$i];continue;}
            $flush();$end=$region['end'];if($end>count($lines))throw new \InvalidArgumentException('Invalid Markdown source range');
            $raw=implode('',array_slice($lines,$i,$end-$i));$component=$depth<RichBlocks::MAX_DEPTH?RichBlocks::parse($region['info'],$region['literal']):null;
            if($component!==null){
                $original=$component;
                // Use a closure capturing warnings by reference for nested bodies.
                $rewriteBody=static function($text)use($source,$pages,$assets,&$warnings,$depth){return self::rewrite($text,$source,$pages,$assets,$warnings,null,$depth+1);};
                if(isset($component['body']))$component['body']=$rewriteBody($component['body']);
                foreach(['cards','items'] as $field)if(isset($component[$field]))foreach($component[$field] as &$item){
                    if(isset($item['body']))$item['body']=$rewriteBody($item['body']);
                    foreach(['href','image'] as $link)if(isset($item[$link]))$item[$link]=self::destination($item[$link],$source,$pages,$assets,$warnings);
                }unset($item);
                if($component!==$original){$encoded=json_encode($component,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    if(RichBlocks::parse($region['info'],$encoded)===null)throw new \InvalidArgumentException('Imported rich block exceeds supported limits');
                    $header=$lines[$i];$newline=str_ends_with($header,"\r\n")?"\r\n":(str_ends_with($header,"\r")?"\r":"\n");
                    $last=$lines[$end-1];$closed=$end>$i+1&&preg_match('/^ {0,3}(?:`{3,}|~{3,})[ \t]*(?:\r?\n|\r|$)/',$last);$raw=$header.$encoded.$newline.($closed?$last:'');
                }
            }
            $out.=$raw;$i=$end-1;
        }$flush();return $out;
    }
    /** Lexical destinations only; parser URL set prevents rewriting lookalike prose. */
    private static function rewriteChunk(string $chunk,string $source,array $pages,array $assets,array &$warnings,?array $known,int $depth=0):string{
        if($depth>16)throw new \InvalidArgumentException('Markdown link nesting exceeds import limit');
        $pattern=<<<'REGEX'
~(?<code>(?<!`)(?<ticks>`+)(?!`).*?(?<!`)\k<ticks>(?!`))|(?<html><!--.*?-->|<[A-Za-z/!?][^>\r\n]*>)|(?<prefix>(?<!\\)(?<label>\[(?:[^\[\]\\]++|\\.|(?&label))*\])\([ \t\r\n]*|^ {0,3}\[[^\]\r\n]+\]:[ \t]*(?:\r?\n[ \t]+)?)(?<dest><[^<>\r\n]*>|(?:\\[() ]|[^\s()<>\\]|(?<paren>\((?:[^()\s\\]++|\\.|(?&paren))*\)))+)(?=[)\s]|$)~ms
REGEX;
        $rewritten=preg_replace_callback($pattern,static function($m)use($source,$pages,$assets,&$warnings,$known,$depth){
            if(($m['code']??'')!==''||($m['html']??'')!=='')return $m[0];
            $prefix=$m['prefix'];$label=$m['label']??'';
            // An image can be nested inside a link label. Preserve label syntax while rewriting its URL.
            if($label!=='')$prefix=self::rewriteChunk($label,$source,$pages,$assets,$warnings,$known,$depth+1).substr($prefix,strlen($label));
            $d=$m['dest'];$angle=str_starts_with($d,'<');$raw=$angle?substr($d,1,-1):$d;$raw=preg_replace('/\\\\([() ])/','$1',$raw);
            if($label!==''&&$known!==null&&!isset($known[self::normalizedUrl($raw)]))return $prefix.$d;
            $resolved=self::destination($raw,$source,$pages,$assets,$warnings);
            return $prefix.($resolved===$raw?$d:($angle?'<'.$resolved.'>':$resolved));
        },$chunk);
        if($rewritten===null)throw new \InvalidArgumentException('Markdown link parsing limit exceeded');return $rewritten;
    }
    public function plan(array $db,array $files,string $book,string $version,string $strategy):array{
        BookStore::key($book,$version,'index');if(!in_array($strategy,['skip','update','rename'],true))throw new \InvalidArgumentException('Invalid conflict strategy');
        $docs=$assets=$mapping=$mediaMap=$rows=$hashes=$warnings=$used=[];$order=0;$total=0;$newMedia=0;$sourceBytes=0;$markdownBytes=0;
        // Sign the original inputs, including Mintlify navigation/redirect configuration.
        foreach($files as $source=>$file){ImportFiles::path((string)$source);if(!is_file($file))throw new \InvalidArgumentException('Import source missing');$hashes[(string)$source]=Files::hash($file);}
        $gitbook=GitBookImport::prepare($files,$book,$version);
        $mint=$gitbook['gitbook']?['mintlify'=>false,'files'=>$files,'nav'=>[],'redirects'=>[],'warnings'=>[]]:MintlifyImport::prepare($files);
        $docus=($mint['mintlify']||$gitbook['gitbook'])?['docusaurus'=>false,'pages'=>[],'nav'=>[],'warnings'=>[]]:DocusaurusImport::prepare($files,$version);
        $files=$gitbook['gitbook']?$gitbook['files']:($mint['mintlify']?$mint['files']:$docus['files']);
        $warnings=$gitbook['gitbook']?$gitbook['warnings']:($mint['mintlify']?$mint['warnings']:$docus['warnings']);
        $navFiles=$gitbook['gitbook']?$gitbook['nav']:($docus['docusaurus']?$docus['nav']:[]);foreach($mint['nav'] as $route=>$settings){$source=MintlifyImport::find($route,$files);if($source!==null){if(!isset($navFiles[$source]))$navFiles[$source]=$settings;}elseif(count($warnings)<1000)$warnings[]=['source'=>'navigation','url'=>'Referenced page not uploaded: '.$route];}
        
        if(count($files)>ImportFiles::MAX_FILES)throw new \InvalidArgumentException('Too many import files');ksort($files,SORT_STRING);
        foreach($db['media']??[] as $item)$total+=$item['size'];
        foreach($files as $path=>$file){
            $path=(string)$path;ImportFiles::path($path);$size=filesize($file);if($size===false||$size>MediaStore::MAX_FILE||($sourceBytes+=$size)>ImportFiles::MAX_TOTAL)throw new \InvalidArgumentException('Import exceeds source size limit');$hashes[$path]=Files::hash($file);
            if(preg_match('/\.(md|mdx|markdown)$/i',$path)){
                if($size>Markdown::MAX_BYTES)throw new \InvalidArgumentException('Markdown exceeds 256 KiB: '.$path);if(($markdownBytes+=$size)>8*1024*1024)throw new \InvalidArgumentException('Markdown import exceeds 8 MiB; split the import');
                $source=(string)file_get_contents($file);$converted=[];
                if($gitbook['gitbook'])$source=GitBookImport::convert($source,$path,$gitbook['original'],$converted);
                elseif($docus['docusaurus'])$source=DocusaurusMdx::convert($source,$converted);
                elseif($mint['mintlify']||preg_match('/\.mdx$/i',$path))$source=MintlifyMdx::convert($source,$converted);
                foreach($converted as $warning){if(count($warnings)>=1000)throw new \InvalidArgumentException('Too many Mintlify conversion warnings');$warning['source']=$path;$warnings[]=$warning;}
                $doc=self::document($source,$path,++$order*10);
                $targetVersion=$version;$targetBook=$book;
                if($gitbook['gitbook']){
                    $sourcePage=$gitbook['pages'][$path]??null;if($sourcePage===null)throw new \InvalidArgumentException('Unexpected GitBook document');
                    $targetBook=$sourcePage['book'];$targetVersion=$sourcePage['version'];$doc['path']=$sourcePage['route'];
                    if($sourcePage['title']!==null)$doc['title']=$sourcePage['title'];
                }
                if($docus['docusaurus']){
                    $sourcePage=$docus['pages'][$path]??null;if($sourcePage===null)throw new \InvalidArgumentException('Unexpected Docusaurus document');
                    $targetVersion=$sourcePage['version'];$doc['path']=$sourcePage['route'];$doc['locale']=$sourcePage['locale'];
                    if($sourcePage['title']!==null&&!str_contains($source,'title:'))$doc['title']=$sourcePage['title'];
                }
                if(isset($navFiles[$path])){$doc['order']=$navFiles[$path]['order'];if($navFiles[$path]['section']!=='')$doc['section']=$navFiles[$path]['section'];$doc['explicit_order']=true;}
                $key=BookStore::key($targetBook,$targetVersion,$doc['path']);
                if(isset($used[$key]))throw new \InvalidArgumentException('Multiple files map to the same page: '.$key);$used[$key]=true;$docs[$path]=['key'=>$key,'doc'=>$doc];
            }else{
                $meta=MediaStore::inspect($file,basename($path));$folder=MediaStore::folder(dirname($path)==='.'?'':dirname($path));$id=null;
                foreach(($db['media']??[])+$assets as $candidate=>$item)if($item['sha256']===$meta['sha256']&&$item['mime']===$meta['mime']){$id=$candidate;break;}
                if($id===null){do{$id=bin2hex(random_bytes(16));}while(isset($assets[$id])||isset($db['media'][$id])||file_exists($this->media->blob($id)));$total+=$meta['size'];$newMedia++;$assets[$id]=$meta+['id'=>$id,'folder'=>$folder,'created'=>gmdate('c'),'updated'=>gmdate('c'),'revision'=>1,'source'=>$file];}
                elseif(isset($db['media'][$id])&&Files::hash($this->media->blob($id))!==$meta['sha256'])throw new \RuntimeException('Existing media is damaged');
                $mediaMap[$path]=$id;
            }
        }
        if(!$docs)throw new \InvalidArgumentException('No Markdown documents found');
        if(count($db['media']??[])+$newMedia>MediaStore::MAX_FILES||$total>MediaStore::MAX_TOTAL)throw new \DomainException('Media quota exceeded');
        $allocated=$used;$newPages=0;
        foreach($docs as $path=>&$d){$key=$d['key'];$entry=$db['pages'][$key]??null;$id=$entry?PageTree::id($key,$entry):'';$reserved=PageTree::reserved($db,$key,$id);$action='create';
            if($entry||$reserved){
                if($strategy==='skip'&&$entry&&!$reserved)$action='skip';
                elseif($strategy==='update'&&$entry&&!$reserved)$action='update';
                elseif($strategy==='rename'){$base=$key;for($n=2;$n<=1001;$n++){$suffix='-'.$n;$last=basename($base);$key=dirname($base).'/'.substr($last,0,64-strlen($suffix)).$suffix;if(!isset($allocated[$key])&&!isset($db['pages'][$key])&&!PageTree::reserved($db,$key,''))break;}if($n>1001)throw new \DomainException('No available import path');$allocated[$key]=true;}
                else throw new \DomainException('Reserved page path: '.$key.'; choose Rename');
            }
            if(!PageTree::validKey($key))throw new \InvalidArgumentException('Renamed page path is too long');$d['key']=$key;$d['action']=$action;$mapping[$path]=$key;if($action==='create')$newPages++;
        }unset($d);
        if(count($db['pages'])+$newPages>1000)throw new \DomainException('Page limit reached');
        if(isset($docs['SUMMARY.md'])){
            $sequence=[];$ignored=[];$syntax=Markdown::importSyntax($docs['SUMMARY.md']['doc']['body']);
            foreach($syntax['links'] as $link){if($link['image'])continue;$url=self::destination($link['url'],'SUMMARY.md',$mapping,[],$ignored);if(str_starts_with($url,'/docs/')){$target=substr($url,6,strcspn($url,'?#')-6);if(!isset($sequence[$target]))$sequence[$target]=(count($sequence)+1)*10;}}
            $next=(count($sequence)+1)*10;foreach($docs as &$d)if(!$d['doc']['explicit_order']){$d['doc']['order']=$sequence[$d['key']]??$next;if(!isset($sequence[$d['key']]))$next+=10;}unset($d);
        }

        // Extensionless Mintlify paths, root-relative links and MDX filenames all resolve to Book URLs.
        $links=$mapping;
        foreach($mapping as $source=>$target){
            $route=preg_replace('/\.(?:md|mdx|markdown)$/i','',$source);
            foreach([$route, '/'.$route] as $alias)if(!isset($links[$alias]))$links[$alias]=$target;
            if(preg_match('~(?:^|/)(?:index|README)$~i',$route)){
                $folder=dirname($route);$alias=$folder==='.'?'index':$folder;
                foreach([$alias, '/'.$alias] as $name)if(!isset($links[$name]))$links[$name]=$target;
            }
            // An explicit front matter slug can affect Book's final URL, not its source path.
        }
        // Map Docusaurus's static/ assets to public URLs without exposing source file paths.
        if($docus['docusaurus'])foreach($docus['pages'] as $src=>$spec){
            $target=$mapping[$src]??null;if($target===null)continue;
            $route=$spec['source_route'];$lang=$spec['locale'];$ver=$spec['version'];
            $base=($lang==='en'?'':'/'.$lang).'/docs/'.($ver===$version?'':$ver.'/');
            foreach([$base.$route, ltrim($base.$route,'/'), '/'.$route] as $alias){
                if($alias==='/'.$route&&($lang!=='en'||$ver!==$version))continue;
                if(!isset($links[$alias]))$links[$alias]=$target;
            }
            if($route==='index'){$root=rtrim($base,'/');if(!isset($links[$root]))$links[$root]=$target;}
        }
        if($gitbook['gitbook'])foreach($gitbook['pages'] as $source=>$spec){
            if(!isset($mapping[$source]))continue;
            $segments=explode('/',trim($spec['space_prefix'],'/'));$spaceId=end($segments);
            if($spaceId!==false&&$spaceId!==''){
                $url='https://app.gitbook.com/s/'.$spaceId.'/'.($spec['route']==='index'?'':$spec['route']);
                $links[rtrim($url,'/')]=$mapping[$source];
            }
        }
        $assetsLinks=$mediaMap;
        foreach($mediaMap as $asset=>$id){
            if(str_starts_with($asset,'public/'))$assetsLinks[substr($asset,7)]=$id;
            if($docus['docusaurus']&&str_starts_with($asset,'static/'))$assetsLinks[substr($asset,7)]=$id;
        }
        $redirects=[];
        foreach($gitbook['redirects'] as $item){
            $dest=$mapping[$item['target']]??null;$alias=BookStore::key($item['book'],$item['version'],$item['old']);
            if($dest===null||$dest===$alias)continue;
            if(isset($db['pages'][$alias])||isset($used[$alias])||isset($redirects[$alias])||PageTree::reserved($db,$alias,($db['pages'][$dest]['id']??''))){
                if(count($warnings)<1000)$warnings[]=['source'=>'redirects','url'=>'Reserved GitBook redirect: '.$alias];continue;
            }$redirects[$alias]=$dest;
        }
        foreach($mint['redirects'] as $old=>$target){
            $source=MintlifyImport::find($target,$files);
            if($source===null||!isset($mapping[$source])){if(count($warnings)<1000)$warnings[]=['source'=>'redirects','url'=>'Missing destination: '.$target];continue;}
            $dest=$mapping[$source];$alias=BookStore::key($book,$version,$old);
            if($alias===$dest)continue;
            if(isset($db['pages'][$alias])||isset($used[$alias])||PageTree::reserved($db,$alias,($db['pages'][$dest]['id']??''))){if(count($warnings)<1000)$warnings[]=['source'=>'redirects','url'=>'Reserved destination: '.$old];continue;}
            $redirects[$alias]=$dest;
        }
        foreach($docs as $path=>&$d){$w=[];if($d['action']!=='skip'){
                $localLinks=$links;$localAssets=$assetsLinks;
                if($gitbook['gitbook']){
                    $root=$gitbook['pages'][$path]['root'];
                    foreach($mapping as $name=>$dest)if(str_starts_with($name,$root))$localLinks['/'.substr($name,strlen($root))]=$dest;
                    foreach($mediaMap as $name=>$id){
                        if(str_starts_with($name,$root))$localAssets['/'.substr($name,strlen($root))]=$id;
                        elseif(str_starts_with($name,$gitbook['pages'][$path]['space_prefix'].'.gitbook/assets/'))$localAssets['/'.substr($name,strlen($gitbook['pages'][$path]['space_prefix']))]=$id;
                    }
                }
                $d['doc']['body']=self::rewrite($d['doc']['body'],$path,$localLinks,$localAssets,$w);Markdown::validate($d['doc']['body']);$d['doc']['media']=Markdown::mediaReferences($d['doc']['body'],$d['key']);foreach($d['doc']['media'] as $id)if(!isset($db['media'][$id])&&!isset($assets[$id]))throw new \InvalidArgumentException('Unknown media file: '.$id);
                if($d['action']==='update'){$current=$db['pages'][$d['key']]['draft'];if(isset($d['doc']['locale'])&&$d['doc']['locale']!==Locale::language($current))throw new \DomainException('Import language differs from existing document: '.$d['key']);unset($current['updated'],$current['locale'],$current['translation_group'],$current['source_digest']);$candidate=$d['doc'];unset($candidate['path'],$candidate['explicit_order'],$candidate['locale']);$current['media']??=[];ksort($current);ksort($candidate);if($current===$candidate)$d['action']='unchanged';}}foreach(array_keys($w) as $url){if(count($warnings)>=1000)throw new \InvalidArgumentException('Too many unresolved links; split import');$warnings[]=['source'=>$path,'url'=>$url];}$rows[]=['source'=>$path,'target'=>$d['key'],'action'=>$d['action'],'title'=>$d['doc']['title']];}unset($d);
        return ['docs'=>$docs,'assets'=>$assets,'rows'=>$rows,'warnings'=>$warnings,'media_new'=>$newMedia,'media_total'=>count($mediaMap),'hashes'=>$hashes,'redirects'=>$redirects,'books'=>$gitbook['books']];
    }
    private static function token(array $db,array $plan,string $book,string $version,string $strategy,string $secret,int $time):string{
        return $time.'.'.hash_hmac('sha256',json_encode([$time,WorkspaceUrls::prefix(),$book,$version,$strategy,$plan['hashes'],hash('sha256',json_encode($db,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR),$secret);
    }
    public function preview(array $files,string $book,string $version,string $strategy,string $secret):array{
        return $this->store->snapshot(function($db)use($files,$book,$version,$strategy,$secret){$p=$this->plan($db,$files,$book,$version,$strategy);return ['rows'=>$p['rows'],'warnings'=>$p['warnings'],'media_new'=>$p['media_new'],'media_total'=>$p['media_total'],'token'=>self::token($db,$p,$book,$version,$strategy,$secret,time())];});
    }
    public function apply(array $files,string $book,string $version,string $strategy,string $secret,string $token):array{
        if(!preg_match('/\A([0-9]{10})\.[a-f0-9]{64}\z/',$token,$m)||(int)$m[1]<time()-900||(int)$m[1]>time())throw new \DomainException('Import preview expired. Preview again.');
        return $this->commit($book,function(array $db)use($files,$book,$version,$strategy,$secret,$token,$m){
            $p=$this->plan($db,$files,$book,$version,$strategy);
            if(!hash_equals(self::token($db,$p,$book,$version,$strategy,$secret,(int)$m[1]),$token))throw new \DomainException('Import files or workspace changed. Preview again.');
            return $p;
        });
    }
    /** Install immutable media with an atomic no-overwrite hardlink, not a replacing rename.
     * The source can change between preview and apply: authenticate the staged bytes
     * before publication. A competing writer or an existing blob must never be replaced. */
    private function installBlob(string $source,string $path,string $digest):void{
        $tmp=dirname($path).'/.import-'.bin2hex(random_bytes(12));$installed=false;
        try{
            Files::copy($source,$tmp);
            if(!hash_equals($digest,Files::hash($tmp)))throw new \RuntimeException('Import source changed');
            if(!@link($tmp,$path))throw new \RuntimeException('Import media destination already exists or is unavailable');
            $installed=true;
            Files::syncDir(dirname($path));
            Files::remove($tmp);
        }catch(\Throwable $e){
            if($installed&&is_file($path)&&!is_link($path))Files::remove($path);
            throw $e;
        }finally{if(is_file($tmp)&&!is_link($tmp))Files::remove($tmp);}
    }
    /** Apply a server-generated, validated plan inside the same exclusive BookStore transaction. */
    public function commit(string $book,callable $planner,?callable $after=null,string $note='Markdown import'):array{
        $created=[];$result=[];
        try{$this->store->change(function(&$db)use($book,$planner,$after,&$created,&$result){
            $p=$planner($db);
            $db['media']??=[];$books=BookStore::books($db);$needed=($p['books']??[])?:[$book=>['slug'=>$book,'title'=>$book]];
            foreach($needed as $slug=>$item)if(!isset($books[$slug])){if(count($books)>=1000)throw new \DomainException('Book limit reached');$books[$slug]=['slug'=>$slug,'id'=>bin2hex(random_bytes(16)),'title'=>$item['title']];}$db['books']=$books;
            foreach($p['assets'] as $id=>$item){$source=$item['source'];unset($item['source']);$path=$this->media->blob($id);$this->installBlob($source,$path,$item['sha256']);$created[$id]=$path;$db['media'][$id]=$item;}
            // Git Sync may identify an exact, unambiguous source rename. Retain the
            // stable page ID and old public path; ordinary tree publication remains separate.
            foreach($p['moves']??[] as $dest=>$from){$entry=$db['pages'][$from]??null;if(!$entry||isset($db['pages'][$dest])||PageTree::scope($dest)!==PageTree::scope($from)||PageTree::reserved($db,$dest,PageTree::id($from,$entry)))throw new \DomainException('Import move no longer available');$entry['id']=PageTree::id($from,$entry);if($entry['published']!==null)$entry['published_key']=PageTree::publicKey($from,$entry);PageTree::retire($db,$from,$entry['id']);unset($db['pages'][$from]);$db['pages'][$dest]=$entry;}
            foreach($p['docs'] as $d){if(in_array($d['action'],['skip','unchanged'],true))continue;$key=$d['key'];$draft=$d['doc'];unset($draft['path'],$draft['explicit_order']);$draft['updated']=gmdate('c');foreach($draft['media'] as $id)if(!isset($db['media'][$id]))throw new \InvalidArgumentException('Unknown media file: '.$id);
                $entry=$db['pages'][$key]??['revision'=>0,'published'=>null,'id'=>bin2hex(random_bytes(16))];$entry['id']??=PageTree::id($key,$entry);$metadata=Locale::metadata($entry['draft']??[]);if(isset($entry['draft'])){if(isset($draft['locale'])&&$draft['locale']!==Locale::language($entry['draft']))throw new \DomainException('Import language differs from existing document: '.$key);unset($draft['locale']);}$entry['draft']=$draft+$metadata;$entry['revision']++;$db['pages'][$key]=$entry;
            }
            foreach($p['redirects']??[] as $alias=>$target){
                if(!isset($db['pages'][$target])||isset($db['pages'][$alias]))throw new \DomainException('Mintlify redirect conflicts with existing page');
                PageTree::remember($db,$alias,PageTree::id($target,$db['pages'][$target]));
            }
            if($after!==null)$after($db,$p);
            $result=['rows'=>$p['rows'],'warnings'=>$p['warnings'],'media_new'=>$p['media_new'],'media_total'=>$p['media_total']];
        },'save',$note);}catch(\Throwable $e){
            // If a durable rename succeeded before a sync error, retain committed blobs.
            try{$this->store->snapshot(function($db)use($created){foreach($created as $id=>$path)if(!isset($db['media'][$id])&&is_file($path))Files::remove($path);});}catch(\Throwable $cleanup){Observability::log('cleanup.failure',['error_type'=>get_class($cleanup)]);}throw $e;
        }return $result;
    }
}
