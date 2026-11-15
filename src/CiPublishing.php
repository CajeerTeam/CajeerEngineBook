<?php
declare(strict_types=1);
namespace CajeerBook;

/** Scoped CI credentials and immutable, whole-version publication bundles. */
final class CiPublishing
{
    public const MAX_BUILDS=20, MAX_TOKENS=20, MAX_BYTES=8*1024*1024;
    private static function hash(mixed $x):string{return hash('sha256',json_encode($x,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private static function hex(mixed $s,int $n=32):bool{return is_string($s)&&preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$s)===1;}
    private static function scope(string $s):string{if(!PageTree::validKey($s.'/index')||substr_count($s,'/')!==1)throw new \InvalidArgumentException('Invalid book/version scope.');return $s;}
    private static function init(array &$db):void{$db['schema']=6;$db['ci']??=['tokens'=>[],'builds'=>[]];}
    private static function actor(array $a):array{return ['id'=>$a['id'],'username'=>$a['username']];}
    private static function permitted(array $users,string $id):bool{return Permissions::allows($users[$id]['role']??'none','manage');}
    public static function issue(array &$db,array $actor,string $scope,string $label,int $days,bool $publish):string{
        self::scope($scope);$label=RevisionHistory::note($label);if($label===''||strlen($label)>100||$days<1||$days>90)throw new \InvalidArgumentException('Label and expiry (1–90 days) required.');self::init($db);
        foreach($db['ci']['tokens'] as $id=>$t)if($t['expires']<=time())unset($db['ci']['tokens'][$id]);
        if(count($db['ci']['tokens'])>=self::MAX_TOKENS)throw new \DomainException('Revoke an unused CI token first.');
        $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));$db['ci']['tokens'][$id]=['id'=>$id,'hash'=>hash('sha256',$secret),'scope'=>$scope,'label'=>$label,'actor'=>self::actor($actor),'expires'=>time()+$days*86400,'publish'=>$publish];
        ActivityJournal::record($db,$actor,'ci.token','CI token created',$id);return 'cbci_'.$id.'.'.$secret;
    }
    public static function revoke(array &$db,array $actor,string $id):void{if(!isset($db['ci']['tokens'][$id]))throw new \InvalidArgumentException('CI token not found.');unset($db['ci']['tokens'][$id]);ActivityJournal::record($db,$actor,'ci.token','CI token revoked',$id);}
    public static function authenticate(array $db,string $credential,array $admins):array{
        if(!preg_match('/\Acbci_([a-f0-9]{32})\.([a-f0-9]{64})\z/D',$credential,$m))throw new OrganizationAccessException('Invalid CI credential.');
        $t=$db['ci']['tokens'][$m[1]]??null;if(!$t||$t['expires']<=time()||!hash_equals($t['hash'],hash('sha256',$m[2]))||!self::permitted($admins,$t['actor']['id']))throw new OrganizationAccessException('Invalid CI credential.');return $t;
    }
    /** Bind all pages in the release scope, including previous public paths and metadata. */
    public static function state(array $db,string $scope):array{
        self::scope($scope);$out=[];foreach($db['pages'] as $key=>$e)if(PageTree::scope($key)===$scope)$out[$key]=ReviewWorkflow::pageState($db,$key);ksort($out,SORT_STRING);return $out;
    }
    public static function fingerprint(array $db,string $scope):string{return self::hash(self::state($db,$scope));}
    /** A Git-labelled release must contain exactly the synchronized remote drafts, not local edits. */
    public static function assertGit(array $db,string $scope,string $commit):string{
        $g=$db['github']??[];if(!self::hex($commit,40)||($g['commit']??null)!==$commit||($g['book']??'').'/'.($g['version']??'')!==$scope)throw new \DomainException('Requested commit has not been synchronized into this book/version.');
        $pages=self::state($db,$scope);$seen=[];$mapping=$g['pages']??[];ksort($mapping,SORT_STRING);
        foreach($mapping as $m){$key=$m['key'];$p=$pages[$key]??null;if(!$p||isset($seen[$key])||$p['page']!==$m['id']||GitHubSync::fingerprint($p['draft'])!==$m['base'])throw new \DomainException('Git release contains unsynchronized local changes.');$seen[$key]=true;}
        if(count($seen)!==count($pages))throw new \DomainException('Git release contains untracked pages; use a draft build instead.');
        return self::hash([$g['id'],$g['repo'],$g['branch'],$g['root'],$commit,$mapping,$g['assets']??[]]);
    }
    private static function gitFresh(array $db,array $b):bool{if($b['commit']==='')return true;try{return hash_equals($b['git_mapping'],self::assertGit($db,$b['scope'],$b['commit']));}catch(\DomainException){return false;}}
    private static function media(array $db,array $pages):array{$out=[];foreach($pages as $p)foreach(array_unique(array_merge($p['draft']['media']??[],$p['published']['media']??[])) as $id){$m=$db['media'][$id]??null;if(!$m)throw new \DomainException('Missing media in build.');$out[$id]=$m['sha256'];}ksort($out);return $out;}
    /** Extract only local documentation/media links, including supported rich component bodies. */
    private static function localLinks(string $body,string $base,int $depth=0):array{
        if($depth>RichBlocks::MAX_DEPTH)throw new \DomainException('Rich block nesting exceeds build limit.');$syntax=Markdown::importSyntax($body);$raw=$syntax['links'];
        foreach($syntax['regions'] as $r){$block=RichBlocks::parse($r['info'],$r['literal']);if(!$block)continue;
            foreach(RichBlocks::bodies($block) as $text)foreach(self::localLinks($text,$base,$depth+1) as $path)$raw[]=['url'=>$path];
            foreach($block['cards']??[] as $card)foreach(['href','image'] as $field)if(isset($card[$field]))$raw[]=['url'=>$card[$field]];
        }
        $out=[];foreach($raw as $link){$url=$link['url'];if($url===''||preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i',$url))continue;
            $path=substr($url,0,strcspn($url,'?#'));if(!str_starts_with($path,'/'))$path='/docs/'.substr($base,0,strrpos($base,'/')+1).$path;
            $path=WorkspaceUrls::normalize($path);if($path==='#')throw new \DomainException('Invalid local link path.');
            if(str_starts_with($path,'/docs/')||str_starts_with($path,'/media/'))$out[$path]=true;
        }$links=array_keys($out);sort($links,SORT_STRING);return $links;
    }
    private static function projection(array $db,array $pages):array{
        foreach($pages as $key=>$p){$db['pages'][$key]['published']=$p['draft'];$db['pages'][$key]['published_key']=$p['published']===null?$key:$p['public_key'];}return $db;
    }
    /** Validate actual rendered Markdown and source media indexes at build time. */
    public static function check(array $db,array $pages,MediaStore $media):array{
        $links=[];$projected=self::projection($db,$pages);$map=PageTree::linkMap($projected);OpenApiStore::audit($db);
        foreach($pages as $key=>$p){$d=$p['draft'];$base=$d['link_base']??$key;$body=$d['body'];Markdown::validate($body);ApiPlayground::validateSnapshot($d);Markdown::render($body,$key,false,true,$base,$map);
            $refs=Markdown::mediaReferences($body,$base);$saved=$d['media']??[];sort($refs);sort($saved);if($refs!==$saved)throw new \DomainException('Media index differs: '.$key);
            $links[$key]=self::localLinks($body,$base);
        }
        self::verify($db,$pages,$links,$media);return $links;
    }
    /** Recheck publication dependencies and binary hashes without reparsing the immutable bundle. */
    public static function verify(array $db,array $pages,array $links,MediaStore $media):void{
        $projected=self::projection($db,$pages);$map=PageTree::linkMap($projected);$public=BookStore::published($projected);$books=BookStore::books($db);
        foreach($links as $key=>$paths)foreach($paths as $path){if(str_starts_with($path,'/docs/')){$target=substr($path,6);$target=$map[$target]??$target;if(!isset($public[$target]))throw new \DomainException('Broken documentation link in '.$key.': '.$target);
            if(Visibility::book($books[explode('/',$key)[0]]??[])!=='private'&&Visibility::book($books[explode('/',$target)[0]]??[])==='private')throw new \DomainException('Public-capable book links to private book: '.$key);
        }elseif(str_starts_with($path,'/media/')){if(!isset($db['media'][substr($path,7)]))throw new \DomainException('Broken media link in '.$key);}}
        foreach(self::media($db,$pages) as $id=>$hash){$m=$db['media'][$id];$path=$media->blob($id);if(!is_file($path)||is_link($path)||filesize($path)!==$m['size']||hash_file('sha256',$path)!==$hash)throw new \DomainException('Missing or corrupt media: '.$id);}
    }
    /** Backup/audit verifies that the saved link report corresponds to the exact source. */
    public static function audit(array $db):void{
        self::validate($db);foreach($db['ci']['builds']??[] as $b)foreach($b['pages'] as $key=>$p){$d=$p['draft'];$base=$d['link_base']??$key;$refs=Markdown::mediaReferences($d['body'],$base);$saved=$d['media'];sort($refs);sort($saved);if($refs!==$saved||self::localLinks($d['body'],$base)!==$b['links'][$key])throw new \RuntimeException('CI build validation report mismatch.');if($p['published']!==null){$old=$p['published'];$refs=Markdown::mediaReferences($old['body'],$old['link_base']??$p['public_key']);$saved=$old['media'];sort($refs);sort($saved);if($refs!==$saved)throw new \RuntimeException('CI previous publication media mismatch.');}}
    }
    private static function digest(array $b):string{return self::hash([$b['id'],$b['request'],$b['created'],$b['scope'],$b['commit'],$b['fingerprint'],$b['policy'],$b['book'],$b['git'],$b['git_mapping'],$b['pages'],$b['media'],$b['links'],$b['authors'],$b['requester']]);}
    public static function get(array $db,string $id):array{if(!self::hex($id)||!isset($db['ci']['builds'][$id]))throw new \InvalidArgumentException('Build not found.');return $db['ci']['builds'][$id];}
    /** Capture exact checked drafts; request key deduplicates retries without changing an existing build. */
    public static function build(array &$db,array $actor,string $scope,string $request,string $commit,MediaStore $media,string $expected):array{
        self::scope($scope);if(!preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/D',$request))throw new \InvalidArgumentException('Invalid idempotency key.');
        self::init($db);foreach($db['ci']['builds'] as $b)if($b['scope']===$scope&&$b['request']===$request){if($b['requester']['id']!==$actor['id']||$b['commit']!==$commit||$b['fingerprint']!==$expected)throw new \DomainException('Idempotency key already used.');return $b;}
        if(!self::hex($expected,64)||!hash_equals(self::fingerprint($db,$scope),$expected))throw new \DomainException('Drafts changed; refresh scope state before building.');
        if($commit!=='')self::assertGit($db,$scope,$commit);
        $pages=self::state($db,$scope);if(!$pages||count($pages)>200)throw new \DomainException('Build requires 1–200 pages.');
        if(strlen(json_encode($pages,JSON_THROW_ON_ERROR))>4*1024*1024)throw new \DomainException('Build snapshot exceeds 4 MiB.');
        $links=self::check($db,$pages,$media);return self::capture($db,$actor,$scope,$request,$commit,$pages,$links);
    }
    /** Called only after the build checks succeed. */
    private static function capture(array &$db,array $actor,string $scope,string $request,string $commit,array $pages,array $links):array{
        $authors=[$actor['id']];foreach($pages as $key=>$p)$authors=array_merge($authors,ReviewWorkflow::authors($db,$actor,'page',$key));$authors=array_values(array_unique($authors));if(count($authors)>500)throw new \DomainException('Build contributor limit exceeded.');sort($authors);
        $id=bin2hex(random_bytes(16));$b=['id'=>$id,'scope'=>$scope,'request'=>$request,'commit'=>$commit,'fingerprint'=>self::hash($pages),'policy'=>ReviewWorkflow::policy($db)['revision'],'book'=>self::bookFingerprint($db,$scope),'git'=>$commit!==''?$db['github']['id']:'','git_mapping'=>$commit!==''?self::assertGit($db,$scope,$commit):'','pages'=>$pages,'media'=>self::media($db,$pages),'links'=>$links,'authors'=>$authors,'requester'=>self::actor($actor),'created'=>time(),'status'=>'ready','reviewer'=>null,'note'=>'','published_by'=>null,'published_at'=>0];$b['digest']=self::digest($b);
        if(count($db['ci']['builds'])>=self::MAX_BUILDS)throw new \DomainException('Build history full. Prune a closed build first.');$db['ci']['builds'][$id]=$b;
        if(strlen(json_encode($db['ci'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES)throw new \DomainException('Build storage full. Prune a closed build.');
        ActivityJournal::record($db,$actor,'ci.build',$scope,$id);return $b;
    }
    private static function bookFingerprint(array $db,string $scope):string{return self::hash(BookStore::books($db)[explode('/',$scope)[0]]??null);}
    public static function fresh(array $db,array $b):bool{return $b['book']===self::bookFingerprint($db,$b['scope'])&& $b['policy']===ReviewWorkflow::policy($db)['revision']&&hash_equals($b['fingerprint'],self::fingerprint($db,$b['scope']))&&$b['media']===self::media($db,$b['pages'])&&self::gitFresh($db,$b);}
    public static function refresh(array &$db):void{foreach($db['ci']['builds']??[] as $id=>$b)if(in_array($b['status'],['ready','approved'],true)&&!self::fresh($db,$b))$db['ci']['builds'][$id]['status']='stale';}
    public static function approve(array &$db,array $actor,array $users,string $id,string $digest,string $note):void{
        $b=self::get($db,$id);if($b['status']!=='ready'||!hash_equals($b['digest'],$digest)||!self::fresh($db,$b))throw new \DomainException('Build changed or is stale.');
        if(!self::permitted($users,$actor['id'])||in_array($actor['id'],$b['authors'],true))throw new OrganizationAccessException('Independent administrator required.');
        $db['ci']['builds'][$id]['status']='approved';$db['ci']['builds'][$id]['reviewer']=self::actor($actor);$db['ci']['builds'][$id]['note']=RevisionHistory::note($note);ActivityJournal::record($db,$actor,'ci.approve',$b['scope'],$id,'','',[$b['requester']['id']]);
    }
    public static function authorizePublish(array $db,array $actor,array $users,string $id,string $digest):array{
        $b=self::get($db,$id);if(!hash_equals($b['digest'],$digest))throw new \DomainException('Build digest mismatch.');if(!self::permitted($users,$actor['id']))throw new OrganizationAccessException('Publication denied.');
        if($b['status']==='published')return $b;
        if(!in_array($b['status'],['ready','approved'],true)||!self::fresh($db,$b))throw new \DomainException('Build is stale or unavailable.');
        if(ReviewWorkflow::required($db)||$b['status']==='approved'){if($b['status']!=='approved'||!self::permitted($users,$b['reviewer']['id']??'')||in_array($b['reviewer']['id'],$b['authors'],true))throw new \DomainException('Independent build approval required.');}
        return $b;
    }
    public static function prune(array &$db,array $actor,string $id):void{$b=self::get($db,$id);if(in_array($b['status'],['ready','approved'],true))throw new \DomainException('Cancel the build before pruning.');unset($db['ci']['builds'][$id]);ActivityJournal::record($db,$actor,'ci.prune',$b['scope'],$id);}
    public static function cancel(array &$db,array $actor,string $id):void{$b=self::get($db,$id);if(!in_array($b['status'],['ready','approved'],true))throw new \DomainException('Build is closed.');$db['ci']['builds'][$id]['status']='cancelled';ActivityJournal::record($db,$actor,'ci.cancel',$b['scope'],$id);}
    /** Restore a previously deployed bundle to drafts, never directly to Reader. */
    public static function rollbackDrafts(array &$db,array $actor,string $id,string $expected):void{
        $b=self::get($db,$id);if($b['status']!=='published'||!hash_equals(self::fingerprint($db,$b['scope']),$expected))throw new \DomainException('Select a published build and refresh current scope.');
        $current=self::state($db,$b['scope']);if(array_keys($current)!==array_keys($b['pages']))throw new \DomainException('Page set changed; restore individual revisions instead.');
        foreach($b['pages'] as $key=>$p){if($current[$key]['page']!==$p['page'])throw new \DomainException('Page identity changed; restore individual revisions instead.');$e=$db['pages'][$key];$e['draft']=$p['draft'];$e['draft']['updated']=gmdate('c');$e['revision']++;$e['review_authors']=array_values(array_unique(array_merge(ReviewWorkflow::contributors($db,$key,$e,false),$b['authors'],[$actor['id']])));$db['pages'][$key]=$e;}
        ActivityJournal::record($db,$actor,'ci.rollback',$b['scope'],$id);
    }
    public static function mediaIndex(array $db):array{$out=[];foreach($db['ci']['builds']??[] as $b)foreach($b['media'] as $id=>$hash)$out[$id][]=$b['scope'].':build';return $out;}
    public static function summary(array $b):array{return array_intersect_key($b,array_flip(['id','scope','commit','digest','status','created','published_at','reviewer','fingerprint']))+['pages'=>count($b['pages'])];}
    public static function validate(array $db):void{
        $ci=$db['ci']??null;if($ci===null){if(($db['schema']??null)===6)throw new \RuntimeException('CI state missing.');return;}
        if(($db['schema']??null)!==6||!is_array($ci)||array_keys($ci)!==['tokens','builds']||!is_array($ci['tokens'])||!is_array($ci['builds'])||count($ci['tokens'])>self::MAX_TOKENS||count($ci['builds'])>self::MAX_BUILDS||strlen(json_encode($ci,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES)throw new \RuntimeException('Invalid CI store.');
        $actor=static fn($a)=>is_array($a)&&self::hex($a['id']??null)&&is_string($a['username']??null)&&preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$a['username']);
        foreach($ci['tokens'] as $id=>$t){if(!self::hex($id)||!is_array($t)||($t['id']??null)!==$id||!self::hex($t['hash']??null,64)||!$actor($t['actor']??null)||!is_string($t['scope']??null)||!is_string($t['label']??null)||$t['label']===''||RevisionHistory::note($t['label'])!==$t['label']||strlen($t['label'])>100||!is_int($t['expires']??null)||$t['expires']<1||!is_bool($t['publish']??null))throw new \RuntimeException('Invalid CI token.');self::scope($t['scope']);}
        $requests=[];foreach($ci['builds'] as $id=>$b){if(!self::hex($id)||!is_array($b)||($b['id']??null)!==$id||!is_string($b['scope']??null)||!is_string($b['request']??null)||!preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/D',$b['request'])||!is_string($b['commit']??null)||($b['commit']!==''&&!self::hex($b['commit'],40))||!self::hex($b['digest']??null,64)||!self::hex($b['fingerprint']??null,64)||!is_int($b['policy']??null)||$b['policy']<1||!self::hex($b['book']??null,64)||!is_string($b['git']??null)||($b['commit']!==''&&!self::hex($b['git']))||($b['commit']===''&&$b['git']!=='')||!is_string($b['git_mapping']??null)||($b['commit']!==''&&!self::hex($b['git_mapping'],64))||($b['commit']===''&&$b['git_mapping']!=='')||!is_array($b['pages']??null)||!$b['pages']||count($b['pages'])>200||!is_array($b['media']??null)||!is_array($b['links']??null)||array_keys($b['links'])!==array_keys($b['pages'])||!is_array($b['authors']??null)||!array_is_list($b['authors'])||count($b['authors'])>500||!$actor($b['requester']??null)||!is_int($b['created']??null)||$b['created']<1||!in_array($b['status']??null,['ready','approved','stale','cancelled','published'],true)||!is_string($b['note']??null)||RevisionHistory::note($b['note'])!==$b['note']||!is_int($b['published_at']??null))throw new \RuntimeException('Invalid CI build.');self::scope($b['scope']);$requestKey=$b['scope'].'/'.$b['request'];if(isset($requests[$requestKey]))throw new \RuntimeException('Duplicate CI request.');$requests[$requestKey]=true;
            foreach($b['authors'] as $uid)if(!self::hex($uid))throw new \RuntimeException('Invalid CI authors.');if(count(array_unique($b['authors']))!==count($b['authors'])||!in_array($b['requester']['id'],$b['authors'],true))throw new \RuntimeException('Invalid CI requester.');
            if(($b['reviewer']??null)!==null&&(!$actor($b['reviewer'])||in_array($b['reviewer']['id'],$b['authors'],true)))throw new \RuntimeException('Invalid CI reviewer.');if($b['status']==='approved'&&!$b['reviewer'])throw new \RuntimeException('Missing CI approval.');if(($b['status']==='published'&&(!$actor($b['published_by']??null)||$b['published_at']<$b['created']))||($b['status']!=='published'&&(($b['published_by']??null)!==null||$b['published_at']!==0)))throw new \RuntimeException('Invalid CI publication.');
            foreach($b['pages'] as $key=>$p){if(!PageTree::validKey($key)||PageTree::scope($key)!==$b['scope']||($p['key']??null)!==$key||!self::hex($p['page']??null)||!is_int($p['revision']??null)||$p['revision']<1||!is_string($p['public_key']??null)||!PageTree::validKey($p['public_key'])||PageTree::scope($p['public_key'])!==$b['scope']||!is_array($p['draft']??null)||!array_key_exists('published',$p))throw new \RuntimeException('Invalid CI snapshot.');foreach(['draft','published'] as $state){$s=$p[$state];if($state==='published'&&$s===null)continue;if(!is_array($s)||!is_string($s['body']??null)||!is_string($s['title']??null)||!is_string($s['section']??null)||trim($s['title'])===''||strlen($s['title'])>200||strlen($s['section'])>100||!is_int($s['order']??null)||!is_string($s['updated']??null)||!is_array($s['media']??null)||!array_is_list($s['media']))throw new \RuntimeException('Invalid CI page.');Markdown::validate($s['body']);foreach($s['media'] as $mid)if(!self::hex($mid)||!isset($db['media'][$mid]))throw new \RuntimeException('Invalid CI media reference.');if(isset($s['link_base'])&&(!is_string($s['link_base'])||!PageTree::validKey($s['link_base'])||PageTree::scope($s['link_base'])!==$b['scope']))throw new \RuntimeException('Invalid CI link base.');ApiPlayground::validateSnapshot($s);}}
            foreach($b['links'] as $paths){if(!is_array($paths)||!array_is_list($paths)||count($paths)>10000)throw new \RuntimeException('Invalid CI link report.');foreach($paths as $path)if(!is_string($path)||strlen($path)>2048||(!str_starts_with($path,'/docs/')&&!str_starts_with($path,'/media/')))throw new \RuntimeException('Invalid CI link report path.');}
            if(self::hash($b['pages'])!==$b['fingerprint']||self::digest($b)!==$b['digest']||self::media($db,$b['pages'])!==$b['media'])throw new \RuntimeException('CI bundle integrity mismatch.');
        }
    }
}
