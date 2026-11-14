<?php
declare(strict_types=1);
namespace CajeerBook;

/** Server-rendered history; no client-side trust or privileged public endpoints. */
final class HistoryView
{
    private static function e(string $s):string{return Markdown::e($s);}
    private static function url(array $params=[]):string{return WorkspaceUrls::url('/studio/history'.($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):''));}
    private static function link(string $url,string $label):string{return '<a href="'.self::e($url).'">'.self::e($label).'</a>';}
    private static function field(string $name,string $value):string{return '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';}
    private static function label(array $r):string{return 'r'.$r['revision'].' · '.$r['action'].' · '.($r['actor']['username']??'System / imported').' · '.$r['at'];}
    private static function param(array $q,string $key,string $default=''):string{return is_string($q[$key]??null)?$q[$key]:$default;}
    public static function render(array $db,string $csrf,array $q):string{
        $page=self::param($q,'page');$html='<div class="eyebrow">AUTHORING / HISTORY</div><h1>Revision history</h1><div class="actions">'.self::link(WorkspaceUrls::url('/studio'),'Studio').self::link(self::url(),'All pages').'</div><p>Retained snapshots are private. Restore creates a new draft; published content and URLs are unchanged. Up to 50 entries per page, 2,000 entries and 8 MiB per workspace; oldest entries expire.</p>';
        if($page===''){
            $list=[];foreach($db['pages'] as $key=>$e)$list[PageTree::id($key,$e)]=['key'=>$key,'title'=>$e['draft']['title'],'deleted'=>false];
            foreach(array_reverse($db['history']??[],true) as $r)if(!isset($list[$r['page']]))$list[$r['page']]=['key'=>$r['key'],'title'=>$r['draft']['title'],'deleted'=>true];
            uasort($list,fn($a,$b)=>[$a['key'],$a['deleted']]<=>[$b['key'],$b['deleted']]);
            $filter=substr(self::param($q,'q'),0,200);$list=array_filter($list,fn($r)=>$filter===''||stripos($r['key'].' '.$r['title'],$filter)!==false);
            $offset=max(0,min(count($list), (int)self::param($q,'offset','0')));$html.='<form method="get" action="'.self::e(self::url()).'" class="actions"><label>Find page <input name="q" value="'.self::e($filter).'"></label><button>Search</button></form><p>'.count($list).' pages</p><ul class="history-list">';
            foreach(array_slice($list,$offset,50,true) as $id=>$r)$html.='<li>'.self::link(self::url(['page'=>$id]),$r['title']).' <span class="muted">'.self::e($r['key']).($r['deleted']?' · Deleted':'').'</span></li>';
            $html.='</ul><div class="actions">';if($offset>0)$html.=self::link(self::url(['q'=>$filter,'offset'=>max(0,$offset-50)]),'Previous');if($offset+50<count($list))$html.=self::link(self::url(['q'=>$filter,'offset'=>$offset+50]),'Next');return $html.'</div>';
        }
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$page))throw new \InvalidArgumentException('Invalid page ID');
        $current=RevisionHistory::current($db,$page);$list=RevisionHistory::entries($db,$page);if(!$current&&!$list){http_response_code(404);return $html.'<p>Page history not found.</p>';}
        $last=$list?reset($list):null;$key=$current?$current[0]:$last['key'];$html.='<h2>'.self::e($current?$current[1]['draft']['title']:$last['draft']['title']).'</h2><p><code>'.self::e($key).'</code>'.($current?' · '.self::link(WorkspaceUrls::url('/studio?edit='.rawurlencode($key)),'Edit current draft'):' · Deleted page').'</p>';
        if(!$list)return $html.'<p>No historical changes yet. The original snapshot is recorded on the first content or structure change.</p>';
        $id=self::param($q,'id',array_key_first($list));$r=RevisionHistory::get($db,$page,$id);
        $state=self::param($q,'state','draft');if(!in_array($state,['draft','published'],true)||!is_array($r[$state]??null))throw new \InvalidArgumentException('Snapshot not available');
        $p=$r[$state];$against=self::param($q,'against','current');$other=null;$otherLabel='Current draft';
        if($against==='current')$other=$current[1]['draft']??null;else{$comparison=RevisionHistory::get($db,$page,$against);$other=$comparison[$state]??null;$otherLabel=self::label($comparison);}
        $html.='<form method="get" action="'.self::e(self::url()).'" class="history-controls">'.self::field('page',$page).'<label>Historical revision<select name="id">';
        foreach($list as $rid=>$item)$html.='<option value="'.$rid.'"'.($rid===$id?' selected':'').'>'.self::e(self::label($item)).'</option>';
        $html.='</select></label><label>Snapshot<select name="state"><option value="draft"'.($state==='draft'?' selected':'').'>Draft</option><option value="published"'.($state==='published'?' selected':'').'>Published (if available)</option></select></label><label>Compare against<select name="against"><option value="current"'.($against==='current'?' selected':'').'>Current draft</option>';
        foreach($list as $rid=>$item)$html.='<option value="'.$rid.'"'.($rid===$against?' selected':'').'>'.self::e(self::label($item)).'</option>';
        $html.='</select></label><button>Compare</button></form><h3>'.self::e(self::label($r)).'</h3>'.(($r['note']??'')!==''?'<p>Note: '.self::e($r['note']).'</p>':'').'<p>Historical path: <code>'.self::e($r['key']).'</code> · '.self::e($state).($r['deleted']?' · Deleted':'').'</p><p>Title: '.self::e($p['title']).' · Section: '.self::e($p['section']).' · Order: '.$p['order'].'</p>';
        if(isset($r['source_revision']))$html.='<p>Restored from: '.(isset($list[$r['source_revision']])?self::link(self::url(['page'=>$page,'id'=>$r['source_revision']]),$r['source_revision']):self::e($r['source_revision']).' (expired)').'</p>';
        if($other!==null){$html.='<h3>Changes: selected → '.self::e($otherLabel).'</h3><p>Title: '.self::e($p['title']).' → '.self::e($other['title']).' · Section: '.self::e($p['section']).' → '.self::e($other['section']).' · Order: '.$p['order'].' → '.$other['order'].'</p><div class="history-diff" aria-label="Markdown diff">';foreach(RevisionHistory::diff($p['body'],$other['body']) as [$kind,$text])$html.='<pre class="diff-'.$kind.'"><span aria-hidden="true">'.match($kind){'add'=>'+ ','remove'=>'− ',default=>'  '}.'</span>'.self::e($text).'</pre>';$html.='</div>';}
        else $html.='<p>No comparison snapshot available.</p>';
        $params=['page'=>$page,'id'=>$id,'state'=>$state,'against'=>$against];$html.='<h3>Original Markdown</h3><pre class="history-source"><code>'.self::e($p['body']).'</code></pre><p>'.self::link(self::url($params+['preview'=>'1']),'Render historical preview').'</p>';
        if(self::param($q,'preview')==='1'){$base=$p['link_base']??($state==='published'?$r['public_key']:$r['key']);$html.='<h3>Historical preview</h3><article>'.Markdown::render($p['body'],$r['key'],false,true,$base,PageTree::linkMap($db))['html'].'</article>';}
        $html.='<form method="post" action="'.self::e(self::url()).'" class="actions">'.self::field('csrf',$csrf).self::field('action','restore').self::field('page',$page).self::field('id',$id).self::field('state',$state).self::field('head',array_key_first($list)).self::field('revision',(string)($current[1]['revision']??0)).'<label>Restore note (optional)<input name="note" maxlength="500"></label><button class="primary" data-confirm="Restore this snapshot as a new draft?">Restore as draft</button></form>';
        return $html;
    }
}
