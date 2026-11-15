<?php
declare(strict_types=1);
namespace CajeerBook;

use CajeerEngine\Update\OperationFiles as Files;

/** Portable published Reader. No application state, credentials, server routes or CDN dependencies. */
final class StaticExport
{
    private const MAX_GENERATED = 256 * 1024 * 1024;
    private const MAX_REDIRECTS = 10000;
    private const MIME_EXT = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf','application/zip'=>'zip','text/plain'=>'txt'];
    private const CSP = "default-src 'none'; script-src 'self' file:; style-src 'self' file:; img-src 'self' file: data: blob:; frame-src 'self' file:; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self' file:";

    public function __construct(private readonly string $root,private readonly BookStore $store,private readonly MediaStore $media,private readonly ?string $sourceOrigin=null) {}

    public static function form(array $db,string $visibility,string $csrf,string $error=''):string
    {
        $e=Markdown::e(...);$options='<option value="">All published books and versions</option>';$scopes=[];
        foreach(BookStore::published($db) as $key=>$p){$scope=PageTree::scope($key);$scopes[$scope]=true;}
        foreach(array_keys($scopes) as $scope)$options.='<option value="'.$e($scope).'">'.$e($scope).'</option>';
        $public=count(Visibility::pages($db,$visibility,'none'));$total=count(BookStore::published($db));
        return '<div class="eyebrow">PUBLISHING / STATIC EXPORT</div><h1>Static Export</h1><p>Download a standalone HTML site with offline search, local media, navigation and Mermaid diagrams. No PHP, database or network is required to read the extracted site.</p><p><strong>'.$public.'</strong> public / <strong>'.$total.'</strong> published pages in this workspace. Drafts, revisions, credentials and unused media are never included.</p>'.($error!==''?'<p role="alert" class="tree-notice">'.$e($error).'</p>':'').'<form method="post" action="'.$e(WorkspaceUrls::url('/studio/static-export')).'" class="editor"><input type="hidden" name="csrf" value="'.$e($csrf).'"><label>Book / version<select name="scope">'.$options.'</select></label><label>Visibility<select name="visibility"><option value="public">Public published only (recommended)</option><option value="all">All published, including private</option></select></label><label><input type="checkbox" name="confirm_private" value="1"> I understand that a private export has no login or access controls. Anyone with the files can read it.</label><button class="primary" name="action" value="download">Download static site (.zip)</button></form><p>Extract the ZIP and open <code>index.html</code>, or upload the entire directory to a static host. Remote images and links outside the selected documentation are not embedded; external web links remain external. API Playground and other server-only actions are not exported.</p><p><a href="'.$e(WorkspaceUrls::url('/studio')).'">Back to Studio</a></p>';
    }

    /** Prefix every directory segment: no collisions with index.html, Windows device names or nested pages. */
    public static function pagePath(string $key):string
    {
        if(!PageTree::validKey($key))throw new \InvalidArgumentException('Invalid export page key');
        return 'docs/'.implode('/',array_map(static fn($s)=>'p-'.$s,explode('/',$key))).'/index.html';
    }

    private static function relative(string $from,string $to):string
    {
        $a=explode('/',$from);array_pop($a);$b=explode('/',$to);
        while($a&&$b&&$a[0]===$b[0]){array_shift($a);array_shift($b);}
        return str_repeat('../',count($a)).implode('/',$b);
    }

    private static function removeStage(string $dir):void
    {
        foreach(new \DirectoryIterator($dir) as $file)if(!$file->isDot())unlink($file->getPathname());
        rmdir($dir);
    }

