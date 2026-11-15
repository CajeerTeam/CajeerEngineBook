<?php
declare(strict_types=1);
namespace CajeerBook;

/** Server-rendered translator workbench. All mutations still run through BookStore. */
final class TranslationView
{
    private static function e(string $s):string{return Markdown::e($s);}
    private static function t(string $s):string{return self::e(Locale::t($s));}
    private static function url(string $path):string{return self::e(WorkspaceUrls::url($path));}
    private static function hidden(string $name,string $value):string{return '<input type="hidden" name="'.$name.'" value="'.self::e($value).'">';}
    public static function render(array $db,string $csrf,string $selected=''):string{
        $out='<h1>'.self::t('Translation Studio').'</h1><p><a href="'.self::url('/studio').'">'.self::t('Back to Studio').'</a></p><p>'.self::t('Save a translation draft, then publish it using the normal review workflow.').'</p>';
        $sources=[];foreach($db['pages'] as $key=>$entry)if($entry['published']!==null&&!isset($entry['published']['translation_group']))$sources[$key]=$entry;
        foreach($db['pages'] as $key=>$e){$g=$e['draft']['translation_group']??null;if($g!==null&&!Locale::source($db,PageTree::scope($key),$g))$out.='<p>'.self::t('Source unavailable').': <a href="'.self::url('/studio?edit='.rawurlencode($key)).'">'.self::e($key).'</a></p>';}
        if(!$sources)return $out.'<p>'.self::t('No published source documents.').'</p>';
        if(!isset($sources[$selected]))$selected=array_key_first($sources);
        $entry=$sources[$selected];$source=$entry['published'];$id=PageTree::id($selected,$entry);$scope=PageTree::scope($selected);$digest=Locale::digest($source);
        $out.='<form method="get" class="editor" action="'.self::url('/studio/translations').'" ><label>'.self::t('Source document').'<select name="source">';
        foreach($sources as $key=>$e)$out.='<option value="'.self::e($key).'"'.($key===$selected?' selected':'').'>'.self::e($key.' · '.$e['published']['title'].' ('.Locale::language($e['published']).')').'</option>';
        $out.='</select></label><button>'.self::t('Open editor').'</button></form><h2>'.self::t('Published source text').'</h2><details><summary>'.self::e($source['title']).'</summary><pre><code>'.self::e($source['body']).'</code></pre></details><h2>'.self::t('Translations').'</h2>';
        if(Locale::canRetag($db,$selected))$out.='<form method="post" class="editor" action="'.self::url('/studio/translations').'">'.self::hidden('csrf',$csrf).self::hidden('action','set-language').self::hidden('key',$selected).self::hidden('source',$selected).self::hidden('revision',(string)$entry['revision']).'<label>'.self::t('Change draft language').'<select name="locale">'.Locale::options(Locale::language($entry['draft'])).'</select></label><p>'.self::t('Publish the draft to change the public language.').'</p><button>'.self::t('Change draft language').'</button></form>';
        if(Locale::language($entry['draft'])!==Locale::language($source))return $out;
        $used=[Locale::language($source)=>true];
        foreach($db['pages'] as $key=>$e){$p=$e['draft'];$pub=$e['published'];if(PageTree::scope($key)!==$scope||(($p['translation_group']??null)!==$id&&($pub['translation_group']??null)!==$id))continue;
            foreach([$p,$pub] as $v)if(($v['translation_group']??null)===$id)$used[Locale::language($v)]=true;
            $lang=Locale::language($p);$out.='<section class="org-card"><h3>'.self::e(Locale::LANGUAGES[$lang].' · '.$p['title']).'</h3><p>'.self::e($key).' · '.self::t('Draft').': '.self::t(($p['source_digest']??'')===$digest?'Up to date':'Source changed').' · '.self::t('Published').': '.($pub?self::t(($pub['source_digest']??'')===$digest?'Up to date':'Source changed'):'—').'</p><a href="'.self::url('/studio?edit='.rawurlencode($key)).'">'.self::t('Open editor').'</a><details><summary>'.self::t('Translation Markdown').'</summary><pre><code>'.self::e($p['body']).'</code></pre></details>';
            if(($p['translation_group']??null)===$id&&$p['source_digest']!==$digest)$out.='<form method="post" class="editor" action="'.self::url('/studio/translations').'">'.self::hidden('csrf',$csrf).self::hidden('action','review-source').self::hidden('key',$key).self::hidden('source',$selected).self::hidden('revision',(string)$e['revision']).self::hidden('source_digest',$digest).'<p>'.self::t('Compare the published source with your translation before confirming.').'</p><button>'.self::t('Mark source reviewed').'</button></form>';
            $out.='</section>';
        }
        $languages=array_diff_key(Locale::LANGUAGES,$used);if(!$languages)return $out;
        $out.='<h2>'.self::t('Create translation').'</h2><form method="post" class="editor" action="'.self::url('/studio/translations').'">'.self::hidden('csrf',$csrf).self::hidden('action','create').self::hidden('source',$selected).self::hidden('source_id',$id).self::hidden('source_digest',$digest).'<label>'.self::t('Translation language').'<select name="locale">';
        foreach($languages as $code=>$label)$out.='<option value="'.$code.'">'.self::e($label).'</option>';
        $out.='</select></label><label>'.self::t('Translation path').'<input required name="path" maxlength="250" value="'.self::e(array_key_first($languages).'/'.explode('/',$selected,3)[2]).'"></label><label>'.self::t('Translation title').'<input required name="title" maxlength="200"></label><label>'.self::t('Translation section').'<input name="section" maxlength="100"></label><label>'.self::t('Translation Markdown').'<textarea name="body" rows="12" maxlength="262144"></textarea></label><button class="primary">'.self::t('Create translation').'</button></form>';
        return $out;
    }
}
