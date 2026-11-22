<?php
declare(strict_types=1);
namespace CajeerBook {
    // In the dependency-free test environment, this narrowly scoped renderer shim
    // stands in for Composer CommonMark; all storage and access checks are real.
    final class Markdown {
        public static function render(string $md,?string $key=null,bool $editor=false,bool $studio=false,?string $linkBase=null):array {
            return ['html'=>'<p>'.htmlspecialchars($md,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>'];
        }
    }
}
namespace {
    require __DIR__.'/oidc-bootstrap.php';
    use CajeerBook\{DocumentationChat as Chat,BookStore};
    use CajeerEngine\Update\OperationFiles as Files;
    function check108(bool $value,string $text):void{if(!$value)throw new \RuntimeException($text);echo "PASS $text\n";}
    function deny108(callable $f,string $label):void{try{$f();}catch(\DomainException){check108(true,$label);return;}throw new \RuntimeException($label);}
    $root=sys_get_temp_dir().'/book-chat108-access-'.bin2hex(random_bytes(8));Files::dir($root);
    $oldPrivate=getenv('BOOK_CHAT_INCLUDE_PRIVATE');$oldEmb=getenv('BOOK_EMBEDDING_URL');
    try {
        $db=require dirname(__DIR__).'/data/seed.php';
        $secret=$db['pages']['handbook/1.0/welcome'];
        $secret['draft']['title']=$secret['published']['title']='Invisible credentials';
        $secret['draft']['body']=$secret['published']['body']='Private secret text must not be sent.';
        $db['pages']['secret/1.0/hidden']=$secret;
        $db['books']=['secret'=>['slug'=>'secret','id'=>str_repeat('a',32),'title'=>'Secret','visibility'=>'private']];
        Files::json($root.'/books.json',$db);
        $store=new BookStore($root,false,true);
        putenv('BOOK_CHAT_INCLUDE_PRIVATE');putenv('BOOK_EMBEDDING_URL');
        $sources=Chat::sources($store,'public','viewer','Getting started in documentation','handbook/1.0/welcome','/w/'.str_repeat('b',32).'/'.str_repeat('c',32));
        check108(count($sources)>=2&&$sources[0]['key']==='handbook/1.0/welcome','current published page and relevant search sources included');
        check108(!str_contains(json_encode($sources),'Private secret')&&!in_array('secret/1.0/hidden',array_column($sources,'key'),true),'private document excluded despite viewer role when private data transfer disabled');
        check108(str_starts_with($sources[0]['url'],'/w/'.str_repeat('b',32).'/'.str_repeat('c',32).'/docs/'),'scoped trusted URLs built from authorized page keys');
        deny108(fn()=>Chat::sources($store,'private','viewer','secret','secret/1.0/hidden',''),'current private page denied without opt-in');
        putenv('BOOK_CHAT_INCLUDE_PRIVATE=1');
        $priv=Chat::sources($store,'private','viewer','secret','secret/1.0/hidden','');
        check108(count($priv)>=1&&$priv[0]['key']==='secret/1.0/hidden','private context enabled explicitly for authorized viewer');
        deny108(fn()=>Chat::sources($store,'private','none','secret','secret/1.0/hidden',''),'anonymous visitor cannot access private book even with provider opt-in');
    }finally{
        if($oldPrivate===false)putenv('BOOK_CHAT_INCLUDE_PRIVATE');else putenv('BOOK_CHAT_INCLUDE_PRIVATE='.$oldPrivate);
        if($oldEmb===false)putenv('BOOK_EMBEDDING_URL');else putenv('BOOK_EMBEDDING_URL='.$oldEmb);
        foreach(glob($root.'/*')?:[] as $f)unlink($f);rmdir($root);
    }
}