    /** Publish atomically to a new ZIP; caller must choose an authorized scope/visibility. */
    public function create(string $target,string $visibility,string $scope='',bool $includePrivate=false,?callable $releaseGuard=null):array
    {
        if(!in_array($visibility,Visibility::WORKSPACE,true)||($scope!==''&&!preg_match('~\A[a-z0-9][a-z0-9._-]{0,63}/[a-z0-9][a-z0-9._-]{0,63}\z~D',$scope)))throw new \InvalidArgumentException('Invalid export scope');
        if(!str_ends_with($target,'.zip')||file_exists($target)||is_link($target)||!is_dir(dirname($target))||is_link(dirname($target)))throw new \InvalidArgumentException('Export destination must be a new .zip file in an existing directory');
        $stage=dirname($target).'/.book-static-'.bin2hex(random_bytes(16));Files::dir($stage);
        $archive=$stage.'/site.zip';$zip=new \ZipArchive();$opened=false;
        try{
            if($zip->open($archive,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true)throw new \RuntimeException('Cannot create static ZIP');$opened=true;
            $result=$this->store->snapshot(function(array $db)use($zip,$stage,$scope,$visibility,$includePrivate,$releaseGuard,&$opened){
                $pages=Visibility::pages($db,$visibility,$includePrivate?'owner':'none');
                if($scope!=='')$pages=array_filter($pages,static fn($k)=>PageTree::scope($k)===$scope,ARRAY_FILTER_USE_KEY);
                $release=$releaseGuard!==null?$releaseGuard($db,$pages):null;
                if(!$pages)throw new \DomainException('No published pages in the selected scope and visibility.');
                $result=$this->build($zip,$stage,$db,$pages,$includePrivate,$release);
                // ZipArchive reads addFile sources only at close; keep snapshot lock until then.
                $ok=$zip->close();$opened=false;if(!$ok)throw new \RuntimeException('Cannot finalize static ZIP');
                return $result;
            });
            if(!chmod($archive,0600))throw new \RuntimeException('Cannot protect static ZIP');
            $handle=fopen($archive,'rb');if(!$handle)throw new \RuntimeException('Cannot read static ZIP');
            try{if(!fsync($handle))throw new \RuntimeException('Cannot sync static ZIP');}finally{fclose($handle);}
            if(!link($archive,$target))throw new \RuntimeException('Cannot publish static ZIP (destination must not exist)');
            Files::syncDir(dirname($target));return $result;
        }finally{
            try{if($opened){$zip->unchangeAll();$zip->close();}}finally{self::removeStage($stage);}
        }
    }

    public function download(string $visibility,string $scope,bool $includePrivate,?callable $beforeSend=null):void
    {
        $dir=sys_get_temp_dir().'/.book-static-download-'.bin2hex(random_bytes(16));Files::dir($dir);$path=$dir.'/site.zip';
        try{
            $this->create($path,$visibility,$scope,$includePrivate);if($beforeSend!==null)$beforeSend();
            header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="cajeerbook-static.zip"');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($path));
            readfile($path);
        }finally{self::removeStage($dir);}
    }

    private function build(\ZipArchive $zip,string $stage,array $db,array $pages,bool $includePrivate,?array $release=null):array
    {
        $bytes=0;$serial=0;$entries=[];
        $addFile=static function(string $name,string $file)use($zip,&$entries):void{
            if(isset($entries[$name])||!is_file($file)||is_link($file)||!$zip->addFile($file,$name))throw new \RuntimeException('Cannot add export file: '.$name);
            if(!$zip->setExternalAttributesName($name,\ZipArchive::OPSYS_UNIX,0100644<<16))throw new \RuntimeException('Cannot set export file permissions: '.$name);
            $entries[$name]=true;
        };
        $write=static function(string $name,string $html)use($stage,&$bytes,&$serial,$addFile):void{
            $bytes+=strlen($html);if($bytes>self::MAX_GENERATED)throw new \DomainException('Generated site exceeds 256 MiB. Export one book/version instead.');
            $file=$stage.'/part-'.++$serial;if(file_put_contents($file,$html)!==strlen($html))throw new \RuntimeException('Cannot write export file');$addFile($name,$file);
        };
        $asset=$this->root.'/public/assets/';
        foreach(['book.css','book.js','diagrams.js','static-search.js','static.css'] as $file)$addFile('assets/'.$file,$asset.$file);
        $paths=[];foreach($pages as $key=>$p)$paths[$key]=self::pagePath($key);
        $map=Visibility::links($db,$pages);$mediaPaths=[];$mediaBytes=0;$diagram=false;$omitted=0;
        // Only AST-recognized media in selected published snapshots. Verify actual bytes before packaging.
        foreach($pages as $key=>$p)foreach(Markdown::mediaReferences($p['body'],$p['link_base']??$key) as $id){
            if(isset($mediaPaths[$id]))continue;
            $item=$db['media'][$id]??null;if(!$item||!isset(self::MIME_EXT[$item['mime']??'']))throw new \RuntimeException('Missing or unsupported published media: '.$id);
            $blob=$this->media->blob($id);$meta=MediaStore::inspect($blob,$item['name']);
            foreach($meta as $field=>$value)if(($item[$field]??null)!==$value)throw new \RuntimeException('Published media integrity mismatch: '.$id);
            $mediaBytes+=$meta['size'];if($mediaBytes>MediaStore::MAX_TOTAL)throw new \DomainException('Export media quota exceeded');
            $ext=strtolower(pathinfo($meta['name'],PATHINFO_EXTENSION));$mediaPaths[$id]='media/'.$id.'.'.$ext;$addFile($mediaPaths[$id],$blob);
        }
        $resolve=static function(string $from,string $url,bool $image)use($paths,$map,$mediaPaths,$pages,&$omitted):?string{
            if(!$image&&str_starts_with($url,'#'))return $url;
            if(!$image&&preg_match('~\A(?:https?://|mailto:)~i',$url))return $url;
            $prefix=WorkspaceUrls::prefix();if($prefix!==''&&str_starts_with($url,$prefix.'/'))$url=substr($url,strlen($prefix));
            $url=WorkspaceUrls::normalize($url);$len=strcspn($url,'?#');$path=substr($url,0,$len);$suffix=substr($url,$len);
            $target=null;
            if(!$image&&preg_match('~\A/l/(en|ru)/docs/(.+)\z~D',$path,$lm)){$key=rawurldecode($lm[2]);$key=$map[$key]??$key;$variant=Locale::variants($pages,$key)[$lm[1]]??null;$target=$variant!==null?($paths[$variant]??null):null;if($target===null&&preg_match('/(?:^\?|&)fallback=1(?:&|#|$)/',$suffix)&&isset($paths[$key]))$target=$paths[$key];}
            elseif(!$image&&str_starts_with($path,'/docs/')){$key=rawurldecode(substr($path,6));$target=$paths[$map[$key]??$key]??null;}
            elseif(preg_match('~\A/media/([a-f0-9]{32})\z~D',$path,$m)){
                $target=$mediaPaths[$m[1]]??null;
                if($image&&$target!==null&&!preg_match('/\.(?:png|jpe?g|gif|webp)\z/D',$target))$target=null;
                // Download query is a server instruction, not needed by a static file.
                $hash=strpos($suffix,'#');$suffix=$hash===false?'':substr($suffix,$hash);
            }elseif(!$image&&$path==='/')$target='index.html';
            elseif(!$image&&$path==='/search')$target='search.html';
            if($target===null){++$omitted;return null;}
            return self::relative($from,$target).$suffix;
        };
        $scopes=[];foreach($pages as $key=>$p)$scopes[PageTree::scope($key)][$key]=$p;
        $books=BookStore::books($db);$indexFile=$stage.'/search-index';$index=fopen($indexFile,'xb');if(!$index)throw new \RuntimeException('Cannot create search index');
        $indexBytes=0;$append=static function(string $s)use($index,&$indexBytes):void{$indexBytes+=strlen($s);if($indexBytes>64*1024*1024)throw new \DomainException('Search index exceeds 64 MiB; export one book/version instead.');if(fwrite($index,$s)!==strlen($s))throw new \RuntimeException('Cannot write search index');};
        try{
            $append('window.BOOK_STATIC_INDEX=[');$first=true;
            foreach($pages as $key=>$p){
                $from=$paths[$key];$url=static fn(string $u,bool $image)=>$resolve($from,$u,$image);
                $r=Markdown::render($p['body'],$key,false,false,$p['link_base']??null,$map,exportUrl:$url);
                if(str_contains($r['html'],'data-book-mermaid="true"'))$diagram=true;
                $toc='';foreach($r['toc'] as $h)$toc.=self::a('#'.$h['id'],$h['title'],'toc-level-'.$h['level']);
                $lang=Locale::language($p);$siblings=Locale::pages($scopes[PageTree::scope($key)],$lang);$keys=array_keys($siblings);$i=array_search($key,$keys,true);$pager='<div class="pager">';
                if($i>0)$pager.=self::a(self::relative($from,$paths[$keys[$i-1]]),'← '.$siblings[$keys[$i-1]]['title']);
                if(isset($keys[$i+1]))$pager.=self::a(self::relative($from,$paths[$keys[$i+1]]),$siblings[$keys[$i+1]]['title'].' →');
                $switch='<div class="versions">'.Markdown::e(Locale::translate($lang,'Versions')).': ';[$b,$v,$tail]=explode('/',$key,3);
                foreach($scopes as $scope=>$group){[$book,$version]=explode('/',$scope);if($book!==$b)continue;$group=Locale::pages($group,$lang);if(!$group)continue;$dest=$scope.'/'.$tail;if(!isset($group[$dest]))$dest=array_key_first($group);$switch.=self::a(self::relative($from,$paths[$dest]),$version,$version===$v?'active':'');}
                $label=Markdown::e(Locale::translate($lang,'Language'));$switch.='</div><nav class="versions" aria-label="'.$label.'">'.$label.': ';foreach(Locale::variants($pages,$key) as $code=>$dest)$switch.='<a href="'.Markdown::e(self::relative($from,$paths[$dest])).'" lang="'.$code.'" hreflang="'.$code.'"'.($code===$lang?' class="active" aria-current="page"':'').'>'.Markdown::e(Locale::LANGUAGES[$code]).'</a> ';
                $body=$switch.'</nav><div class="eyebrow">'.Markdown::e(PageTree::scope($key).' / '.$p['section']).'</div><h1>'.Markdown::e($p['title']).'</h1><article>'.$r['html'].'</article>'.$pager.'</div>';
                $write($from,self::layout($from,$p['title'],$body,self::navigation($siblings,$paths,$from,$key),$toc,false,$includePrivate,null,$lang));
                $text=html_entity_decode(strip_tags(preg_replace('/<[^>]+>/',' ',$r['html'])??$r['html']),ENT_QUOTES|ENT_HTML5,'UTF-8');
                $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
                $row=['url'=>$from,'title'=>$p['title'],'scope'=>PageTree::scope($key),'path'=>$key,'text'=>$text,'locale'=>$lang];
                $append(($first?'':',').json_encode($row,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$first=false;
            }
            $append("];\n");
        }finally{fclose($index);}
        $addFile('assets/search-index.js',$indexFile);
        // Landing and search remain usable on file:// and subdirectory deployments.
        $cards='<div class="cards">';foreach($scopes as $scope=>$group)foreach(Locale::LANGUAGES as $lang=>$label){$localized=Locale::pages($group,$lang);if(!$localized)continue;$key=array_key_first($localized);[$book,$version]=explode('/',$scope);$cards.='<div class="tile" lang="'.$lang.'"><div class="eyebrow">'.Markdown::e($version.' · '.$label).'</div><h2>'.Markdown::e($books[$book]['title']??$book).'</h2>'.self::a($paths[$key],Locale::translate($lang,'Read documentation →')).'</div>';}$cards.='</div>';
        $write('index.html',self::layout('index.html','Documentation','<div class="eyebrow">CAJEERENGINE BOOK / STATIC EXPORT</div><h1>Documentation</h1><p class="lead">Published documentation snapshot.</p>'.$cards,self::navigation($pages,$paths,'index.html',''),'',false,$includePrivate));
        $write('search.html',self::layout('search.html','Search','<h1>Search documentation</h1><p data-search-status role="status" aria-live="polite">Enter a search term.</p><div data-search-results></div><noscript><p>Search requires JavaScript. Browse the documentation using the navigation.</p></noscript>',self::navigation($pages,$paths,'search.html',''),'',true,$includePrivate));
        $redirects=0;foreach($db['redirects']??[] as $old=>$id){$target=$map[$old]??null;if(!isset($paths[$target??''])||isset($paths[$old])||!PageTree::validKey($old))continue;
            if(++$redirects>self::MAX_REDIRECTS)throw new \DomainException('Too many published redirects');$from=self::pagePath($old);$to=self::relative($from,$paths[$target]);
            $write($from,self::layout($from,'Page moved','<h1>Page moved</h1><p>'.self::a($to,'Continue to '.$pages[$target]['title']).'</p>','','',false,$includePrivate,$to));
        }
        if($diagram){
            $mermaid=$asset.'mermaid/';$version=trim((string)@file_get_contents($mermaid.'VERSION'));
            if($version!==ReleaseHealth::MERMAID_VERSION||!is_file($mermaid.'mermaid.min.js')||filesize($mermaid.'mermaid.min.js')<100000)throw new \DomainException('Mermaid runtime unavailable. Run bin/install-mermaid.sh before exporting diagrams.');
            // Inline trusted runtime in its own opaque sandbox, so file:// does not require cross-origin script fetches.
            $library=file_get_contents($mermaid.'mermaid.min.js');$frame=file_get_contents($asset.'diagram-frame.js');
            if($library===false||$frame===false)throw new \RuntimeException('Cannot read Mermaid runtime');
            $write('assets/diagram-frame.html',self::diagramFrame($library,$frame));$addFile('assets/mermaid-LICENSE.txt',$mermaid.'LICENSE.txt');
        }
        if(!$includePrivate&&$this->sourceOrigin!==null){$host=parse_url($this->sourceOrigin,PHP_URL_HOST);if(!is_string($host)||$this->sourceOrigin!=='https://'.CustomDomains::name($host))throw new \InvalidArgumentException('Invalid static source origin');$write('source.json',json_encode(['source_origin'=>$this->sourceOrigin],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}
        if($release!==null)$write('release.json',json_encode($release,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        $write('README.txt',"CajeerEngine Book static site\n\nExtract every file and open index.html, or deploy the directory to a static HTTP host.\nNo server or CDN is required. JavaScript enables offline search, tabs and Mermaid.\nOnly published snapshots and referenced local media are included.\n".($includePrivate?"WARNING: This export can contain private documentation. Keep it in a protected location.\n":"Public published content only.\n")."External images and unavailable internal links are omitted; external links still require a network.\nPages: ".count($pages)."\nMedia: ".count($mediaPaths)."\nOmitted link/image references: $omitted\n");
        return ['pages'=>count($pages),'media'=>count($mediaPaths),'redirects'=>$redirects,'omitted'=>$omitted];
    }

    private static function a(string $url,string $label,string $class=''):string{return '<a href="'.Markdown::e($url).'"'.($class!==''?' class="'.Markdown::e($class).'"':'').'>'.Markdown::e($label).'</a>';}
    private static function navigation(array $pages,array $paths,string $from,string $current):string
    {
        $nodes=PageTree::nodes($pages);$scopes=[];foreach($pages as $key=>$p)$scopes[PageTree::scope($key)]=true;
        $visit=static function(string $key)use(&$visit,$nodes,$pages,$paths,$from,$current):string{
            $node=$nodes[$key];$label=Markdown::e($node['title']);$link=$node['page']?self::a(self::relative($from,$paths[$key]),$node['title'],$key===$current?'active':''):$label;
            if(!$node['children'])return '<li>'.$link.'</li>';
            $out='<li><details'.($current===''||$key===$current||str_starts_with($current,$key.'/')?' open':'').'><summary>'.$label.'</summary>'.($node['page']?$link:'').'<ul>';
            foreach($node['children'] as $child)$out.=$visit($child);return $out.'</ul></details></li>';
        };
        $html='';foreach(array_keys($scopes) as $scope){$html.='<div class="group">'.Markdown::e($scope).'</div><ul class="reader-tree">';foreach(PageTree::roots($nodes,$scope) as $key)$html.=$visit($key);$html.='</ul>';}
        return $html;
    }

    private static function layout(string $from,string $title,string $body,string $nav,string $toc,bool $search,bool $private,?string $redirect=null,string $lang='en'):string
    {
        $e=Markdown::e(...);$asset=self::relative($from,'assets/');$home=self::relative($from,'index.html');$searchUrl=self::relative($from,'search.html');
        return '<!doctype html><html lang="'.Markdown::e($lang).'" data-diagram-frame="'.$e($asset.'diagram-frame.html').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="'.$e(self::CSP).'"><meta name="robots" content="'.($private?'noindex,nofollow':'index,follow').'">'.($redirect!==null?'<meta http-equiv="refresh" content="0; url='.$e($redirect).'">':'').'<title>'.$e($title).' · CajeerEngine Book</title><link rel="stylesheet" href="'.$e($asset.'book.css').'"><link rel="stylesheet" href="'.$e($asset.'static.css').'"><script defer src="'.$e($asset.'book.js').'"></script><script defer src="'.$e($asset.'diagrams.js').'"></script>'.($search?'<script defer src="'.$e($asset.'search-index.js').'"></script>':'').'<script defer src="'.$e($asset.'static-search.js').'"></script>'.'</head><body><header class="top"><a class="brand" href="'.$e($home).'"><span class="logo">C</span> Cajeer<span>Book</span></a><form class="search" action="'.$e($searchUrl).'" method="get"><input name="q" aria-label="'.$e(Locale::translate($lang,'Search documentation')).'" placeholder="'.$e(Locale::translate($lang,'Search documentation')).'…" maxlength="200">'.($search?'<select name="locale" aria-label="Language"><option value="">All languages</option><option value="en">English</option><option value="ru">Русский</option></select>':($from!=='index.html'?'<input type="hidden" name="locale" value="'.$lang.'">':'')).'<button>'.$e(Locale::translate($lang,'Search')).'</button></form><button id="theme" type="button" aria-label="'.$e(Locale::translate($lang,'Toggle theme')).'">◐</button></header><div class="shell"><aside class="sidebar"><div class="side-label">'.$e(Locale::translate($lang,'Documentation')).'</div>'.$nav.'</aside><main id="main">'.$body.'</main><aside class="toc">'.($toc!==''?'<div class="side-label">'.$e(Locale::translate($lang,'On this page')).'</div>'.$toc:'').'</aside></div><footer class="foot">CajeerEngine Book · Static Export'.($private?' · May contain private documentation':'').'</footer></body></html>';
    }

    private static function diagramFrame(string $library,string $frame):string
    {
        // Base64 keeps arbitrary JS bytes out of the HTML parser. The only inline script is hash-authorized.
        $loader='(()=>{const load=(data)=>new Promise((resolve,reject)=>{const bytes=Uint8Array.from(atob(data),c=>c.charCodeAt(0));const url=URL.createObjectURL(new Blob([bytes],{type:"text/javascript"}));const s=document.createElement("script");s.onload=()=>{URL.revokeObjectURL(url);resolve()};s.onerror=()=>{URL.revokeObjectURL(url);reject(new Error("Diagram runtime unavailable"))};s.src=url;document.head.append(s)});load("'.base64_encode($library).'").then(()=>load("'.base64_encode($frame).'")).catch(()=>{document.getElementById("message").textContent="Diagram runtime unavailable. Source remains available."})})();';
        $hash=base64_encode(hash('sha256',$loader,true));
        $csp="default-src 'none'; script-src 'sha256-$hash' blob:; style-src 'unsafe-inline'; img-src data: blob:; font-src 'none'; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="'.Markdown::e($csp).'"><meta name="referrer" content="no-referrer"><title>Mermaid diagram</title><style>html,body{margin:0;padding:0;min-height:100%;font:14px system-ui,sans-serif}body{padding:12px;box-sizing:border-box}#diagram{width:max-content;min-width:100%;display:flex;justify-content:center}svg{display:block}#message{white-space:pre-wrap;overflow-wrap:anywhere}</style></head><body><div id="diagram" aria-label="Diagram"></div><p id="message" role="status"></p><script>'.$loader.'</script></body></html>';
    }
}
