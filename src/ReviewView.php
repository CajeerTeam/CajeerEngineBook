<?php
declare(strict_types=1);
namespace CajeerBook;

/** Server rendered review inbox and immutable snapshot comparison. */
final class ReviewView
{
    private static function e(string $s):string{return Markdown::e($s);}
    private static function url(array $q=[]):string{return WorkspaceUrls::url('/studio/reviews'.($q?'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986):''));}
    private static function link(string $url,string $text):string{return '<a href="'.self::e($url).'">'.self::e($text).'</a>';}
    private static function field(string $name,string $value):string{return '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';}
    private static function param(array $q,string $key,string $default=''):string{return is_string($q[$key]??null)?$q[$key]:$default;}
    private static function label(string $s):string{return ucfirst(str_replace('_',' ',$s));}
    private static function form(string $csrf,array $fields):string{$h='<form method="post" action="'.self::e(self::url()).'" class="editor review-form">'.self::field('csrf',$csrf);foreach($fields as $key=>$value)$h.=self::field($key,(string)$value);return $h;}
    private static function note(string $label='Comment (optional)',bool $required=false):string{return '<label>'.self::e($label).'<textarea name="note" rows="3" maxlength="2000"'.($required?' required':'').'></textarea></label>';}
    public static function render(array $db,string $csrf,array $actor,array $users,array $q):string{
        $uid=$actor['id'];$role=$users[$uid]['role']??'none';$manager=Permissions::allows($role,'publish');$owner=$role==='owner';$policy=ReviewWorkflow::policy($db);
        $h='<div class="eyebrow">AUTHORING / REVIEWS</div><h1>Review &amp; approval</h1><div class="actions">'.self::link(WorkspaceUrls::url('/studio'),'Studio').self::link(self::url(),'All reviews').'</div><p>Submit a saved draft or pending tree changes. Approval is tied to that exact state; subsequent changes invalidate it. Only the assigned independent reviewer may approve.</p><p class="tree-notice">Publication policy: <strong>'.($policy['required']?'Review required':'Direct publication allowed').'</strong></p>';
        if($owner){$h.=self::form($csrf,['action'=>'policy','revision'=>$policy['revision']]).'<label>Publication policy<select name="required"><option value="0"'.(!$policy['required']?' selected':'').'>Direct publication allowed</option><option value="1"'.($policy['required']?' selected':'').'>Require review for pages and tree</option></select></label><p>Only the organization Owner may change this policy. Changing it invalidates all open requests.</p><button data-confirm="Change publication policy? Open reviews become stale.">Save review policy</button></form>';}
        $id=self::param($q,'id');if($id!=='')return $h.self::detail($db,$csrf,$actor,$users,$id,$q);
        $kind=self::param($q,'kind');$target=self::param($q,'target');
        if($kind!==''&&$target!=='')return $h.self::submit($db,$csrf,$actor,$users,$kind,$target);
        $h.='<h2>Submit a review</h2><form method="get" action="'.self::e(self::url()).'" class="review-form editor">'.self::field('kind','page').'<label>Saved page<select name="target" required><option value="">Choose a page</option>';
        foreach(PageTree::ordered(PageTree::drafts($db)) as $key=>$p)$h.='<option value="'.self::e($key).'">'.self::e($p['title'].' · '.$key).'</option>';
        $h.='</select></label><button>Review saved draft</button></form>';
        $scopes=[];foreach($db['pages'] as $key=>$unused)$scopes[PageTree::scope($key)]=true;
        if($scopes){$h.='<form method="get" action="'.self::e(self::url()).'" class="editor review-form">'.self::field('kind','tree').'<label>Book version<select name="target" required>';foreach(array_keys($scopes) as $scope)$h.='<option value="'.self::e($scope).'">'.self::e($scope).'</option>';$h.='</select></label><button>Review tree changes</button></form>';}
        $filter=self::param($q,'status','active');$mine=self::param($q,'mine')==='1';$filters=['active'=>'Open requests','all'=>'All requests'];foreach(ReviewWorkflow::STATES as $s)$filters[$s]=self::label($s);if(!isset($filters[$filter]))$filter='active';
        $h.='<h2>Review queue</h2><form method="get" action="'.self::e(self::url()).'" class="actions"><label>Status<select name="status">';foreach($filters as $s=>$label)$h.='<option value="'.$s.'"'.($s===$filter?' selected':'').'>'.self::e($label).'</option>';$h.='</select></label><label><input type="checkbox" name="mine" value="1"'.($mine?' checked':'').'> My requests / assignments</label><button>Filter</button></form>';
        $rows=array_reverse($db['reviews']??[],true);$count=0;$h.='<ul class="history-list review-list">';foreach($rows as $rid=>$r){$status=in_array($r['status'],ReviewWorkflow::ACTIVE,true)&&!ReviewWorkflow::fresh($db,$r)?'stale':$r['status'];if($filter==='active'&&!in_array($status,ReviewWorkflow::ACTIVE,true)||$filter!=='active'&&$filter!=='all'&&$status!==$filter||$mine&&$r['requester']['id']!==$uid&&$r['reviewer']['id']!==$uid)continue;$count++;$h.='<li>'.self::link(self::url(['id'=>$rid]),($r['kind']==='tree'?'Tree · ':'Page · ').$r['target']).' <strong>'.self::e(self::label($status)).'</strong><div class="muted">'.self::e($r['requester']['username'].' → '.$r['reviewer']['username'].' · '.$r['created']).'</div></li>';}
        return $h.'</ul><p>'.$count.' requests</p>';
    }
    private static function submit(array $db,string $csrf,array $actor,array $users,string $kind,string $target):string{
        $h='<h2>Submit '.self::e($kind==='tree'?'tree changes':'saved draft').'</h2><p><code>'.self::e($target).'</code></p>';
        if($kind==='page'){$s=ReviewWorkflow::pageState($db,$target);$expected=(string)$s['revision'];$h.='<p>Revision '.$expected.' · '.self::e($s['draft']['title']).'</p>'.self::snapshot($db,$s,false);}
        elseif($kind==='tree'){$s=ReviewWorkflow::treePlan($db,$target);$expected=PageTree::token($db);if(!$s)return $h.'<p>No unpublished changes to the public navigation of this version.</p>';$h.=self::tree($s);}
        else throw new \InvalidArgumentException('Unknown review target');
        foreach($db['reviews']??[] as $r)if($r['kind']===$kind&&$r['target']===$target&&in_array($r['status'],ReviewWorkflow::ACTIVE,true)&&ReviewWorkflow::fresh($db,$r))return $h.'<p>An open review already exists. '.self::link(self::url(['id'=>$r['id']]),'Open review').'</p>';
        $authors=ReviewWorkflow::authors($db,$actor,$kind,$target);$options='';foreach($users as $id=>$u)if(!in_array($id,$authors,true)&&Permissions::allows($u['role'],'publish'))$options.='<option value="'.self::e($id).'">'.self::e($u['username'].' · '.ucfirst($u['role'])).'</option>';
        if($options==='')return $h.'<p>No other active Admin or Owner is available to review. Ask an eligible author to submit or assign another reviewer.</p>';
        return $h.self::form($csrf,['action'=>'submit','kind'=>$kind,'target'=>$target,'expected'=>$expected]).'<label>Assign reviewer<select name="reviewer" required><option value="">Choose an independent reviewer</option>'.$options.'</select></label><p>Reviewers may not be the requester or the recorded contributors to the unpublished changes.</p>'.self::note('Submission note (optional)').'<button class="primary">Submit for review</button></form>';
    }
    private static function tree(array $rows):string{
        $h='<div class="review-table-wrap"><table class="review-table"><thead><tr><th>Current public path</th><th>Proposed public path</th><th>Order</th></tr></thead><tbody>';
        foreach($rows as $r)$h.='<tr><td><code>'.self::e($r['from']).'</code></td><td><code>'.self::e($r['to']).'</code></td><td>'.$r['old_order'].' → '.$r['new_order'].'</td></tr>';
        return $h.'</tbody></table></div><p>Only navigation of already published pages changes. Draft-only pages and draft bodies are not published.</p>';
    }
    private static function snapshot(array $db,array $s,bool $preview):string{
        $draft=$s['draft'];$pub=$s['published'];$h='<h3>Submitted snapshot</h3><p>Page ID <code>'.self::e($s['page']).'</code> · Revision '.$s['revision'].'</p><p>Title: '.self::e($pub['title']??'(unpublished)').' → '.self::e($draft['title']).' · Section: '.self::e($pub['section']??'—').' → '.self::e($draft['section']).'</p><p>Public URL: <code>'.self::e($pub===null?$s['key']:$s['public_key']).'</code> · Publication order: '.($pub['order']??$draft['order']).'. Existing public paths and order require a separate tree review.</p><h3>Changes: published → submitted</h3><div class="history-diff" aria-label="Markdown diff">';
        foreach(RevisionHistory::diff($pub['body']??'',$draft['body']) as [$kind,$text])$h.='<pre class="diff-'.$kind.'"><span aria-hidden="true">'.match($kind){'add'=>'+ ','remove'=>'− ',default=>'  '}.'</span>'.self::e($text).'</pre>';
        $h.='</div><h3>Exact submitted Markdown</h3><pre class="history-source"><code>'.self::e($draft['body']).'</code></pre>';
        if($preview)$h.='<h3>Submitted preview</h3><article>'.Markdown::render($draft['body'],$s['key'],false,true,$draft['link_base']??null,PageTree::linkMap($db))['html'].'</article>';
        return $h;
    }
    private static function detail(array $db,string $csrf,array $actor,array $users,string $id,array $q):string{
        $r=ReviewWorkflow::get($db,$id);$uid=$actor['id'];$role=$users[$uid]['role']??'none';$manager=Permissions::allows($role,'publish');$assigned=$r['reviewer']['id']===$uid&&$manager;$requester=$r['requester']['id']===$uid;
        $active=in_array($r['status'],ReviewWorkflow::ACTIVE,true)&&ReviewWorkflow::fresh($db,$r);$status=!$active&&in_array($r['status'],ReviewWorkflow::ACTIVE,true)?'stale':$r['status'];$eligible=Permissions::allows($users[$r['reviewer']['id']]['role']??'none','publish');
        $h='<h2>'.self::e(self::label($r['kind']).' · '.$r['target']).'</h2><p><strong>'.self::e(self::label($status)).'</strong> · '.self::e($r['requester']['username'].' → '.$r['reviewer']['username']).' · '.self::e($r['created']).'</p><p>Request ID <code>'.self::e($id).'</code> · Snapshot SHA-256 <code class="review-hash">'.self::e($r['snapshot_hash']).'</code></p>';
        if(!$eligible&&$active)$h.='<p class="tree-notice">The assigned reviewer no longer has publication permission. Cancel and submit a new request with an eligible reviewer.</p>';
        if($r['kind']==='page'){$h.=self::snapshot($db,$r['snapshot'],self::param($q,'preview')==='1');if(self::param($q,'preview')!=='1')$h.='<p>'.self::link(self::url(['id'=>$id,'preview'=>'1']),'Render submitted preview').'</p>';}
        else $h.=self::tree($r['snapshot']);
        $h.='<h3>Discussion and decisions</h3><ol class="review-messages">';foreach($r['messages'] as $m)$h.='<li><strong>'.self::e(self::label($m['action']).' · '.$m['actor']['username']).'</strong> <span class="muted">'.self::e($m['at']).'</span>'.($m['text']!==''?'<p>'.nl2br(self::e($m['text']),false).'</p>':'').'</li>';$h.='</ol>';
        $fields=['id'=>$id,'revision'=>$r['revision']];
        if($active){
            if($requester||$manager)$h.=self::form($csrf,$fields+['action'=>'comment']).self::note('Add comment',true).'<button>Post comment</button></form>';
            if($assigned&&$r['status']==='in_review'){$h.=self::form($csrf,$fields).self::note('Decision note (required for requested changes or rejection)').'<div class="actions"><button class="primary" name="action" value="approve">Approve</button><button name="action" value="changes">Request changes</button><button name="action" value="reject">Reject</button></div></form>';}
            if($manager&&$r['status']==='approved'&&$eligible)$h.=self::form($csrf,$fields+['action'=>'publish']).self::note('Publication note (optional)').'<button class="primary" data-confirm="Publish exactly the approved snapshot?">Publish approved revision</button></form>';
            if($requester||$manager)$h.=self::form($csrf,$fields+['action'=>'cancel']).'<button data-confirm="Cancel this review?">Cancel request</button></form>';
        }else{$target=$r['kind']==='page'?((RevisionHistory::current($db,$r['snapshot']['page']))[0]??null):$r['target'];$h.='<p>Closed request. '.($target!==null?self::link(self::url(['kind'=>$r['kind'],'target'=>$target]),'Submit a new revision'):'The page has been deleted. '.self::link(WorkspaceUrls::url('/studio/history?page='.$r['snapshot']['page']),'Restore through history')).'</p>';if($role==='owner'&&!in_array($r['status'],ReviewWorkflow::ACTIVE,true))$h.=self::form($csrf,$fields+['action'=>'prune']).'<button data-confirm="Permanently remove this closed request and its discussion?">Remove closed review</button></form>';}
        return $h;
    }
}
