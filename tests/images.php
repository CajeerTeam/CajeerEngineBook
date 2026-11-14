<?php
declare(strict_types=1);
use CajeerBook\{Markdown,MediaMarkup};
$policyOnly = in_array('--policy-only',$argv,true);
if ($policyOnly) { require dirname(__DIR__).'/src/Markdown.php'; require dirname(__DIR__).'/src/MediaMarkup.php'; }
else require dirname(__DIR__).'/bootstrap.php';
function check(bool $ok,string $name):void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
function title(array $options):string { return MediaMarkup::IMAGE_PREFIX.rawurlencode(json_encode($options, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
$options=['width'=>50,'align'=>'right','caption'=>'Описание <script>alert(1)</script> & "quotes"'];
$encoded=title($options);
check(MediaMarkup::imageOptions($encoded)===$options,'metadata round trip Unicode and escaped text');
foreach ([null,'ordinary title','book-image:v1:%','book-image:v1:[]',title(['width'=>101,'align'=>'right','caption'=>'x']),title(['width'=>50,'align'=>'evil','caption'=>'x']),title(['width'=>50,'align'=>'right','caption'=>"x\0y"]),title(['width'=>50,'align'=>'right','caption'=>str_repeat('x',2001)])] as $bad) check(MediaMarkup::imageOptions($bad)===null,'invalid metadata rejected '.substr((string)$bad,0,50));
$figure=MediaMarkup::imageHtml('<img src="/media/example" alt="x">',$options);
check(str_contains($figure,'book-image-50 book-image-right') && str_contains($figure,'&lt;script&gt;') && !str_contains($figure,'<script>'),'figure escapes caption and uses fixed classes');
check(!str_contains(MediaMarkup::imageHtml('<img>', ['width'=>'evil','align'=>'evil','caption'=>'']), 'evil'),'layout values cannot inject CSS or HTML');
check(str_contains(MediaMarkup::attachmentHtml('<a href="/media/example">File</a>'),'class="book-attachment"'),'attachment markup');
if ($policyOnly) exit;
$id=str_repeat('a',32);$other=str_repeat('b',32);$key='qa/1.0/media';
$md="![Image *alt*](</media/$id> \"$encoded\")\n\n[Download PDF](</media/$other> \"book-attachment:v1\")\n\nOrdinary [link](https://example.org).\n";
$r=Markdown::render($md,$key,true,true);
check(implode('',array_column($r['blocks'],'source'))===$md,'visual source segmentation preserves bytes');
$media=array_values(array_filter($r['blocks'],fn($b)=>isset($b['media'])));
check(count($media)===2 && $media[0]['kind']==='image' && $media[1]['kind']==='attachment','image and attachment are separate editable blocks');
check($media[0]['media']['url']==='/media/'.$id && $media[0]['media']['previewUrl']==='/studio/media/file/'.$id && $media[0]['media']['caption']===$options['caption'],'visual image retains canonical URL and private preview');
check(str_contains($r['html'],'src="/studio/media/file/'.$id.'"') && str_contains($r['html'],'href="/studio/media/file/'.$other.'?download=1"'),'preview uses authenticated routes');
$public=Markdown::render($md,$key)['html'];
check(str_contains($public,'src="/media/'.$id.'"') && str_contains($public,'href="/media/'.$other.'?download=1"') && !str_contains($public,'/studio/media/file/'),'public Reader uses canonical routes');
check(str_contains($public,'<figcaption>') && str_contains($public,'&lt;script&gt;') && !str_contains($public,'<script>') && !str_contains($public,'book-image:v1:'),'caption rendered safely without metadata tooltip');
check(Markdown::mediaReferences($md,$key)===[$id,$other],'reference tracking recognizes both canonical media IDs');
check(Markdown::mediaReferences("`/media/$id`\n\n[external](https://example.org/media/$other)",$key)===[],'code and remote links do not grant access');
check(Markdown::render('![image](javascript:alert%281%29 "'.$encoded.'")',$key,true)['blocks'][0]['kind']==='protected','unsafe image stays protected');
check(Markdown::render('![image](/media/'.$id.' "book-image:v1:bad")',$key,true)['blocks'][0]['kind']==='protected','malformed reserved title stays protected');
check(Markdown::render('Text ![image](/media/'.$id.')',$key,true)['blocks'][0]['kind']==='protected','mixed inline image stays protected');
check(Markdown::render('[**File**](/media/'.$other.' "book-attachment:v1")',$key,true)['blocks'][0]['kind']==='protected','complex attachment label stays protected');
check(Markdown::render('![image](relative.png)',$key,true)['blocks'][0]['media']['previewUrl']==='/docs/qa/1.0/relative.png','relative image URL remains canonical');
