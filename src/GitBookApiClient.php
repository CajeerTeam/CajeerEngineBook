<?php
declare(strict_types=1);
namespace CajeerBook;

/** Export the GitBook REST API into the identical staging format used by Studio ZIP imports. */
final class GitBookApiClient
{
    private int $requests=0;
    private int $bytes=0;
    private readonly \Closure $transport;
    public function __construct(private readonly string $token,?callable $transport=null)
    {
        if(strlen($token)<8||strlen($token)>4096||preg_match('/[\x00-\x20\x7f]/',$token))throw new \InvalidArgumentException('Invalid GitBook API credential');
        $this->transport=$transport!==null?\Closure::fromCallable($transport):$this->request(...);
    }
    private static function id(string $id):string{if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$id))throw new \InvalidArgumentException('Invalid GitBook identifier');return $id;}
    private function get(string $path):array
    {
        if(++$this->requests>1000)throw new \DomainException('GitBook API request limit reached');
        $response=($this->transport)('https://api.gitbook.com/v1'.$path,$this->token);
        if(!is_string($response)||strlen($response)>4*1024*1024)throw new \DomainException('Invalid or oversized GitBook API response');
        try{$json=json_decode($response,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \DomainException('Invalid GitBook API JSON',0,$e);}
        if(!is_array($json)||array_is_list($json))throw new \DomainException('Invalid GitBook API response object');
        return $json;
    }
    private function request(string $url,string $token):string
    {
        $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>"Authorization: Bearer $token\r\nAccept: application/json\r\n",'timeout'=>25,'ignore_errors'=>true,'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        return self::readUrl($url,$ctx,4*1024*1024,0);
    }
    private static function readUrl(string $url,$ctx,int $max,int $redirects):string
    {
        $origin=parse_url($url);$host=strtolower((string)($origin['host']??''));
        if(strlen($url)>8192||($origin['scheme']??null)!=='https'||isset($origin['user'])||isset($origin['pass'])||(isset($origin['port'])&&$origin['port']!==443)||!self::approvedHost($host))throw new \DomainException('Untrusted GitBook download host');
        $handle=@fopen($url,'rb',false,$ctx);if(!$handle)throw new \RuntimeException('Cannot fetch GitBook API resource');
        try{
            $meta=stream_get_meta_data($handle);$lines=$meta['wrapper_data']??[];$status=0;$location=null;
            foreach($lines as $line){if(preg_match('~^HTTP/\S+\s+(\d{3})~',$line,$m))$status=(int)$m[1];if(stripos($line,'Location:')===0)$location=trim(substr($line,9));}
            if($status>=300&&$status<400&&$location!==null){
                if(str_contains((string)(stream_context_get_options($ctx)['http']['header']??''),'Authorization:'))throw new \DomainException('GitBook API redirect forbidden with bearer token');
                if($redirects>=3)throw new \DomainException('GitBook asset redirect limit');return self::readUrl($location,$ctx,$max,$redirects+1);
            }
            if($status!==200)throw new \RuntimeException('GitBook request returned HTTP '.$status);
            $data=stream_get_contents($handle,$max+1);if($data===false||strlen($data)>$max)throw new \DomainException('GitBook API resource exceeds size limit');return $data;
        }finally{fclose($handle);}
    }
    private static function approvedHost(string $host):bool
    {
        return $host==='api.gitbook.com'||$host==='files.gitbook.com'||$host==='storage.googleapis.com'||$host==='firebasestorage.googleapis.com'||str_ends_with($host,'.gitbook.io');
    }
    /** Organization listing is read-only, cursor-limited and cannot fetch arbitrary hosts. */
    public function spaces(string $organization):array
    {
        self::id($organization);$result=[];$cursor='';$cursors=[];
        do{
            $obj=$this->get('/orgs/'.$organization.'/spaces?limit=100'.($cursor!==''?'&page='.rawurlencode($cursor):''));
            $entries=$obj['items']??[];if(!is_array($entries)||!array_is_list($entries))throw new \DomainException('Invalid GitBook organization page');
            foreach($entries as $entry){if(!is_array($entry)||!is_string($entry['id']??null))throw new \DomainException('Invalid GitBook space');$id=self::id($entry['id']);$result[$id]=$entry['title']??$id;if(count($result)>20)throw new \DomainException('Limit import to 20 GitBook spaces');}
            $cursor=$obj['next']['page']??'';if(!is_string($cursor)||strlen($cursor)>256||isset($cursors[$cursor]))throw new \DomainException('Invalid GitBook pagination');if($cursor!=='')$cursors[$cursor]=true;
        }while($cursor!=='');
        return $result;
    }
    /** Creates a real ImportFiles bundle; pass files() to MarkdownImport preview/apply or pack into ZIP. */
    public function export(array $spaces):ImportFiles
    {
        if(!$spaces||count($spaces)>20)throw new \InvalidArgumentException('Select 1 to 20 GitBook spaces');
        $stage=new ImportFiles();
        foreach($spaces as $spaceId=>$title){$space=self::id((string)$spaceId);$title=is_string($title)?$title:$space;
            $root=$space.'/';
            $tree=$this->get('/spaces/'.$space.'/content/pages');$pages=$tree['pages']??null;
            if(!is_array($pages)||!array_is_list($pages))throw new \DomainException('GitBook API did not return page tree');
            $list=[];$summary=["# Summary",''];$seen=[];
            $this->collect($pages,'',0,$list,$summary,$seen);
            if(!$list)throw new \DomainException('GitBook space has no document pages: '.$space);
            $attachments=$this->files($space);
            $replacement=[];
            foreach($attachments as $entry){$name=$entry['name']??null;$url=$entry['downloadURL']??null;
                if(!is_string($name)||!is_string($url)||!preg_match('/\.(?:png|jpe?g|gif|webp|pdf|zip|txt|csv)$/i',$name))continue;
                $filename=self::assetName($name,$entry['id']??'');$target=$root.'.gitbook/assets/'.$filename;
                $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));if(!self::approvedHost($host))throw new \DomainException('Unexpected GitBook media host');
                $body=$this->download($url,MediaStore::MAX_FILE);
                $stage->addBytes($target,$body);$replacement[$url]='/.gitbook/assets/'.$filename;
            }
            foreach($list as $path=>$id){$response=$this->get('/spaces/'.$space.'/content/page/'.rawurlencode($id).'?format=markdown');
                $body=$response['markdown']??null;
                if(!is_string($body))throw new \DomainException('GitBook API page missing Markdown: '.$id);
                foreach($replacement as $url=>$relative)$body=str_replace($url,$relative,$body);
                if(strlen($body)>262144)throw new \DomainException('GitBook API Markdown page exceeds size limit');
                $stage->addBytes($root.$path,$body);
            }
            $stage->addBytes($root.'SUMMARY.md',implode("\n",$summary)."\n");
            // Static configuration only; the actual source of truth is the pages API response.
            $stage->addBytes($root.'.gitbook.yaml',"root: ./\nstructure:\n  readme: README.md\n  summary: SUMMARY.md\n");
        }
        return $stage;
    }
    private function download(string $url,int $limit):string
    {
        if(++$this->requests>1000)throw new \DomainException('GitBook API request limit reached');
        $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>25,'ignore_errors'=>true,'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $body=self::readUrl($url,$ctx,$limit,0);$this->bytes+=strlen($body);
        if($this->bytes>ImportFiles::MAX_TOTAL)throw new \DomainException('GitBook media exceeds expanded size limit');return $body;
    }
    private function files(string $space):array
    {
        $result=[];$cursor='';$cursors=[];
        do{
            $obj=$this->get('/spaces/'.$space.'/content/files?limit=100'.($cursor!==''?'&page='.rawurlencode($cursor):''));
            $items=$obj['items']??[];if(!is_array($items)||!array_is_list($items))throw new \DomainException('Invalid GitBook media list');
            foreach($items as $item){if(!is_array($item))throw new \DomainException('Invalid GitBook file');$result[]=$item;if(count($result)>ImportFiles::MAX_FILES)throw new \DomainException('Too many GitBook attachments');}
            $cursor=$obj['next']['page']??'';if(!is_string($cursor)||strlen($cursor)>256||isset($cursors[$cursor]))throw new \DomainException('Invalid GitBook media cursor');if($cursor!=='')$cursors[$cursor]=true;
        }while($cursor!=='');return $result;
    }
    private function collect(array $pages,string $prefix,int $level,array &$found,array &$summary,array &$seen):void
    {
        if($level>16)throw new \DomainException('GitBook page nesting limit');
        foreach($pages as $page){if(!is_array($page)||!is_string($page['id']??null)||!is_string($page['title']??null))throw new \DomainException('Invalid GitBook page');
            $id=self::id($page['id']);if(isset($seen[$id]))throw new \DomainException('Duplicate GitBook page ID');$seen[$id]=true;
            $slug=$page['slug']??$page['path']??null;
            if(!is_string($slug)){if(($page['type']??'document')==='document')throw new \DomainException('GitBook document page has no URL path');$slug='group-'.substr(hash('sha256',$id),0,10);}
            $slug=trim($slug,'/');if($slug==='')$slug='index';
            $path=(str_contains($slug,'/')?$slug:($prefix===''?$slug:$prefix.'/'.$slug));
            // API page paths may be canonical full paths; path names are never executed or trusted.
            ImportFiles::path($path);
            $children=$page['pages']??$page['children']??[];if(!is_array($children)||!array_is_list($children))throw new \DomainException('Invalid GitBook child page list');
            if(($page['type']??'document')==='document'){
                $file=$path==='index'?'README.md':$path.'.md';ImportFiles::path($file);
                if(isset($found[$file]))throw new \DomainException('Duplicate GitBook page path');$found[$file]=$id;
                $title=str_replace(['[',']',"\n",'\r'],' ', $page['title']);$summary[]=str_repeat('  ',$level).'* ['.$title.']('.$file.')';
            }else{$title=str_replace(["\n",'\r'],' ',$page['title']);$summary[]='## '.$title;}
            $this->collect($children,$path==='index'?'':$path,$level+1,$found,$summary,$seen);
        }
    }
    private static function assetName(string $name,mixed $id):string
    {
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));$stem=preg_replace('/[^a-zA-Z0-9._-]+/','-',pathinfo($name,PATHINFO_FILENAME));
        $stem=substr(trim($stem,'-._')?:'attachment',0,80);$suffix=substr(hash('sha256',is_string($id)?$id:$name),0,12);
        return $stem.'-'.$suffix.'.'.$extension;
    }
}
