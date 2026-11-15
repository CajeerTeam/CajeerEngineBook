<?php
declare(strict_types=1);
namespace CajeerBook;

/** Source revisions and generated drafts commit together under BookStore's exclusive lock. */
final class OpenApiStore
{
    public const MAX_SPECS=16, MAX_REVISIONS=5, MAX_BYTES=4*1024*1024;
    public function __construct(private readonly BookStore $store){}
    private static function hash(mixed $v):string{return hash('sha256',OpenApiDocument::json($v));}
    private static function fingerprint(array $p):string{return self::hash([$p['title'],$p['section'],$p['order'],$p['body'],$p['media']??[],$p['link_base']??'']);}
    private static function hashValid(mixed $s):bool{return is_string($s)&&preg_match('/\A[a-f0-9]{64}\z/D',$s)===1;}
    private static function idValid(mixed $s):bool{return is_string($s)&&preg_match('/\A[a-f0-9]{32}\z/D',$s)===1;}
    private static function scope(string $book,string $version,string $slug):string{return BookStore::key($book,$version,'reference/'.$slug);}
    public static function validate(array $db):void{
        $all=$db['openapi']??[];if(!is_array($all)||count($all)>self::MAX_SPECS||($all&&!in_array($db['schema']??null,[4,5,6],true)))throw new \RuntimeException('Invalid OpenAPI store.');
        $bytes=0;$ids=[];
        foreach($all as $scope=>$s){
            if(!is_array($s)||!is_string($s['book']??null)||!is_string($s['version']??null)||!is_string($s['slug']??null)||!BookStore::slug($s['slug'])||self::scope($s['book'],$s['version'],$s['slug'])!==$scope||!self::idValid($s['id']??null)||isset($ids[$s['id']])||!is_int($s['revision']??null)||$s['revision']<1||!is_array($s['pages']??null)||count($s['pages'])>OpenApiReference::MAX_PAGES||!is_array($s['revisions']??null)||!array_is_list($s['revisions'])||!$s['revisions']||count($s['revisions'])>self::MAX_REVISIONS)throw new \RuntimeException('Invalid OpenAPI source record.');
            $ids[$s['id']]=true;$n=$s['revision']-count($s['revisions'])+1;
            foreach($s['revisions'] as $r){
                if(!is_array($r)||($r['revision']??null)!==$n++||!is_string($r['bundle']??null)||strlen($r['bundle'])>2*OpenApiDocument::MAX_BYTES||!self::hashValid($r['sha256']??null)||hash('sha256',$r['bundle'])!==$r['sha256']||!is_string($r['title']??null)||!is_string($r['api_version']??null)||strlen($r['title'])>200||strlen($r['api_version'])>200||!is_string($r['format']??null)||!preg_match('/\A3\.[01]\.\d+\z/D',$r['format'])||!is_string($r['at']??null)||!is_string($r['commit']??null)||($r['commit']!==''&&!preg_match('/\A[a-f0-9]{40}\z/D',$r['commit']))||!is_array($r['actor']??null)||!self::idValid($r['actor']['id']??null)||!is_string($r['actor']['username']??null)||strlen($r['actor']['username'])>64)throw new \RuntimeException('Invalid OpenAPI revision.');
                $bytes+=strlen($r['bundle']);if($bytes>self::MAX_BYTES)throw new \RuntimeException('OpenAPI source quota exceeded.');
                // Validate normalized envelope on read; full structural verification in offline audit/restore.
                try{$b=json_decode($r['bundle'],false,64,JSON_THROW_ON_ERROR);if(!is_object($b)||!is_string($b->root??null)||!is_object($b->files??null)||!property_exists($b->files,$b->root))throw new \RuntimeException('Invalid OpenAPI bundle envelope.');}catch(\JsonException $e){throw new \RuntimeException('Invalid OpenAPI bundle.',0,$e);}
            }
            foreach($s['pages'] as $key=>$p){if(!is_string($key)||!str_starts_with($key,$scope.'/')||!PageTree::validKey($key)||!is_array($p)||!self::idValid($p['id']??null)||isset($ids[$p['id']])||!self::hashValid($p['base']??null)||!is_bool($p['retired']??null))throw new \RuntimeException('Invalid OpenAPI generated page mapping.');$ids[$p['id']]=true;}
        }
    }
    public static function audit(array $db):void{
        self::validate($db);foreach($db['openapi']??[] as $s)foreach($s['revisions'] as $r){$doc=OpenApiDocument::fromBundle($r['bundle']);$model=OpenApiReference::render($doc,$s['book'],$s['version'],$s['slug']);foreach(['title','api_version','format'] as $k)if($model[$k]!==$r[$k])throw new \RuntimeException('OpenAPI revision metadata mismatch.');}
    }
    private static function plan(array $db,OpenApiDocument $doc,string $book,string $version,string $slug):array{
        $scope=self::scope($book,$version,$slug);$old=$db['openapi'][$scope]??null;$model=OpenApiReference::render($doc,$book,$version,$slug);$pages=$model['pages'];$mapped=$old['pages']??[];$rows=[];$conflicts=0;$create=0;
        // Removed operations/models remain at the same stable URL, as a retirement draft, until reviewed/published or explicitly unpublished.
        foreach($mapped as $key=>$m)if(!isset($pages[$key])){
            $entry=$db['pages'][$key]??null;
            $pages[$key]=['title'=>$entry['draft']['title']??'Removed API reference','section'=>'API / Removed','order'=>9999,'body'=>"# Removed from the current specification\n\nThis operation or schema is no longer part of the imported OpenAPI source. The previous publication is unchanged until an authorized publication or unpublish operation.\n",'media'=>[]];
        }
        if(count($pages)>OpenApiReference::MAX_PAGES)throw new \DomainException('Reference exceeds the retained page limit. Use a new API version.');
        foreach($pages as $key=>$p){$entry=$db['pages'][$key]??null;$m=$mapped[$key]??null;$retired=!isset($model['pages'][$key]);$hash=self::fingerprint($p);$action='create';$why='';
            if($m){
                if(!$entry||PageTree::id($key,$entry)!==$m['id']){$action='conflict';$why='Generated page was moved, deleted or replaced.';}
                elseif(self::fingerprint($entry['draft'])===$hash&&($entry['draft']['playground']??null)===($p['playground']??null))$action='unchanged';
                elseif(self::fingerprint($entry['draft'])===$hash)$action='update';
                elseif(self::fingerprint($entry['draft'])!==$m['base']){$action='conflict';$why='Local draft was edited. Restore the generated draft or import into a new version.';}
                else $action=$retired?'removed':'update';
            }elseif($entry||PageTree::reserved($db,$key,'')){$action='conflict';$why='Target path already belongs to another page.';}
            if($action==='conflict')$conflicts++;if($action==='create')$create++;
            $rows[$key]=['key'=>$key,'title'=>$p['title'],'action'=>$action,'reason'=>$why,'retired'=>$retired];
        }
        if(count($db['pages'])+$create>1000)throw new \DomainException('Workspace page limit exceeded.');
        return ['scope'=>$scope,'model'=>$model,'pages'=>$pages,'rows'=>$rows,'conflicts'=>$conflicts];
    }
    private static function token(array $db,string $bundle,string $scope,string $commit,string $secret,int $time):string{
        return $time.'.'.hash_hmac('sha256',OpenApiDocument::json([$time,WorkspaceUrls::prefix(),$scope,hash('sha256',$bundle),$commit,self::hash($db)]),$secret);
    }
    public function preview(OpenApiDocument $doc,string $book,string $version,string $slug,string $secret,string $commit=''):array{
        return $this->store->snapshot(function($db)use($doc,$book,$version,$slug,$secret,$commit){$plan=self::plan($db,$doc,$book,$version,$slug);$bundle=$doc->bundle();$plan['token']=self::token($db,$bundle,$plan['scope'],$commit,$secret,time());$plan['bundle']=$bundle;$plan['commit']=$commit;return $plan;});
    }
    public function apply(string $bundle,string $book,string $version,string $slug,string $secret,string $token,array $actor,string $commit=''):void{
        if(!preg_match('/\A([0-9]{10})\.[a-f0-9]{64}\z/D',$token,$m)||(int)$m[1]<time()-900||(int)$m[1]>time())throw new \DomainException('Preview expired. Preview again.');
        $doc=OpenApiDocument::fromBundle($bundle);$scope=self::scope($book,$version,$slug);
        $this->store->setHistoryActor($actor);
        $this->store->change(function(&$db)use($doc,$bundle,$book,$version,$slug,$scope,$secret,$token,$m,$actor,$commit){
            if(!hash_equals(self::token($db,$bundle,$scope,$commit,$secret,(int)$m[1]),$token))throw new \DomainException('Specification or workspace changed. Preview again.');
            $plan=self::plan($db,$doc,$book,$version,$slug);if($plan['conflicts'])throw new \DomainException('Resolve generated page conflicts before applying.');
            $s=$db['openapi'][$scope]??['id'=>bin2hex(random_bytes(16)),'book'=>$book,'version'=>$version,'slug'=>$slug,'revision'=>0,'pages'=>[],'revisions'=>[]];
            $sha=hash('sha256',$bundle);$last=end($s['revisions']);if($last&&$last['sha256']===$sha&&$last['commit']===$commit&&count(array_filter($plan['rows'],fn($r)=>$r['action']!=='unchanged'))===0)return;
            if(!isset($db['openapi'][$scope])&&count($db['openapi']??[])>=self::MAX_SPECS)throw new \DomainException('Specification limit reached.');
            $books=BookStore::books($db);if(!isset($books[$book])){if(count($books)>=1000)throw new \DomainException('Book limit reached.');$books[$book]=['id'=>bin2hex(random_bytes(16)),'slug'=>$book,'title'=>$book];}$db['books']=$books;
            $sourceAuthors=[];foreach($s['revisions'] as $prior)if($prior['sha256']===$sha)$sourceAuthors[]=$prior['actor']['id'];
            foreach($plan['pages'] as $key=>$p){$e=$db['pages'][$key]??['id'=>bin2hex(random_bytes(16)),'revision'=>0,'published'=>null];$e['id']??=PageTree::id($key,$e);
                if($plan['rows'][$key]['action']!=='unchanged'){$p['updated']=gmdate('c');$e['draft']=$p;$e['revision']++;
                    foreach(['review_authors'=>false,'review_tree_authors'=>true] as $field=>$tree){$authors=$sourceAuthors;foreach(RevisionHistory::entries($db,$e['id']) as $event)if(self::fingerprint($event['draft'])===self::fingerprint($p))$authors=array_merge($authors,RevisionHistory::historicalContributors($db,$event,$tree));$e[$field]=array_values(array_unique(array_merge($e[$field]??[],$authors)));}
                    $db['pages'][$key]=$e;}
                $s['pages'][$key]=['id'=>$e['id'],'base'=>self::fingerprint($p),'retired'=>$plan['rows'][$key]['retired']];
            }
            $s['revision']++;$r=['revision'=>$s['revision'],'bundle'=>$bundle,'sha256'=>$sha,'commit'=>$commit,'at'=>gmdate('c'),'actor'=>['id'=>$actor['id'],'username'=>$actor['username']]];foreach(['title','api_version','format'] as $k)$r[$k]=$plan['model'][$k];$s['revisions'][]=$r;$s['revisions']=array_slice($s['revisions'],-self::MAX_REVISIONS);$db['schema']=max(4,$db['schema']??1);$db['openapi'][$scope]=$s;
            // Evict oldest non-current source revisions first; never discard another specification's current source.
            while(true){$bytes=0;$oldest=null;foreach($db['openapi'] as $k=>$item){foreach($item['revisions'] as $r)$bytes+=strlen($r['bundle']);if(count($item['revisions'])>1&&($oldest===null||$item['revisions'][0]['at']<$db['openapi'][$oldest]['revisions'][0]['at']))$oldest=$k;}if($bytes<=self::MAX_BYTES)break;if($oldest===null)throw new \DomainException('OpenAPI source storage quota exceeded.');array_shift($db['openapi'][$oldest]['revisions']);}
        },'save','OpenAPI reference import');
    }
}
