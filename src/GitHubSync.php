<?php
declare(strict_types=1);
namespace CajeerBook;

/** Workspace-owned integration. Cursor and draft changes commit together. */
final class GitHubSync
{
    public function __construct(private readonly BookStore $store,private readonly MarkdownImport $importer,private readonly ?\Closure $clientFactory=null,private readonly ?string $cacheDir=null){}
    private static function key():string{
        $key=getenv('BOOK_GITHUB_KEY')?:'';if(!preg_match('/\A[a-fA-F0-9]{64}\z/D',$key))throw new \DomainException('Set BOOK_GITHUB_KEY to a private 32-byte hex key on the server before storing a GitHub token.');return hex2bin($key);
    }
    private static function aad(array $c):string{return 'cajeerbook-github-v1:'.$c['id'].':'.$c['repo'];}
    private static function encrypt(string $token,array $c):string{
        if($token==='')return '';if(!preg_match('/\A[A-Za-z0-9_-]{10,512}\z/D',$token))throw new \InvalidArgumentException('Invalid GitHub token format.');
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($token,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,self::aad($c));if($cipher===false)throw new \RuntimeException('Credential encryption failed.');return base64_encode($iv.$tag.$cipher);
    }
    private static function decrypt(array $c):string{
        if($c['credential']==='')return '';$raw=base64_decode($c['credential'],true);$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),self::aad($c));if($plain===false)throw new \DomainException('GitHub credential cannot be decrypted. Restore the master key or rotate the token.');return $plain;
    }
    private function client(array $c):GitHubClient{$token=self::decrypt($c);return $this->clientFactory===null?new GitHubClient($token):($this->clientFactory)($token);}
    public static function validate(array $db):void{
        if(!array_key_exists('github',$db))return;$c=$db['github'];
        if(!is_array($c)||!is_string($c['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$c['id'])||!is_int($c['revision']??null)||$c['revision']<1||!is_string($c['credential']??null)||strlen($c['credential'])>800||!is_bool($c['push']??null)||!is_array($c['pages']??null)||count($c['pages'])>200||!is_array($c['assets']??null)||count($c['assets'])>200||!is_array($c['runs']??null)||count($c['runs'])>30)throw new \RuntimeException('Invalid GitHub integration record.');
        try{GitHubClient::repository($c['repo']);GitHubClient::branch($c['branch']);BookStore::key($c['book'],$c['version'],'index');if($c['root']!=='')ImportFiles::path($c['root']);if($c['commit']!=='')GitHubClient::sha($c['commit']);if($c['credential']!==''){$bytes=base64_decode($c['credential'],true);if($bytes===false||strlen($bytes)<38||strlen($bytes)>540)throw new \InvalidArgumentException('Invalid credential envelope');}
            $ids=[];foreach($c['pages'] as $path=>$p){ImportFiles::path((string)$path);if(!preg_match('/\.(md|markdown)$/i',(string)$path)||!is_array($p)||!is_string($p['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$p['id'])||isset($ids[$p['id']])||!is_string($p['key']??null)||!PageTree::validKey($p['key'])||!str_starts_with($p['key'],$c['book'].'/'.$c['version'].'/')||!self::hashValid($p['base']??null))throw new \InvalidArgumentException('Invalid tracked page');$ids[$p['id']]=true;GitHubClient::sha($p['sha']??null);}
            foreach($c['assets'] as $path=>$sha){ImportFiles::path((string)$path);if(!self::hashValid($sha))throw new \InvalidArgumentException('Invalid asset hash');}
            foreach($c['runs'] as $r)if(!is_array($r)||!is_string($r['at']??null)||!is_string($r['actor']??null)||strlen($r['actor'])>100||!in_array($r['type']??null,['sync','proposal','error'],true)||!is_string($r['detail']??null)||strlen($r['detail'])>500)throw new \InvalidArgumentException('Invalid sync run');
        }catch(\Throwable $e){throw new \RuntimeException('Invalid GitHub integration state.',0,$e);}
    }
    private static function hashValid(mixed $s):bool{return is_string($s)&&preg_match('/\A[a-f0-9]{64}\z/D',$s)===1;}
    public function configure(array $input):void{
        if(isset($this->store->read()['github']))throw new \DomainException('Disconnect the existing integration first.');$repo=GitHubClient::repository(trim($input['repo']));$branch=GitHubClient::branch(trim($input['branch']));$root=trim($input['root'],'/');if($root!=='')ImportFiles::path($root);$book=$input['book'];$version=$input['version'];BookStore::key($book,$version,'index');
        $c=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'repo'=>$repo,'branch'=>$branch,'root'=>$root,'book'=>$book,'version'=>$version,'push'=>$input['push']==='1','credential'=>'','commit'=>'','pages'=>[],'assets'=>[],'runs'=>[]];$c['credential']=self::encrypt($input['token'],$c);
        if($c['push']&&$c['credential']==='')throw new \InvalidArgumentException('A token is required for Pull Requests.');
        // Verify the configured branch before persisting credentials.
        $this->client($c)->head($repo,$branch);
        $this->store->change(function(&$db)use($c){if(isset($db['github']))throw new \DomainException('Disconnect the existing integration first.');$db['github']=$c;});
    }
    private static function current(array $db):array{if(!isset($db['github']))throw new \DomainException('Connect a GitHub repository first.');return $db['github'];}
    public function rotate(string $token,int $expected,bool $push):void{
        $c=self::current($this->store->read());if($c['revision']!==$expected)throw new \DomainException('Integration changed. Reload.');$c['credential']=self::encrypt($token,$c);$c['push']=$push;if($push&&$token==='')throw new \InvalidArgumentException('A token is required for Pull Requests.');$this->client($c)->head($c['repo'],$c['branch']);
        $this->store->change(function(&$db)use($c,$expected){if(self::current($db)['revision']!==$expected||$db['github']['id']!==$c['id'])throw new \DomainException('Integration changed. Reload.');$c['revision']++;$db['github']=$c;});
    }
    public function disconnect(int $expected):void{$this->store->change(function(&$db)use($expected){if(self::current($db)['revision']!==$expected)throw new \DomainException('Integration changed. Reload.');unset($db['github']);});}
    public static function fingerprint(array $doc):string{
        // Ignore updated timestamp, but retain link context and sorted media references.
        $media=$doc['media']??[];sort($media,SORT_STRING);
        return hash('sha256',json_encode([$doc['title'],$doc['section'],$doc['order'],$doc['body'],$media,$doc['link_base']??''],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    private static function byId(array $db,string $id):?string{foreach($db['pages'] as $key=>$p)if(PageTree::id($key,$p)===$id)return $key;return null;}
    /** Three-way comparison of normalized remote draft, last imported base and current local draft. */
    public static function classify(array $db,array $c,array $plan,array $entries):array{
        $rows=[];$tracked=$c['pages'];$conflicts=0;$blocked=0;
        foreach($plan['docs'] as $path=>$d){$key=$d['key'];$entry=$db['pages'][$key]??null;$old=$tracked[$path]??null;$remote=self::fingerprint($d['doc']);$local=$entry?self::fingerprint($entry['draft']):'';$state='create';
            if($old!==null){$actual=self::byId($db,$old['id']);if($actual!==$key||$old['key']!==$key){$state='blocked';$blocked++;}
                elseif($local===$remote)$state='unchanged';
                elseif($remote===$old['base'])$state='local';
                elseif($local===$old['base'])$state='update';
                else{$state='conflict';$conflicts++;}
            }elseif($entry){if($local===$remote)$state='unchanged';else{$state='conflict';$conflicts++;}}
            $rows[$path]=['source'=>$path,'target'=>$key,'state'=>$state,'remote'=>$remote,'sha'=>$entries[$path]['sha'],'local'=>$local];
        }
        // Only unambiguous unchanged-source leaf renames are applied automatically. Never
        // guess when a local draft moved/changed or multiple identical files exist.
        $removed=[];$added=[];$moves=[];$matched=[];
        foreach($tracked as $path=>$old)if(!isset($plan['docs'][$path]))$removed[$old['sha']][]=$path;
        foreach($plan['docs'] as $path=>$d)if(!isset($tracked[$path]))$added[$entries[$path]['sha']][]=$path;
        foreach($added as $hash=>$paths){if(count($paths)!==1||count($removed[$hash]??[])!==1)continue;$path=$paths[0];$from=$removed[$hash][0];$old=$tracked[$from];$dest=$rows[$path]['target'];$actual=self::byId($db,$old['id']);
            if($actual!==$old['key']||self::fingerprint($db['pages'][$actual]['draft'])!==$old['base'])continue;
            if($dest!==$actual){if(isset($db['pages'][$dest])||PageTree::reserved($db,$dest,$old['id']))continue;foreach(array_keys($db['pages']) as $key)if(str_starts_with($key,$actual.'/'))continue 2;}
            if($rows[$path]['state']==='conflict')$conflicts--;elseif(!in_array($rows[$path]['state'],['create','unchanged'],true))continue;
            $rows[$path]['state']='rename';$rows[$path]['from']=$from;$matched[$from]=true;if($dest!==$actual)$moves[$dest]=$actual;
        }
        foreach($tracked as $path=>$old)if(!isset($plan['docs'][$path])&&!isset($matched[$path])){$rows[$path]=['source'=>$path,'target'=>$old['key'],'state'=>'removed','remote'=>'','sha'=>'','local'=>''];$conflicts++;}
        return ['rows'=>$rows,'conflicts'=>$conflicts,'blocked'=>$blocked,'moves'=>$moves];
    }
    private function plan(array $db,array $c,array $remote):array{
        $files=$remote['files']->files();
        if(!array_filter(array_keys($files),fn($path)=>preg_match('/\.(md|markdown)$/i',$path))){if(!$c['pages'])throw new \DomainException('No Markdown documents found in the selected directory.');$plan=['docs'=>[],'assets'=>[],'rows'=>[],'warnings'=>[],'hashes'=>[],'media_new'=>0,'media_total'=>0];}
        else $plan=$this->importer->plan($db,$files,$c['book'],$c['version'],'update');return array_replace($plan,self::classify($db,$c,$plan,$remote['entries']));
    }
    private static function seal(array $db,array $c,string $sha,string $secret,int $time):string{
        return $time.'.'.hash_hmac('sha256',json_encode([$time,WorkspaceUrls::prefix(),$c['id'],$sha,hash('sha256',json_encode($db,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR),$secret);
    }
    private static function verifyPreview(array $db,array $c,string $secret,string $token,string $sha,string $resolution):int{
        if(!in_array($resolution,['safe','remote','local','manual'],true)||!preg_match('/\A([0-9]{10})\.[a-f0-9]{64}\z/D',$token,$m)||(int)$m[1]<time()-900||(int)$m[1]>time())throw new \DomainException('Preview expired or invalid. Preview again.');GitHubClient::sha($sha);
        if(!hash_equals(self::seal($db,$c,$sha,$secret,(int)$m[1]),$token))throw new \DomainException('Workspace or integration changed. Preview again.');return (int)$m[1];
    }
    /** Network-only phase. The HTTP controller captures the authorized snapshot and releases identity lock. */
    public function download(array $db,string $secret,?string $token=null,string $sha='',string $resolution='safe'):array{
        $c=self::current($db);if($token!==null)self::verifyPreview($db,$c,$secret,$token,$sha,$resolution);
        $client=$this->client($c);$head=$client->head($c['repo'],$c['branch']);if($token!==null&&$head!==$sha)throw new \DomainException('GitHub branch changed. Preview again.');
        $remote=$client->fetch($c['repo'],$head,$c['root'],$this->cacheDir);return ['id'=>$c['id'],'revision'=>$c['revision'],'commit'=>$head,'remote'=>$remote];
    }
    private static function checkDownload(array $c,array $prepared):void{if($prepared['id']!==$c['id']||$prepared['revision']!==$c['revision'])throw new \DomainException('Integration changed during download. Preview again.');}
    public function preview(string $secret,?array $prepared=null):array{
        $db=$this->store->read();$c=self::current($db);$prepared??=$this->download($db,$secret);self::checkDownload($c,$prepared);$sha=$prepared['commit'];$remote=$prepared['remote'];$p=$this->plan($db,$c,$remote);
        $diffs=[];foreach($p['rows'] as $path=>$r)if(in_array($r['state'],['conflict','update','local'],true)&&count($diffs)<20){$old=$db['pages'][$r['target']]['draft']['body']??'';$new=$p['docs'][$path]['doc']['body'];$diffs[$path]=RevisionHistory::diff($old,$new);}
        return ['commit'=>$sha,'token'=>self::seal($db,$c,$sha,$secret,time()),'rows'=>$p['rows'],'conflicts'=>$p['conflicts'],'blocked'=>$p['blocked'],'warnings'=>$p['warnings'],'ignored'=>$remote['ignored'],'downloaded'=>$remote['downloaded'],'cached'=>$remote['cached'],'author'=>$remote['author'],'diffs'=>$diffs];
    }
    public function apply(string $secret,string $token,string $sha,string $resolution,string $actor,?array $prepared=null,array $decisions=[]):array{
        $db=$this->store->read();$c=self::current($db);$time=self::verifyPreview($db,$c,$secret,$token,$sha,$resolution);
        $prepared??=$this->download($db,$secret,$token,$sha,$resolution);self::checkDownload($c,$prepared);if($prepared['commit']!==$sha)throw new \DomainException('GitHub branch changed. Preview again.');$remote=$prepared['remote'];
        $report=[];
        $this->importer->commit($c['book'],function($db)use($c,$remote,$sha,$token,$secret,$time,$resolution,$decisions,&$report){
            if(!hash_equals(self::seal($db,self::current($db),$sha,$secret,$time),$token))throw new \DomainException('Workspace changed. Preview again.');$p=$this->plan($db,$c,$remote);
            if($p['blocked'])throw new \DomainException('Tracked page was moved or deleted, or its source slug changed. Move it back or disconnect and reconnect to resolve explicitly.');
            if($p['conflicts']&&$resolution==='safe')throw new \DomainException('Conflicts or removed files require an explicit resolution.');
            if($resolution==='manual')foreach($p['rows'] as $path=>$row)if(in_array($row['state'],['conflict','removed'],true)){
                $choice=$decisions[hash('sha256',(string)$path)]??'';if(!in_array($choice,$row['state']==='removed'?['detach']:['remote','local'],true))throw new \DomainException('Choose a resolution for every conflicting or removed source.');
            }
            foreach($p['docs'] as $path=>&$d){$state=$p['rows'][$path]['state'];$choice=$resolution==='manual'?($decisions[hash('sha256',(string)$path)]??''):$resolution;$d['action']=match($state){'create'=>'create','update','rename'=>'update','conflict'=>$choice==='remote'?'update':'skip',default=>'skip'};}unset($d);$report=$p['rows'];return $p;
        },function(&$db,$p)use($c,$sha,$actor,$resolution,$remote){
            $next=$c;$next['commit']=$sha;$next['revision']++;$next['pages']=[];$next['assets']=[];
            foreach($p['docs'] as $path=>$d){$row=$p['rows'][$path];$key=$d['key'];$entry=$db['pages'][$key];$next['pages'][$path]=['key'=>$key,'id'=>PageTree::id($key,$entry),'sha'=>$row['sha'],'base'=>$row['remote']];}
            foreach($p['hashes'] as $path=>$hash)if(!isset($p['docs'][$path]))$next['assets'][$path]=$hash;
            self::log($next,'sync',$actor,$sha.($remote['author']!==''?' · Git author: '.$remote['author']:'').' · '.count($p['docs']).' files · '.$resolution.' · '.count(array_filter($p['rows'],fn($r)=>$r['state']==='removed')).' removed sources retained locally');$db['github']=$next;
        },'GitHub sync '.$sha);return $report;
    }
    /** Persist only safe provider diagnostics after the controller reauthorizes the original connection. */
    public function failure(string $id,int $revision,string $actor,string $operation,string $message):void{
        $this->store->change(function(&$db)use($id,$revision,$actor,$operation,$message){$c=$db['github']??null;if(!$c||$c['id']!==$id||$c['revision']!==$revision)return;
            if(!in_array($operation,['preview','apply','propose','rotate'],true)||strlen($message)>400||!preg_match('//u',$message)||preg_match('/[\x00-\x1f\x7f]/',$message))$message='GitHub operation failed.';
            self::log($c,'error',$actor,$operation.' · '.$message);$c['revision']++;$db['github']=$c;
        });
    }
    private static function log(array &$c,string $type,string $actor,string $detail):void{$c['runs'][]=['at'=>gmdate('c'),'type'=>$type,'actor'=>$actor,'detail'=>$detail];$c['runs']=array_slice($c['runs'],-30);}
    private static function relative(string $source,string $target):string{
        $from=explode('/',dirname($source));if($from===['.'])$from=[];$to=explode('/',$target);while($from&&$to&&$from[0]===$to[0]){array_shift($from);array_shift($to);}return str_repeat('../',count($from)).implode('/',array_map('rawurlencode',$to));
    }
    /** Reverse canonical documentation/media links into repository-relative Markdown. */
    public static function exportBody(array $db,array $c,string $source,array $draft,string $key):string{
        $map=[];foreach($c['pages'] as $path=>$p)$map['/docs/'.$p['key']]=self::relative($source,$path);
        foreach($c['assets'] as $path=>$sha)foreach($db['media']??[] as $id=>$item)if($item['sha256']===$sha)$map['/media/'.$id]??=self::relative($source,$path);
        foreach($draft['media']??[] as $id)if(!isset($map['/media/'.$id]))throw new \DomainException('A local attachment is not tracked in GitHub. Add it to the repository and sync before proposing this page.');
        $warnings=[];$body=MarkdownImport::rewrite($draft['body'],$source,$map,[],$warnings);$leaf=basename($key);$front="---\n";foreach(['title'=>$draft['title'],'section'=>$draft['section'],'slug'=>$leaf,'order'=>(string)$draft['order']] as $k=>$v){if(preg_match('/[\x00-\x1f\x7f]/',$v))throw new \DomainException('Document metadata cannot be exported safely.');$front.=$k.': '.json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
        // README/index landing pages must not gain a redundant slug on reimport.
        $natural=MarkdownImport::document('', $source, 10)['path'];if($key===$c['book'].'/'.$c['version'].'/'.$natural)$front=preg_replace('/^slug:.*\n/m','',$front);
        return $front."---\n".$body;
    }
    public static function stateHash(array $db):string{return hash('sha256',json_encode($db,JSON_THROW_ON_ERROR));}
    public function propose(int $expected,string $actor,bool $includeNew=false,?string $expectedState=null):string{
        $db=$this->store->read();$c=self::current($db);if($expectedState!==null&&!hash_equals(self::stateHash($db),$expectedState))throw new \DomainException('Workspace changed. Reload the proposal list.');if($c['revision']!==$expected||!$c['push']||$c['commit']==='')throw new \DomainException('Enable Pull Requests and synchronize first.');$changes=[];$export=$c;$tracked=[];
        foreach($c['pages'] as $path=>$p){$key=self::byId($db,$p['id']);if($key!==$p['key'])throw new \DomainException('Tracked page moved or deleted. Resolve before proposing changes.');$tracked[$key]=true;}
        if($includeNew)foreach($db['pages'] as $key=>$e){if(PageTree::scope($key)!==$c['book'].'/'.$c['version']||isset($tracked[$key]))continue;$leaf=explode('/',$key,3)[2];$path=$leaf==='index'?'README.md':$leaf.'.md';ImportFiles::path($path);if(isset($export['pages'][$path])||isset($c['assets'][$path]))throw new \DomainException('New local page collides with a tracked repository path: '.$path);$export['pages'][$path]=['key'=>$key,'id'=>PageTree::id($key,$e),'base'=>''];}
        if(count($export['pages'])>ImportFiles::MAX_FILES)throw new \DomainException('Proposal exceeds source file limit.');
        foreach($export['pages'] as $path=>$p){$key=$p['key'];$d=$db['pages'][$key]['draft'];if(self::fingerprint($d)===$p['base'])continue;$body=self::exportBody($db,$export,$path,$d,$key);if(strlen($body)>Markdown::MAX_BYTES)throw new \DomainException('Exported Markdown exceeds page limit.');$changes[($c['root']===''?'':$c['root'].'/').$path]=$body;}
        // No external write if a concurrent local mutation changed the snapshot before the proposal.
        $this->store->snapshot(function($current)use($db){if($current!==$db)throw new \DomainException('Workspace changed. Reload before proposing.');});
        try{$url=$this->client($c)->propose($c['repo'],$c['branch'],$c['commit'],$changes);}catch(GitHubRemoteException $e){$this->failure($c['id'],$c['revision'],$actor,'propose',$e->getMessage());throw $e;}
        $this->store->change(function(&$db)use($c,$actor,$url){$next=self::current($db);if($next['id']!==$c['id']||$next['revision']!==$c['revision'])throw new \DomainException('Pull request created, but integration changed before recording it. Check GitHub.');self::log($next,'proposal',$actor,$url);$next['revision']++;$db['github']=$next;});return $url;
    }
}
