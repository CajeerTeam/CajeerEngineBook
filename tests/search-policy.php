<?php
declare(strict_types=1);
$root=dirname(__DIR__);spl_autoload_register(function($class)use($root){foreach(['CajeerEngine\\'=>'core/','CajeerBook\\'=>'src/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){$path=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;return;}});
use CajeerBook\{Search,BookStore,Visibility};
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
$o=Search::options(['q'=>'  "red fox" policy API_v2  ','book'=>'guide','version'=>'1.0','limit'=>'2','offset'=>'1']);
ok($o['q']==='"red fox" policy API_v2'&&$o['book']==='guide'&&$o['version']==='1.0'&&$o['limit']===2&&$o['offset']===1,'bounded validated search parameters');
ok(Search::query($o['groups'],true)==="('red' <-> 'fox') & ('policy':*) & ('API':* & 'v2':*)",'phrases, AND terms and technical prefixes');
ok(Search::query(Search::groups("foo' | !bar & <-> baz"),false)==="('foo') & ('bar') & ('baz')",'tsquery metacharacters never become operators');
ok(Search::query(Search::groups('документация'),true)==="('документация':*)",'Unicode terms retained');
ok(Search::options(['q'=>"\xff"])['groups']===[]&&Search::options(['q'=>['bad']])['groups']===[],'malformed query cannot throw or expand results');
ok(strlen(Search::options(['q'=>str_repeat('я',150)])['q'])===200&&Search::cut('aя',2)==='a','UTF-8 truncation preserves valid codepoints');
ok(Search::options(['q'=>'!!!'])['groups']===[],'empty and punctuation-only queries have no terms');
foreach([['book'=>'../bad'],['limit'=>'51'],['offset'=>'-1'],['version'=>[]],['q'=>str_repeat('x ',25)]] as $bad)deny(fn()=>Search::options($bad),'invalid filter/pagination/term limit rejected');
ok(Search::text('<h2>Title</h2><p>Some <b>visible</b> &amp; safe <a href="hiddenURL">label</a></p><script>secret</script><template>payload</template>')==='Title Some visible & safe label','HTML text extraction omits URLs and hidden payloads');
$p=['title'=>'Public title','body'=>'Published text','section'=>'Guide','order'=>10,'updated'=>'2026-11-15'];$draft=$p;$draft['body']='SECRET DRAFT';$db=['schema'=>1,'pages'=>['guide/1/new'=>['draft'=>$draft,'published'=>$p,'published_key'=>'guide/1/old','revision'=>2],'guide/1/draft'=>['draft'=>$draft,'published'=>null,'revision'=>1],'secret/1/index'=>['draft'=>$p,'published'=>$p,'revision'=>1]],'books'=>['secret'=>['slug'=>'secret','title'=>'Private','id'=>str_repeat('b',32),'visibility'=>'private']]];
$r=Search::projection($db);ok(array_keys($r)===['guide/1/old','secret/1/index']&&$r['guide/1/old']['body']==='Published text'&&$r['guide/1/old']['public']&&!$r['secret/1/index']['public'],'only published snapshots and public paths projected, with legacy book policy');
ok(array_keys(Visibility::pages($db,'public','none'))===['guide/1/old']&&Visibility::pages($db,'private','none')===[],'search projection matches reader visibility policy');
