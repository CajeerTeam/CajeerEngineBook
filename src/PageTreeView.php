<?php
declare(strict_types=1);
namespace CajeerBook;

/** Server-rendered navigation and Studio forms; usable without JavaScript. */
final class PageTreeView
{
    private static function e(string $s): string { return Markdown::e($s); }
    private static function edit(string $key): string { return '/studio?edit='.rawurlencode($key); }
    private static function child(string $key): string { return '/studio?new_under='.rawurlencode($key); }
    private static function scopes(array $pages): array
    { $out=[];foreach($pages as $k=>$p)$out[PageTree::scope($k)]=true;$out=array_keys($out);sort($out,SORT_STRING);return $out; }
    public static function reader(array $pages,string $current): string
    {
        $nodes=PageTree::nodes($pages);$html='';
        $visit=function(string $key)use(&$visit,$nodes,$current):string{
            $n=$nodes[$key];$label=self::e($n['title']);$active=$key===$current;
            $link=$n['page']?'<a href="/docs/'.self::e($key).'"'.($active?' class="active" aria-current="page"':'').'>'.$label.'</a>':'<span>'.$label.'</span>';
            if(!$n['children'])return '<li>'.$link.'</li>';
            $open=$active||str_starts_with($current,$key.'/')||$current==='';
            $out='<li><details'.($open?' open':'').'><summary>'.$label.'</summary>';
            if($n['page'])$out.=$link;
            $out.='<ul>';foreach($n['children'] as $child)$out.=$visit($child);return $out.'</ul></details></li>';
        };
        foreach(self::scopes($pages) as $scope){$html.='<div class="group">'.self::e(str_replace('/',' / ',$scope)).'</div><ul class="reader-tree">';$section=null;
            foreach(PageTree::roots($nodes,$scope) as $root){$next=$pages[$root]['section']??'';if($next!==$section&&$next!=='')$html.='<li class="group">'.self::e($next).'</li>';$section=$next;$html.=$visit($root);}$html.='</ul>';}

        return $html;
    }
    public static function list(array $db,string $current): string
    {
        $pages=PageTree::drafts($db);$nodes=PageTree::nodes($pages);$html='<div class="editor-list"><p><a href="/studio/tree">Manage page tree →</a></p>';
        $visit=function($key)use(&$visit,$nodes,$db,$current):string{
            $n=$nodes[$key];$label=self::e($n['title']);$out='<li>';
            if($n['page'])$out.='<div data-page-key="'.self::e($key).'"><a href="'.self::edit($key).'"'.($current===$key?' aria-current="page"':'').'>'.$label.'</a> <small>'.($db['pages'][$key]['published']!==null?'Published':'Draft').'</small></div>';
            else $out.='<span class="tree-folder">'.$label.'</span>';
            if($n['children']){$out.='<ul>';foreach($n['children'] as $child)$out.=$visit($child);$out.='</ul>';}
            return $out.'</li>';
        };
        foreach(self::scopes($pages) as $scope){$html.='<div class="group">'.self::e($scope).'</div><ul class="editor-tree">';foreach(PageTree::roots($nodes,$scope) as $root)$html.=$visit($root);$html.='</ul>';}
        return $html.'</div>';
    }
    public static function studio(array $db,string $csrf,string $scope,string $notice=''): string
    {
        $pages=PageTree::drafts($db);$scopes=self::scopes($pages);$scope=in_array($scope,$scopes,true)?$scope:($scopes[0]??'');
        $html='<div class="eyebrow">AUTHORING / STRUCTURE</div><h1>Page tree</h1><p>Move, reorder, rename or duplicate a branch. Changes affect drafts until you publish the tree. Publishing the tree never publishes draft content.</p><div class="actions"><a href="/studio">Back to Studio</a><a href="/studio">New page</a></div>';
        if($notice!=='')$html.='<p role="status" class="tree-notice">'.self::e($notice).'</p>';
        if($scope==='')return $html.'<p>No pages yet. Create a page in Studio to start a tree.</p>';
        $html.='<div class="versions" aria-label="Book versions">';foreach($scopes as $s)$html.='<a href="/studio/tree?scope='.rawurlencode($s).'"'.($s===$scope?' class="active" aria-current="page"':'').'>'.self::e($s).'</a>';$html.='</div>';
        $nodes=PageTree::nodes(array_filter($pages,fn($k)=>PageTree::scope($k)===$scope,ARRAY_FILTER_USE_KEY));$roots=PageTree::roots($nodes,$scope);
        $hidden='<input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="token" value="'.PageTree::token($db).'"><input type="hidden" name="scope" value="'.self::e($scope).'">';
        $options='';$option=function($key,$depth)use(&$option,&$options,$nodes){$n=$nodes[$key];$options.='<option value="'.self::e($key).'">'.self::e(str_repeat('· ',$depth).$n['title'].' ('.substr($key,strlen(PageTree::scope($key))+1).')').'</option>';foreach($n['children'] as $child)$option($child,$depth+1);};foreach($roots as $r)$option($r,0);
        $html.='<section data-tree-ui><form method="post" action="/studio/tree" class="editor tree-controls" data-tree-form>'.$hidden.'<h2>Move or duplicate</h2><label>Source branch<select name="source" required>'.$options.'</select></label><label>Destination<select name="target"><option value="">Version root</option>'.$options.'</select></label><label>Position<select name="position"><option value="root">At end of version root</option><option value="before">Before destination</option><option value="after">After destination</option><option value="inside">Inside destination</option></select></label><label>New slug (optional for move; required if the duplicate would collide)<input name="slug" maxlength="64" placeholder="Keep existing slug"></label><div class="actions"><button name="action" value="move" class="primary">Move branch</button><button name="action" value="duplicate">Duplicate branch as drafts</button></div><p data-tree-status role="status" aria-live="polite">Drag a handle onto Before, Inside or After. The form above is the keyboard and touch alternative.</p></form>';
        $html.='<div class="actions"><a href="'.self::child($scope).'">New root page</a><form method="post" action="/studio/tree">'.$hidden.'<button name="action" value="publish" class="primary" data-confirm="Publish navigation for existing public pages? Draft content remains private.">Publish tree</button></form></div><div class="tree-root-drop" data-drop-position="root" data-drop-key="">Drop here to move to end of version root</div><ul class="manage-tree">';
        $visit=function($key)use(&$visit,$nodes,$db):string{
            $n=$nodes[$key];$k=self::e($key);$label=self::e($n['title']);$out='<li data-tree-node="'.$k.'"><div class="tree-node"><span draggable="true" class="tree-handle" data-drag-key="'.$k.'" title="Drag branch" aria-hidden="true">⠿</span><div class="tree-name">';
            if($n['page']){$e=$db['pages'][$key];$out.='<a href="'.self::edit($key).'">'.$label.'</a> <small>'.($e['published']===null?'Draft':'Published').'</small>';if($e['published']!==null&&PageTree::publicKey($key,$e)!==$key)$out.='<small>Public: '.self::e(PageTree::publicKey($key,$e)).'</small>';}
            else $out.='<span class="tree-folder">'.$label.' (folder)</span>';
            $out.='<small>'.self::e(substr($key,strlen(PageTree::scope($key))+1)).'</small></div><a class="tree-child" href="'.self::child($key).'">+ Child</a><button type="button" data-select-source="'.$k.'" aria-label="Select '.$label.' as source">Select</button><div class="tree-drop-controls">';
            foreach(['before'=>'Before','inside'=>'Inside','after'=>'After'] as $pos=>$text)$out.='<button type="button" data-drop-key="'.$k.'" data-drop-position="'.$pos.'" aria-label="'.$text.' '.$label.'">'.$text.'</button>';
            $out.='</div></div>';
            if($n['children']){$out.='<details open><summary>Children ('.count($n['children']).')</summary><ul>';foreach($n['children'] as $child)$out.=$visit($child);$out.='</ul></details>';}
            return $out.'</li>';
        };
        foreach($roots as $r)$html.=$visit($r);
        return $html.'</ul></section>';
    }
    public static function newUnder(array $db,string $parent): ?array
    {
        $pages=PageTree::drafts($db);$nodes=PageTree::nodes($pages);$scope=PageTree::scope($parent);
        if(!in_array($scope,self::scopes($pages),true)||($parent!==$scope&&!isset($nodes[$parent])))return null;
        $suffix='new-page';$n=1;while(isset($nodes[$parent.'/'.$suffix])||PageTree::reserved($db,$parent.'/'.$suffix,''))$suffix='new-page-'.(++$n);
        $key=$parent.'/'.$suffix;return PageTree::validKey($key)&&PageTree::depth($key)<=PageTree::MAX_DEPTH?explode('/',$key,3):null;
    }
}
