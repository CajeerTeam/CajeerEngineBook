<?php
declare(strict_types=1);
namespace CajeerBook;

/** Path hierarchy, stable identities and transactional draft navigation. */
final class PageTree
{
    public const MAX_DEPTH=32;
    public static function depth(string $key): int { return substr_count($key,'/')-1; }
    public static function id(string $key,array $entry): string
    { return $entry['id'] ?? substr(hash('sha256','cajeerbook-page:'.$key),0,32); }
    public static function publicKey(string $key,array $entry): string
    { return $entry['published_key'] ?? $key; }
    public static function scope(string $key): string
    { return implode('/',array_slice(explode('/',$key),0,2)); }
    private static function parent(string $path): string
    { return substr($path,0,strrpos($path,'/')); }
    private static function below(string $key,string $root): bool
    { return $key===$root || str_starts_with($key,$root.'/'); }
    public static function validKey(string $key): bool
    { $p=explode('/',$key,3); try {return count($p)===3 && BookStore::key(...$p)===$key;}catch(\InvalidArgumentException){return false;} }
    public static function token(array $db): string
    {
        $revisions=[];foreach($db['pages'] as $key=>$e)$revisions[$key]=[self::id($key,$e),$e['revision']];
        ksort($revisions);return hash('sha256',json_encode($revisions,JSON_THROW_ON_ERROR));
    }
    /** Materialize implicit folder nodes; actual pages may also have children. */
    public static function nodes(array $pages): array
    {
        $nodes=[];
        foreach($pages as $key=>$p){
            $scope=self::scope($key);$cursor=$key;
            while($cursor!==$scope){
                $nodes[$cursor]??=['key'=>$cursor,'title'=>basename($cursor),'order'=>$p['order'],'page'=>false,'children'=>[]];
                if(!$nodes[$cursor]['page'])$nodes[$cursor]['order']=min($nodes[$cursor]['order'],$p['order']);
                $cursor=self::parent($cursor);
            }
            $nodes[$key]['title']=$p['title'];$nodes[$key]['order']=$p['order'];$nodes[$key]['page']=true;
        }
        foreach($nodes as $key=>$n){$parent=self::parent($key);if(isset($nodes[$parent]))$nodes[$parent]['children'][]=$key;}
        foreach($nodes as &$n)usort($n['children'],fn($a,$b)=>[$nodes[$a]['order'],$a]<=>[$nodes[$b]['order'],$b]);unset($n);
        return $nodes;
    }
    public static function roots(array $nodes,string $scope): array
    { $roots=array_keys(array_filter($nodes,fn($n)=>self::parent($n['key'])===$scope));usort($roots,fn($a,$b)=>[$nodes[$a]['order'],$a]<=>[$nodes[$b]['order'],$b]);return $roots; }
    public static function ordered(array $pages): array
    {
        $nodes=self::nodes($pages);$scopes=[];foreach($pages as $k=>$p)$scopes[self::scope($k)]=true;ksort($scopes);$out=[];
        $visit=function($key)use(&$visit,&$out,$pages,$nodes){if(isset($pages[$key]))$out[$key]=$pages[$key];foreach($nodes[$key]['children'] as $child)$visit($child);};
        foreach(array_keys($scopes) as $scope)foreach(self::roots($nodes,$scope) as $root)$visit($root);
        return $out;
    }
    public static function drafts(array $db): array
    { $out=[];foreach($db['pages'] as $key=>$e)$out[$key]=$e['draft'];return $out; }
    public static function reserved(array $db,string $key,string $id): bool
    {
        if(isset($db['redirects'][$key]) && $db['redirects'][$key]!==$id)return true;
        if(isset($db['retired_paths'][$key]) && $db['retired_paths'][$key]!==$id)return true;
        foreach($db['pages'] as $k=>$e)if(($e['published']!==null||isset($e['published_key']))&&self::publicKey($k,$e)===$key&&self::id($k,$e)!==$id)return true;
        return false;
    }
    public static function redirect(array $db,string $key): ?string
    {
        $id=$db['redirects'][$key]??null;if($id===null)return null;
        foreach($db['pages'] as $k=>$e)if($e['published']!==null&&self::id($k,$e)===$id){$target=self::publicKey($k,$e);return $target!==$key?$target:null;}
        return null;
    }
    /** Resolve references to old locations, including moves made before first publication.
     * Private historical paths are never exposed as public redirects. */
    public static function linkMap(array $db): array
    {
        $byId=[];$map=[];
        foreach($db['pages'] as $key=>$e)if($e['published']!==null){$public=self::publicKey($key,$e);$byId[self::id($key,$e)]=$public;$map[$key]=$public;$map[$public]=$public;}
        foreach(['retired_paths','redirects'] as $field)foreach($db[$field]??[] as $key=>$id)if(isset($byId[$id]))$map[$key]=$byId[$id];
        return $map;
    }
    public static function rewriteLink(string $url,array $map): string
    {
        if(!str_starts_with($url,'/docs/')||!$map)return $url;
        $length=strcspn($url,'?#');$path=substr($url,0,$length);$parts=[];
        foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
        if(($parts[0]??null)!=='docs')return $url;
        $key=implode('/',array_slice($parts,1));
        if(!isset($map[$key])||$map[$key]===$key)return $url;
        return '/docs/'.$map[$key].substr($url,$length);
    }
    public static function draftRedirect(array $db,string $key): ?string
    {
        $id=$db['retired_paths'][$key]??$db['redirects'][$key]??null;if($id===null)return null;
        foreach($db['pages'] as $k=>$e)if(self::id($k,$e)===$id)return $k!==$key?$k:null;
        return null;
    }
    public static function retire(array &$db,string $key,string $id): void
    {
        if(!isset($db['retired_paths'][$key])&&count($db['retired_paths']??[])>=10000)throw new \DomainException('Retired path limit reached');
        if(isset($db['retired_paths'][$key])&&$db['retired_paths'][$key]!==$id)throw new \DomainException('Path reserved by another page');
        $db['retired_paths'][$key]=$id;
    }
    public static function remember(array &$db,string $key,string $id): void
    {
        if(!isset($db['redirects'][$key])&&count($db['redirects']??[])>=10000)throw new \DomainException('Redirect limit reached');
        if(isset($db['redirects'][$key])&&$db['redirects'][$key]!==$id)throw new \DomainException('Redirect reserved by another page');
        $db['redirects'][$key]=$id;
    }
    private static function verify(array $db,string $token): void
    { if(!hash_equals(self::token($db),$token))throw new \DomainException('Page tree changed. Reload before changing structure.'); }
    /** Move or copy an entire branch, preserving relative descendant paths and content. */
    public static function move(array &$db,string $source,string $target,string $position,string $slug,string $token,bool $duplicate=false): string
    {
        self::verify($db,$token);$scope=self::scope($source);$pages=self::drafts($db);$nodes=self::nodes($pages);
        if(!isset($nodes[$source])||!in_array($position,['before','after','inside','root'],true))throw new \InvalidArgumentException('Invalid source or position');
        if($position!=='root'&&(!isset($nodes[$target])||self::scope($target)!==$scope||self::below($target,$source)))throw new \InvalidArgumentException('Invalid target or cyclic move');
        $parent=match($position){'root'=>$scope,'inside'=>$target,default=>self::parent($target)};
        $slug=$slug===''?basename($source):$slug;if(!BookStore::slug($slug))throw new \InvalidArgumentException('Invalid slug');
        $dest=$parent.'/'.$slug;
        if(($duplicate||$dest!==$source)&&isset($nodes[$dest]))throw new \DomainException('Destination branch already exists');
        $mapping=[];foreach($db['pages'] as $key=>$e)if(self::below($key,$source)){
            $new=$dest.substr($key,strlen($source));if(!self::validKey($new)||($new!==$key&&self::depth($new)>self::MAX_DEPTH))throw new \InvalidArgumentException('Destination path is too long, too deep or invalid');
            $id=$duplicate?bin2hex(random_bytes(16)):self::id($key,$e);
            if(self::reserved($db,$new,$id))throw new \DomainException('Destination is reserved by a published page or redirect');
            $mapping[$key]=[$new,$id];
        }
        if($duplicate&&count($db['pages'])+count($mapping)>1000)throw new \DomainException('Page limit reached');
        // Build the ordered sibling lists from the original tree, then splice the branch.
        $children=[$scope=>self::roots($nodes,$scope)];foreach($nodes as $k=>$n)if(self::scope($k)===$scope)$children[$k]=$n['children'];
        if(!$duplicate){$oldParent=self::parent($source);$children[$oldParent]=array_values(array_filter($children[$oldParent],fn($k)=>$k!==$source));}
        $branch=[];foreach($children as $k=>$list)if(self::below($k,$source))$branch[$dest.substr($k,strlen($source))]=array_map(fn($v)=>$dest.substr($v,strlen($source)),$list);
        if(!$duplicate)foreach(array_keys($children) as $k)if(self::below($k,$source))unset($children[$k]);
        foreach($branch as $k=>$list)$children[$k]=$list;
        $siblings=$children[$parent]??[];$index=count($siblings);
        if($position==='before'||$position==='after'){$index=array_search($target,$siblings,true);if($index===false)throw new \DomainException('Target changed');if($position==='after')$index++;}
        array_splice($siblings,$index,0,[$dest]);$children[$parent]=$siblings;
        $moved=[];foreach($mapping as $old=>[$new,$id]){
            $e=$db['pages'][$old];$e['id']=$id;
            if($duplicate){$e['published']=null;unset($e['published_key']);$e['revision']=1;}
            else {if($e['published']!==null)$e['published_key']=self::publicKey($old,$e);if($new!==$old)self::retire($db,$old,$id);$e['revision']++;unset($db['pages'][$old]);}
            if($new!==$old)$e['draft']['link_base']??=$old;
            $moved[$new]=$e;
        }
        foreach($moved as $new=>$e)$db['pages'][$new]=$e;
        // Preorder ranks make both actual and implicit folder ordering deterministic.
        $rank=0;$visit=function($k)use(&$visit,&$db,&$rank,$children,$moved){
            if(isset($db['pages'][$k])){$rank++;$e=&$db['pages'][$k];if($e['draft']['order']!==$rank){$e['draft']['order']=$rank;if(!isset($moved[$k]))$e['revision']++;}unset($e);}
            foreach($children[$k]??[] as $child)$visit($child);
        };
        foreach($children[$scope] as $root)$visit($root);
        return $dest;
    }
    /** Publish only navigation of already published pages; never publish draft content. */
    public static function publish(array &$db,string $scope,string $token): void
    {
        self::verify($db,$token);$parts=explode('/',$scope);if(count($parts)!==2)throw new \InvalidArgumentException('Invalid version');BookStore::key($parts[0],$parts[1],'index');
        if(!array_filter(array_keys($db['pages']),fn($key)=>self::scope($key)===$scope))throw new \InvalidArgumentException('Unknown book version');
        foreach($db['pages'] as $key=>&$e){
            if(self::scope($key)!==$scope||$e['published']===null)continue;
            $id=self::id($key,$e);$old=self::publicKey($key,$e);
            if(self::reserved($db,$key,$id))throw new \DomainException('Public URL is reserved');
            if($old===$key&&$e['published']['order']===$e['draft']['order'])continue;
            if($old!==$key){self::remember($db,$old,$id);$e['published']['link_base']??=$old;}
            $e['id']=$id;$e['published_key']=$key;$e['published']['order']=$e['draft']['order'];$e['revision']++;
        }unset($e);
    }
    /** Validate additional metadata when restoring an untrusted portable backup. */
    public static function validate(array $db): void
    {
        $ids=[];$public=[];$draft=[];$reserved=[];
        foreach($db['pages'] as $key=>$e){
            if(!is_string($key)||!self::validKey($key)||!is_array($e))throw new \InvalidArgumentException('Invalid page entry');
            $id=$e['id']??self::id($key,[]);
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($ids[$id]))throw new \InvalidArgumentException('Invalid page identity');
            $ids[$id]=self::scope($key);$draft[$key]=$id;
            $p=$e['published_key']??$key;
            if(!is_string($p)||!self::validKey($p)||self::scope($p)!==self::scope($key))throw new \InvalidArgumentException('Invalid public path');
            if($e['published']!==null||isset($e['published_key'])){if(isset($public[$p]))throw new \InvalidArgumentException('Duplicate public path');$public[$p]=$id;}
            foreach(['draft','published'] as $state)if(isset($e[$state]['link_base'])){$base=$e[$state]['link_base'];if(!is_string($base)||!self::validKey($base)||self::scope($base)!==self::scope($key))throw new \InvalidArgumentException('Invalid link base');}
        }
        foreach(['redirects','retired_paths'] as $field){
            $map=$db[$field]??[];
            if(!is_array($map)||count($map)>10000)throw new \InvalidArgumentException('Invalid path history');
            foreach($map as $key=>$id){
                if(!is_string($key)||!self::validKey($key)||!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)
                    ||(isset($ids[$id])&&$ids[$id]!==self::scope($key))
                    ||(isset($public[$key])&&$public[$key]!==$id)||(isset($draft[$key])&&$draft[$key]!==$id)
                    ||(isset($reserved[$key])&&$reserved[$key]!==$id))throw new \InvalidArgumentException('Invalid path history');
                $reserved[$key]=$id;
            }
        }
        foreach($public as $key=>$id)if(isset($draft[$key])&&$draft[$key]!==$id)throw new \InvalidArgumentException('Conflicting draft and public paths');
    }
}
