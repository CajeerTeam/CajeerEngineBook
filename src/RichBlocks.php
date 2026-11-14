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
    public static function reserved(string $info): bool { return in_array($info,['book-callout','book-cards'],true); }
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
    public static function cost(array $data): int { return isset($data['cards']) ? count($data['cards']) : 1; }
    public static function bodies(array $data): array { return isset($data['cards']) ? array_column($data['cards'],'body') : [$data['body']]; }
    /** $bodyHtml and $resolve originate exclusively from the shared Markdown renderer. */
    public static function html(array $data,array $bodyHtml,callable $resolve): string
    {
        $e=Markdown::e(...);
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
}
