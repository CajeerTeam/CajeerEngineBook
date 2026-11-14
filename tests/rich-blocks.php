<?php
declare(strict_types=1);
use CajeerBook\{Markdown,RichBlocks,BookStore,MediaStore};
$policyOnly=in_array('--policy-only',$argv,true);
if($policyOnly){require dirname(__DIR__).'/src/Markdown.php';require dirname(__DIR__).'/src/RichBlocks.php';}
else require dirname(__DIR__).'/bootstrap.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function payload(string $kind,array $data):string{return "~~~book-$kind\n".json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n~~~\n";}
function data(string $type='note',string $body='**Bold**'):array{return ['v'=>1,'type'=>$type,'title'=>'Title <safe>','body'=>$body];}
function card(array $overrides=[]):array{return array_replace(['title'=>'Guide','body'=>'Read **more**','href'=>'guide','icon'=>'book','image'=>'','alt'=>''],$overrides);}
function grid(array $cards):array{return ['v'=>1,'columns'=>2,'cards'=>$cards];}
function decode(string $kind,array $d):?array{return RichBlocks::parse('book-'.$kind,json_encode($d,JSON_THROW_ON_ERROR));}
foreach(RichBlocks::TYPES as $type){$d=data($type);check(decode('callout',$d)===$d,'callout type '.$type);}
foreach([['v'=>2],['type'=>'script'],['title'=>['bad']],['title'=>"bad\nline"],['body'=>str_repeat('x',65537)],['body'=>"bad\0"],['extra'=>true]] as $patch)check(decode('callout',array_replace(data(),$patch))===null,'invalid callout '.array_key_first($patch));
check(RichBlocks::parse('book-callout','{invalid')===null,'malformed JSON rejected');
check(RichBlocks::parse('php',json_encode(data()))===null,'ordinary code not recognized');
$g=grid([card(),card(['title'=>'API','icon'=>'code','image'=>'/media/'.str_repeat('a',32),'alt'=>'<diagram>'])]);
check(decode('cards',$g)===$g,'cards metadata round trip');
foreach([['columns'=>5],['cards'=>[]],['cards'=>array_fill(0,13,card())],['cards'=>[card(['title'=>''])]],['cards'=>[card(['icon'=>'<script>'])]],['cards'=>[card(['href'=>'javascript:alert(1)'])]],['cards'=>[card(['image'=>'data:image/svg+xml,evil'])]],['cards'=>[card(['image'=>'/studio/media/file/a'])]],['cards'=>[card(['href'=>'//evil.com'])]]] as $patch)check(decode('cards',array_replace($g,$patch))===null,'invalid grid '.json_encode(array_keys($patch)));
foreach(['javascript:alert(1)','JaVaScRiPt:alert(1)','data:text/html,evil','https:evil','//evil','\\evil',"java\nscript:evil",'/studio/media/file/a'] as $url)check(!RichBlocks::validUrl($url,false),'unsafe URL '.$url);
check(RichBlocks::validUrl('https://example.com',true)&&RichBlocks::validUrl('../guide#start',false)&&RichBlocks::validUrl('mailto:a@example.com',false)&&!RichBlocks::validUrl('mailto:a@example.com',true),'safe URL schemes and relative links');
$html=RichBlocks::html(data('warning'),['<p><strong>Bold</strong></p>'],fn($url,$image)=>$url);
check(str_contains($html,'book-callout-warning')&&str_contains($html,'Title &lt;safe&gt;')&&str_contains($html,'<strong>Bold</strong>')&&!str_contains($html,'Title <safe>'),'callout HTML safe title and body');
$html=RichBlocks::html($g,['<p>one</p>','<p>two</p>'],fn($url,$image)=>$url);
check(str_contains($html,'book-card-cols-2')&&substr_count($html,'class="book-card"')===2&&str_contains($html,'alt="&lt;diagram&gt;"')&&str_contains($html,'href="guide"'),'card grid HTML, links and escaped alt');
check(RichBlocks::cost($g)===2&&RichBlocks::bodies($g)===['Read **more**','Read **more**'],'body expansion budget');
if($policyOnly)exit;
// Actual League CommonMark integration: only reserved top-level fences expand.
$key='qa/1.0/start';$id=str_repeat('a',32);$media='/media/'.$id;
$call=payload('callout',data('tip',"## Inner\n\n**Bold** [go](#inner) [file]($media)\n\n<script>alert(1)</script>\n\n| A | B |\n| --- | --- |\n| 1 | 2 |"));
$cards=payload('cards',grid([card(['image'=>$media,'alt'=>'Image','href'=>'guide','body'=>'**Description**']),card(['title'=>'Nested','body'=>'## Inner'])]));
$source="# Inner\n\n".$call."\n".$cards."\nLast paragraph\n";
$r=Markdown::render($source,$key,true,true);
check(implode('',array_column($r['blocks'],'source'))===$source,'editor preserves entire original source');
check(in_array('callout',array_column($r['blocks'],'kind'),true)&&in_array('cards',array_column($r['blocks'],'kind'),true),'visual component AST classification');
check(str_contains($r['html'],'book-callout-tip')&&str_contains($r['html'],'book-card-grid')&&str_contains($r['html'],'<strong>Bold</strong>')&&str_contains($r['html'],'<table>'),'actual nested CommonMark/GFM rendering');
check(!str_contains($r['html'],'<script>')&&str_contains($r['html'],'&lt;script&gt;'),'nested raw HTML escapes');
check(str_contains($r['html'],'id="book:rich:1:0:inner"')&&str_contains($r['html'],'href="#book:rich:1:0:inner"')&&str_contains($r['html'],'id="book:rich:2:1:inner"'),'unique inner heading ids and local anchors');
check(str_contains($r['html'],'href="/docs/qa/1.0/guide"')&&str_contains($r['html'],'src="/studio/media/file/'.$id.'"'),'relative card link and private preview image');
$public=Markdown::render($source,$key)['html'];check(str_contains($public,'src="'.$media.'"')&&!str_contains($public,'/studio/media/file/'),'public reader uses canonical media');
check(Markdown::mediaReferences($source,$key)===[$id],'nested and card media references deduplicate');
$nested=payload('callout',data('note',payload('callout',data('note','nested'))));
check(substr_count(Markdown::render($nested,$key)['html'],'class="book-callout ' )===1,'nested component syntax stays inert code');
$invalid=payload('cards',grid([card(['href'=>'javascript:alert(1)'])]));
$bad=Markdown::render($invalid,$key,true);check(!str_contains($bad['html'],'class="book-card-grid')&&$bad['blocks'][0]['kind']==='protected','invalid reserved fence safe fallback');
$ordinary="````markdown\n".$call."````\n";check(!str_contains(Markdown::render($ordinary,$key)['html'],'class="book-callout '),'nested fence in ordinary code is inert');
$many=str_repeat(payload('cards',grid(array_fill(0,12,card())))."\n",9);check(substr_count(Markdown::render($many,$key)['html'],'class="book-card-grid')===8,'page rendering budget protects resources');
// Actual save/publication integration (no fake parser or media references).
$dir=sys_get_temp_dir().'/book-rich-'.bin2hex(random_bytes(8));mkdir($dir,0700);
function clean(string $p):void{if(!is_dir($p)){if(file_exists($p))unlink($p);return;}foreach(new DirectoryIterator($p) as $f)if(!$f->isDot())clean($f->getPathname());rmdir($p);}
try{$store=new BookStore($dir.'/data');$ms=new MediaStore($dir.'/data',$store);$file=$dir.'/guide.txt';file_put_contents($file,'Documentation asset');$real=$ms->upload($file,'guide.txt')['item']['id'];$url='/media/'.$real;
    $body=payload('callout',data('info',"[download]($url)"))."\n".payload('cards',grid([card(['href'=>$url])]));
    $input=['book'=>'qa','version'=>'1.0','path'=>'rich','title'=>'Rich','section'=>'Guide','order'=>10,'revision'=>0,'body'=>$body];
    $store->saveResult($input,false);check($ms->open($real,false)===null,'draft component media private');
    try{$ms->delete($real,1);throw new RuntimeException('Referenced media deleted');}catch(DomainException){check(true,'component reference prevents deletion');}
    $input['revision']=1;$store->saveResult($input,true);$opened=$ms->open($real,false);check($opened!==null,'published component media available');fclose($opened[1]);
    check(BookStore::published($store->read())['qa/1.0/rich']['body']===$body,'published source exact');
    $store->remove('qa/1.0/rich',2,true);check($ms->open($real,false)===null,'unpublish revokes media access');
}finally{clean($dir);}
