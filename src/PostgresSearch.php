<?php
declare(strict_types=1);
namespace CajeerBook;
use PDO;
require_once __DIR__.'/Observability.php';

/** Transactional derived index, scoped to the same locked content revision as the Reader. */
final class PostgresSearch
{
    /** Explicit CLI-only profiling of the actual query path, never enabled by HTTP or environment. */
    private static ?array $plans=null;
    private static function q(PDO $pdo,string $sql,array $args):\PDOStatement{
        $start=hrtime(true);$ok=false;
        try{
            if(self::$plans!==null&&preg_match('/\A(?:SELECT|WITH)\b/i',$sql)){
                $explain=$pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql);$explain->execute($args);
                self::$plans[]=json_decode($explain->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            }
            $s=$pdo->prepare($sql);$s->execute($args);$ok=true;return $s;
        }finally{Observability::operation('sql_search_query',$start,$ok);}
    }
    public static function explain(PDO $pdo,array $scope,string $visibility,string $role,array $options,bool $navigation=false):array{
        if(PHP_SAPI!=='cli'||!$pdo->inTransaction()||self::$plans!==null)throw new \LogicException('Search profiling requires an isolated CLI transaction.');
        self::$plans=[];
        try{self::find($pdo,$scope,$visibility,$role,$options,$navigation);return self::$plans;}
        finally{self::$plans=null;}
    }
    public static function assertReady(PDO $pdo,array $scope):void
    {
        $pdo->query('SELECT locale FROM cajeer_book.search_document LIMIT 0');
        $r=self::q($pdo,'SELECT s.revision=c.revision FROM cajeer_book.content_store c JOIN cajeer_book.search_state s USING(installation,scope) WHERE c.installation=? AND c.scope=?',$scope)->fetchColumn();
        if($r!==true&&$r!=='t'&&$r!=='1'&&$r!==1)throw new \RuntimeException('Search index not ready; run bin/search.php reindex --offline.');
    }
    public static function sync(PDO $pdo,array $scope,array $before,array $after,bool $rebuild=false):void
    {
        if(!$pdo->inTransaction())throw new \LogicException('Search update outside transaction.');
        $old=$rebuild||!isset($before['pages'])?[]:Search::projection($before);$new=Search::projection($after);
        if($rebuild)self::q($pdo,'DELETE FROM cajeer_book.search_document WHERE installation=? AND scope=?',$scope);
        else foreach(array_chunk(array_keys(array_diff_key($old,$new)),128) as $chunk)self::q($pdo,'DELETE FROM cajeer_book.search_document WHERE installation=? AND scope=? AND page_key IN ('.implode(',',array_fill(0,count($chunk),'?')).')',[...$scope,...$chunk]);
        // Invalidate exactly the changed published representations, in bounded batches.
        // Removal of unpublished/deleted pages already cascades from search_document.
        if(!$rebuild&&isset($before['pages'])){
            $changed=[];foreach($new as $key=>$p)if(!isset($old[$key])||$old[$key]!==$p)$changed[]=$key;
            foreach(array_chunk($changed,128) as $chunk)self::q($pdo,'DELETE FROM cajeer_book.search_embedding WHERE installation=? AND scope=? AND page_key IN ('.implode(',',array_fill(0,count($chunk),'?')).')',[...$scope,...$chunk]);
        }
        $batch=[];$bytes=0;
        $flush=static function()use($pdo,&$batch,&$bytes){
            if(!$batch)return;
            self::q($pdo,'INSERT INTO cajeer_book.search_document(installation,scope,page_key,book,version,title,section,page_order,is_public,body,locale) VALUES '.implode(',',array_fill(0,count($batch),'(?,?,?,?,?,?,?,?,CAST(? AS boolean),?,?)')).' ON CONFLICT(installation,scope,page_key) DO UPDATE SET book=EXCLUDED.book,version=EXCLUDED.version,title=EXCLUDED.title,section=EXCLUDED.section,page_order=EXCLUDED.page_order,is_public=EXCLUDED.is_public,body=EXCLUDED.body,locale=EXCLUDED.locale',array_merge(...$batch));
            $batch=[];$bytes=0;
        };
        foreach($new as $key=>$p){if(isset($old[$key])&&$old[$key]===$p)continue;
            $body=Search::plain($key,$p);$size=strlen($body)+strlen($key)+strlen($p['title'])+strlen($p['section']);
            if(count($batch)>=64||($batch&&$bytes+$size>2097152))$flush();
            $batch[]=[...$scope,$key,$p['book'],$p['version'],str_replace("\0",' ',$p['title']),str_replace("\0",' ',$p['section']),$p['order'],$p['public']?'true':'false',$body,$p['locale']];$bytes+=$size;
        }
        $flush();
        self::q($pdo,'INSERT INTO cajeer_book.search_state(installation,scope,revision) SELECT installation,scope,revision FROM cajeer_book.content_store WHERE installation=? AND scope=? ON CONFLICT(installation,scope) DO UPDATE SET revision=EXCLUDED.revision',$scope);
    }
    public static function find(PDO $pdo,array $scope,string $visibility,string $role,array $o,bool $navigation):array
    {
        self::assertReady($pdo,$scope);
        $where='d.installation=? AND d.scope=?';$params=$scope;
        if(!Permissions::allows($role,'read'))$where.=$visibility==='public'?' AND d.is_public':' AND false';
        if(($o['locale']??'')!==''){$where.=' AND d.locale=?';$params[]=$o['locale'];}
        $pages=[];
        if($navigation){$q=self::q($pdo,'SELECT page_key,title,section,page_order,locale FROM cajeer_book.search_document d WHERE '.$where.' ORDER BY page_key',$params);foreach($q as $r)$pages[$r['page_key']]=['title'=>$r['title'],'section'=>$r['section'],'order'=>(int)$r['page_order'],'locale'=>$r['locale']];}
        $result=['hits'=>[],'total'=>0,'pages'=>$pages,'engine'=>'postgresql','match'=>'fulltext'];if(!$o['groups'])return $result;
        foreach(['book','version'] as $f)if($o[$f]!==''){$where.=' AND d.'.$f.'=?';$params[]=$o[$f];}
        $query=Search::query($o['groups'],false);$prefix=Search::query($o['groups'],true);
        $cte="WITH q AS NOT MATERIALIZED (SELECT to_tsquery('pg_catalog.simple',?) AS s,to_tsquery('pg_catalog.english',?) AS e,to_tsquery('pg_catalog.russian',?) AS r) ";
        $args=[$prefix,$query,$query,...$params];
        // Exact quoted phrases use the simple configuration. For other queries a stemmed
        // alternative may not silently drop a term (e.g. a language stop word).
        $hasPhrase=(bool)array_filter($o['groups'],fn($g)=>$g['phrase']);
        $english=$hasPhrase?'false':'(numnode(q.e)=numnode(q.s) AND d.english_vector @@ q.e)';
        $russian=$hasPhrase?'false':'(numnode(q.r)=numnode(q.s) AND d.russian_vector @@ q.r)';
        if(($o['locale']??'')==='en')$russian='false';
        if(($o['locale']??'')==='ru')$english='false';
        $condition=' FROM cajeer_book.search_document d CROSS JOIN q WHERE '.$where.' AND (d.simple_vector @@ q.s OR '.$english.' OR '.$russian.')';
        // Concatenated vectors have adjacent positions across fields. Confirm each phrase
        // in one actual field rather than matching an artificial title/body boundary.
        // A single quoted lexeme cannot cross field boundaries. Reuse the stored vector
        // instead of tokenizing every candidate body (especially costly under concurrent load).
        foreach($o['groups'] as $g)if($g['phrase']&&count($g['words'])>1){
            $condition.=" AND (to_tsvector('pg_catalog.simple',d.title) @@ to_tsquery('pg_catalog.simple',?) OR to_tsvector('pg_catalog.simple',d.section) @@ to_tsquery('pg_catalog.simple',?) OR to_tsvector('pg_catalog.simple',regexp_replace(d.page_key,'[^a-zA-Z0-9]+',' ','g')) @@ to_tsquery('pg_catalog.simple',?) OR to_tsvector('pg_catalog.simple',d.body) @@ to_tsquery('pg_catalog.simple',?))";
            $phrase=Search::query([$g],false);array_push($args,$phrase,$phrase,$phrase,$phrase);
        }
        $result['total']=(int)self::q($pdo,$cte.'SELECT count(*)'.$condition,$args)->fetchColumn();
        // Typo fallback is limited to a single unquoted term, after a zero-hit authorized FTS query.
        if($result['total']===0){
            $g=$o['groups'];if(count($g)===1&&!$g[0]['phrase']&&count($g[0]['words'])===1&&preg_match('/^.{3,64}$/us',$g[0]['words'][0]))return self::similar($pdo,$where,$params,$g[0]['words'][0],$o,$result);
            return $result;
        }
        // Only selected, authorized rows reach headline generation. Plain-text markers are not HTML.
        $sql=$cte.', hits AS MATERIALIZED (SELECT d.page_key,d.title,d.body,d.locale, GREATEST(CASE WHEN d.simple_vector @@ q.s THEN ts_rank_cd(d.simple_vector,q.s,32) ELSE 0 END,CASE WHEN '.$english.' THEN ts_rank_cd(d.english_vector,q.e,32) ELSE 0 END,CASE WHEN '.$russian.' THEN ts_rank_cd(d.russian_vector,q.r,32) ELSE 0 END) AS score, CASE WHEN d.simple_vector @@ q.s THEN 0 WHEN '.$english.' THEN 1 ELSE 2 END AS config'.$condition.' ORDER BY score DESC,d.page_key LIMIT '.(int)$o['limit'].' OFFSET '.(int)$o['offset'].') SELECT page_key AS key,title,locale,score, CASE config WHEN 0 THEN ts_headline(\'pg_catalog.simple\',body,q.s,\'StartSel=[, StopSel=], MaxWords=35, MinWords=12, MaxFragments=1\') WHEN 1 THEN ts_headline(\'pg_catalog.english\',body,q.e,\'StartSel=[, StopSel=], MaxWords=35, MinWords=12, MaxFragments=1\') ELSE ts_headline(\'pg_catalog.russian\',body,q.r,\'StartSel=[, StopSel=], MaxWords=35, MinWords=12, MaxFragments=1\') END AS snippet FROM hits CROSS JOIN q ORDER BY score DESC,page_key';
        foreach(self::q($pdo,$sql,$args)->fetchAll(PDO::FETCH_ASSOC) as $r){$r['score']=(float)$r['score'];$r['snippet']=Search::cut($r['snippet'],500);$result['hits'][]=$r;}
        return $result;
    }
    private static function similar(PDO $pdo,string $where,array $params,string $term,array $o,array $result):array
    {
        $schema=$pdo->query("SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='pg_trgm'")->fetchColumn();
        if(!is_string($schema)||$schema==='')throw new \RuntimeException('Search pg_trgm extension missing.');
        $schema='"'.str_replace('"','""',$schema).'"'; // identifier from PostgreSQL catalog, not user input
        $pdo->exec("SET LOCAL pg_trgm.word_similarity_threshold = '0.55'");
        $cte='WITH q AS (SELECT CAST(? AS text) AS term) ';
        $condition=' FROM cajeer_book.search_document d CROSS JOIN q WHERE '.$where.' AND (d.title OPERATOR('.$schema.'.%>) q.term OR d.page_key OPERATOR('.$schema.'.%>) q.term)';
        $args=[$term,...$params];$result['total']=(int)self::q($pdo,$cte.'SELECT count(*)'.$condition,$args)->fetchColumn();
        if(!$result['total'])return $result;
        $result['match']='similar';
        $sql=$cte.'SELECT d.page_key AS key,d.title,d.locale,substring(d.body from 1 for 200) AS snippet,GREATEST('.$schema.'.word_similarity(q.term,d.title),0.65*'.$schema.'.word_similarity(q.term,d.page_key)) AS score'.$condition.' ORDER BY score DESC,d.page_key LIMIT '.(int)$o['limit'].' OFFSET '.(int)$o['offset'];
        foreach(self::q($pdo,$sql,$args)->fetchAll(PDO::FETCH_ASSOC) as $r){$r['score']=(float)$r['score'];$r['snippet']=Search::cut($r['snippet'],500);$result['hits'][]=$r;}
        return $result;
    }

}
