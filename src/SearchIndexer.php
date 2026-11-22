<?php
declare(strict_types=1);
namespace CajeerBook;

/** Refresh materialized semantic vectors independently of publication transactions.
 * Supports file workspaces and PostgreSQL; every write is CAS checked in its store.
 */
final class SearchIndexer
{
    private static ?string $lastScope=null;
    private static function scopes(string $root):array {
        $identity=new IdentityStore($root);
        $data=$identity->installationSnapshot(static fn(array $d):array=>$d);
        Workspaces::assertRegistryDirectories($root,$data);
        $dirs=['legacy'=>$root];
        foreach($data['workspaces']??[] as $w)if(!($w['legacy']??false))$dirs[$w['organization'].'/'.$w['id']]=Workspaces::directory($root,$w);
        ksort($dirs);return $dirs;
    }
    public static function tick(string $root):int {
        if(!Embeddings::enabled())return 0;
        $scopes=self::scopes($root);$names=array_keys($scopes);
        if(self::$lastScope!==null){$after=[];$before=[];foreach($names as $name){if(strcmp($name,self::$lastScope)>0)$after[]=$name;else $before[]=$name;}$names=array_merge($after,$before);}
        foreach($names as $name){self::$lastScope=$name;
            try{$n=(new BookStore($scopes[$name],$name==='legacy'))->refreshEmbeddings(2);if($n)return $n;}
            catch(\Throwable $e){Observability::log('search.indexing_pending',['scope'=>$name,'error_type'=>get_class($e)]);}
        }
        return 0;
    }
    public static function rebuild(string $root):array {
        if(!Embeddings::enabled())throw new \RuntimeException('Configure BOOK_EMBEDDING_URL and BOOK_EMBEDDING_MODEL to build vector search.');
        $result=[];
        foreach(self::scopes($root) as $name=>$dir){$store=new BookStore($dir,$name==='legacy');$count=0;
            for($i=0;$i<1000;$i++){$n=$store->refreshEmbeddings(8);if(!$n)break;$count+=$n;}
            $result[$name]=$count;
        }
        return $result;
    }
}
