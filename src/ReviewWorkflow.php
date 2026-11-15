<?php
declare(strict_types=1);
namespace CajeerBook;

/** Durable review requests, pinned to exact saved state. All mutations run under books.lock. */
final class ReviewWorkflow
{
    public const MAX_REQUESTS=100, MAX_BYTES=8*1024*1024, MAX_MESSAGES=50;
    public const ACTIVE=['in_review','approved'];
    public const STATES=['in_review','approved','changes_requested','rejected','cancelled','stale','published'];
    private static function hex(mixed $v,int $n=32):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$v)===1;}
    private static function digest(array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    public static function policy(array $db):array{return $db['review_policy']??['required'=>false,'revision'=>1];}
    public static function required(array $db):bool{return self::policy($db)['required'];}
    public static function text(string $s,bool $required=false):string{
        $s=trim($s);if(($required&&$s==='')||strlen($s)>2000||!preg_match('//u',$s)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$s))throw new \InvalidArgumentException('Review comment must be valid UTF-8 (up to 2000 bytes).');return $s;
    }
    private static function actor(array $u):array{return ['id'=>$u['id'],'username'=>$u['username']];}
    private static function assertActor(array $u):void{if(!self::hex($u['id']??null)||!is_string($u['username']??null)||!preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$u['username']))throw new \InvalidArgumentException('Invalid review actor');}
    private static function role(array $users,string $id):string{return $users[$id]['role']??'none';}
    private static function requireRole(array $users,array $actor,string $permission):void{if(!Permissions::allows(self::role($users,$actor['id']),$permission))throw new OrganizationAccessException('Review permission denied.');}
    private static function content(array $p):array{return [$p['title'],$p['section'],$p['body'],$p['link_base']??null];}
    /** Retain all contributors to unpublished changes, even if history is pruned. */
    public static function trackAuthors(array $before,array &$after,?array $actor,string $action):void{
        if($actor===null)return;$old=[];foreach($before['pages'] as $k=>$e)$old[PageTree::id($k,$e)]=[$k,$e];
        foreach($after['pages'] as $key=>&$entry){$prev=$old[PageTree::id($key,$entry)]??null;
            if($prev&&$prev[0]===$key&&$prev[1]===$entry)continue;
            $contentChanged=!$prev||self::content($prev[1]['draft'])!==self::content($entry['draft']);
            $treeChanged=!$prev||$prev[0]!==$key||$prev[1]['draft']['order']!==$entry['draft']['order'];
            foreach(['review_authors'=>false,'review_tree_authors'=>true] as $field=>$tree){
                $authors=array_merge($prev?self::contributors($before,$prev[0],$prev[1],$tree):[],$entry[$field]??[]);
                if($tree?$treeChanged:$contentChanged)$authors[]=$actor['id'];
                // Publishing content and publishing navigation are distinct operations.
                if($entry['published']!==null&&($tree?in_array($action,['publish','publish-tree'],true)&&PageTree::publicKey($key,$entry)===$key&&$entry['draft']['order']===$entry['published']['order']:$action==='publish'))$authors=[];
                $entry[$field]=array_values(array_unique($authors));
            }
        }unset($entry);
    }
    public static function contributors(array $db,string $key,array $e,bool $tree):array{
        $field=$tree?'review_tree_authors':'review_authors';if(array_key_exists($field,$e))return $e[$field];
        // Before 0.2.7 use retained history. Imported/expired author data cannot be reconstructed.
        $id=PageTree::id($key,$e);$events=array_values(RevisionHistory::entries($db,$id));$authors=[];
        $part=fn($r)=>$tree?[$r['key'],$r['draft']['order']]:self::content($r['draft']);
        foreach($events as $i=>$r){
            if(!$tree&&$r['action']==='publish')break;
            if($tree&&$r['published']!==null&&$r['key']===$r['public_key']&&$r['draft']['order']===$r['published']['order'])break;
            $older=$events[$i+1]??null;
            if(($older===null||$part($r)!==$part($older))&&$r['actor']!==null&&in_array($r['action'],['save','restore','move','duplicate','copy','publish'],true))$authors[]=$r['actor']['id'];
        }return array_values(array_unique($authors));
    }
    public static function pageState(array $db,string $key):array{
        $e=$db['pages'][$key]??null;if(!$e)throw new \InvalidArgumentException('Page not found. Save a draft first.');
        $draft=$e['draft'];$draft['media']??=[];$published=$e['published'];if($published!==null)$published['media']??=[];
        return ['page'=>PageTree::id($key,$e),'key'=>$key,'public_key'=>PageTree::publicKey($key,$e),'revision'=>$e['revision'],'draft'=>$draft,'published'=>$published];
    }
    /** Preview exactly the public structure change, without publishing new content. */
    public static function treePlan(array $db,string $scope):array{
        $parts=explode('/',$scope);if(count($parts)!==2)throw new \InvalidArgumentException('Invalid book version');BookStore::key($parts[0],$parts[1],'index');$rows=[];
        foreach($db['pages'] as $key=>$e){if(PageTree::scope($key)!==$scope||$e['published']===null)continue;$old=PageTree::publicKey($key,$e);if($old===$key&&$e['published']['order']===$e['draft']['order'])continue;
            $rows[]=['page'=>PageTree::id($key,$e),'from'=>$old,'to'=>$key,'old_order'=>$e['published']['order'],'new_order'=>$e['draft']['order']];}
        usort($rows,fn($a,$b)=>strcmp($a['to'],$b['to']));return $rows;
    }
    private static function fingerprint(array $db,string $kind,string $target):string{
        if($kind==='page')return self::digest(self::pageState($db,$target));
        // PageTree::publish already checks the global tree token; bind it plus the exact reviewed plan.
        return self::digest([PageTree::token($db),self::treePlan($db,$target)]);
    }
    public static function fresh(array $db,array $r):bool{
        try{return $r['policy_revision']===self::policy($db)['revision']&&hash_equals($r['fingerprint'],self::fingerprint($db,$r['kind'],$r['target']));}catch(\InvalidArgumentException){return false;}
    }
    public static function refresh(array &$db):void{
        foreach($db['reviews']??[] as $id=>$r)if(in_array($r['status'],self::ACTIVE,true)&&!self::fresh($db,$r)){$db['reviews'][$id]['status']='stale';$db['reviews'][$id]['revision']++;$db['reviews'][$id]['updated']=gmdate('c');}
    }
    public static function guard(array $db,string $kind,string $target,?string $id=null):void{
        if($id===null){if(self::required($db))throw new \DomainException('Review required. Submit a saved revision for approval before publishing.');return;}
        $r=$db['reviews'][$id]??null;if(!$r||$r['kind']!==$kind||$r['target']!==$target||$r['status']!=='approved'||!self::fresh($db,$r))throw new \DomainException('Approved review is missing or stale.');
    }
    public static function setPolicy(array &$db,array $actor,array $users,int $rev,bool $required):void{
        self::assertActor($actor);self::requireRole($users,$actor,'ownership');$p=self::policy($db);if($p['revision']!==$rev)throw new \DomainException('Review policy changed. Reload.');
        if($p['required']===$required)return;
        if($required&&count(array_filter($users,fn($u)=>Permissions::allows($u['role'],'edit')))<2)throw new \DomainException('Add another active author or reviewer before requiring review.');
        $db['schema']=max(2,$db['schema']??1);$db['review_policy']=['required'=>$required,'revision'=>$rev+1,'actor'=>self::actor($actor),'updated'=>gmdate('c')];self::refresh($db);
    }
    public static function authors(array $db,array $actor,string $kind,string $target):array{
        $authors=[$actor['id']];if($kind==='page'){$e=$db['pages'][$target]??null;if(!$e)throw new \InvalidArgumentException('Page not found.');$authors=array_merge($authors,self::contributors($db,$target,$e,false));if($e['published']===null)$authors=array_merge($authors,self::contributors($db,$target,$e,true));}
        elseif($kind==='tree')foreach(self::treePlan($db,$target) as $row)$authors=array_merge($authors,self::contributors($db,$row['to'],$db['pages'][$row['to']],true));
        else throw new \InvalidArgumentException('Unknown review target');return array_values(array_unique($authors));
    }
    public static function submit(array &$db,array $actor,array $users,string $kind,string $target,string $expected,string $reviewer,string $note):string{
        self::assertActor($actor);self::requireRole($users,$actor,'edit');$note=self::text($note);$authors=self::authors($db,$actor,$kind,$target);
        if($kind==='page'){$snapshot=self::pageState($db,$target);if((string)$snapshot['revision']!==$expected)throw new \DomainException('Page changed. Reload before submitting.');}
        elseif($kind==='tree'){if(!hash_equals(PageTree::token($db),$expected))throw new \DomainException('Tree changed. Reload before submitting.');$snapshot=self::treePlan($db,$target);if(!$snapshot)throw new \DomainException('No unpublished structure changes in this version.');}
        else throw new \InvalidArgumentException('Unknown review target');
        if(in_array($reviewer,$authors,true)||!Permissions::allows(self::role($users,$reviewer),'publish'))throw new OrganizationAccessException('Choose an independent active Admin or Owner as reviewer.');
        foreach($db['reviews']??[] as $r)if($r['kind']===$kind&&$r['target']===$target&&in_array($r['status'],self::ACTIVE,true)&&self::fresh($db,$r))throw new \DomainException('An active review already exists for this target.');
        $id=bin2hex(random_bytes(16));$now=gmdate('c');$db['schema']=max(2,$db['schema']??1);
        $db['reviews'][$id]=['id'=>$id,'kind'=>$kind,'target'=>$target,'snapshot'=>$snapshot,'snapshot_hash'=>self::digest($snapshot),'fingerprint'=>self::fingerprint($db,$kind,$target),'tree_token'=>$kind==='tree'?$expected:null,'policy_revision'=>self::policy($db)['revision'],'requester'=>self::actor($actor),'reviewer'=>self::actor($users[$reviewer]),'authors'=>$authors,'status'=>'in_review','revision'=>1,'created'=>$now,'updated'=>$now,'messages'=>[['action'=>'submit','actor'=>self::actor($actor),'at'=>$now,'text'=>$note]],'approved_by'=>null];
        if(strlen(json_encode($db['reviews'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES-512*1024)throw new \DomainException('Review storage is nearly full. Remove closed reviews or export a backup.');
        return $id;
    }
    public static function get(array $db,string $id):array{if(!self::hex($id)||!isset($db['reviews'][$id]))throw new \InvalidArgumentException('Review not found.');return $db['reviews'][$id];}
    /** Decisions must be by the assigned reviewer; requester can cancel or comment. */
    public static function decide(array &$db,array $actor,array $users,string $id,int $rev,string $action,string $note):void{
        self::assertActor($actor);self::requireRole($users,$actor,'edit');$r=self::get($db,$id);if($r['revision']!==$rev)throw new \DomainException('Review changed. Reload.');
        $uid=$actor['id'];$manager=Permissions::allows(self::role($users,$uid),'publish');$assigned=$r['reviewer']['id']===$uid;$requester=$r['requester']['id']===$uid;
        if(!in_array($r['status'],self::ACTIVE,true))throw new \DomainException('Review is closed. Submit a new request.');
        if(!self::fresh($db,$r))throw new \DomainException('Review is stale. Submit a new request.');
        $note=self::text($note,in_array($action,['comment','changes','reject'],true));$next=$r['status'];
        if($action==='cancel'){if(!$requester&&!$manager)throw new OrganizationAccessException('Cannot cancel this review.');$next='cancelled';}
        elseif($action==='comment'){if(!$requester&&!$manager)throw new OrganizationAccessException('Cannot comment on this review.');}
        elseif(in_array($action,['approve','changes','reject'],true)){
            if(!$assigned||!$manager||in_array($uid,$r['authors'],true))throw new OrganizationAccessException('Only the independent assigned reviewer may decide.');
            if($r['status']!=='in_review')throw new \DomainException('Review is already decided.');
            $next=match($action){'approve'=>'approved','changes'=>'changes_requested','reject'=>'rejected'};if($action==='approve')$r['approved_by']=self::actor($actor);
        }else throw new \InvalidArgumentException('Unknown review action.');
        self::message($r,$actor,$action,$note);$r['status']=$next;$db['reviews'][$id]=$r;
        if($action==='comment'&&strlen(json_encode($db['reviews'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES-512*1024)throw new \DomainException('Review storage is nearly full. Remove closed reviews before commenting.');
    }
    private static function message(array &$r,array $actor,string $action,string $note):void{
        // Reserve the final slots for a decision and publication, rather than making a full discussion unpublishable.
        if(count($r['messages'])>=self::MAX_MESSAGES||($action==='comment'&&count($r['messages'])>=self::MAX_MESSAGES-3))throw new \DomainException('Review discussion limit reached.');
        $r['messages'][]=['action'=>$action,'actor'=>self::actor($actor),'at'=>gmdate('c'),'text'=>$note];$r['revision']++;$r['updated']=gmdate('c');
    }
    public static function publish(array &$db,array $actor,array $users,string $id,int $rev,string $note):void{
        self::assertActor($actor);self::requireRole($users,$actor,'publish');$r=self::get($db,$id);if($r['revision']!==$rev)throw new \DomainException('Review changed. Reload.');self::guard($db,$r['kind'],$r['target'],$id);
        $reviewer=$r['approved_by']['id']??'';if($reviewer!==$r['reviewer']['id']||in_array($reviewer,$r['authors'],true)||!Permissions::allows(self::role($users,$reviewer),'publish'))throw new OrganizationAccessException('Approval is no longer valid: reviewer must retain publication permission.');
        $note=self::text($note);if($r['kind']==='tree')PageTree::publish($db,$r['target'],$r['tree_token'],$id);
        else{ $key=$r['target'];$e=$db['pages'][$key];$e['id']??=PageTree::id($key,$e);BookStore::publishSnapshot($db,$key,$e,$id);$e['revision']++;$db['pages'][$key]=$e; }
        self::message($r,$actor,'publish',$note);$r['status']='published';$db['reviews'][$id]=$r;
    }
    public static function mediaIndex(array $db):array{
        $out=[];foreach($db['reviews']??[] as $r){if($r['kind']!=='page')continue;foreach(['draft','published'] as $state){$p=$r['snapshot'][$state];if($p===null)continue;preg_match_all('~/media/([a-f0-9]{32})~',$p['body'],$m);foreach(array_unique(array_merge($p['media']??[],$m[1])) as $id)$out[$id][$r['target'].':review']=true;}}return array_map('array_keys',$out);
    }
    /** Closed requests can be explicitly pruned by an Owner; no active approvals silently disappear. */
    public static function prune(array &$db,array $actor,array $users,string $id,int $rev):void{
        self::requireRole($users,$actor,'ownership');$r=self::get($db,$id);if($r['revision']!==$rev||in_array($r['status'],self::ACTIVE,true))throw new \DomainException('Only closed reviews may be removed.');unset($db['reviews'][$id]);
    }
    public static function validate(array $db,bool $parse=false):void{
        $p=self::policy($db);if(!is_array($p)||!is_bool($p['required']??null)||!is_int($p['revision']??null)||$p['revision']<1)throw new \InvalidArgumentException('Invalid review policy');
        if(isset($p['actor']))self::assertActor($p['actor']);
        $all=$db['reviews']??[];if(!is_array($all)||count($all)>self::MAX_REQUESTS||strlen(json_encode($all,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES)throw new \DomainException('Review quota reached. Remove closed reviews or export a backup.');
        if((isset($db['review_policy'])||$all)&&!in_array($db['schema']??null,[2,3,4,5,6],true))throw new \InvalidArgumentException('Review metadata requires store schema 2 through 6');
        foreach($db['pages'] as $e)foreach(['review_authors','review_tree_authors'] as $f)if(array_key_exists($f,$e)){if(!is_array($e[$f])||!array_is_list($e[$f])||count($e[$f])>500)throw new \InvalidArgumentException('Invalid draft contributors');foreach($e[$f] as $a)if(!self::hex($a))throw new \InvalidArgumentException('Invalid draft contributor');}
        foreach($all as $id=>$r){
            if(!self::hex($id)||!is_array($r)||($r['id']??null)!==$id||!in_array($r['kind']??null,['page','tree'],true)||!is_string($r['target']??null)||!is_array($r['snapshot']??null)||!self::hex($r['snapshot_hash']??null,64)||!hash_equals($r['snapshot_hash'],self::digest($r['snapshot']))||!self::hex($r['fingerprint']??null,64)||!in_array($r['status']??null,self::STATES,true)||!is_int($r['revision']??null)||$r['revision']<1||!is_int($r['policy_revision']??null)||$r['policy_revision']<1||$r['policy_revision']>$p['revision']||!is_array($r['authors']??null)||!array_is_list($r['authors'])||count($r['authors'])<1||count($r['authors'])>501||!is_array($r['messages']??null)||!array_is_list($r['messages'])||count($r['messages'])<1||count($r['messages'])>self::MAX_MESSAGES)throw new \InvalidArgumentException('Invalid review request');
            self::assertActor($r['requester']);self::assertActor($r['reviewer']);foreach($r['authors'] as $a)if(!self::hex($a))throw new \InvalidArgumentException('Invalid review author');if(!in_array($r['requester']['id'],$r['authors'],true)||in_array($r['reviewer']['id'],$r['authors'],true))throw new \InvalidArgumentException('Invalid independent reviewer');
            $approved=$r['approved_by']??null;if($approved!==null){self::assertActor($approved);if($approved['id']!==$r['reviewer']['id'])throw new \InvalidArgumentException('Invalid approval');}if(in_array($r['status'],['approved','published'],true)&&$approved===null)throw new \InvalidArgumentException('Missing approval');
            foreach($r['messages'] as $m){if(!is_array($m)||!in_array($m['action']??null,['submit','comment','approve','changes','reject','cancel','publish'],true)||!is_string($m['text']??null)||self::text($m['text'])!==$m['text']||!is_string($m['at']??null))throw new \InvalidArgumentException('Invalid review message');self::assertActor($m['actor']);}
            if(!is_string($r['created']??null)||!is_string($r['updated']??null))throw new \InvalidArgumentException('Invalid review dates');
            $state='in_review';$decision=null;
            foreach($r['messages'] as $i=>$m){$action=$m['action'];$mid=$m['actor']['id'];
                if($i===0){if($action!=='submit'||$mid!==$r['requester']['id'])throw new \InvalidArgumentException('Invalid review submission');continue;}
                if(!in_array($state,self::ACTIVE,true)||$action==='submit')throw new \InvalidArgumentException('Invalid review transition');
                if(in_array($action,['approve','changes','reject'],true)){if($state!=='in_review'||$mid!==$r['reviewer']['id'])throw new \InvalidArgumentException('Invalid review decision');$state=match($action){'approve'=>'approved','changes'=>'changes_requested','reject'=>'rejected'};if($action==='approve')$decision=$m['actor'];}
                elseif($action==='publish'){if($state!=='approved')throw new \InvalidArgumentException('Unapproved review publication');$state='published';}
                elseif($action==='cancel')$state='cancelled';
            }
            if(($r['status']!==$state&&!($r['status']==='stale'&&in_array($state,self::ACTIVE,true)))||$decision!==$approved)throw new \InvalidArgumentException('Invalid review state');
            if($r['kind']==='page'){$s=$r['snapshot'];if(!array_key_exists('published',$s)||($s['key']??null)!==$r['target']||!self::hex($s['page']??null)||!is_int($s['revision']??null)||$s['revision']<1||!is_string($s['public_key']??null)||!PageTree::validKey($s['key'])||!PageTree::validKey($s['public_key'])||PageTree::scope($s['key'])!==PageTree::scope($s['public_key'])||!hash_equals($r['fingerprint'],self::digest($s)))throw new \InvalidArgumentException('Invalid review snapshot');
                foreach(['draft','published'] as $state){$v=$s[$state]??null;if($v===null&&$state==='published')continue;if(!is_array($v)||!is_string($v['body']??null)||strlen($v['body'])>262144||!preg_match('//u',$v['body'])||!is_string($v['title']??null)||trim($v['title'])===''||strlen($v['title'])>200||!is_string($v['section']??null)||strlen($v['section'])>100||!preg_match('//u',$v['title'].$v['section'])||!is_int($v['order']??null)||!is_string($v['updated']??null)||!is_array($v['media']??null)||!array_is_list($v['media']))throw new \InvalidArgumentException('Invalid review page');$base=$v['link_base']??($state==='published'?$s['public_key']:$s['key']);if(!is_string($base)||!PageTree::validKey($base)||PageTree::scope($base)!==PageTree::scope($s['key']))throw new \InvalidArgumentException('Invalid review link base');foreach($v['media'] as $mid)if(!self::hex($mid)||!isset($db['media'][$mid]))throw new \InvalidArgumentException('Review media missing');if(isset($v['playground']))ApiPlayground::validateSnapshot($v);if($parse){$refs=Markdown::mediaReferences($v['body'],$base);$saved=$v['media'];sort($refs);sort($saved);if($refs!==$saved)throw new \InvalidArgumentException('Review media index mismatch');}}
            }else{$parts=explode('/',$r['target']);if(count($parts)!==2)throw new \InvalidArgumentException('Invalid tree review');BookStore::key($parts[0],$parts[1],'index');if(!self::hex($r['tree_token']??null,64)||!array_is_list($r['snapshot'])||!$r['snapshot']||count($r['snapshot'])>1000||!hash_equals($r['fingerprint'],self::digest([$r['tree_token'],$r['snapshot']])))throw new \InvalidArgumentException('Invalid tree snapshot');foreach($r['snapshot'] as $row){if(!self::hex($row['page']??null)||!is_string($row['from']??null)||!is_string($row['to']??null)||!PageTree::validKey($row['from'])||!PageTree::validKey($row['to'])||PageTree::scope($row['from'])!==$r['target']||PageTree::scope($row['to'])!==$r['target']||!is_int($row['old_order']??null)||!is_int($row['new_order']??null))throw new \InvalidArgumentException('Invalid tree review row');}}
        }
    }
}
