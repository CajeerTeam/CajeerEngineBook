<?php
declare(strict_types=1);
namespace CajeerBook;

/** Convert static Docusaurus MDX/admonitions into inert Book rich blocks. */
final class DocusaurusMdx
{
    public static function convert(string $source,array &$warnings):string
    {
        if(strlen($source)>Markdown::MAX_BYTES)throw new \InvalidArgumentException('Docusaurus Markdown exceeds size limit');
        // Only remove well-known component imports. Never evaluate arbitrary MDX/JS expressions.
        $source=self::stripStaticImports($source);
        $source=preg_replace_callback('~<TabItem\b([^<>]*?)>(.*?)</TabItem\s*>~s',static function($m):string {
            if(preg_match('/[{}]/',$m[1]))throw new \InvalidArgumentException('Dynamic Docusaurus TabItem attributes are forbidden');
            preg_match('/\b(?:label|value)\s*=\s*([\'\"])(.*?)\1/s',$m[1],$a);
            $title=$a[2]??'Tab';if(preg_match('/[<>\x00-\x1f]/',$title))throw new \InvalidArgumentException('Invalid TabItem title');
            return '<Tab title="'.htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.$m[2].'</Tab>';
        },$source)??$source;
        $source=preg_replace_callback('~<Admonition\b([^<>]*?)>(.*?)</Admonition\s*>~s',static function($m):string {
            if(preg_match('/[{}]/',$m[1]))throw new \InvalidArgumentException('Dynamic Docusaurus Admonition attributes are forbidden');
            preg_match('/\btype\s*=\s*([\'\"])(.*?)\1/s',$m[1],$type);$type=strtolower($type[2]??'note');
            $tag=match($type){'note'=>'Note','info'=>'Info','tip'=>'Tip','warning','caution'=>'Warning','danger'=>'Danger',default=>throw new \InvalidArgumentException('Unsupported admonition type')};
            preg_match('/\btitle\s*=\s*([\'\"])(.*?)\1/s',$m[1],$title);$label=$title[2]??'';
            return '<'.$tag.($label!==''?' title="'.htmlspecialchars($label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"':'').'>'.$m[2].'</'.$tag.'>';
        },$source)??$source;
        // A static @site asset reference is data, not a general JS require. Dynamic requires remain prohibited.
        $source=preg_replace_callback('~src=\{require\([\'\"]@site/static/([a-zA-Z0-9_./-]+)[\'\"]\)(?:\.default)?\}~',static function($m):string {
            if(str_contains($m[1],'..'))throw new \InvalidArgumentException('Unsafe @site asset path');return 'src="/'.htmlspecialchars($m[1],ENT_QUOTES,'UTF-8').'"';
        },$source)??$source;
        $source=self::admonitions($source);
        return MintlifyMdx::convert($source,$warnings);
    }
    private static function stripStaticImports(string $source):string
    {
        $lines=preg_split('/(?<=\n)/',$source);if(!is_array($lines))throw new \InvalidArgumentException('Invalid source lines');
        $fence=null;$result='';
        foreach($lines as $line){
            if(preg_match('/^ {0,3}(`{3,}|~{3,})/',$line,$match)){
                if($fence===null)$fence=$match[1][0];elseif($fence===$match[1][0])$fence=null;
            }
            if($fence===null&&preg_match('~^\s*import\s+(?:Tabs\s+from\s+[\'\"]@theme/Tabs[\'\"]|TabItem\s+from\s+[\'\"]@theme/TabItem[\'\"]|Admonition\s+from\s+[\'\"]@theme/Admonition[\'\"]|CodeBlock\s+from\s+[\'\"]@theme/CodeBlock[\'\"])\s*;?\s*$~',$line))continue;
            $result.=$line;
        }
        return $result;
    }
    private static function admonitions(string $body):string
    {
        $lines=preg_split('/(?<=\n)/',$body);if(!is_array($lines))throw new \InvalidArgumentException('Invalid Markdown lines');
        $output=[];$stack=[];$fence=null;
        foreach($lines as $line){
            if(preg_match('/^ {0,3}(`{3,}|~{3,})/',$line,$f)){
                if($fence===null)$fence=$f[1][0];elseif($fence===$f[1][0])$fence=null;
            }
            if($fence===null&&preg_match('/^ {0,3}:::(note|info|tip|warning|caution|danger|success)(?:\[([^\]\r\n]{0,200})\]|[ \t]+([^\r\n]{0,200}))?[ \t]*\r?\n?$/i',$line,$m)){
                if(count($stack)>=RichBlocks::MAX_DEPTH)throw new \InvalidArgumentException('Admonitions nesting too deep');
                $stack[]=['type'=>strtolower($m[1]),'title'=>trim($m[2]??($m[3]??'')),'lines'=>[]];continue;
            }
            if($fence===null&&preg_match('/^ {0,3}:::[ \t]*\r?\n?$/',$line)&&$stack){
                $node=array_pop($stack);$type=$node['type']==='caution'?'warning':$node['type'];
                $payload=['v'=>1,'type'=>$type,'title'=>$node['title'],'body'=>trim(implode('',$node['lines']))];
                $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if(RichBlocks::parse('book-callout',$json)===null)throw new \InvalidArgumentException('Invalid Docusaurus admonition content');
                $converted="\n```book-callout\n".$json."\n```\n";
                if($stack)$stack[count($stack)-1]['lines'][]=$converted;else $output[]=$converted;
                continue;
            }
            if($stack)$stack[count($stack)-1]['lines'][]=$line;else $output[]=$line;
        }
        if($stack)throw new \InvalidArgumentException('Unterminated Docusaurus admonition');
        return implode('',$output);
    }
}
