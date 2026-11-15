<?php
declare(strict_types=1);
namespace CajeerBook;

final class CiView
{
    public static function render(array $db,string $csrf,string $notice='',string $secret='',string $selected='',string $preview=''):string{
        $e=Markdown::e(...);$url=$e(WorkspaceUrls::url('/studio/ci'));$form=fn($action)=>'<form method="post" action="'.$url.'"><input type="hidden" name="csrf" value="'.$e($csrf).'"><input type="hidden" name="action" value="'.$e($action).'">';
        $input=fn($name,$value)=>'<input type="hidden" name="'.$e($name).'" value="'.$e($value).'">';
        $out='<h1>CI/CD Publishing</h1><p>Build an exact book/version, validate Markdown, links and media, then publish the whole version atomically. Tree publication is separate. CI tokens cannot approve builds.</p>';
        if($notice!=='')$out.='<p role="status">'.$e($notice).'</p>';if($secret!=='')$out.='<h2>Copy this token now</h2><p>Shown once. Store as a protected CI secret.</p><pre>'.$e($secret).'</pre>';
        $out.='<h2>Service tokens</h2><p>API: <code>'.$e(WorkspaceUrls::url('/api/v1/ci')).'</code></p>'.$form('issue').'<label>Book/version<input name="scope" required placeholder="guide/1.0"></label><label>Label<input name="label" required maxlength="100"></label><label>Valid days<input type="number" name="days" min="1" max="90" value="30" required></label><label><input type="checkbox" name="publish" value="1"> Allow publication</label><button>Create token</button></form>';
        foreach($db['ci']['tokens']??[] as $id=>$t)$out.='<p>'.$e($t['label']).' · '.$e($t['scope']).' · '.($t['publish']?'build + publish':'build only').' · expires '.$e(gmdate('Y-m-d',$t['expires'])).'</p>'.$form('revoke').$input('id',$id).'<button>Revoke</button></form>';
        $scopes=[];foreach($db['pages'] as $key=>$entry)$scopes[PageTree::scope($key)]=true;$out.='<h2>Build saved drafts</h2>';
        foreach(array_keys($scopes) as $scope)$out.=$form('build').$input('scope',$scope).$input('expected',CiPublishing::fingerprint($db,$scope)).'<button>Build '.$e($scope).'</button></form>';
        $out.='<h2>Build and deployment history</h2><p>Policy: '.(ReviewWorkflow::required($db)?'Independent approval required':'Direct publication allowed').'</p>';
        foreach(array_reverse($db['ci']['builds']??[],true) as $id=>$b)$out.='<p><a href="'.$url.'?id='.$id.'">'.$e($b['scope']).' · '.$e(substr($b['digest'],0,12)).'</a> · '.$e($b['status']).' · '.count($b['pages']).' pages · '.$e(gmdate('Y-m-d H:i',$b['created'])).' UTC</p>';
        if(isset($db['ci']['builds'][$selected])){$b=$db['ci']['builds'][$selected];$id=$b['id'];$out.='<h2>Build '.$e($id).'</h2><p>SHA-256: <code>'.$e($b['digest']).'</code></p><p>Source commit: '.$e($b['commit']?:'saved drafts').'; requester: '.$e($b['requester']['username']).'; status: '.$e($b['status']).'</p>';
            if($b['reviewer'])$out.='<p>Approved by '.$e($b['reviewer']['username']).': '.$e($b['note']).'</p>';
            foreach($b['pages'] as $key=>$p)$out.='<details><summary>'.$e($key).' · revision '.$p['revision'].'</summary><h3>'.$e($p['draft']['title']).'</h3><pre>'.$e($p['draft']['body']).'</pre><p><a href="'.$url.'?id='.$id.'&amp;preview='.rawurlencode($key).'">Preview and compare this snapshot</a></p></details>';
            if(isset($b['pages'][$preview])){$p=$b['pages'][$preview];$d=$p['draft'];$out.='<h3>Preview · '.$e($preview).'</h3><article>'.Markdown::render($d['body'],$preview,false,true,$d['link_base']??$preview,PageTree::linkMap($db))['html'].'</article><h3>Previous publication → bundle</h3><div class="history-diff">';foreach(RevisionHistory::diff($p['published']['body']??'', $d['body']) as [$kind,$text])$out.='<pre class="diff-'.$e($kind).'">'.$e($text).'</pre>';$out.='</div>';}
            $fields=$input('id',$id).$input('digest',$b['digest']);
            if($b['status']==='ready')$out.=$form('approve').$fields.'<label>Approval note<input name="note" maxlength="500"></label><button>Approve exact bundle</button></form>';
            if(in_array($b['status'],['ready','approved'],true)){$out.=$form('publish').$fields.'<button>Publish exact bundle</button></form>'.$form('cancel').$fields.'<button>Cancel build</button></form>';}
            else{$out.=$form('prune').$fields.'<button>Prune build</button></form>';if($b['status']==='published')$out.=$form('rollback').$fields.$input('expected',CiPublishing::fingerprint($db,$b['scope'])).'<button>Restore this release to drafts</button></form>';}
        }
        return $out;
    }
}
