<?php
declare(strict_types=1);
namespace CajeerBook;

/** Compile only known static Fumadocs components to Book rich blocks. Never evaluate JSX. */
final class FumadocsMdx
{
    public static function convert(string $source,array &$warnings):string{
        if(strlen($source)>Markdown::MAX_BYTES)throw new \InvalidArgumentException('Fumadocs MDX exceeds document limit');
        // Keep source code, including JSX examples, byte-for-byte intact.
        $saved=[];$marker='BOOKFUMACODE'.bin2hex(random_bytes(8));
        $source=preg_replace_callback('/^ {0,3}(`{3,}|~{3,})[^\r\n]*\n.*?^ {0,3}\1[ \t]*(?=\r?$)/ms',static function(array $m)use(&$saved,$marker){$key=$marker.count($saved).'END';$saved[$key]=$m[0];return $key;},$source)??$source;
        $source=preg_replace_callback('~<Callout\b([^<>]*?)>(.*?)</Callout\s*>~s',static function(array $m){
            $attributes=$m[1];if(preg_match('/\{/', $attributes))throw new \InvalidArgumentException('Dynamic Fumadocs Callout attributes are unsupported');
            $type='info';if(preg_match('/\btype\s*=\s*[\'\"]([^\'\"]+)[\'\"]/',$attributes,$a))$type=strtolower($a[1]);
            $tag=match($type){'error','danger'=>'Danger','warn','warning'=>'Warning','success'=>'Success','tip'=>'Tip','note'=>'Note',default=>'Info'};
            $title='';if(preg_match('/\btitle\s*=\s*([\'\"])(.*?)\1/s',$attributes,$t))$title=' title='.json_encode($t[2],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            return '<'.$tag.$title.'>'.$m[2].'</'.$tag.'>';
        },$source)??$source;
        if(preg_match('~</?Callout\b~',$source))throw new \InvalidArgumentException('Unbalanced Fumadocs Callout');
        // Fumadocs uses Tabs items={["...", "..."]} and Tab value="...".
        // The items expression is accepted only when it is a literal JSON/JS string array.
        $source=preg_replace_callback('~<Tabs\b([^<>]*?)>~s',static function(array $m){
            $attrs=$m[1];if(preg_match('/\bitems\s*=\s*\{(\[[^{}]*\])\}/s',$attrs,$list)){
                $raw=preg_replace('/\x27([^\x27\\r\\n]*)\x27/u','"$1"',$list[1]);
                try{$items=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException('Fumadocs tabs must have a literal list of labels');}
                if(!is_array($items)||!array_is_list($items)||count($items)>20)throw new \InvalidArgumentException('Invalid Fumadocs tab labels');
                foreach($items as $item)if(!is_string($item)||strlen($item)>100)throw new \InvalidArgumentException('Invalid Fumadocs tab label');
                $attrs=str_replace($list[0],'',$attrs);
            }
            if(str_contains($attrs,'{'))throw new \InvalidArgumentException('Dynamic Fumadocs Tabs props are unsupported');
            return '<Tabs>';
        },$source)??$source;
        $source=preg_replace_callback('~<Tab\b([^<>]*?)>~s',static function(array $m){
            $attrs=$m[1];if(str_contains($attrs,'{'))throw new \InvalidArgumentException('Dynamic Fumadocs Tab props are unsupported');
            if(!preg_match('/\btitle\s*=\s*([\'\"])(.*?)\1/s',$attrs,$title)&&preg_match('/\bvalue\s*=\s*([\'\"])(.*?)\1/s',$attrs,$value))return '<Tab title='.json_encode($value[2],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).'>';
            return '<Tab'.$attrs.'>';
        },$source)??$source;
        $source=preg_replace('~<Cards(?=\s|>)~','<CardGroup',$source)??$source;
        $source=preg_replace('~</Cards\s*>~','</CardGroup>',$source)??$source;
        $source=preg_replace('~<Accordions(?=\s|>)~','<AccordionGroup',$source)??$source;
        $source=preg_replace('~</Accordions\s*>~','</AccordionGroup>',$source)??$source;
        if(preg_match('~<(?:CardGroup|AccordionGroup|Step|Steps|Card|Accordion)\b[^<>]*\{~',$source))throw new \InvalidArgumentException('Dynamic component attributes must be rendered before import');
        // Render known Fumadocs components using the same strict, bounded Book converters.
        $converted=MintlifyMdx::convert($source,$warnings);
        foreach($saved as $key=>$literal)$converted=str_replace($key,$literal,$converted);
        Markdown::validate($converted);
        return $converted;
    }
}
