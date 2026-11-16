<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{RedisRuntime,RedisTopology};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";flush();}
function until(callable $fn,int $seconds,string $label):void{$end=microtime(true)+$seconds;do{try{if($fn()){check(true,$label);return;}}catch(Throwable){}usleep(250000);}while(microtime(true)<$end);throw new RuntimeException($label.' timed out');}
$r=RedisRuntime::forBinding(str_repeat('a',32),str_repeat('b',32));$config=RedisTopology::config();
$primary=function()use($config){return RedisTopology::discover($config,function($h,$p,$name){$c=new Redis();$c->connect($h,$p,.4,null,0,.5);try{return $c->rawCommand('SENTINEL','get-master-addr-by-name',$name);}finally{$c->close();}})[0];};
until(function()use($r,$primary){$r->check();return $primary()===getenv('BOOK_TEST_PRIMARY');},60,'initial Redis primary and actual commands');
check($r->limit('test','first',1,60)['allowed']&&!$r->limit('test','first',1,60)['allowed'],'initial shared rate limit');
echo "READY_INITIAL\n";flush();
until(function()use($r,$primary){if($primary()!==getenv('BOOK_TEST_REPLICA'))return false;$r->check();return true;},90,'same long-lived runtime reconnects to promoted Redis replica');
check($r->limit('test','promoted',1,60)['allowed']&&!$r->limit('test','promoted',1,60)['allowed'],'rate limit uses promoted primary');
$r->notify('legacy');until(fn()=>in_array('legacy',$r->wait(),true),15,'Redis consumer group recreated or resumed on promoted primary');
echo "READY_PROMOTED\n";flush();
until(function()use($r){try{$r->check();return false;}catch(Throwable){return true;}},30,'loss of Sentinel majority fails closed');
$computed=0;$value=$r->remember('test',['outage'],function()use(&$computed){$computed++;return ['ok'=>true];});check($computed===1&&$value===['ok'=>true],'cache computes source data during Sentinel outage');
try{$r->limit('test','outage',1,60);throw new LogicException('Limiter unexpectedly allowed outage');}catch(RuntimeException){check(true,'limiter refuses outage');}
echo "READY_NO_QUORUM\n";flush();
until(function()use($r){$r->check();return true;},90,'runtime recovers after Sentinel quorum returns');
