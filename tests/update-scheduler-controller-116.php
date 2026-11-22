<?php
declare(strict_types=1);
ob_start();
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $f=$root.'/core/'.str_replace('\\','/',substr($class,strlen('CajeerEngine\\'))).'.php';
        if(is_file($f))require_once $f;
    }
    if(str_starts_with($class,'CajeerBook\\')){
        $f=$root.'/src/'.str_replace('\\','/',substr($class,strlen('CajeerBook\\'))).'.php';
        if(is_file($f))require_once $f;
    }
});
use CajeerBook\{IdentityStore,AuthController,UpdateController,UpdateScheduler,UpdateCenter,UpdateAgentClient};
function ok116(bool $value,string $message):void{if(!$value)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message."\n";}
$dir=sys_get_temp_dir().'/book-schedule-ctrl-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/data',0700);
try{
    putenv('BOOK_SECURE_COOKIE=0');file_put_contents($dir.'/VERSION',"1.1.6\n");
    $store=new IdentityStore($dir.'/data');$store->initialize('owner','Owner','owner@example.org','VeryStrongPassword!116');
    $owner=$store->login('owner','VeryStrongPassword!116','127.0.0.1');
    $store->createUser($owner['token'],'editor','Editor','editor@example.org','VeryStrongPassword!117');
    $editor=$store->login('editor','VeryStrongPassword!117','127.0.0.2');
    $center=new UpdateCenter($dir,$dir.'/data');$agent=new UpdateAgentClient();
    $sched=new UpdateScheduler($center,$dir.'/data',$agent);
    $controller=new UpdateController($store,new AuthController($store),$dir,$dir.'/data',$center,$agent,$sched);
    $rendered='';$render=static function(string $title,string $body)use(&$rendered):void{$rendered=$body;};
    $call=static function(string $method,string $token='',array $post=[])use($controller,$render,&$rendered):array{
        $_COOKIE=$token?['book_session'=>$token]:[];$_POST=$post;$_SERVER['REQUEST_METHOD']=$method;
        http_response_code(200);$rendered='';ob_start();$controller->dispatch($method,$render);ob_end_clean();
        return [http_response_code(),$rendered];
    };
    [$status]=$call('GET');ok116($status===401,'Anonymous cannot view schedule');
    [$status]=$call('GET',$editor['token']);ok116($status===403,'Workspace editor cannot view installation scheduler');
    [$status,$html]=$call('GET',$owner['token']);ok116($status===200&&str_contains($html,'Scheduled checks & notifications')&&str_contains($html,'Save scheduled checks'),'Owner sees real scheduler form');
    $csrf=$owner['session']['csrf'];$form=['action'=>'schedule-save','csrf'=>$csrf,'enabled'=>'1','channel'=>'stable',
        'mode'=>'manual_approval','interval_minutes'=>'60','notification_email'=>'owner@example.org'];
    [$status]=$call('POST',$editor['token'],$form);ok116($status===403&&!$sched->snapshot()['settings']['enabled'],'Editor cannot change schedule');
    [$status]=$call('POST',$owner['token'],array_replace($form,['csrf'=>'invalid']));ok116($status===403&&!$sched->snapshot()['settings']['enabled'],'Bad CSRF rejected');
    [$status]=$call('POST',$owner['token'],array_replace($form,['interval_minutes'=>'0']));ok116($status===409,'Invalid interval rejected');
    [$status]=$call('POST',$owner['token'],array_replace($form,['channel'=>'beta']));ok116($status===409,'Beta auto-staging rejected');
    [$status,$html]=$call('POST',$owner['token'],$form);
    ok116($status===200&&str_contains($html,'Scheduled checks configuration saved'),'Owner configures scheduler via HTTP');
    $cfg=$sched->snapshot()['settings'];ok116($cfg['enabled']&&$cfg['mode']==='manual_approval'&&$cfg['interval_minutes']===60,'Configuration persisted as private state');
    [$status,$html]=$call('GET',$owner['token']);ok116($status===200&&str_contains($html,'owner@example.org'),'Owner sees current notification destination');
    $store->logout($owner['token']);[$status]=$call('POST',$owner['token'],$form);ok116($status===401,'Revoked owner session cannot schedule updates');
    echo "Scheduled Update Controller 1.1.6 checks completed\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($dir);putenv('BOOK_SECURE_COOKIE');
}
