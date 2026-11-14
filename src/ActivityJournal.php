<?php
declare(strict_types=1);
namespace CajeerBook;

/** Bounded, transactional event journal. Never store credentials, Markdown, comments or request payloads. */
final class ActivityJournal
{
    public const MAX_EVENTS=1000;
    public const MAX_BYTES=524288;
    private const TYPES=['login','logout','account.create','account.enable','account.disable','account.password','account.reset','account.sessions','organization.create','organization.rename','organization.archive','organization.restore','organization.delete','organization.invite','organization.revoke','organization.join','organization.leave','organization.remove','organization.transfer','organization.role','workspace.create','workspace.adopt','workspace.rename','workspace.archive','workspace.restore','workspace.visibility','workspace.role','page.save','page.publish','page.unpublish','page.delete','page.move','page.duplicate','page.copy','page.publish-tree','page.restore','book.create','book.visibility','media.upload','media.update','media.delete','review.in_review','review.approved','review.changes_requested','review.rejected','review.cancelled','review.stale','review.published','review.comment','review.prune','review.policy'];
    private static function id(mixed $v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{32}\z/D',$v)===1;}
    public static function record(array &$db,?array $actor,string $type,string $subject='',string $target='',string $org='',string $workspace='',array $recipients=[],string $detail=''):void
    {
        $id=bin2hex(random_bytes(16));$recipients=array_values(array_unique(array_filter($recipients,fn($u)=>self::id($u)&&$u!==($actor['id']??null))));
        $db['activity'][$id]=['id'=>$id,'at'=>gmdate('c'),'actor'=>$actor===null?null:['id'=>$actor['id'],'username'=>$actor['username']],'type'=>$type,'subject'=>$subject,'target'=>$target,'organization'=>$org,'workspace'=>$workspace,'recipients'=>$recipients,'read'=>[],'detail'=>$detail];
    }
    public static function validate(array $db):void
    {
        $events=$db['activity']??[];if(!is_array($events)||count($events)>self::MAX_EVENTS)throw new \InvalidArgumentException('Invalid activity journal');
        foreach($events as $id=>$e){
            if(!self::id($id)||!is_array($e)||array_diff(array_keys($e),['id','at','actor','type','subject','target','organization','workspace','recipients','read','detail'])||($e['id']??null)!==$id||!in_array($e['type']??null,self::TYPES,true)||!is_string($e['at']??null)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D',$e['at'])||!array_key_exists('actor',$e))throw new \InvalidArgumentException('Invalid activity event');
            $a=$e['actor'];if($a!==null&&(!is_array($a)||array_diff(array_keys($a),['id','username'])||!self::id($a['id']??null)||!is_string($a['username']??null)||!preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',$a['username'])))throw new \InvalidArgumentException('Invalid activity actor');
            foreach(['subject'=>400,'detail'=>200] as $k=>$max)if(!is_string($e[$k]??null)||strlen($e[$k])>$max||!preg_match('//u',$e[$k])||preg_match('/[\x00-\x1f\x7f]/',$e[$k]))throw new \InvalidArgumentException('Invalid activity text');
            foreach(['target','organization','workspace'] as $k)if(($e[$k]??null)!==''&&!self::id($e[$k]??null))throw new \InvalidArgumentException('Invalid activity scope');
            foreach(['recipients','read'] as $k){$v=$e[$k]??null;if(!is_array($v)||!array_is_list($v)||count($v)>10||count(array_unique($v,SORT_REGULAR))!==count($v))throw new \InvalidArgumentException('Invalid notification state');foreach($v as $u)if(!self::id($u))throw new \InvalidArgumentException('Invalid notification recipient');}
            $identityType=in_array($e['type'],['login','logout'],true)||str_starts_with($e['type'],'account.')||str_starts_with($e['type'],'organization.')||str_starts_with($e['type'],'workspace.');
            if((isset($db['users'])&&!$identityType)||isset($db['pages'])&&$identityType||(!$identityType&&($e['organization']!==''||$e['workspace']!==''))||str_starts_with($e['type'],'organization.')&&($e['organization']===''||$e['workspace']!=='')||str_starts_with($e['type'],'workspace.')&&($e['organization']===''||$e['workspace']===''))throw new \InvalidArgumentException('Invalid activity namespace');
            if(isset($db['workspaces'][$e['workspace']])&&$db['workspaces'][$e['workspace']]['organization']!==$e['organization'])throw new \InvalidArgumentException('Activity workspace scope mismatch');
            if(array_diff($e['read'],$e['recipients']))throw new \InvalidArgumentException('Invalid notification receipt');
        }
        if(strlen(json_encode($events,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))>self::MAX_BYTES)throw new \InvalidArgumentException('Activity quota exceeded');
    }
    /** Oldest records expire first. Journal must not prevent ordinary content writes. */
    public static function trim(array &$db,int $maxDatabase,bool $pretty=false):void
    {
        if(!isset($db['activity']))return;
        $flags=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|($pretty?JSON_PRETTY_PRINT:JSON_UNESCAPED_UNICODE);
        $base=$db;unset($base['activity']);$budget=max(0,min(self::MAX_BYTES,$maxDatabase-strlen(json_encode($base,$flags))-128));unset($base);
        $sizes=[];$total=2;foreach($db['activity'] as $id=>$e){$s=json_encode($e,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);$sizes[$id]=strlen($s)+8*substr_count($s,"\n")+64;$total+=$sizes[$id];}
        $n=count($sizes);foreach($sizes as $id=>$size){if($n<=self::MAX_EVENTS&&$total<=$budget)break;unset($db['activity'][$id]);$total-=$size;$n--;}
        if(!$db['activity'])unset($db['activity']);
    }
    /** Content changes and notifications share the BookStore commit, including stale review transitions. */
    public static function content(array $before,array &$db,?array $actor,?string $action):void
    {
        if($action!==null){
            // Capture the actual page transaction, independently of history retention.
            $map=static function(array $pages):array{$out=[];foreach($pages as $key=>$e){$draft=$e['draft'];$published=$e['published'];unset($draft['updated']);if($published!==null)unset($published['updated']);$out[PageTree::id($key,$e)]=['key'=>$key,'public'=>PageTree::publicKey($key,$e),'draft'=>$draft,'published'=>$published,'revision'=>$e['revision']];}return $out;};
            $old=$map($before['pages']);$new=$map($db['pages']);$count=0;$first=null;$firstId='';
            foreach($old+$new as $id=>$unused){$a=$old[$id]??null;$b=$new[$id]??null;if($action==='save'&&$a&&$b){unset($a['revision'],$b['revision']);}if($a===$b)continue;$count++;if($first===null){$first=$b??$a;$firstId=$id;}}
            if($first!==null)self::record($db,$actor,'page.'.$action,$first['key'],$firstId,'','',[],$count.' page(s)');
        }
        foreach(['books'=>'book','media'=>'media'] as $field=>$prefix){$old=$field==='books'?BookStore::books($before):($before[$field]??[]);$new=$field==='books'?BookStore::books($db):($db[$field]??[]);foreach($old+$new as $key=>$unused){$a=$old[$key]??null;$b=$new[$key]??null;if($a===$b)continue;if($field==='books'){if($a===null&&$b!==null)self::record($db,$actor,'book.create',$b['slug'],$b['id']);elseif($a&&$b&&($a['visibility']??'inherit')!==($b['visibility']??'inherit'))self::record($db,$actor,'book.visibility',$b['slug'],$b['id'],'','',[],$b['visibility']);}else{self::record($db,$actor,'media.'.($a===null?'upload':($b===null?'delete':'update')),($b??$a)['name']??'Media file',(string)$key);}}}
        if(ReviewWorkflow::policy($before)!==ReviewWorkflow::policy($db))self::record($db,$actor,'review.policy','Publication policy','','','',[],ReviewWorkflow::required($db)?'Required':'Direct');
        foreach(($before['reviews']??[])+($db['reviews']??[]) as $id=>$unused){$a=$before['reviews'][$id]??null;$b=$db['reviews'][$id]??null;if($a===$b)continue;$r=$b??$a;
            $type=$b===null?'prune':($a===null||$a['status']!==$b['status']?$b['status']:'comment');
            $recipients=$type==='prune'?[]:[$r['requester']['id'],$r['reviewer']['id']];self::record($db,$actor,'review.'.$type,$r['target'],$id,'','',$recipients);
        }
        self::trim($db,BookStore::MAX_DB_BYTES,true);
    }
    public static function inbox(array $events,string $uid):array{return array_values(array_reverse(array_filter($events,fn($e)=>in_array($uid,$e['recipients'],true)),true));}
    public static function mark(array &$db,string $uid,string $id,array $allowed):void
    {
        if($id!=='all'&&(!self::id($id)||!isset($allowed[$id])||!in_array($uid,$allowed[$id]['recipients'],true)))throw new \InvalidArgumentException('Notification not found');
        foreach($allowed as $key=>$e)if(($id==='all'||$id===$key)&&in_array($uid,$e['recipients'],true)&&!in_array($uid,$e['read'],true))$db['activity'][$key]['read'][]=$uid;
    }
    public static function unread(array $events,string $uid):int{return count(array_filter($events,fn($e)=>in_array($uid,$e['recipients'],true)&&!in_array($uid,$e['read'],true)));}
    /** Identity events are scoped at read time; a revoked membership never exposes historical names. */
    public static function identityVisible(array $db,string $uid,bool $audit):array
    {
        $validInvites=[];foreach($db['invitations']??[] as $i)if($i['user']===$uid&&$i['expires']>time())$validInvites[$i['id']]=true;
        $out=[];foreach($db['activity']??[] as $id=>$e){$org=$e['organization'];$wid=$e['workspace'];$o=$db['organizations'][$org]??null;$w=$db['workspaces'][$wid]??null;
            if($audit){if($org===''){if(!($db['users'][$uid]['owner']??false))continue;}elseif(!$o&&$e['type']==='organization.delete'&&($e['actor']['id']??'')===$uid){}elseif(!$o||!Permissions::allows(Permissions::organization($o,$uid),'manage')||($wid!==''&&(!$w||!Permissions::allows(Permissions::workspace($o,$w,$uid),'manage'))))continue;}
            else{if(!in_array($uid,$e['recipients'],true))continue;if($e['type']==='organization.invite'){if(!isset($validInvites[$e['target']]))continue;}elseif($org!==''&&(!$o||!isset($o['members'][$uid])||($wid!==''&&(!$w||Permissions::workspace($o,$w,$uid)==='none'))))continue;}
            $out[$id]=$e;
        }return $out;
    }
}
