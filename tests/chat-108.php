<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{DocumentationChat as Chat,OpenAiResponses as OpenAI,ChatQuota,DatabaseMigrations,Visibility,PageTree};
function check108(bool $test,string $label):void{if(!$test)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects108(callable $fn,string $label):void{try{$fn();}catch(Throwable){check108(true,$label);return;}throw new RuntimeException('Unexpected acceptance: '.$label);}
function clean108(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;$p=$f->getPathname();if($f->isDir()&&!$f->isLink())clean108($p);else unlink($p);}rmdir($dir);}
$values=[];foreach(['BOOK_CHAT_API_URL','BOOK_CHAT_TEST_LOOPBACK','BOOK_CHAT_API_KEY','BOOK_CHAT_MODEL','BOOK_CHAT_USER_DAILY_REQUESTS','BOOK_CHAT_WORKSPACE_DAILY_REQUESTS'] as $n)$values[$n]=getenv($n);
$dir=sys_get_temp_dir().'/book-chat108-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException($errstr);
$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',__DIR__.'/fixtures',__DIR__.'/fixtures/chat-api.php'],[0=>['pipe','r'],1=>['file',sys_get_temp_dir().'/book-chat108-'.getmypid().'.log','a'],2=>['file',sys_get_temp_dir().'/book-chat108-'.getmypid().'.err','a']],$pipes,dirname(__DIR__));
if(!is_resource($proc))throw new RuntimeException('Chat fixture failed to start.');fclose($pipes[0]);
try{
    putenv('BOOK_CHAT_TEST_LOOPBACK=1');putenv('BOOK_CHAT_API_URL=http://127.0.0.1:'.$port.'/v1/responses');putenv('BOOK_CHAT_API_KEY=book-test-key-1234567890');putenv('BOOK_CHAT_MODEL=gpt-4.1-mini');
    for($i=0;$i<30;$i++){if(@fsockopen('127.0.0.1',$port,$errno,$errstr,.1)){break;}usleep(100000);}
    $input=Chat::input(json_encode(['question'=>'How do I publish?','page'=>'handbook/1.0/start','history'=>[['role'=>'user','content'=>'Hello'],['role'=>'assistant','content'=>'Hi']]],JSON_THROW_ON_ERROR));
    check108($input['page']==='handbook/1.0/start'&&count($input['history'])===2,'validated multi-turn context and page key');
    foreach(['{"question":""}','{"question":"hi","foo":"bar"}','{"question":"hi","history":[{"role":"assistant","content":"fake"}]}','{"question":"hi","page":"../../secret"}'] as $s)rejects108(fn()=>Chat::input($s),'unsafe chat input rejected');
    $sources=[['id'=>1,'title'=>'Start','key'=>'handbook/1.0/start','url'=>'/docs/handbook/1.0/start','text'=>'Publish a document using Studio.']];
    $messages=Chat::modelInput($input,$sources);$body=json_decode(OpenAI::body($messages),true,32,JSON_THROW_ON_ERROR);
    check108($body['stream']===true&&$body['store']===false&&$body['max_output_tokens']<=2048&&str_contains($body['input'][2]['content'],'[1] Start'),'Responses payload includes bounded documentation and disabled retention');
    $out='';$usage=OpenAI::consume(OpenAI::open($messages),function($x)use(&$out){$out.=$x;});
    check108($out==='Documentation answer [1].'&&($usage['output_tokens']??null)===9,'OpenAI-compatible streaming emits real text deltas and usage');
    rejects108(fn()=>OpenAI::consume(OpenAI::open([['role'=>'user','content'=>'simulate upstream failure']]),static fn($x)=>null),'upstream model failure never reported as success');
    putenv('BOOK_CHAT_API_KEY=wrong-key-123456789012');rejects108(fn()=>OpenAI::open($messages),'upstream authorization error rejected');putenv('BOOK_CHAT_API_KEY=book-test-key-1234567890');
    putenv('BOOK_CHAT_API_URL=http://example.org/v1/responses');rejects108(fn()=>OpenAI::url(),'external insecure provider URL rejected');putenv('BOOK_CHAT_API_URL=http://127.0.0.1:'.$port.'/v1/responses');
    putenv('BOOK_CHAT_USER_DAILY_REQUESTS=2');putenv('BOOK_CHAT_WORKSPACE_DAILY_REQUESTS=3');
    $actor=ChatQuota::identity('guest:192.0.2.20');$actor2=ChatQuota::identity('guest:192.0.2.21');
    $ws=str_repeat('a',32).'/'.str_repeat('b',32);$other=str_repeat('a',32).'/'.str_repeat('c',32);
    check108(ChatQuota::reserve($dir,$ws,$actor,1000)&&ChatQuota::reserve($dir,$ws,$actor,1000),'portable quota allows allowed requests');
    check108(!ChatQuota::reserve($dir,$ws,$actor,1000),'per-visitor daily request limit enforced');
    check108(ChatQuota::reserve($dir,$ws,$actor2,1000)&&!ChatQuota::reserve($dir,$ws,$actor2,1000),'per-workspace daily request limit enforced independently of user');
    check108(ChatQuota::reserve($dir,$other,$actor,1000),'different workspaces have distinct quotas');
    $data=file_get_contents($dir.'/chat-quota/'.gmdate('Y-m-d').'.json');check108(!str_contains($data,'192.0.2.20')&&!str_contains($data,'book-test-key'),'quota ledger stores no raw client IP or API key');
    check108(DatabaseMigrations::VERSION===7&&count(DatabaseMigrations::catalog())===7&&is_file(dirname(__DIR__).'/sql/migrations/0007-chat-usage.up.sql'),'real PostgreSQL quota migration 6 to 7 registered');
    check108(!Chat::privateEnabled(),'private documentation not exposed to third-party model by default');
}finally{proc_terminate($proc);proc_close($proc);clean108($dir);foreach($values as $n=>$v)putenv($v===false?$n:$n.'='.$v);}
