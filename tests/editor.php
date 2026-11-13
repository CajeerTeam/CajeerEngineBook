<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{Markdown,BookStore};
function check(bool $condition,string $name):void{if(!$condition)throw new RuntimeException($name);echo "PASS $name\n";}
$source = "\n# Title\n\nA **bold** paragraph with [ref][x].\n\n[x]: guide\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n\n- [x] task\n\n![image](diagram.png)\n\n```php\necho 1;\n```\n\n<script>alert(1)</script>\n";
$r = Markdown::render($source,'qa/1.0/visual',true);
$segments = $r['blocks'];
check(implode('',array_column($segments,'source'))===$source,'editor source segmentation is lossless');
check(count(array_filter($segments,fn($b)=>$b['kind']==='rich'))>=2,'paragraph and heading are editable');
check(count(array_filter($segments,fn($b)=>$b['kind']==='protected'))>=2,'image and raw HTML are protected');
check(count(array_filter($segments,fn($b)=>$b['kind']==='table'))===1,'GFM table is editable');
check(count(array_filter($segments,fn($b)=>$b['kind']==='rich' && str_contains($b['source'],'- [x] task')))===1,'GFM task list is editable');
$complex = "| A | B |\n| --- | --- |\n| 1 | 2 | 3 |\n";
check(Markdown::render($complex,null,true)['blocks'][0]['kind']==='protected','surplus source cells remain protected');
$aligned = "| A | B |\n| :--- | ---: |\n| **x** | `a\\|b` |\n";
$t=Markdown::render($aligned,null,true);
check($t['blocks'][0]['kind']==='table' && str_contains($t['html'],'align="right"') && str_contains($t['html'],'a|b'),'aligned table and escaped code pipe render');
check(count(array_filter($segments,fn($b)=>$b['kind']==='code'))===1,'fenced code editable');
check(str_contains($r['html'],'data-book-source-href="guide"')&&str_contains($r['html'],'href="/docs/qa/1.0/guide"'),'visual link retains raw destination and canonical preview URL');
check(!str_contains(Markdown::render($source,'qa/1.0/visual')['html'],'data-book-source-href'),'editor-only attributes excluded from Reader');
check(!str_contains($r['html'],'<script>alert(1)</script>'),'editor HTML escapes raw HTML');
foreach (["", "\n\n", "\r\n# CRLF\r\n\r\ntext\r\n", "[x]: /foo\n", "A\n\nB", "# Heading\n", "- a\n  - b\n\n> quote\n"] as $input) {
    check(implode('',array_column(Markdown::render($input,null,true)['blocks'],'source'))===$input,'preserve source '.json_encode($input));
}
$dir=sys_get_temp_dir().'/book-editor-'.bin2hex(random_bytes(8));$store=new BookStore($dir);
try{
 $input=['book'=>'qa','version'=>'1.0','path'=>'visual','title'=>'Visual','section'=>'Guide','order'=>1,'body'=>'# Test','revision'=>0];
 $a=$store->saveResult($input,false);check($a===['key'=>'qa/1.0/visual','revision'=>1,'published'=>false],'save returns committed draft revision');
 $input['revision']=1;$b=$store->saveResult($input,true);check($b['revision']===2&&$b['published'],'publish returns committed revision');
 $input['revision']=2;$input['body']='Draft only';$c=$store->saveResult($input,false);check($c['revision']===3&&$c['published'],'draft save preserves existing publication state');
 check(BookStore::published($store->read())['qa/1.0/visual']['body']==='# Test','draft isolated');
 try{$store->saveResult($input,false);throw new RuntimeException('conflict accepted');}catch(DomainException){check(true,'stale revision rejected');}
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);}
