<?php
declare(strict_types=1);
/** Local policy/serialization tests do not pretend to exercise a PostgreSQL server. */
$root=dirname(__DIR__);spl_autoload_register(function($class)use($root){foreach(['CajeerEngine\\'=>'core/','CajeerBook\\'=>'src/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){$path=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;return;}});
use CajeerBook\{BookStore,PostgresContentStore};
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(RuntimeException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
$dir=sys_get_temp_dir().'/book-pg-policy-'.bin2hex(random_bytes(8));mkdir($dir,0700);$namespace=str_repeat('a',32);
try{
    putenv('BOOK_DATA_DIR='.$dir);putenv('BOOK_PG_DSN');putenv('BOOK_PG_NAMESPACE');
    $legacy=new BookStore($dir);ok(count($legacy->read()['pages'])===3,'legacy backend remains compatible when PostgreSQL is not configured');
    $legacy->materialize();$before=file_get_contents($dir.'/books.json');
    putenv('BOOK_PG_DSN=pgsql:host=127.0.0.1;port=1;dbname=book;connect_timeout=1');putenv('BOOK_PG_NAMESPACE='.$namespace);
    deny(fn()=>(new BookStore($dir))->read(),'configured backend requires completed migration marker');
    file_put_contents($dir.'/postgres.required',$namespace.':'.str_repeat('c',32)."\n");
    putenv('BOOK_PG_NAMESPACE='.str_repeat('b',32));deny(fn()=>(new BookStore($dir))->read(),'wrong installation namespace denied');
    putenv('BOOK_PG_NAMESPACE='.$namespace);deny(fn()=>(new BookStore($dir))->read(),'database unavailable fails closed');
    ok(file_get_contents($dir.'/books.json')===$before,'failed SQL reads never change legacy JSON');
    putenv('BOOK_PG_DSN');deny(fn()=>(new BookStore($dir))->read(),'marker prevents fallback when DSN is removed');
    deny(fn()=>(new BookStore($dir.'/workspaces/'.str_repeat('1',32).'/'.str_repeat('2',32),false))->read(),'marker covers scoped workspace paths');
    $source=['schema'=>6,'pages'=>['guide/1/a'=>['published'=>null,'draft'=>['body'=>'雪','media'=>[]],'revision'=>1]],'ci'=>['tokens'=>['token'=>['secret'=>'hash']],'builds'=>['build'=>['snapshot'=>['z'=>1,'a'=>2]]]],'automation'=>['config'=>null,'jobs'=>['job'=>['id'=>'job']],'receipts'=>[]],'child:ci:builds'=>'unrelated legacy field','numeric'=>[3=>'three',0=>false,1=>null],'empty'=>[],'decimal'=>1.0];
    $split=new ReflectionMethod(PostgresContentStore::class,'sections');$assemble=new ReflectionMethod(PostgresContentStore::class,'assemble');
    $sections=$split->invoke(null,$source);ok(isset($sections['child:ci:builds']['build'],$sections['child:automation:jobs']['job'])&&$sections['root:ci']['builds']===[],'nested collections become independently persisted rows');
    ok($assemble->invoke(null,$sections)===$source,'record representation preserves key order, nested order, empty arrays, null, numeric keys and scalar types');
    unset($sections['child:ci:builds']);deny(fn()=>$assemble->invoke(null,$sections),'missing child section fails closed');
    unlink($dir.'/postgres.required');file_put_contents($dir.'/postgres.migrating',$namespace.':'.str_repeat('c',32)."\n");deny(fn()=>(new BookStore($dir))->read(),'unfinished migration blocks file fallback');unlink($dir.'/postgres.migrating');ok((new BookStore($dir))->read()===$legacy->read(),'original legacy data unchanged');
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
