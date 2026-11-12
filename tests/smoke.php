<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,Markdown};
function check(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
$dir=sys_get_temp_dir().'/cajeerbook-'.bin2hex(random_bytes(8));$s=new BookStore($dir);
try{
 $key='test/1.0/start';$p=['book'=>'test','version'=>'1.0','path'=>'start','title'=>'Hello','section'=>'Start','order'=>1,'body'=>'## Hello','revision'=>0];
 $s->save($p,false);check(!isset(BookStore::published($s->read())[$key]),'draft hidden');$p['revision']=1;$s->save($p,true);check(isset(BookStore::published($s->read())[$key]),'publish');$p['revision']=2;$p['body']='Changed';$s->save($p,false);check(BookStore::published($s->read())[$key]['body']==='## Hello','published snapshot unchanged');
 try{$s->save($p,true);throw new RuntimeException('stale write accepted');}catch(DomainException){check(true,'stale write rejected');}
 $s->copyVersion('test','1.0','2.0');check(!isset(BookStore::published($s->read())['test/2.0/start']),'copy is draft');
 $s->remove($key,3,true);check(!isset(BookStore::published($s->read())[$key]),'unpublish');
 try{BookStore::key('test','1.0','../etc');throw new RuntimeException('traversal accepted');}catch(InvalidArgumentException){check(true,'path traversal rejected');}
 $m=Markdown::render("## Hello\n<script>alert(1)</script>\n[bad](javascript:alert)\n[ok](/docs/test/1.0/start)\n```php\n<?php echo 1;\n```");check(!str_contains($m['html'],'<script>')&&!str_contains($m['html'],'href="javascript:'),'XSS escaping');check(str_contains($m['html'],'&lt;?php')&&count($m['toc'])===1,'code and TOC');
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);}
