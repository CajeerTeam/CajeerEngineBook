<?php
declare(strict_types=1);
ob_start();
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,strlen('CajeerEngine\\'))).'.php';
        if(is_file($file))require_once $file;
    }
    if(str_starts_with($class,'CajeerBook\\')){
        $file=$root.'/src/'.str_replace('\\','/',substr($class,strlen('CajeerBook\\'))).'.php';
        if(is_file($file))require_once $file;
    }
});
use CajeerBook\{IdentityStore,AuthController,UpdateController,UpdateScheduler,UpdateCenter,UpdateAgentClient,UpdateOperations};
function verify117(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo 'PASS '.$why."\n";}
function deny117(Closure $cb):void{try{$cb();throw new LogicException('Missing validation');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}}
$dir=sys_get_temp_dir().'/book117-'.bin2hex(random_bytes(7));mkdir($dir,0700);mkdir($dir.'/data',0700);
try{
    file_put_contents($dir.'/VERSION',"1.1.7\n");putenv('BOOK_SECURE_COOKIE=0');
    $store=new IdentityStore($dir.'/data');$store->initialize('owner','Owner','owner@example.test','VeryStrongPass117!');
    $owner=$store->login('owner','VeryStrongPass117!','127.0.0.1');
    $store->createUser($owner['token'],'editor','Editor','editor@example.test','VeryStrongPass118!');
    $editor=$store->login('editor','VeryStrongPass118!','127.0.0.2');
    $id=str_repeat('a',32);$events=[['id'=>$id,'time'=>1763840000,'action'=>'fetch','state'=>'succeeded','stage'=>'fetched',
        'version'=>'1.1.7','code'=>'COMPLETED','elapsed_seconds'=>3]];
    $calls=[];
    $transport=static function(string $request)use(&$calls,$events):string{
        $req=json_decode($request,true,10,JSON_THROW_ON_ERROR);$calls[]=$req['action'];
        return json_encode(['ok'=>true,'data'=>match($req['action']){
            'status'=>['state'=>'succeeded','stage'=>'fetched','action'=>'fetch','job_id'=>str_repeat('b',32),
                'candidate'=>['version'=>'1.1.7']],
            'history'=>['state'=>'ready','events'=>$events],
            'recovery-status'=>['state'=>'ready','manual_recovery_required'=>false,'env_changed'=>false],
            default=>throw new RuntimeException('Unexpected write action')
        }],JSON_THROW_ON_ERROR);
    };
    $agent=new UpdateAgentClient('/fixture/agent.sock',$transport);
    $center=new UpdateCenter($dir,$dir.'/data');$scheduler=new UpdateScheduler($center,$dir.'/data',$agent);
    $scheduler->configure(['enabled'=>true,'mode'=>'notify_only','notification_email'=>'private+token@example.test']);
    $report=UpdateOperations::report($center,$scheduler,$agent);
    verify117($report['operations'][0]['code']==='COMPLETED'&&$report['installed_version']==='1.1.7','Live operation and version exported');
    $encoded=json_encode($report,JSON_THROW_ON_ERROR);
    verify117(!str_contains($encoded,'private+token')&&!str_contains($encoded,'/fixture/agent.sock'),
        'Diagnostic report redacts notification destination and socket path');
    deny117(fn()=>UpdateOperations::events(['state'=>'ready','events'=>[array_replace($events[0],['code'=>'SECRET /root/key'])]]));
    verify117(true,'Untrusted host error strings rejected rather than exported');
    $ctl=new UpdateController($store,new AuthController($store),$dir,$dir.'/data',$center,$agent,$scheduler);
    $rendered='';$render=static function(string $title,string $body)use(&$rendered):void{$rendered=$body;};
    $request=static function(string $token,string $query='')use($ctl,$render,&$rendered):array{
        $_POST=[];$_GET=$query!==''?['download'=>$query]:[];
        $_COOKIE=$token!==''?['book_session'=>$token]:[];$_SERVER['REQUEST_METHOD']='GET';
        http_response_code(200);$rendered='';ob_start();$ctl->dispatch('GET',$render);$response=ob_get_clean();
        return [http_response_code(),$rendered,$response];
    };
    [$code]=$request('','diagnostics');verify117($code===401,'Anonymous diagnostics denied');
    [$code]=$request($editor['token'],'diagnostics');verify117($code===403,'Editor diagnostics denied');
    [$code,$html]=$request($owner['token']);
    verify117($code===200&&str_contains($html,'Update history & operations')&&str_contains($html,'COMPLETED'),
        'Owner sees durable host operation history in panel');
    verify117(str_contains($html,'Export sanitized update diagnostics'),'Owner sees report export');
    [$code,$html,$download]=$request($owner['token'],'diagnostics');
    $data=json_decode($download,true,20,JSON_THROW_ON_ERROR);
    verify117($code===200&&$data['operations'][0]['action']==='fetch'&&$html==='','Owner can export report as JSON');
    verify117(!in_array('apply',$calls,true),'Read-only history and export never trigger host mutation');
    $store->logout($owner['token']);[$code]=$request($owner['token'],'diagnostics');
    verify117($code===401,'Revoked owner session cannot export history');
    echo "Update Operations 1.1.7 tests completed\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($dir);putenv('BOOK_SECURE_COOKIE');
}
