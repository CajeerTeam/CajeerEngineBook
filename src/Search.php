<?php
declare(strict_types=1);
namespace CajeerBook;

/** Shared search input, published projection, and portable-mode search. */
final class Search
{
    public static function options(array $input): array
    {
        $q=$input['q']??'';
        if(!is_string($q)||!preg_match('//u',$q))$q='';
        $q=trim(self::cut($q,200));
        $filters=[];
        foreach(['book','version'] as $k){$v=$input[$k]??'';if(!is_string($v)||($v!==''&&!BookStore::slug($v)))throw new \InvalidArgumentException('Invalid search filter.');$filters[$k]=$v;}
        $number=static function($v,int $min,int $max):int{if(!is_string($v)||!preg_match('/\A[0-9]{1,5}\z/D',$v)||(int)$v<$min||(int)$v>$max)throw new \InvalidArgumentException('Invalid search pagination.');return (int)$v;};
        $groups=self::groups($q);
        return ['q'=>$q,'groups'=>$groups,'book'=>$filters['book'],'version'=>$filters['version'],'limit'=>$number($input['limit']??'50',1,50),'offset'=>$number($input['offset']??'0',0,1000)];
    }
    public static function cut(string $text,int $bytes):string
    {
        $text=substr($text,0,$bytes);while($text!==''&&!preg_match('//u',$text))$text=substr($text,0,-1);return $text;
    }
    public static function groups(string $q): array
    {
        preg_match_all('/"([^"\r\n]+)"|([^\s"]+)/u',$q,$m,PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
        $groups=[];$count=0;
        foreach($m as $part){preg_match_all('/[\p{L}\p{N}\p{M}]+/u',$part[1]??$part[2],$tokens);$words=$tokens[0];if(!$words)continue;$count+=count($words);if($count>24)throw new \InvalidArgumentException('Search supports at most 24 terms.');$groups[]=['words'=>$words,'phrase'=>$part[1]!==null];}
        return $groups;
    }
    /** Only normalized alphanumeric tokens enter tsquery syntax; parameters still use PDO. */
    public static function query(array $groups,bool $prefix):string
    {
        $parts=[];foreach($groups as $g){$words=[];foreach($g['words'] as $w)$words[]="'".$w."'".($prefix&&!$g['phrase']&&!preg_match('/^.$/us',$w)?':*':'');$parts[]='('.implode($g['phrase']?' <-> ':' & ',$words).')';}return implode(' & ',$parts);
    }
    public static function text(string $html):string
    {
        // Renderer escapes raw HTML; do not index URLs, attributes, or hidden script/template payloads.
        $html=preg_replace('~<(script|style|template)\b[^>]*>.*?</\1\s*>~is',' ',$html)??'';
        $html=preg_replace('~</?(?:p|div|li|ul|ol|h[1-6]|pre|br|td|th|tr|section|summary|details|figcaption|blockquote|hr)\b[^>]*>~i',' ',$html)??'';
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return trim(preg_replace('/[\s\x00-\x1f\x7f]+/u',' ',$text)??'');
    }
    public static function projection(array $db):array
    {
        $books=BookStore::books($db);$rows=[];
        foreach(BookStore::published($db) as $key=>$p){[$b,$v]=explode('/',$key,3);$rows[$key]=['title'=>$p['title'],'section'=>$p['section'],'order'=>$p['order'],'body'=>$p['body'],'book'=>$b,'version'=>$v,'public'=>Visibility::publicBook($books[$b],'public'),'link_base'=>$p['link_base']??null];}
        return $rows;
    }
    public static function plain(string $key,array $p):string
    {
        return self::text(Markdown::render($p['body'],$key,false,false,$p['link_base']??null)['html']);
    }
    public static function portable(array $db,string $visibility,string $role,array $o,bool $navigation):array
    {
        $pages=Visibility::pages($db,$visibility,$role);$hits=[];
        if($o['groups'])foreach($pages as $key=>$p){[$b,$v]=explode('/',$key,3);if(($o['book']!==''&&$b!==$o['book'])||($o['version']!==''&&$v!==$o['version']))continue;
            $body=self::plain($key,$p);$score=0;$first=null;$match=true;
            foreach($o['groups'] as $g){$parts=array_map(fn($w)=>preg_quote($w,'~'),$g['words']);$pattern=$g['phrase']?'(?<![\p{L}\p{N}\p{M}])'.implode('[^\p{L}\p{N}\p{M}]+',$parts).'(?![\p{L}\p{N}\p{M}])':null;
                foreach($pattern!==null?[$pattern]:array_map(fn($w)=>'(?<![\p{L}\p{N}\p{M}])'.$w.(preg_match('/^.$/us',$w)?'(?![\p{L}\p{N}\p{M}])':''),$parts) as $pat){$re='~'.$pat.'~iu';$found=false;foreach([[$p['title'],8],[$key.' '.$p['section'],4],[$body,1]] as [$field,$weight])if(preg_match($re,$field,$m,PREG_OFFSET_CAPTURE)){$score+=$weight;$found=true;if($weight===1&&$first===null)$first=$m[0][1];}if(!$found){$match=false;break 2;}}
            }
            if(!$match)continue;$start=$first===null?0:max(0,$first-80);while($start>0&&(ord($body[$start])&0xc0)===0x80)$start--;$snippet=self::cut(substr($body,$start),320);$hits[]=['key'=>$key,'title'=>$p['title'],'snippet'=>($start?'…':'').$snippet.(strlen($body)>$start+strlen($snippet)?'…':''),'score'=>$score];
        }
        usort($hits,fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['key'],$b['key']));
        return ['hits'=>array_slice($hits,$o['offset'],$o['limit']),'total'=>count($hits),'pages'=>$navigation?$pages:[],'engine'=>'portable','match'=>'fulltext'];
    }
}
