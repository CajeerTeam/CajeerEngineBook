<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{Embeddings,HybridSearch,BookStore,Search};
function pass107(bool $good,string $name):void{if(!$good)throw new RuntimeException($name);echo "PASS $name\n";}
function deny107(callable $fn,string $name):void{try{$fn();}catch(Throwable){pass107(true,$name);return;}throw new RuntimeException('Unexpected success: '.$name);}
$oldUrl=getenv('BOOK_EMBEDDING_URL');$oldModel=getenv('BOOK_EMBEDDING_MODEL');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException($errstr);
$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$cmd=[PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',__DIR__.'/fixtures',__DIR__.'/fixtures/embedding-api.php'];
$proc=proc_open($cmd,[0=>['pipe','r'],1=>['file',sys_get_temp_dir().'/book-107-fixture-'.getmypid().'.log','a'],2=>['file',sys_get_temp_dir().'/book-107-fixture-'.getmypid().'.err','a']],$pipes,dirname(__DIR__));
if(!is_resource($proc))throw new RuntimeException('Embedding test provider failed to start.');fclose($pipes[0]);
try{
    putenv('BOOK_EMBEDDING_URL=http://127.0.0.1:'.$port.'/v1/embeddings');putenv('BOOK_EMBEDDING_MODEL=book-test-v1');
    for($i=0;$i<30;$i++){if(@fsockopen('127.0.0.1',$port,$errno,$errstr,.1)){break;}usleep(100000);}
    $emb=Embeddings::generate(['network infrastructure','alpine travel','miscellaneous']);
    pass107(count($emb)===3&&count($emb[0])===4&&Embeddings::cosine($emb[0],[1,0,0,0])>0.99,'OpenAI-compatible provider returns real validated vectors');
    pass107(Embeddings::cosine(Embeddings::generate(['connection troubleshooting'])[0],$emb[0])>0.99,'semantic similarity beyond lexical query terms');
    deny107(fn()=>Embeddings::normalize([0,0]),'zero embeddings rejected');
    deny107(fn()=>Embeddings::normalize([INF,1]),'non-finite embeddings rejected');
    deny107(fn()=>Embeddings::generate(['']), 'blank embedding payload rejected');
    putenv('BOOK_EMBEDDING_URL=http://evil.example.com/v1/embeddings');
    deny107(fn()=>Embeddings::generate(['anything']),'remote plaintext provider rejected');
    putenv('BOOK_EMBEDDING_URL=http://127.0.0.1:'.$port.'/v1/embeddings');
    $lex=['hits'=>[['key'=>'guide/1/alpine','title'=>'Alpine','snippet'=>'alpine','score'=>12,'locale'=>'en']],'total'=>1,'pages'=>[],'engine'=>'portable','match'=>'fulltext'];
    $vec=['guide/1/network'=>['key'=>'guide/1/network','title'=>'Network','snippet'=>'ethernet','score'=>.93,'locale'=>'en']];
    $result=HybridSearch::combine($lex,$vec,['limit'=>10,'offset'=>0]);
    pass107($result['total']===2&&$result['match']==='hybrid'&&array_column($result['hits'],'key')===['guide/1/alpine','guide/1/network'],'hybrid fusion preserves lexical and adds semantically relevant page');
    $page=HybridSearch::combine($lex,$vec,['limit'=>1,'offset'=>1]);
    pass107($page['total']===2&&$page['hits'][0]['key']==='guide/1/network','hybrid pagination applied after ranking');
    pass107(HybridSearch::combine($lex,[],['limit'=>10,'offset'=>0])===$lex,'lexical fallback keeps result and total unchanged');
    pass107(Embeddings::input('title','section',str_repeat('я',5000))!==''&&strlen(Embeddings::input('title','section',str_repeat('я',5000)))<=6000,'large Unicode document input bounded');
    $dirCache=sys_get_temp_dir().'/book107-cache-'.bin2hex(random_bytes(8));mkdir($dirCache,0700);
    try{
        \CajeerEngine\Update\OperationFiles::write($dirCache.'/search-embeddings.json',json_encode(['model'=>'book-test-v1','entries'=>['guide/1/index'=>['digest'=>str_repeat('a',64),'vector'=>$emb[0]]]],JSON_THROW_ON_ERROR));
        pass107(array_keys(Embeddings::portableVectors($dirCache))===['guide/1/index'],'real derived file index read from disk');
        putenv('BOOK_EMBEDDING_MODEL=book-test-v2');pass107(Embeddings::portableVectors($dirCache)===[],'old-model vectors excluded');putenv('BOOK_EMBEDDING_MODEL=book-test-v1');
        unlink($dirCache.'/search-embeddings.json');symlink(__FILE__,$dirCache.'/search-embeddings.json');
        deny107(fn()=>Embeddings::portableVectors($dirCache),'symlink vector store rejected');
    }finally{unlink($dirCache.'/search-embeddings.json');rmdir($dirCache);}

    $emptyDir=sys_get_temp_dir().'/book107-prune-'.bin2hex(random_bytes(8));mkdir($emptyDir,0700);
    try{
        $emptyStore=new BookStore($emptyDir,false,true);$emptyStore->initialize();
        \CajeerEngine\Update\OperationFiles::write($emptyDir.'/search-embeddings.json',json_encode(['model'=>'book-test-v1','entries'=>['guide/1/unpublished'=>['digest'=>str_repeat('a',64),'vector'=>$emb[0]]]],JSON_THROW_ON_ERROR));
        pass107(Embeddings::refreshPortable($emptyDir)===0&&Embeddings::portableVectors($emptyDir)===[],'obsolete file vector pruned when no published pages remain');
    }finally{foreach(glob($emptyDir.'/*')?:[] as $file)unlink($file);rmdir($emptyDir);}

    // The full publication/Markdown path is exercised when Composer is present.
    if(is_file(dirname(__DIR__).'/vendor/autoload.php')){
        $dir=sys_get_temp_dir().'/book107-'.bin2hex(random_bytes(8));mkdir($dir,0700);$store=new BookStore($dir,false,true);$store->initialize();
        try{
            $input=static fn($book,$path,$title,$body,$rev=0)=>['book'=>$book,'version'=>'1','path'=>$path,'title'=>$title,'section'=>'Overview','body'=>$body,'order'=>0,'revision'=>$rev];
            $store->saveResult($input('guide','network','Ethernet setup','Use an ethernet cable for network troubleshooting.'),true);
            $store->saveResult($input('secret','private','Private ethernet','Secret network troubleshooting.'),true);
            $store->change(static function(array &$db):void{$db['books']['secret']['visibility']='private';});
            $q=Search::options(['q'=>'connection']);$r=$store->search('public','none',$q);
            pass107(array_column($r['hits'],'key')===['guide/1/network']&&$r['match']==='hybrid','real publishing and authorized semantic Reader search');
            $priv=$store->search('public','viewer',$q);
            pass107(in_array('secret/1/private',array_column($priv['hits'],'key'),true),'authorized member can search private published vectors');
            $old=$store->read()['pages']['guide/1/network']['revision'];$store->saveResult($input('guide','network','Mountain hiking','Alpine climbing trails',$old),true);
            $r=$store->search('public','none',$q);
            pass107(!in_array('guide/1/network',array_column($r['hits'],'key'),true),'updated document loses old embedding and lexical result');
            $n=$store->read()['pages']['guide/1/network']['revision'];$store->remove('guide/1/network',$n,true);
            pass107(!in_array('guide/1/network',array_column($store->search('public','none',Search::options(['q'=>'mountain']))['hits'],'key'),true),'unpublish removes document from hybrid search immediately');
        }finally{
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
        }
    }else echo "SKIP Composer-dependent publication integration (vendor/ not installed)\n";
}finally{
    proc_terminate($proc);proc_close($proc);
    if($oldUrl===false)putenv('BOOK_EMBEDDING_URL');else putenv('BOOK_EMBEDDING_URL='.$oldUrl);
    if($oldModel===false)putenv('BOOK_EMBEDDING_MODEL');else putenv('BOOK_EMBEDDING_MODEL='.$oldModel);
}
