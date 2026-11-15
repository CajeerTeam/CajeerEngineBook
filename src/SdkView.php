<?php
declare(strict_types=1);
namespace CajeerBook;
final class SdkView
{
    private static function e(string $s):string{return Markdown::e($s);}
    public static function markup(array $m,string $key,string $language,int $server=0,int $security=0):string{
        $url=WorkspaceUrls::url('/sdk?key='.rawurlencode($key));$d=$m['definitions'][$m['selected']];$examples=SdkBuilder::examples($m,$server,$security);
        $out='<div class="eyebrow">API REFERENCE / SDK</div><h1>SDK &amp; Code Examples</h1><p><a href="'.self::e(WorkspaceUrls::url('/docs/'.$key)).'">Return to operation</a></p><h2>'.self::e($d['method'].' '.$d['path']).'</h2><p>Generated from '.count($m['operations']).' supported published operations in this reference. Packages contain no API credentials. Replace example values and credentials before running.</p><p>Publication digest: <code>'.self::e($m['version']).'</code></p><div class="actions">';
        foreach(['curl'=>'cURL','typescript'=>'TypeScript / JavaScript','python'=>'Python','php'=>'PHP'] as $l=>$label)$out.='<a class="'.($language===$l?'active':'').'" href="'.self::e($url.'&lang='.$l.'&server='.$server.'&security='.$security).'">'.self::e($label).'</a> ';
        $out.='</div><form method="get" action="'.self::e(WorkspaceUrls::url('/sdk')).'" class="playground"><input type="hidden" name="key" value="'.self::e($key).'"><input type="hidden" name="lang" value="'.self::e($language).'"><label>Example server<select name="server">';
        foreach($d['servers'] as $i=>$base)$out.='<option value="'.$i.'"'.($server===$i?' selected':'').'>'.self::e($base).'</option>';
        $out.='</select></label><label>Authentication alternative<select name="security">';foreach($d['security'] as $i=>$alt)$out.='<option value="'.$i.'"'.($security===$i?' selected':'').'>'.self::e($alt?implode(' + ',array_column($alt,'name')):'No authentication').'</option>';
        $out.='</select></label><button type="submit">Update example</button></form><h3>Request example</h3><pre><code>'.self::e($examples[$language]).'</code></pre><p>Examples use placeholders and may modify real API data. Run only against an appropriate API environment.</p><h2>Download SDK</h2><div class="actions">';
        foreach(SdkBuilder::LANGUAGES as $l)$out.='<a href="'.self::e($url.'&lang='.$l.'&download=1&digest='.$m['version']).'">'.self::e(ucfirst($l)).' ZIP</a> ';
        $out.='</div><h2>Included operations</h2><ul>';foreach($m['operations'] as $n=>$op)$out.='<li><a href="'.self::e(WorkspaceUrls::url('/sdk?key='.rawurlencode($op['key']).'&lang='.$language)).'">'.self::e($op['definition']['method'].' '.$op['definition']['path']).'</a> — <code>'.self::e($n).'</code></li>';
        return $out.'</ul><p>SDK clients call the published HTTPS API directly, not the managed Playground proxy. Browser calls require CORS. Unsupported OpenAPI operations remain in Reference but are not included in the SDK.</p>';
    }
}
