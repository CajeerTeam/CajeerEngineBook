<?php
declare(strict_types=1);
/** Exercise the actual owner-only PHP controller with local identity and synthetic GitHub transport. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir)
        if(str_starts_with($class,$prefix)){$file=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;return;}
});
use CajeerBook\{IdentityStore,AuthController,UpdateController,UpdateCenter};
function must(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('FAIL '.$msg);}
$dir=sys_get_temp_dir().'/book-update-controller-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);mkdir($dir.'/data',0700);
$keypair=sodium_crypto_sign_keypair();$der=hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($keypair);
file_put_contents($dir.'/key.pem',"-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64)."-----END PUBLIC KEY-----\n");
file_put_contents($dir.'/VERSION',"1.1.2\n");
$identity=new IdentityStore($dir.'/data');
$_SERVER['REQUEST_METHOD']='GET';
putenv('BOOK_SECURE_COOKIE=0');
try{
    $identity->initialize('owner','Owner','owner@example.org','Update-StrongPass!1');
    $identity->createUser(($login=$identity->login('owner','Update-StrongPass!1','127.0.0.1'))['token'],'editor','Editor','editor@example.org','Editor-StrongPass!1');
    $other=$identity->login('editor','Editor-StrongPass!1','127.0.0.2');
    $auth=new AuthController($identity);
    $api='https://api.github.com/repos/CajeerTeam/CajeerEngineBook/releases?per_page=100&page=1';
    $called=0;
    $center=new UpdateCenter($dir,$dir.'/data',static function(string $url,int $cap)use($api,&$called):string{
        if($url!==$api)throw new RuntimeException('Unexpected network access');$called++;return '[]';
    },$dir.'/key.pem');
    $controller=new UpdateController($identity,$auth,$dir,$dir.'/data',$center);
    $output='';$render=static function(string $title,string $body)use(&$output):void{$output=$body;};
    $run=static function(string $method,array $post=[],string $token='')use($controller,$render,&$output):array {
        $output='';$_POST=$post;$_COOKIE=$token?['book_session'=>$token]:[];
        http_response_code(200);ob_start();$controller->dispatch($method,$render);ob_end_clean();
        return [http_response_code(),$output];
    };
    [$status,$html]=$run('GET');must($status===401&&$called===0,'Anonymous user cannot access updates');
    [$status,$html]=$run('GET',[],$other['token']);must($status===403&&$called===0,'Non-owner cannot view update metadata');
    [$status,$html]=$run('GET',[],$login['token']);must($status===200&&str_contains($html,'System Updates')&&str_contains($html,'1.1.2'),'Owner sees live system page');
    [$status,$html]=$run('POST',['csrf'=>'bad','action'=>'check'],$login['token']);must($status===403&&$called===0,'Invalid POST CSRF does not reach GitHub');
    [$status,$html]=$run('POST',['csrf'=>$login['session']['csrf'],'action'=>'check'],$other['token']);must($status===403&&$called===0,'Non-owner POST cannot reach GitHub');
    [$status,$html]=$run('POST',['csrf'=>$login['session']['csrf'],'action'=>'check'],$login['token']);
    must($status===200&&$called===1&&str_contains($html,'up_to_date'),'Owner can check through real controller');
    [$status,$html]=$run('POST',['csrf'=>$login['session']['csrf'],'action'=>'prepare','manifest_sha256'=>str_repeat('0',64)],$login['token']);
    must($status===409&&$called===2&&!is_file($dir.'/data/update-center/prepared.json'),'Unsigned candidate cannot be prepared');
    echo "PASS anonymous, editor, owner, CSRF, successful check and unsigned preparation protections\n";
}finally{
    putenv('BOOK_SECURE_COOKIE');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($dir);
}
