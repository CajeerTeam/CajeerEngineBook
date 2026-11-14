<?php
declare(strict_types=1);
namespace CajeerBook;

/** Versioned, inert JSON payloads inside reserved CommonMark fenced blocks. */
final class RichBlocks
{
    public const TYPES = ['note','info','tip','warning','danger','success'];
    public const ICONS = ['','book','code','link','download','rocket','check','info'];
    public const MAX_CARDS = 12;
    public const MAX_BUDGET = 96;
    public static function reserved(string $info): bool { return in_array($info,['book-callout','book-cards','book-tabs','book-code-tabs','book-accordion','book-steps'],true); }
    public const INTERACTIVE = ['tabs','code-tabs','accordion','steps'];
    public const MAX_ITEMS = 12;
    public const MAX_DEPTH = 3;
    public static function kind(array $data): string { return $data['kind'] ?? (isset($data['cards']) ? 'cards' : 'callout'); }
    public static function interactive(array $data): bool { return in_array(self::kind($data),self::INTERACTIVE,true); }
    private static function text(mixed $s,int $max,bool $multiline=false): bool
    {
        return is_string($s) && strlen($s)<=$max && preg_match('//u',$s)===1
            && !preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/',$s);
    }
    private static function fields(array $a,array $keys): bool
    {
        return count($a)===count($keys) && !array_diff($keys,array_keys($a));
    }
    /** Invalid or unsupported payloads render as ordinary code, never as executable markup. */
    public static function parse(string $info,string $literal): ?array
    {
        if (!self::reserved($info) || strlen($literal)>Markdown::MAX_BYTES) return null;
        try { $d=json_decode($literal,true,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { return null; }
        if (!is_array($d) || ($d['v']??null)!==1) return null;
        if (in_array(substr($info,5),self::INTERACTIVE,true)) return self::parseInteractive(substr($info,5),$d);
        if ($info==='book-callout') {
            if (!self::fields($d,['v','type','title','body']) || !in_array($d['type'],self::TYPES,true)
                || !self::text($d['title'],512) || !self::text($d['body'],65536,true)) return null;
            return $d;
        }
        if (!self::fields($d,['v','columns','cards']) || !in_array($d['columns'],[1,2,3,4],true)
            || !is_array($d['cards']) || !array_is_list($d['cards']) || count($d['cards'])<1 || count($d['cards'])>self::MAX_CARDS) return null;
        foreach ($d['cards'] as $c) {
            if (!is_array($c) || !self::fields($c,['title','body','href','icon','image','alt'])
                || !self::text($c['title'],512) || trim($c['title'])==='' || !self::text($c['body'],65536,true)
                || !self::text($c['href'],2048) || !self::text($c['image'],2048) || !self::text($c['alt'],512)
                || !in_array($c['icon'],self::ICONS,true)) return null;
            foreach (['href','image'] as $field) if ($c[$field]!=='' && (!self::validUrl($c[$field],$field==='image'))) return null;
        }
        return $d;
    }
    private static function parseInteractive(string $kind,array $d): ?array
    {
        $tabs=in_array($kind,['tabs','code-tabs'],true);
        if (!self::fields($d,$tabs?['v','kind','sync','items']:['v','kind','items']) || $d['kind']!==$kind
            || !is_array($d['items']) || !array_is_list($d['items']) || count($d['items'])<1 || count($d['items'])>self::MAX_ITEMS) return null;
        if ($tabs && (!is_string($d['sync']) || !preg_match('/\A[a-z0-9-]{0,64}\z/D',$d['sync']))) return null;
        $keys=[];
        foreach ($d['items'] as $item) {
            $fields=match($kind){'tabs'=>['key','title','body'],'code-tabs'=>['key','title','language','code'],'accordion'=>['title','body','open'],default=>['title','body']};
            if (!is_array($item) || !self::fields($item,$fields) || !self::text($item['title'],512) || trim($item['title'])==='') return null;
            if ($tabs) {
                if (!is_string($item['key']) || !preg_match('/\A[a-z0-9-]{1,64}\z/D',$item['key']) || isset($keys[$item['key']])) return null;
                $keys[$item['key']]=true;
            }
            if ($kind==='code-tabs') {
                if (!is_string($item['language']) || !preg_match('/\A[a-zA-Z0-9_+.-]{0,64}\z/D',$item['language']) || !self::text($item['code'],65536,true)) return null;
            } elseif (!self::text($item['body'],65536,true)) return null;
            if ($kind==='accordion' && !is_bool($item['open'])) return null;
        }
        return $d;
    }
    public static function validUrl(string $url,bool $image): bool
    {
        if ($url==='' || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || str_starts_with($url,'//') || str_starts_with($url,'/studio/')) return false;
        if ($image && preg_match('/^[#?]/',$url)) return false;
        if (preg_match('/\A([a-z][a-z0-9+.-]*):/i',$url,$m)) {
            if (!$image && strtolower($m[1])==='mailto') return strlen($url)>7;
            return preg_match('~\Ahttps?://[^/?#]+~i',$url)===1;
        }
        return true;
    }
    public static function cost(array $data): int { return isset($data['items']) ? count($data['items']) : (isset($data['cards']) ? count($data['cards']) : 1); }
    public static function bodies(array $data): array { return isset($data['items']) ? (self::kind($data)==='code-tabs' ? array_fill(0,count($data['items']),'') : array_column($data['items'],'body')) : (isset($data['cards']) ? array_column($data['cards'],'body') : [$data['body']]); }
    /** $bodyHtml and $resolve originate exclusively from the shared Markdown renderer. */
    public static function html(array $data,array $bodyHtml,callable $resolve): string
    {
        $e=Markdown::e(...);
        if (self::interactive($data)) return self::interactiveHtml($data,$bodyHtml);
        if (!isset($data['cards'])) {
            $type=$data['type']; $title=$data['title']==='' ? ucfirst($type) : $data['title'];
            $icon=['note'=>'▤','info'=>'ⓘ','tip'=>'✦','warning'=>'⚠','danger'=>'!','success'=>'✓'][$type];
            return '<aside class="book-callout book-callout-'.$type.'" aria-label="'.$e($title).'"><div class="book-callout-title"><span aria-hidden="true">'.$icon.'</span> '.$e($title).'</div><div class="book-rich-body">'.$bodyHtml[0].'</div></aside>';
        }
        $html='<div class="book-card-grid book-card-cols-'.$data['columns'].'">';
        $icons=['book'=>'▤','code'=>'&lt;/&gt;','link'=>'↗','download'=>'↓','rocket'=>'✦','check'=>'✓','info'=>'ⓘ'];
        foreach ($data['cards'] as $i=>$c) {
            $href=$c['href']===''?null:$resolve($c['href'],false);
            $image=$c['image']===''?null:$resolve($c['image'],true);
            $html.='<section class="book-card">';
            if ($image!==null) $html.='<img class="book-card-image" src="'.$e($image).'" alt="'.$e($c['alt']).'" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
            $html.='<div class="book-card-content"><h3 class="book-card-title">';
            if ($c['icon']!=='') $html.='<span class="book-card-icon" aria-hidden="true">'.$icons[$c['icon']].'</span> ';
            $html.=$href===null?$e($c['title']):'<a href="'.$e($href).'">'.$e($c['title']).' <span aria-hidden="true">↗</span></a>';
            $html.='</h3><div class="book-rich-body">'.$bodyHtml[$i].'</div></div></section>';
        }
        return $html.'</div>';
    }
    private static function interactiveHtml(array $data,array $bodyHtml): string
    {
        $e=Markdown::e(...); $kind=self::kind($data);
        if ($kind==='tabs' || $kind==='code-tabs') {
            // All panels and their headings are readable before JS enhancement.
            $html='<div class="book-tabs" data-book-tabs data-sync="'.$e($data['sync']).'">';
            foreach ($data['items'] as $i=>$item) {
                $body=$bodyHtml[$i];
                if ($kind==='code-tabs') {
                    $lang=$e($item['language']);
                    $body='<pre><code'.($lang!==''?' class="language-'.$lang.'" data-language="'.$lang.'"':'').'>'.$e($item['code']).'</code></pre>';
                }
                $html.='<section class="book-tab-panel" data-tab-panel data-tab-key="'.$e($item['key']).'"><h3 data-tab-title>'.$e($item['title']).'</h3><div class="book-rich-body">'.$body.'</div></section>';
            }
            return $html.'</div>';
        }
        $html=$kind==='steps'?'<ol class="book-steps">':'<div class="book-accordion">';
        foreach ($data['items'] as $i=>$item) {
            $title=$e($item['title']);$body='<div class="book-rich-body">'.$bodyHtml[$i].'</div>';
            $html.=$kind==='steps'?'<li><h3>'.$title.'</h3>'.$body.'</li>':'<details class="book-accordion-item"'.($item['open']?' open':'').'><summary>'.$title.'</summary>'.$body.'</details>';
        }
        return $html.($kind==='steps'?'</ol>':'</div>');
    }
}
