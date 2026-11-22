<?php
declare(strict_types=1);
namespace CajeerBook;

use PDO;

/** Reciprocal-rank fusion of authorized lexical matches and vector similarities.
 * Semantic candidates are scoped by the same policy as Reader/FTS BEFORE ranking.
 */
final class HybridSearch
{
    public static function query(string $q): ?array {
        if(!Embeddings::enabled()||trim($q)==='')return null;
        try{return Embeddings::generate([Search::cut($q,6000)])[0];}
        catch(\Throwable $e){Observability::log('search.embedding_unavailable',['error_type'=>get_class($e)]);return null;}
    }
    public static function portableCandidates(string $dir,array $db,string $visibility,string $role,array $o,array $query): array {
        $entries=Embeddings::portableVectors($dir);$visible=Locale::pages(Visibility::pages($db,$visibility,$role),$o['locale']);$out=[];
        foreach($visible as $key=>$p){[$book,$version]=explode('/',$key,3);if(($o['book']!==''&&$o['book']!==$book)||($o['version']!==''&&$o['version']!==$version))continue;
            $entry=$entries[$key]??null;if(!is_array($entry)||!is_array($entry['vector']??null)||!is_string($entry['digest']??null))continue;
            $input=Embeddings::input($p['title'],$p['section'],Search::plain($key,$p));
            if(!hash_equals(Embeddings::digest($input),$entry['digest']))continue;
            $score=Embeddings::cosine($query,$entry['vector']);if($score<0.35)continue;
            $out[$key]=['key'=>$key,'title'=>$p['title'],'snippet'=>Search::cut(Search::plain($key,$p),320),'score'=>$score,'locale'=>Locale::language($p)];
        }
        return $out;
    }
    public static function postgresCandidates(PDO $pdo,array $scope,string $visibility,string $role,array $o,array $query): array {
        $where='d.installation=? AND d.scope=?';$params=$scope;
        if(!Permissions::allows($role,'read'))$where.=$visibility==='public'?' AND d.is_public':' AND false';
        if($o['locale']!==''){$where.=' AND d.locale=?';$params[]=$o['locale'];}
        foreach(['book','version'] as $f)if($o[$f]!==''){$where.=' AND d.'.$f.'=?';$params[]=$o[$f];}
        $sql='SELECT d.page_key,d.title,d.section,d.body,d.locale,e.digest,e.vector::text AS vector FROM cajeer_book.search_document d JOIN cajeer_book.search_embedding e USING (installation,scope,page_key) WHERE '.$where.' AND e.model=?';
        $stmt=$pdo->prepare($sql);$stmt->execute([...$params,Embeddings::model()]);$out=[];
        foreach($stmt as $r){$input=Embeddings::input($r['title'],$r['section'],$r['body']);if(!hash_equals(Embeddings::digest($input),$r['digest']))continue;
            try{$vec=json_decode($r['vector'],true,32,JSON_THROW_ON_ERROR);if(!is_array($vec))continue;$score=Embeddings::cosine($query,$vec);}catch(\Throwable){continue;}
            if($score<0.35)continue;$out[$r['page_key']]=['key'=>$r['page_key'],'title'=>$r['title'],'snippet'=>Search::cut($r['body'],320),'score'=>$score,'locale'=>$r['locale']];
        }
        return $out;
    }
    public static function combine(array $lexical,array $candidates,array $options):array {
        if(!$candidates)return ['hits'=>array_slice($lexical['hits'],$options['offset'],$options['limit']),'total'=>$lexical['total'],'pages'=>$lexical['pages'],'engine'=>$lexical['engine'],'match'=>$lexical['match']];
        $ranked=[];$scores=[];$rank=0;
        foreach($lexical['hits'] as $hit){$rank++;$key=$hit['key'];$ranked[$key]=$hit;$scores[$key]=0.70/(60+$rank);}
        uasort($candidates,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp($a['key'],$b['key']));
        $rank=0;foreach(array_slice($candidates,0,100,true) as $key=>$hit){$rank++;if(!isset($ranked[$key]))$ranked[$key]=$hit;
            $scores[$key]=($scores[$key]??0)+0.30/(60+$rank);
        }
        foreach($ranked as $key=>&$hit)$hit['score']=round($scores[$key],8);unset($hit);
        uasort($ranked,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp($a['key'],$b['key']));
        return ['hits'=>array_slice(array_values($ranked),$options['offset'],$options['limit']),'total'=>count($ranked),'pages'=>$lexical['pages'],'engine'=>$lexical['engine'],'match'=>'hybrid'];
    }
}
