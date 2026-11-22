<?php
declare(strict_types=1);
ob_start();
/** Owner-only live execution route with a mocked bounded Unix-socket response; no root commands. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir)
        if(str_starts_with($class,$prefix)){$file=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;return;}
});
use CajeerBook\{IdentityStore,AuthController,UpdateController,UpdateCenter,UpdateAgentClient};
function verify114(bool $good,string $label):void{if(!$good)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$dir=sys_get_temp_dir().'/book-update-controller-114-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);mkdir($dir.'/data',0700);
try{
    putenv('BOOK_SECURE_COOKIE=0');
    file_put_contents($dir.'/VERSION',"1.1.3\n");
    $identity=new IdentityStore($dir.'/data');
    $identity->initialize('owner','Owner','owner@example.org','Owner-StrongPass!114');
    $owner=$identity->login('owner','Owner-StrongPass!114','127.0.0.1');
    $identity->createUser($owner['token'],'editor','Editor','editor@example.org','Editor-StrongPass!114');
    $editor=$identity->login('editor','Editor-StrongPass!114','127.0.0.2');
    $candidate=['version'=>'1.1.4','commit'=>str_repeat('a',40),'manifest_sha256'=>str_repeat('b',64)];
    $center=new UpdateCenter($dir,$dir.'/data');
    $center->save(['repository'=>'CajeerTeam/CajeerEngineBook','installed'=>'1.1.3',
        'channel'=>'stable','status'=>'available','checked_at'=>gmdate('c'),
        'candidate'=>['tag'=>'v1.1.4','name'=>'Release','published_at'=>gmdate('c'),
            'schema'=>7,'requires_schema_upgrade'=>false,'changelog'=>'changes']+$candidate],true);
    $requests=[];$stage='none';
    $commandCount=static fn(array $events):int=>count(array_filter($events,static fn($r):bool=>$r['action']!=='recovery-status'));
    $transport=static function(string $json)use(&$requests,&$stage,$candidate):string{
        $req=json_decode($json,true,10,JSON_THROW_ON_ERROR);$requests[]=$req;
        $action=$req['action'];
        if($action==='recovery-status')return json_encode(['ok'=>true,'data'=>[
            'state'=>'ready','current_version'=>'1.1.3','previous_version'=>'1.1.2',
            'backup_id'=>'20261122T120000Z-'.str_repeat('a',16),'backup_attested'=>true,
            'manual_recovery_required'=>false,'env_changed'=>false]], JSON_THROW_ON_ERROR);
        if($action!=='status'){
            if($req['candidate']!==$candidate)throw new RuntimeException('Wrong candidate sent to host');
            $stage=['fetch'=>'fetched','preflight'=>'preflight','apply'=>'applied'][$action];
            return json_encode(['ok'=>true,'data'=>['state'=>'queued','stage'=>$stage,'action'=>$action]],JSON_THROW_ON_ERROR);
        }
        return json_encode(['ok'=>true,'data'=>['state'=>'succeeded','stage'=>$stage,'action'=>'status','candidate'=>$candidate]],JSON_THROW_ON_ERROR);
    };
    $agent=new UpdateAgentClient('/tmp/test-update.sock',$transport);
    $controller=new UpdateController($identity,new AuthController($identity),$dir,$dir.'/data',$center,$agent);
    $output='';$render=static function(string $title,string $body)use(&$output):void{$output=$body;};
    $run=static function(string $method,string $token='',array $post=[])use($controller,$render,&$output):array{
        $_COOKIE=$token?['book_session'=>$token]:[];$_POST=$post;$_SERVER['REQUEST_METHOD']=$method;
        http_response_code(200);$output='';ob_start();$controller->dispatch($method,$render);ob_end_clean();
        return [http_response_code(),$output];
    };
    [$code]=$run('GET');verify114($code===401&&$commandCount($requests)===0,'Anonymous user cannot query host agent');
    [$code]=$run('GET',$editor['token']);verify114($code===403&&$commandCount($requests)===0,'Editor cannot query host agent (HTTP '.(string)$code.', requests '.$commandCount($requests).')');
    [$code,$html]=$run('GET',$owner['token']);verify114($code===200&&str_contains($html,'Fetch signed update')&&str_contains($html,'Backup / recovery'),'Owner sees prepared signed candidate and fetch button');
    $csrf=$owner['session']['csrf'];$post=['action'=>'fetch','csrf'=>$csrf,'manifest_sha256'=>$candidate['manifest_sha256']];
    [$code]=$run('POST',$editor['token'],$post);verify114($code===403&&$commandCount($requests)===1,'Editor fetch blocked before privileged socket');
    [$code]=$run('POST',$owner['token'],array_replace($post,['csrf'=>'invalid']));verify114($code===403&&$commandCount($requests)===1,'Bad CSRF blocks host RPC');
    [$code]=$run('POST',$owner['token'],array_replace($post,['manifest_sha256'=>str_repeat('0',64)]));verify114($code===409&&$commandCount($requests)===2,'Manifest pin mismatch blocks host RPC');
    [$code,$html]=$run('POST',$owner['token'],$post);verify114($code===200&&$stage==='fetched'&&str_contains($html,'Preflight signed update'),'Authenticated fetch reaches host agent');
    [$code,$html]=$run('POST',$owner['token'],array_replace($post,['action'=>'preflight']));verify114($code===200&&$stage==='preflight'&&str_contains($html,'Apply signed update'),'Host preflight exposed after fetch');
    [$code]=$run('POST',$owner['token'],array_replace($post,['action'=>'apply']));verify114($code===409&&$stage==='preflight','Apply without maintenance confirmation denied');
    [$code,$html]=$run('POST',$owner['token'],array_replace($post,['action'=>'apply','confirm_maintenance'=>'yes']));
    verify114($code===200&&$stage==='applied'&&str_contains($html,'Host execution status'),'Confirmed activation accepted by agent');
    $actions=array_values(array_map(static fn($r)=>$r['action'],array_filter($requests,static fn($r)=>!in_array($r['action'],['status','recovery-status'],true))));
    verify114($actions===['fetch','preflight','apply'],'PHP sends only allowlisted host actions');
    try{$agent->call('shell',$candidate);throw new RuntimeException('Arbitrary action permitted');}catch(InvalidArgumentException){}
    verify114(true,'PHP Unix-socket client rejects arbitrary operations');
    $identity->logout($owner['token']);$n=$commandCount($requests);
    [$code]=$run('GET',$owner['token']);verify114($code===401&&$commandCount($requests)===$n,'Revoked session cannot query agent');
    echo "Admin Update Center 1.1.4 checks completed\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($dir);
    putenv('BOOK_SECURE_COOKIE');
    ob_end_flush();
}
