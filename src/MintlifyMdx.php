<?php
declare(strict_types=1);
namespace CajeerBook;

/** A bounded, inert conversion from Mintlify's built-in MDX components to Book rich blocks. */
final class MintlifyMdx
{
    private const CONTAINERS=['Note','Info','Tip','Warning','Danger','Check','Success','Steps','Tabs','AccordionGroup','CardGroup','CodeGroup','Frame','Accordion','Step','Tab','Card'];
    public static function convert(string $source,array &$warnings):string
    {
        if(str_contains($source,"\x1e")||str_contains($source,"\x1f"))throw new \InvalidArgumentException('Invalid control character in MDX source');
        if(strlen($source)>Markdown::MAX_BYTES)throw new \InvalidArgumentException('MDX exceeds document size limit');
        [$head,$body]=self::frontmatter($source);
        // Executable MDX is intentionally not run or imported as code.
        if(preg_match('/^\s*(?:import|export)\s+(?:[\w{*]|default)/m',self::withoutFences($body)))throw new \InvalidArgumentException('MDX imports/exports require a static Markdown version');
        if(preg_match('/\{\s*(?:\.\.\.|[a-zA-Z_$][\w$]*\s*(?:\(|\?|\.|\[))/',self::withoutFences($body)))throw new \InvalidArgumentException('Dynamic MDX expressions are not supported; export static content');
        $body=self::markup($body,$warnings);
        $result=$head.$body;
        Markdown::validate($result);return $result;
    }
    private static function frontmatter(string $source):array
    {
        $bom=str_starts_with($source,"\xef\xbb\xbf")?"\xef\xbb\xbf":'';
        if($bom!=='')$source=substr($source,3);
        if(!preg_match('/\A---\s*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s',$source,$m))return ['', $source];
        if(strlen($m[1])>16384)throw new \InvalidArgumentException('Mintlify front matter exceeds 16 KiB');
        $values=[];$key=null;
        foreach(preg_split('/\r?\n/',$m[1]) as $line){
            if(preg_match('/\A([a-zA-Z][a-zA-Z0-9_-]*):\s*(.*?)\s*\z/u',$line,$pair)){
                $key=$pair[1];if(!in_array($key,['title','sidebarTitle','description','slug','order','section','locale'],true))continue;
                $value=$pair[2];if($value===''||str_starts_with($value,'|')||str_starts_with($value,'>'))continue;
                if(str_starts_with($value,'"')){try{$value=json_decode($value,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Invalid Mintlify front matter quotation');}}
                elseif(str_starts_with($value,"'")){if(!preg_match("/\\A'(?:[^']|'')*'\\z/u",$value))throw new \InvalidArgumentException('Invalid Mintlify front matter quotation');$value=str_replace("''","'",substr($value,1,-1));}
                if(!is_string($value)||preg_match('/[\x00-\x1f\x7f]/',$value)||!preg_match('//u',$value))throw new \InvalidArgumentException('Invalid Mintlify front matter value');
                if($key==='sidebarTitle')$key='title';if(isset($values[$key]))continue;
                $values[$key]=$value;
            }
        }
        $head="---\n";foreach($values as $k=>$value)$head.=$k.': '.json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        $head.="---\n";return [$bom.$head,substr($source,strlen($m[0]))];
    }
    private static function withoutFences(string $body):string{return preg_replace('/^ {0,3}(`{3,}|~{3,})[^\r\n]*\n.*?^ {0,3}\1\s*$/ms','',$body)??$body;}
    private static function attrs(string $input):array
    {
        $out=[];preg_match_all('/([a-zA-Z][\w-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|\{([0-9]+)\})/u',$input,$matches,PREG_SET_ORDER);
        foreach($matches as $m)$out[$m[1]]=html_entity_decode($m[2]!==''?$m[2]:($m[3]!==''?$m[3]:$m[4]),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return $out;
    }
    private static function fence(string $info,array $payload):string
    {
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||RichBlocks::parse($info,$json)===null)throw new \InvalidArgumentException('Mintlify component exceeds Book rich-block limits');
        return "\n\n```".$info."\n".$json."\n```\n\n";
    }
    private static function markup(string $body,array &$warnings,int $depth=0):string
    {
        if($depth>RichBlocks::MAX_DEPTH+3)throw new \InvalidArgumentException('Mintlify component nesting is too deep');
        // Keep fenced source blocks byte-for-byte intact, including JSX examples.
        $fences=[];
        $mask=function(array $m)use(&$fences):string{$key="\x1eMINTCODE".count($fences)."\x1f";$fences[$key]=$m[0];return $key;};
        $body=preg_replace_callback('/^ {0,3}(`{3,}|~{3,})[^\r\n]*\n.*?^ {0,3}\1[ \t]*(?=\r?$)/ms',$mask,$body)??$body;
        $body=preg_replace_callback('/<!--.*?-->/s',$mask,$body)??$body;
        $body=preg_replace_callback('/(?<!`)(`+)(?!`).*?(?<!`)\1(?!`)/s',$mask,$body)??$body;
        // Replace component wrappers from the inside; fail on unbalanced supported components.
        $iterations=0;$converted=[];
        while(preg_match('~<('.implode('|',self::CONTAINERS).')\b([^<>]*?)(?<!/)>((?:(?!<\1\b)[\s\S])*?)</\1\s*>~u',$body,$m,PREG_OFFSET_CAPTURE)){
            if(++$iterations>300)throw new \InvalidArgumentException('Too many Mintlify components');
            $tag=$m[1][0];$attr=self::attrs($m[2][0]);$inner=$m[3][0];$replacement=self::component($tag,$attr,strtr($inner,$fences),$warnings,$depth+1);
            $placeholder="\x1eMINTRENDER".count($converted)."\x1f";$converted[$placeholder]=$replacement;
            $body=substr_replace($body,$placeholder,$m[0][1],strlen($m[0][0]));
        }
        // Inner leaf components are not supposed to survive except static HTML wrappers.
        if(preg_match('~</?(?:'.implode('|',self::CONTAINERS).')\b~',$body))throw new \InvalidArgumentException('Unbalanced or unsupported nested Mintlify component');
        $body=preg_replace_callback('~<(?:img|Image)\s+([^<>]*?)/?>~i',static function($m){$a=self::attrs($m[1]);$url=$a['src']??'';if($url===''||(preg_match('/[\x00-\x20<>]/',$url)||str_contains($url,'\\'))||str_starts_with($url,'//'))throw new \InvalidArgumentException('Invalid MDX image URL');return '!['.str_replace([']','['],['\\]','\\['],$a['alt']??'').'](<'.$url.'>)';},$body)??$body;
        $body=preg_replace_callback('~<a\s+([^<>]*?)>([^<>]+)</a>~is',static function($m){$a=self::attrs($m[1]);$href=$a['href']??'';if($href===''||(preg_match('/[\x00-\x20<>]/',$href)||str_contains($href,'\\')))throw new \InvalidArgumentException('Invalid MDX link');return '['.str_replace([']','['],['\\]','\\['],trim($m[2])).'](<'.$href.'>)';},$body)??$body;
        $body=preg_replace('~<br\s*/>~i',"\n",$body)??$body;
        if(preg_match_all('~<([A-Z][A-Za-z0-9]*)\b~',$body,$unknown))foreach(array_unique($unknown[1]) as $tag){if(count($warnings)<1000)$warnings[]=['source'=>'MDX','url'=>'Unsupported component rendered as inert source: '.$tag];}
        foreach($converted as $placeholder=>$fence)$body=str_replace($placeholder,$fence,$body);
        foreach($fences as $placeholder=>$fence)$body=str_replace($placeholder,$fence,$body);
        return $body;
    }
    private static function component(string $tag,array $a,string $inner,array &$warnings,int $depth):string
    {
        if(in_array($tag,['Note','Info','Tip','Warning','Danger','Check','Success'],true)){
            $type=match($tag){'Check','Success'=>'success',default=>strtolower($tag)};
            return self::fence('book-callout',['v'=>1,'type'=>$type,'title'=>$a['title']??'','body'=>trim(self::markup($inner,$warnings,$depth))]);
        }
        if($tag==='Frame'||in_array($tag,['Accordion','Step','Tab','Card'],true))return self::markup($inner,$warnings,$depth);
        $children=match($tag){'Steps'=>'Step','Tabs'=>'Tab','AccordionGroup'=>'Accordion','CardGroup'=>'Card',default=>null};
        if($tag==='CodeGroup'){
            $code=[];preg_match_all('/```([a-zA-Z0-9_+.-]*)[^\r\n]*\n(.*?)\n```/s',$inner,$matches,PREG_SET_ORDER);
            foreach($matches as $i=>$c){$title=$c[1]!==''?$c[1]:'Example '.($i+1);$code[]=['key'=>'code-'.($i+1),'title'=>$title,'language'=>$c[1],'code'=>$c[2]];}
            if(!$code)return self::markup($inner,$warnings,$depth);
            return self::fence('book-code-tabs',['v'=>1,'kind'=>'code-tabs','sync'=>'','items'=>$code]);
        }
        if($children===null)return self::markup($inner,$warnings,$depth);
        $items=[];$cursor=0;
        while(preg_match('~<'.$children.'\b([^<>]*?)>(.*?)</'.$children.'\s*>|<'.$children.'\b([^<>]*?)/>~s',$inner,$m,PREG_OFFSET_CAPTURE,$cursor)){
            if(trim(substr($inner,$cursor,$m[0][1]-$cursor))!=='')throw new \InvalidArgumentException('Unexpected content inside Mintlify '.$tag);
            $attr=self::attrs($m[1][1]>=0?$m[1][0]:$m[3][0]);$text=$m[2][1]>=0?$m[2][0]:'';
            $title=$attr['title']??$attr['name']??('Item '.(count($items)+1));$text=trim(self::markup($text,$warnings,$depth));
            if($tag==='Tabs'){$items[]=['key'=>'tab-'.(count($items)+1),'title'=>$title,'body'=>$text];}
            elseif($tag==='AccordionGroup'){$items[]=['title'=>$title,'body'=>$text,'open'=>false];}
            elseif($tag==='Steps'){$items[]=['title'=>$title,'body'=>$text];}
            else{$href=$attr['href']??'';$img=$attr['img']??$attr['image']??'';
                $items[]=['title'=>$title,'body'=>$text,'href'=>$href,'icon'=>in_array($attr['icon']??'',RichBlocks::ICONS,true)?($attr['icon']??''):'','image'=>$img,'alt'=>$attr['alt']??''];}
            $cursor=$m[0][1]+strlen($m[0][0]);
        }
        if(trim(substr($inner,$cursor))!==''||!$items)throw new \InvalidArgumentException('Malformed Mintlify '.$tag.' children');
        return match($tag){
            'Tabs'=>self::fence('book-tabs',['v'=>1,'kind'=>'tabs','sync'=>'','items'=>$items]),
            'Steps'=>self::fence('book-steps',['v'=>1,'kind'=>'steps','items'=>$items]),
            'AccordionGroup'=>self::fence('book-accordion',['v'=>1,'kind'=>'accordion','items'=>$items]),
            default=>self::fence('book-cards',['v'=>1,'columns'=>max(1,min(4,(int)($a['cols']??$a['columns']??2))),'cards'=>$items])
        };
    }
}
