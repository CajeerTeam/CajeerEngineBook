<?php
declare(strict_types=1);
use CajeerBook\{Markdown,RichBlocks,BookStore,MediaStore};
$policy=in_array('--policy-only',$argv,true);
if($policy){require dirname(__DIR__).'/src/Markdown.php';require dirname(__DIR__).'/src/RichBlocks.php';}else require dirname(__DIR__).'/bootstrap.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function fence(array $d):string{return "~~~book-".$d['kind']."\n".json_encode($d,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n~~~\n";}
function decode(array $d):?array{return RichBlocks::parse('book-'.$d['kind'],json_encode($d,JSON_THROW_ON_ERROR));}
$tabs=['v'=>1,'kind'=>'tabs','sync'=>'platform','items'=>[['key'=>'linux','title'=>'Linux <safe>','body'=>'**Linux**'],['key'=>'windows','title'=>'Windows','body'=>'## Setup']]];
$code=['v'=>1,'kind'=>'code-tabs','sync'=>'platform','items'=>[['key'=>'windows','title'=>'PowerShell','language'=>'powershell','code'=>"<script>alert(1)</script>\n```"],['key'=>'linux','title'=>'Bash','language'=>'bash','code'=>'echo "hello"']]];
$accordion=['v'=>1,'kind'=>'accordion','items'=>[['title'=>'Details','body'=>'Details body','open'=>false],['title'=>'Open','body'=>'Open body','open'=>true]]];
$steps=['v'=>1,'kind'=>'steps','items'=>[['title'=>'First','body'=>'First body'],['title'=>'Second','body'=>'Second body']]];
foreach([$tabs,$code,$accordion,$steps] as $d){check(decode($d)===$d,'parse '.$d['kind']);check(RichBlocks::cost($d)===2,'cost '.$d['kind']);check(RichBlocks::kind($d)===$d['kind'],'kind '.$d['kind']);}
foreach([['v'=>2],['sync'=>'Bad Group'],['items'=>[]],['items'=>array_fill(0,13,$tabs['items'][0])],['items'=>[$tabs['items'][0],$tabs['items'][0]]],['items'=>[array_replace($tabs['items'][0],['key'=>'bad key'])]],['items'=>[array_replace($tabs['items'][0],['title'=>' '])]],['items'=>[array_replace($tabs['items'][0],['body'=>str_repeat('a',65537)])]],['extra'=>true]] as $patch)check(decode(array_replace($tabs,$patch))===null,'reject invalid '.json_encode(array_keys($patch)));
check(decode(array_replace($code,['items'=>[array_replace($code['items'][0],['language'=>'<script>'])]]))===null,'reject code language');
check(decode(array_replace($accordion,['items'=>[array_replace($accordion['items'][0],['open'=>'false'])]]))===null,'reject nonboolean open');
check(RichBlocks::parse('book-tabs',json_encode($steps))===null,'reject mismatched kind');
check(RichBlocks::parse('book-tabs','{')===null,'reject malformed JSON');
$html=RichBlocks::html($tabs,['<p>One</p>','<p>Two</p>'],fn($u,$i)=>$u);
check(substr_count($html,'data-tab-panel')===2&&str_contains($html,'Linux &lt;safe&gt;')&&str_contains($html,'<p>Two</p>')&&!str_contains($html,' hidden'),'all tab panels readable without JS');
check(str_contains($html,'data-sync="platform"')&&str_contains($html,'data-tab-key="windows"'),'sync by key');
$ch=RichBlocks::html($code,['',''],fn($u,$i)=>$u);check(str_contains($ch,'language-powershell')&&str_contains($ch,'&lt;script&gt;')&&!str_contains($ch,'<script>')&&str_contains($ch,'```'),'code tabs escape literal code');
check(RichBlocks::bodies($code)===['',''],'code tabs never parse code as Markdown');
$ah=RichBlocks::html($accordion,['<p>Details</p>','<p>Open</p>'],fn($u,$i)=>$u);check(substr_count($ah,'<details')===2&&substr_count($ah,' open>')===1&&str_contains($ah,'<summary>Details</summary>'),'native details with open state');
$sh=RichBlocks::html($steps,['<p>First</p>','<p>Second</p>'],fn($u,$i)=>$u);check(str_contains($sh,'<ol class="book-steps">')&&substr_count($sh,'<li>')===2,'ordered steps');
if($policy)exit;
$key='qa/1.0/interactive';$media='/media/'.str_repeat('a',32);
$steps['items'][0]['body']='[download]('.$media.')';
$accordion['items'][0]['body']=fence($steps);
$tabs['items'][1]['body']="## Setup\n\n[go](#setup)\n\n".fence($accordion);
$source=fence($tabs)."\n".fence($code)."\n# Outside\n";
$r=Markdown::render($source,$key,true,true);$h=$r['html'];
check(implode('',array_column($r['blocks'],'source'))===$source,'exact editor source preservation');
check(in_array('tabs',array_column($r['blocks'],'kind'),true)&&in_array('code-tabs',array_column($r['blocks'],'kind'),true),'AST visual types');
check(str_contains($h,'book-accordion-item')&&str_contains($h,'book-steps')&&str_contains($h,'<strong>Linux</strong>'),'nested components and real CommonMark');
check(str_contains($h,'id="book:rich:1:1:setup"')&&str_contains($h,'href="#book:rich:1:1:setup"'),'isolated tab anchors');
check(str_contains($h,'/studio/media/file/'.str_repeat('a',32))&&Markdown::mediaReferences($source,$key)===[str_repeat('a',32)],'nested media references and private preview');
check(!str_contains($h,'<script>'),'no executable code in code tabs');
$bad=$tabs;$bad['items'][1]['key']='linux';$r=Markdown::render(fence($bad),$key,true);check($r['blocks'][0]['kind']==='protected'&&!str_contains($r['html'],'data-book-tabs'),'invalid component protected');
$depth=$steps;$depth['items'][0]['body']=fence($tabs);$outer=$tabs;$outer['items'][0]['body']=fence($depth);$outer['items'][1]['body']='';check(substr_count(Markdown::render(fence($outer),$key)['html'],'data-book-tabs')===2,'bounded nesting');
$budget=$steps;$budget['items']=array_fill(0,12,['title'=>'Item','body'=>'']);$many=str_repeat(fence($budget)."\n",9);check(substr_count(Markdown::render($many,$key)['html'],'class="book-steps"')===8,'shared budget limits expansion');
$dir=sys_get_temp_dir().'/book-interactive-'.bin2hex(random_bytes(8));mkdir($dir,0700);
function clean(string $p):void{if(!is_dir($p)){if(file_exists($p))unlink($p);return;}foreach(new DirectoryIterator($p) as $f)if(!$f->isDot())clean($f->getPathname());rmdir($p);}
try{$store=new BookStore($dir.'/data');$ms=new MediaStore($dir.'/data',$store);$file=$dir.'/guide.txt';file_put_contents($file,'Attachment');$id=$ms->upload($file,'guide.txt')['item']['id'];$body=str_replace(str_repeat('a',32),$id,$source);
$input=['book'=>'qa','version'=>'1.0','path'=>'interactive','title'=>'Interactive','section'=>'Guide','order'=>10,'revision'=>0,'body'=>$body];
$store->saveResult($input,false);check($ms->open($id,false)===null,'draft nested media private');
try{$ms->delete($id,1);throw new RuntimeException('Deleted referenced file');}catch(DomainException){check(true,'nested reference prevents deletion');}
$input['revision']=1;$store->saveResult($input,true);$opened=$ms->open($id,false);check($opened!==null,'published nested media accessible');fclose($opened[1]);check(BookStore::published($store->read())[$key]['body']===$body,'published source exact');
$store->remove($key,2,true);check($ms->open($id,false)===null,'unpublish revokes access');
}finally{clean($dir);}
