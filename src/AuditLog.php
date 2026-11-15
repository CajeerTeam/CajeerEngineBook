<?php
declare(strict_types=1);
namespace CajeerBook;

use PDO;

/** Durable, allowlisted audit records. One chain per content scope, plus identity.
 * Writers hold their existing content/identity locks; no cross-stream writer lock. */
final class AuditLog
{
    public const ZERO='0000000000000000000000000000000000000000000000000000000000000000';
    private static ?string $request=null;
    public static function request():string{return self::$request??=bin2hex(random_bytes(16));}
    public static function beginRequest():string{return self::$request=bin2hex(random_bytes(16));}
    private static function id(mixed $s):string{return is_string($s)&&preg_match('/\A[a-f0-9]{32}\z/D',$s)?$s:'';}
    private static function json(array $a):string{return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private static function q(PDO $pdo,string $sql,array $args=[]):\PDOStatement{$q=$pdo->prepare($sql);$q->execute($args);return $q;}
    public static function schema(PDO $pdo):void{
        $sql=file_get_contents(dirname(__DIR__).'/sql/postgres-audit.sql');if($sql===false)throw new \RuntimeException('Audit schema unavailable.');$pdo->exec($sql);
    }
    public static function assertReady(PDO $pdo):void{
        if((int)$pdo->query('SELECT version FROM cajeer_book.audit_format WHERE singleton=true')->fetchColumn()!==1)throw new \RuntimeException('Audit schema missing; run bin/audit.php migrate --offline.');
    }
    public static function connection(string $root):array{
        $b=PostgresContentStore::identityBinding($root);if(!$b)throw new \DomainException('Enterprise audit requires PostgreSQL.');
        $pdo=PostgresContentStore::connect();if(self::q($pdo,'SELECT binding FROM cajeer_book.content_installation WHERE installation=?',[$b[0]])->fetchColumn()!==$b[1])throw new \RuntimeException('Audit installation binding mismatch.');return [$pdo,$b[0]];
    }
    public static function migrate(string $root):void{
        [$pdo,$id]=self::connection($root);$pdo->beginTransaction();try{$pdo->exec('SELECT pg_advisory_xact_lock(1128416305,1)');self::schema($pdo);self::initialize($pdo,$id);$pdo->commit();}catch(\Throwable $e){$pdo->rollBack();throw $e;}
    }
    /** Initialize once, offline, after content/identity import. Existing missing streams are damage. */
    public static function initialize(PDO $pdo,string $id):void{
        if(!$pdo->inTransaction())throw new \LogicException('Audit initialization requires transaction.');
        $n=self::q($pdo,'INSERT INTO cajeer_book.audit_installation(installation) VALUES(?) ON CONFLICT DO NOTHING',[$id])->rowCount();
        if($n===1){self::q($pdo,"INSERT INTO cajeer_book.audit_stream(installation,stream) VALUES(?,'identity')",[$id]);self::q($pdo,'INSERT INTO cajeer_book.audit_stream(installation,stream) SELECT installation,scope FROM cajeer_book.content_store WHERE installation=?',[$id]);self::append($pdo,$id,'identity',[self::event('audit.started')]);}
        self::assertInstallation($pdo,$id);
    }
    public static function assertInstallation(PDO $pdo,string $id):void{
        self::assertReady($pdo);
        $expected=self::q($pdo,"SELECT scope FROM cajeer_book.content_store WHERE installation=? UNION ALL SELECT 'identity' ORDER BY 1",[$id])->fetchAll(PDO::FETCH_COLUMN);
        $actual=self::q($pdo,'SELECT stream FROM cajeer_book.audit_stream WHERE installation=? ORDER BY stream',[$id])->fetchAll(PDO::FETCH_COLUMN);
        if($expected!==$actual||!in_array('legacy',$expected,true))throw new \RuntimeException('Audit streams missing or inconsistent; restore the matched audit backup or initialize offline.');
    }
    public static function initializeScope(PDO $pdo,string $id,string $scope):void{
        self::q($pdo,'INSERT INTO cajeer_book.audit_stream(installation,stream) VALUES(?,?)',[$id,$scope]);
    }
    /** No arbitrary subject/detail, usernames, IPs, headers, body or credential hashes.
     * Stable IDs and fixed outcome/action are enough to correlate with the original entity. */
    public static function project(array $e,string $stream):array{
        $action=$e['type']??'';if(!is_string($action)||!preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$action))throw new \InvalidArgumentException('Invalid audit action.');
        $org=self::id($e['organization']??null);$workspace=self::id($e['workspace']??null);
        if($stream!=='identity'&&$stream!=='legacy'){
            if(!preg_match('~\A([a-f0-9]{32})/([a-f0-9]{32})\z~D',$stream,$m))throw new \InvalidArgumentException('Invalid audit scope.');[$unused,$org,$workspace]=$m;
        }
        $outcome=$e['outcome']??match($action){'login.failed','oidc.failed','access.denied'=>'denied','login.limited'=>'limited','git.error'=>'failure',default=>'success'};
        if($action==='workspace.domain'&&in_array($e['detail']??'',['proof missing','DNS unavailable','DNS proof lost'],true))$outcome='failure';
        if(!in_array($outcome,['success','denied','limited','failure'],true))throw new \InvalidArgumentException('Invalid audit outcome.');
        $event=self::id($e['id']??null);if($event==='')throw new \InvalidArgumentException('Invalid audit event ID.');
        $at=strtotime((string)($e['at']??''));if($at===false||$at<0)throw new \InvalidArgumentException('Invalid audit timestamp.');
        $detail=$e['detail']??'';$code='';$count=0;
        if(is_string($detail)&&preg_match('/\A([1-9][0-9]{0,3}) page\(s\)\z/D',$detail,$m))$count=(int)$m[1];
        if(in_array($action,['workspace.role','organization.role'],true)&&is_string($detail)&&preg_match('/ · (inherit|viewer|editor|admin|none)\z/D',$detail,$m))$code=$m[1];
        if(is_string($detail)&&in_array($detail,['public','private','inherit','viewer','editor','admin','owner','none','verified','proof missing','DNS unavailable','DNS proof lost','add','disable','reset','primary','remove','Required','Direct'],true))$code=$detail;
        return ['id'=>$event,'at'=>$at,'action'=>$action,'outcome'=>$outcome,'actor'=>self::id($e['actor']['id']??null),'target'=>self::id($e['target']??null),'organization'=>$org,'workspace'=>$workspace,'request'=>self::request(),'code'=>$code,'count'=>$count];
    }
    public static function hash(string $installation,string $stream,int $seq,string $previous,string $payload):string{return hash('sha256',"cajeer-audit-v1\n$installation\n$stream\n$seq\n$previous\n$payload");}
    /** The ledger insert must commit or roll back with the business transaction. */
    public static function append(PDO $pdo,string $installation,string $stream,array $events):void{
        if(!$events)return;if(!$pdo->inTransaction())throw new \LogicException('Audit requires transaction.');self::assertReady($pdo);
        $head=self::q($pdo,'SELECT head_seq,head_hash,anchor_seq,anchor_hash FROM cajeer_book.audit_stream WHERE installation=? AND stream=? FOR UPDATE',[$installation,$stream])->fetch(PDO::FETCH_ASSOC);
        if(!$head)throw new \RuntimeException('Audit stream missing; run offline audit migration or restore the matched backup.');
        $seq=(int)$head['head_seq'];$hash=$head['head_hash'];
        $tail=self::q($pdo,'SELECT seq,event_hash FROM cajeer_book.audit_event WHERE installation=? AND stream=? ORDER BY seq DESC LIMIT 1',[$installation,$stream])->fetch(PDO::FETCH_ASSOC);
        if($seq===(int)$head['anchor_seq']){if($tail||$hash!==$head['anchor_hash'])throw new \RuntimeException('Audit anchor mismatch.');}
        elseif(!$tail||(int)$tail['seq']!==$seq||$tail['event_hash']!==$hash)throw new \RuntimeException('Audit tail mismatch.');
        $legacy=null;if($stream==='legacy'){$raw=self::q($pdo,"SELECT value::text FROM cajeer_book.identity_workspaces WHERE installation=? AND value->>'legacy'='true'",[$installation])->fetchColumn();if($raw!==false)$legacy=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        foreach($events as $event){if($legacy){$event['organization']=$legacy['organization'];$event['workspace']=$legacy['id'];}$e=self::project($event,$stream);$payload=self::json($e);$next=self::hash($installation,$stream,++$seq,$hash,$payload);
            self::q($pdo,'INSERT INTO cajeer_book.audit_event(installation,stream,seq,event_id,at_epoch,action,outcome,actor_id,organization,workspace,payload,previous_hash,event_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$installation,$stream,$seq,$e['id'],$e['at'],$e['action'],$e['outcome'],$e['actor'],$e['organization'],$e['workspace'],$payload,$hash,$next]);$hash=$next;
        }
        self::q($pdo,'UPDATE cajeer_book.audit_stream SET head_seq=?,head_hash=? WHERE installation=? AND stream=?',[$seq,$hash,$installation,$stream]);
    }
    public static function event(string $action,?array $actor=null,string $target='',string $outcome='success',string $org='',string $workspace=''):array{
        return ['id'=>bin2hex(random_bytes(16)),'at'=>gmdate('c'),'type'=>$action,'actor'=>$actor,'target'=>$target,'organization'=>$org,'workspace'=>$workspace,'outcome'=>$outcome];
    }
    /** SQL predicates derived only from live identity state under its authorization lock. */
    public static function access(array $db,string $uid):array{
        $owner=(bool)($db['users'][$uid]['owner']??false);$orgs=[];$workspaces=[];
        foreach($db['organizations'] as $id=>$o)if(Permissions::allows(Permissions::organization($o,$uid),'manage'))$orgs[]=$id;
        foreach($db['workspaces'] as $id=>$w){$o=$db['organizations'][$w['organization']]??null;if($o&&Permissions::allows(Permissions::workspace($o,$w,$uid),'manage'))$workspaces[]=$id;}
        return ['owner'=>$owner,'orgs'=>$orgs,'workspaces'=>$workspaces];
    }
    public static function filters(array $input):array{
        $out=[];foreach(['action','actor','organization','workspace','outcome','from','to','before'] as $k){$v=$input[$k]??'';if(!is_string($v))throw new \InvalidArgumentException('Invalid audit filter.');if($v==='')continue;
            $valid=match($k){'action'=>preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$v),'actor','organization','workspace'=>self::id($v)!=='','outcome'=>in_array($v,['success','denied','limited','failure'],true),'from','to'=>preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$v)&&($d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v,new \DateTimeZone('UTC')))&&$d->format('Y-m-d')===$v,'before'=>preg_match('/\A[0-9]{1,12}\.[a-f0-9]{32}\z/D',$v)};
            if(!$valid)throw new \InvalidArgumentException('Invalid audit filter: '.$k);$out[$k]=$v;
        }if(isset($out['from'],$out['to'])&&$out['from']>$out['to'])throw new \InvalidArgumentException('Invalid audit date range.');return $out;
    }
    public static function find(PDO $pdo,string $installation,array $access,array $filters,int $limit=50):array{
        self::assertInstallation($pdo,$installation);$filters=self::filters($filters);$limit=max(1,min(5000,$limit));$args=[$installation];$allowed=[];
        if($access['owner'])$allowed[]="(organization='' AND workspace='')";
        foreach(['orgs'=>'organization','workspaces'=>'workspace'] as $k=>$col){$ids=$access[$k];if(!$ids)continue;$allowed[]='('.($k==='orgs'?"workspace='' AND ":'').$col.' IN ('.implode(',',array_fill(0,count($ids),'?')).'))';array_push($args,...$ids);}
        if(!$allowed)return ['events'=>[],'next'=>null];$where=['installation=?','('.implode(' OR ',$allowed).')'];
        foreach($filters as $k=>$v){if($k==='before'){[$at,$event]=explode('.',$v,2);$where[]='(at_epoch,event_id)<(CAST(? AS bigint),?)';$args[]=$at;$args[]=$event;continue;}$where[]=match($k){'actor'=>'actor_id=?','from'=>'at_epoch>=?','to'=>'at_epoch<?',default=>$k.'=?'};$args[]=match($k){'from'=>strtotime($v.'T00:00:00Z'),'to'=>strtotime($v.'T00:00:00Z')+86400,default=>$v};}
        $rows=self::q($pdo,'SELECT * FROM cajeer_book.audit_event WHERE '.implode(' AND ',$where).' ORDER BY at_epoch DESC,event_id DESC LIMIT '.($limit+1),$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>$limit;if($more)array_pop($rows);$out=[];foreach($rows as $r)$out[]=self::row($r);return ['events'=>$out,'next'=>$more?end($out)['cursor']:null];
    }
    private static function row(array $r):array{
        if(!hash_equals($r['event_hash'],self::hash($r['installation'],$r['stream'],(int)$r['seq'],$r['previous_hash'],$r['payload'])))throw new \RuntimeException('Audit event integrity mismatch.');
        $e=json_decode($r['payload'],true,16,JSON_THROW_ON_ERROR);
        if($e['id']!==$r['event_id']||$e['at']!==(int)$r['at_epoch']||$e['action']!==$r['action']||$e['outcome']!==$r['outcome']||$e['actor']!==$r['actor_id']||$e['organization']!==$r['organization']||$e['workspace']!==$r['workspace'])throw new \RuntimeException('Audit index mismatch.');
        return ['cursor'=>$e['at'].'.'.$e['id'],'stream'=>$r['stream'],'sequence'=>(int)$r['seq'],'previous_hash'=>$r['previous_hash'],'hash'=>$r['event_hash'],'event'=>$e];
    }
    /** Check the entire retained suffix, including gaps and head/anchor consistency. */
    public static function verify(PDO $pdo,string $installation):array{
        self::assertReady($pdo);$owned=!$pdo->inTransaction();if($owned){$pdo->beginTransaction();$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');}
        try{self::assertInstallation($pdo,$installation);$heads=self::q($pdo,'SELECT * FROM cajeer_book.audit_stream WHERE installation=? ORDER BY stream',[$installation])->fetchAll(PDO::FETCH_ASSOC);$result=[];
            foreach($heads as $h){$seq=(int)$h['anchor_seq'];$hash=$h['anchor_hash'];$count=0;
                if(($seq===0&&$hash!==self::ZERO)||self::q($pdo,'SELECT 1 FROM cajeer_book.audit_event WHERE installation=? AND stream=? AND seq<=? LIMIT 1',[$installation,$h['stream'],$seq])->fetchColumn()!==false)throw new \RuntimeException('Audit anchor mismatch.');
                do{$rows=self::q($pdo,'SELECT * FROM cajeer_book.audit_event WHERE installation=? AND stream=? AND seq>? ORDER BY seq LIMIT 1000',[$installation,$h['stream'],$seq])->fetchAll(PDO::FETCH_ASSOC);
                    foreach($rows as $r){$e=self::row($r)['event'];if((int)$r['seq']!==++$seq||$r['previous_hash']!==$hash||$e['id']!==$r['event_id']||$e['at']!==(int)$r['at_epoch']||$e['action']!==$r['action']||$e['outcome']!==$r['outcome']||$e['actor']!==$r['actor_id']||$e['organization']!==$r['organization']||$e['workspace']!==$r['workspace'])throw new \RuntimeException('Audit chain or index mismatch.');$hash=$r['event_hash'];$count++;}
                }while(count($rows)===1000);
                if($seq!==(int)$h['head_seq']||!hash_equals($hash,$h['head_hash']))throw new \RuntimeException('Audit head mismatch.');$result[$h['stream']]=['retained'=>$count,'anchor'=>(int)$h['anchor_seq'],'anchor_hash'=>$h['anchor_hash'],'head'=>$seq,'head_hash'=>$hash];
            }if($owned)$pdo->commit();return $result;
        }catch(\Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
