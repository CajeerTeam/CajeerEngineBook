<?php
declare(strict_types=1);
namespace CajeerBook;

/** No audit data is returned until the live identity transaction authorizes it. */
final class AuditView
{
    private static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function render(array $result,array $filters):string{
        $html='<h1>Enterprise Audit Log</h1><p>Events are retained independently of notifications. All times are UTC. Only scopes you administer are shown.</p><form class="editor" method="get" action="/audit">';
        foreach(['action'=>'Action (e.g. page.publish)','actor'=>'Actor ID','organization'=>'Organization ID','workspace'=>'Workspace ID','from'=>'From (UTC)','to'=>'Through (UTC)'] as $k=>$label)$html.='<label>'.self::e($label).'<input name="'.$k.'" type="'.(in_array($k,['from','to'],true)?'date':'text').'" maxlength="64" value="'.self::e($filters[$k]??'').'"></label>';
        $html.='<label>Outcome<select name="outcome"><option value="">All</option>';foreach(['success','denied','limited','failure'] as $o)$html.='<option'.(($filters['outcome']??'')===$o?' selected':'').'>'.$o.'</option>';
        $html.='</select></label><button type="submit">Filter</button><a href="/audit">Clear filters</a></form><form method="post" action="/audit"><input type="hidden" name="csrf" value="'.self::e($result['csrf']).'">';
        foreach($filters as $k=>$v)$html.='<input type="hidden" name="'.$k.'" value="'.self::e($v).'">';
        $html.='<div class="actions"><button name="format" value="jsonl">Export JSONL (up to 5000)</button><button name="format" value="csv">Export CSV (up to 5000)</button></div></form><p>Exports contain the current filtered window. Continue with the cursor if more records exist.</p>';
        if(!$result['events'])$html.='<p>No matching events.</p>';
        foreach($result['events'] as $r){$e=$r['event'];$html.='<div class="account-row"><strong>'.self::e($e['action']).'</strong> · '.self::e($e['outcome']).' · '.gmdate('Y-m-d H:i:s',$e['at']).' UTC<details><summary>Event details</summary><dl>';
            foreach(['id'=>'Event','actor'=>'Actor','target'=>'Target','organization'=>'Organization','workspace'=>'Workspace','request'=>'Request','code'=>'Code','count'=>'Affected page count'] as $k=>$label)$html.='<dt>'.$label.'</dt><dd><code>'.self::e($e[$k]!==''?(string)$e[$k]:'—').'</code></dd>';
            $html.='<dt>Stream / sequence</dt><dd>'.self::e($r['stream']).' / '.$r['sequence'].'</dd><dt>Previous hash</dt><dd><code>'.self::e($r['previous_hash']).'</code></dd><dt>SHA-256</dt><dd><code>'.self::e($r['hash']).'</code></dd></dl></details></div>';
        }
        if($result['next']!==null)$html.='<p><a href="/audit?'.self::e(http_build_query(array_replace($filters,['before'=>$result['next']]),'', '&',PHP_QUERY_RFC3986)).'">Older events</a></p>';
        return $html;
    }
    public static function encode(array $events,string $format):string{
        if($format==='jsonl'){$out='';foreach($events as $r)$out.=json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";return $out;}
        if($format!=='csv')throw new \InvalidArgumentException('Unknown export format.');
        $f=fopen('php://temp','w+b');if(!$f)throw new \RuntimeException('Cannot allocate audit export.');
        try{$fields=['cursor','stream','sequence','previous_hash','hash','id','at','action','outcome','actor','target','organization','workspace','request','code','count'];fputcsv($f,$fields,',','"','');foreach($events as $r){$row=$r+$r['event'];$row['at']=gmdate('c',$row['at']);$values=[];foreach($fields as $k){$v=(string)$row[$k];if(preg_match('/\A[=+\-@\t\r]/',$v))$v="'".$v;$values[]=$v;}if(fputcsv($f,$values,',','"','')===false)throw new \RuntimeException('Audit export write failed.');}rewind($f);$s=stream_get_contents($f);if($s===false)throw new \RuntimeException('Audit export read failed.');return $s;}finally{fclose($f);}
    }
}
