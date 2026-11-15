<?php
declare(strict_types=1);
namespace CajeerBook;

/** Server-rendered team dashboard and personal inbox; all inputs are escaped, no client authorization. */
final class TeamView
{
    private static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function link(string $url,string $label):string{return '<a href="'.self::e($url).'">'.self::e($label).'</a>';}
    private static function field(string $name,string $value):string{return '<input type="hidden" name="'.$name.'" value="'.self::e($value).'">';}
    private static function mark(string $url,string $csrf,string $id,string $label):string{return '<form method="post" action="'.self::e($url).'">'.self::field('csrf',$csrf).self::field('action','read').self::field('id',$id).'<button type="submit">'.self::e($label).'</button></form>';}
    private static function query(array $q,string $key):string{return is_string($q[$key]??null)?substr($q[$key],0,200):'';}
    private static function page(array $q,string $key):int{return max(1,min(1000,(int)self::query($q,$key)));}
    private static function nav(string $url,array $q,string $key,int $page,int $count,int $limit):string{
        $q=array_filter($q,fn($v)=>is_string($v)&&strlen($v)<=200);$out='<nav class="actions" aria-label="Pagination">';
        if($page>1)$out.=self::link($url.'?'.http_build_query(array_replace($q,[$key=>$page-1])),'Previous');
        $out.='<span>Page '.$page.' / '.max(1,(int)ceil($count/$limit)).'</span>';
        if($page*$limit<$count)$out.=self::link($url.'?'.http_build_query(array_replace($q,[$key=>$page+1])),'Next');return $out.'</nav>';
    }
    private static function pending(array $e,string $key):bool{return $e['published']===null||array_diff_key($e['draft'],['updated'=>true])!==array_diff_key($e['published'],['updated'=>true])||PageTree::publicKey($key,$e)!==$key;}
    private static function lastAuthors(array $db):array{$last=[];foreach(array_reverse($db['history']??[],true) as $r)if($r['action']!=='baseline'&&!isset($last[$r['page']]))$last[$r['page']]=$r['actor']??['id'=>'','username'=>'System'];foreach(array_reverse($db['activity']??[],true) as $e)if(str_starts_with($e['type'],'page.')&&$e['target']!==''&&!isset($last[$e['target']]))$last[$e['target']]=$e['actor']??['id'=>'','username'=>'System'];return $last;}
    private static function myDraft(array $e,string $key,string $uid,array $last):bool{return self::pending($e,$key)&&(in_array($uid,$e['review_authors']??[],true)||in_array($uid,$e['review_tree_authors']??[],true)||(!isset($e['review_authors'])&&!isset($e['review_tree_authors'])&&($last[PageTree::id($key,$e)]['id']??'')===$uid));}
    private static function label(string $type):string{return match($type){'review.in_review'=>'Review assigned','review.changes_requested'=>'Changes requested','review.approved'=>'Review approved','review.published'=>'Approved revision published','review.stale'=>'Review became stale','review.comment'=>'New review comment','organization.invite'=>'Organization invitation','organization.join'=>'Member joined','organization.role'=>'Organization role changed','workspace.role'=>'Workspace access changed',default=>ucfirst(str_replace(['.','_','-'],' ',$type))};}
    private static function eventLink(array $e,?array $db):string{
        if($db!==null){if(str_starts_with($e['type'],'review.')&&isset($db['reviews'][$e['target']]))return self::link(WorkspaceUrls::url('/studio/reviews?id='.$e['target']),'Open review');
            if(str_starts_with($e['type'],'page.')&&$e['target']!=='')return self::link(WorkspaceUrls::url('/studio/history?page='.$e['target']),'Page history');
            if(str_starts_with($e['type'],'book.'))return self::link(WorkspaceUrls::url('/studio/books'),'Books');
            if(str_starts_with($e['type'],'media.'))return self::link(WorkspaceUrls::url('/studio/media'),'Media library');
            if($e['type']==='openapi.import')return self::link(WorkspaceUrls::url('/studio/openapi?spec='.rawurlencode($e['subject'])),'OpenAPI Reference');
            if(str_starts_with($e['type'],'git.'))return self::link(WorkspaceUrls::url('/studio/git'),'Git Sync');
        }elseif($e['type']==='organization.invite')return self::link('/organizations/join','Accept using invitation token');
        elseif($e['organization']!=='')return self::link('/organizations/'.$e['organization'],'Organization');return '';
    }
    private static function events(array $events,string $uid,string $csrf,string $url,array $q,string $pageKey,bool $inbox,?array $db=null):string{
        $filters='';if(!$inbox){$type=self::query($q,$pageKey.'_type');$actor=self::query($q,$pageKey.'_actor');$events=array_filter($events,fn($e)=>($type===''||stripos($e['type'],$type)!==false)&&($actor===''||strcasecmp($e['actor']['username']??'System',$actor)===0));
            $filters='<form class="team-filters" method="get" action="'.self::e($url).'"><label>Event type<input name="'.$pageKey.'_type" value="'.self::e($type).'" placeholder="review, page, role"></label><label>Actor<input name="'.$pageKey.'_actor" value="'.self::e($actor).'" placeholder="username"></label><button>Filter events</button></form>';}
        $events=array_values(array_reverse($events,true));$page=self::page($q,$pageKey);$limit=25;$count=count($events);$out=$filters;
        if($inbox){$out.='<div class="actions"><strong>'.ActivityJournal::unread($events,$uid).' unread</strong>'.self::mark($url,$csrf,'all','Mark all read').'</div>';}
        foreach(array_slice($events,($page-1)*$limit,$limit) as $e){$unread=$inbox&&!in_array($uid,$e['read'],true);$actor=$e['actor']['username']??'System';$out.='<div class="team-event'.($unread?' team-unread':'').'" data-event="'.$e['id'].'"><div><strong>'.self::e(self::label($e['type'])).'</strong>'.($unread?' <span class="team-tag">Unread</span>':'').'<p>'.self::e($e['subject']).($e['detail']!==''?' · '.self::e($e['detail']):'').'</p><small>'.self::e($actor.' · '.$e['at']).($e['target']!==''&&!$inbox?' · ID '.self::e($e['target']):'').'</small><div>'.self::eventLink($e,$db).'</div></div>'.($unread?self::mark($url,$csrf,$e['id'],'Mark read'):'').'</div>';}
        if(!$count)$out.='<p>No events yet.</p>';return $out.self::nav($url,$q,$pageKey,$page,$count,$limit);
    }
    public static function global(array $ctx,array $q):string{
        $u=$ctx['user'];$csrf=$ctx['session']['csrf'];$url='/team';$out='<div class="eyebrow">TEAM / OVERVIEW</div><h1>Team Studio</h1><p>Signed in as <strong>'.self::e($u['username']).'</strong>. Choose a workspace to manage drafts, reviews and activity.</p><div class="actions">'.self::link('/organizations','Organizations').self::link('/workspaces','Manage workspaces').self::link('/account','Account').'</div><h2>Your workspaces</h2><div class="team-grid">';
        foreach($ctx['workspaces'] as $w){$prefix=$w['legacy']?'':Workspaces::prefix($w);$archived=$w['archived']||$w['organization_archived'];$out.='<section class="org-card"><h3>'.self::e($w['name']).'</h3><p>'.self::e($w['organization_name'].' · '.ucfirst($w['role'])).($archived?' · Archived':'').'</p>';
            if(!$archived){$out.='<div class="actions">'.self::link($prefix.'/','Reader');if(Permissions::allows($w['role'],'edit'))$out.=self::link($prefix.'/studio/team','Team dashboard').self::link($prefix.'/studio','Studio');$out.='</div>';if(isset($w['summary']))$out.='<p>'.(int)$w['summary']['unread'].' unread · '.(int)$w['summary']['tasks'].' review tasks · '.(int)$w['summary']['drafts'].' my drafts</p>';if(!empty($w['unavailable']))$out.='<p role="status">Content store unavailable.</p>';}$out.='</section>';}
        if(!$ctx['workspaces'])$out.='<p>No accessible workspaces. Join or create an organization to get started.</p>';
        $out.='</div>'.self::nav($url,$q,'workspace_page',$ctx['workspace_page'],$ctx['workspace_count'],10).'<h2>Personal notifications</h2><p>Invitations and membership changes. Review notifications are in each workspace dashboard.</p>'.self::events($ctx['inbox'],$u['id'],$csrf,$url,$q,'inbox_page',true);
        if($ctx['audit']||$u['owner']||array_filter($ctx['organizations'],fn($o)=>Permissions::allows($o['role'],'manage'))){$out.='<h2>Administrative audit</h2><p>Successful security and organization changes in your current administrative scope. No passwords, tokens or document bodies are recorded.</p>'.self::events($ctx['audit'],$u['id'],$csrf,$url,$q,'audit_page',false);}
        return $out;
    }
    public static function summary(array $db,string $uid,string $role='admin'):array{
        $tasks=count(self::tasks($db,$uid,$role));
        $drafts=0;$last=self::lastAuthors($db);foreach($db['pages'] as $key=>$e)if(self::myDraft($e,$key,$uid,$last))$drafts++;
        return ['unread'=>ActivityJournal::unread($db['activity']??[],$uid),'tasks'=>$tasks,'drafts'=>$drafts];
    }
    private static function tasks(array $db,string $uid,string $role):array{
        $out=[];$seen=[];foreach(array_reverse($db['reviews']??[],true) as $id=>$r){$key=$r['kind'].':'.$r['target'];if(isset($seen[$key]))continue;$seen[$key]=true;
            if((Permissions::allows($role,'publish')&&$r['reviewer']['id']===$uid&&in_array($r['status'],['in_review','approved'],true))||($r['requester']['id']===$uid&&in_array($r['status'],['changes_requested','stale','approved'],true)))$out[$id]=$r;
        }return $out;
    }
    public static function workspace(array $db,array $ctx,string $role,array $q):string{
        $uid=$ctx['user']['id'];$csrf=$ctx['session']['csrf'];$url=WorkspaceUrls::url('/studio/team');$sum=self::summary($db,$uid,$role);$out='<div class="eyebrow">TEAM / WORKSPACE</div><h1>Team dashboard</h1><div class="actions">'.self::link('/team','All workspaces').self::link(WorkspaceUrls::url('/studio'),'Studio').self::link(WorkspaceUrls::url('/studio/reviews'),'Reviews').self::link(WorkspaceUrls::url('/studio/history'),'History').(Permissions::allows($role,'manage')?self::link(WorkspaceUrls::url('/studio/ci'),'CI/CD Publishing'):'').'</div><div class="team-grid">';
        foreach(['My drafts'=>$sum['drafts'],'My review tasks'=>$sum['tasks'],'Unread notifications'=>$sum['unread']] as $label=>$n)$out.='<section class="org-card"><strong>'.(int)$n.'</strong><p>'.$label.'</p></section>';$out.='</div><h2>My review tasks</h2>';$tasks=self::tasks($db,$uid,$role);
        foreach(array_slice($tasks,(self::page($q,'task_page')-1)*25,25) as $r)$out.='<div class="team-event"><div><strong>'.self::link(WorkspaceUrls::url('/studio/reviews?id='.$r['id']),$r['target']).'</strong><p>'.self::e(self::label('review.'.$r['status'])).' · '.self::e($r['requester']['username'].' → '.$r['reviewer']['username']).'</p></div></div>';
        if(!$tasks)$out.='<p>No review tasks assigned to you.</p>';$out.=self::nav($url,$q,'task_page',self::page($q,'task_page'),count($tasks),25);
        $members=array_values($ctx['team_members']??[]);usort($members,fn($a,$b)=>strcmp($a['username'],$b['username']));$mp=self::page($q,'member_page');$out.='<h2>Workspace members</h2><p>Enabled members with effective access to this workspace.</p><div class="team-grid">';foreach(array_slice($members,($mp-1)*25,25) as $m)$out.='<div class="org-card"><strong>'.self::e($m['username']).'</strong><p>'.self::e(ucfirst($m['role'])).'</p></div>';$out.='</div>'.self::nav($url,$q,'member_page',$mp,count($members),25);
        $out.='<h2>Documents</h2>';$status=self::query($q,'status');if(!in_array($status,['all','mine','draft','published'],true))$status='all';$search=self::query($q,'q');$author=self::query($q,'author');$book=self::query($q,'book');$from=self::query($q,'updated_after');$to=self::query($q,'updated_before');foreach(['from','to'] as $field)if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$$field))$$field='';
        $out.='<form class="team-filters" method="get" action="'.self::e($url).'"><label>Search title/path<input name="q" value="'.self::e($search).'"></label><label>Status<select name="status">';foreach(['all'=>'All','mine'=>'My drafts','draft'=>'Unpublished changes','published'=>'Published'] as $k=>$label)$out.='<option value="'.$k.'"'.($status===$k?' selected':'').'>'.$label.'</option>';$out.='</select></label><label>Book<select name="book"><option value="">All books</option>';foreach(BookStore::books($db) as $b)$out.='<option value="'.self::e($b['slug']).'"'.($book===$b['slug']?' selected':'').'>'.self::e($b['title']).'</option>';$out.='</select></label><label>Last author<input name="author" value="'.self::e($author).'" placeholder="username"></label><label>Updated from (UTC)<input type="date" name="updated_after" value="'.self::e($from).'"></label><label>Updated through (UTC)<input type="date" name="updated_before" value="'.self::e($to).'"></label><button>Filter</button></form>';
        $last=self::lastAuthors($db);
        $pages=[];foreach($db['pages'] as $key=>$e){$p=$e['draft'];$pub=$e['published'];$a=$last[PageTree::id($key,$e)]['username']??'System / imported';$draft=self::pending($e,$key);$mine=self::myDraft($e,$key,$uid,$last);
            $date=substr($p['updated'],0,10);if(($from!==''&&$date<$from)||($to!==''&&$date>$to)||($status==='mine'&&!$mine)||($status==='draft'&&!$draft)||($status==='published'&&$pub===null)||($search!==''&&stripos($key.' '.$p['title'],$search)===false)||($author!==''&&strcasecmp($a,$author)!==0)||($book!==''&&!str_starts_with($key,$book.'/')))continue;$pages[]=['key'=>$key,'p'=>$p,'author'=>$a,'status'=>$pub===null?'Draft':($draft?'Published · unpublished changes':'Published')];}
        usort($pages,fn($a,$b)=>strcmp($b['p']['updated'],$a['p']['updated'])?:strcmp($a['key'],$b['key']));$pg=self::page($q,'doc_page');foreach(array_slice($pages,($pg-1)*25,25) as $row)$out.='<div class="team-event"><div><strong>'.self::link(WorkspaceUrls::url('/studio?edit='.rawurlencode($row['key'])),$row['p']['title']).'</strong><p>'.self::e($row['key'].' · '.$row['status']).'</p><small>'.self::e($row['author'].' · '.$row['p']['updated']).'</small></div></div>';if(!$pages)$out.='<p>No matching documents.</p>';$out.=self::nav($url,$q,'doc_page',$pg,count($pages),25);
        $out.='<h2>Personal notifications</h2>'.self::events(array_filter($db['activity']??[],fn($e)=>in_array($uid,$e['recipients'],true)),$uid,$csrf,$url,$q,'inbox_page',true,$db);
        $out.='<h2>Workspace activity</h2><p>Recent content, media and review operations. Events are retained within the workspace quota.</p>'.self::events($db['activity']??[],$uid,$csrf,$url,$q,'activity_page',false,$db);
        if(Permissions::allows($role,'manage'))$out.='<h2>Publication and access audit</h2>'.self::events(array_filter($db['activity']??[],fn($e)=>in_array($e['type'],['book.visibility','review.policy','review.published','page.publish','page.publish-tree','page.unpublish','page.delete','media.delete','git.connect','git.rotate','git.disconnect','git.proposal','automation.configure','automation.retry','ci.token','ci.build','ci.approve','ci.publish','ci.rollback','ci.cancel','ci.prune'],true)),$uid,$csrf,$url,$q,'audit_page',false,$db);
        return $out;
    }
}
