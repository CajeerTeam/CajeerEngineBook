<?php
declare(strict_types=1);
namespace CajeerBook;
/** A deliberately limited, HTML-free Markdown dialect. No executable MDX. */
final class Markdown {
    public static function e(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function inline(string $s): string {
        $parts=preg_split('/(`[^`\n]+`|\[[^\]\n]+\]\([^\s)]+\)|\*\*[^*\n]+\*\*|\*[^*\n]+\*)/u',$s,-1,PREG_SPLIT_DELIM_CAPTURE)?:[$s];$html='';
        foreach($parts as $i=>$p){if($i%2===0){$html.=self::e($p);continue;}
            if($p[0]==='`')$html.='<code>'.self::e(substr($p,1,-1)).'</code>';
            elseif(preg_match('/^\[([^]]+)\]\(([^)]+)\)$/u',$p,$m)){ $url=$m[2];$safe=preg_match('~\A(?:https?://[^\s]+|/(?!/)[^\\\\\s]*|#[a-zA-Z0-9_-]+)\z~iD',$url);$html.=$safe?'<a href="'.self::e($url).'">'.self::e($m[1]).'</a>':self::e($m[1]); }
            elseif(str_starts_with($p,'**'))$html.='<strong>'.self::e(substr($p,2,-2)).'</strong>';else $html.='<em>'.self::e(substr($p,1,-1)).'</em>';
        }return $html;
    }
    public static function render(string $md): array {
        $html='';$toc=[];$list='';$code=null;$lang='';$para=[];$n=0;
        $flush=function()use(&$para,&$html){if($para){$html.='<p>'.self::inline(implode(' ',$para)).'</p>';$para=[];}};
        $close=function()use(&$list,&$html){if($list){$html.="</$list>";$list='';}};
        $emitCode=function()use(&$code,&$html,&$lang){$html.='<pre><code data-language="'.self::e($lang).'">'.self::e(implode("\n",$code)).'</code></pre>';$code=null;};
        foreach(preg_split('/\R/u',$md)?:[] as $line){
            if($code!==null){if(preg_match('/^\s*```\s*$/D',$line))$emitCode();else $code[]=$line;continue;}
            if(preg_match('/^\s*```([a-zA-Z0-9_-]*)\s*$/D',$line,$m)){$flush();$close();$code=[];$lang=$m[1];continue;}
            if(preg_match('/^(#{1,6})\s+(.+)$/u',$line,$m)){$flush();$close();$level=max(2,strlen($m[1]));$id='section-'.(++$n);$toc[]=['id'=>$id,'title'=>$m[2],'level'=>$level];$html.="<h$level id=\"$id\">".self::inline($m[2])."</h$level>";continue;}
            if(preg_match('/^\s*([-*]|\d+\.)\s+(.+)$/u',$line,$m)){$flush();$tag=ctype_digit($m[1][0])?'ol':'ul';if($list!==$tag){$close();$list=$tag;$html.="<$tag>";}$html.='<li>'.self::inline($m[2]).'</li>';continue;}
            $close();if(trim($line)===''){$flush();continue;}
            if(preg_match('/^>\s?(.*)$/u',$line,$m)){$flush();$html.='<blockquote>'.self::inline($m[1]).'</blockquote>';continue;}
            if(preg_match('/^---+$/D',$line)){$flush();$html.='<hr>';continue;}$para[]=trim($line);
        }$flush();$close();if($code!==null)$emitCode();return ['html'=>$html,'toc'=>$toc];
    }
}
