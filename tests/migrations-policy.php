<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\DatabaseMigrations as M;
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|InvalidArgumentException $e){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
$c=M::catalog();ok(array_keys($c)===[1,2,3,4,5]&&M::VERSION===5,'ordered, contiguous shipped migration catalog');
ok(!$c[1]['reversible']&&$c[2]['reversible']&&$c[3]['reversible']&&$c[4]['reversible']&&$c[5]['reversible'],'baseline irreversible, both index migrations reversible');
foreach($c as $m){$parts=[];foreach($m['files'] as $file)$parts[]=$file.'='.hash_file('sha256',dirname(__DIR__).'/sql/'.$file);ok(hash('sha256',implode("\n",$parts))===$m['checksum'],'checksum covers every ordered SQL file for '.$m['version']);}
ok(M::validateLedger([],$c)===0,'empty database has no version');ok(M::validateLedger([$c[1]],$c)===1,'baseline accepted');ok(M::validateLedger(array_values($c),$c)===5,'current ledger accepted');
deny(fn()=>M::validateLedger([$c[2]],$c),'missing baseline denied');
deny(fn()=>M::validateLedger([$c[1],$c[1]],$c),'duplicate version denied');
deny(fn()=>M::validateLedger([$c[1],array_replace($c[2],['checksum'=>str_repeat('a',64)])],$c),'modified migration denied');
deny(fn()=>M::validateLedger([$c[1],array_replace($c[2],['release'=>'0.5.1'])],$c),'changed release metadata denied');
deny(fn()=>M::validateLedger([$c[1],$c[2],$c[3],$c[4],['version'=>6]],$c),'future schema denied');
deny(fn()=>M::validateLedger([$c[2],$c[1]],$c),'out of order denied');
$up=file_get_contents(dirname(__DIR__).'/sql/'.$c[2]['files'][0]);$down=file_get_contents(dirname(__DIR__).'/sql/'.$c[2]['files'][1]);ok(str_contains($up,'(installation, outcome, at_epoch DESC, event_id DESC)')&&str_contains($down,'DROP INDEX cajeer_book.audit_event_outcome;'),'real reversible audit query index');
foreach([['apply'],['rollback','0','--offline'],['rollback','1'],['apply','--force']] as $args){$cmd=[PHP_BINARY,dirname(__DIR__).'/bin/migrations.php',...$args];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ok(proc_close($p)!==0&&str_contains($out,'Usage:'),'unsafe CLI rejected: '.implode(' ',$args));}
$history=[];foreach($c as $m)$history[]=$m+['operation'=>'apply'];
M::validateHistory($history,$c,5);ok(true,'known history verified');
$future=['version'=>6,'name'=>'future-index','release'=>'0.8.2','checksum'=>str_repeat('c',64),'operation'=>'apply'];
$down=array_replace($future,['operation'=>'rollback']);
M::validateHistory([...$history,$future,$down],$c,5);ok(true,'completed future rollback verified without accepting unknown applied schema');
deny(fn()=>M::validateHistory([...$history,$future],$c,5),'unbalanced future apply denied');
deny(fn()=>M::validateHistory([...$history,$future,array_replace($down,['checksum'=>str_repeat('d',64)])],$c,5),'future rollback checksum mismatch denied');
deny(fn()=>M::validateHistory([...$history,$down],$c,5),'future rollback without apply denied');
