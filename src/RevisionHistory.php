<?php
declare(strict_types=1);
namespace CajeerBook;

/** Immutable, bounded page snapshots, committed atomically with the current document. */
final class RevisionHistory
{
    public const MAX_PER_PAGE=50, MAX_EVENTS=2000, MAX_BYTES=8*1024*1024;
    private const ACTIONS=['baseline','save','publish','unpublish','delete','copy','move','duplicate','publish-tree','restore'];
    private static function hex(mixed $s):bool{return is_string($s)&&preg_match('/\A[a-f0-9]{32}\z/D',$s)===1;}
    private static function byId(array $pages):array{
        $out=[];foreach($pages as $key=>$entry)$out[PageTree::id($key,$entry)]=[$key,$entry];return $out;
    }
    public static function current(array $db,string $id):?array{return self::byId($db['pages'])[$id]??null;}
    private static function snapshot(array $p):array{
        $s=array_intersect_key($p,array_flip(['title','section','order','body','updated','media','link_base','playground']));$s['media']??=[];return $s;
    }
    private static function state(string $key,array $e):array{
        return ['key'=>$key,'public_key'=>PageTree::publicKey($key,$e),'draft'=>self::snapshot($e['draft']),'published'=>$e['published']===null?null:self::snapshot($e['published'])];
    }
    private static function comparable(array $s):array{
        unset($s['draft']['updated'],$s['published']['updated']);return $s;
    }
    public static function note(string $note):string { $note=trim($note);if(strlen($note)>500||!preg_match('//u',$note)||preg_match('/[\x00-\x1f\x7f]/',$note))throw new \InvalidArgumentException('Invalid revision note (maximum 500 UTF-8 bytes)');return $note; }
    private static function add(array &$db,string $id,string $key,array $entry,string $action,?array $actor,bool $deleted=false,string $note='',?string $source=null):void{
        $rid=bin2hex(random_bytes(16));
        $db['history'][$rid]=['id'=>$rid,'page'=>$id,'revision'=>$entry['revision'],'action'=>$action,'at'=>gmdate('c'),
            'actor'=>$actor===null?null:['id'=>$actor['id'],'username'=>$actor['username']], 'deleted'=>$deleted,'note'=>$note,'source_revision'=>$source]+self::state($key,$entry);
        foreach(['review_authors'=>false,'review_tree_authors'=>true] as $field=>$tree)$db['history'][$rid][$field]=ReviewWorkflow::contributors($db,$key,$entry,$tree);
    }
    /** Called inside BookStore's exclusive transaction, only for actual document operations. */
    public static function capture(array $before,array &$after,string $action,?array $actor,string $note='',?string $source=null):void{
        if(!in_array($action,self::ACTIONS,true)||$action==='baseline')throw new \LogicException('Unknown history operation');
        $note=self::note($note);
        $old=self::byId($before['pages']);$new=self::byId($after['pages']);$known=[];
        foreach($after['history']??[] as $r)$known[$r['page']]=true;
        foreach($old+$new as $id=>$unused){
            $a=$old[$id]??null;$b=$new[$id]??null;
            if($a&&$b&&$a===$b)continue;
            if($a&&$b&&self::comparable(self::state(...$a))===self::comparable(self::state(...$b))&&$action!=='restore'&&$note==='')continue;
            if($a&&!isset($known[$id]))self::add($after,$id,$a[0],$a[1],'baseline',null);
            $pair=$b??$a;self::add($after,$id,$pair[0],$pair[1],$action,$actor,$b===null,$note,$source);
        }
        self::prune($after);
    }
    /** Keep the newest entries, first per page and then within global storage bounds. */
    private static function prune(array &$db):void{
        if(!isset($db['history']))return;
        $counts=[];foreach(array_reverse($db['history'],true) as $rid=>$r){$id=$r['page'];$counts[$id]=($counts[$id]??0)+1;if($counts[$id]>self::MAX_PER_PAGE)unset($db['history'][$rid]);}
        // History cannot consume space needed by current content or portable backups.
        $base=$db;unset($base['history']);$budget=max(0,min(self::MAX_BYTES,BookStore::MAX_DB_BYTES-strlen(json_encode($base,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))-64));unset($base);
        $sizes=[];$size=2;foreach($db['history'] as $rid=>$r){$encoded=json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);$sizes[$rid]=strlen($encoded)+8*substr_count($encoded,"\n")+strlen($rid)+32;$size+=$sizes[$rid];}
        $count=count($sizes);foreach($sizes as $rid=>$bytes){if($count<=self::MAX_EVENTS&&$size<=$budget)break;unset($db['history'][$rid]);$count--;$size-=$bytes;}
        if(!$db['history'])unset($db['history']);
    }
    public static function entries(array $db,string $page):array{
        return array_reverse(array_filter($db['history']??[],fn($r)=>$r['page']===$page),true);
    }
    public static function get(array $db,string $page,string $id):array{
        $r=$db['history'][$id]??null;if(!self::hex($page)||!self::hex($id)||!$r||$r['page']!==$page)throw new \InvalidArgumentException('Historical revision not found');return $r;
    }
    public static function validate(array $db,bool $parse=false):void{
        $history=$db['history']??[];
        if(!is_array($history)||count($history)>self::MAX_EVENTS)throw new \InvalidArgumentException('Invalid revision history');
        $counts=[];$scopes=[];$live=self::byId($db['pages']);
        foreach($history as $rid=>$r){
            if(!self::hex($rid)||!is_array($r)||($r['id']??null)!==$rid||!self::hex($r['page']??null)||!is_string($r['key']??null)||!PageTree::validKey($r['key'])||!is_string($r['public_key']??null)||!PageTree::validKey($r['public_key'])||PageTree::scope($r['key'])!==PageTree::scope($r['public_key'])||!is_int($r['revision']??null)||$r['revision']<1||!in_array($r['action']??null,self::ACTIONS,true)||!is_string($r['at']??null)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D',$r['at'])||!is_bool($r['deleted']??null)||!array_key_exists('actor',$r)||!array_key_exists('published',$r))throw new \InvalidArgumentException('Invalid historical revision');
            if($r['deleted']!==($r['action']==='delete')||!is_string($r['note']??'')||self::note($r['note']??'')!==($r['note']??''))throw new \InvalidArgumentException('Invalid history event');
            foreach(['review_authors','review_tree_authors'] as $field)if(isset($r[$field])){if(!is_array($r[$field])||!array_is_list($r[$field])||count($r[$field])>500)throw new \InvalidArgumentException('Invalid historical contributors');foreach($r[$field] as $uid)if(!self::hex($uid))throw new \InvalidArgumentException('Invalid historical contributor');}
            $source=$r['source_revision']??null;if($source!==null&&($r['action']!=='restore'||!self::hex($source)))throw new \InvalidArgumentException('Invalid history restore source');
            $id=$r['page'];$scope=PageTree::scope($r['key']);$counts[$id]=($counts[$id]??0)+1;
            if($counts[$id]>self::MAX_PER_PAGE||isset($scopes[$id])&&$scopes[$id]!==$scope||isset($live[$id])&&PageTree::scope($live[$id][0])!==$scope)throw new \InvalidArgumentException('Invalid history page scope');$scopes[$id]=$scope;
            $a=$r['actor'];if($a!==null&&(!is_array($a)||!self::hex($a['id']??null)||!is_string($a['username']??null)||!preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$a['username'])))throw new \InvalidArgumentException('Invalid historical author');
            foreach(['draft','published'] as $state){$p=$r[$state]??null;if($p===null&&$state==='published')continue;
                if(!is_array($p)||!is_string($p['title']??null)||trim($p['title'])===''||strlen($p['title'])>200||!is_string($p['section']??null)||strlen($p['section'])>100||!preg_match('//u',$p['title'].$p['section'])||!is_int($p['order']??null)||!is_string($p['updated']??null)||!is_string($p['body']??null)||strlen($p['body'])>262144||!preg_match('//u',$p['body'])||!is_array($p['media']??null)||!array_is_list($p['media']))throw new \InvalidArgumentException('Invalid historical snapshot');
                $base=$p['link_base']??($state==='published'?$r['public_key']:$r['key']);if(!is_string($base)||!PageTree::validKey($base)||PageTree::scope($base)!==$scope)throw new \InvalidArgumentException('Invalid historical link base');
                foreach($p['media'] as $mid)if(!self::hex($mid)||!isset($db['media'][$mid]))throw new \InvalidArgumentException('Historical media missing');
                if(isset($p['playground']))ApiPlayground::validateSnapshot($p);if($parse){$refs=Markdown::mediaReferences($p['body'],$base);$saved=$p['media'];sort($refs);sort($saved);if($refs!==$saved)throw new \InvalidArgumentException('Historical media reference index mismatch');}
            }
        }
        if(strlen(json_encode($history,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES)throw new \InvalidArgumentException('History quota exceeded');
    }
    public static function mediaReferences(array $db,string $id):array{
        $refs=[];foreach($db['history']??[] as $r)foreach(['draft','published'] as $state){$p=$r[$state]??null;if($p!==null&&(in_array($id,$p['media'],true)||str_contains($p['body'],'/media/'.$id)))$refs[]=$r['key'].':history';}return array_values(array_unique($refs));
    }
    public static function mediaIndex(array $db):array{
        $out=[];foreach($db['history']??[] as $r)foreach(['draft','published'] as $state){$p=$r[$state]??null;if($p===null)continue;preg_match_all('~/media/([a-f0-9]{32})~',$p['body'],$m);foreach(array_unique(array_merge($p['media'],$m[1])) as $id)$out[$id][$r['key'].':history']=true;}return array_map('array_keys',$out);
    }
    /** Older snapshots have no explicit provenance; reconstruct conservatively from retained events. */
    public static function historicalContributors(array $db,array $r,bool $tree):array{
        $field=$tree?'review_tree_authors':'review_authors';if(array_key_exists($field,$r))return $r[$field];
        $authors=[];$started=false;
        foreach(self::entries($db,$r['page']) as $id=>$event){
            if($id===$r['id'])$started=true;if(!$started)continue;
            if(!$tree&&$event['action']==='publish')break;
            if($tree&&$event['published']!==null&&$event['key']===$event['public_key']&&$event['draft']['order']===$event['published']['order'])break;
            if($event['actor']!==null&&in_array($event['action'],['save','restore','move','duplicate','copy'],true))$authors[]=$event['actor']['id'];
            if(isset($event[$field])){$authors=array_merge($authors,$event[$field]);break;}
        }return array_values(array_unique($authors));
    }
    /** Restore content only; never publish or roll back a public URL. */
    public static function restore(array &$db,string $page,string $rid,string $state,int $expected,string $head):string{
        $r=self::get($db,$page,$rid);$list=self::entries($db,$page);if(array_key_first($list)!==$head)throw new \DomainException('History changed. Reload before restoring.');
        if(!in_array($state,['draft','published'],true)||!is_array($r[$state]??null))throw new \InvalidArgumentException('Historical snapshot not available');
        $current=self::current($db,$page);$last=reset($list);
        if($current){[$key,$entry]=$current;if($entry['revision']!==$expected)throw new \DomainException('Page changed. Reload before restoring.');}
        else{
            if($expected!==0||!$last['deleted'])throw new \DomainException('Deleted page changed. Reload.');
            $key=$last['key'];if(isset($db['pages'][$key])||PageTree::reserved($db,$key,$page)||count($db['pages'])>=1000)throw new \DomainException('Cannot restore page at its previous path');
            $entry=['id'=>$page,'revision'=>$last['revision'],'published'=>null,'published_key'=>$last['public_key']];
        }
        $p=$r[$state];$base=$p['link_base']??($state==='published'?$r['public_key']:$r['key']);
        if($base!==$key)$p['link_base']=$base;else unset($p['link_base']);
        $p['media']=Markdown::mediaReferences($p['body'],$base);foreach($p['media'] as $id)if(!isset($db['media'][$id]))throw new \DomainException('Historical media is no longer available');
        if($state==='draft')foreach(['review_authors'=>false,'review_tree_authors'=>true] as $field=>$tree)$entry[$field]=array_values(array_unique(array_merge($entry[$field]??[],self::historicalContributors($db,$r,$tree))));
        $p['updated']=gmdate('c');$entry['draft']=$p;$entry['id']=$page;$entry['revision']++;$db['pages'][$key]=$entry;return $key;
    }
    /** Bounded line diff: LCS for small regions, exact remove/add fallback for large ones. */
    public static function diff(string $old,string $new):array{
        if($old===$new)return [['same',$old]];
        $a=explode("\n",$old);$b=explode("\n",$new);$n=count($a);$m=count($b);$p=0;$s=0;
        while($p<$n&&$p<$m&&$a[$p]===$b[$p])$p++;
        while($s<$n-$p&&$s<$m-$p&&$a[$n-1-$s]===$b[$m-1-$s])$s++;
        $out=[];if($p)$out[]=['same',implode("\n",array_slice($a,0,$p))];
        $a=array_slice($a,$p,$n-$p-$s);$b=array_slice($b,$p,$m-$p-$s);$n2=count($a);$m2=count($b);
        if($n2*$m2>160000||$n2+$m2>2000){if($n2)$out[]=['remove',implode("\n",$a)];if($m2)$out[]=['add',implode("\n",$b)];}
        else{
            $dp=array_fill(0,$n2+1,array_fill(0,$m2+1,0));for($i=$n2-1;$i>=0;$i--)for($j=$m2-1;$j>=0;$j--)$dp[$i][$j]=$a[$i]===$b[$j]?1+$dp[$i+1][$j+1]:max($dp[$i+1][$j],$dp[$i][$j+1]);
            $i=$j=0;while($i<$n2||$j<$m2){if($i<$n2&&$j<$m2&&$a[$i]===$b[$j]){$out[]=['same',$a[$i++]];$j++;}elseif($j<$m2&&($i===$n2||$dp[$i][$j+1]>$dp[$i+1][$j]))$out[]=['add',$b[$j++]];else $out[]=['remove',$a[$i++]];}
        }
        if($s)$out[]=['same',implode("\n",array_slice(explode("\n",$old),-$s))];return $out;
    }
}
